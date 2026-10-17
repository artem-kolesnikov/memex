// A note's properties: named facts beside its text, as Obsidian's frontmatter
// properties are (App\Service\NoteProperties). A value is text, a number,
// true or false, empty, a list or an object of these.

export type PropertyValue = string | number | boolean | null | PropertyValue[] | { [name: string]: PropertyValue }
export type Properties = Record<string, PropertyValue>

/** The frontmatter keys that are the note's own fields, never properties. */
export const RESERVED_PROPERTY_NAMES = ['title', 'tags', 'aliases', 'summary', 'summary_by', 'source_url', 'created', 'updated']

/** Why a name cannot be a property's, or null when it can. Mirrors the server's rules. */
export function propertyNameProblem(name: string, taken: string[]): 'empty' | 'shape' | 'reserved' | 'taken' | null {
  if (name.trim() === '') return 'empty'
  if (name !== name.trim() || name.length > 64 || /[."\p{Cc}]/u.test(name) || name.startsWith('$') || /^\d+$/.test(name)) return 'shape'
  if (RESERVED_PROPERTY_NAMES.includes(name)) return 'reserved'
  if (taken.includes(name)) return 'taken'
  return null
}

function isScalar(value: PropertyValue): value is string | number | boolean {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
}

/**
 * A value as one line of text, the way the editor shows it and reads it back:
 * a list of plain items as `[a, b]`, anything else nested as JSON.
 */
export function propertyText(value: PropertyValue): string {
  if (value === null) return ''
  if (isScalar(value)) return String(value)
  // Bare only when every item reads back as itself; a text that looks like a
  // number or a yes/no keeps its quotes, so editing one item retypes no other.
  if (Array.isArray(value) && value.every((item) => isScalar(item) && readPropertyText(String(item)) === item && !/[,[\]"']/.test(String(item)))) {
    return `[${value.join(', ')}]`
  }
  return JSON.stringify(value)
}

/**
 * What a typed value means: blank is empty, `true` and `false` a yes or no, a
 * plain number a number, `[a, b]` a list, `{…}` an object in JSON, anything
 * else text as typed.
 */
export function readPropertyText(text: string): PropertyValue {
  const typed = text.trim()
  if (typed === '') return null
  if (typed === 'true' || typed === 'false') return typed === 'true'
  if (/^-?(0|[1-9]\d*)(\.\d+)?$/.test(typed)) return Number(typed)
  if (typed.startsWith('[') && typed.endsWith(']')) {
    try {
      const parsed: unknown = JSON.parse(typed)
      if (Array.isArray(parsed)) return parsed as PropertyValue[]
    } catch {
      // Not JSON: a list written by hand.
    }
    const inner = typed.slice(1, -1).trim()
    if (inner === '') return []
    return inner.split(',').map((item) => item.trim().replace(/^(["'])(.*)\1$/, '$2')).filter((item) => item !== '')
      .map((item) => {
        const read = readPropertyText(item)
        return isScalar(read as PropertyValue) ? read : item
      })
  }
  if (typed.startsWith('{') && typed.endsWith('}')) {
    try {
      const parsed: unknown = JSON.parse(typed)
      if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) return parsed as Properties
    } catch {
      // Text that happens to be in braces.
    }
  }
  return typed
}

/** Two values, or two sets, alike in everything but the order of an object's names. */
export function samePropertyValue(a: PropertyValue | Properties | undefined, b: PropertyValue | Properties | undefined): boolean {
  return canonical(a) === canonical(b)
}

function canonical(value: unknown): string {
  const sort = (v: unknown): unknown => {
    if (Array.isArray(v)) return v.map(sort)
    if (v !== null && typeof v === 'object') {
      return Object.fromEntries(Object.keys(v).sort().map((key) => [key, sort((v as Record<string, unknown>)[key])]))
    }
    return v
  }
  return JSON.stringify(sort(value === undefined ? null : value))
}

/** $current with an edit's changes laid on it: each name set, a null removed. */
export function applyPropertyChanges(current: Properties, changes: Properties | null | undefined): Properties {
  if (!changes) return current
  const next: Properties = { ...current }
  for (const [name, value] of Object.entries(changes)) {
    if (value === null) delete next[name]
    else next[name] = value
  }
  return next
}

/** One row of a properties comparison: what it was, what it becomes. */
export interface PropertyChangeRow {
  name: string
  state: 'same' | 'added' | 'removed' | 'changed'
  before: PropertyValue | undefined
  after: PropertyValue | undefined
}

/** Every property either side holds, in the resulting order, removals last. */
export function propertyChanges(before: Properties, after: Properties): PropertyChangeRow[] {
  const rows: PropertyChangeRow[] = Object.keys(after).map((name) => ({
    name,
    state: !(name in before) ? 'added' : samePropertyValue(before[name], after[name]) ? 'same' : 'changed',
    before: before[name],
    after: after[name],
  }))
  for (const name of Object.keys(before)) {
    if (!(name in after)) rows.push({ name, state: 'removed', before: before[name], after: undefined })
  }
  return rows
}

/** One row of the editor's properties. */
export interface PropertyRow {
  name: string
  text: string
  /** The value it was loaded with, kept while `text` still shows it. Typed
   *  loosely: a recursive type inside a ref is one Vue cannot unwrap. */
  loaded?: unknown
}

/** The rows as a set of properties, or null while a name is refused. */
export function propertiesOf(rows: PropertyRow[]): Properties | null {
  const out: Properties = {}
  for (const [index, row] of rows.entries()) {
    if (row.name === '' && row.text.trim() === '') continue
    if (propertyNameProblem(row.name, rows.slice(0, index).map((other) => other.name)) !== null) return null
    const loaded = row.loaded as PropertyValue | undefined
    out[row.name] = loaded !== undefined && row.text === propertyText(loaded) ? loaded : readPropertyText(row.text)
  }
  return out
}

/** A set of properties as rows. */
export function rowsOf(properties: Properties | undefined): PropertyRow[] {
  return Object.entries(properties ?? {}).map(([name, value]) => ({ name, text: propertyText(value), loaded: value }))
}
