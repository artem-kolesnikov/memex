# Changelog

Each release is a tag `vMAJOR.MINOR.PATCH` and the image
`ghcr.io/artem-kolesnikov/memex:MAJOR.MINOR.PATCH`. When a release is tagged, its section
below is renamed from *Unreleased* to its version, and that section becomes the release's
notes.

## Unreleased

The first release of memex for one person's own computer or server.

- One account, created at first run: with no code for ten minutes after the first start,
  and with the setup code the server prints after that. It signs in with a password, and
  with Google, Apple, Microsoft or GitHub when the server sets them up.
- Notes with tags, wiki-links and search by meaning, run on the server's own model or on
  OpenAI with your key. What assistants write waits for your review by default.
- Assistants connect over MCP: by address with OAuth or a token, and Claude Desktop through
  the container.
- One Docker image. It writes a daily backup and a Markdown export to its volume; with
  `compose.yaml`, Caddy serves it over HTTPS on a public server.
