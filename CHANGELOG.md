# Changelog

Each release is a tag `vMAJOR.MINOR.PATCH` and the image
`ghcr.io/artem-kolesnikov/memex:MAJOR.MINOR.PATCH`. When a release is tagged, its section
below is renamed from *Unreleased* to its version, and that section becomes the release's
notes.

## 1.9.6

- *Docs*, in the header, the account menu and the search operators' help, opens the
  documentation at docs.memex.tools in a new tab. It covers self-hosted memex as well as
  memex.tools. The in-app Docs page is gone.
- memex no longer writes a description or tags for what a connected assistant saves; the
  assistant writes its own. Notes you save on the website are described as before, when
  descriptions are on.
- Every memex tool has a title, which assistants show in their list of memex's tools, and
  tool descriptions that said something untrue are corrected.

## 1.9.5

- A header on every page holds the Activity log, Docs, the light and dark switch and the
  Settings gear.
- Docs is a new page of short how-tos with prompts to copy: connecting an assistant,
  finding notes, review, skills, your profile, curation, import and export. It opens from
  the header and from the account menu.
- The sidebar shows Skills again, between Notes and Review Inbox, and always shows
  Workspaces, with a line on how to add one while none is saved. Until an assistant has
  connected, *Connect an assistant* sits above your name.
- Saved filters are now workspaces: *Save as workspace* sits right after the active filters.
- On Notes, *New note* sits beside the list and map buttons, and the **?** beside the search
  box lists the search operators.
- Skills, Review Inbox and the Activity log keep their buttons beside the page title. The
  Skills page no longer has *Import*: give a `SKILL.md` to your assistant, which proposes it
  as a skill.

## 1.9.4

- Summaries, tags and titles work with every model your key offers. memex sent a fixed
  temperature, which current Claude models (Opus 4.7 and Sonnet 5 on) and OpenAI's o-series
  refuse, so on those models every description failed.
- The Activity page records every change by every writer: you in the browser, each
  connection and memex itself, from notes and proposals to verdicts, connections and skill
  settings. It starts with this version; earlier changes are not added.
- Dependencies patched.

## 1.9.3

- The `app:seed-demo` command and the fictional accounts it wrote are gone from the image,
  and the screenshots taken from them are gone from the README.

## 1.9.2

- The guide and the first welcome note open with a new description of memex, and the
  guide's section 18 is called *AI features*.
- What memex tells a connecting assistant about describing notes now says only whether it is
  switched on.

## 1.9.1

- A token made in Settings or with `app:create-token` can be copied again: *Copy token* in a
  connection's *Actions*, or the copy button in *Edit connection*. A token made in 1.9.0 is
  not kept, so its connection offers *Make a new token* instead, and the old token stops at
  once.
- The connection lines in Settings name this memex `memex-local`, and `health` returns its
  address, so an assistant connected to memex.tools as well can tell the two apart.

## 1.9.0

The first release of memex for a machine of your own: a laptop, a home server or a virtual
machine.

- One account, created at first run: with no code for ten minutes after the first start,
  and with the setup code the server prints after that. It signs in with a password.
- Notes with tags, wiki-links and search by meaning, run on the server's own model or on
  OpenAI with your key. What assistants write waits for your review by default.
- Assistants connect over MCP: by address with OAuth or a token, and Claude Desktop through
  the container.
- One Docker image, reached from this computer or, once you publish its port, your
  network. It writes a daily backup and a Markdown export to its volume.
