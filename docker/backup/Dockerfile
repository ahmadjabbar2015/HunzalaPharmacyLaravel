# syntax=docker/dockerfile:1
FROM alpine:3.20

RUN apk add --no-cache mysql-client gzip tzdata

COPY backup.sh      /usr/local/bin/backup.sh
COPY entrypoint.sh  /usr/local/bin/entrypoint
COPY restore.sh     /usr/local/bin/restore.sh
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/entrypoint /usr/local/bin/restore.sh

ENTRYPOINT ["entrypoint"]
