# memex on a public server

ChatGPT, Claude's connectors and Gemini Spark call memex from their own servers, so they
need it at a public HTTPS address. Each way below keeps everything in one volume mounted
at `/data` and needs 1 GB of memory. Open the address once it is up and create the account:
for ten minutes after memex first starts the page asks only for an email address and a
password, and after that for the setup code too, which is in memex's log.

## A VPS or Amazon Lightsail, with Caddy

Caddy sits in front of memex, gets the HTTPS certificate and renews it. You need a Linux
server with 1 GB of memory or more, ports 80 and 443 open to the internet, and a domain
name whose DNS `A` record (and `AAAA`, for IPv6) points at the server.

On Lightsail: create a Linux instance with Ubuntu and the 1 GB plan, attach a static IP,
and under *Networking* add HTTPS (443) to the firewall beside SSH and HTTP.

On the server:

1. Install Docker with the Compose plugin:
   [docs.docker.com/engine/install](https://docs.docker.com/engine/install/).
2. Put `compose.yaml` and `Caddyfile` from this repository in a directory, and beside them
   a file named `.env` holding your domain:

   ```
   MEMEX_DOMAIN=memex.example.com
   ```

3. Start it, and open your domain within ten minutes; after that the page also asks for
   the setup code, which the second command prints:

   ```bash
   docker compose up -d
   docker compose logs memex
   ```

Only Caddy is published; memex is reachable from Caddy alone, which is why
`compose.yaml` sets `TRUSTED_PROXIES=REMOTE_ADDR`. Commands run the same way as on one
computer, `docker exec -it memex php bin/console <command>`, and upgrading is
`docker compose pull && docker compose up -d`.

## Fly.io

With [flyctl](https://fly.io/docs/flyctl/install/) signed in, in an empty directory:

1. Create the app and its volume, choosing a name and a region:

   ```bash
   fly apps create my-memex
   fly volumes create memex_data --app my-memex --region ams --size 1
   ```

2. Write `fly.toml`:

   ```toml
   app = "my-memex"
   primary_region = "ams"

   [build]
     image = "ghcr.io/artem-kolesnikov/memex:latest"

   [env]
     APP_BASE_URL = "https://my-memex.fly.dev"
     TRUSTED_PROXIES = "REMOTE_ADDR"

   [[mounts]]
     source = "memex_data"
     destination = "/data"

   [http_service]
     internal_port = 8080
     force_https = true
     auto_stop_machines = "off"
     min_machines_running = 1

   [[vm]]
     memory = "1gb"
   ```

3. Deploy, and open the address within ten minutes; after that the page also asks for the
   setup code, which the second command prints:

   ```bash
   fly deploy
   fly logs
   ```

memex keeps one machine running: a stopped machine cannot answer an assistant. Commands run
with `fly ssh console -C "php /app/backend/bin/console <command>"`; upgrading is
`fly deploy` again.

## Railway

1. In a new project, add a service from the Docker image
   `ghcr.io/artem-kolesnikov/memex:latest`.
2. Add a volume to the service, mounted at `/data`.
3. Under *Settings › Networking*, generate a domain for port `8080`.
4. Under *Variables*, set `APP_BASE_URL` to `https://` and that domain, and
   `TRUSTED_PROXIES` to `REMOTE_ADDR`.
5. Deploy, and open the domain within ten minutes; after that the page also asks for the
   setup code, which is in the deployment's logs.

## The address

Settings › Assistants shows this server's MCP address, `https://` and your domain followed
by `/mcp`, with each assistant's steps. If the domain changes, change `APP_BASE_URL` with
it: sign-in providers and memex's own commands take the address from it.
