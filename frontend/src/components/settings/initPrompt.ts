import type { ConnectGuide } from './connectGuides'

/**
 * The text a new user pastes into their assistant once, after connecting.
 *
 * Short and in plain words. memex already hands every connection its own
 * instructions and the `memex-recall` skill carries the habits in full; what
 * this adds is the one thing no server can do — tell the assistant, from
 * inside the person's own chat, that memex outranks what it remembers about
 * them — and a first request whose answer memex can check for: the assistant
 * opening the docs.
 */
const BODY = `Use my memex as the source of truth about me, my work and my plans. Check it before answering about those things. When we decide something worth keeping, offer to save it there.

First, read the memex docs: they are the memex-docs skill in my memex. Briefly explain how you’ll use memex with me, without technical details.`

export function initPrompt(client: ConnectGuide | null): string {
  const closing = client?.persist.closing ?? ''
  return closing === '' ? BODY : `${BODY}\n\n${closing}`
}
