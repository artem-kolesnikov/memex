// What *Import note…* takes from a Markdown file: the frontmatter's title and
// tags, and for a skill a SKILL.md's `name` and `description`, the two keys
// the format requires. A skill is titled after its name, so the alias it gets
// from its title is that name. A note takes every other key as a property, as
// a vault import does (App\Service\NoteProperties::fromFrontmatter), in the
// shapes Obsidian writes: one value, a list in brackets or under the key.

import type { Properties, PropertyValue } from '@/lib/properties'

/** The keys that are the note's own fields, as `RESERVED_PROPERTY_NAMES` lists them; kept here so the intake check runs without the app's imports. */
const OWN_FIELDS = ['title', 'tags', 'aliases', 'summary', 'summary_by', 'source_url', 'created', 'updated']

export interface Intake {
  title: string | null
  tags: string[]
  summary: string | null
  properties: Properties
  body: string
}

const SKILL_NAME = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

export function readIntake(text: string, asSkill: boolean): Intake {
  const fm = /^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/.exec(text)
  const block = fm?.[1] ?? ''
  const body = fm ? text.slice(fm[0].length) : text

  let title = scalar(block, 'title')
  let summary: string | null = null
  if (asSkill) {
    const name = scalar(block, 'name')
    if (name && SKILL_NAME.test(name)) title = name.charAt(0).toUpperCase() + name.slice(1).replace(/-/g, ' ')
    summary = scalar(block, 'description')
  }
  const tagList = /^tags:\s*\[(.*)\]\s*$/m.exec(block)?.[1]
  const tags = tagList ? tagList.split(',').map((t) => t.trim().replace(/^["']|["']$/g, '')).filter(Boolean) : []

  return { title: title || null, tags, summary: summary || null, properties: asSkill ? {} : properties(block), body }
}

/** Every top-level key but the note's own fields, as a property. A key holding an object is left to a vault import. */
function properties(block: string): Properties {
  const out: Properties = {}
  const lines = block.split(/\r?\n/)
  for (let at = 0; at < lines.length; at++) {
    const match = /^([^\s#\-][^:]*?|"[^"]*"|'[^']*'):(?:[ \t]+(.*))?$/.exec(lines[at]!)
    if (!match) continue
    const name = unquote(match[1]!.trim())
    if (OWN_FIELDS.includes(name) || name in out || name.length > 64 || /[."]/.test(name) || name.startsWith('$') || /^\d+$/.test(name)) continue
    const rest = (match[2] ?? '').trim()
    if (rest === '') {
      const items: PropertyValue[] = []
      let nested = false
      let next = at + 1
      for (; next < lines.length; next++) {
        const line = lines[next]!
        if (line.trim() !== '' && !/^\s/.test(line) && !/^-(\s|$)/.test(line)) break
        const item = /^\s*-\s*(.*)$/.exec(line)
        if (item) items.push(plain(item[1]!.trim()))
        else if (line.trim() !== '') nested = true
      }
      at = next - 1
      if (!nested) out[name] = items.length ? items : null
      continue
    }
    if (/^[|>][+-]?$/.test(rest)) {
      out[name] = scalar(block, match[1]!)
      continue
    }
    if (rest.startsWith('{')) continue
    out[name] = rest.startsWith('[') && rest.endsWith(']') ? flowList(rest.slice(1, -1)) : plain(rest)
  }
  // An older memex export's own lines, which are no properties.
  if (['manual', 'scrape', 'upload', 'agent', 'memex'].includes(String(out.source)) && ['verified', 'pending'].includes(String(out.status))) {
    delete out.source
    delete out.status
  }

  return out
}

function flowList(inner: string): PropertyValue[] {
  const items: string[] = []
  let current = ''
  let quote: string | null = null
  for (const char of inner) {
    if (quote) {
      current += char
      if (char === quote) quote = null
    } else if (char === '"' || char === "'") {
      quote = char
      current += char
    } else if (char === ',') {
      items.push(current)
      current = ''
    } else {
      current += char
    }
  }
  items.push(current)

  return items.map((item) => item.trim()).filter((item) => item !== '').map(plain)
}

/** A YAML value written on one line, read as YAML reads it. */
function plain(text: string): PropertyValue {
  const value = text.replace(/\s+#.*$/, '')
  if (/^"(.*)"$/.test(value) || /^'(.*)'$/.test(value)) return unquote(value)
  if (value === '' || value === '~' || value === 'null') return null
  if (value === 'true' || value === 'false') return value === 'true'
  if (/^-?(0|[1-9]\d*)(\.\d+)?$/.test(value)) return Number(value)

  return value
}

function unquote(text: string): string {
  if (text.length > 1 && text.startsWith('"') && text.endsWith('"')) {
    try {
      return JSON.parse(text) as string
    } catch {
      return text.slice(1, -1)
    }
  }
  if (text.length > 1 && text.startsWith("'") && text.endsWith("'")) return text.slice(1, -1).replace(/''/g, "'")

  return text
}

function scalar(block: string, key: string): string | null {
  const lines = block.split(/\r?\n/)
  const at = lines.findIndex((line) => line.startsWith(key + ':'))
  if (at < 0) return null
  const first = lines[at]!.slice(key.length + 1).trim()
  const more: string[] = []
  for (const line of lines.slice(at + 1)) {
    if (line.trim() !== '' && !/^\s/.test(line)) break
    more.push(line.trim())
  }
  while (more.length && more[more.length - 1] === '') more.pop()

  if (/^[|>][+-]?$/.test(first)) {
    return first.startsWith('|') ? more.join('\n') : more.join(' ').replace(/\s+/g, ' ').trim()
  }
  const value = [first, ...more].filter(Boolean).join(' ')
  if (value.startsWith('"') && value.endsWith('"') && value.length > 1) {
    try {
      return JSON.parse(value) as string
    } catch {
      return value.slice(1, -1)
    }
  }
  if (value.startsWith("'") && value.endsWith("'") && value.length > 1) return value.slice(1, -1).replace(/''/g, "'")

  return value
}
