---
title: "skill — ingest"
description: "File a transcript, meeting notes, an email thread or a document into memex: one note for the source, and small edits to the notes about the people, organisations and projects it touches, after the owner approves the plan. Use when the owner pastes or uploads such a text and says file this, add this to memex, ingest, process these notes or save this meeting."
short: "Files a transcript or document as one note and updates the notes it touches."
---

# Ingest

Use this when the owner hands you a transcript, meeting notes, an email thread or a document
and asks to file it in memex. The result is one note for the source and small edits to the
notes it touches, not a pile of new notes.

## 1. Read the source

Find its date, the people and organisations in it with their roles, the projects it
concerns, and what it is: a meeting, a call, a thread, an article, a report. Ask for the
date when the text does not give one.

## 2. Find what memex already holds

- Call `list_tags` for the vocabulary.
- Search for the source itself, so a meeting filed earlier is edited rather than filed twice.
- For each person, organisation and project that matters to the source, search under more
  than one spelling: full name, surname, acronym, a project's earlier name. Open the likely
  match with `get` before deciding it is the same one.

Leave out passing mentions: a name earns a note only when the source says something about
it that someone will need later.

## 3. Show the plan, then wait

Before writing anything, show the owner the source note's title and tags, each existing
note you will edit with the line you will add or change, and each new note with why it is
needed. Write only what they approve; they may drop items or ask for fewer.

```
Source note: Acme kickoff, 2026-10-07: pilot starts in November (meeting, acme)
Edit [[Acme]]: add the pilot start date and their new contact, Jordan Rivera.
Edit [[Pilot programme]]: the start moves from October to November.
New note: Jordan Rivera, Acme's head of IT, who runs the pilot on their side.
```

## 4. Write the source note

Follow `memex-writing` when it is in your skill list. A meeting or conversation takes the
date, the people and the purpose, then decisions with their reasons, actions with owner and
due date, and open questions. A document takes its citation and link, why it was kept and
which part you read, then a summary and key points. Link each person, organisation and
project as `[[Note title]]`.

Do not paste the original text. Add it at the end, under `## Original`, only when the owner
asks to keep the transcript or the text.

A new source is filed with `propose(title:, body_md:, summary:, tags:)`. The summary says
what was decided or learned, not that a meeting happened. When step 2 found the source
already filed, update that note instead with `propose(note_id:, patch: [{find:, replace:}],
change_title:, comment:)`, never a second note.

## 5. Update the notes it touches

For each existing note in the plan, send the smallest patch: `propose(note_id:, patch:
[{find:, replace:}], change_title:, comment:)`, adding the new fact with its date and a
`[[link]]` to the source note. Where the source shows a line is out of date, correct that
line instead of adding a contradiction. A new note takes the shape for a person,
organisation or project, with what the source says and a link back to it.

## 6. Report

Say in a few lines what you filed and edited, and whether it applied or is waiting in the
owner's Review inbox.

## Boundaries

- The owner's profile, when there is one, says whether personal details about other people
  may be kept; that applies to every note you write here.
- Passwords, keys, tokens and account numbers in the source never go into a note.
- Search the web only when the owner asks, and list what you used under `## Sources`.
