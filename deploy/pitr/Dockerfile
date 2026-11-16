ARG POSTGRES_BASE=postgres:16-bookworm
FROM ${POSTGRES_BASE}
RUN apt-get update && apt-get install -y --no-install-recommends pgbackrest python3 ca-certificates \
 && dpkg --compare-versions "$(dpkg-query -W -f='${Version}' pgbackrest)" ge 2.59.3 \
 && rm -rf /var/lib/apt/lists/*
COPY bin/pitr-repo.py /usr/local/bin/book-pitr-repo
RUN chmod 0755 /usr/local/bin/book-pitr-repo
