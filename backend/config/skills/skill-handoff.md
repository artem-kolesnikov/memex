---
title: "skill — handoff"
description: "Save where a piece of work stands so that any assistant can pick it up later, and pick it up again. Use when the owner says hand off, save where we are, wrap up for today or let's stop here, or says pick up, resume, continue or where did we leave something."
short: "Saves where a piece of work stands, and picks it up again in any assistant."
---

# Handoff

Two moves: **save** where a piece of work stands, and **pick it up** later, in this
assistant or another one. The handoff note is a bookmark, not a report: one screen at most,
and one note per piece of work, updated each time rather than added to.

## Save

When the owner says *hand off*, *save where we are*, *wrap up* or similar:

1. Name the work in a few words. Ask when the chat covered more than one thing.
2. Look for its handoff note first: `search(query: "<the work> handoff")`. When one exists,
   update it with a `propose` patch instead of writing a second one.
3. Otherwise write a new note titled *<The work> — handoff*, with these sections in this
   order:
   - **Goal**: one sentence.
   - **Where it stands**: what is done, with the decisions made and why.
   - **Next**: the next concrete step first, then the ones after it.
   - **Open questions**: what is undecided, and who decides it.
   - **Where things are**: files, links, and the other notes it depends on, each note as
     a wiki-link to its title.
   Put the date at the top. Reuse the tags `list_tags` returns; when the work already has
   a tag, use it.
4. Tell the owner in one line what you saved and that it is waiting in their Review inbox,
   or that it applied at once when your connection is a curator.

## Pick up

When the owner says *pick up*, *resume*, *continue* or *where did we leave* something:

1. `search(query: "<the work> handoff")`, open the note with `get`, and read the notes it
   links to.
2. Answer in five lines or fewer: the goal, where it stands, and the next step. Then ask
   whether to start on that step.
3. When the note is still pending, say so: the owner has not approved it yet.
4. When there is no handoff note, say so and offer to search memex for the work instead.
