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
*Connect an assistant*. Pick the assistant you use most; you can add others later.

{{edition:assistants-connect}}

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
choose **Allow all actions**. Always allow is safe, as memex will never allow any
destructive actions without your review and approval.

## Claude

1. **Add memex to Claude.** [Add to Claude](https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=Memex&connectorUrl={{origin}}/mcp).
   Claude opens with Memex and its address filled in. Click **Add**. Free accounts can have
   one custom connector.

   If the link doesn’t work, [open Claude connectors](https://claude.ai/customize/connectors):

   1. In Claude, open **Settings → Connectors** and click **Add**.
   2. Name it **Memex**, paste this address and click **Continue**.
   3. Keep the detected settings, then click **Add**.

   ```
   {{origin}}/mcp
   ```

2. **Connect and approve.** Click **Connect** next to Memex in Claude. When Memex opens,
   click **Connect it**. You’ll still review what your assistant writes before it becomes
   a note.

3. **Allow all tools.** To stop Claude asking before every memex action, open **Memex** in
   **Connectors** and choose **Always allow** for both **Read-only tools** and
   **Write/delete tools**. Always allow is safe, as memex will never allow any destructive
   actions without your review and approval.
   [Open Claude connectors](https://claude.ai/customize/connectors)

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

- **Claude:** Note: you can also add this prompt into
  [Claude’s profile preferences](https://claude.ai/new#settings/profile) and any Project’s
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
