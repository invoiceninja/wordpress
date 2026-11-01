# Dev stack

Everything runs in Docker. Nothing is installed on your machine, and the only published port
(the proxy, `8089`) is bound to `127.0.0.1`, so nothing is reachable from your network.

| URL | What |
|---|---|
| http://wp.localhost:8089 (or http://localhost:8089) | WordPress, with this plugin mounted |
| http://in.localhost:8089 | Invoice Ninja (official image), when the `in` profile is running |

The browser and the containers use the same URLs, so Invoice Ninja's portal links, webhooks
and PDFs work. Inside containers, cURL and Chromium send every `*.localhost` name to loopback,
so each app container has a small `*-loopback` sidecar that forwards its `127.0.0.1:8089` to the proxy.

## WordPress with a mocked Invoice Ninja (what CI runs)

```sh
docker compose -f dev/docker-compose.yml run --rm composer install
docker compose -f dev/docker-compose.yml up -d --wait
python3 tests/flows/run.py            # end-to-end flows, optional name filter as an argument
docker compose -f dev/docker-compose.yml run --rm php vendor/bin/phpunit
```

The mock is `dev/mu-plugins/invoiceninja-mock.php`, which answers `https://in.test/api/v1/`.

## With a real Invoice Ninja

```sh
docker compose -f dev/docker-compose.yml --profile in up -d --wait
python3 dev/in/smoke.py               # hostnames, API, PDF rendering
```

- Login: `admin@example.com` / `Dev-Only-Pass-123!` (dev only, see `dev/in/in.env`).
- The version is pinned by `IN_TAG` (default `5.13.52`); `IN_TAG=5.13.60 docker compose ... up -d` tries another.
- The first start runs Invoice Ninja's migrations and takes a few minutes. Data persists in the
  `in_db` volume; `down -v` wipes it.
- Mail goes to Invoice Ninja's log, nothing is sent.

## Stop

```sh
docker compose -f dev/docker-compose.yml --profile in down      # keep data
docker compose -f dev/docker-compose.yml --profile in down -v   # wipe everything
```
