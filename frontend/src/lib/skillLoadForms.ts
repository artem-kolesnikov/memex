export function skillLoadForms(slug: string) {
  return {
    tool: `get_skill("${slug}")`,
    resource: `memex://skill/${slug}`,
    command: `/mcp__memex__${slug}`,
  }
}
