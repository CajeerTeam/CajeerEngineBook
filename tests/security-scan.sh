#!/bin/sh
# Run on a disposable build host. Fail closed on scanner errors or findings.
set -eu
cd "$(dirname "$0")/.."
report=${1:?usage: security-scan.sh REPORT_DIR}
mkdir -p "$report"
report=$(cd "$report" && pwd)
work=$(mktemp -d)
image="book-security:$(basename "$work" | tr '[:upper:]' '[:lower:]')"
cleanup() { rc=$?; docker image rm "$image" >/dev/null 2>&1 || true; rm -rf "$work"; exit "$rc"; }
trap cleanup EXIT
trap 'exit 130' INT TERM
composer audit --locked --format=json > "$report/composer-audit.json"
semgrep scan --config security/semgrep.yml --metrics=off --error --strict --json --output "$report/semgrep.json" src core public
docker build --pull --target production -t "$image" . > "$report/build.log" 2>&1
docker run --rm --entrypoint sh "$image" -ec 'test ! -e /opt/cajeerbook/tests; test ! -e /usr/local/bin/composer; test -f /opt/cajeerbook/public/index.php; test -f /opt/cajeerbook/vendor/autoload.php; test "$(id -u www-data):$(id -g www-data)" = 33:33; httpd -t; aws --version'
docker save -o "$work/image.tar" "$image"
# Preserve the full JSON report and surface exact blockers in the job log.
rc=0
docker run --rm -v "$work:/scan:ro" -v "$report:/report" aquasec/trivy:0.76.2 image --input /scan/image.tar --scanners vuln,secret --severity HIGH,CRITICAL --exit-code 1 --format json --output /report/trivy.json || rc=$?
if [ "$rc" -ne 0 ] && [ -s "$report/trivy.json" ]; then
    python3 - "$report/trivy.json" <<'PYSCAN'
import json, sys
with open(sys.argv[1], encoding='utf-8') as stream:
    report = json.load(stream)
for result in report.get('Results', []):
    for finding in result.get('Vulnerabilities', []):
        print(f"{finding.get('Severity')} {finding.get('VulnerabilityID')} {finding.get('PkgName')} installed={finding.get('InstalledVersion')} fixed={finding.get('FixedVersion') or 'not available'}")
    for finding in result.get('Secrets', []):
        print(f"Secret finding: {finding.get('RuleID')} in {result.get('Target')}")
PYSCAN
fi
exit "$rc"
