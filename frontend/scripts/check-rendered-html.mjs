#!/usr/bin/env node --experimental-strip-types
/**
 * Guard: text an agent wrote cannot become markup in the owner's browser.
 *
 * Note bodies, proposal comments, journal rows and diffs reach the page through
 * v-html, and each renderer claims its input is escaped. A note written over MCP
 * is the attacker's text, and the page it lands in is the owner's session. So
 * every renderer gets the same hostile corpus, and the output must be nothing
 * but the renderer's own tags and attributes, with no URL that runs script.
 *
 * Usage: node --experimental-strip-types scripts/check-rendered-html.mjs
 */
import { renderNote, renderReason, renderLogEntry } from '../src/lib/markdown.ts'
import { diffHtml, lineDiffHtml } from '../src/lib/diffs.ts'

const TAGS = new Set([
  'p', 'em', 'strong', 's', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'hr', 'br',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
  'div', 'ins', 'del',
])
const ATTRIBUTES = new Set(['href', 'src', 'alt', 'title', 'class', 'target', 'rel', 'start', 'style'])
const TAG = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s+[a-zA-Z-]+(?:="[^"<>]*")?)*)\s*\/?>/g
const ATTRIBUTE = /\s+([a-zA-Z-]+)(?:="([^"]*)")?/g

const HOSTILE = [
  '<script>alert(1)</script>',
  '<img src=x onerror=alert(1)>',
  '<svg/onload=alert(1)>',
  '<a href="javascript:alert(1)">raw</a>',
  '<iframe src="https://evil.test"></iframe>',
  '[x](javascript:alert(1))',
  '[x](JaVaScRiPt:alert(1))',
  '[x](java\tscript:alert(1))',
  '[x](&#106;avascript:alert(1))',
  '[x](&#x6A;avascript&#x3A;alert(1))',
  '[x](vbscript:msgbox(1))',
  '[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
  '![x](javascript:alert(1))',
  '![x](data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+)',
  '[x](https://a.test/"onmouseover="alert(1))',
  '[x](<https://a.test/" onmouseover="alert(1)>)',
  '[x](https://a.test "t\\" onmouseover=\\"alert(1)")',
  '![a" onerror="alert(1)](https://a.test/x.png)',
  '<javascript:alert(1)>',
  'javascript:alert(1)',
  '[x](wikilink:1" onclick="alert(1))',
  '[x](wikilink:javascript:alert(1))',
  '[x](wikilink:x)',
  '[[x" onmouseover="alert(1)]]',
  '[[<img src=x onerror=alert(1)>]]',
  '[[Target|<script>alert(1)</script>]]',
  '[[x](javascript:alert(1))]]',
  '[[Target|[x](javascript:alert(1))]]',
  '[[Target|x" onclick="alert(1)]]',
  '# <b onclick=alert(1)>heading</b>',
  '| a | b |\n|:-|-:|\n| <img src=x onerror=alert(1)> | [x](javascript:alert(1)) |',
  '```\n</code></pre><script>alert(1)</script>\n```',
  '`</code><img src=x onerror=alert(1)>`',
  '&lt;script&gt;alert(1)&lt;/script&gt;',
  '<!-- --><script>alert(1)</script>',
  '<style>body{display:none}</style>',
  '<base href="https://evil.test/">',
]
const ALL = HOSTILE.join('\n\n')
const LINKS = [
  { target: 'Target', note_id: 7 },
  { target: 'x" onmouseover="alert(1)', note_id: 8 },
  { target: '<img src=x onerror=alert(1)>', note_id: 9 },
]

function decode(value) {
  return value
    .replace(/&#x([0-9a-f]+);?/gi, (_m, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);?/g, (_m, dec) => String.fromCodePoint(Number(dec)))
    .replaceAll('&quot;', '"').replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&amp;', '&')
}

function safeUrl(name, value) {
  const url = decode(value).replace(/[\u0000- \u007f]/g, '').toLowerCase()
  const scheme = /^([a-z][a-z0-9+.-]*):/.exec(url)?.[1]
  if (!['javascript', 'vbscript', 'data', 'file'].includes(scheme)) return true
  return name === 'src' && /^data:image\/(png|gif|jpeg|webp);/.test(url)
}

function problems(html) {
  const found = []
  const rest = html.replace(TAG, (tag, _close, name, attrs) => {
    const tagName = name.toLowerCase()
    if (!TAGS.has(tagName)) found.push(`tag <${tagName}>`)
    for (const [, attr, value = ''] of attrs.matchAll(ATTRIBUTE)) {
      const attrName = attr.toLowerCase()
      if (!ATTRIBUTES.has(attrName)) found.push(`attribute ${attrName} on <${tagName}>`)
      if ((attrName === 'href' || attrName === 'src') && !safeUrl(attrName, value)) found.push(`${attrName}="${value}"`)
      if (attrName === 'style' && !/^text-align:(left|right|center)$/.test(value)) found.push(`style="${value}"`)
    }
    if (tagName === 'a' && / target="_blank"/.test(attrs) && !/ rel="noopener noreferrer"/.test(attrs)) {
      found.push('a target=_blank without rel=noopener')
    }
    return ''
  })
  if (/[<>]/.test(rest)) found.push(`unescaped markup: ${rest.match(/.{0,30}[<>].{0,30}/s)?.[0]}`)
  return found
}

const RENDERERS = {
  renderNote: (text) => renderNote(text, LINKS, 'handle123'),
  'renderNote without a handle': (text) => renderNote(text, LINKS),
  renderReason: (text) => renderReason(text).html,
  renderLogEntry: (text) => renderLogEntry(text),
  lineDiffHtml: (text) => lineDiffHtml(text, `${text}\nchanged`),
  'lineDiffHtml, removed': (text) => lineDiffHtml(text, ''),
  diffHtml: (text) => diffHtml(text, `${text} changed`),
}

const failures = []
for (const [name, render] of Object.entries(RENDERERS)) {
  for (const input of [...HOSTILE, ALL]) {
    const found = problems(render(input))
    if (found.length > 0) failures.push(`${name} ← ${JSON.stringify(input.slice(0, 60))}\n    ${found.join('\n    ')}`)
  }
}

const own = renderNote('[[Target]] and [[Missing]], [a](https://a.test) and ![i](https://a.test/i.png)', LINKS, 'handle123')
for (const expected of ['<a class="wiki-link" href="/handle123/notes/7">Target</a>', '<a class="wiki-link unresolved">Missing</a>', '<a href="https://a.test">a</a>', '<img src="https://a.test/i.png" alt="i">']) {
  if (!own.includes(expected)) failures.push(`the guard no longer sees the renderer's own markup: expected ${expected} in ${own.trim()}`)
}
if (problems(own).length > 0) failures.push(`an honest note fails the guard: ${problems(own).join(', ')}`)

if (failures.length > 0) {
  console.error(`Rendered HTML carries markup an agent wrote:\n  ${failures.join('\n  ')}`)
  process.exit(1)
}
console.log(`Rendered HTML: ${Object.keys(RENDERERS).length} renderers × ${HOSTILE.length + 1} hostile inputs, no markup gets through`)
