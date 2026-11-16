#!/usr/bin/env python3
"""Check current handbook references and snippets; never execute Markdown commands."""
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT/'docs/admin'
CURRENT = ('README.md','operations.md','configuration.md','incidents.md','cli-reference.md','verification.md','production-qualification.md')


def main():
    errors = []
    counts = dict(links=0, commands=0, shell=0, json=0, settings=0)
    version = (ROOT/'VERSION').read_text().strip()
    schema = re.search(r'public const VERSION = (\d+);', (ROOT/'src/DatabaseMigrations.php').read_text())[1]
    handbook = (ADMIN/'README.md').read_text()
    if f'Book {version}' not in handbook or f'Current PostgreSQL schema: {schema}.' not in handbook:
        errors.append('Handbook version/schema does not match code')
    # Verify all local links in specialist guides as well as the current handbook.
    for file in sorted(ADMIN.glob('*.md')):
        text = file.read_text()
        prose = re.sub(r'^```[^\n]*\n.*?^```[ \t]*$', '', text, flags=re.M|re.S)
        for dest in re.findall(r'\]\(([^\s)]+)\)', prose):
            if '://' in dest or dest.startswith(('#','mailto:')):
                continue
            dest = unquote(dest.split('#',1)[0])
            path = (file.parent/dest).resolve()
            if not path.is_relative_to(ROOT) or not path.is_file():
                errors.append(f'{file.name}: broken local link {dest}')
            counts['links'] += 1
        # Every executable named in a guide must actually exist in the release tree.
        for target in sorted(set(re.findall(r'\b(?:php|python3|bash|sh)\s+(bin/[a-zA-Z0-9_.-]+|tests/[a-zA-Z0-9_.-]+)', text))):
            if not (ROOT/target).is_file(): errors.append(f'{file.name}: missing CLI {target}')
            counts['commands'] += 1
        if file.name not in CURRENT: continue
        for lang, body in re.findall(r'^```(bash|sh|json)\s*\n(.*?)^```[ \t]*$',text,flags=re.M|re.S):
            if lang == 'json':
                try: json.loads(body)
                except ValueError as e: errors.append(f'{file.name}: invalid JSON: {e}')
                counts['json'] += 1
            else:
                proc = subprocess.run(['bash','-n'], input=body, text=True, capture_output=True, timeout=10)
                if proc.returncode: errors.append(f'{file.name}: invalid shell snippet: {proc.stderr}')
                counts['shell'] += 1
    # Reference variables must be present in actual runtime, deployment or release source.
    source_files = list((ROOT/'src').glob('*.php')) + list((ROOT/'bin').glob('*')) + list(ROOT.glob('compose*.yaml')) + [ROOT/'Dockerfile', ROOT/'.github/workflows/ci.yml']
    known = set()
    for path in source_files:
        if path.is_file(): known.update(re.findall(r'\b(?:BOOK|AWS)_[A-Z0-9_]+\b',path.read_text(errors='replace')))
    for name in sorted(set(re.findall(r'\b(?:BOOK|AWS)_[A-Z0-9_]+\b',(ADMIN/'configuration.md').read_text()))):
        if name not in known: errors.append(f'configuration.md: unknown setting {name}')
        counts['settings'] += 1
    for path in ADMIN.glob('*.md'):
        if path.name not in CURRENT and f'({path.name})' not in handbook:
            errors.append(f'Specialist guide missing from handbook index: {path.name}')
    if errors:
        raise SystemExit('\n'.join(errors))
    print('OK documentation checks: '+json.dumps(counts,sort_keys=True))

if __name__ == '__main__': main()
