import { SHIPPED, currentLocale } from '@/i18n'

// docs.memex.tools: the documentation for memex.tools and self-hosted memex
// alike, one page per subject, in English and every shipped language.
// landing/build.mjs refuses a page named here that it does not build.
export const DOCS_ORIGIN = 'https://docs.memex.tools'

export function docsPage(page = ''): string {
  return `${DOCS_ORIGIN}${sitePrefix()}/${page}`
}

/** `/ru` while the interface speaks a shipped language, which the public pages speak too; else nothing. */
export function sitePrefix(): string {
  const code = currentLocale()
  return SHIPPED.includes(code) ? `/${code}` : ''
}
