# memex

**A knowledge base your assistants write to, and you approve.**

memex keeps Markdown notes with tags, links between them and search by meaning. Ask
Claude, ChatGPT, Codex or whatever you use: it reads your memex and answers from your
notes, and what it writes waits in your review inbox by default, so nothing lands without
an author and a way back. This is memex on a machine of your own, a laptop, a home server
or a virtual machine, free and open source. It is local-first: the assistants on that
machine and your network reach it. [memex.tools](https://memex.tools) is memex on the
internet, for the assistants that reach it from there.

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

To run the code in this repository rather than the published image, build it from a clone
under the image's name first, and the commands here use it:

```bash
docker build -t ghcr.io/artem-kolesnikov/memex .
```

## On your network

`-p 127.0.0.1:8080:8080` lets only this computer reach memex. To reach it from other
machines on your network, as when it runs on a home server or a virtual machine, publish
the port there and give memex the address they use:

```bash
docker run -d --name memex -p 8080:8080 -e APP_BASE_URL=http://192.168.1.20:8080 -v memex:/data --restart unless-stopped ghcr.io/artem-kolesnikov/memex
```

How far it reaches beyond that is your own setup. Do not publish memex to the internet: it
has no HTTPS of its own, and nothing here covers putting it there.

## Connect your assistants

Settings › Assistants gives each assistant's steps with this server's own address filled in.

- **Claude Code, Codex, Cursor, Gemini CLI**: *Connect an assistant* creates a token and
  gives the command or settings lines, pointing at `http://localhost:8080/mcp`, or at the
  address you gave memex.
- **Claude Desktop**: the same screen gives the lines for `claude_desktop_config.json`.
  Claude Desktop starts memex's own command inside the container, so it works on the
  computer that runs memex, and nothing listens for it on the network.
- **Hermes, OpenClaw and agents of your own** add memex as an MCP server at the same
  address and send the token on every request as `Authorization: Bearer <token>`.
- **ChatGPT, Claude's connectors on the web, desktop and phone, and Gemini Spark** call
  memex from their own servers over the internet, so they connect to
  [memex.tools](https://memex.tools), memex's hosted edition, which is free. A Markdown
  export from either imports into the other.

## Upgrade

```bash
docker pull ghcr.io/artem-kolesnikov/memex
docker rm -f memex
docker run -d --name memex -p 127.0.0.1:8080:8080 -v memex:/data --restart unless-stopped ghcr.io/artem-kolesnikov/memex
```

Run it with the same options you started it with. The notes stay on the volume, and memex
brings their files up to date when it opens them. If you built the image yourself, `git pull`
and build it again instead of `docker pull`.

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
volume was lost.

**Notes only, from a Markdown export**: on a new memex, Settings › Notes › *Import* takes
the ZIP. Each note arrives with its tags, description and dates; history, connections and
settings stay behind. An export from memex.tools imports the same way, and back.

## Configuration

Set these with `-e NAME=value` on `docker run`.

| Variable | What it does |
| --- | --- |
| `APP_BASE_URL` | The address memex is reached at: `http://localhost:8080` unless you set it. Set it when memex is published on another port or on your network, such as `http://192.168.1.20:8080`; sign-in providers and memex's own commands take the address from it. |
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
Security reports: [SECURITY.md](SECURITY.md). Issues and pull requests:
[CONTRIBUTING.md](CONTRIBUTING.md).
