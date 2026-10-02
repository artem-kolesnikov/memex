- **One account per server.** A memex holds its owner's notes and nobody else's.
- **No daily limits, and nothing to pay memex.** Search runs on this server, or on OpenAI
  with your own key; descriptions run on a key of your own, and your provider bills you.
- **A note body is at most 2 MB.** A save, a proposal, an approval, a merge or a revision
  restore that would take a note past the limit is refused with the size it observed and
  the limit, and that write leaves nothing behind; earlier files in the same upload or
  import that fit stay saved and are listed.
- **It runs on your machine.** Its backups and upgrades are in your hands, and so is how
  far it reaches on your network; the README says how.
- **Not on the internet.** It has no HTTPS of its own. ChatGPT, Claude's connectors and
  Gemini Spark, which call memex from their own servers, connect to memex.tools.
