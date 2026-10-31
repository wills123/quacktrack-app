# syntax=docker/dockerfile:1.28.0@sha256:bb22d9815c728170f72750f4e5b0d672e06176142e1d602c7e66c050100b7e5b
#
# The blue/green router: a single long-lived nginx that owns the public
# port and picks which color's web container gets traffic. It is built
# once per router version, not per release -- see docker-compose.router.yml.
# Images are pinned by digest (audit H2); doc/operations/IMAGE_PINS.md says how to update them.
FROM nginx:1.30.6-alpine@sha256:47928bf67eb62a1863cb08d9ecb52d3e704304ba614eaee17884204677702951

COPY router/default.conf /etc/nginx/conf.d/default.conf
COPY router/proxy_params.conf /etc/nginx/proxy_params.conf

# Baked-in fallback only. In real use, scripts/release.sh bind-mounts a
# host-managed file over this path (see docker-compose.router.yml), so the
# container can flip colors via `nginx -s reload` without a rebuild.
COPY router/active-upstream.conf.default /etc/nginx/state/active-upstream.conf
