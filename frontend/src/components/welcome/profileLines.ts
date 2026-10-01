/**
 * The profile screen's choices, and how they become a note.
 *
 * Compact labels describe the choices; Review changes shows the exact lines
 * before saving. Only selected lines are written. Existing custom text stays.
 */

export const PROFILE_TAG = 'user-profile'
export const PROFILE_TITLE = 'What my assistants should know about me'
export const PROFILE_SUMMARY =
  'Who I am, what I am working on, and how I want to be answered, for my assistants to read before answering about me.'

export interface ProfileGroup {
  id: 'answers' | 'personal'
  /** The heading the group's line is written under. */
  heading: string
  /** Radio groups pick one line; a checkbox group may pick none or all. */
  kind: 'radio' | 'checkbox'
  options: ProfileOption[]
}

export interface ProfileOption {
  id: string
  line: string
}

const ANSWERS: ProfileGroup = {
  id: 'answers',
  heading: '# How I want to be answered',
  kind: 'radio',
  options: [
    { id: 'short', line: 'Lead with the answer and keep it short.' },
    { id: 'full', line: 'Include explanations in your answers.' },
  ],
}

const PERSONAL: ProfileGroup = {
  id: 'personal',
  heading: '# Boundaries',
  kind: 'radio',
  options: [
    { id: 'allow', line: 'Personal details I share — health, family, money — may go into notes when they matter to the work.' },
    { id: 'keep_out', line: 'Keep personal details out of notes unless I ask for them to be kept.' },
  ],
}

/** The groups the profile screen offers. How notes are written is memex-writing's, not the profile's. */
export function profileGroups(): ProfileGroup[] {
  return [ANSWERS, PERSONAL]
}

/** Every line the screen could ever write, so an existing body can be read back. */
export function allLines(groups: ProfileGroup[]): string[] {
  return groups.flatMap((g) => g.options.map((o) => o.line))
}

/** Which of the offered lines an existing profile already carries, exactly. */
export function linesIn(body: string, groups: ProfileGroup[]): Set<string> {
  const present = new Set(body.split('\n').map((l) => l.trim()))
  return new Set(allLines(groups).filter((line) => present.has(line)))
}

/** A new profile from the chosen lines: headings in group order, nothing else. */
export function buildProfile(groups: ProfileGroup[], chosen: Set<string>): string {
  const sections: string[] = []
  for (const group of groups) {
    const lines = group.options.filter((o) => chosen.has(o.line)).map((o) => o.line)
    if (lines.length) sections.push(`${group.heading}\n${lines.join('\n')}`)
  }
  return sections.join('\n\n') + '\n'
}

/**
 * An existing profile with the offered lines brought into line with the
 * choice. A line the person switched off is removed wherever it stands; a
 * line switched on is appended under its heading, or under a new heading at
 * the end when the note has none. Every other line is untouched.
 */
export function applyChoice(body: string, groups: ProfileGroup[], chosen: Set<string>): string {
  const offered = new Set(allLines(groups))
  let lines = body.replace(/\r\n/g, '\n').split('\n')
  lines = lines.filter((l) => !(offered.has(l.trim()) && !chosen.has(l.trim())))

  for (const group of groups) {
    const wanted = group.options.map((o) => o.line).filter((line) => chosen.has(line))
    const missing = wanted.filter((line) => !lines.some((l) => l.trim() === line))
    if (missing.length === 0) continue
    const at = lines.findIndex((l) => l.trim().toLowerCase() === group.heading.toLowerCase())
    if (at === -1) {
      while (lines.length && lines[lines.length - 1]!.trim() === '') lines.pop()
      lines.push('', group.heading, ...missing)
      continue
    }
    // Under the heading: after its last non-empty line before the next heading.
    let end = at + 1
    while (end < lines.length && !/^#/.test(lines[end]!)) end++
    let insert = end
    while (insert > at + 1 && lines[insert - 1]!.trim() === '') insert--
    lines.splice(insert, 0, ...missing)
  }
  return lines.join('\n').replace(/\n*$/, '\n')
}

/** What a save would change, for the screen to say before it happens. */
export function diffChoice(body: string, groups: ProfileGroup[], chosen: Set<string>): { adds: string[]; removes: string[] } {
  const before = linesIn(body, groups)
  const adds = allLines(groups).filter((l) => chosen.has(l) && !before.has(l))
  const removes = allLines(groups).filter((l) => before.has(l) && !chosen.has(l))
  return { adds, removes }
}
