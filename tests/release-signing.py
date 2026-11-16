#!/usr/bin/env python3
"""Real Git/ZIP/Ed25519 integration; synthetic CI metadata is never release evidence."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('release_package',ROOT/'bin/release-package.py')
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
spec=importlib.util.spec_from_file_location('qualification_fixture',ROOT/'tests/production-qualification-fixture.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)


class Signing(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='book-signing-test-'); self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name); self.root=self.base/'source'; self.root.mkdir(); self.evidence=self.base/'evidence'; self.evidence.mkdir()
        # A real Git commit with the actual shipping Compose configurations, not a mocked Git command.
        for p in ROOT.glob('compose*.yaml'): shutil.copyfile(p,self.root/p.name)
        for name in ('VERSION','Dockerfile','src/DatabaseMigrations.php','bin/postgres-init.sh','tests/release-signing.py'):
            p=self.root/name; p.parent.mkdir(parents=True,exist_ok=True); shutil.copyfile(ROOT/name,p)
        (self.root/'README.md').write_text('Test source\n')
        self.git('init','-q'); self.git('add','.'); self.git('-c','user.name=Book test','-c','user.email=book@example.invalid','commit','-qm','fixture')
        self.commit=self.git('rev-parse','HEAD').strip(); self.version=(ROOT/'VERSION').read_text().strip(); self.repo='Test/Book'
        self.key=self.base/'private.pem'; self.pub=self.base/'public.pem'; self.make_key(self.key,self.pub)
        self.digests=['sha256:'+'1'*64,'sha256:'+'2'*64]; self.images={r:m.image_ref(self.repo,r,d) for r,d in zip(m.ROLES,self.digests)}
        self.e={'version':self.version,'commit':self.commit,'repository':self.repo,'run_id':'123','run_attempt':'1','gates':{g:'success' for g in m.GATES},'images':self.images,'image_checks':{r:{'smoke':'success','scan':'success'} for r in m.ROLES}}
        if tuple(map(int,self.version.split('.'))) >= (0,9,0):self.e['production_qualification']=fixture.qualification_fixture(self.version,self.commit,self.images)
        m.dump(self.evidence/'gate-evidence.json',self.e)
        for r,d in zip(m.ROLES,self.digests):
            m.dump(self.evidence/f'{r}-sbom.spdx.json',{'spdxVersion':'SPDX-2.3','packages':[{'name':'fixture'}]})
            m.dump(self.evidence/f'{r}-provenance.json',{'buildType':'https://mobyproject.org/buildkit@v1','materials':[{'uri':'fixture'}]})
            m.dump(self.evidence/f'{r}-build-metadata.json',{'containerimage.digest':d})
            m.dump(self.evidence/f'{r}-scan.json',{'Results':[{'Target':'fixture'}]})
            (self.evidence/f'{r}-smoke.log').write_text('TEST FIXTURE ONLY\n')
        self.out=self.base/'release'

    def git(self,*args): return subprocess.check_output(['git',*args],cwd=self.root,text=True)
    def make_key(self,key,pub):
        subprocess.run(['openssl','genpkey','-algorithm','ED25519','-out',str(key)],check=True); key.chmod(0o600)
        subprocess.run(['openssl','pkey','-in',str(key),'-pubout','-out',str(pub)],check=True)
    def command(self,action='assemble',**kw):
        values={'version':self.version,'repository':self.repo,'public-key':self.pub,'commit':self.commit}
        if action=='assemble': values.update(root=self.root,output=self.out,evidence=self.evidence,key=self.key,**{'book-digest':self.digests[0],'pitr-digest':self.digests[1]})
        else: values['directory']=self.out
        values.update(kw)
        args=[sys.executable,str(ROOT/'bin/release-package.py'),action]
        for k,v in values.items(): args.extend(['--'+k,str(v)])
        return subprocess.run(args,capture_output=True,text=True)
    def assemble(self):
        p=self.command(); self.assertEqual(p.returncode,0,p.stderr)
    def verify(self,**kw): return self.command('verify',**kw)
    def rejected(self,action='verify',**kw):
        p=self.command(action,**kw); self.assertNotEqual(p.returncode,0,p.stdout+p.stderr)

    def test_real_package_and_reproducibility(self):
        (self.root/'untracked-secret.txt').write_text('never included')
        (self.root/'README.md').write_text('dirty worktree')
        self.assemble(); p=self.verify(); self.assertEqual(p.returncode,0,p.stderr)
        with zipfile.ZipFile(self.out/'source.zip') as z:
            self.assertEqual(z.read('README.md'),b'Test source\n'); self.assertNotIn('untracked-secret.txt',z.namelist()); self.assertIn('tests/release-signing.py',z.namelist())
        with zipfile.ZipFile(self.out/'deployment.zip') as z:
            self.assertNotIn('tests/release-signing.py',z.namelist())
            for name in z.namelist():
                if name.startswith('compose') and name.endswith('.yaml'):
                    body=z.read(name).decode(); self.assertNotIn('    build:',body)
            self.assertIn(self.images['book'],z.read('compose.yaml').decode()); self.assertIn(self.images['postgres-pitr'],z.read('compose.pitr.yaml').decode())
        second=self.base/'second'; p=self.command(output=second); self.assertEqual(p.returncode,0,p.stderr)
        for name in ('source.zip','deployment.zip','release.json','release.json.sig','source.zip.sig','deployment.zip.sig'):
            self.assertEqual((self.out/name).read_bytes(),(second/name).read_bytes(),name)

    def test_tampered_zip(self):
        self.assemble(); p=self.out/'source.zip'; p.write_bytes(p.read_bytes()+b'evil'); self.rejected()
    def test_tampered_manifest(self):
        self.assemble(); p=self.out/'release.json'; p.write_bytes(p.read_bytes()+b' '); self.rejected()
    def test_tampered_signature(self):
        self.assemble(); p=self.out/'deployment.zip.sig'; p.write_bytes(b'\0'*64); self.rejected()
    def test_tampered_sbom(self):
        self.assemble(); (self.out/'book-sbom.spdx.json').write_text('{}'); self.rejected()
    def test_wrong_key(self):
        self.assemble(); k=self.base/'other.pem'; p=self.base/'other.pub'; self.make_key(k,p); self.rejected(**{'public-key':p}); self.rejected('assemble',output=self.base/'other-output',**{'public-key':p})
    def test_wrong_version_repo_commit(self):
        self.assemble(); self.rejected(version='1.0.0'); self.rejected(repository='Other/Book'); self.rejected(commit='a'*40)
    def test_extra_artifact(self):
        self.assemble(); (self.out/'untrusted.zip').write_bytes(b'extra'); self.rejected()
    def test_missing_artifact(self):
        self.assemble(); (self.out/'gate-evidence.json').unlink(); self.rejected()
    def test_no_overwrite(self):
        self.assemble(); self.rejected('assemble')
    def test_failed_gate(self):
        self.e['gates']['security-scan']='failure'; m.dump(self.evidence/'gate-evidence.json',self.e); self.rejected('assemble'); self.assertFalse(self.out.exists())
    def test_missing_gate(self):
        del self.e['gates']['enterprise-release']; m.dump(self.evidence/'gate-evidence.json',self.e); self.rejected('assemble')
    def test_qualification_required(self):
        self.e.pop('production_qualification',None);m.dump(self.evidence/'gate-evidence.json',self.e);self.rejected('assemble')
    def test_qualification_slo(self):
        self.e['production_qualification']['candidate_rto_seconds']=3601;m.dump(self.evidence/'gate-evidence.json',self.e);self.rejected('assemble')
    def test_qualification_wrong_image(self):
        self.e['production_qualification']['candidate_images']={};m.dump(self.evidence/'gate-evidence.json',self.e);self.rejected('assemble')
    def test_wrong_digest(self):
        m.dump(self.evidence/'book-build-metadata.json',{'containerimage.digest':self.digests[1]}); self.rejected('assemble')
    def test_empty_sbom(self):
        m.dump(self.evidence/'book-sbom.spdx.json',{'spdxVersion':'SPDX-2.3','packages':[]}); self.rejected('assemble')
    def test_private_key_permissions(self):
        self.key.chmod(0o644); self.rejected('assemble')
    def test_symlink_tracked(self):
        (self.root/'link').symlink_to(self.key); self.git('add','link'); self.git('-c','user.name=T','-c','user.email=t@example.invalid','commit','-qm','link'); self.commit=self.git('rev-parse','HEAD').strip(); self.e['commit']=self.commit; m.dump(self.evidence/'gate-evidence.json',self.e); self.rejected('assemble')
    def test_private_file_tracked(self):
        (self.root/'.env').write_text('secret'); self.git('add','.env'); self.git('-c','user.name=T','-c','user.email=t@example.invalid','commit','-qm','secret'); self.commit=self.git('rev-parse','HEAD').strip(); self.e['commit']=self.commit; m.dump(self.evidence/'gate-evidence.json',self.e); self.rejected('assemble')
    def test_unsafe_member(self):
        for name in ('../x','/x','a/../x','a\\b','a//b','.git/config','C:/x'):
            with self.subTest(name=name), self.assertRaises(ValueError): m.safe_member(name)

if __name__=='__main__': unittest.main(verbosity=2)
