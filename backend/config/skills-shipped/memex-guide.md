---
title: "memex — guide"
description: "What memex is and how every screen, setting and assistant tool works: notes, the three ways in, the review gate, connections and roles, skills, curation, services and spend, export and deletion, and the MCP tool reference. Load it to answer any question about memex. Ships with memex and stays current with it."
short: "Help you use memex, from saving your first note to managing settings and connections."
---

This guide explains what memex is, how it works, and what every screen, setting and
assistant tool does. It is written for you and for the assistants you connect: when you or
your assistant has a question about memex, the answer should be here. Nothing in it is a
promise about the future; it describes memex as it is.

{{edition:operator}}

**About this guide.** memex serves it to your assistants as the `memex-guide` skill, so
ask one anything about memex. It is never copied into your knowledge base, so it always
describes memex as it is now.

---

## Contents

**Part I — What memex is**
1. What memex is, and what it is not
2. How a note lives here
3. Three ways in
4. Your assistant proposes, you decide

**Part II — The web app, screen by screen**
5. Signing in and your account
6. Notes: search and browse
7. A note open
8. Writing and editing
9. Review inbox
10. Activity log
11. Map
12. Skills
13. Settings

**Part III — Assistants**
14. Connecting an assistant
15. Roles: agent and curator
16. Skills and standing instructions over MCP
17. Curation, if you ever want it
18. AI features, and whose money they spend

**Part IV — Your data**
19. Export
20. Import
21. Deleting, restoring, and what is kept

**Part V — Reference**
22. MCP tool reference
23. What memex does not do
24. Glossary
25. Questions people ask

---

# Part I — What memex is

## 1. What memex is, and what it is not

memex is a knowledge base your assistants write to, and you approve. You keep talking to
Claude, ChatGPT, Gemini or whatever you use, the way you already do. When something is worth
keeping, you say so, and your assistant writes it into memex as a note. Later, any assistant
you have connected can read that note back. Between those two moments, you are the one who
decides what stays.

Every note is plain Markdown with a title, tags and links to other notes. Search works by
meaning as well as by keyword. Every note downloads as a text file, and the whole knowledge
base downloads as one archive, whenever you want.

Some things memex deliberately is not:

- **There is no AI inside memex.** memex has no chat box and generates no text of its own.
  The thinking is done by you and by the assistants you already use. The one exception is
  optional: if you add a provider key of your own, memex can write a short description and
  file tags when a note arrives without them. That is described in section 18.
- **Nothing works on your notes by itself.** memex never tidies, curates or summarises
  anything unasked. The one thing it does on a clock is housekeeping: a deleted note's
  text is purged after its thirty restorable days (section 21). What memex does keep is
  the record: who wrote what, and when.
- **One person, one knowledge base.** There are no shared teams. Your assistants are the
  only other things that can reach your notes, and only on the terms you set for each.
- **It is not a file store.** Notes are text. A PDF or a spreadsheet becomes a note *about*
  the document, written by your assistant when you ask; the original stays where it was.

memex sits alongside the memory features your assistants already have. Those remember how
you like an answer. memex holds the work you and your assistants produced and chose to keep,
in your own words, readable by every assistant you connect.

{{edition:where-it-runs}}

## 2. How a note lives here

A note has:

- **A title**, required, up to 500 characters. Titles do not have to be unique, but links
  between notes resolve by title, so a distinct title is a well-connected note.
- **A body** in Markdown. Headings, lists, tables, code blocks, task lists and links all
  render. Anything a Markdown file can hold, a note can hold.
- **Tags**, a flat list of lowercase words. There are no folders and no nested tags. Three
  tags are special and are explained in section 12: `skill`, `live-state` and `user-profile`.
- **A description** (the field is called *summary* in the assistant tools): two or three
  sentences saying what the note holds, so that a person or an assistant can decide
  whether to open it. Your assistant writes it when it saves a note; you can edit it; memex
  can write one on your own key if you switch that on.
- **Wiki-links**: `[[Title of another note]]` anywhere in the body, or `[[Title|shown
  text]]` to change the visible words. A link resolves to the note with that title
  (case-insensitive; a filename or folder path from an imported vault also works). A link
  to a note that does not exist yet is kept as written and starts working the moment a
  note with that title appears. Links inside code blocks are not links. A note's web
  address or number is not a link: both belong to one account, so an export or a move
  breaks them.
- **Backlinks**: every note shows which other notes link to it.
- **Provenance**: how the note first arrived (written here, imported from a file, written
  by an assistant, or by memex, which only the welcome notes are), the source URL if the
  writer gave one, who added it and who last changed it. Every author is named: a person by name, an assistant by the
  name and icon you gave its connection, memex itself when it wrote a description.
- **A status**: *verified* or *pending review*. Section 4 explains the difference.
- **History**: every change that alters the title, body, tags or description is kept as a
  version. Open the history, see what changed, restore any earlier version. The twenty
  most recent versions are kept per note.
- **A number and an address.** Notes are numbered within your knowledge base, in the order
  they were created, and a number is never reused. The address of a note is
  `{{origin}}/<your handle>/notes/<number>`, where the handle is a twelve-character code
  that identifies your knowledge base and never changes. Assistants refer to notes by the
  same number.

**Sources.** A note may end with a *Sources* section: a list of links to what lies outside
memex and governs what the note says — a vendor's documentation, a standard, a page an
assistant read while writing — each with a word on what it is for and when to reread it.
It is a convention, not a field: a `## Sources` heading and a list, so it exports with the
note and any editor can write it. Assistants are told to record the sources they consulted
under that heading when they file a note, and to reread a note's sources before acting on
what it says. It is not the same as provenance: the source URL above says where a note came
from; *Sources* says what to consult next.

## 3. Three ways in

You may only ever use one of these, and that is fine.

1. **Your assistant writes it.** You say "save that to memex", and your connected assistant
   files a note. By default it arrives in your review inbox as *pending* and becomes part of
   the knowledge base when you approve it.
2. **You write it in memex.** *New note* opens an editor with a formatting toolbar, live
   preview and link autocomplete. Notes you write are verified at once.
3. **You upload a file, or a whole vault.** Markdown and text files, one at a time or as a
   ZIP of a folder (an Obsidian vault, for instance). Frontmatter titles and tags are
   honoured, and links between imported notes resolve. Section 20 has the details.

memex fetches nothing from the web itself. A page you want kept is a page your assistant
reads and files. The built-in `memex-writing` skill (section 12) tells it how: the citation
and link, why the page was kept, and which part it actually read. The same goes for a PDF
or a deck.

Whichever way a note arrives, it is the same kind of note afterwards.

## 4. Your assistant proposes, you decide

No model checks its own work, so by default nothing an assistant writes changes your
knowledge base until you have seen it.

**New notes from an assistant** arrive with the status *pending review*. A pending note is
already visible: it appears in your Notes list with a clock mark, it can be searched, it
can be linked to, and assistants can read it. What it cannot do is count as verified: it is
not served as a skill, and a filtered export leaves it out unless you ask for it. Approve
it (with or without your own edits) and it becomes verified. Reject it and it goes to
Deleted notes, restorable for thirty days like any deletion.

**Edits to existing notes** are *held*. The note does not change; the proposed change waits
in the inbox with a diff. Approve it and the change is applied, credited to the assistant
that proposed it, and recorded in the note's history. Reject it and it is gone. An assistant
can also send a *report*: a comment saying something in a note is wrong, without proposing
the fix. Acknowledging a report changes nothing on the note.

**Deletes and merges** are always held, for every assistant, whatever role it has.

**One held item per note per assistant.** If the same assistant proposes a second change
to a note it already has in review, the second call revises the first draft rather than
queueing another. Two different assistants can each have their own draft on the same note.

**You can always write over it.** Editing a note yourself in the browser applies at once.
If an assistant has a draft waiting on that note, the editor tells you before you save, and
saving your own version discards the draft.

**The Curator exception.** You can promote one or more connections to the *curator* role
(section 15). A curator's new notes land verified and its precise edits apply on the spot,
each one recorded in Activity with a diff. Even a curator's deletes and merges wait for you,
and a curator that wants to replace a note's whole body is held too. Only you can grant the
role, from a browser; no assistant can change its own role or another's.

---

# Part II — The web app, screen by screen

## 5. Signing in and your account

{{edition:sign-in}}

**Devices.** Settings › Account lists every browser signed in to your account, with a
*Sign out* for each and *Sign out everywhere else*. A session lasts thirty days of
inactivity. *Sign out* in the account menu ends the current one.

**The account menu** opens from your name at the foot of the sidebar: *Activity log*
(section 10), *Skills* (section 12), *Personalization* (Settings › Personalization),
*Settings* with the *Sunrise* and *Midnight* switch beside it, and *Sign out*.

**A new account** opens on the Notes list, holding five notes. memex wrote three of them:
*Welcome to memex*, *Connect your first assistant* and *Make memex yours*, which say how to
connect an assistant or an agent, what happens to what it writes, how skills work, and where
your profile and memex's writing rules are set. Their links to Settings and the inbox open
those pages of your own memex. The other two are starter skills, switched on: *Plain
writing*, for drafting text someone else will read, and *Handoff*, for saving where a piece
of work stands and picking it up again in any assistant. All five are yours: edit them,
switch the skills off on the Skills page, or delete any of them. memex starts no profile
for you; the welcome notes say how to start one.

## 6. Notes: search and browse

*Notes* is the home screen: every note in your knowledge base, newest change first, with a
count. *List* and *Map* at the top switch between two views of the same search; the map is
section 11.

**Searching.** Type in the box and press Enter or *Search*. memex first looks for notes that
contain all your words literally, in title or body; if any do, those are the results, best
keyword match first. Only when no note contains the words literally does memex search by
meaning, comparing your query with every note's embedding and returning the closest. So a
search for a phrase you remember finds the note that holds it, and a search for a concept
finds notes that discuss it in other words.

**Operators.** The search box and the assistant's `search` tool read the same expression
language, for the question a plain search cannot ask: *this but not that*.

- `memex memory` — both words, anywhere in the title or body, in any order. This is the
  plain search and it has not changed.
- `memex OR memory` — either word. `|` means the same.
- `NOT ai` or `-ai` — notes that do not contain the word. `!ai` means the same.
- `( … )` — grouping: `(memex OR memory) NOT ai`.
- `"review gate"` — the words in that order, next to each other.
- `AND` is accepted and means what the space already means.

*Without parentheses, words next to each other bind before `OR`*: `memex memory OR ai`
reads as `(memex memory) OR ai`. *Operators are uppercase words*: `cats or dogs` is a
three-word search, and `not` in a sentence is a word. *A hyphen inside a word is part of
it*: `memex-oss` is a word, `-oss` is an exclusion. *The symbols are operators wherever
they stand*: `cat|dog` is `cat OR dog`, and `!` always negates what follows. *Words match by stem*: `notes` finds
*note*, and English stop words such as *the* and *of* are ignored on both sides, so
`NOT the` excludes nothing.

A query carrying `OR`, `NOT` or a quoted phrase is an exact one. It runs only against the
literal text, ranked by keyword relevance, and never falls back to meaning-search — the
nearest notes in meaning to *NOT ai* would be the ones about ai. When nothing matches, the
page says so under the box, and the `search` tool says so in `exact_match`; the empty
result is then the answer, not a search that fell short. Tags and status combine with an
expression the way they combine with any search. Malformed input never errors: a trailing
`OR`, an unclosed parenthesis or an unmatched quote is read as far as it goes.

{{edition:search-unavailable}}

**Filters.** *All tags* narrows to notes carrying every tag you pick. *Status* offers *Any
status*, *Verified*, *Pending review*, *Flagged for curation* and *Not described yet*. From
a note's page you can also open *All notes added by* a particular assistant. Active filters
show as chips with *Clear all*.

**Saved filters.** *Save filter* stores the current search terms, tags and status under a
name and an icon, in the sidebar under *Workspace*. Up to fifty. Removing one leaves your
notes untouched. Merging a tag moves a saved filter onto the tag it merged into; removing
a tag takes it out of the filter.

**The list.** One row per note: the title, with a status mark before it (a clock for
pending review, a flag for flagged; verified notes show no mark), its description beneath,
and beside them when it was last modified, by whom, and its tags. The row opens the note;
the checkbox at its left selects it. Twenty-five, fifty or a hundred rows per page. Ticking notes shows a selection bar
that downloads them as one Markdown file or a ZIP of several.

## 7. A note open

The note page shows the title, its number, its status, when it was last modified and by
whom, then the description and the rendered body. Wiki-links open the note they point to.

**Actions.** *Edit note*, *Flag for curation* (section 17) and *Download .md*. A pending
note also shows *Approve* and *Reject*.

**Note details.** When it was created and by whom (with a link to everything that
connection added), when it was modified and by whom, the source (only when the writer gave
a URL or the note was imported from a file), its size in words and approximate tokens, and
its tags.

**Local neighbourhood.** A small map of this note and the notes it links to or is linked
from, one or two hops out, with *Open full map*. Links that lead nowhere are listed
beneath it.

**Backlinks.** Every note that links here.

**History.** Each earlier version with what changed, when, and who did it. A proposal you
approved is credited to the assistant that proposed it; if you amended it before
approving, the version is marked as your edit. Open a version to see the diff or the
whole text, and *Restore this version* to put it back. Restoring is itself a new version, so
it can be undone. *Forget history* permanently removes earlier versions and the retained
content in related Activity records and applied proposals. If a shared curation run
refers to the note, its narrative is cleared too; identities, actions and times remain. Held drafts must be decided first; otherwise forgetting is refused without erasing
anything. It does not erase the current note, independent copies, exports or backups.

## 8. Writing and editing

*New note* from the Notes screen, or *Edit note* on a note.

**The editor.** A Markdown editor with live preview: markup is hidden except on the line
you are editing, and *Write* / *Preview* switches to a full rendering. The toolbar offers
undo and redo, bold, italic, strikethrough, inline code, headings, bullet, numbered and task
lists, links, *Link to a note* and tables. Typing `[[` opens autocomplete over your note
titles. Save with the button or Ctrl/⌘+S. Leaving with unsaved changes asks first.

**The rail.** *Summary* with a counter (the target is about 350 characters; longer is
allowed), *Tags* (type to search your vocabulary or create a new tag; tags you have
removed from your vocabulary are not offered, though typing one brings it back), and
*Original source* when the note came from a URL.

{{edition:editorial-assistant}}

**Import note…** (new notes only) fills the draft from a Markdown or text file; frontmatter
`title:` and `tags:` are honoured. Nothing is saved until you save, and a note you save this
way is verified because you saved it.

**Conflicts.** If the note changed while you were editing (an approved proposal, a curator's
edit, another tab), saving offers *Open current note*, *Keep editing* or *Overwrite with my
version*. If an assistant has an edit waiting in the inbox, the editor says so before you
save; saving your version discards that draft.

**Danger zone.** *Delete this note* moves it to Deleted notes (section 21). If drafts are
waiting on it, deleting also discards them, and the dialog says so.

## 9. Review inbox

Everything an assistant has filed waits here: new notes, held edits, reports, deletions and
merges, newest first, with a count at the end of the *Review Inbox* row in the navigation;
while the sidebar is collapsed the count stands in for the icon. *Show* filters by kind.

Each card names the kind, the note, the assistant, how long ago, and the reason it gave.
Open a card to see the proposed document: a line-by-line diff of the body, tags added and
removed, and the description.

**Verdicts.** For an edit, *Apply edit* or *Reject*. For a report, *Acknowledge* or
*Dismiss*. For a deletion, *Delete note* or *Reject proposal*. For a merge, *Merge into
this note* or *Reject proposal*. For a pending note, *Approve note* or *Reject note*.

**Amend** lets you change the title, body, tags or description before approving. What you
approve is what is applied; the assistant is still credited with the change, and your
amendment is recorded as yours. Approval is tied to the version shown: if the draft or a
note it affects changes, approval stops and leaves the work waiting. Refresh the review
before deciding again. Your unsaved amendment is kept until you explicitly discard it to
refresh.

**Batch.** Tick several cards, optionally give one reason for the whole batch, and *Approve
selected* or *Reject selected*. The confirmation says what the batch contains, and nothing
in it is opened. Each selected item keeps its displayed version; changed items remain
waiting and are reported as failures while unchanged items can succeed.

A rejected note goes to Deleted notes for thirty days. A rejected edit is simply removed.
An edit that no longer applies because the note changed underneath it shows "This edit no
longer applies" and cannot be approved. Only you, in a browser, can decide anything here;
assistants can read the inbox but never approve or reject.

## 10. Activity log

*Activity log* in the account menu. The journal of everything written to your knowledge base and every decision made about
it: notes created and edited by curators, proposals filed, approvals and rejections, flags
raised and resolved, tags removed or merged, descriptions written by memex, and the run
summaries curators record. Each row names the actor, and rows that changed a note offer
*Show diff*. Filters: by action, by assistant, and by scope (*All activity* or *Curation
passes only*), plus a search box. Nothing here is ever deleted.

*Curation* switches to one row per curation pass (section 17). *Request logs download*
saves the journal for a date range as Markdown or CSV.

Your own ordinary edits are not journal rows; they live in each note's history.

## 11. Map

*Map*, beside *List* at the top of *Notes*, draws the same notes as a picture: every note
is a dot, every wiki-link a line drawn the way it was written. The search box, *All tags*
and *Status* stay where they are and work as they do on the list, except that what they
leave out is dimmed rather than removed, so you still see where the matches sit. Red marks
notes that need work (no tags, no description, no links either way, links that lead
nowhere), yellow marks notes you flagged, and notes waiting for review are faded. Position
means nothing; only colour and size do.

**Views:** *Everything*, *Orphans*, *Hubs*, *Neighbourhood*, *Possibly stale*, *Islands*,
each with a sentence saying why you would look. *Suggested links* adds dashed lines between
notes that are close in meaning but not linked; a dashed line is a suggestion, not a fact.
*2D* and *3D* switch between the flat map and a flyable view that separates clusters a flat
map has to overlap; 3D needs a mouse or trackpad. On either, drag a note to move it. Very
large collections are capped at six hundred notes on the map, flagged and defective ones
first.

**The box.** Clicking a note opens a box over the map with its description, tags, what it
needs, its links either way and *Open note*; clicking one of its tags shows that tag's
notes. With no note picked, *Orphans*, *Hubs*, *Possibly stale* and *Islands* list their
notes in the same box. It opens in the top right corner; drag its bar to move it, and it
stays there until you close it. Closing puts it back in the corner, and the next note or
view you click opens it again.

The map is a way to navigate and to spot what needs attention. It is not a knowledge graph:
memex extracts no entities and no facts.

Settings › Preferences › *Map* chooses flat or 3D, dots or squares, curved, straight or arrowed
links, and whether labels show titles, numbers or nothing.

## 12. Skills

A skill is a note about how you want a job done, and memex advertises it to every connected
assistant without ever applying it. Connected assistants are handed the list of skill titles
and descriptions; when a job matches one, the assistant asks for that note and follows it.
Nothing runs because it exists; it runs because the work matched it.

**Making one.** Tag a note `skill` and it is served once it is verified; a note your
assistant proposes waits in the inbox first, like any note. Or press *New skill* on the
Skills page, which opens the editor with the tag already set and a short scaffold — when to
use it, steps, sources — for you to fill in. Or use *Import* to bring in a `SKILL.md` from
another tool, a plain `.md`, or a zip of skill folders; each one lands as a verified note of
your own.

**The Skills page** (*Skills* in the account menu) has three sections: *Your enabled skills*,
*Available skills*, then *Built into Memex*. Your enabled skills are ready to use.
Turning one off moves it into Available skills, alongside skills waiting for review.
Turning it back on moves it into Your enabled skills. Failed saves show
an error and switches return to the saved state.
System skills appear at the bottom as compact cards with only their name, short description,
and estimated token size. They cannot be edited or limited to particular connections, and
only `memex-writing` can be switched off, in Settings › Personalization; while it is off,
its card says so and links there.

The summary counts enabled skills and shows their combined *Full instruction size*.
The bar shows each skill's share of that total: muted built-ins first, then your enabled
skills. Colors match the cards below. Hover, tap, or focus a segment to see its name,
estimated tokens, and share of the total. Built-ins are always available and managed by Memex;
your skills are yours to edit or turn off. Disabled, pending, and uninstalled skills
are excluded. Turning a skill on or off updates the count, total, and bar.
Each estimate is the instruction text's byte length divided by four, rounded up.
Actual model token counts vary; custom system instructions may differ by connection.
This is the size of the enabled library, not a context limit or measured chat usage.
Instructions load when requested, subject to each skill's connection access.
Recorded loads remain on individual cards and in their panels. A load means Memex sent
instructions; it does not prove the assistant followed them.

Your card's title and description come from its note title and saved summary. The skill
panel shows that summary and links to the note editor. System and template descriptions
come from files bundled with memex. *Usage example* is always visible on each user skill card and gives a prompt naming the
skill by its alias, with a place for your task. The copy icon copies that prompt;
it is labelled *Copy prompt*. Copying is enabled when the skill is available. For example, save your writing rules as a skill, then
ask a connected assistant to use it to rewrite an update. Replace the example's brackets
with the task or text you want help with.

**A skill's panel.** *Open skill* shows the note summary, then *Who can use this skill*
with connection checkboxes. Below it, *Skill alias* and *How to use this skill* occupy
two equal-width, equal-height columns. The alias has a *Save* button; the example
has an embedded copy icon. The usage statistics line follows these columns. There are
no expandable sections or top-right close icon; the top-right corner holds the switch. Very long titles show up
to three lines in the panel; the full title remains in the note and the heading tooltip. There is one *Enable this
skill* switch in the top-right corner. Turning it on makes the skill available through all supported MCP access
paths (tools, resources and prompts); turning it off stops access without changing the
note. Users do not choose between those technical paths. Enabling a skill makes its
instructions available, but does not guarantee that an assistant will choose to use it.

Connections are arranged in two equal columns. Select individual connections and press
*Save connections* to limit access. Both *Save connections* and the alias field's *Save* are bordered buttons. An
*Unsaved changes* label stays visible until the selected connections are saved. *All connected
assistants* also includes future connections. The UI refuses to save an empty selection;
turn the skill off instead. This avoids confusing an empty selection with the API's
empty grant list, which means unrestricted access.

The visible *Alias* field next to the chat example is the skill's exact lookup
name (its slug). Most users can leave it alone. Renaming the note does not change this
name, but changing the alias means older prompts using it need updating. Names
are fixed when first served or opened here; collisions get a numbered suffix within
64 characters, even when another request claims a name concurrently. Pending notes have
distinct temporary download names. Names accept lowercase letters, digits and single
hyphens, with no whitespace. Taken names are refused without revealing the other note.

Use *Edit this skill* to open the note editor. Review pending skills in the inbox. The panel
also shows loads by connection, the last recorded load time, and suggestions. Sections stay visible. Full instructions
are available through *Edit this skill* or *Download SKILL.md*, rather than repeated in the panel.
These actions sit at the left of the footer; *Cancel* sits at the right. Cancel closes
the panel and discards unfinished alias and connection selections; changes already
saved, including the immediate on/off switch, remain saved. Suggestions flag missing or overly short/long descriptions, instruction bodies
over about 5,000 estimated tokens, name collisions, and skills not loaded in 30 days.
*Download SKILL.md* saves the portable file.

**System skills.** `memex-recall` explains reading and writing here; `memex-curation`
contains curation rules; `memex-profile` explains profile notes; `memex-guide` is this
guide; `memex-skills` explains writing and reviewing skills; and `memex-writing` is how to
write a note here, tuned by the presets you pick in Settings › Personalization. They update
with memex and are enabled for every connection, `memex-writing` unless you switch it off.
Enabled means available to load, not that their full text is inserted into every chat.

**memex-writing** is established practice for writing a note, drawn from style guides,
standards and studies of how people and assistants read: write for a reader who was not
in the conversation, absolute dates, exact identifiers, no invented reasons or sources, no
chat leftovers, and the usual parts of nine kinds of note — decision, procedure, checklist,
problem and fix, meeting or conversation, project status, source, concept, and person or
organisation — leaving out any part the conversation did not supply. Your presets set its
scope, opening, format and reasoning (section 13). Your own writing instructions, in a
skill note or your profile, add to it; where the two differ it wins. Choose your own
writing rules instead and it is switched off. It is an instruction to the assistant,
never something memex checks, and what you ask in a conversation comes first.

**Improving one.** Ask your assistant — "tighten the description on my meeting-notes skill", or
just point it at a finding on the page. It loads `memex-skills`, which tells it what a
description an assistant can match on looks like, the size a skill should stay under, and to
propose the change as a patch rather than apply it, so the edit waits in your inbox like any
other.

**Your profile** is a note about you — who you are, what you are working on, the tools you
use, how you want to be answered, what to leave alone — tagged `user-profile`. memex names
it to every assistant when it connects, and every assistant that reads it is asked to
propose a small correction when a conversation shows a line has changed. It is an
ordinary note: you edit it in the editor, an assistant's edit always waits in your inbox,
curator or not, and deleting it or taking the tag off makes it an ordinary note again. A
profile an assistant proposed is named to the others while it waits, so none proposes a
second one, but it is not in force: its instructions and boundaries are not followed until
you approve it. Settings › Personalization shows
whether you have one, opens it, or starts one; or ask your assistant to start one from
what it already knows about you. Keep it short — it is read often. Its sections say what
you have not written yet in italics, and an assistant treats such a section as unknown.
memex's own instructions for it are the built-in `memex-profile` skill, not lines in your
note, so nothing you write there has to be preserved. What goes in is yours to decide:
the profile's *Boundaries* section is where you say whether personal details you share may
be kept in notes, and assistants apply that to every note they write here. Two profile
notes are allowed; assistants are told about both and choose neither.

**Three tags memex itself reads.** `skill` is one. `live-state` is the second: tag a note
`live-state` when it describes a system that changes (a server, a configuration, a
project's current status), and every assistant that reads it over MCP is asked, in the same
reply, to correct the note if its own work has just made it wrong. `user-profile` is the
third, above. None of the three words can be removed from your vocabulary, because
removing it would silently switch the behaviour off for every note carrying it;
individual notes can gain or lose any of them freely.

## 13. Settings

Settings is a dialog over whatever you were doing. Six panes in two groups; on a phone,
the section picker uses the same groups. Old settings links still reach their section.
All panes share one content grid and section spacing. Paired fields use equal columns
and stack on phones. Connections, sign-in accounts, browsers, and curators use the same
table layout, with labelled rows on phones and actions in the final column.

{{edition:settings-account}}

**Personal → Preferences**
- *Appearance*: *Sunrise* (light) or *Midnight* (dark), note text size and font (Sans,
  Serif, Mono). These are remembered per browser. The account menu also switches themes.
- *Language*: the interface language, from those installed.
- *Date & time*: date format and time zone, per browser.
- *Map*: layout, note shape, link style and labels, saved to your account.
- *Version*: the version of memex serving the page.

**Personal → Personalization**
- *Writing notes*: the presets for `memex-writing` (section 12). A two-sided switch chooses
  *Use memex writing rules* (left, the default) or *Use my own writing rules* (right); the
  side it points at is the one in use. With your own, memex-writing is off: assistants are
  not pointed at it, it leaves their skill list, and they follow only the writing rules you
  add as a skill on the Skills page — without one there, nothing tells them how to write
  notes. The table has one row per setting — *Scope*,
  *Opening*, *Format*, *Reasoning* — with its choices, defaults first, and the exact text the
  current choice adds to the skill. Scope: *One subject*, *One idea* or *Whole topic*, plus
  *Keep it short*, which does not go with a whole topic and is disabled beside it. Opening:
  *Summary, then sections*, *Answer first* or *Context first*. Format: *Form follows
  content*, *Prose* or *Bullets*, plus *Minimal markup*. Reasoning: *Conclusion and
  reasons*, *Conclusions only* or *Full rationale*, plus *State how sure* and *Cite outside
  sources*. Each change saves on its own and shows *Saving…*, *Saved* or *Not saved*; a
  change made meanwhile in another tab is refused and the current settings are shown. A line
  under the table says where assistants are pointed at the skill and which connections have
  loaded its current text — loaded, not followed. *The whole skill* shows the full text as
  your presets make it. Only you, in a browser, can read or change these settings.
- *About you*: your profile note (section 12). With none, *Add profile* opens two short
  screens over Settings. *Choose a few preferences* offers optional choices for answer detail
  and sensitive details in any notes, with nothing preselected; *Review changes* shows the
  exact sentences to add or remove, and *Save and continue* writes them. *Let your assistant
  add more* offers an example message to copy into a connected assistant's chat. Finishing
  returns to Settings. With one, *Open profile* opens that note; with several, each is listed, none chosen
  for you. A profile waiting for your review says it is not in force. A failed lookup offers
  *Try again* instead of treating the profile as missing. *What assistants are told about
  it* shows, word for word, what every connection is told about your profile when it
  connects. *Ask an assistant to help write it* expands an example message you can copy.

**Your memex → Notes**
- *Import* and *Export*: bring in files or ZIPs (section 20), or download the notes.
- *Tags*: every tag in use, with a count. Click one to search. The × opens a dialog to
  remove it from every note or merge it into another. Both are immediate and irreversible;
  no note is deleted. `skill`, `live-state` and `user-profile` show a padlock.
  *Blocked tag suggestions* lists removed words that Memex will not suggest again;
  *Start using* allows one again.
- *Deleted notes*: how many are restorable and *Open deleted notes* (section 21).

**Your memex → Assistants**
- *Connect an assistant*: setup instructions. For existing connections, press the button
  to open the guide; with no connections, the guide is already open.
- Connected assistants and agents, with rename and revoke controls (section 14).
- *Note maintenance*: first choose a curator with *Make curator*, then copy the message
  under *Start a maintenance pass* to a chat with that assistant (section 17). Custom maintenance presets are not exposed in the interface.

**Your memex → AI features**
- *Search* and *Descriptions and tags*, with usage and provider controls
  (section 18). Maintenance is in Assistants; Memex does not run a curator.

---

# Part III — Assistants

## 14. Connecting an assistant

Every connection speaks MCP, the standard assistants use to reach tools, at one address:

```
{{origin}}/mcp
```

**Connect an assistant** (Settings › Assistants) opens the connection guides: four tabs,
ChatGPT, Claude, Gemini Spark and *Other*, and for the first three one numbered
instruction at a time, with a picture of the screen it describes, the address
to paste, *approve sign-in with Memex*, and the prompt to paste last. *Back* and the
numbered dots move between instructions. Each guide is a few minutes. ChatGPT's Developer
mode and custom plugins and Claude's custom connectors are available on free accounts,
though a free Claude account holds one custom connector; Gemini Spark's custom apps need a
paid Google AI Pro or Ultra plan.

{{edition:assistants-connect}}

**Signing in from the assistant.** When you add the connector, the assistant sends you to
memex to approve it. The consent page names the assistant and the site it connects from,
shows which account you are signed in as, and says what the connection will be able to do:
its writes land in your review inbox as pending, and nothing becomes verified without your
approval. *Connect it* creates the connection. It appears in the Connected table at once,
marked *OAuth*.

**The prompt.** The last step of every guide is a short prompt to paste into a new chat.
It tells the assistant to use your memex as the source of truth about you, your work and
your plans, to check it before answering about those things, to offer to save what you
decide, and to read this guide first. For ChatGPT and Gemini the prompt
asks the assistant to keep this in its own memory; Claude does not keep it between chats,
so paste the same text into your Claude profile preferences or a Project's instructions.

**Checking a new connection.** Ask the assistant to read this guide and run the check
below. It exercises read, search, instruction loading and write in that order, takes under
a minute, and finds the failure you cannot see from the browser: a connection that reads
but cannot write, or writes somewhere you never look. If you are an assistant that has
just been connected, run it and report each result in plain language.

1. Call `health`. Report the knowledge base name and the role it says you hold. If this
   fails, nothing below will work and the problem is the connection.
2. Call `search` with no query. A new knowledge base holds the welcome notes memex wrote;
   any note will do. Fetch one with `get` and confirm you can see its body, which proves
   full bodies reach you, not titles.
3. Call `list_skills`. `memex-guide` should be in it, along with the owner's own skills.
   Load one with `get_skill`.
4. Call `propose` with a short note of your own, titled *Connection check*, saying which
   assistant you are, when you ran this, and what the three steps above returned.
5. Read the answer `propose` gave you. If it says the note is pending, tell the owner it
   is waiting in their review inbox and that this is correct: the review gate is doing its
   job. If it applied immediately, tell them this connection holds the curator role and
   writes without waiting.

Then say plainly which of the five worked.

**Per-call permissions.** Out of the box, ChatGPT and Claude ask you before each call
until you tell them not to: in ChatGPT, Settings › Plugins › Memex, then *Allow all
actions*; in Claude, Settings › Connectors › Memex, then *Always allow* for both *Read-only
tools* and *Write/delete tools*. Gemini asks before write actions and has no switch. That
is the assistant's behaviour, not memex's, and it is safe to switch off: with the agent
role, new notes arrive in your review inbox and edits, deletions and merges wait for your
approval; a curator connection's creates and edits apply at once, and its deletes and
merges still wait.

**Other** is the tab for anything that cannot complete a browser sign-in: a coding agent,
a self-hosted agent, a script. Give the connection a name and *Create token*. The token is
shown once; copy it then. The agent sends it on every request as the header
`Authorization: Bearer <token>`. Below the address, setups for Claude Code, Codex, Cursor
and Gemini CLI give the command to run or the lines to add to the assistant's settings
file, with `YOUR_TOKEN` where the token goes. These connections show *Manual* in the table.

{{edition:assistants-other}}

**Connected assistants & agents** lists every connection with its name, how it
authenticated, when it was created and when it last called in. *Rename* opens the identity
editor: the displayed name, an optional description, and an icon (a provider logo, a
glyph, or an uploaded image). The name and icon you choose are what every byline shows for
that assistant across memex. *Revoke* marks the connection; *Save changes* confirms, and the
connection stops working immediately. A revoked assistant gets "Invalid or revoked token"
on its next call and nothing else. Its past bylines stay.

Every new connection, however made, starts with the *agent* role.

## 15. Roles: agent and curator

**Agent** is the default and the review gate in full: new notes pending, edits held,
deletes and merges held. An agent can read everything, including the inbox and the curation
queue, and can file anything; nothing it files applies until you approve it.

**Curator** is for a connection you trust to work unattended. A curator's new notes land
verified. Its edits apply immediately when they are precise (an anchored find-and-replace,
a new title, tags or description), and each applied edit is recorded in Activity with the
diff. The exception is instructions: a new note tagged `skill` or `user-profile`, any
change to a note carrying either tag, and adding or removing either tag all wait for you,
because they change what every other assistant is told. A curator that replaces a whole
note body is held anyway, because a whole-body rewrite is exactly what needs eyes. A curator may also choose to hold an edit it is unsure
about. Its deletes and merges are always held. Curators can also record curation passes in
the journal, read the journal to learn from your past verdicts, and close the flags you
raise; agents cannot.

**Granting it.** Settings › Assistants › *Note maintenance* › *Choose a curator*: choose an assistant
and press *Make curator*. The text there says exactly what changes. *Remove role* takes the role away. Only you,
from a browser, can grant or remove the role. No token can change a role, including its
own, and connections made through an assistant's sign-in never arrive with the role even
if the assistant asks for it. `health` tells an assistant which role it holds.

## 16. Skills and standing instructions over MCP

When an assistant connects, memex hands it a short instruction text before anything else:
that you write descriptions and tags yourself; that `list_skills` returns instruction sets
you have approved and `get_skill` loads one; that a note's *Sources* section is what to
reread before acting on the note, and where to record what it consulted; that its writes
are reviewed; and what curation it may and may not do in its role. The wording differs for
agents and curators.

Skills are served as **tools** (`list_skills` and `get_skill`), **resources**
(`memex://skill/<slug>`) and **prompts**, which some clients display as commands.
The Skills page has one enable switch and opens all these access paths when a skill is
turned on. The underlying API retains separate surface flags for compatibility.
Six skills ship with memex and are listed first: `memex-recall`, the
conventions for reading and writing this knowledge base; `memex-curation`, the curation
procedure; `memex-profile`, how to find, read and maintain your profile note; `memex-guide`,
this guide; `memex-skills`, how to write, review and improve a skill; and `memex-writing`,
how to write a note, while you keep it switched on. Your own served skill notes follow,
newest first. The shipped skills appear on the Skills page as compact system cards; they
have no note to edit. Only `memex-writing` changes with your
settings, and only through its presets.

A skill's enable switch is one setting for the whole knowledge base: turning it off
or on changes access for every connection, not just one. Narrowing a
skill to a set of connections is the part that varies per connection — only the connections
you choose are served it, and every other connection is served nothing for that skill.
memex's transport is one request and one reply, never a stream, so it cannot push a change
to an assistant mid conversation; the list reaches an assistant three ways instead. At
connect time, the `initialize` instructions name exactly what that connection is served.
The `list_skills` tool description carries the same list, so a client that keeps tool
descriptions in context for the session already has it without calling anything. And every
tool result, including a failed load, carries a short `skills_version`; when it differs from the one that connection
last saw, including a change to a description, the same result also carries `skills_changed`, the served list in one line — so
the next call after you change something is where the assistant learns of it, never sooner.

`memex-writing` reaches assistants the same way. While it is on, the `initialize` text
carries a short paragraph, *Writing notes*, telling the assistant to load it before it
writes or edits a note and that what you ask comes first; `memex-recall` says the same in
one line, and the description of `propose` ends with it. The first tool result after you
change a preset or the switch carries `personalization_changed` once: load the skill again,
or, when you switched it off, that it no longer applies. Off, none of these pointers is
sent.

`live-state` works only through `get`: the reply for a note carrying that tag includes a
standing instruction to correct the note if the assistant's own work changed the system it
describes, by proposing a patch to the sentences that are now wrong or, failing that, a
comment saying what changed.

`user-profile` works at connect time and through `get`. The instruction text every
connection receives names your profile note (or says there is none, and how to look
again), and the reply for the note itself carries a reading instruction: it outranks what
the assistant remembers about you, an unwritten section is unknown, the boundaries in it
apply to every note written here, and a change is proposed as a small patch only when the
conversation shows a line has stopped being true. A profile that is still pending gets a
different instruction: it is not in force, and only there so no second profile is
proposed. A note carrying both tags receives the profile instruction alone.

## 17. Curation, if you ever want it

Most people never need this section. Notes go stale, tags drift, links break; memex finds
that, keeps a queue of it, keeps the instructions a curating assistant should follow, and
keeps a record of every pass. It never runs one. Curation happens when you ask one of your
assistants for it.

**What memex keeps.**
- *The queue.* Every note is checked for defects: no tags, no description, not yet
  searchable by meaning, no links either way, links that lead nowhere, tag names to tidy,
  a title that looks like a filename, and anything you flagged. The queue ranks them:
  flagged first, then the most defective, then notes whose neighbours changed, then notes
  no pass has read, then the longest unseen. A note that comes through a pass clean earns a
  rest that doubles each time, so a settled knowledge base produces a short queue.
- *The instructions*, the `memex-curation` skill: who may do what, what to hunt and in
  what order, how to close a pass. Every assistant loads the same text; what differs is the
  role.
- *The record.* Activity › *Curation* shows one row per pass: what was read, what was
  applied, what was filed for you, and whether the assistant's own account of the pass
  matches what memex logged. Open a pass and it lists the notes it changed and the notes
  it read, each linked. Rows waiting on you link to the inbox.
- *Flags.* On any note, *Flag for curation* with a comment saying what is wrong and what you
  want done. The next pass starts there. A curator closes the flag by saying what it did,
  and disagreeing with you is a valid answer.

**Asking for a pass.** Settings › Assistants › *Note maintenance* › *Choose a curator* promotes a
connection; below it, *Start a maintenance pass* provides a message to copy into a chat
with that assistant or into a scheduled task: load the `memex-curation` skill and follow it, noting the start
time. The section links to scheduled tasks in ChatGPT and in Claude, because a pass works
best on a schedule the assistant runs. *Last run* shows when each curator last loaded the
instructions. An agent-role connection can run the same pass; everything it does is held for
you, and it cannot write the run record.

After a pass, the assistant reports to you in the chat you started it from. memex reports
nothing on its own.

## 18. AI features, and whose money they spend

{{edition:ai-features}}

---

# Part IV — Your data

## 19. Export

**One note.** *Download .md* on the note, or tick it in the list. The file is the note's
body with YAML frontmatter: title, tags, description, who wrote the description, source,
source URL, status, created and updated dates. Wiki-links stay as written, so the file
drops into a vault unchanged.

**Several notes.** Tick them in the Notes list and download a ZIP. Each file is named after
its note's title, so the ZIP opens as an Obsidian vault and `[[Title]]` opens the note; a
note imported from a vault goes back to the folder and file it came from, unless that file
was named only after its title, as memex's own export names them. Characters a file
name cannot hold (`: / \ ? * " < > | # ^ [ ]`) become a space, so in Obsidian a link to such
a title does not open; imported back into memex, it links again. Notes whose titles match,
ignoring case, get the note number added. Tags keep their exact text, and exported Markdown
preserves body whitespace. A failed archive write produces an error.

**Everything.** Settings › Notes › *Export* › *Download notes*: one ZIP, one file per note,
pending notes included. History, deleted notes and the journal are not in it; the journal
downloads separately from Activity. The same ZIP moves your notes to another memex account:
imported there (section 20), each note arrives with its tags, description and dates.
Connections, keys, settings, history and deleted notes stay behind.

**Skills.** The skills export API downloads a ZIP with one folder per served
skill, each holding a `SKILL.md` — the format Claude Code, Codex and other tools that read
Agent Skills already understand. A single skill downloads the same way, as one file, from
its panel.

## 20. Import

{{edition:import}}

Frontmatter `title`, `tags` (a list or a comma-separated string), `summary`, `summary_by`
(who wrote the description), `created` and `updated` are read; every other key is dropped.
A date without a time zone, as Obsidian writes one, is read as UTC, and a date in the future
is ignored. A file without a title takes its filename. Two options for
ZIPs: *Skip notes whose title already exists* (on by default) and *Top-level folders become
tags*, plus a field for extra tags to put on everything imported. *Analyze* first shows
what would be created and what would be skipped; *Import* does it.

Links between imported notes resolve by title and by path, in any order, and a final pass
after the import connects them. If the archive contains notes you deliberately deleted
before, memex says so and holds them out unless you tick *Import them again*. Imported
notes become searchable by meaning as the index catches up over the following minutes.

**Skills.** *Import* on the Skills page takes a `SKILL.md`, a plain `.md`, or a zip of skill
folders. The frontmatter `name` becomes the slug and `description` becomes the summary; the
rest of the file becomes the note body. `scripts/`, `references/` and `assets/`, if the
package carries them, are not imported — memex holds markdown only — and are named in the
report so you know what to bring over by hand. An invalid explicit name or malformed
frontmatter is reported for that file without creating a note; other valid files still import.
Without a name, the title or filename supplies one. Plain Markdown files, including
frontmatter, must fit both the note-size and import-size limits before they are read.

## 21. Deleting, restoring, and what is kept

**Deleting a note** moves it to Deleted notes, where it stays restorable for thirty days at
its original number and address. While it is there it is out of search, out of export and
invisible to assistants; notes that linked to it show the link as leading nowhere. Its tags
are dropped from your vocabulary if nothing else carries them, and any drafts waiting on it
are discarded (the dialog warns you; a restore brings the note back, not the drafts). Its
history is kept.

**Restoring** (Settings › Notes › *Open deleted notes* › *Restore*) puts the note back
with its tags, status, authorship and history; links to it resolve again.

**After thirty days** the text is purged and a tombstone remains: the title, the reason,
the dates and who deleted it. A tombstone cannot be restored, and it stops a later import
from silently bringing the note back. *Purge now* also clears the remaining content fields
and related Activity narratives, including shared run narratives. Audit identities, actions
and times remain. The tombstone title stays to prevent accidental re-import. Use it for a
note that must genuinely be gone, such as one that carried a credential.

**Assistants cannot delete.** A delete from any connection, curator included, is a
proposal in your inbox.

**Tags** removed or merged from Settings › Notes are gone from the notes at once, with
no thirty-day limbo; the notes themselves are untouched.

**Your account** can be deleted from Settings › Account, immediately and completely. There
is no recovery. Export first.

---

# Part V — Reference

## 22. MCP tool reference

memex speaks MCP over plain HTTPS at `{{origin}}/mcp`: one JSON-RPC request per
call, no streaming. A connection authenticates with the bearer token memex minted for it,
either through the assistant's sign-in flow or a token you created on the *Other* tab of
Settings › Assistants.
An invalid or revoked token gets "Invalid or revoked token". Every note id on the wire is
the note's number in your knowledge base, the same one in its address. Proposal and journal
ids are numbered within your knowledge base the same way.

Each call answers with structured JSON. A refused call (a missing argument, an anchor that
no longer matches, an allowance reached, a role the token does not hold) comes back as a
tool error with a sentence saying why. Lists page with `offset` and `limit` and report a
`total`. Long note bodies page with `max_chars` and `body_next_offset`.

The tools, in the order memex lists them:

**search** — Find notes by meaning and keyword, or browse. With a `query`, results are
ranked as section 6 describes, and the same operators apply — `OR`, `NOT` or `-word`,
parentheses, `"quoted phrases"` — matching words as written with no fall-back to meaning,
which the reply says in `exact_match`; without one, it lists notes filtered by `tags` (all must
match) and `status` (`verified` or `pending`), ordered by `order` (relevance, or updated or
created, ascending or descending). Up to twenty-five per page. Each result carries the id,
title, description, status, source URL, tags and last update, and sometimes which section
matched. Tag names that match nothing are reported back as `unknown_tags`. If meaning-search
could not run, the reply says so in `semantic_search_unavailable`, and an empty result then
means only that no note contains those words. Open to every role.

**get** — Read one note by id: the full body, tags, wiki-links and backlinks, status,
description, who added and last edited it, and the true length of the body. If you flagged
the note, `curation_flag` carries your comment, and the assistant is told to weigh it above
its own reading. If the note is tagged `live-state`, `live_state_notice` carries the
standing correction instruction; if it is tagged `user-profile`, `profile_notice` carries
the reading instruction instead, or, for a profile still pending, the instruction not to
follow it. For a long note the assistant can ask for a slice with
`max_chars` and continue from `body_next_offset`; memex tells assistants not to give up on
a note for being large. Open to every role.

**inbox** — What is waiting for you: counts of pending notes and held proposals, and the
oldest of each kind up to `limit`, with proposed bodies only if `max_chars` is asked for.
Read-only, for every role: an assistant can tell you what is waiting, and can avoid filing
what a peer already filed, but nothing here approves anything.

**propose** — The one write tool, in two shapes. Without `note_id` it files a **new note**:
`title` and `body_md` required, plus `summary`, `tags` and optionally a `source_url`.
Assistants are told to write the description themselves, about two or three sentences, to
call `list_tags` first and reuse your vocabulary, and to list what they consulted under a
*Sources* heading at the end of the body. The note lands pending (agent) or verified
(curator).

With `note_id` it files an **edit**, sending only what should change: a new `title`, a full
replacement `body_md`, or a `patch` (up to fifty ordered find-and-replace operations, each
`find` copied exactly from the note and occurring exactly once; an empty `replace`
deletes), plus `summary`, `tags` (the full new list) and a `change_title` of six or seven
words with a `comment` reporting what the change does, one short sentence per change. A
`summary` alone is a complete edit. For an agent the edit is held; for a curator a patch
or a field change applies immediately, a whole `body_md` is held, and `hold: true` holds it
on purpose. A held patch is checked again against the note as it stands when you approve
it, so an anchor that has since changed is refused rather than misapplied.

A `comment` with nothing else is a **report**: the note is unchanged, and the comment waits
in your inbox as a staleness report. A second `propose` on a note the same connection
already has in review revises that draft. The reply says which of these happened, and may
add free `hints`: near-duplicates it noticed, notes the text names but does not link (with
ready patches), and vocabulary tags the text uses, none of which was applied. If other
notes cite this one, the reply says so and asks the assistant to check them.

**propose_delete** — Ask for a note to be retired, with a `reason` you can decide from.
Held for every role. If other notes link to it, the reply asks where those links should
point instead.

**propose_merge** — Ask for a duplicate to be folded into a keeper: `note_id` is absorbed,
`into_note_id` survives and inherits its tags and backlinks, and `merged_body_md` optionally
replaces the keeper's body. Held for every role. Both notes stay until you approve.

**needs_enrichment** — The backlog of notes with no description or no tags, oldest first,
leaving out any note whose description is already waiting in your inbox. The intended loop
is `get` a note and `propose` its description, one call per note. Open to every role; an
assistant works it when you ask, never unasked.

**list_skills** and **get_skill** — The instruction sets memex serves to this connection:
the shipped skills and your own served skill notes, each with a slug, title and
description; then the full text of one by slug. The list is this connection's own, not
everyone's — narrowed by the skill's enabled state, its tool/resource surface flag, and
the connections it has been granted to. Assistants are told to check the list before a
task that a skill might cover, and to follow what they load. Every tool result also carries
`skills_version`, a short hash of that list; when it differs from the one this connection
last saw, the result carries `skills_changed` too, the served list in one line, so a change
made on the Skills page is learned on the connection's very next call.

**list_tags** — Your vocabulary with a count per tag, the names you removed (with what they
were merged into, so an assistant never proposes a retired word), and the three system tags
marked with what each does.

**health** — Confirms the connection, names the connection and its role, and reports how
much work is waiting (inbox counts and the enrichment backlog), so a scheduled run can stop
when there is nothing to do.

**curation_candidates** — The curation queue: which notes need attention and why, ranked
as section 17 describes, with counts per reason. `reason` narrows to one defect,
`stale_days` to notes unread for that long, `cooldown_days` (seven by default) holds back
recently curated notes, and `include_pending` adds pending notes as advisory. Notes another
connection is currently working are withheld for up to half an hour. Open to every role.
An empty result means there is nothing to do.

**blast_radius** — Notes whose linked neighbours changed recently, with what changed, so a
pass can find notes left stale by someone else's edit. Open to every role.

**last_curated** — With no arguments, a preflight: when the last pass ran, what changed
since, the size of the queue, and whether a pass is due. With `note_ids`, the last time
each was curated. Open to every role; curators also see who and what.

**log** — Curator only. Append to the journal: a run summary (with the start time, the ids
examined, and the counts the assistant claims, which memex checks against what it logged),
an observation, or a tooling gap. Recording the run also releases the notes the queue was
holding for that connection.

**log_recent** — Curator only. Read the journal newest first, optionally for one note, one
kind of entry, or only the verdicts you marked as precedent. Assistants are told that your
comments on their held items outrank their own judgment, and that a precedent applies to
comparable cases from then on.

**resolve_curation_flag** — Curator only. Close your flag on a note by saying what was done
about it. Disagreeing is a valid resolution. Refused while a delete or merge of that note is
waiting for you, because your verdict on that proposal answers the flag.

**duplicate_candidates** — Curator only. Pairs of notes closest in meaning, with a distance,
for a pass to open both and decide. `unsettled` counts notes still being compared; their pairs
arrive on a later call.

**A note on connector filters.** Some assistants reach memex through a connector path that
blocks text which looks like shell commands, answering with an HTML block page instead of
JSON. Every write tool accepts `body_encoding: "base64"` as a retry: the assistant re-sends
the same call with the free-text fields base64-encoded, including the `find` and `replace`
of each patch operation, and memex decodes them so the note keeps its literal commands.

## 23. What memex does not do

- **Nothing on its own.** No scheduler, no background pass, no resident model. If nobody
  asks, nothing happens.
- **No chat box.** You ask Claude, ChatGPT or whatever you use; it reads your memex and
  answers from your notes.
- **No shared teams.** One person, one knowledge base. Your assistants are the only other
  readers.
- **No files.** Markdown and text. A PDF becomes a note about the PDF.
- **No transcripts.** memex holds what somebody chose to save, so "what did I say in March"
  works only for what was kept.
- **No folders, no nested tags, no collections.** Tags, links and saved filters.
- **Review is work.** Held writes accumulate if the inbox is never opened. It is fine to
  let them sit; they are not applied meanwhile.
- **Connecting takes a settings pane and a few minutes**, not one click.
{{edition:limits}}

## 24. Glossary

- **Agent** — the default role of a connection: everything it writes waits for you.
{{edition:glossary-allowance}}
- **Backlink** — a note that links to this one.
- **Connection** — one assistant or agent that can reach your knowledge base, with a name,
  an icon, a role, and a record of when it last called in.
- **Curator** — the role whose precise edits and new notes apply without review. Granted
  only by you.
- **Description** — the two or three sentences that say what a note holds. Called
  `summary` in the tools.
- **Embedding** — the vector that makes a note findable by meaning.
- **Flag** — your request that the next curation pass look at a note, with a comment.
- **Handle** — the twelve-character code in every address of your knowledge base.
- **Held** — a proposed edit, delete, merge or report waiting in your inbox.
- **Live-state** — the tag that asks every assistant reading a note to correct it if its
  own work made the note wrong.
- **MCP** — the standard assistants use to reach tools. memex is an MCP server.
- **Operator** — in memex's replies to assistants, you: the owner, at a browser.
- **Profile** — the note tagged `user-profile`: your own account of who you are and how
  you want to be answered, named to every assistant when it connects.
- **Patch** — an edit expressed as find-and-replace operations anchored on the note's text.
- **Pending** — a note an assistant created that you have not yet approved.
- **Proposal** — anything an assistant files that waits for your decision.
- **Skill** — a verified note tagged `skill`, served to every assistant as instructions it
  can load when a job matches. Five ship with memex and are not notes.
- **Tombstone** — what remains of a deleted note after its content is purged.
- **Verified** — a note you wrote, approved or imported, or a curator created.
- **Wiki-link** — `[[Title]]` in a note body.

## 25. Questions people ask

**How does my assistant know who I am?** From your profile note (section 12), which memex
names to it when it connects, and from whatever else your knowledge base says about you.
If you have no profile, ask your assistant to start one from what it already knows, and
approve what it proposes.

**Does my assistant need to ask me every time before saving?** No. Tell it once what you
want kept, or put that in a skill note, and it saves on your standing instruction. What
arrives still waits for your approval, unless the connection is a curator.

**I approved a note but a meaning-search does not find it yet.** The embedding follows
within minutes. Keyword search finds it immediately.

**Can my assistant delete things?** It can propose a deletion. It cannot delete.

**Can I turn the review gate off for one assistant?** Promote it to curator (section 15).
Its deletes and merges still wait for you.

**Where do I see what an assistant changed?** In the note's history for an applied change,
and in Activity for everything curators did.

**A note is wrong and my assistant knows it. What should it do?** Propose a patch to the
sentences that are wrong, or file a comment-only proposal saying what is wrong. Saying it
in chat leaves the note wrong.

**Can I use memex from my phone?** Yes: the website works in a phone browser, and a mobile
assistant reaches memex over MCP like any other.

{{edition:faq-who-sees}}

**How do I leave?** Settings › Notes › *Export* gives you everything as Markdown files
that open anywhere. Then, if you want, *Delete my account*.
