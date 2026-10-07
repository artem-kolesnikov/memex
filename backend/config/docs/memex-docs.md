# Your memex

## What memex is

memex keeps the notes you and your assistants decide are worth keeping. Every assistant you
connect can read them and propose new ones. By default, nothing an assistant writes counts
until you approve it.

- Notes are plain Markdown with a title, tags and links to other notes.
- Search finds notes by exact words and by meaning.
- Everything downloads as Markdown files, whenever you want.

## First steps

1. **Connect an assistant.** Follow the steps for yours under
   [Connect an assistant](#connect-an-assistant).
2. **Send the first message.** Paste [your first message](#your-first-message) into a new
   chat.
3. **Save something.** In that chat, say "save that to memex".
4. **Approve it.** Open [Review Inbox]({{base}}/inbox) and approve the note.
5. **Tell assistants about you.** Add [your profile](#your-profile), so you don't explain
   yourself in every chat.

# Connect an assistant

Every assistant connects at the same address:

```
{{origin}}/mcp
```

{{web}}
The same steps are in [Settings › Assistants]({{base}}/settings/connections#connect).

## ChatGPT

1. **Turn on Developer mode.** In ChatGPT, open **Settings → Security and login** and switch
   on **Developer mode**. [Open ChatGPT settings](https://chatgpt.com/settings/security)
2. **Add Memex as a plugin.** Open [Plugins](https://chatgpt.com/plugins) and choose
   **+ (Add) → Create MCP App**. Name it **Memex**, paste this address, tick the
   confirmation box and click **Create**.

   ```
   {{origin}}/mcp
   ```

3. **Allow the connection.** Memex opens in a new tab. Click **Connect it**. You’ll still
   review what your assistant writes before it becomes a note.

**Optional.** To stop ChatGPT asking each time, open **Settings → Plugins → Memex** and
choose **Allow all actions**. Memex still holds edits, deletions and merges in your review
inbox for approval. New notes arrive there for review too.

## Claude

1. **Add your memex.** In Claude, open **Settings → Connectors** and click **Add**. Name it
   **Memex**, paste this address and click **Continue**. Keep the detected settings, then
   click **Add**. Free accounts can have one custom connector.
   [Open Claude connectors](https://claude.ai/new#customize/connectors)

   ```
   {{origin}}/mcp
   ```

2. **Connect and approve.** Click **Connect** next to Memex in Claude. When Memex opens,
   click **Connect it**. You’ll still review what your assistant writes before it becomes
   a note.

**Optional.** To stop Claude asking each time, open **Memex** in **Connectors** and choose
**Always allow** for both **Read-only tools** and **Write/delete tools**. Memex still holds
edits, deletions and merges in your review inbox for approval. New notes arrive there for
review too.

## Gemini Spark

1. **Find Custom apps for Spark.** In Gemini, open **Settings → Personal intelligence →
   Connected Apps** and find **Custom apps for Spark**. Spark requires a paid Google AI Pro
   or Ultra plan. [Open Connected Apps](https://gemini.google.com/apps)
2. **Add the Memex link.** Under **Custom apps for Spark**, paste this address and click
   **Next**. On the next screen, leave the settings as they are and click **Next** again.

   ```
   {{origin}}/mcp
   ```

3. **Link your accounts.** Check the confirmation box and click **Connect**. When Google
   asks to link your Google and Memex accounts, click **Agree and continue**. When Memex
   opens, click **Connect it**. You’ll still review what your assistant writes before it
   becomes a note.
4. **Save your custom app.** Keep the name **Memex** and click **Connect** to save it, then
   switch to Spark.

## An agent or a script

For anything that cannot complete a browser sign-in: a coding agent, an agent you run
yourself, or a script.

1. **Create a connection.** Open [Settings › Assistants]({{base}}/settings/connections#connect),
   press *Connect an assistant* and choose **Other**. Give the connection a name you will
   recognise later and press **Create token**.
{{/web}}
{{local}}
This memex runs on your own machine, so the assistants that connect to it run there or
elsewhere on your network: Claude Code, Codex, Cursor, Gemini CLI, Claude Desktop, agents
such as Hermes and OpenClaw, or a script of your own. ChatGPT, Claude's connectors and
Gemini Spark connect to [memex.tools](https://memex.tools), memex's hosted edition, instead.

## Your assistant

1. **Create a connection.** Open [Settings › Assistants]({{base}}/settings/connections#connect),
   give the connection a name you will recognise later and press **Create token**.
{{/local}}
2. **Copy the token**, and keep it private. *Copy token*, in the connection's *Actions*,
   copies it again later.
{{web}}
3. **Add it to your agent.** Add memex as an MCP server at `{{origin}}/mcp`. The
   agent sends the token with every request, as the header
   `Authorization: Bearer <your token>`.
{{/web}}
{{local}}
3. **Add it to your assistant.** Settings › Assistants gives the lines for each one. Anything
   else adds memex as an MCP server at `{{origin}}/mcp` and sends the token with every
   request, as the header `Authorization: Bearer <your token>`.
{{/local}}

## Your first message

Start a new chat and paste this:

> Use my memex as the source of truth about me, my work and my plans. Check it before
> answering about those things. When we decide something worth keeping, offer to save it
> there.
>
> First, read the memex guide: it is the memex-guide skill in my memex. Briefly explain how
> you’ll use memex with me, without technical details.

{{web}}
- **ChatGPT:** add this line at the end.

  > Remember this for all our future chats.

- **Gemini:** add this line at the end.

  > Save this to your saved info so it holds in future chats.

- **Claude:** For future chats, also paste this into
  [Claude’s profile preferences](https://claude.ai/new#settings/profile) or your Project’s
  instructions.
{{/web}}
{{local}}
For future sessions, also add it to the assistant’s own instructions, such as `CLAUDE.md`
for Claude Code or `AGENTS.md` for Codex.
{{/local}}

## Check that it works

In a new chat, ask:

> Can you reach my memex? Tell me in a sentence what memex is.

An assistant that reached memex answers from it. [Settings › Assistants]({{base}}/settings/connections)
shows when it last called in.

# Everyday use

## Find notes

1. Type in the search box on [Notes]({{base}}/notes) and press **Enter**.
2. Narrow the list with **All tags** and the status menu.
3. Remove a filter with its **×**, or press **Clear all**.

memex first looks for notes that contain all your words. If none do, it searches by meaning.

**Operators.** Press **?** beside the search box for this list.

| Type | To find |
|---|---|
| `memex memory` | Both words, in any order |
| `memex OR memory` | Either word |
| `NOT ai` or `-ai` | Notes without the word |
| `(memex OR memory) NOT ai` | A group, then an exclusion |
| `"review gate"` | The exact phrase |

Operators are uppercase. A search with operators matches words as written, never by meaning.

## Workspaces

A workspace is a saved search, one click away in the sidebar.

1. Search or filter your notes.
2. Press **Save as workspace**, next to the active filters.
3. Give it a name and an icon, and save.

To edit or remove one, point at it in the sidebar and press **⋯**.

## Write a note

1. On [Notes]({{base}}/notes), press **New note**.
2. Write a title and the body. Type `[[` to link to another note.
3. Add tags and a short summary on the right.
4. Press **Save**, or **Ctrl/⌘+S**.

A note you write is verified at once. To change one, open it and press **Edit note**.

- **Undo a change:** open the note, pick a version under **History** and press
  **Restore this version**.
- **Start from a file:** in a new note, **Import note…** fills it from a `.md` or `.txt` file.

## Review Inbox

What an assistant writes waits in [Review Inbox]({{base}}/inbox): new notes, edits,
deletions and merges. A [curator](#agent-or-curator)'s new notes and edits apply at once.

1. Open a card to see the change.
2. Approve it (**Approve note**, **Apply edit**, …) or reject it.
3. To fix it first, press **Amend**, make your change, then approve.

To decide several at once, tick them and press **Approve selected** or **Reject selected**.

A new note from an assistant is *pending* until you decide: you can read and search it, but
it is not verified. A rejected note goes to Deleted notes for thirty days.

## Activity log

**Activity log**, at the top right, lists every change to your memex: what changed, who did
it and when.

- Filter by action or by who, or search.
- **Curation** shows one row per maintenance pass.
- **Request logs download** saves the log as Markdown or CSV.

## Map

On [Notes]({{base}}/notes), the map button beside the list button draws your notes as dots
and their links as lines.

- Red marks notes that need work, yellow marks flagged notes, faded notes wait for review.
- Pick a view: Everything, Orphans, Hubs, Neighbourhood, Possibly stale or Islands.
- Click a note for its details and **Open note**.

# Make it yours

## Skills

A skill is a note tagged `skill`: instructions an assistant loads when a task matches. Every
connected assistant sees the list of your skills.

**Make one.** Ask a connected assistant:

> Make me a memex skill for [the task].

It proposes one, and you approve it in Review Inbox. You can also press **New skill** on
[Skills]({{base}}/skills) and write it yourself.

**Manage them** on [Skills]({{base}}/skills):

- Switch a skill on or off.
- **Open skill** to limit it to some connections, copy a usage example or download its
  `SKILL.md`.

The skills built into memex are listed last. They update with memex.

## Your profile

Your profile is a note about you, tagged `user-profile`. Every connected assistant reads it,
so you don't explain yourself in every chat.

1. Open [Settings › Personalization]({{base}}/settings/personalization).
2. Under **About you**, press **Add profile**.
3. Pick a few preferences and press **Save and continue**.

Or ask a connected assistant:

> Help me create or update my profile in memex using what I’ve told you about my work and preferences.

Keep it short. Edit it like any other note.

## Writing rules

memex gives assistants rules for writing notes: what goes first, how long a note is, how to
record a decision or a meeting.

- Tune them in [Settings › Personalization]({{base}}/settings/personalization), under
  **Writing notes**.
- Or choose **Use my own writing rules** and add yours as a skill.

## Settings

The gear at the top right opens Settings.

| Pane | What's there |
|---|---|
| **Account** | Your name and picture, how you sign in, signed-in browsers, deleting your account |
| **Preferences** | Theme, text size and font, language, date and time, map |
| **Personalization** | Writing rules and your profile |
| **Notes** | Import, export, tags, deleted notes |
| **Assistants** | Connecting assistants, your connections, curators and maintenance |
| **AI features** | Search by meaning, automatic descriptions and tags, your provider keys |

# Curation

## Agent or curator

Every new connection is an **agent**. You can make one a **curator** to let it work without
waiting for you.

| | Agent | Curator |
|---|---|---|
| New notes | Wait for you | Saved at once |
| Edits | Wait for you | Precise edits apply at once |
| Deletes and merges | Wait for you | Wait for you |

Every change a curator makes is in the Activity log. Changes to skills and to your profile
always wait for you.

## Make a curator

1. Open [Settings › Assistants]({{base}}/settings/connections#curation).
2. Under **Choose a curator**, pick an assistant.
3. Press **Make curator**.

**Remove role** takes it back. Only you can do this, from a browser.

## Run a maintenance pass

A pass finds notes that need work: no tags, no summary, broken links, notes you flagged. Then
it fixes them or proposes fixes.

1. In [Settings › Assistants]({{base}}/settings/connections#curation), copy the message
   under **Start a maintenance pass**.
2. Paste it into a chat with your curator:

   > Load the memex-curation skill from memex and follow it.
   > Note the time before you start and send it as started_at when you close the run with log.

3. Read its report in the chat, and decide what it filed in Review Inbox.

To repeat it, set up a scheduled task with the same message in ChatGPT or Claude. memex
never runs a pass on its own.

## Flag a note

1. Open the note and press **Flag for curation**.
2. Say what is wrong and what you want done.

The next pass starts with flagged notes. On Notes, the status menu shows them under
**Flagged for curation**.

# Your data

## Import

1. Open [Settings › Notes]({{base}}/settings/content#import).
2. Drop Markdown or text files, or a ZIP of a folder such as an Obsidian vault.
3. Press **Import**. For a ZIP, **Analyze** first shows what would come in.

Titles, tags and links between the imported notes are kept. PDFs and images are not accepted.

## Export

- **One note:** **Download .md** on the note.
- **Some notes:** tick them on Notes and download them as a ZIP.
- **Everything:** [Settings › Notes]({{base}}/settings/content) › **Export** › **Download notes**.

Each file is Markdown with its title and tags at the top, so it opens in Obsidian or any
editor.

## Delete and restore

- **Delete:** open the note, press **Edit note**, then **Delete this note**.
- **Restore:** [Settings › Notes]({{base}}/settings/content#deleted) › **Open deleted notes**
  › **Restore**, within thirty days.

After thirty days the text is gone for good. Assistants cannot delete: they can only propose
it.

## AI features

[Settings › AI features]({{base}}/settings/automation) has two parts:

- **Search** prepares notes so search can find them by meaning.
- **Descriptions and tags** writes a summary and tags for a note saved without them.

**Add personal key** adds a key from OpenAI, Anthropic or Google. The provider bills you for
what it does.

# Help

## Prompts to copy

**Save something.**

> Save that to memex.

**Check the connection.**

> Can you reach my memex? Tell me in a sentence what memex is.

**Fix a wrong note.**

> That note in memex is wrong. Propose a fix.

**Write your profile.**

> Help me create or update my profile in memex using what I’ve told you about my work and preferences.

**Make a skill.**

> Make me a memex skill for [the task].

**Run a maintenance pass**, in a chat with your curator.

> Load the memex-curation skill from memex and follow it.
> Note the time before you start and send it as started_at when you close the run with log.

## Questions

**Does my assistant ask before every save?** No. Tell it once what to keep. What it saves
still waits for your approval, unless it is a curator.

**I approved a note, but search by meaning misses it.** It is ready within minutes. Search by
exact words finds it at once.

**Can my assistant delete notes?** No. It can propose a deletion, and you decide.

**Can one assistant skip review?** Make it a [curator](#make-a-curator). Its deletes and
merges still wait for you.

**Where do I see what changed?** In the **Activity log**, and in a note's **History**.

**Does memex work on my phone?** Yes, in a phone browser. Assistants on your phone connect
like any other.

**How do I leave?** Export everything from [Settings › Notes]({{base}}/settings/content),
then delete your account in [Settings › Account]({{base}}/settings/account).

**Anything else?** Ask a connected assistant. It can read the full guide, the `memex-guide`
skill.
