#!/usr/bin/env node
import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const policy = JSON.parse(readFileSync(path.join(root, 'tools/dependency-exceptions.json')))
const today = new Date().toISOString().slice(0, 10)
let failed = false
for (const item of policy.exceptions) {
  if (!item.owner || !item.boundary || item.expires < today) throw new Error(`Missing/expired advisory review: ${item.project} ${item.advisory}`)
}
for (const project of ['frontend', 'services/ml-processor']) {
  const result = spawnSync('npm', ['audit', '--json'], { cwd: path.join(root, project), encoding: 'utf8' })
  if (result.error || ![0, 1].includes(result.status)) throw new Error(`${project}: audit failed: ${result.error || result.stderr}`)
  const report = JSON.parse(result.stdout)
  if (report.error || !report.vulnerabilities) throw new Error(`${project}: audit unavailable: ${JSON.stringify(report.error)}`)
  const lock = JSON.parse(readFileSync(path.join(root, project, 'package-lock.json')))
  const seen = new Set()
  for (const [name, vulnerability] of Object.entries(report.vulnerabilities)) {
    for (const advisory of vulnerability.via) {
      if (typeof advisory === 'string') continue // inherited graph edge; inspect its actual advisory node below
      const id = new URL(advisory.url).pathname.split('/').at(-1)
      const identity = `${name}:${id}`
      if (seen.has(identity)) continue
      seen.add(identity)
      const exception = policy.exceptions.find(e => e.project === project && e.package === name && e.advisory === id)
      const versions = Object.entries(lock.packages).filter(([key]) => key === `node_modules/${name}` || key.endsWith(`/node_modules/${name}`)).map(([, value]) => value.version)
      if (!exception || !versions.length || versions.some(version => version !== exception.version)) {
        failed = true
        console.error(`UNREVIEWED ${project} ${name} ${versions.join(',')} ${id}: ${advisory.title}`)
      } else {
        console.log(`REVIEWED until ${exception.expires}: ${project} ${name}@${exception.version} ${id}`)
      }
    }
  }
  console.log(`${project}: checked ${seen.size} distinct direct advisories; inherited package alerts are included through their source advisory`)
}
if (failed) process.exitCode = 1
