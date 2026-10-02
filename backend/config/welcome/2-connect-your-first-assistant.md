---
title: "Connect your first assistant"
description: "Step by step for each assistant you can connect: connecting it to memex, the first message to send, and how to check that it reached your notes."
---

Every assistant connects at the same address:

```
{{origin}}/mcp
```

{{web}}
The steps below are also in [Settings › Assistants]({{base}}/settings/connections), under
*Connect an assistant*, with a picture of each screen. Pick the assistant you use most; you
can add others later.

{{edition:assistants-connect}}

## ChatGPT

1. **Turn on Developer mode.** In ChatGPT, open **Settings → Security and login**. Switch
   on **Developer mode**. [Open ChatGPT settings](https://chatgpt.com/settings/security)
2. **Add Memex as a plugin.** Open [Plugins](https://chatgpt.com/plugins) and choose
   **+ (Add) → Create MCP App**. Name it **Memex** and paste this server address:
   `{{origin}}/mcp`. Tick the confirmation box, then click Create.
3. **Allow the connection.** Memex will open in a new tab. Click **Connect it**, then come
   back here. You’ll still review what your assistant writes before it becomes a note.
4. **Optional. Fewer interruptions, same review inbox.** **Allow low-risk actions** is a
   good default. For fewer interruptions, let ChatGPT use memex without asking each time.
   Open **Settings → Plugins → Memex** and choose **Allow all actions**. Memex still holds
   edits, deletions and merges in your review inbox for approval. New notes arrive there
   for review too.

## Claude

1. **Open Claude’s connectors.** Go to **Settings → Connectors** in Claude.
   [Open Claude connectors](https://claude.ai/new#customize/connectors)
2. **Add your memex.** Click **Add**. Name it **Memex** and paste this address:
   `{{origin}}/mcp`. Click **Continue**, keep the detected settings, then click
   **Add**. Free accounts can have one custom connector.
3. **Connect and approve.** Click **Connect** next to Memex in Claude. When Memex opens,
   click **Connect it**, then come back here. You’ll still review what your assistant
   writes before it becomes a note.
4. **Optional. Fewer interruptions, same review inbox.** In **Connectors**, open
   **Memex**. Choose **Always allow** for both **Read-only tools** and **Write/delete
   tools**. Claude can then use memex without asking each time. Memex still holds edits,
   deletions and merges in your review inbox for approval. New notes arrive there for
   review too.

## Gemini Spark

1. **Open Personal intelligence.** In Gemini, go to **Settings → Personal intelligence**.
   This setup uses Custom apps for Spark. Spark requires a paid Google AI Pro or Ultra plan.
   [Open Gemini settings](https://gemini.google.com/personalization-settings)
2. **Find Custom apps.** Open **Connected Apps**, then find **Custom apps for Spark**.
   [Open Connected Apps](https://gemini.google.com/apps)
3. **Add the Memex link.** Under **Custom apps for Spark**, paste this address:
   `{{origin}}/mcp`. Click **Next**. On the next screen, leave the settings as
   they are and click **Next** again.
4. **Link your accounts.** Check the confirmation box and click **Connect**. When Google
   asks to link your Google and Memex accounts, click **Agree and continue**.
5. **Approve in Memex.** When Memex opens, click **Connect it**. Then return to Gemini to
   finish. You’ll still review what your assistant writes before it becomes a note.
6. **Save your custom app.** Keep the name **Memex** and click **Connect** to save it. All
   set. Next, switch to Spark and say hello to memex.
{{/web}}

{{local}}
This memex runs on your own machine, so the assistants that connect to it run there or
elsewhere on your network: Claude Code, Codex, Cursor, Gemini CLI, Claude Desktop, agents
such as Hermes and OpenClaw, or a script of your own. ChatGPT, Claude's connectors and
Gemini Spark call memex from their own servers over the internet, so they connect to
[memex.tools](https://memex.tools), memex's hosted edition, instead.
{{/local}}

{{web}}
## An agent or a script

For anything that cannot complete a browser sign-in: a coding agent, an agent you run
yourself, or a script.

1. **Create a connection.** Open [Settings › Assistants]({{base}}/settings/connections),
   press *Connect an assistant* and choose **Other**. Give the connection a name you will
   recognise later and press **Create token**.
{{/web}}

{{local}}
## Your assistant

1. **Create a connection.** Open [Settings › Assistants]({{base}}/settings/connections),
   give the connection a name you will recognise later and press **Create token**.
{{/local}}
2. **Copy the token.** It is shown once, so copy it then, and keep it private.
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

An assistant that reached memex answers from it, and the table in Settings › Assistants
shows when it last called in. A plain "hello" is not enough: an assistant answers that from
its own head.

Allowing an assistant to act without asking is safe: by default, what a connection writes
waits in your Review inbox, as [[Welcome to memex]] explains.

## Next

[[Make memex yours]]: try one of your two skills in the chat you just connected.
