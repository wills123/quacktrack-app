# Trusted proxies

nginx (`nginx/default.conf`) and the release router (`router/default.conf`) take the real client address from
`X-Forwarded-For` only when the request comes from a trusted proxy. The proxies' addresses belong to the deployment, so
they are not committed (audit finding H10). Each deployment puts them in a `*.conf` file that nginx includes from
`/etc/nginx/trusted-proxies/`:

```nginx
# /etc/quacktrack/trusted-proxies/lan.conf on the server (nginx/trusted-proxies/lan.conf for local compose)
set_real_ip_from 192.0.2.10;   # LAN proxy 1
set_real_ip_from 192.0.2.11;   # LAN proxy 2
```

| Compose file | Directory mounted at `/etc/nginx/trusted-proxies` |
| --- | --- |
| `docker-compose.yml` | `${QT_TRUSTED_PROXIES_DIR:-./nginx/trusted-proxies}` |
| `docker-compose.release.yml`, `docker-compose.router.yml` | `${QT_TRUSTED_PROXIES_DIR:-/etc/quacktrack/trusted-proxies}` |

Without a file nginx starts normally and trusts no proxy: rate limits and logs then see the proxy's address instead of
the client's. List only the proxies that sit directly in front of this nginx; never a whole private range.
`*.conf` files in this directory are ignored by git.
