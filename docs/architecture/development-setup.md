# Development setup

`compose.dev.yaml` is an override for working on Intakt in the container with file watching. Nothing of it is in the `Dockerfile`, the shipped `Caddyfile`, `compose.yaml` or the image: `.dockerignore` leaves `compose.dev.yaml`, `docker/dev/` and the browser reload client's `templates/dashboard/dev/` out of the build context, and without the override the image runs as the [container image](container-image.md) page describes.

## Starting it

```
composer install
php bin/console importmap:install
docker compose -f compose.yaml -f compose.dev.yaml up --wait --build
```

Run both install commands on the host first: the override mounts the checkout over `/app`, which hides the image's own `vendor/` (built with `--no-dev`) and `assets/vendor/` (the scripts `importmap:install` downloads, without which a page that loads the import map, such as `/components`, fails). The dashboard is on `http://localhost:7781`, the same loopback-only port as the demo; the override publishes no port and sets no `CADDY_ADMIN`, so the admin API stays on `localhost:2019` inside the container.

## What it changes

- `APP_ENV=dev` and `APP_DEBUG=1`.
- The checkout is mounted at `/app`, and `docker/dev/Caddyfile` over `/app/Caddyfile`, so `docker/entrypoint.sh` is unchanged.
- All of `var/` is the named volume `intakt-dev-var`. Docker fills a new named volume from the image's `var/`, so it belongs to the image's user (id 10001) whoever owns the checkout's `var/` on the host: the entrypoint writes the cache, the log and the seed's mark `var/sample-ready` there. `var/data` stays on the demo's volume. The host's `var/` is not used by the container; `docker compose -f compose.yaml -f compose.dev.yaml down -v` removes both volumes.

## What is watched

`docker/dev/Caddyfile` is the shipped `Caddyfile` plus four things, and `DevelopmentCaddyfileTest` fails if their lines differ in anything else (it compares lines without braces, so it does not check nesting):

- a `watch` in both workers (`app` and `mcp`) for `src/**/*.php`, `templates/**/*.twig`, `config/**/*.{php,yaml}` and `.env*`: a change restarts that worker's threads, so the next request serves it without a manual restart. A change to `.env*` only restarts the workers, it reloads no browser, and a value that Compose passes into the container (such as those in `.env.local`) changes only when the container is created again;
- `hot_reload` on the `php_server`, which publishes a change to the Mercure hub, for the same folders plus CSS and JavaScript under `assets/`;
- `anonymous` in the `mercure` block, because the browser subscribes to that update without a token; private updates still need one;
- the `mercure` block no longer sits in a `route` block, because FrankenPHP 1.13.4 finds the hub for `hot_reload` only outside one. The hub is served as before.

The folders are named instead of using the bare `watch`, whose default includes `var/`: Symfony rewrites its cache there in dev, and the workers would restart in a loop.

## The browser reload

Under `hot_reload`, FrankenPHP gives each request the hub address to subscribe to in `FRANKENPHP_HOT_RELOAD`. When it is set, `templates/dashboard/dev/hot_reload.html.twig`, which `templates/dashboard/base.html.twig` includes with `ignore missing`, adds a `frankenphp-hot-reload:url` meta tag and a short script that subscribes with `EventSource` and reloads the page on each update. It loads nothing from outside, and without the override the variable is unset, so the page has neither (`HotReloadClientTest`). The image does not contain that template at all, and the base template renders without it (`HotReloadClientTest`, `DevelopmentCaddyfileTest::testNoTemplateTheImageCopiesCarriesTheBrowserReloadClient`).
