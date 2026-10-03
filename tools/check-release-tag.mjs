#!/usr/bin/env node
// A release tag is vMAJOR.MINOR.PATCH with the MAJOR.MINOR that VERSION holds:
// node tools/check-release-tag.mjs <tag>
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

export function releaseTagProblem(tag, version) {
  const match = /^v(\d+\.\d+)\.\d+$/.exec(tag)
  if (!match) return `${tag} is not vMAJOR.MINOR.PATCH`
  if (match[1] !== version) return `${tag} is not a release of ${version}, which VERSION holds: tag v${version}.PATCH`
  return null
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const version = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim()
  const problem = releaseTagProblem(process.argv[2] ?? '', version)
  if (problem) {
    console.error(problem)
    process.exit(1)
  }
}
