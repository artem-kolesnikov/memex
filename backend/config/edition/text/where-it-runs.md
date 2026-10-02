**Where this memex runs.** This memex is local-first. It runs on its owner's own machine, a
laptop, a home server or a virtual machine, in one Docker container named `memex`, and
answers at `{{origin}}`. The assistants and agents on that machine reach it, and those
elsewhere on the owner's network when the owner has published it there. It does not face
the internet and has no HTTPS of its own: ChatGPT, Claude's connectors and Gemini Spark,
which call memex from their own servers, connect to memex.tools, memex's hosted edition.
Notes move between the two by export and import (sections 19 and 20).

Everything it keeps is in the container's `/data` volume: the knowledge base is a SQLite
file under `vaults/`, beside the server's own `directory.sqlite`; `secret.env` holds the key
that encrypts the provider keys added in Settings; and `backups/` holds the copy of the
database files and the Markdown export memex writes once a day, the last seven of each.
Search by meaning runs on a model inside the container unless Settings › AI features
chooses OpenAI (section 18).

The owner looks after it with commands, each run as
`docker exec -it memex php bin/console <command>`: `app:create-token <email> <name>` makes a
token for an assistant, `app:reset-password` replaces a forgotten password, since memex
sends no mail, and `app:setup-code` prints the setup code until the account exists. An
upgrade is a new image on the same volume. The README in memex's source repository gives
the steps for installing, upgrading, backing up and restoring.
