<script setup lang="ts">
// Docs: short how-tos for people. Assistants read the full guide, the
// `memex-guide` skill, instead. The parts and sections down the left, the text
// on the right; the section being read is lit as the page scrolls, and every
// address and prompt in the text has a copy button.
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { copyText } from '@/lib/clipboard'
import { docsDoc, type DocsDoc } from '@/lib/docsDoc'
import { toastError } from '@/components/toastService'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const doc = ref<DocsDoc | null>(null)
const failed = ref(false)
const active = ref('')
const body = ref<HTMLElement | null>(null)

async function load() {
  failed.value = false
  try {
    doc.value = docsDoc((await api.docs()).body)
    active.value = doc.value.parts[0]?.id ?? ''
    await nextTick()
    decorate()
    if (route.hash) show(route.hash.slice(1), 'auto')
  } catch {
    failed.value = true
  }
}

/** A copy button on every address and prompt; outside links open beside memex. */
function decorate() {
  const root = body.value
  if (root === null) return
  for (const block of root.querySelectorAll<HTMLElement>('pre, blockquote')) {
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'mm-docs-copy'
    button.title = t('common.copy')
    button.setAttribute('aria-label', t('common.copy'))
    button.innerHTML = '<i class="fa-regular fa-copy" aria-hidden="true"></i>'
    block.classList.add('mm-docs-copyable')
    block.append(button)
  }
  for (const link of root.querySelectorAll<HTMLAnchorElement>('a[href^="http"]')) {
    link.target = '_blank'
    link.rel = 'noopener noreferrer'
  }
}

function copyable(block: HTMLElement): string {
  if (block.tagName === 'PRE') return (block.querySelector('code')?.textContent ?? '').trim()
  return [...block.querySelectorAll('p')].map((p) => p.textContent?.replace(/\s+/g, ' ').trim()).join('\n\n')
}

async function copy(button: HTMLButtonElement) {
  const block = button.closest<HTMLElement>('.mm-docs-copyable')
  if (block === null) return
  if (!(await copyText(copyable(block)))) {
    toastError(t('common.copy_failed'))
    return
  }
  const icon = button.querySelector('i')
  icon?.classList.replace('fa-copy', 'fa-circle-check')
  setTimeout(() => icon?.classList.replace('fa-circle-check', 'fa-copy'), 1500)
}

const stage = () => document.querySelector<HTMLElement>('.app-stage')

function show(id: string, behavior: ScrollBehavior = 'smooth') {
  const target = document.getElementById(id)
  if (target === null) return
  target.scrollIntoView({ behavior, block: 'start' })
  active.value = id
}

function go(id: string) {
  show(id)
  if (route.hash !== `#${id}`) router.replace({ hash: `#${id}` })
}

function onBodyClick(event: MouseEvent) {
  const target = event.target as HTMLElement | null
  const button = target?.closest<HTMLButtonElement>('.mm-docs-copy')
  if (button) {
    void copy(button)
    return
  }
  const link = target?.closest<HTMLAnchorElement>('a[href^="#"], a[href^="/"]')
  if (!link || event.metaKey || event.ctrlKey || event.shiftKey) return
  event.preventDefault()
  const href = link.getAttribute('href')!
  if (href.startsWith('#')) go(href.slice(1))
  else router.push(href)
}

function onJump(event: Event) {
  go((event.target as HTMLSelectElement).value)
}

// The last heading scrolled past is the one being read.
let frame = 0
function track() {
  cancelAnimationFrame(frame)
  frame = requestAnimationFrame(() => {
    const scroller = stage()
    if (scroller === null || body.value === null || doc.value === null) return
    const line = scroller.getBoundingClientRect().top + 96
    let current = doc.value.parts[0]?.id ?? ''
    for (const heading of body.value.querySelectorAll<HTMLElement>('h1[id], h2[id]')) {
      if (heading.getBoundingClientRect().top > line) break
      current = heading.id
    }
    active.value = current
  })
}

watch(() => route.hash, (hash) => {
  if (route.name === 'docs' && hash && hash.slice(1) !== active.value) show(hash.slice(1))
})

onMounted(() => {
  void load()
  stage()?.addEventListener('scroll', track, { passive: true })
})
onUnmounted(() => {
  cancelAnimationFrame(frame)
  stage()?.removeEventListener('scroll', track)
})
</script>

<template>
  <div class="container">
    <header class="app-page-head">
      <h1>{{ $t('docs.title') }}</h1>
      <p class="app-page-lede">{{ $t('docs.lede') }}</p>
    </header>

    <div class="app-state" v-if="failed">
      <h4>{{ $t('docs.failed') }}</h4>
      <div class="app-state-actions">
        <button type="button" class="btn btn-secondary" @click="load">{{ $t('common.retry') }}</button>
      </div>
    </div>

    <div class="text-center my-4" v-else-if="doc === null">
      <div class="spinner-border" role="status"><span class="visually-hidden">{{ $t('search.loading') }}</span></div>
    </div>

    <div class="mm-docs" v-else>
      <nav class="mm-docs-nav" :aria-label="$t('docs.contents')">
        <div v-for="part in doc.parts" :key="part.id" class="mm-docs-part">
          <a :href="`#${part.id}`" class="mm-docs-part-title" :class="{ 'is-active': active === part.id }"
             @click.prevent="go(part.id)">{{ part.title }}</a>
          <a v-for="section in part.sections" :key="section.id" :href="`#${section.id}`" class="mm-docs-link"
             :class="{ 'is-active': active === section.id }" :aria-current="active === section.id ? 'location' : undefined"
             @click.prevent="go(section.id)">{{ section.title }}</a>
        </div>
      </nav>

      <select class="form-select mm-docs-jump" :aria-label="$t('docs.contents')" :value="active" @change="onJump">
        <optgroup v-for="part in doc.parts" :key="part.id" :label="part.title">
          <option :value="part.id">{{ part.title }}</option>
          <option v-for="section in part.sections" :key="section.id" :value="section.id">{{ section.title }}</option>
        </optgroup>
      </select>

      <article class="app-paper mm-docs-paper">
        <!-- eslint-disable-next-line vue/no-v-html — markdown-it with html off, over memex's own docs -->
        <div ref="body" class="note-body mm-docs-body" v-html="doc.html" @click="onBodyClick"></div>
      </article>
    </div>
  </div>
</template>
