import MarkdownIt from 'markdown-it'

/**
 * The Docs page's markdown as the page shows it: an id on every part and
 * section, so the menu beside the text can point at them, and the list of
 * them for that menu.
 */
export interface DocsSection {
  id: string
  title: string
}

export interface DocsPart extends DocsSection {
  sections: DocsSection[]
}

export interface DocsDoc {
  html: string
  parts: DocsPart[]
}

const md = new MarkdownIt({ linkify: true, html: false })

export function slug(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export function docsDoc(body: string): DocsDoc {
  const env = {}
  const tokens = md.parse(body, env)
  const parts: DocsPart[] = []
  const used = new Set<string>()

  tokens.forEach((token, i) => {
    if (token.type !== 'heading_open' || (token.tag !== 'h1' && token.tag !== 'h2')) return
    const title = tokens[i + 1]?.content.trim() ?? ''
    const base = slug(title) || 'section'
    let id = base
    for (let n = 2; used.has(id); n++) id = `${base}-${n}`
    used.add(id)
    token.attrSet('id', id)
    if (token.tag === 'h1') parts.push({ id, title, sections: [] })
    else parts[parts.length - 1]?.sections.push({ id, title })
  })

  return { html: md.renderer.render(tokens, md.options, env), parts }
}
