# syntax=docker/dockerfile:1
#
# The blue/green router: a single long-lived nginx that owns the public
# port and picks which color's web container gets traffic. It is built
# once per router version, not per release -- see docker-compose.router.yml.
FROM nginx:1.27-alpine

COPY router/default.conf /etc/nginx/conf.d/default.conf
COPY router/proxy_params.conf /etc/nginx/proxy_params.conf

# Baked-in fallback only. In real use, scripts/release.sh bind-mounts a
# host-managed file over this path (see docker-compose.router.yml), so the
# container can flip colors via `nginx -s reload` without a rebuild.
COPY router/active-upstream.conf.default /etc/nginx/state/active-upstream.conf
