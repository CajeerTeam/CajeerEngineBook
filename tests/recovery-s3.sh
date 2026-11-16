#!/usr/bin/env bash
set -euo pipefail
# Real disposable S3-compatible HTTPS server, never exposed outside loopback.
# Upstream community images and dl.min.io are no longer reliably available.
base=$(mktemp -d)
pid=''
cleanup() { rc=$?; if [ -n "$pid" ]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi; if [ "$rc" -ne 0 ] && [ -f "$base/minio.log" ]; then tail -60 "$base/minio.log" >&2; fi; rm -rf "$base"; exit "$rc"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$base/certs" "$base/data"
openssl req -x509 -nodes -newkey rsa:2048 -days 1 -keyout "$base/certs/private.key" -out "$base/certs/public.crt" -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' >/dev/null 2>&1
chmod 644 "$base/certs/public.crt"; chmod 600 "$base/certs/private.key"
if [ -n "${BOOK_TEST_MINIO_BINARY:-}" ]; then
  minio=$(realpath "$BOOK_TEST_MINIO_BINARY"); test -x "$minio"
else
  test "$(uname -s):$(uname -m)" = Linux:x86_64 || { echo 'Set BOOK_TEST_MINIO_BINARY to a local MinIO executable for this platform' >&2; exit 1; }
  release=RELEASE.2025-09-07T16-13-09Z
  minio="$base/minio"
  curl --fail --silent --show-error --location --retry 3 --connect-timeout 15 --max-time 300 --proto '=https' --proto-redir '=https' \
    "https://github.com/minio/minio/releases/download/$release/minio.linux-amd64.$release" -o "$minio"
  printf '%s  %s\n' 7c5bd8512c6e966455b1d198209358b2d191c77a83ab377c4073281065fb855f "$minio" | sha256sum -c -
  chmod 700 "$minio"
fi
# Allocate a loopback port and validate startup; do not reuse an existing service.
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
MINIO_ROOT_USER=booktest MINIO_ROOT_PASSWORD=book-test-only-password MINIO_BROWSER=off MINIO_UPDATE=off \
  "$minio" server --address "127.0.0.1:$port" --certs-dir "$base/certs" "$base/data" > "$base/minio.log" 2>&1 &
pid=$!
unset AWS_SESSION_TOKEN AWS_PROFILE AWS_DEFAULT_PROFILE AWS_SECURITY_TOKEN
export AWS_ACCESS_KEY_ID=booktest AWS_SECRET_ACCESS_KEY=book-test-only-password AWS_DEFAULT_REGION=us-east-1 AWS_CA_BUNDLE="$base/certs/public.crt" AWS_PAGER=""
export AWS_CONFIG_FILE="$base/aws-config" AWS_SHARED_CREDENTIALS_FILE="$base/aws-credentials" AWS_EC2_METADATA_DISABLED=true
printf '[default]\ns3 =\n    addressing_style = path\n' > "$AWS_CONFIG_FILE"
export BOOK_BACKUP_S3_ENDPOINT="https://localhost:$port" BOOK_BACKUP_S3_URI="s3://book-recovery-test/test"
for i in $(seq 1 60); do
  kill -0 "$pid"
  if curl -fsS --cacert "$AWS_CA_BUNDLE" "$BOOK_BACKUP_S3_ENDPOINT/minio/health/ready" >/dev/null; then break; fi
  sleep 1
done
kill -0 "$pid"
curl -fsS --cacert "$AWS_CA_BUNDLE" "$BOOK_BACKUP_S3_ENDPOINT/minio/health/ready" >/dev/null
aws --endpoint-url "$BOOK_BACKUP_S3_ENDPOINT" s3 mb s3://book-recovery-test
php tests/recovery.php --s3
