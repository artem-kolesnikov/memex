#!/usr/bin/env node
// Fails if any HTML we serve would be broken by `script-src 'self'`.
//
// The origin's CSP (deploy/nginx-security-headers.conf) allows no inline
// script at all: no 'unsafe-inline', no hashes, no nonces. That is a decision
// worth keeping, and it has one failure mode — an inline <script> or an
// onclick= attribute reintroduced into a page, which the browser then refuses
// to run. Nothing catches that locally: `npm run build` is happy, the dev
// server has no CSP, and the first symptom is a blank page in production.
//
// So it is checked here, against the BUILT output rather than the sources,
// because a bundler is perfectly capable of inlining something the source did
// not. Run over every directory this repo publishes.
//
// Usage: node scripts/check-csp-compat.mjs <dir-or-file> [...]

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const targets = process.argv.slice(2)
if (targets.length === 0) {
  console.error('usage: check-csp-compat.mjs <dir-or-file> [...]')
  process.exit(2)
}

/** Every .html file under a path, or the path itself if it is one. */
function htmlFiles(path) {
  const st = statSync(path)
  if (st.isFile()) return path.endsWith('.html') ? [path] : []
  return readdirSync(path).flatMap(name => htmlFiles(join(path, name)))
}

// An opening <script> tag carrying no src=. `type="module"`, `crossorigin`
// and friends are all fine as long as the body comes from a file.
const INLINE_SCRIPT = /<script\b(?![^>]*\bsrc\s*=)[^>]*>/gi
// on*= as an ATTRIBUTE, i.e. preceded by whitespace inside a tag. Written
// narrowly so that prose containing the word "online" is not a violation.
const INLINE_HANDLER = /\son(?:click|load|error|submit|change|input|focus|blur|mouse\w+|key\w+)\s*=/gi

const violations = []

for (const target of targets) {
  for (const file of htmlFiles(target)) {
    const html = readFileSync(file, 'utf8')
    const where = relative(process.cwd(), file)
    for (const m of html.matchAll(INLINE_SCRIPT)) {
      violations.push(`${where}: inline <script> — ${m[0]}`)
    }
    for (const m of html.matchAll(INLINE_HANDLER)) {
      violations.push(`${where}: inline event handler —${m[0]}`)
    }
  }
}

if (violations.length > 0) {
  console.error('CSP incompatibility — the origin sends `script-src \'self\'`, which')
  console.error('will refuse to run any of this:\n')
  for (const v of violations) console.error(`  ${v}`)
  console.error('\nMove the code into a file under frontend/public/ and load it with')
  console.error('<script src="...">. See frontend/public/theme-boot.js.')
  process.exit(1)
}

console.log(`CSP-compatible: no inline script in ${targets.join(', ')}`)
