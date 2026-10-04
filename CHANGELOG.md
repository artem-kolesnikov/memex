# Changelog

Each release is a tag `vMAJOR.MINOR.PATCH` and the image
`ghcr.io/artem-kolesnikov/memex:MAJOR.MINOR.PATCH`. When a release is tagged, its section
below is renamed from *Unreleased* to its version, and that section becomes the release's
notes.

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
