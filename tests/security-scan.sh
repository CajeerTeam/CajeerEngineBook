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
docker build --target production -t "$image" . > "$report/build.log" 2>&1
docker run --rm --entrypoint sh "$image" -ec 'test ! -e /opt/cajeerbook/tests; test -f /opt/cajeerbook/public/index.php; test -f /opt/cajeerbook/vendor/autoload.php'
docker save -o "$work/image.tar" "$image"
docker run --rm -v "$work:/scan:ro" -v "$report:/report" aquasec/trivy:0.69.3 image --input /scan/image.tar --scanners vuln,secret --severity HIGH,CRITICAL --exit-code 1 --format json --output /report/trivy.json
