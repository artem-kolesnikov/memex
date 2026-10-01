/**
 * The connect guides, shared by Settings › Connections (as a list) and the
 * first-run wizard (one instruction at a time), so the two cannot drift.
 *
 * Every text is a message key. Bodies may carry `**bold**` and `[label](url)`,
 * which `GuideRich.vue` renders as elements rather than as HTML.
 */

export interface GuideImage {
  src: string
  altKey: string
  captionKey: string
}

export interface GuideInstruction {
  titleKey: string
  bodyKey: string
  /** A second paragraph under the body. */
  instructionKey?: string
  /** The address, with its copy button, between the body and `afterAddressKey`. */
  address?: boolean
  afterAddressKey?: string
  tipKey?: string
  link?: { url: string; labelKey: string }
  /** The footer button that moves on from this instruction. */
  nextKey: string
  /** An optional instruction has a second exit, "Skip this step". */
  optional?: boolean
  images: GuideImage[]
}

export interface ConnectGuide {
  id: string
  label: string
  /** Where the assistant lives, for the "Open ChatGPT" links. */
  home: string
  logo: { light: string; dark: string }
  instructions: GuideInstruction[]
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
  /** The text, for this MCP address, with YOUR_TOKEN where the token goes. */
  text: (address: string) => string
}

export const SETUPS: AssistantSetup[] = [
  {
    id: 'claude-code',
    label: 'Claude Code',
    text: (address) =>
      `claude mcp add --transport http --scope user memex ${address} --header "Authorization: Bearer YOUR_TOKEN"`,
  },
  {
    id: 'codex',
    label: 'Codex',
    file: '~/.codex/config.toml',
    text: (address) =>
      `[mcp_servers.memex]\nurl = "${address}"\nhttp_headers = { "Authorization" = "Bearer YOUR_TOKEN" }`,
  },
  {
    id: 'cursor',
    label: 'Cursor',
    file: '~/.cursor/mcp.json',
    text: (address) =>
      JSON.stringify({ mcpServers: { memex: { url: address, headers: { Authorization: 'Bearer YOUR_TOKEN' } } } }, null, 2),
  },
  {
    id: 'gemini-cli',
    label: 'Gemini CLI',
    text: (address) =>
      `gemini mcp add --transport http -s user -H "Authorization: Bearer YOUR_TOKEN" memex ${address}`,
  },
]

const APPROVE: GuideImage = {
  src: '/onboarding/memex-approve.jpg',
  altKey: 'connections.guides.images.approve',
  captionKey: 'connections.guides.images.enlarge',
}

export const CHATGPT: ConnectGuide = {
  id: 'chatgpt',
  label: 'ChatGPT',
  home: 'https://chatgpt.com',
  logo: { light: '/logos/chatgpt-on-light.svg', dark: '/logos/chatgpt-on-dark.svg' },
  instructions: [
    {
      titleKey: 'connections.guides.chatgpt.developer_mode.title',
      bodyKey: 'connections.guides.chatgpt.developer_mode.body',
      link: { url: 'https://chatgpt.com/settings/security', labelKey: 'connections.guides.chatgpt.developer_mode.link' },
      nextKey: 'connections.guides.chatgpt.developer_mode.next',
      images: [{ src: '/onboarding/chatgpt-developer-mode.jpg', altKey: 'connections.guides.images.developer_mode', captionKey: 'connections.guides.images.enlarge' }],
    },
    {
      titleKey: 'connections.guides.chatgpt.plugin.title',
      bodyKey: 'connections.guides.chatgpt.plugin.body',
      address: true,
      tipKey: 'connections.guides.chatgpt.plugin.tip',
      nextKey: 'connections.guides.chatgpt.plugin.next',
      images: [{ src: '/onboarding/chatgpt-new-plugin.jpg', altKey: 'connections.guides.images.new_plugin', captionKey: 'connections.guides.images.enlarge' }],
    },
    {
      titleKey: 'connections.guides.chatgpt.approve.title',
      bodyKey: 'connections.guides.chatgpt.approve.body',
      tipKey: 'connections.guides.review_tip',
      nextKey: 'connections.guides.chatgpt.approve.next',
      images: [APPROVE],
    },
    {
      titleKey: 'connections.guides.permissions_title',
      bodyKey: 'connections.guides.chatgpt.permissions.body',
      instructionKey: 'connections.guides.chatgpt.permissions.instruction',
      tipKey: 'connections.guides.permissions_tip',
      nextKey: 'connections.guides.continue_to_test',
      optional: true,
      images: [
        { src: '/onboarding/chatgpt-plugin-list.png', altKey: 'connections.guides.images.chatgpt_plugin_list', captionKey: 'connections.guides.images.open_memex' },
        { src: '/onboarding/chatgpt-plugin-permissions.png', altKey: 'connections.guides.images.chatgpt_plugin_permissions', captionKey: 'connections.guides.images.choose_permissions' },
      ],
    },
  ],
  persist: { closing: 'Remember this for all our future chats.' },
}

export const CLAUDE: ConnectGuide = {
  id: 'claude',
  label: 'Claude',
  home: 'https://claude.ai',
  logo: { light: '/logos/claude.svg', dark: '/logos/claude.svg' },
  instructions: [
    {
      titleKey: 'connections.guides.claude.connectors.title',
      bodyKey: 'connections.guides.claude.connectors.body',
      link: { url: 'https://claude.ai/new#customize/connectors', labelKey: 'connections.guides.claude.connectors.link' },
      nextKey: 'connections.guides.im_there',
      images: [{ src: '/onboarding/claude-connectors.png', altKey: 'connections.guides.images.claude_connectors', captionKey: 'connections.guides.images.enlarge' }],
    },
    {
      titleKey: 'connections.guides.claude.add.title',
      bodyKey: 'connections.guides.claude.add.body',
      address: true,
      afterAddressKey: 'connections.guides.claude.add.after_address',
      tipKey: 'connections.guides.claude.add.tip',
      nextKey: 'connections.guides.claude.add.next',
      images: [
        { src: '/onboarding/claude-add-connector.png', altKey: 'connections.guides.images.claude_add', captionKey: 'connections.guides.images.name_and_address' },
        { src: '/onboarding/claude-confirm-connector.png', altKey: 'connections.guides.images.claude_confirm', captionKey: 'connections.guides.images.confirm_and_add' },
      ],
    },
    {
      titleKey: 'connections.guides.claude.approve.title',
      bodyKey: 'connections.guides.claude.approve.body',
      tipKey: 'connections.guides.review_tip',
      nextKey: 'connections.guides.claude.approve.next',
      images: [APPROVE],
    },
    {
      titleKey: 'connections.guides.permissions_title',
      bodyKey: 'connections.guides.claude.permissions.body',
      instructionKey: 'connections.guides.claude.permissions.instruction',
      tipKey: 'connections.guides.permissions_tip',
      nextKey: 'connections.guides.continue_to_test',
      optional: true,
      images: [
        { src: '/onboarding/claude-connectors.png', altKey: 'connections.guides.images.claude_connectors_open', captionKey: 'connections.guides.images.open_memex' },
        { src: '/onboarding/claude-read-permissions.png', altKey: 'connections.guides.images.claude_read', captionKey: 'connections.guides.images.read_only' },
        { src: '/onboarding/claude-write-permissions.png', altKey: 'connections.guides.images.claude_write', captionKey: 'connections.guides.images.write_delete' },
      ],
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
  home: 'https://gemini.google.com',
  logo: { light: '/logos/gemini.svg', dark: '/logos/gemini.svg' },
  instructions: [
    {
      titleKey: 'connections.guides.gemini.personal.title',
      bodyKey: 'connections.guides.gemini.personal.body',
      link: { url: 'https://gemini.google.com/personalization-settings', labelKey: 'connections.guides.gemini.personal.link' },
      tipKey: 'connections.guides.gemini.personal.tip',
      nextKey: 'connections.guides.im_there',
      images: [],
    },
    {
      titleKey: 'connections.guides.gemini.custom_apps.title',
      bodyKey: 'connections.guides.gemini.custom_apps.body',
      link: { url: 'https://gemini.google.com/apps', labelKey: 'connections.guides.gemini.custom_apps.link' },
      nextKey: 'connections.guides.gemini.custom_apps.next',
      images: [{ src: '/onboarding/gemini-custom-apps.png', altKey: 'connections.guides.images.gemini_custom_apps', captionKey: 'connections.guides.images.enlarge' }],
    },
    {
      titleKey: 'connections.guides.gemini.link.title',
      bodyKey: 'connections.guides.gemini.link.body',
      address: true,
      afterAddressKey: 'connections.guides.gemini.link.after_address',
      nextKey: 'connections.guides.gemini.link.next',
      images: [
        { src: '/onboarding/gemini-custom-apps.png', altKey: 'connections.guides.images.gemini_paste', captionKey: 'connections.guides.images.paste_link' },
        { src: '/onboarding/gemini-server-confirmation.png', altKey: 'connections.guides.images.gemini_confirm', captionKey: 'connections.guides.images.confirm_address' },
      ],
    },
    {
      titleKey: 'connections.guides.gemini.accounts.title',
      bodyKey: 'connections.guides.gemini.accounts.body',
      instructionKey: 'connections.guides.gemini.accounts.instruction',
      nextKey: 'connections.guides.gemini.accounts.next',
      images: [],
    },
    {
      titleKey: 'connections.guides.gemini.approve.title',
      bodyKey: 'connections.guides.gemini.approve.body',
      tipKey: 'connections.guides.review_tip',
      nextKey: 'connections.guides.gemini.approve.next',
      images: [APPROVE],
    },
    {
      titleKey: 'connections.guides.gemini.save.title',
      bodyKey: 'connections.guides.gemini.save.body',
      tipKey: 'connections.guides.gemini.save.tip',
      nextKey: 'connections.guides.continue_to_test',
      images: [{ src: '/onboarding/gemini-save-app.png', altKey: 'connections.guides.images.gemini_save', captionKey: 'connections.guides.images.enlarge' }],
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
