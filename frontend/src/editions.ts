import type { App, Component } from 'vue'
import type { RouteRecordRaw, Router } from 'vue-router'
import type { AssistantSetup } from '@/components/settings/connectGuides'

// What an edition adds to the app: routes of its own, registered before the
// router's first navigation (one named like a core route replaces it), and
// whatever it installs once the app exists.
export interface Edition {
  routes?: RouteRecordRaw[]
  install?: (app: App, router: Router) => void
  /** Blocks of Settings › Account, after the profile. */
  accountBlocks?: Component[]
  /** Assistants connected with a token, after the core's own. */
  assistantSetups?: AssistantSetup[]
  /** Links in the footer, after the copyright. */
  footerLinks?: { labelKey: string; href: string }[]
  /** The name assistants' settings give this memex, where it is not `memex`. */
  connectionName?: string
}

export const editions: Edition[] = Object.values(
  import.meta.glob<Edition>('./edition/*/index.ts', { eager: true, import: 'edition' }),
)

export function connectionName(): string {
  return editions.find((edition) => edition.connectionName)?.connectionName ?? 'memex'
}
