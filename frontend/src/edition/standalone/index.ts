import { defineAsyncComponent } from 'vue'
import type { Edition } from '@/editions'
import { i18n } from '@/i18n'
import messages from './en.json'

export const edition: Edition = {
  routes: [
    { path: '/login', name: 'login', meta: { public: true }, component: () => import('./OwnerSignIn.vue') },
  ],
  accountBlocks: [defineAsyncComponent(() => import('./PasswordBlock.vue'))],
  connectionName: 'memex-local',
  assistantSetups: [
    {
      id: 'claude-desktop',
      label: 'Claude Desktop',
      file: 'claude_desktop_config.json',
      text: (_address, name) => `{
  "mcpServers": {
    "${name}": {
      "command": "docker",
      "args": ["exec", "-i", "-e", "MEMEX_TOKEN=YOUR_TOKEN", "memex", "php", "bin/console", "app:mcp-stdio"]
    }
  }
}`,
    },
  ],
  footerLinks: [{ labelKey: 'standalone.footer.source', href: 'https://github.com/artem-kolesnikov/memex' }],
  install() {
    i18n.global.mergeLocaleMessage('en', messages)
    document.title = i18n.global.t('app.brand.name')
  },
}
