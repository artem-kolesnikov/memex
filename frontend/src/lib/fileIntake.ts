// What *Import note…* takes from a Markdown file: the frontmatter's title and
// tags, and for a skill a SKILL.md's `name` and `description`, the two keys
// the format requires. A skill is titled after its name, so the alias it gets
// from its title is that name.

export interface Intake {
  title: string | null
  tags: string[]
  summary: string | null
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

  return { title: title || null, tags, summary: summary || null, body }
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
