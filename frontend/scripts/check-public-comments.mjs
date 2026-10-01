#!/usr/bin/env node
// Fails if anything this repo publishes carries a source comment.
//
// The files under landing/ and frontend/public/ are rsynced to the box byte
// for byte — no bundler, no minifier — so every note left in them is served
// to anyone who opens view-source. Vite strips comments from what it builds,
// which is why dist/ is checked too but rarely offends.
//
// Usage: node scripts/check-public-comments.mjs <dir-or-file> [...]

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { extname, join, relative } from 'node:path'

const targets = process.argv.slice(2)
if (targets.length === 0) {
  console.error('usage: check-public-comments.mjs <dir-or-file> [...]')
  process.exit(2)
}

const MARKUP = new Set(['.html', '.htm', '.svg', '.xml'])
const CODE = new Set(['.js', '.mjs', '.css'])

function files(path) {
  const st = statSync(path)
  if (st.isFile()) {
    const ext = extname(path)
    return MARKUP.has(ext) || CODE.has(ext) ? [path] : []
  }
  return readdirSync(path).flatMap(name => files(join(path, name)))
}

function lineOf(src, index) {
  let line = 1
  for (let i = 0; i < index; i++) if (src[i] === '\n') line++
  return line
}

function markupComments(src) {
  const found = []
  const re = /<!--[\s\S]*?(?:-->|$)/g
  for (const m of src.matchAll(re)) found.push(lineOf(src, m.index))
  return found
}

// A `/` opens a regex literal rather than a division only in expression
// position. Everything before it that is not one of these is an operand, and
// an operand can only be followed by division.
const REGEX_OK_BEFORE = /[=(,:[!&|?{};+\-*%~^<>]$/
const REGEX_OK_KEYWORD = /\b(?:return|typeof|instanceof|in|of|case|new|delete|void|do|else|yield|await)$/

function codeComments(src, { slashSlash }) {
  const found = []
  let i = 0
  let prev = ''
  const templates = []
  while (i < src.length) {
    const c = src[i]
    const next = src[i + 1]
    if (c === '/' && next === '*') {
      found.push(lineOf(src, i))
      const end = src.indexOf('*/', i + 2)
      i = end === -1 ? src.length : end + 2
      continue
    }
    if (slashSlash && c === '/' && next === '/') {
      found.push(lineOf(src, i))
      const end = src.indexOf('\n', i)
      i = end === -1 ? src.length : end
      continue
    }
    if (c === '"' || c === "'") {
      i++
      while (i < src.length && src[i] !== c) i += src[i] === '\\' ? 2 : 1
      i++
      prev = c
      continue
    }
    if (c === '`') {
      i++
      while (i < src.length) {
        if (src[i] === '\\') { i += 2; continue }
        if (src[i] === '`') { i++; break }
        if (src[i] === '$' && src[i + 1] === '{') { templates.push('`'); i += 2; break }
        i++
      }
      prev = '`'
      continue
    }
    if (c === '}' && templates.length > 0) {
      templates.pop()
      i++
      while (i < src.length) {
        if (src[i] === '\\') { i += 2; continue }
        if (src[i] === '`') { i++; break }
        if (src[i] === '$' && src[i + 1] === '{') { templates.push('`'); i += 2; break }
        i++
      }
      prev = '`'
      continue
    }
    if (slashSlash && c === '/' && (prev === '' || REGEX_OK_BEFORE.test(prev) || REGEX_OK_KEYWORD.test(prev))) {
      i++
      let inClass = false
      while (i < src.length) {
        if (src[i] === '\\') { i += 2; continue }
        if (src[i] === '[') inClass = true
        else if (src[i] === ']') inClass = false
        else if (src[i] === '/' && !inClass) { i++; break }
        else if (src[i] === '\n') break
        i++
      }
      prev = '/'
      continue
    }
    if (!/\s/.test(c)) prev += c
    if (prev.length > 24) prev = prev.slice(-24)
    i++
  }
  return found
}

const violations = []

for (const target of targets) {
  for (const file of files(target)) {
    const src = readFileSync(file, 'utf8')
    const ext = extname(file)
    const lines = MARKUP.has(ext)
      ? markupComments(src)
      : codeComments(src, { slashSlash: ext !== '.css' })
    const where = relative(process.cwd(), file)
    for (const line of lines) violations.push(`${where}:${line}`)
  }
}

if (violations.length > 0) {
  console.error('Comments in published files. These ship verbatim and are')
  console.error('readable by anyone who opens view-source:\n')
  for (const v of violations) console.error(`  ${v}`)
  console.error('\nDelete them. Rationale that has to survive belongs in the commit')
  console.error('message or in docs/, not in a file a visitor can read.')
  process.exit(1)
}

console.log(`No comments in published files (${targets.length} target(s) checked).`)
