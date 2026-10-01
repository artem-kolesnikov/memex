// Every word a person reads in the SPA comes from src/locales/en.json, so an
// installed language can translate it. This reads the templates for English
// that never went through $t(), and checks that every key the code names
// exists in the bundled file.
//
//   node scripts/check-i18n.mjs                 # the whole tree, text and keys
//   node scripts/check-i18n.mjs --text a.vue …  # text only, for a subset
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('..', import.meta.url)))
const args = process.argv.slice(2)
const textOnly = args.includes('--text')
const given = args.filter((a) => !a.startsWith('--')).map((a) => resolve(a))

// src/control is the operator's console on its own host, English only, and
// none of its words belong in a memex user's language file.
const NOT_THE_SPA = [join(root, 'src/control')]

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    if (NOT_THE_SPA.includes(path)) continue
    if (statSync(path).isDirectory()) walk(path, out)
    else if (/\.(vue|ts)$/.test(name) && !name.endsWith('.d.ts')) out.push(path)
  }
  return out
}

const files = given.length ? given : walk(join(root, 'src'))
const findings = []
const report = (file, line, what) => findings.push(`${relative(root, file)}:${line}: ${what}`)

// Attributes whose static value a person reads.
const READ_ATTRS = ['placeholder', 'title', 'aria-label', 'aria-description', 'alt', 'label', 'header', 'aria-placeholder']
const HAS_WORD = /[A-Za-z]{2,}/

function lineOf(text, index) {
  return text.slice(0, index).split('\n').length
}

function checkTemplate(file, source) {
  const start = source.indexOf('<template')
  const end = source.lastIndexOf('</template>')
  if (start === -1 || end === -1) return
  const offset = lineOf(source, start) - 1
  let tpl = source.slice(start, end)

  // Blank out what is not text a person reads, keeping newlines so line
  // numbers survive: comments, mustaches, and the inside of every tag except
  // the attributes checked below.
  const blank = (s) => s.replace(/[^\n]/g, '\0')
  tpl = tpl.replace(/<!--[\s\S]*?-->/g, blank)
  tpl = tpl.replace(/\{\{[\s\S]*?\}\}/g, blank)

  tpl = tpl.replace(/<(?:[^>"']|"[^"]*"|'[^']*')*>/g, (tag, index) => {
    const attrs = tag.matchAll(/(?<![:@\w-])([a-zA-Z-]+)="([^"]*)"/g)
    for (const m of attrs) {
      if (READ_ATTRS.includes(m[1]) && HAS_WORD.test(m[2])) {
        report(file, offset + lineOf(tpl, index + m.index), `static ${m[1]}="${m[2]}"`)
      }
    }
    return blank(tag)
  })

  for (const m of tpl.matchAll(/[^\s<\0][^<\0]*/g)) {
    const text = m[0].trim()
    if (!HAS_WORD.test(text)) continue
    // A unit or an entity beside a mustache is not a sentence.
    if (/^(&[a-z]+;|px|%|×|·|—|–|-|\/|\(|\))+$/.test(text)) continue
    report(file, offset + lineOf(tpl, m.index), `text "${text.replace(/\s+/g, ' ').slice(0, 70)}"`)
  }
}

function checkScript(file, source) {
  for (const m of source.matchAll(/\b(toastSuccess|toastError|toastInfo|toastWarn)\(\s*(['"`])/g)) {
    report(file, lineOf(source, m.index), `${m[1]}() with a literal message`)
  }
  for (const m of source.matchAll(/\bdocument\.title\s*=\s*(['"`])/g)) {
    report(file, lineOf(source, m.index), 'document.title with a literal')
  }
}

const KEY_CALL = /(?:\$t|\bt|i18n\.global\.t|\btc|\$tc)\(\s*'([^']+)'/g
const KEYPATH = /(?<![:\w-])keypath="([^"]+)"/g

function leaves(tree, prefix = '', out = new Set()) {
  for (const [k, v] of Object.entries(tree)) {
    const path = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object') leaves(v, path, out)
    else out.add(path)
  }
  return out
}

const used = new Map()
for (const file of files) {
  const source = readFileSync(file, 'utf8')
  if (file.endsWith('.vue')) checkTemplate(file, source)
  checkScript(file, source)
  for (const re of [KEY_CALL, KEYPATH]) {
    for (const m of source.matchAll(re)) {
      if (!used.has(m[1])) used.set(m[1], `${relative(root, file)}:${lineOf(source, m.index)}`)
    }
  }
}

if (!textOnly) {
  const en = JSON.parse(readFileSync(join(root, 'src/locales/en.json'), 'utf8'))
  // An edition merges its own messages over the core's (src/editions.ts).
  const known = leaves(en)
  for (const edition of readdirSync(join(root, 'src/edition'))) {
    const file = join(root, 'src/edition', edition, 'en.json')
    if (existsSync(file)) leaves(JSON.parse(readFileSync(file, 'utf8')), '', known)
  }
  for (const [key, where] of used) {
    if (!known.has(key)) findings.push(`${where}: key "${key}" is not in en.json`)
  }
  const unused = [...known].filter((k) => !used.has(k) && !k.startsWith('common.'))
  if (unused.length) {
    console.log(`${unused.length} key(s) in en.json are named nowhere in src/ (dynamic keys are invisible here):`)
    for (const k of unused) console.log(`  ${k}`)
  }
}

if (findings.length) {
  console.error(`check-i18n: ${findings.length} finding(s)`)
  for (const f of findings) console.error(`  ${f}`)
  process.exit(1)
}
console.log(`check-i18n: ${files.length} file(s), ${used.size} key(s) named, nothing left in English`)
