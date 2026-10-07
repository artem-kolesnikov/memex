#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: the welcome note "Connect your first assistant", and the Docs page,
 * say what Settings › Assistants says.
 *
 * The note repeats every connection step in text, and it is copied into each
 * new account at sign-up, so a vendor's changed screen fixed in the guides and
 * not in the note sends every new account the old steps. Each client's section
 * must hold one numbered item per step, in order, and each item every text the
 * guide shows for it: title, body, the address where the step has it, the tip,
 * the link; then the optional permissions line. The first-chat prompt, the
 * ways each assistant keeps it, the address and the token header must appear
 * too.
 *
 * Usage: node --experimental-strip-types scripts/check-connect-note.mjs
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { CLIENTS } from '../src/components/settings/connectGuides.ts'
import { initPrompt } from '../src/components/settings/initPrompt.ts'
import { PROFILE_PROMPT } from '../src/components/welcome/profilePrompt.ts'
import { slug } from '../src/lib/docsDoc.ts'

const here = dirname(fileURLToPath(import.meta.url))
// The address the note gives: the server writes its own in place of {{origin}}
// when the note is copied in (App\Service\ShippedText).
const MCP_URL = '{{origin}}/mcp'
const NOTE = join(here, '../../backend/config/welcome/2-connect-your-first-assistant.md')
// The Docs page carries the same steps, and the profile prompt Settings offers.
const DOCS = join(here, '../../backend/config/docs/memex-docs.md')
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
    if (items.length !== client.steps.length) {
      out.push(`${client.label}: ${items.length} numbered steps, Settings has ${client.steps.length}`)
    }
    client.steps.forEach((ins, i) => {
      const item = items[i] ?? ''
      const step = `${client.label} step ${i + 1}`
      for (const key of [ins.titleKey, ins.bodyKey, ins.tipKey]) {
        if (key !== undefined && !item.includes(flat(t(key)))) out.push(`${step} lacks: ${t(key)}`)
      }
      if (ins.address && !item.includes(MCP_URL)) out.push(`${step} lacks the address ${MCP_URL}`)
      if (ins.link && !item.includes(ins.link.url)) out.push(`${step} lacks the link ${ins.link.url}`)
    })
    if (client.permissionsKey !== undefined) {
      for (const text of [t(client.permissionsKey), t('connections.guides.permissions_tip')]) {
        if (!flat(section).includes(flat(text))) out.push(`${client.label}: the optional permissions line lacks: ${text}`)
      }
    }
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
  ['a step dropped from the note', note.replace(/^4\. \*\*Save your custom app[\s\S]*?(?=\n\n)/m, '')],
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

const docs = readFileSync(DOCS, 'utf8')
const docsFound = problems(docs)
if (!flat(docs).includes(flat(PROFILE_PROMPT))) docsFound.push('the profile prompt differs from profilePrompt.ts')

// Every place that opens the Docs at a section names one that exists: the
// Docs' own links, and the app's links to them.
function anchors(text) {
  return new Set([...text.matchAll(/^#{1,2} (.+)$/gm)].map((m) => slug(m[1].trim())))
}
function sources(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) sources(path, out)
    else if (/\.(vue|ts)$/.test(name)) out.push(path)
  }
  return out
}
function brokenAnchors(text, appFiles) {
  const known = anchors(text)
  const out = []
  for (const m of text.matchAll(/\]\(#([a-z0-9-]+)\)/g)) {
    if (!known.has(m[1])) out.push(`the Docs link to #${m[1]}, which is no heading`)
  }
  for (const [file, source] of appFiles) {
    for (const m of source.matchAll(/name: 'docs', hash: '#([a-z0-9-]+)'/g)) {
      if (!known.has(m[1])) out.push(`${file} opens the Docs at #${m[1]}, which is no heading`)
    }
  }
  return out
}
const appFiles = sources(join(here, '../src')).map((path) => [path.slice(path.indexOf('src/')), readFileSync(path, 'utf8')])
const anchorDrift = docs.replace('## Find notes', '## Finding notes')
if (brokenAnchors(anchorDrift, appFiles).length === 0) {
  console.error('FAIL the connect-note check cannot see a Docs section the app links to being renamed')
  process.exit(1)
}
docsFound.push(...brokenAnchors(docs, appFiles))
if (docsFound.length > 0) {
  console.error(`FAIL backend/config/docs/memex-docs.md no longer says what Settings says:\n  ${docsFound.join('\n  ')}`)
  process.exit(1)
}
console.log(`Connect note and Docs passed: ${CLIENTS.map((c) => `${c.label} ${c.steps.length} steps`).join(', ')}, the prompts and the address match Settings.`)
