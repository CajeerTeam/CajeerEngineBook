#!/usr/bin/env python3
"""Build and verify the signed Book distribution. No third-party Python dependencies."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tempfile
import zipfile

VERSION = re.compile(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z')
COMMIT = re.compile(r'[0-9a-f]{40}\Z')
DIGEST = re.compile(r'sha256:[0-9a-f]{64}\Z')
REPO = re.compile(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z')
GATES = {'test', 'postgres-content', 'docker-release', 'distributed-release', 'domains-edge', 'security-scan', 'enterprise-release'}
ROLES = ('book', 'postgres-pitr')
FILES = {'source.zip', 'deployment.zip', 'gate-evidence.json', 'SHA256SUMS'} | {f'{r}-{s}.json' for r in ROLES for s in ('sbom.spdx', 'provenance', 'build-metadata', 'scan')} | {f'{r}-smoke.log' for r in ROLES}
QUALIFICATION_FILES = {"qualification.json": "report_sha256", "qualification-lifecycle.json": "lifecycle_sha256", "qualification-soak.json": "soak_sha256", "qualification-candidate.json": "candidate_lifecycle_sha256"}
HOSTED_PROFILE = 'hosted-ci'
FULL_PROFILE = 'full-production'
HOSTED_NO_24H = {
    'status': 'not-run',
    'reason': 'The independent 24-hour/100000-page production qualification was not executed.',
    'required_soak_seconds': 86400,
    'soak_seconds': 0,
    'soak_requests': 0,
    'minute_windows': 0,
}
HOSTED_FILES = {'qualification-candidate.json'}
MAX_SOAK = 256 * 1024 * 1024
MAX_JSON = 128 * 1024 * 1024
MAX_ZIP = 256 * 1024 * 1024
MAX_UNPACK = 512 * 1024 * 1024
MAX_FILES = 10000
ROOT = Path(__file__).resolve().parents[1]


def stable(version):
    return tuple(map(int, version.split('.'))) >= (1, 0, 0)


def profile_for(version, evidence):
    profile = evidence.get('release_profile', FULL_PROFILE)
    if stable(version):
        require(profile in (HOSTED_PROFILE, FULL_PROFILE), 'Invalid release profile')
        require(profile != HOSTED_PROFILE or version == '1.0.0', 'Hosted release profile is explicitly limited to v1.0.0')
    else:
        require(profile == FULL_PROFILE, 'Legacy release cannot claim a hosted profile')
    return profile


def files_for(version, profile=FULL_PROFILE):
    if not stable(version): return FILES
    return FILES | (HOSTED_FILES if profile == HOSTED_PROFILE else set(QUALIFICATION_FILES))


def gates_for(version, profile=FULL_PROFILE):
    return GATES | ({'production-qualification'} if stable(version) and profile == FULL_PROFILE else set())


def asset_limit(name):
    if name == 'qualification-soak.json': return MAX_SOAK
    return MAX_ZIP if name.endswith('.zip') else MAX_JSON


def require(condition, message):
    if not condition:
        raise ValueError(message)


def run(*args, cwd=None):
    return subprocess.check_output(args, cwd=cwd, stderr=subprocess.PIPE)


def regular(path):
    path = Path(path)
    require(path.is_file() and not path.is_symlink(), f'Not a regular file: {path.name}')
    return path


def json_read(path, cap=MAX_JSON):
    path = regular(path)
    require(path.stat().st_size <= cap, 'JSON exceeds limit')
    def unique(pairs):
        out = {}
        for k, v in pairs:
            require(k not in out, 'Duplicate JSON key')
            out[k] = v
        return out
    return json.loads(path.read_bytes(), object_pairs_hook=unique,
                      parse_constant=lambda _: (_ for _ in ()).throw(ValueError('Nonfinite JSON')))


def dump(path, value):
    Path(path).write_text(json.dumps(value, sort_keys=True, indent=2, ensure_ascii=True, allow_nan=False)+'\n', encoding='utf-8')


def sha(path):
    with regular(path).open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def key_der(key, public):
    args = ['openssl', 'pkey', '-in', str(regular(key)), '-pubout', '-outform', 'DER']
    if public:
        args.insert(2, '-pubin')
    else:
        require(stat.S_IMODE(Path(key).stat().st_mode) & 0o077 == 0, 'Signing key must be private (0600/0400)')
        args += ['-passin', 'pass:']
    der = run(*args)
    require(len(der) == 44 and der.startswith(bytes.fromhex('302a300506032b6570032100')), 'Only Ed25519 keys are supported')
    return der


def sign(path, key):
    sig = path.with_name(path.name+'.sig')
    require(not sig.exists(), 'Refusing to overwrite signature')
    run('openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(key), '-passin', 'pass:', '-in', str(path), '-out', str(sig))
    require(sig.stat().st_size == 64, 'Invalid Ed25519 signature size')


def verify_sig(path, key):
    path = regular(path)
    require(path.stat().st_size <= (MAX_ZIP if path.suffix=='.zip' else 1024*1024), 'Signed artifact exceeds size limit')
    sig = regular(path.with_name(path.name+'.sig'))
    require(sig.stat().st_size == 64, 'Invalid signature size')
    run('openssl', 'pkeyutl', '-verify', '-rawin', '-pubin', '-inkey', str(key), '-in', str(regular(path)), '-sigfile', str(sig))


def image_ref(repository, role, digest):
    require(DIGEST.fullmatch(digest), 'Invalid OCI digest')
    return 'ghcr.io/'+repository.lower()+('' if role == 'book' else '-postgres-pitr')+'@'+digest


def validate_evidence(e, version, commit, repository, images):
    require(e['version'] == version and e['commit'] == commit and e['repository'] == repository, 'Evidence source mismatch')
    profile=profile_for(version,e)
    require(e['gates'] == {g: 'success' for g in gates_for(version,profile)}, 'Required gates did not all pass')
    require(re.fullmatch(r'[1-9]\d*', e['run_id']) and re.fullmatch(r'[1-9]\d*', e['run_attempt']), 'Invalid workflow run')
    require(e['images'] == images and e['image_checks'] == {r: {'smoke': 'success', 'scan': 'success'} for r in ROLES}, 'Exact image checks missing')
    if stable(version) and profile == HOSTED_PROFILE:
        # A hosted release is signed and CI-qualified, not 24h/lab-qualified.
        # Forbid synthetic soak counters and fail closed on missing disclosure.
        require(e.get('production_qualification') == HOSTED_NO_24H, 'Hosted release must disclose missing 24h qualification exactly')
        c=e.get('candidate_lifecycle')
        require(isinstance(c,dict) and set(c)=={'status','previous_commit','candidate_images','source_digest','report_sha256','rpo_seconds','rto_seconds'}, 'Missing exact candidate lifecycle evidence')
        require(c['status']=='passed' and c['candidate_images']==images and isinstance(c['previous_commit'],str) and COMMIT.fullmatch(c['previous_commit']) and c['previous_commit']!=commit, 'Incorrect candidate identity')
        for k in ('source_digest','report_sha256'):
            require(isinstance(c.get(k),str) and re.fullmatch(r'[0-9a-f]{64}',c[k]), 'Invalid candidate evidence hash')
        for k,limit in (('rpo_seconds',900),('rto_seconds',3600)):
            require(type(c.get(k)) in (int,float) and 0 <= c[k] <= limit and (k!='rto_seconds' or c[k]>0), 'Candidate recovery SLO failed')
    elif tuple(map(int,version.split('.'))) >= (0,9,0):
        q=e.get('production_qualification',{})
        require(q.get('status')=='lab-qualified' and q.get('version')==version and q.get('commit')==commit and q.get('candidate_images')==images, 'Production qualification source or image mismatch')
        require(q.get('soak_seconds')==86400 and q.get('soak_requests')==1728000 and q.get('minute_windows')==1440, '24h qualification missing')
        for field in ('source_digest','report_sha256','lifecycle_sha256','soak_sha256','candidate_lifecycle_sha256'):
            require(isinstance(q.get(field),str) and re.fullmatch(r'[0-9a-f]{64}',q[field]), 'Missing qualification evidence hash')
        for field,limit in [('rpo_seconds',900),('rto_seconds',3600),('candidate_rpo_seconds',900),('candidate_rto_seconds',3600)]:
            value=q.get(field);require(type(value) in (int,float) and 0<=value<=limit and ('rto' not in field or value>0), 'Recovery SLO failed')


def validate_metadata(directory, images):
    for role in ROLES:
        sbom = json_read(directory/f'{role}-sbom.spdx.json')
        require(str(sbom.get('spdxVersion','')).startswith('SPDX-') and isinstance(sbom.get('packages'), list) and bool(sbom['packages']), 'Empty/invalid SPDX SBOM')
        prov = json_read(directory/f'{role}-provenance.json')
        require(isinstance(prov, dict) and bool(prov.get('buildType')) and bool(prov.get('materials')), 'Missing BuildKit provenance/materials')
        meta = json_read(directory/f'{role}-build-metadata.json')
        require(regular(directory/f'{role}-smoke.log').stat().st_size > 0, 'Missing smoke log')
        scan = json_read(directory/f'{role}-scan.json')
        require(isinstance(scan.get('Results'), list) and bool(scan['Results']), 'Missing image scan results')
        for result in scan['Results']:
            require(not any(v.get('Severity') in ('HIGH','CRITICAL') for v in (result.get('Vulnerabilities') or [])+(result.get('Secrets') or [])), 'Image scan contains blocking findings')
        require(meta.get('containerimage.digest') == images[role].rsplit('@',1)[1], 'Build metadata digest mismatch')


def verify_qualification(directory, version, commit, images, digest, evidence):
    if not stable(version): return
    spec=importlib.util.spec_from_file_location('qualification_policy', ROOT/'bin/qualification_policy.py')
    policy=importlib.util.module_from_spec(spec); spec.loader.exec_module(policy)
    profile = profile_for(version,evidence)
    if profile == HOSTED_PROFILE:
        c=evidence['candidate_lifecycle']
        require(c['source_digest']==digest, 'Hosted candidate source fingerprint mismatch')
        require(sha(directory/'qualification-candidate.json')==c['report_sha256'], 'Actual candidate lifecycle report hash mismatch')
        lifecycle=json_read(directory/'qualification-candidate.json')
        checked=policy.validate_lifecycle(lifecycle,version,commit,c['previous_commit'])
        require(lifecycle['images']['new'].get('candidate_ref')==images['book'] and
                lifecycle['images']['pitr'].get('candidate_ref')==images['postgres-pitr'], 'Hosted release did not test these OCI candidate digests')
        require(c['rpo_seconds']==checked['rpo_seconds'] and c['rto_seconds']==checked['rto_seconds'], 'Hosted candidate SLO metrics differ')
        return
    q=evidence['production_qualification']
    for name, field in QUALIFICATION_FILES.items():
        require(sha(directory/name)==q[field], 'Qualification report hash mismatch: '+name)
    summary=json_read(directory/'qualification.json')
    lifecycle=json_read(directory/'qualification-lifecycle.json')
    soak=json_read(directory/'qualification-soak.json', MAX_SOAK)
    candidate=json_read(directory/'qualification-candidate.json')
    require(summary['evidence_sha256']=={'lifecycle':q['lifecycle_sha256'],'load':q['soak_sha256']}, 'Qualification summary hash mismatch')
    require(q['source_digest']==digest, 'Qualification source digest mismatch')
    checked=policy.validate_reports(summary,lifecycle,soak,candidate,version,commit,images,digest)
    require(all(q.get(k)==v for k,v in checked.items()), 'Qualification measurements differ from signed summary')


def source_fingerprint(entries):
    spec=importlib.util.spec_from_file_location('qualification_policy', ROOT/'bin/qualification_policy.py')
    policy=importlib.util.module_from_spec(spec); spec.loader.exec_module(policy)
    return policy.source_digest_entries(entries)


def safe_member(name):
    p = PurePosixPath(name)
    require(name and not name.startswith('/') and '\\' not in name and all(x not in ('', '.', '..') for x in name.split('/')) and ':' not in name and all(ord(c)>=32 and ord(c)!=127 for c in name), 'Unsafe archive path')
    require(not any(x.casefold() == '.git' for x in p.parts), 'Git metadata is forbidden')
    return p


def zip_members(z):
    """Validate all members before any decompression; shared by verifier and updater."""
    infos = z.infolist()
    require(0 < len(infos) <= MAX_FILES, 'Invalid ZIP member count')
    seen, dirs = set(), {}
    total = 0
    for i in infos:
        # zipfile normalizes NUL in filename; reject the original spelling too.
        require(i.orig_filename == i.filename, 'Ambiguous ZIP member name')
        p = safe_member(i.filename)
        name = i.filename.casefold()
        require(not i.is_dir() and stat.S_ISREG(i.external_attr >> 16) and not i.flag_bits & 1
                and i.compress_type in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED)
                and name not in seen and name not in dirs, 'Unsafe ZIP member')
        for parent in p.parents:
            if str(parent) == '.': continue
            spelling = str(parent); key = spelling.casefold()
            require(key not in seen and (key not in dirs or dirs[key] == spelling), 'ZIP file/directory conflict')
            dirs[key] = spelling
        seen.add(name); total += i.file_size
        require(0 <= i.file_size <= MAX_UNPACK and total <= MAX_UNPACK, 'ZIP expands beyond limit')
    return infos


def source_files(root, commit):
    # Use committed blobs, never the dirty worktree or ignored runtime data.
    entries = run('git', 'ls-tree', '-rz', commit, cwd=root).split(b'\0')
    seen = set()
    for entry in entries:
        if not entry:
            continue
        meta, name = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode().split()
        name = name.decode('utf-8')
        p = safe_member(name)
        require(mode in ('100644','100755') and kind == 'blob', 'Symlinks and submodules are not releasable')
        require(name.casefold() not in seen, 'Case-colliding archive names')
        seen.add(name.casefold())
        require(not any(x in ('vendor','var','__pycache__') or x.startswith('.env') or x.endswith(('.pem','.key','.cbk','.pfx','.p12')) for x in p.parts), 'Private/generated path in Git tree: '+name)
        yield name, int(mode, 8), run('git', 'cat-file', 'blob', oid, cwd=root)


def zip_write(path, files):
    with zipfile.ZipFile(path, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name, mode, body in sorted(files):
            safe_member(name)
            info = zipfile.ZipInfo(name, (1980,1,1,0,0,0))
            info.create_system = 3
            info.external_attr = mode << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, body, compresslevel=9)


def deployment_files(files, images):
    """Rewrite only known application build blocks, leaving optional overlays optional."""
    services = {'book','worker','backup','domain-check','diagnostics','pitr-sidecar'}
    for name, mode, body in files:
        if name.startswith(('.github/','tests/')) or name in ('.gitignore','.dockerignore','Dockerfile'):
            continue
        if name.startswith('compose') and name.endswith('.yaml'):
            lines = body.decode().splitlines(keepends=True)
            output = []
            service = None
            i = 0
            while i < len(lines):
                line = lines[i]
                match = re.fullmatch(r'  ([a-z][a-z-]*):\s*', line)
                if match:
                    service = match.group(1)
                if line == 'services:\n':
                    service = None
                if line.startswith('    build:'):
                    require(service in services or (service == 'postgres' and name == 'compose.pitr.yaml'), 'Unknown build service: '+str(service))
                    role = 'postgres-pitr' if service == 'postgres' else 'book'
                    if role == 'book':
                        output.append('    image: '+images[role]+'\n')
                    i += 1
                    while i < len(lines) and lines[i].startswith('      '):
                        i += 1
                    continue
                if service == 'postgres' and name == 'compose.pitr.yaml' and line.startswith('    image:'):
                    line = '    image: '+images['postgres-pitr']+'\n'
                output.append(line)
                i += 1
            body = ''.join(output).encode()
        yield name, mode, body


def assemble(args):
    root, out = args.root.resolve(), args.output.resolve()
    require(VERSION.fullmatch(args.version) and COMMIT.fullmatch(args.commit) and REPO.fullmatch(args.repository), 'Invalid release identity')
    require(run('git','rev-parse','HEAD',cwd=root).decode().strip() == args.commit, 'Checkout commit mismatch')
    require(run('git','show',args.commit+':VERSION',cwd=root).decode().strip() == args.version, 'VERSION mismatch')
    require(not out.exists(), 'Output directory must be new')
    require(not out.is_relative_to(root), 'Output must be outside source checkout')
    key, pub = args.key.resolve(), args.public_key.resolve()
    require(not args.key.is_symlink() and not key.is_relative_to(root) and not key.is_relative_to(out), 'Signing key must be outside checkout and output')
    require(key_der(key,False) == key_der(pub,True), 'Signing key does not match pinned public key')
    images = {r: image_ref(args.repository,r,d) for r,d in zip(ROLES,(args.book_digest,args.pitr_digest))}
    evidence = json_read(args.evidence/'gate-evidence.json')
    validate_evidence(evidence,args.version,args.commit,args.repository,images)
    validate_metadata(args.evidence,images)
    files = list(source_files(root,args.commit))
    profile = profile_for(args.version,evidence)
    names = files_for(args.version,profile)
    if stable(args.version):
        verify_qualification(args.evidence,args.version,args.commit,images,source_fingerprint((n,b) for n,_,b in files),evidence)
    with tempfile.TemporaryDirectory(prefix='.book-release-', dir=out.parent) as temp:
        stage = Path(temp)/'release'; stage.mkdir(mode=0o700)
        zip_write(stage/'source.zip',files)
        zip_write(stage/'deployment.zip',deployment_files(files,images))
        for name in sorted(names-{'source.zip','deployment.zip','SHA256SUMS'}):
            (stage/name).write_bytes(regular(args.evidence/name).read_bytes())
        (stage/'SHA256SUMS').write_text(''.join(f'{sha(stage/n)}  {n}\n' for n in sorted(names-{'SHA256SUMS'})))
        manifest = {'format':1,'product':'CajeerEngineBook','version':args.version,'commit':args.commit,'repository':args.repository,'schema_version':int(re.search(r'public const VERSION = (\d+);',run('git','show',args.commit+':src/DatabaseMigrations.php',cwd=root).decode()).group(1)),'platform':'linux/amd64','images':images,'release_profile':profile,'production_qualification':{'status':'not-run' if profile==HOSTED_PROFILE else 'lab-qualified' if stable(args.version) else 'not-applicable','required_soak_seconds':86400 if stable(args.version) else 0},'signing_key_sha256':hashlib.sha256(key_der(pub,True)).hexdigest(), 'artifacts':{n:{'sha256':sha(stage/n),'size':(stage/n).stat().st_size} for n in sorted(names)}}
        dump(stage/'release.json',manifest)
        for n in ('release.json','source.zip','deployment.zip'):
            sign(stage/n,key)
        verify_directory(stage,pub,args.version,args.repository,args.commit)
        stage.rename(out)
    print(out)


def verify_directory(directory, pub, version, repository, commit=None):
    directory = Path(directory)
    require(directory.is_dir() and not directory.is_symlink(), 'Release directory is not a regular directory')
    require(VERSION.fullmatch(version) and REPO.fullmatch(repository), 'Invalid expected release identity')
    verify_sig(directory/'release.json',pub)
    m = json_read(directory/'release.json')
    profile=m.get('release_profile',FULL_PROFILE)
    require(profile in (FULL_PROFILE,HOSTED_PROFILE) and (stable(version) or profile==FULL_PROFILE) and (profile!=HOSTED_PROFILE or version=='1.0.0'), 'Unsupported signed release profile')
    names = files_for(version,profile)
    require({p.name for p in directory.iterdir()} == names | {'release.json','release.json.sig','source.zip.sig','deployment.zip.sig'}, 'Unexpected or missing release files')
    require(m['format']==1 and m['product']=='CajeerEngineBook' and m['version']==version and m['repository']==repository and COMMIT.fullmatch(m['commit']) and (commit is None or m['commit']==commit), 'Release identity mismatch')
    require(m['signing_key_sha256']==hashlib.sha256(key_der(pub,True)).hexdigest(), 'Signing key fingerprint mismatch')
    if 'production_qualification' in m:
        require(m['production_qualification']=={'status':'not-run' if profile==HOSTED_PROFILE else 'lab-qualified' if stable(version) else 'not-applicable','required_soak_seconds':86400 if stable(version) else 0}, 'Signed qualification disclosure mismatch')
    else:
        # Older signed full-production manifests had no explicit profile fields.
        # Their complete real qualification evidence is still verified below.
        require('release_profile' not in m and profile==FULL_PROFILE, 'Missing signed qualification disclosure')
    require(m['platform']=='linux/amd64' and type(m['schema_version']) is int and m['schema_version']>=1, 'Unsupported platform/schema')
    require(set(m['images'])==set(ROLES) and all(m['images'][r]==image_ref(repository,r,m['images'][r].rsplit('@',1)[-1]) for r in ROLES), 'Unexpected OCI repository/digest')
    require(set(m['artifacts'])==names, 'Missing/unexpected artifacts')
    for n, a in m['artifacts'].items():
        p=regular(directory/n)
        cap = asset_limit(n)
        require(type(a['size']) is int and 0 < a['size'] <= cap and p.stat().st_size==a['size'] and sha(p)==a['sha256'], 'Artifact mismatch: '+n)
    require((directory/'SHA256SUMS').read_text() == ''.join(f"{m['artifacts'][n]['sha256']}  {n}\n" for n in sorted(names-{'SHA256SUMS'})), 'SHA256SUMS does not match manifest')
    for n in ('source.zip','deployment.zip'):
        verify_sig(directory/n,pub)
        with zipfile.ZipFile(directory/n) as z:
            zip_members(z)
            require(z.getinfo('VERSION').file_size <= 64, 'Invalid ZIP version size')
            require(z.read('VERSION').decode().strip()==version and z.testzip() is None, 'ZIP version or integrity mismatch')
    evidence=json_read(directory/'gate-evidence.json')
    validate_evidence(evidence,version,m['commit'],repository,m['images'])
    require(profile_for(version,evidence)==profile, 'Signed release profile and gate evidence mismatch')
    if stable(version):
        with zipfile.ZipFile(directory/'source.zip') as z:
            source = [(i.filename,i.external_attr >> 16,z.read(i)) for i in zip_members(z)]
        digest=source_fingerprint((n,b) for n,_,b in source)
        # A signed deployment must actually be the deterministic transform of its source.
        expected={n:(mode,hashlib.sha256(b).digest()) for n,mode,b in deployment_files(source,m['images'])}
        with zipfile.ZipFile(directory/'deployment.zip') as z:
            actual={i.filename:(i.external_attr >> 16,hashlib.sha256(z.read(i)).digest()) for i in zip_members(z)}
        require(actual==expected, 'Deployment does not match signed source and OCI digests')
        schema=dict((n,b) for n,_,b in source).get('src/DatabaseMigrations.php',b'')
        match=re.search(rb'public const VERSION = (\d+);',schema)
        require(match and int(match.group(1))==m['schema_version'], 'Signed schema metadata mismatch')
        verify_qualification(directory,version,m['commit'],m['images'],digest,evidence)
    validate_metadata(directory,m['images'])
    return m


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='action',required=True)
    a=sub.add_parser('assemble'); a.add_argument('--root',type=Path,default=ROOT); a.add_argument('--output',type=Path,required=True); a.add_argument('--evidence',type=Path,required=True); a.add_argument('--key',type=Path,required=True); a.add_argument('--book-digest',required=True); a.add_argument('--pitr-digest',required=True); a.add_argument('--commit',required=True)
    v=sub.add_parser('verify'); v.add_argument('--directory',type=Path,required=True); v.add_argument('--commit'); v.add_argument('--oci',action='store_true')
    for p in (a,v):
        p.add_argument('--version',required=True); p.add_argument('--repository',required=True); p.add_argument('--public-key',type=Path,required=True)
    args=parser.parse_args()
    try:
        if args.action=='assemble': assemble(args)
        else:
            m = verify_directory(args.directory,args.public_key,args.version,args.repository,args.commit)
            if args.oci:
                identity = f'https://github.com/{args.repository}/.github/workflows/ci.yml@refs/tags/v{args.version}'
                for ref in m['images'].values():
                    run('cosign','verify','--certificate-identity',identity,'--certificate-oidc-issuer','https://token.actions.githubusercontent.com',ref)
            print(json.dumps(m,indent=2))
    except (ValueError, KeyError, TypeError, OSError, subprocess.CalledProcessError, zipfile.BadZipFile) as e:
        print('Release rejected: '+str(e),file=sys.stderr); return 1
    return 0


if __name__=='__main__': sys.exit(main())
