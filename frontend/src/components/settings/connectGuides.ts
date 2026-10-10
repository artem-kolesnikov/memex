/**
 * The connect guides of Settings › Assistants, one numbered list per assistant.
 *
 * Every text is a message key. Bodies may carry `**bold**` and `[label](url)`,
 * which `GuideRich.vue` renders as elements rather than as HTML.
 */

export interface GuideStep {
  titleKey: string
  bodyKey: string
  /** The address, with its copy button, under the step. */
  address?: boolean
  /** A short aside under the body. */
  tipKey?: string
  /** An aside the person must not miss, marked as a warning. */
  warnKey?: string
  link?: { url: string; labelKey: string }
  /** The step done for the person: a primary button, given this server's MCP address. */
  action?: { href: (address: string) => string; labelKey: string }
  /** The same step by hand, folded under the action for when it fails. */
  manual?: { summaryKey: string; lineKeys: string[]; address?: boolean; link?: { url: string; labelKey: string } }
}

export interface ConnectGuide {
  id: string
  label: string
  logo: { light: string; dark: string }
  steps: GuideStep[]
  /** The line under the list: how to stop the assistant asking before each call. */
  permissionsKey?: string
  /**
   * How this assistant is made to KEEP the first-run prompt, the only part of
   * it that differs. Claude cannot be told from inside a chat, so its closing
   * is empty and its note says where the text actually sticks.
   *
   * `closing` is pasted into the assistant, not shown on screen, so it stays
   * as text rather than a key.
   */
  persist: { closing: string; note?: { beforeKey: string; link: { url: string; labelKey: string }; afterKey: string } }
}

/** This server's MCP address. */
export function mcpAddress(): string {
  return `${window.location.origin}/mcp`
}

/**
 * An assistant connected with a token rather than a browser sign-in: the text
 * that sets it up, pasted into a terminal or added to its settings file.
 */
export interface AssistantSetup {
  id: string
  label: string
  /** Settings file the text is added to; none means a terminal command. */
  file?: string
  /** The text, for this MCP address and server name, with YOUR_TOKEN where the token goes. */
  text: (address: string, name: string) => string
}

export const SETUPS: AssistantSetup[] = [
  {
    id: 'claude-code',
    label: 'Claude Code',
    text: (address, name) =>
      `claude mcp add --transport http --scope user ${name} ${address} --header "Authorization: Bearer YOUR_TOKEN"`,
  },
  {
    id: 'codex',
    label: 'Codex',
    file: '~/.codex/config.toml',
    text: (address, name) =>
      `[mcp_servers.${name}]\nurl = "${address}"\nhttp_headers = { "Authorization" = "Bearer YOUR_TOKEN" }`,
  },
  {
    id: 'cursor',
    label: 'Cursor',
    file: '~/.cursor/mcp.json',
    text: (address, name) =>
      JSON.stringify({ mcpServers: { [name]: { url: address, headers: { Authorization: 'Bearer YOUR_TOKEN' } } } }, null, 2),
  },
  {
    id: 'gemini-cli',
    label: 'Gemini CLI',
    text: (address, name) =>
      `gemini mcp add --transport http -s user -H "Authorization: Bearer YOUR_TOKEN" ${name} ${address}`,
  },
]

export const CHATGPT: ConnectGuide = {
  id: 'chatgpt',
  label: 'ChatGPT',
  logo: { light: '/logos/chatgpt-on-light.svg', dark: '/logos/chatgpt-on-dark.svg' },
  steps: [
    {
      titleKey: 'connections.guides.chatgpt.developer_mode.title',
      bodyKey: 'connections.guides.chatgpt.developer_mode.body',
      link: { url: 'https://chatgpt.com/settings/security', labelKey: 'connections.guides.chatgpt.developer_mode.link' },
    },
    {
      titleKey: 'connections.guides.chatgpt.plugin.title',
      bodyKey: 'connections.guides.chatgpt.plugin.body',
      address: true,
    },
    {
      titleKey: 'connections.guides.chatgpt.approve.title',
      bodyKey: 'connections.guides.chatgpt.approve.body',
      tipKey: 'connections.guides.review_tip',
    },
  ],
  permissionsKey: 'connections.guides.chatgpt.permissions',
  persist: { closing: 'Remember this for all our future chats.' },
}

/** Claude's connector settings, where a connector is added and its tools are allowed. */
export const CLAUDE_CONNECTORS = 'https://claude.ai/customize/connectors'

/** Claude's documented install link: the Add custom connector dialog, prefilled. */
export function claudeInstallLink(address: string): string {
  return `${CLAUDE_CONNECTORS}?modal=add-custom-connector&connectorName=Memex&connectorUrl=${encodeURIComponent(address)}`
}

export const CLAUDE: ConnectGuide = {
  id: 'claude',
  label: 'Claude',
  logo: { light: '/logos/claude.svg', dark: '/logos/claude.svg' },
  steps: [
    {
      titleKey: 'connections.guides.claude.add.title',
      bodyKey: 'connections.guides.claude.add.body',
      warnKey: 'connections.guides.claude.add.tip',
      action: { href: claudeInstallLink, labelKey: 'connections.guides.claude.add.button' },
      manual: {
        summaryKey: 'connections.guides.claude.add.manual',
        lineKeys: [
          'connections.guides.claude.add.manual_open',
          'connections.guides.claude.add.manual_paste',
          'connections.guides.claude.add.manual_add',
        ],
        address: true,
        link: { url: CLAUDE_CONNECTORS, labelKey: 'connections.guides.claude.add.link' },
      },
    },
    {
      titleKey: 'connections.guides.claude.approve.title',
      bodyKey: 'connections.guides.claude.approve.body',
      tipKey: 'connections.guides.review_tip',
    },
    {
      titleKey: 'connections.guides.claude.allow.title',
      bodyKey: 'connections.guides.claude.allow.body',
      tipKey: 'connections.guides.permissions_tip',
      link: { url: CLAUDE_CONNECTORS, labelKey: 'connections.guides.claude.allow.link' },
    },
  ],
  persist: {
    closing: '',
    note: {
      beforeKey: 'connections.guides.claude.persist_note.before',
      link: { url: 'https://claude.ai/new#settings/profile', labelKey: 'connections.guides.claude.persist_note.link' },
      afterKey: 'connections.guides.claude.persist_note.after',
    },
  },
}

export const GEMINI: ConnectGuide = {
  id: 'gemini',
  label: 'Gemini Spark',
  logo: { light: '/logos/gemini.svg', dark: '/logos/gemini.svg' },
  steps: [
    {
      titleKey: 'connections.guides.gemini.custom_apps.title',
      bodyKey: 'connections.guides.gemini.custom_apps.body',
      tipKey: 'connections.guides.gemini.custom_apps.tip',
      link: { url: 'https://gemini.google.com/apps', labelKey: 'connections.guides.gemini.custom_apps.link' },
    },
    {
      titleKey: 'connections.guides.gemini.link.title',
      bodyKey: 'connections.guides.gemini.link.body',
      address: true,
    },
    {
      titleKey: 'connections.guides.gemini.accounts.title',
      bodyKey: 'connections.guides.gemini.accounts.body',
      tipKey: 'connections.guides.review_tip',
    },
    {
      titleKey: 'connections.guides.gemini.save.title',
      bodyKey: 'connections.guides.gemini.save.body',
    },
  ],
  persist: { closing: 'Save this to your saved info so it holds in future chats.' },
}

export const CLIENTS: ConnectGuide[] = [CHATGPT, CLAUDE, GEMINI]

/** The fourth choice: no guide, a token. */
export const OTHER = 'other'

export function logoFor(client: ConnectGuide, theme: 'light' | 'dark'): string {
  return theme === 'dark' ? client.logo.dark : client.logo.light
}
