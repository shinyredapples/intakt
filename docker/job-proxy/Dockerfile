FROM alpine:3.22

RUN apk add --no-cache tinyproxy

COPY entrypoint.sh /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
