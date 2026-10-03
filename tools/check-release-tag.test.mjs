import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { releaseTagProblem } from './check-release-tag.mjs'

test('a release of the MAJOR.MINOR that VERSION holds passes', () => {
  assert.equal(releaseTagProblem('v1.9.0', '1.9'), null)
  assert.equal(releaseTagProblem('v1.9.14', '1.9'), null)
})

test('a release of another MAJOR.MINOR is refused', () => {
  for (const tag of ['v2.0.0', 'v1.10.0', 'v1.90.0', 'v1.8.3']) {
    assert.match(releaseTagProblem(tag, '1.9') ?? '', /VERSION holds: tag v1\.9\.PATCH$/, tag)
  }
})

test('a tag that is not vMAJOR.MINOR.PATCH is refused', () => {
  for (const tag of ['1.9.0', 'v1.9', 'v1.9.0-rc1', 'v1.9.0.1', '']) {
    assert.match(releaseTagProblem(tag, '1.9') ?? '', /is not vMAJOR\.MINOR\.PATCH$/, tag)
  }
})

test('the command reads VERSION at the root of the tree', () => {
  const script = fileURLToPath(new URL('check-release-tag.mjs', import.meta.url))
  const version = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim()
  execFileSync('node', [script, `v${version}.0`], { stdio: 'pipe' })
  const next = `v${Number(version.split('.')[0]) + 1}.0.0`
  assert.throws(() => execFileSync('node', [script, next], { stdio: 'pipe' }), /VERSION holds/)
})
