// docs.memex.tools: the documentation for memex.tools and self-hosted memex
// alike, one page per subject. landing/build.mjs refuses a page named here
// that it does not build.
export const DOCS_ORIGIN = 'https://docs.memex.tools'

export function docsPage(page = ''): string {
  return `${DOCS_ORIGIN}/${page}`
}
