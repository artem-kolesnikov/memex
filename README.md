# memex

**A knowledge base your assistants write to, and you approve.**

memex keeps Markdown notes with tags, links between them and search by meaning. Ask
Claude, ChatGPT, Codex or whatever you use: it reads your memex and answers from your
notes, and what it writes waits in your review inbox by default, so nothing lands without
an author and a way back. This is memex for one person on their own computer or server,
free and open source. A hosted edition runs at [memex.tools](https://memex.tools).

## Run it

You need [Docker](https://docs.docker.com/get-docker/) on Linux, macOS, or Windows with
Docker Desktop, on 64-bit x86 or ARM, with 1 GB of memory to spare.

```bash
docker run -d --name memex -p 127.0.0.1:8080:8080 -v memex:/data --restart unless-stopped ghcr.io/artem-kolesnikov/memex
```

Open <http://localhost:8080> and create your account with your email address and a
password. It is the only one this server holds, and your memex opens on three notes that
show you around.

For ten minutes after memex first starts, that is all the page asks. After that it also
asks for a setup code, which proves you run the server; memex prints it in its log:

```bash
docker logs memex
```

The container has to be called `memex` for Claude Desktop's setup to find it. Everything
memex keeps is in the `memex` volume.

## Connect your assistants

Settings › Assistants gives each assistant's steps with this server's own address filled in.

- **Claude Code, Codex, Cursor, Gemini CLI**: the *Other* tab creates a token and gives the
  command or settings lines, pointing at `http://localhost:8080/mcp`.
- **Claude Desktop**: the *Other* tab gives the lines for `claude_desktop_config.json`.
  Claude Desktop starts memex's own command inside the container, so nothing listens for it
  on the network.
- **ChatGPT, Claude (its connectors on the web, desktop and phone) and Gemini Spark** call
  memex from their own servers, so they need it at a public HTTPS address:
  [docs/hosting.md](docs/hosting.md).

## A public server

[docs/hosting.md](docs/hosting.md): a VPS or Amazon Lightsail with Caddy in front for HTTPS
(`compose.yaml` and `Caddyfile` here), Fly.io, or Railway.

## Upgrade

```bash
docker pull ghcr.io/artem-kolesnikov/memex
docker rm -f memex
docker run -d --name memex -p 127.0.0.1:8080:8080 -v memex:/data --restart unless-stopped ghcr.io/artem-kolesnikov/memex
```

The notes stay on the volume, and memex brings their files up to date when it opens them.
With `compose.yaml`: `docker compose pull && docker compose up -d`.

## Backups

Once a day memex writes a copy of its database files and a Markdown export of your notes
to `/data/backups`, and keeps the last seven. They are on the same volume as everything
else, so copy them off the machine:

```bash
docker cp memex:/data/backups ./memex-backups
```

Settings › Notes › *Export* downloads the Markdown export at any time.

The keys you add in Settings › AI features are stored encrypted with `/data/secret.env`.
Keep a copy of that file somewhere safe as well (`docker cp memex:/data/secret.env .`);
without it, a restored memex asks for those keys again.

## Restore

**Everything, from a backup folder** (`20261001-000000` stands for the one you choose):

```bash
docker stop memex
docker run --rm -v memex:/data -v "$PWD/memex-backups/20261001-000000:/backup:ro" --entrypoint sh ghcr.io/artem-kolesnikov/memex -c 'test -f /backup/directory.sqlite && test -d /backup/vaults && old=/data/before-restore-$(date +%s) && mkdir "$old" && mv /data/directory.sqlite* /data/vaults "$old"/ && cp /backup/directory.sqlite /data/ && cp -r /backup/vaults /data/ && echo restored'
docker start memex
```

Nothing is touched unless the folder holds a backup, and what was there before is moved to
`/data/before-restore-…` rather than deleted. Put `secret.env` back the same way if the
volume was lost. With `compose.yaml` the volume has the same name, `memex`; stop with
`docker compose stop memex` and start with `docker compose start memex`.

**Notes only, from a Markdown export**: on a new memex, Settings › Notes › *Import* takes
the ZIP. Each note arrives with its tags, description and dates; history, connections and
settings stay behind. An export from memex.tools imports the same way, and back.

## Configuration

Set these with `-e NAME=value` on `docker run`, or under `environment:` in `compose.yaml`.

| Variable | What it does |
| --- | --- |
| `APP_BASE_URL` | The address people use: `http://localhost:8080` unless you set it. Set it whenever memex is reached anywhere else, such as `https://memex.example.com` on a public server; sign-in providers and memex's own commands take the address from it. |
| `TRUSTED_PROXIES` | The TLS proxy in front of memex: an address, a range, or `REMOTE_ADDR` when nothing but the proxy can reach memex. |
| `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET` | Optional sign-in with Google, linked from Settings › Account. |
| `GITHUB_OAUTH_CLIENT_ID`, `GITHUB_OAUTH_CLIENT_SECRET` | The same, with GitHub. |
| `MICROSOFT_OAUTH_CLIENT_ID`, `MICROSOFT_OAUTH_CLIENT_SECRET` | The same, with Microsoft. |
| `APPLE_OAUTH_CLIENT_ID`, `APPLE_OAUTH_TEAM_ID`, `APPLE_OAUTH_KEY_ID`, `APPLE_OAUTH_PRIVATE_KEY` | The same, with Apple; the private key is a path to the `.p8` file inside the container. |

A provider's redirect address is `APP_BASE_URL` followed by `/api/auth/<provider>/callback`,
where `<provider>` is `google`, `github`, `microsoft` or `apple`.

## Commands

Run each as `docker exec -it memex php bin/console <command>`.

| Command | What it does |
| --- | --- |
| `app:setup-code` | Prints the setup code again, until the account exists. |
| `app:create-owner <email>` | Creates the account from a shell instead of the browser. |
| `app:reset-password` | Sets a new password and signs out every browser. memex sends no mail, so this is how a forgotten password is replaced. |
| `app:create-token <email> <name>` | Creates a token for an assistant, as the *Other* tab does. |

## What memex does not do

memex does nothing on its own: no background model, no scheduled pass over your notes.
It keeps notes, not files: a PDF becomes a note about the PDF. It holds one person's
notes, with no shared teams. Review is work, and drafts wait until you open the inbox.
The guide inside memex covers every screen and tool; ask any connected assistant to load
the `memex-guide` skill.

## Licence

memex is free software under the GNU Affero General Public License, version 3 only
([LICENSE](LICENSE)). If you run a modified memex for other people, the licence asks you
to offer them its source. The name, wordmark and logo are not under that licence:
[TRADEMARKS.md](TRADEMARKS.md). Third-party material is listed in [NOTICE](NOTICE).
Security reports: [SECURITY.md](SECURITY.md).
