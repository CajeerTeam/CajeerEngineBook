#!/bin/sh
# Executed only when the optional PostgreSQL container initializes an empty volume.
set -eu
: "${BOOK_PG_PASSWORD:?Set the application database password}"
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=book_password="$BOOK_PG_PASSWORD" <<'SQL'
CREATE ROLE book LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD :'book_password';
ALTER DATABASE book OWNER TO book;
REVOKE CONNECT ON DATABASE book FROM PUBLIC;
GRANT CONNECT ON DATABASE book TO book;
SQL
