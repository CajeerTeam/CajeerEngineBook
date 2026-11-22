#!/usr/bin/env python3
"""Exercise the actual administrator CLIs on a disposable installation.
--minimal explicitly skips Composer-dependent operations; never a release pass.
"""
import argparse
import json
import os
import re
import secrets
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    p = argparse.ArgumentParser(description=__doc__)
    group = p.add_mutually_exclusive_group()
    group.add_argument('--minimal', action='store_true')
    group.add_argument('--postgres', action='store_true', help='Dedicated disposable release-gate database only')
    args = p.parse_args()
    count = 0
    env = {k: v for k, v in os.environ.items() if not k.startswith(('BOOK_', 'PG', 'REDIS_', 'AWS_', 'COMPOSE_'))}
    env.update(BOOK_SECURE_COOKIE='0', BOOK_OBSERVABILITY='0')
    secret = 'private-test-password-082'

    def expect(ok, message):
        nonlocal count
        if not ok:
            raise RuntimeError(message)
        count += 1
        print('PASS', message, flush=True)

    def run(*cmd, code=0, data=None, extra=None):
        proc = subprocess.run(['php', *cmd], cwd=ROOT, env=env | (extra or {}), input=data,
                              text=True, capture_output=True, timeout=180)
        if proc.returncode != code:
            raise RuntimeError(f'{cmd[0]} {cmd[1:]}: exit {proc.returncode}, expected {code}: {proc.stderr[:500]}')
        return proc.stdout

    def report(*flags, extra=None, check=False, code=0):
        raw = run('bin/admin.php', 'check' if check else 'report', *flags, code=code, extra=extra)
        result = json.loads(raw)
        if secret in raw or str(base) in raw or 'admin@example.org' in raw:
            raise RuntimeError('Report exposes installation data')
        return result

    with tempfile.TemporaryDirectory(prefix='book-admin-runbook-') as tmp:
        base = Path(tmp)
        source = base / 'source'
        env['BOOK_DATA_DIR'] = str(source)
        r = report()
        expect(r['checks']['storage']['status'] == 'failed' and not source.exists(), 'missing storage is not created by report')
        source.mkdir(mode=0o700)
        r = report()
        expect(r['checks']['identity']['status'] == 'failed', 'uninitialized owner is not reported ready')
        run('bin/identity.php', 'init', 'admin', 'admin@example.org', 'Admin', data=secret+'\n')
        r = report()
        expect(r['version'] == (ROOT/'VERSION').read_text().strip() and r['schema_required'] == 3, 'report uses shipped release and schema')
        expect(r['engine_version'] == (ROOT/'core'/'ENGINE_VERSION').read_text().strip(), 'report identifies embedded CajeerEngine release')
        expect(r['checks']['identity']['status'] == 'ok' and r['checks']['workspace_stores']['status'] == 'ok', 'initialized identity and actual stores pass')
        expect(r['checks']['postgres_schema']['status'] == r['checks']['redis']['status'] == 'skipped', 'optional services are explicitly skipped')
        expect(r['checks']['configuration']['status'] == 'ok', 'file configuration validated')
        if args.minimal:
            expect(r['checks']['runtime']['status'] == 'failed' and r['status'] == 'unavailable', 'missing runtime dependencies remain visible')
            report(check=True, code=1)
        else:
            expect(r['status'] == 'ok' and r['checks']['runtime']['status'] == 'ok', 'installed runtime dependencies pass')
            report(check=True)
        r = report(extra={'BOOK_OIDC_REQUIRED':'1'})
        expect(r['checks']['configuration']['status'] == 'failed', 'required OIDC without provider fails')
        r = report(extra={'BOOK_REDIS_HOST':'redis.invalid'})
        expect(r['checks']['configuration']['status'] == 'failed', 'Redis without SQL fails before connection')
        r = report(extra={'BOOK_DATA_DIR':str(ROOT/'public'/'not-created')})
        expect(r['checks']['storage']['status'] == 'failed', 'public data path refused')
        marker = source/'postgres.required'
        marker.write_text('a'*32+':'+ 'b'*32+'\n')
        r = report()
        expect(r['checks']['binding']['status'] == 'failed' and r['checks']['identity']['status'] == 'skipped', 'SQL marker forbids stale file fallback')
        marker.unlink()
        anchor = source/'identity.required'
        saved = anchor.read_bytes()
        anchor.write_text('0'*32+'\n')
        r = report()
        expect(r['checks']['identity']['status'] == 'failed' and r['checks']['workspace_stores']['status'] == 'skipped', 'damaged identity anchor blocks content checks')
        anchor.write_bytes(saved)
        key = base/'key'
        key.write_text('c'*64+'\n'); key.chmod(0o600)
        r = report(extra={'BOOK_GITHUB_KEY_FILE':str(key)})
        expect(r['checks']['git_keys']['status'] == 'ok' and 'c'*64 not in json.dumps(r), 'private key checked without disclosure')
        key.chmod(0o644)
        r = report(extra={'BOOK_GITHUB_KEY_FILE':str(key)})
        expect(r['checks']['git_keys']['status'] == 'failed', 'insecure key permissions refused')
        key.chmod(0o600)
        r = report(extra={'BOOK_GITHUB_KEY_FILE':str(key),'BOOK_GITHUB_KEY':'d'*64})
        expect(r['checks']['git_keys']['status'] == 'failed', 'ambiguous key configuration refused')
        r = report('--oidc', '--monitoring', check=True, code=1)
        expect(r['checks']['oidc_discovery']['status'] == r['checks']['monitoring']['status'] == 'failed', 'explicit unconfigured optional checks fail')
        run('bin/admin.php', 'check', '--unknown', code=2)
        expect(True, 'invalid CLI usage returns 2')
        if not args.minimal:
            r = report('--deep', check=True)
            expect(r['checks']['installation_integrity']['status'] == r['checks']['active_credentials']['status'] == 'ok', 'deep check uses actual integrity and credential validators')
            backup = base/'backup'; restored = base/'restored'
            run('bin/installation.php','backup',str(backup))
            expect((backup/'manifest.json').is_file(), 'documented backup creates a manifest')
            run('bin/installation.php','restore',str(backup),extra={'BOOK_DATA_DIR':str(restored)})
            r = report('--deep', check=True, extra={'BOOK_DATA_DIR':str(restored)})
            expect(r['status'] == 'ok', 'documented restore into new directory passes deep check')
            run('bin/installation.php','restore',str(backup),extra={'BOOK_DATA_DIR':str(restored)},code=1)
            expect(True, 'restore refuses existing destination')
            run('bin/identity.php','revoke-sessions','admin',extra={'BOOK_DATA_DIR':str(restored)})
            expect(True, 'documented operator session revocation succeeds')
        if args.postgres:
            dsn = os.environ.get('BOOK_ADMIN_RUNBOOK_PG_DSN','')
            port = os.environ.get('BOOK_ADMIN_RUNBOOK_REDIS_PORT','')
            if not re.fullmatch(r'pgsql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=book_admin_runbook;connect_timeout=5', dsn) or not port.isdigit() or not 1 <= int(port) <= 65535:
                raise RuntimeError('PostgreSQL runbook requires disposable loopback release-gate services')
            if not os.environ.get('BOOK_GATE_PROJECT','').startswith('book-gate-'):
                raise RuntimeError('Use tests/release-gate.py --suite postgres')
            sqlroot = base/'sql'; sqlroot.mkdir(mode=0o700)
            env.update(BOOK_DATA_DIR=str(sqlroot), BOOK_PG_DSN=dsn,
                       BOOK_PG_USER='book_app', BOOK_PG_PASSWORD='book_app_test_only',
                       BOOK_PG_NAMESPACE=secrets.token_hex(16))
            # Fail closed if a different or previously used database is supplied.
            run('-r', '''$p=new PDO(getenv("BOOK_PG_DSN"),getenv("BOOK_PG_USER"),getenv("BOOK_PG_PASSWORD"));
            if($p->query("SELECT current_database()")->fetchColumn()!=="book_admin_runbook" ||
               $p->query("SELECT to_regnamespace('cajeer_book') IS NOT NULL")->fetchColumn())exit(1);''')
            run('bin/postgres-content.php','migrate','--offline')
            r = report()
            expect(r['checks']['postgres_schema']['status']=='ok' and r['checks']['identity']['status']=='failed', 'fresh SQL schema and uninitialized owner verified')
            run('bin/identity.php','init','admin','admin@example.org','Admin',data=secret+'\n')
            env.update(BOOK_REDIS_HOST='127.0.0.1',BOOK_REDIS_PORT=port)
            r = report('--deep',check=True)
            expect(r['status']=='ok' and r['checks']['audit_chain']['status']=='ok' and r['checks']['redis']['status']=='ok', 'documented SQL/Redis deep checks succeed')
            run('bin/migrations.php','verify')
            run('bin/runtime.php','check')
            expect(True, 'documented migrations and runtime checks succeed')
            r = report(extra={'BOOK_PG_NAMESPACE':secrets.token_hex(16)})
            expect(r['checks']['binding']['status']=='failed','wrong SQL namespace refused')
            sqlbackup=base/'sql-backup'; sqlrestore=base/'sql-restored'
            run('bin/installation.php','backup',str(sqlbackup))
            portable = {k:'' for k in ('BOOK_PG_DSN','BOOK_PG_NAMESPACE','BOOK_PG_USER','BOOK_PG_PASSWORD','BOOK_REDIS_HOST','BOOK_REDIS_SENTINELS')}
            portable['BOOK_DATA_DIR']=str(sqlrestore)
            run('bin/installation.php','restore',str(sqlbackup),extra=portable)
            r = report('--deep',check=True,extra=portable)
            expect(r['backend']=='file' and r['status']=='ok','SQL portable backup restored independently and verified')
            # Dedicated database belongs to the release gate and is removed with its container.

        if args.minimal:
            print('SKIP: Composer-dependent deep check, CLI backup and restore (minimal mode).')
    print(f'OK {count} administrator runbook checks ({"minimal" if args.minimal else "full"})')

if __name__ == '__main__':
    main()
