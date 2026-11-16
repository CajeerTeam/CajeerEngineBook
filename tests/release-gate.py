#!/usr/bin/env python3
"""Run the same disposable release gates locally and in CI. No production credentials."""
import argparse
import json
import os
from pathlib import Path
import shlex
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SUITES = ('native', 'postgres', 'docker', 'distributed', 'domains')


def isolated_env():
    # Inherit toolchain paths, not app/DB/cloud credentials or Compose project overrides.
    env = {k: v for k, v in os.environ.items() if not k.startswith(('BOOK_', 'COMPOSE_', 'AWS_', 'PG', 'REDIS_')) and k != 'PYTHONOPTIMIZE'}
    env.update(COMPOSE_DISABLE_ENV_FILE='1', AWS_EC2_METADATA_DISABLED='true', AWS_PAGER='')
    return env


class Gate:
    def __init__(self, report, suite, env):
        self.report = report
        self.suite = suite
        self.env = env
        self.rows = []

    def run(self, label, cmd, timeout=900, env=None):
        log = self.report / (f'{len(self.rows)+1:03d}-{label}.log')
        start = time.monotonic()
        print(f'[{self.suite}] {label}', flush=True)
        status = 1
        with log.open('w') as out:
            try:
                p = subprocess.Popen(cmd, cwd=ROOT, env=self.env if env is None else env,
                                     stdout=out, stderr=subprocess.STDOUT, start_new_session=True)
                try:
                    status = p.wait(timeout=timeout)
                except (subprocess.TimeoutExpired, KeyboardInterrupt):
                    os.killpg(p.pid, signal.SIGTERM)
                    try:
                        p.wait(timeout=30)
                    except subprocess.TimeoutExpired:
                        os.killpg(p.pid, signal.SIGKILL)
                        p.wait()
                    status = 124
                    out.write('\nGate command interrupted or timed out.\n')
            except OSError as exc:
                out.write(f'{exc}\n')
        self.rows.append({'name': label, 'exit_code': status, 'seconds': round(time.monotonic()-start, 3), 'log': log.name})
        self.save()
        if status:
            print(f'FAILED {label}: exit {status}; {log}', file=sys.stderr)
        return status == 0

    def save(self):
        data = {'version': (ROOT/'VERSION').read_text().strip(), 'suite': self.suite, 'steps': self.rows,
                'status': 'failed' if any(r['exit_code'] for r in self.rows) else 'running'}
        self.write(data)

    def write(self, data):
        (self.report/'report.json').write_text(json.dumps(data, indent=2)+'\n')
        node = ET.Element('testsuite', name=self.suite, tests=str(len(self.rows)), failures=str(sum(r['exit_code'] != 0 for r in self.rows)))
        for row in self.rows:
            case = ET.SubElement(node, 'testcase', name=row['name'], classname=self.suite, time=str(row['seconds']))
            if row['exit_code']:
                ET.SubElement(case, 'failure', message=f"exit {row['exit_code']}").text = row['log']
            ET.SubElement(case, 'system-out').text = row['log']
        ET.ElementTree(node).write(self.report/'junit.xml', encoding='utf-8', xml_declaration=True)

    def require(self, label, cmd, timeout=900):
        if not self.run(label, cmd, timeout):
            raise RuntimeError(f'{label} failed')

    def finish(self, success):
        self.write({'version': (ROOT/'VERSION').read_text().strip(), 'suite': self.suite, 'steps': self.rows,
                    'status': 'passed' if success and self.rows and not any(r['exit_code'] for r in self.rows) else 'failed'})


def preflight(g):
    tools = ['php', 'python3', 'openssl', 'bash', 'docker']
    if g.suite in ('native', 'postgres'):
        tools += ['composer', 'node', 'npm']
    if g.suite == 'native':
        tools += ['aws', 'tsc', 'curl', 'nginx', 'git']
    missing = [x for x in tools if not shutil.which(x)]
    if missing:
        raise RuntimeError('Missing executables: '+', '.join(missing))
    g.require('docker-info', ['docker', 'info'], 30)
    g.require('compose-version', ['docker', 'compose', 'version'], 30)
    if g.suite in ('native', 'postgres'):
        extensions = ['mbstring', 'fileinfo', 'phar', 'gd', 'zip', 'openssl', 'sodium', 'pcntl']
        if g.suite == 'postgres':
            extensions += ['pdo_pgsql', 'redis']
        g.require('php-runtime', ['php', '-r', 'if(PHP_VERSION_ID<80401)exit(1);foreach(array_slice($argv,1) as $e)if(!extension_loaded($e)){fwrite(STDERR,"Missing extension: $e\n");exit(1);}', *extensions], 30)
        g.require('composer-install', ['composer','install','--no-interaction','--prefer-dist','--no-progress'], 600)
        g.require('mermaid-assets', ['sh','bin/install-mermaid.sh'], 300)


def native(g):
    g.require('composer-validate', ['composer','validate','--strict'])
    g.require('composer-audit', ['composer','audit','--locked'])
    g.require('php-lint', ['bash','-c', "set -o pipefail; find src core data public tests bin -name '*.php' -print0 | xargs -0 -n1 php -l && php -l bootstrap.php"])
    g.require('browser-dependency', ['python3','-c','import playwright'])
    # Read the same list as composer test. Execute each separately for actionable evidence.
    commands = json.loads((ROOT/'composer.json').read_text())['scripts']['test']
    for i, command in enumerate(commands):
        args = shlex.split(command)
        if args[0] == '@php': args[0] = 'php'
        g.run(f'composer-{i+1:02d}-{Path(args[1]).stem}', args)
    g.run('production-qualification-policy',['python3','tests/production-qualification-policy.py'])
    g.run('performance-policy',['python3','tests/performance-policy.py'])
    g.run('load-qualification-policy',['python3','tests/load-qualification-policy.py'])
    g.run('load-qualification-file',['python3','tests/performance-benchmark.py','--qualify','--pages','100','--per-workspace','50','--concurrency','8','--offered-rps','8','--phase-seconds','8','--soak-seconds','20','--min-samples','3','--telemetry','--report-dir',str(g.report/'load-qualification-file')],300)
    g.run('performance-file',['python3','tests/performance-benchmark.py','--pages','100','--per-workspace','50','--requests','20','--concurrency','4','--full','--telemetry','--report-dir',str(g.report/'performance-file')],1200)
    g.run('monitoring-rules', ['docker','run','--rm','--entrypoint','/bin/promtool','-v',str(ROOT/'deploy/monitoring/alerts.yml')+':/rules/alerts.yml:ro','-v',str(ROOT/'tests/monitoring-rules.yml')+':/rules/tests.yml:ro','prom/prometheus:v3.5.0','test','rules','/rules/tests.yml'], 600)
    g.run('s3-https', ['bash','tests/recovery-s3.sh'], 1200)
    g.run('offline-search', ['node','tests/static-search-language.mjs'])
    for i, args in enumerate(json.loads((ROOT/'tests/gate-browser.json').read_text())):
        g.run(f'browser-{i+1:02d}-{Path(args[1]).stem}', ['python3', *args[1:]])


def capture(cmd, env):
    return subprocess.check_output(cmd, cwd=ROOT, env=env, text=True, timeout=60).strip()


def postgres(g):
    name = g.env['BOOK_GATE_PROJECT']
    pg, redis = name+'-pg', name+'-redis'
    # Disposable services, randomly allocated loopback ports; never connect to operator databases.
    try:
        g.require('postgres-start',['docker','run','-d','--name',pg,'-e','POSTGRES_DB=book_test','-e','POSTGRES_USER=book_test','-e','POSTGRES_PASSWORD=book_test_only','-p','127.0.0.1::5432','postgres:16-bookworm'])
        g.require('redis-start',['docker','run','-d','--name',redis,'-p','127.0.0.1::6379','redis:7.4-alpine'])
        for container, probe in [(pg,['pg_isready','-h','127.0.0.1','-U','book_test','-d','book_test']),(redis,['redis-cli','ping'])]:
            for _ in range(60):
                if subprocess.run(['docker','exec',container,*probe],env=g.env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=10).returncode==0: break
                time.sleep(1)
            else: raise RuntimeError(f'{container} not ready')
        # Source and drill use separate non-superusers; drill cannot connect to the source DB.
        g.require('postgres-roles',['docker','exec',pg,'psql','-U','book_test','-d','book_test','-v','ON_ERROR_STOP=1',
            '-c',"CREATE ROLE book_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD 'book_app_test_only'",
            '-c',"CREATE ROLE book_drill LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD 'book_drill_test_only'",
            '-c','ALTER DATABASE book_test OWNER TO book_app','-c','REVOKE CONNECT ON DATABASE book_test FROM PUBLIC',
            '-c','CREATE DATABASE book_drill OWNER book_drill','-c','REVOKE CONNECT ON DATABASE book_drill FROM PUBLIC',
            '-c','CREATE DATABASE book_migrations OWNER book_app','-c','REVOKE CONNECT ON DATABASE book_migrations FROM PUBLIC',
            '-c','CREATE DATABASE book_benchmark OWNER book_app','-c','REVOKE CONNECT ON DATABASE book_benchmark FROM PUBLIC',
            '-c','CREATE DATABASE book_admin_runbook OWNER book_app','-c','REVOKE CONNECT ON DATABASE book_admin_runbook FROM PUBLIC'])
        port=capture(['docker','port',pg,'5432/tcp'],g.env).rsplit(':',1)[1]
        rport=capture(['docker','port',redis,'6379/tcp'],g.env).rsplit(':',1)[1]
        g.env.update(BOOK_PG_DSN=f'pgsql:host=127.0.0.1;port={port};dbname=book_test;connect_timeout=5',BOOK_PG_USER='book_app',BOOK_PG_PASSWORD='book_app_test_only')
        migration_env=dict(g.env,BOOK_PG_DSN=f'pgsql:host=127.0.0.1;port={port};dbname=book_migrations;connect_timeout=5',BOOK_MIGRATION_TEST='1')
        g.run('postgres-migrations',['php','tests/postgres-migrations.php'],1200,migration_env)
        admin_env=dict(g.env,BOOK_ADMIN_RUNBOOK_PG_DSN=f'pgsql:host=127.0.0.1;port={port};dbname=book_admin_runbook;connect_timeout=5',BOOK_ADMIN_RUNBOOK_REDIS_PORT=rport)
        g.run('administrator-runbook-postgres',['python3','tests/admin-runbook.py','--postgres'],1200,admin_env)
        g.run('upgrade-050',['php','tests/upgrade-050.php','--postgres'])
        for name in ('postgres-content','postgres-identity','postgres-audit'):
            g.run(name,['php',f'tests/{name}.php'])
        g.run('postgres-reader',['php','tests/postgres-reader.php','--postgres'])
        g.run('security-lifecycle-postgres',['php','tests/security-lifecycle.php','--postgres'])
        g.run('physical-pitr',['bash','tests/pitr-gate.sh'],1800)
        drill=dict(g.env,BOOK_BACKUP_DRILL_PG_DSN=f'pgsql:host=127.0.0.1;port={port};dbname=book_drill;connect_timeout=5',BOOK_BACKUP_DRILL_PG_USER='book_drill',BOOK_BACKUP_DRILL_PG_PASSWORD='book_drill_test_only')
        g.run('postgres-recovery',['php','tests/recovery.php','--postgres'],1200,drill)
        for name, args in [('domains',['php','tests/domains.php','--postgres']),('domains-http',['python3','tests/domains-http.py','--postgres','--full']),('oidc-http',['python3','tests/oidc-http.py','--postgres']),('multilingual',['php','tests/multilingual.php','--postgres']),('multilingual-http',['python3','tests/multilingual-http.py','--postgres']),('postgres-search',['php','tests/postgres-search.php']),('search-http',['python3','tests/search-http.py','--postgres']),('ci-http',['python3','tests/ci-http.py','--postgres'])]:
            g.run(name,args)
        g.env.update(BOOK_REDIS_HOST='127.0.0.1',BOOK_REDIS_PORT=rport)
        g.run('postgres-primary',['php','tests/postgres-primary.php'])
        g.run('redis-runtime',['php','tests/redis-runtime.php'])
        g.run('redis-http',['python3','tests/redis-http.py'])
        benchmark_env=dict(g.env,BOOK_BENCHMARK_PG_DSN=f'pgsql:host=127.0.0.1;port={port};dbname=book_benchmark;connect_timeout=5',BOOK_BENCHMARK_PG_USER='book_app',BOOK_BENCHMARK_PG_PASSWORD='book_app_test_only',BOOK_BENCHMARK_REDIS_HOST='127.0.0.1',BOOK_BENCHMARK_REDIS_PORT=rport)
        g.run('performance-postgres',['python3','tests/performance-benchmark.py','--postgres','--redis','--full','--telemetry','--maintenance','--pages','1000','--per-workspace','500','--requests','60','--concurrency','8','--report-dir',str(g.report/'performance-postgres')],1800,benchmark_env)
        g.run('load-qualification-postgres',['python3','tests/performance-benchmark.py','--qualify','--postgres','--redis','--full','--telemetry','--pages','1000','--per-workspace','500','--concurrency','8','--source-ips','16','--offered-rps','8','--phase-seconds','10','--soak-seconds','30','--min-samples','3','--background-maintenance','--maintenance-interval','600','--maintenance-timeout','600','--report-dir',str(g.report/'load-qualification-postgres')],900,benchmark_env)
    finally:
        for container in (pg,redis):
            # Preserve service diagnostics before cleanup if a test failed.
            if any(r['exit_code'] for r in g.rows):
                g.run('logs-'+container.rsplit('-',1)[-1],['docker','logs',container],60)
            g.run('cleanup-'+container.rsplit('-',1)[-1],['docker','rm','-fv',container],60)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite',choices=(*SUITES,'all'),default='all')
    parser.add_argument('--report-dir',required=True,type=Path,help='New directory for logs, JSON and JUnit evidence')
    args=parser.parse_args()
    report=args.report_dir.resolve();report.mkdir(mode=0o700,parents=True,exist_ok=False)
    results={}
    for suite in SUITES if args.suite=='all' else (args.suite,):
        dest=report/suite;dest.mkdir(mode=0o700)
        with tempfile.TemporaryDirectory(prefix='book-gate-') as tmp:
            env=isolated_env();env.update(BOOK_GATE_PROJECT='book-gate-'+uuid.uuid4().hex[:12],BOOK_GATE_TMP=tmp,BOOK_GATE_REPORT=str(dest))
            g=Gate(dest,suite,env);ok=False
            try:
                preflight(g)
                if suite=='native': native(g)
                elif suite=='postgres': postgres(g)
                else:
                    g.require(suite,['bash', 'tests/domains-edge.sh' if suite=='domains' else f'tests/gate-{suite}.sh'],3600)
                    if suite=='docker':
                        g.require('secure-update-compose',(['sudo','-n'] if os.geteuid()!=0 else [])+['python3','tests/update-compose-gate.py'],3600)
                        g.require('production-lifecycle',(['sudo','-n'] if os.geteuid()!=0 else [])+['python3','tests/production-lifecycle.py','--report-dir',str(g.report/'production-lifecycle')],10800)
                ok=not any(r['exit_code'] for r in g.rows)
            except (RuntimeError, OSError, subprocess.SubprocessError) as exc:
                (dest/'gate-error.log').write_text(str(exc)+'\n');g.rows.append({'name':'gate-error','exit_code':1,'seconds':0,'log':'gate-error.log'})
                print(f'[{suite}] {exc}',file=sys.stderr)
            finally:
                g.finish(ok)
            results[suite]='passed' if ok else 'failed'
    (report/'summary.json').write_text(json.dumps(results,indent=2)+'\n')
    print(json.dumps(results,indent=2))
    return 0 if all(v=='passed' for v in results.values()) else 1

if __name__=='__main__':
    sys.exit(main())
