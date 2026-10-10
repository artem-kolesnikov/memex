[![Licence: AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue)](LICENSE)
[![CI](https://github.com/artem-kolesnikov/memex/actions/workflows/ci.yml/badge.svg)](https://github.com/artem-kolesnikov/memex/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/artem-kolesnikov/memex)](https://github.com/artem-kolesnikov/memex/releases)
 
# memex

### Project notes *you and your AI* work from.

Memex is a simple notebook for you and your AI assistants. Ask your AI to save decisions and context. Use those notes with any assistant you connect. Skip the repeat explanations. Read, edit, and review suggested updates. You decide what stays.

Use our free cloud version at **[memex.tools](https://memex.tools)**, or install memex on your own computer and keep everything local.

The documentation for both is at [docs.memex.tools](https://docs.memex.tools).

-----
### Cloud vs self-hosted

|                                       | **Cloud memex**, free at [memex.tools](https://memex.tools)                        | **Self-hosted memex** (this repository)                                            |
| ------------------------------------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| How memex is accessed                 | `https://memex.tools/mcp`                                                         | `http://localhost:8080/mcp`                                                        |
| Memex                                 | Notes, review inbox, history, search, map, skills, import and export              | The same                                                                           |
| Assistants                            | Any assistants and agentic harnesses which can use MCP and connect over HTTPS.    | Assistants and agents on your computer or those which can access your own network. |
| Search by meaning                     | Included                                                                          | Included, as local model or via your own OpenAI API key                            |
| Descriptions and tags written for you | Included, through your connected assistants (using subscription), or your own API key | The same                                                                       |
| Sign-in                               | Google, Apple, Microsoft or GitHub                                                | Email and password                                                                 |
| **Infrastructure**                    |                                                                                   |                                                                                    |
| Where it runs                         | memex.tools's servers, on AWS                                                     | Your computer, a home server or a virtual machine                                  |
| Reached from                          | Anywhere, over HTTPS                                                              | That computer, or your network if you open it up                                   |
| What you need                         | A browser                                                                         | [Docker](DOCKER.md), 1 GB of memory to spare, and a browser                        |
| Updates                               | Always the latest version                                                         | You pull the new image when you choose                                             |
| Backups                               | Nightly, by memex.tools                                                           | Daily, on your own disk; copying them elsewhere is up to you                       |
| **Costs**                             |                                                                                   |                                                                                    |
| Price                                 | Free                                                                              | Free and open source                                                               |
| Limits                                | Very generous hourly and daily limits; unlimited on your own keys                 | None                                                                               |
| What you pay for                      | Provider keys, if you add any. And you don't have to                              | Your own hardware, and provider keys if you add any                                |

**[Start free on memex.tools →](https://memex.tools)** Sign in with Google, Apple,
Microsoft or GitHub. No password. To install it on your own computer, go to
[Run it](#run-it).

## Who is it for and how does it work?

Anyone who works with AI and wants a notebook made for it: one your assistant writes in while you talk, and you read, approve and come back to. Plans, research, client briefs, a family trip: whatever you work out with AI and don’t want to lose or explain again.

Memex doesn't pretend to be another professional AI memory solution. You don’t need a photo studio to crop a family picture, or a complex memory system to keep notes with your AI. Use your usual AI chat. Ask your assistant to read your notes, save what you work out, and suggest updates when things change. Open memex to review and edit them yourself.

An assistant helps only as much as it knows about you and your work. Memex keeps that in one place: your projects, your notes, your workflows and the skills you have written down.

1. **Start with what you have.** Import your existing Markdown notes, or start with the next thing you work out with AI.
2. **Connect the assistants you use.** Decide what they can read and which changes need your approval.
3. **Keep doing your thing.** Ask questions, explore ideas, work through the details. When something is worth keeping, say `save that to memex`.
4. **Review what your assistant wrote on your memex page.** Open the proposed note or update in memex. Read it, make any corrections, and decide what to keep.
5. **Use the notes. Keep them current.** Next time, start with asking your assistant to read the relevant notes.

Every assistant you use today will be replaced by a better one tomorrow. *What you worked out with them now stays with you.*

Try memex online at https://memex.tools, see how it works and if it fits your needs, and keep using it for free, or go with a local-first version below.

## Local-first memex in this repository

- **Your notes stay on your computer.** They live in a Docker volume there, and only the
  assistants you connect on that computer or your network can reach them.
- **Search by meaning is built in.** The model behind it comes with memex and runs on your
  computer, with no key and no cost.
- **If it talks MCP, it can connect.** Claude Code, Codex, Cursor, Gemini CLI, Claude
  Desktop, Hermes, OpenClaw, or an agent you wrote yourself.
- **Open source.** Read the code, change it and extend it, under the AGPL-3.0.
- **Your notes can move.** Export any note, or all of them, as Markdown. Import them into local vaults, or online vaults, including at [memex.tools](https://memex.tools), whenever you want them online, and back again.

> Memex is not a better memory product, and doesn't try to be one. If you already use one, keep it: it remembers you and your conversations, while **memex keeps what you made in them, as notes you can read and correct**. Both can be available to the same assistant.


| Feature              | Details                                                                                                                                                                                                  |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Notes                | What you ask your assistant to write down: a summary, the findings, the background. Each note is readable in the web app, from a short note to a detailed plan, with a description, tags and wiki-links. |
| Review inbox         | What an assistant saves is held for your approval. Trusted permissions are available if you want them.                                                                                                   |
| Corrections          | Open the note and change the sentence, or restore an earlier version.                                                                                                                                    |
| History              | Full note history with authors and dates. Restore any earlier version. Deleted notes stay recoverable for thirty days before their content is purged.                                                    |
| Search               | By meaning or by keyword, with filters on top.                                                                                                                                                           |
| Map                  | Every note and link as a picture, with views for orphans, hubs, islands and notes that may be stale, and suggested links between notes close in meaning. Each note shows its own neighbourhood.          |
| Duplicates and links | Before you save, the editor finds possible duplicates, notes the text names but does not link, and tags you already use.                                                                                 |
| Skills               | Every instruction and skill is a note you can open, edit or delete. Connected assistants see your skills and load one when a task calls for it, or when you ask.                                         |
| Tidying up           | Memex lists what needs attention; your own assistant works the list and files the changes for you to check.                                                                                              |
| Connections          | Each assistant connects with its own token, which you can revoke.                                                                                                                                        |
| Import and export    | Text and Markdown files in, including a whole vault. Every note out as a Markdown file, or the whole knowledge base in one click.                                                                        |

[Compare memex →](https://memex.tools/#compare)

## Run it

The easiest way is to let the AI on your computer do it. Give this repository to Claude
Code, Codex or any assistant that can run commands there, and ask:

```text
Install memex from https://github.com/artem-kolesnikov/memex and connect yourself to it.
```

It follows this section and the next one, and hands you the one step that is yours:
creating your account. To do it yourself, the steps are the same.

You need Docker on Linux, macOS, or Windows with Docker Desktop, on 64-bit x86 or ARM,
with 1 GB of memory to spare. To install it, follow [DOCKER.md](DOCKER.md).

```bash
docker run -d --name memex -p 127.0.0.1:8080:8080 -v memex:/data --restart unless-stopped ghcr.io/artem-kolesnikov/memex
```

memex is ready when this answers with `"status":"ok"`:

```bash
curl -fsS http://localhost:8080/api/health
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

An assistant can also connect itself from a shell once your account exists. This prints a
token once:

```bash
docker exec memex php bin/console app:create-token you@example.com "Claude Code"
```

With that token, Claude Code adds memex like this, and the others take the same address
and token in the lines Settings › Assistants gives:

```bash
claude mcp add --transport http --scope user memex-local http://localhost:8080/mcp --header "Authorization: Bearer YOUR_TOKEN"
```

What a connected assistant can do:

| | Tools | What for |
| --- | --- | --- |
| Read | `search`, `get`, `list_tags` | Find notes by meaning or keyword, read one with its links, reuse your tags |
| Write | `propose`, `propose_delete`, `propose_merge` | File a new note, an edit, a deletion or a merge, held for you by default |
| Skills | `list_skills`, `get_skill` | See the instructions you keep for it, and load one when a task matches |
| Tidy up | `inbox`, `needs_enrichment`, `curation_candidates`, `duplicate_candidates`, `blast_radius`, `last_curated`, `resolve_curation_flag` | See what is waiting and what needs attention, then file the work for you to check |
| Journal | `health`, `log`, `log_recent` | Check its connection and role; record and read what it did |

`log`, `log_recent`, `resolve_curation_flag` and `duplicate_candidates` appear once you grant the connection the **curator role** in Settings › Assistants.

The guide inside memex covers every screen and tool; ask any connected assistant to load
the `memex-guide` skill.

## On your network

`-p 127.0.0.1:8080:8080` lets only this computer reach memex. To reach it from other
machines on your network, as when it runs on a home server or a virtual machine, publish
the port there and give memex the address they use:

```bash
docker run -d --name memex -p 8080:8080 -e APP_BASE_URL=http://192.168.1.20:8080 -v memex:/data --restart unless-stopped ghcr.io/artem-kolesnikov/memex
```

`APP_BASE_URL` is `http://localhost:8080` unless you set it. Set it whenever memex is
published on another port or address; memex gives your assistants and its own commands
the address from it. How far it reaches beyond that is your own setup.

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

**Everything, from a backup folder** (`YYYYMMDD-HHMMSS` stands for the one you choose):

```bash
docker stop memex
docker run --rm -v memex:/data -v "$PWD/memex-backups/YYYYMMDD-HHMMSS:/backup:ro" --entrypoint sh ghcr.io/artem-kolesnikov/memex -c 'test -f /backup/directory.sqlite && test -d /backup/vaults && old=/data/before-restore-$(date +%s) && mkdir "$old" && mv /data/directory.sqlite* /data/vaults "$old"/ && cp /backup/directory.sqlite /data/ && cp -r /backup/vaults /data/ && echo restored'
docker start memex
```

Nothing is touched unless the folder holds a backup, and what was there before is moved to
`/data/before-restore-…` rather than deleted. Put `secret.env` back the same way if the
volume was lost.

**Notes only, from a Markdown export**: on a new memex, Settings › Notes › *Import* takes
the ZIP. Each note arrives with its tags, description and dates; history, connections and
settings stay behind. An export from memex.tools imports the same way, and back.

## Commands

Run each as `docker exec -it memex php bin/console <command>`.

| Command | What it does |
| --- | --- |
| `app:setup-code` | Prints the setup code again, until the account exists. |
| `app:create-owner <email>` | Creates the account from a shell instead of the browser. |
| `app:reset-password` | Sets a new password and signs out every browser. memex sends no mail, so this is how a forgotten password is replaced. |
| `app:create-token <email> <name>` | Creates a token for an assistant, as the *Other* tab does. |

## What runs inside memex

As installed, the one model in memex is the one behind search by meaning, and it runs
on your computer. With a key of your own in Settings › AI features, memex can also write a
description and tags for a note that arrives without them. Nothing else changes your notes
by itself, and what an assistant writes waits in your inbox, by default, until you approve
it.

## Licence

memex is free software under the GNU Affero General Public License, version 3 only
([LICENSE](LICENSE)). If you run a modified memex for other people, the licence asks you
to offer them its source. The name, wordmark and logo are not under that licence:
[TRADEMARKS.md](TRADEMARKS.md). Third-party material is listed in [NOTICE](NOTICE).
Security reports: [SECURITY.md](SECURITY.md). Issues and pull requests:
[CONTRIBUTING.md](CONTRIBUTING.md).
