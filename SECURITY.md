# Security

## Reporting a vulnerability

Report it privately: this repository's *Security* tab › *Report a vulnerability*. Please
do not open a public issue for it. The fix ships as a release, and the advisory credits
you unless you ask otherwise.

## Supported versions

The latest release. Upgrading is a new image: README › Upgrade.

## What a memex server relies on

- **HTTPS in front of it** on any network but your own computer. `compose.yaml` puts Caddy
  in front; never publish port 8080 to the internet directly.
- **The account's password**, as long as you make it; guesses from one address are
  throttled. Tokens for assistants are shown once, and Settings › Assistants revokes them.
- **The `/data` volume.** It holds the notes, the provider keys you add (encrypted) and
  `secret.env`, the key that decrypts them. Protect it and every copy of its backups as you
  would the notes themselves.
