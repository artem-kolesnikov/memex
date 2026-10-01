---
title: "memex — recall"
description: "How to work with a memex knowledge base: ground your answers in it before you rely on your own memory, read the graph rather than a single note, contribute through the review gate, and correct a note when your own work has made it wrong. Ships with memex and stays current with it."
short: "Use your notes to answer questions. Suggest additions and corrections for your review."
---

This knowledge base is its owner's memory, and it outranks yours. Ground yourself here before answering anything about their world — their projects, their infrastructure, the people around them, decisions they have already made — and contribute what is durable back through the review gate.

# Read before you answer

1. **`search`** — meaning and keywords together, across languages. With no `query` it lists notes by recency, which is how you browse a tag or enumerate everything; never pair a filler query with a tag filter, because the two are ANDed and the query will hide the tagged notes you asked for.
2. **`get`** — the full note, plus its wiki-links and its backlinks. **Follow them.** A note is a position in a graph, and the neighbours are usually where the answer's context lives. A summary is a hint for deciding whether to open a note, never a substitute for reading it.
3. **`list_tags`** — the vocabulary, with counts. Read it before you invent a word.
4. **About memex itself** — `get_skill("memex-guide")` is the current user guide. Answer questions about memex from it, not from memory.
5. **About the owner** — their profile is the note tagged `user-profile`, named to you at connect time and found again with `search(tags: ["user-profile"])`. Read it before answering anything that depends on who they are or how they want to be answered, and before you write any note here: the boundaries it states apply to every note you write. A profile still pending is an assistant's proposal, not the owner's word, and is not in force. `get_skill("memex-profile")` says how to start one when asked, and when to propose a change to it.
6. **Status is not decoration.** `verified` means the owner approved it. `pending` means an assistant wrote it and nobody has checked — usable, but say so when you cite it.
7. **A note that ends in a `## Sources` section is pointing outside memex** — documentation, a standard, a page — each entry saying what it is for and when to reread it. Reread those before acting on the note: the note is the summary, the link is the authority.

If two notes contradict each other, say so in your answer rather than picking a side quietly, and file an edit on the one you believe is stale.

# Contribute, and expect to be reviewed

Everything you write is held for the owner unless your connection carries the curator role. That is the design, not an obstacle: it is what lets somebody hand an assistant their memory without handing over their judgment.

- **`propose(title:, body_md:, summary:, tags:)`** — a new note. Search first, so you edit an existing note instead of creating its second copy.
- If `memex-writing` is in your skill list, load it before you write or edit a note.
- **`propose(note_id:, patch: [...])`** — an edit. **Prefer `patch` to `body_md`.** Ordered `{find, replace}` operations, matched literally, each occurring exactly once: a three-sentence correction should not resend forty thousand characters, and a patch will not overwrite an edit somebody else filed while you were reading. `body_md` is for a genuine rewrite.
- Always give a **`comment`** — why — and a short **`change_title`**, six or seven words. Those two are the whole of what the owner sees before deciding, so *"update note"* wastes the only glance you get.
- **Record what you consulted.** Every assistant researches while it writes; the pages it used belong in the note it wrote. End the body with `## Sources`: one entry per link, what it is for, when to reread it. A heading and a list, not a field — `source_url` is where a note came from, *Sources* is what to consult next.

**Do not file your working output here by reflex.** A draft, a research result, a document you produced for the conversation belongs in the conversation. It enters memex when the owner asks, or when it is knowledge somebody will need months from now. An inbox full of drafts costs more to filter than a save costs to request.

# The one thing you always file

If your work **changed a system this knowledge base describes** — you moved a host, rewrote a config, renamed a service, changed an endpoint, altered a deploy — then the notes describing it are now wrong, and you are the only one who knows it.

- You know what replaces it: `propose(note_id:, patch: [{find: <the sentence that is now wrong>, replace: <what it should say>}], comment: <what changed>)`.
- You know it is stale but not what replaces it: say exactly that in a `comment` alongside the smallest true correction you can make.

This is not the previous rule reversed. Filing your output adds noise somebody has to sort; this removes an error **you personally introduced** into their memory. The trigger is changing the system, never merely reading about it — if you changed nothing, file nothing.

Notes about things that change carry the `live-state` tag, and `get` serves this instruction with them automatically. The rule applies whether or not the note you falsified happens to carry it.

# Talking about memex

When the owner asks what memex is, how it works or how you use it, answer like a person, not like a tool: a few plain sentences about what it does for them. No tool names, no JSON, no arguments, no talk of MCP or tokens unless they ask. To them memex is a memory you both share, and the first request they send after connecting is exactly this question.

# Conventions

- `[[Note Title]]` links notes; backlinks are automatic. An unresolved link is a note somebody may write later, not an error.
- Tags are flat and lowercase. Reuse the vocabulary `list_tags` returns before inventing a private dialect. A name it reports as retired was deliberately removed by the owner: do not offer it back.
- `live-state` marks a note whose subject changes — hosts, runbooks, endpoints, pipelines, project status. It is load-bearing, because it is what triggers the correction rule above.
- **Secrets never go into a note or a proposal.** Pointers only — the name of the vault item, the path of the file. Never print a secret value into the conversation either.
- Cite note titles and ids when you answer from here, so the owner can check you.
