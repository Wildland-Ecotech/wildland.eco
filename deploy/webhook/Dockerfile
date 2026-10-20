# Deploy webhook listener (https://github.com/adnanh/webhook), packaged by Debian.
#
# It receives GitHub push webhooks and only writes a trigger file. It has no
# Docker access; the host's systemd units run the deploy. See
# deploy/systemd/ and docs/07-deployment.md.

FROM debian:bookworm-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends webhook \
 && rm -rf /var/lib/apt/lists/*

COPY start.sh /usr/local/bin/start-webhook

ENTRYPOINT ["/usr/local/bin/start-webhook"]
