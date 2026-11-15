#!/usr/bin/env bash
set -euo pipefail
# Disposable real S3-compatible HTTPS server. No production credentials or bucket.
base=$(mktemp -d)
name="book-recovery-s3-$RANDOM-$$"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$base"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$base/certs"
openssl req -x509 -nodes -newkey rsa:2048 -days 1 -keyout "$base/certs/private.key" -out "$base/certs/public.crt" -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' >/dev/null 2>&1
chmod 644 "$base/certs/public.crt"; chmod 600 "$base/certs/private.key"
docker run -d --name "$name" -p 127.0.0.1::9000 -e MINIO_ROOT_USER=booktest -e MINIO_ROOT_PASSWORD=book-test-only-password -v "$base/certs:/root/.minio/certs:ro" quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z server /data >/dev/null
port=$(docker port "$name" 9000/tcp | head -1 | sed 's/.*://')
unset AWS_SESSION_TOKEN AWS_PROFILE AWS_DEFAULT_PROFILE
export AWS_ACCESS_KEY_ID=booktest AWS_SECRET_ACCESS_KEY=book-test-only-password AWS_DEFAULT_REGION=us-east-1 AWS_CA_BUNDLE="$base/certs/public.crt" AWS_PAGER=""
export AWS_CONFIG_FILE="$base/aws-config"
printf '[default]\ns3 =\n    addressing_style = path\n' > "$AWS_CONFIG_FILE"
export BOOK_BACKUP_S3_ENDPOINT="https://localhost:$port" BOOK_BACKUP_S3_URI="s3://book-recovery-test/test"
for i in $(seq 1 60); do
  if curl -fsS --cacert "$AWS_CA_BUNDLE" "$BOOK_BACKUP_S3_ENDPOINT/minio/health/ready" >/dev/null; then break; fi
  sleep 1
done
curl -fsS --cacert "$AWS_CA_BUNDLE" "$BOOK_BACKUP_S3_ENDPOINT/minio/health/ready" >/dev/null
aws --endpoint-url "$BOOK_BACKUP_S3_ENDPOINT" s3 mb s3://book-recovery-test
php tests/recovery.php --s3
