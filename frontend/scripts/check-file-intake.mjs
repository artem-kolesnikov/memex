#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: what *Import note…* takes from a file.
 *
 * A SKILL.md brought in from the new-skill editor used to be titled after its
 * file and lose its description: the editor read only `title` and `tags`.
 *
 * Usage: node --experimental-strip-types scripts/check-file-intake.mjs
 */
import { readIntake } from '../src/lib/fileIntake.ts'

const failures = []

function check(what, actual, expected) {
  const a = JSON.stringify(actual)
  const e = JSON.stringify(expected)
  if (a !== e) failures.push(`${what}\n     expected ${e}\n     got      ${a}`)
}

const skill = `---
name: pdf-processing
description: Extract text and tables from PDF files. Use when working with PDFs.
---

# PDF processing

Steps.
`
check('a SKILL.md is titled after its name', readIntake(skill, true).title, 'Pdf processing')
check('and described by its description', readIntake(skill, true).summary, 'Extract text and tables from PDF files. Use when working with PDFs.')
check('and its body is what follows the frontmatter', readIntake(skill, true).body, '\n# PDF processing\n\nSteps.\n')
check('a plain note takes neither key', [readIntake(skill, false).title, readIntake(skill, false).summary], [null, null])

check('the name outranks a title, so the alias is the name', readIntake('---\nname: pdf\ntitle: Reading PDFs\n---\nBody', true).title, 'Pdf')
check('a plain note keeps its title', readIntake('---\nname: pdf\ntitle: Reading PDFs\n---\nBody', false).title, 'Reading PDFs')
check('a name that is no alias is not used', readIntake('---\nname: Not An Alias\n---\nBody', true).title, null)

check(
  'memex\'s own export writes JSON strings',
  readIntake('---\nname: "house-style"\ndescription: "Write \\"plainly\\" — always."\nmetadata:\n  memex_note_id: "12"\n---\nBody', true),
  { title: 'House style', tags: [], summary: 'Write "plainly" — always.', body: 'Body' },
)
check(
  'a folded description is one line',
  readIntake('---\nname: x\ndescription: >-\n  Use this\n  when asked.\nlicense: MIT\n---\nBody', true).summary,
  'Use this when asked.',
)
check('a single-quoted description', readIntake("---\nname: x\ndescription: 'It''s short.'\n---\nBody", true).summary, "It's short.")
check('Windows line endings', readIntake('---\r\nname: x\r\ndescription: Short.\r\n---\r\nBody', true), { title: 'X', tags: [], summary: 'Short.', body: 'Body' })
check('tags still arrive', readIntake('---\ntitle: T\ntags: [a, "b"]\n---\nBody', false).tags, ['a', 'b'])
check('no frontmatter is all body', readIntake('# Just text', true), { title: null, tags: [], summary: null, body: '# Just text' })

if (failures.length) {
  console.error(`check-file-intake: ${failures.length} failure(s)\n`)
  for (const f of failures) console.error(`  ✗ ${f}\n`)
  process.exit(1)
}
console.log('check-file-intake: ok')
