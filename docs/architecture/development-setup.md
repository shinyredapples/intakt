# Development setup

`compose.dev.yaml` is an override for working on Intakt in the container with file watching. Nothing of it is in the `Dockerfile`, the shipped `Caddyfile`, `compose.yaml` or the image: without the override, the image runs as the [container image](container-image.md) page describes.

## Starting it

```
composer install
docker compose -f compose.yaml -f compose.dev.yaml up --wait --build
```

Run `composer install` on the host first, with the dev packages: the override mounts the checkout over `/app`, which hides the image's own `vendor/` (built with `--no-dev`). The dashboard is on `http://localhost:7781`, the same loopback-only port as the demo; the override publishes no port and sets no `CADDY_ADMIN`, so the admin API stays on `localhost:2019` inside the container.

## What it changes

- `APP_ENV=dev` and `APP_DEBUG=1`.
- The checkout is mounted at `/app`, and `docker/dev/Caddyfile` over `/app/Caddyfile`, so `docker/entrypoint.sh` is unchanged.
- `var/cache` and `var/log` are named volumes, so they stay writable for the image's user (id 10001) whatever the host folder allows; `var/data` stays on the demo's volume.

## What is watched

`docker/dev/Caddyfile` is the shipped `Caddyfile` plus three things, and `DevelopmentCaddyfileTest` fails if the two differ in anything else:

- a `watch` in both workers (`app` and `mcp`) for `src/**/*.php`, `templates/**/*.twig`, `config/**/*.{php,yaml}` and `.env*`: a change restarts that worker's threads, so the next request serves it without a manual restart;
- `hot_reload` on the `php_server`, which reloads the browser through the Mercure hub, for the same folders plus CSS and JavaScript under `assets/`;
- the `mercure` block no longer sits in a `route` block, because FrankenPHP 1.13.4 finds the hub for `hot_reload` only outside one. The hub is served as before.

The folders are named instead of using the bare `watch`, whose default includes `var/`: Symfony rewrites its cache there in dev, and the workers would restart in a loop.
