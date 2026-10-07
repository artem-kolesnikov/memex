---
title: "memex — skills"
description: "How to write, review and tighten a skill served through memex: what a description that triggers looks like, the size budget, the shape to follow, and how to propose the change so the owner sees it. Load it when asked to create, import, review or improve a skill, or when the Skills page names a lint finding."
short: "How a skill for memex is written, reviewed and improved."
---

# Writing and improving a skill for memex

A skill is a note in the owner's knowledge base tagged `skill`. memex serves it to every
connected assistant the owner allows, under a slug that never changes once served. You are
here because the owner asked for a new skill, an imported one, or a better one.

## What a good one looks like

- **A description an assistant can match on.** One or two sentences: what the skill does
  and when to use it, with the words a task would contain. "Helps with notes" matches
  nothing. "File a decision from a discussion: what was decided, why, what it replaces;
  use when the owner says decide, decided, or agreed" matches the moment it is needed.
  Keep it under 1,024 characters.
- **A body that is instructions, not an essay.** Steps in order, then the edge cases, then
  an example of the result. Under 5,000 tokens; an assistant loads all of it every time.
  Move reference material into a separate note and link it with `[[title]]`.
- **A title that is the skill's name.** The slug is derived from it the first time and then
  fixed, so pick a title you would type as a command.
- **Sources at the end**, under `## Sources`, if the instructions rest on something outside
  memex: what it is, and when to reread it.

## Creating one

1. Search the knowledge base first: `search(query: "<the task>")` and
   `search(tags: ["skill"])`. Another skill may already cover it; improve that one instead.
2. Load `get_skill("memex-writing")` if it is in your skill list, and follow it.
3. Write the note with `propose(title:, body_md:, summary:, tags: ["skill"])`. The summary
   IS the description. It lands pending; the owner opens the note from the Skills page
   to verify it, or reviews it in the inbox, and only then is it served.
4. Tell the owner the slug it will get and that the Skills page is where it is switched on,
   narrowed to connections, or renamed.

## Improving one

1. Load it: `get_skill("<slug>")`. Read the whole thing.
2. Check it against the list above. The Skills page's own findings name the same things:
   no description, a description too short or too long, a body over budget, a slug that
   carries a number because a title collided, a skill nobody has loaded in a month.
3. Propose the change as anchored patches: `propose(note_id:, patch: [{find:, replace:}],
   comment:)`. Never rewrite it whole unless the owner asked; never apply it yourself.
4. Say in one line what you changed and why the old text failed.

## Importing one

A `SKILL.md` from another tool comes in as a proposal: a new note tagged `skill`, its
`description` the summary, its body the note. `scripts/`, `references/` and `assets/` do
not come over: memex holds markdown only. If a skill depends on one of those, say so and
write what it did into the body, or link a note that carries it.
