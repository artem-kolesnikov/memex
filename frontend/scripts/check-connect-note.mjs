#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: the welcome note "Connect your first assistant" says what Settings ›
 * Assistants says.
 *
 * The note repeats every connection step in text, and it is copied into each
 * new account at sign-up, so a vendor's changed screen fixed in the guides and
 * not in the note sends every new account the old steps. Each client's section
 * must hold one numbered item per instruction, in order, and each item every
 * text the guide shows for it: title, body (the address may follow it), the
 * lines after it, the tip, the link. The first-chat prompt, the ways each
 * assistant keeps it, the address and the token header must appear too.
 *
 * Usage: node --experimental-strip-types scripts/check-connect-note.mjs
 */
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { CLIENTS } from '../src/components/settings/connectGuides.ts'
import { initPrompt } from '../src/components/settings/initPrompt.ts'

const here = dirname(fileURLToPath(import.meta.url))
// The address the note gives: the server writes its own in place of {{origin}}
// when the note is copied in (App\Service\ShippedText).
const MCP_URL = '{{origin}}/mcp'
const NOTE = join(here, '../../backend/config/welcome/2-connect-your-first-assistant.md')
const messages = JSON.parse(readFileSync(join(here, '../src/locales/en.json'), 'utf8'))

const flat = (text) => text.replace(/^\s*>\s?/gm, '').replace(/\s+/g, ' ').trim()

function t(key) {
  const value = key.split('.').reduce((node, part) => node?.[part], messages)
  if (typeof value !== 'string') throw new Error(`no message ${key}`)
  return value
}

function sections(note) {
  const found = new Map()
  for (const part of note.split(/^## /m).slice(1)) {
    const newline = part.indexOf('\n')
    found.set(part.slice(0, newline).trim(), part.slice(newline + 1))
  }
  return found
}

function problems(note) {
  const out = []
  const all = flat(note)
  const bySection = sections(note)

  for (const client of CLIENTS) {
    const section = bySection.get(client.label)
    if (section === undefined) {
      out.push(`no "## ${client.label}" section`)
      continue
    }
    const items = section.split(/^\d+\.\s/m).slice(1).map(flat)
    if (items.length !== client.instructions.length) {
      out.push(`${client.label}: ${items.length} numbered steps, Settings has ${client.instructions.length}`)
    }
    client.instructions.forEach((ins, i) => {
      const item = items[i] ?? ''
      const step = `${client.label} step ${i + 1}`
      const body = flat(t(ins.bodyKey))
      const withAddress = body.replace(/\.$/, `: \`${MCP_URL}\`.`)
      if (!item.includes(body) && !(ins.address && item.includes(withAddress))) out.push(`${step} lacks: ${body}`)
      for (const key of [ins.titleKey, ins.afterAddressKey, ins.instructionKey, ins.tipKey]) {
        if (key !== undefined && !item.includes(flat(t(key)))) out.push(`${step} lacks: ${t(key)}`)
      }
      if (ins.link && !item.includes(ins.link.url)) out.push(`${step} lacks the link ${ins.link.url}`)
    })
    const { closing, note: keep } = client.persist
    if (closing !== '' && !all.includes(closing)) out.push(`${client.label}: the prompt's closing is missing: ${closing}`)
    if (keep) {
      for (const text of [t(keep.beforeKey), t(keep.afterKey), keep.link.url]) {
        if (!all.includes(flat(text))) out.push(`${client.label}: where the prompt is kept is missing: ${text}`)
      }
    }
  }

  if (!all.includes(flat(initPrompt(null)))) out.push('the first-chat prompt differs from initPrompt.ts')
  if (!all.includes(MCP_URL)) out.push(`the address ${MCP_URL} is missing`)
  if (!all.includes(t('connections.manual.header_value'))) out.push('the token header is missing')
  return out
}

const note = readFileSync(NOTE, 'utf8')

// The guard must be able to fail: each of these is the drift it exists for.
const drifts = [
  ['a vendor step reworded in Settings only', note.replace('Create MCP App', 'Create App')],
  ['a step dropped from the note', note.replace(/^6\. \*\*Save your custom app[\s\S]*?(?=\n\n)/m, '')],
  ['the first-chat prompt edited in the note only', note.replace('source of truth', 'main source')],
]
const blind = drifts.filter(([, mutated]) => mutated === note || problems(mutated).length === 0).map(([name]) => name)
if (blind.length > 0) {
  console.error(`FAIL the connect-note check cannot see: ${blind.join('; ')}`)
  process.exit(1)
}

const found = problems(note)
if (found.length > 0) {
  console.error(`FAIL backend/config/welcome/2-connect-your-first-assistant.md no longer says what Settings › Assistants says:\n  ${found.join('\n  ')}`)
  process.exit(1)
}
console.log(`Connect note passed: ${CLIENTS.map((c) => `${c.label} ${c.instructions.length} steps`).join(', ')}, the prompt and the address match Settings.`)
