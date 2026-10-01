<script setup lang="ts">
// The note form, create and edit.
//
// ## Importing is a line, not a step (operator, 2026-08-23, 2026-08-28)
//
// Numbered steps would describe a procedure nobody follows: most notes are
// typed, and a numbered Step 1 that most people skip makes the form look like
// it has a beginning they are missing. So filling the form from a file is one
// row above it, and only when creating — importing into an edit would replace
// the content, which is not what editing is for.
//
// ## Analyze first, Save second (operator, 2026-08-24)
//
// Analyze is back, and optional. It went on 2026-08-23 because it spent money
// the instant it was pressed and a user may have no provider of their own —
// *"I don't want them to use my API."* That is now structurally impossible for
// the expensive half: every text call refuses without the team's own key, so a
// knowledge base with no AI configured reaches none of them. What is left on
// the operator's account is one embedding, which the save was going to buy a
// moment later anyway, and which buys the chance not to create the duplicate
// at all.
//
// Two of the three checks cost NOTHING — the link and tag candidates are
// string matching against this team's own titles and vocabulary — so the panel
// is useful on an installation with no provider at all rather than empty.
//
// Everything it finds is applied HERE, in the form, by the person: a link
// rewrites the body, a tag joins the tag field, a summary fills the summary
// box. Nothing is written until Save. That is the same line the server draws
// for agents — it reports, the writer decides.
//
// Enrichment still has no control here. A save describes an undescribed note
// and files it under tags the vocabulary already has, when the team has
// switched that on; words the model invents are named in the toast and never
// written.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api, ApiError, type AnalyzeAllowance, type AnalyzeResult, type EditProposalItem } from '@/api/client'
import AnalyzeAllowanceNote from '@/components/AnalyzeAllowanceNote.vue'
import { renderNote } from '@/lib/markdown'
import { formatCount, noteStats } from '@/lib/noteStats'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import NoteDeleteDialog from '@/components/NoteDeleteDialog.vue'
import { SUMMARY_SOFT_CAP } from '@/lib/summaryLength'
import TagInput from '@/components/TagInput.vue'
import { toastError, toastSuccess } from '@/components/toastService'
import PaneToggle from '@/components/PaneToggle.vue'
import { useLayoutStore } from '@/stores/layout'
import { useAuthStore } from '@/stores/auth'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import OperationOutcomeNotice from '@/components/OperationOutcomeNotice.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const layout = useLayoutStore()
const auth = useAuthStore()

const noteId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isAddForm = computed(() => noteId.value === null)

const lifetime = useOperationLifetime()
const outcomeUnknown = ref(false)

const loading = ref(true)
const saving = ref(false)
// An edit whose note would not load. The form is NOT shown in that state: a
// blank writable sheet where the note should be looks like a note somebody
// emptied, and saving it would make that true.
const loadFailed = ref(false)
// The version this form was opened against, and whether a save has already
// been refused for being written against a stale one.
const loadedVersion = ref<number | null>(null)
const conflict = ref(false)

/**
 * Work an assistant has waiting on this note.
 *
 * Loaded with the note so the notice is there BEFORE the save — the point is
 * that somebody can go and read the proposal rather than discover it as an
 * obstacle. `pendingDrafts` is the refused save's own list, which is the same
 * thing a moment later and is what the dialog names.
 */
const pendingCount = ref(0)
const pendingDrafts = ref<EditProposalItem[]>([])
const discardDialogOpen = ref(false)
/** The last refused save, kept on the page rather than only in a toast. */
const saveError = ref<string | null>(null)

const fileInput = ref<HTMLInputElement | null>(null)
/** An import that failed, reported inside the dock that started it. */
const intakeError = ref<string | null>(null)

const entity = ref({
  title: '',
  bodyMd: '',
  sourceUrl: '',
  summary: '',
  tags: [] as string[],
})
const errors = ref<{ title: boolean; body: boolean }>({ title: false, body: false })
/** Past the target length. Colours the counter; refuses nothing. */
const summaryOverCap = computed(() => entity.value.summary.length > SUMMARY_SOFT_CAP)

const titleInput = ref<HTMLTextAreaElement | null>(null)

/** The title box is as tall as the title, so it wraps where Note View wraps. */
function growTitle() {
  const el = titleInput.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight}px`
}
const bodyRegion = ref<HTMLElement | null>(null)

const existingTagNames = ref<string[]>([])
/** id → name, so a model's `tag_ids` can be shown as words. */
const tagNameById = ref<Record<number, string>>({})

// ---- Analyze -----------------------------------------------------------
const analyzing = ref(false)
const analysis = ref<AnalyzeResult | null>(null)
/** Who writes Analyze's summaries and titles, and how many clicks are left today. */
const allowance = ref<AnalyzeAllowance | null>(null)
/** A refused Analyze, kept inside the panel that asked for it. */
const analyzeError = ref<string | null>(null)
/** The draft the open findings were run against. */
const analyzedDraft = ref<string | null>(null)

/** Everything Analyze is given, in one comparable string. */
function draftFingerprint(): string {
  return [entity.value.title.trim(), entity.value.bodyMd, [...entity.value.tags].sort().join(',')]
    .join('\u0000')
}
/** Link and tag candidates the person has already taken, so a second Analyze
 *  does not offer back what is now in the form. */
const takenLinks = ref<string[]>([])

/** Candidates still worth offering: not taken, and still present in the body. */
const openLinks = computed(() =>
  (analysis.value?.hints.links ?? []).filter(
    (l) => !takenLinks.value.includes(l.find) && entity.value.bodyMd.includes(l.find),
  ),
)
/**
 * One list of tags to file this note under, from both sources.
 *
 * The free one is string matching against the team's own vocabulary; the paid
 * one is a model choosing from that same vocabulary, and only when the team
 * has a key. They answer the same question and overlap often, so showing them
 * as two lists would ask the reader to notice that "infrastructure" appears
 * twice for reasons that do not concern them.
 *
 * Words the model INVENTED stay separate and stay unclickable — see the
 * template. A taxonomy that grows by accepting suggestions stops being one
 * (operator, 2026-08-23).
 */
const openTags = computed(() => {
  const fromVocabulary = analysis.value?.hints.tags ?? []
  const fromModel = (analysis.value?.suggested_tags.tag_ids ?? [])
    .map((id) => tagNameById.value[id])
    .filter((name): name is string => Boolean(name))
  return [...new Set([...fromVocabulary, ...fromModel])].filter(
    (t) => !entity.value.tags.includes(t),
  )
})

/**
 * Whether the findings still describe what is in the form.
 *
 * Taking a suggestion rewrites the draft, so the comparison ignores that: only
 * the writer's own edits since the run make a finding old news.
 */
const analysisIsStale = computed(() => {
  if (analysis.value === null || analyzedDraft.value === null) return false
  return analyzedDraft.value !== draftFingerprint()
})

const analysisIsEmpty = computed(() => {
  const a = analysis.value
  if (!a) return false
  return (
    !a.summary && !a.suggested_title &&
    !a.suggested_tags.new_tags.length &&
    !a.hints.duplicates?.length && !openLinks.value.length && !openTags.value.length
  )
})

async function analyze() {
  if (analyzing.value || outcomeUnknown.value) return
  if (entity.value.bodyMd.trim() === '') {
    analyzeError.value = t('editor.analyze.nothing_to_check')
    return
  }
  const mine = lifetime.capture()
  analyzing.value = true
  analyzeError.value = null
  try {
    takenLinks.value = []
    const draft = draftFingerprint()
    const result = await api.analyze(
      entity.value.title.trim(),
      entity.value.bodyMd,
      noteId.value,
      entity.value.tags,
    )
    // The findings describe the note this call was made from, and a navigation
    // since then means that is no longer the note on screen.
    if (!lifetime.current(mine)) return
    analysis.value = result
    allowance.value = result.allowance
    analyzedDraft.value = draft
  } catch (e) {
    if (!lifetime.current(mine)) return
    analyzeError.value = e instanceof Error ? e.message : t('common.unknown_error')
    toastError(t('editor.analyze.failed'), analyzeError.value)
  } finally {
    if (lifetime.current(mine)) analyzing.value = false
  }
}

/**
 * Apply one link candidate to the body.
 *
 * The server built `find` to occur exactly once — that is what makes the same
 * operation safe to send to `propose(patch:)` — so a plain first-occurrence
 * replace here is the same edit the API would make.
 */
function takeLink(find: string, replace: string) {
  applySuggestion(() => {
    entity.value.bodyMd = entity.value.bodyMd.replace(find, replace)
    takenLinks.value = [...takenLinks.value, find]
  })
}

function takeTag(name: string) {
  applySuggestion(() => {
    if (!entity.value.tags.includes(name)) entity.value.tags = [...entity.value.tags, name]
  })
}

/**
 * Taking a suggestion is not the draft drifting away from the findings — but it
 * cannot un-drift what the writer already changed, so the baseline moves only
 * when the findings still described the draft a moment ago.
 */
function applySuggestion(change: () => void) {
  const wasFresh = !analysisIsStale.value
  change()
  if (wasFresh && analyzedDraft.value !== null) analyzedDraft.value = draftFingerprint()
}

// A field that is still shouting after it has been corrected teaches people to
// ignore it, so the errors clear as the fields become valid rather than at the
// next Save.
watch(() => entity.value.bodyMd, (body) => {
  if (errors.value.body && body.trim() !== '') errors.value.body = false
})
watch(() => entity.value.title, (title) => {
  if (errors.value.title && title.trim() !== '' && title.length <= 500) errors.value.title = false
})

const previewMode = ref(false)
const rendered = computed(() => renderNote(entity.value.bodyMd, []))
/** Live, from the body — never stored, so it cannot go stale. */
const stats = computed(() => noteStats(entity.value.bodyMd))
function counted(key: string, n: number): string {
  return t(key, n, { named: { n: formatCount(n) } })
}

const pristine = ref<string | null>(null)
/** Set by save and by cancel alike: both are the user saying "I am done here",
 *  and a Cancel that then asks whether you meant it is friction with nothing
 *  behind it. */
let leaving = false

const dirty = computed(
  () => pristine.value !== null && pristine.value !== JSON.stringify(entity.value),
)

/**
 * What the header says about the draft.
 *
 * Never a long-lived `Saved`: a successful save leaves this page for the notes
 * list, so a saved state here could only ever be a lie about a form that is
 * still open.
 */
const saveState = computed(() => {
  if (saving.value) return { text: t('common.saving'), icon: 'fa-solid fa-circle-notch fa-spin', tone: 'busy' }
  if (conflict.value) return { text: t('editor.state.conflict'), icon: 'fa-solid fa-triangle-exclamation', tone: 'warn' }
  if (saveError.value) return { text: t('editor.state.save_failed'), icon: 'fa-solid fa-circle-exclamation', tone: 'error' }
  if (dirty.value) return { text: t('editor.state.unsaved'), icon: 'fa-regular fa-pen-to-square', tone: 'dirty' }
  return null
})

function snapshot() {
  pristine.value = JSON.stringify(entity.value)
}

function mayDiscardDraft() {
  if (leaving || pristine.value === null) return true
  return !dirty.value || window.confirm(t('editor.confirm_leave'))
}

onBeforeRouteLeave(mayDiscardDraft)
// Every edit URL is the SAME route record, so moving from one note's editor to
// another reuses this component: without these two the guard never runs, the
// draft and `loadedVersion` stay with the note you left, and `noteId` follows
// the URL — a Save would then write the old draft over the new note against a
// precondition that is not its own.
onBeforeRouteUpdate(mayDiscardDraft)
watch([noteId, () => route.params.handle, () => auth.user?.team.handle], () => {
  leaving = false
  load()
})

watch(sessionEpoch, () => {
  lifetime.invalidate()
  if (loading.value) {
    loading.value = false
    loadFailed.value = true
  }
  // The server may still complete these calls after a failed sign-out.
  // Keep their locks until the user reloads or leaves this route/account.
  if (saving.value || analyzing.value) outcomeUnknown.value = true
})

// The same guarded operation as the primary button, on the platform's own key.
function onKeydown(event: KeyboardEvent) {
  const accel = navigator.platform.toLowerCase().includes('mac') ? event.metaKey : event.ctrlKey
  if (!accel || event.key.toLowerCase() !== 's') return
  event.preventDefault()
  saveEntity()
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

// ---- Bringing something in ---------------------------------------------

/** Whether an import would overwrite work that is already here. */
const draftHasContent = computed(() =>
  Boolean(
    entity.value.title.trim() || entity.value.bodyMd.trim() ||
    entity.value.summary.trim() || entity.value.tags.length,
  ),
)

function mayReplaceDraft(): boolean {
  if (!draftHasContent.value) return true
  return window.confirm(t('editor.confirm_replace'))
}

let intakeRequest = 0
let draftRevision = 0
watch(entity, () => { draftRevision++ }, { deep: true, flush: 'sync' })

function beginIntake() {
  const mine = lifetime.capture()
  const request = ++intakeRequest
  const draft = draftRevision
  return {
    current: () => lifetime.current(mine) && request === intakeRequest,
    unchanged: () => lifetime.current(mine) && request === intakeRequest && draft === draftRevision,
  }
}

function chooseFile() {
  if (outcomeUnknown.value) return
  if (!mayReplaceDraft()) return
  fileInput.value?.click()
}

// Fills the form the way a fetch does; frontmatter title and tags are honored.
async function onFileChosen(event: Event) {
  if (outcomeUnknown.value) return
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  const intake = beginIntake()
  intakeError.value = null
  try {
    const text = await file.text()
    if (!intake.unchanged()) {
      if (intake.current()) intakeError.value = t('editor.intake.changed')
      return
    }
    let body = text
    const fm = /^---\n([\s\S]*?)\n---\n?/.exec(text)
    if (fm) {
      body = text.slice(fm[0].length)
      const fmBlock = fm[1] ?? ''
      const title = /^title:\s*["']?(.+?)["']?\s*$/m.exec(fmBlock)?.[1]
      if (title) entity.value.title = title
      const tagList = /^tags:\s*\[(.*)\]\s*$/m.exec(fmBlock)?.[1]
      if (tagList) {
        const tags = tagList.split(',').map((t) => t.trim().replace(/^["']|["']$/g, '')).filter(Boolean)
        entity.value.tags = [...new Set([...entity.value.tags, ...tags])]
      }
    }
    if (!entity.value.title) {
      entity.value.title = file.name.replace(/\.(md|markdown|txt)$/i, '')
    }
    entity.value.bodyMd = body
    toastSuccess(t('editor.intake.file_loaded'), file.name)
  } catch (e) {
    if (!intake.unchanged()) return
    intakeError.value = e instanceof Error ? e.message : t('editor.intake.read_failed')
  } finally {
    if (intake.current() && fileInput.value) fileInput.value.value = ''
  }
}

function cancel() {
  leaving = true
  router.push(isAddForm.value ? { name: 'search' } : { name: 'note', params: { id: noteId.value! } })
}

/**
 * What the save toast says.
 *
 * A save files the note under tags from the vocabulary it already has; words
 * the model INVENTS are deliberately not written (operator, 2026-08-23),
 * because a taxonomy that grows on its own stops being one. They still cost
 * nothing to hear about, since they come back from the same call that did the
 * filing, so the toast names them and the person decides.
 *
 * A toast is a mention rather than an offer: accepting one still means typing
 * it into the tag field.
 */
function savedWith(title: string, suggested?: { new_tags: string[] }): string {
  const invented = suggested?.new_tags ?? []
  if (!invented.length) return title
  return t('editor.saved_with_suggestion', { title, tags: invented.join(', ') })
}

async function saveEntity(options: { overwrite?: boolean; discardDrafts?: boolean } = {}) {
  if (saving.value || outcomeUnknown.value) return
  // The request outlives a navigation, and its answer belongs to the note it
  // was made from: a 409 for A landing on B would offer to overwrite B without
  // a precondition, for a conflict B never had.
  const mine = lifetime.capture()
  errors.value.title = entity.value.title.trim() === '' || entity.value.title.length > 500
  errors.value.body = entity.value.bodyMd.trim() === ''
  if (errors.value.title || errors.value.body) {
    toastError(t('editor.validation.title'), t('editor.validation.body'))
    await nextTick()
    if (errors.value.title) {
      titleInput.value?.focus()
    } else {
      // Preview hides the editor, and a hidden element cannot take focus.
      previewMode.value = false
      await nextTick()
      bodyRegion.value?.querySelector<HTMLElement>('.cm-content')?.focus()
    }
    return
  }
  saving.value = true
  saveError.value = null
  try {
    // Back to the list after saving (mm2 behavior — operator preference).
    if (isAddForm.value) {
      const result = await api.createNote({
        title: entity.value.title.trim(),
        body_md: entity.value.bodyMd,
        tags: entity.value.tags,
        source_url: entity.value.sourceUrl.trim() || undefined,
        summary: entity.value.summary.trim(),
      })
      if (!lifetime.current(mine)) return
      toastSuccess(t('editor.created'), savedWith(result.note.title, result.suggested_tags))
    } else {
      // The precondition is dropped only for a save the person asked to be an
      // overwrite. An ordinary Save after a conflict is refused again, on
      // purpose: the guard exists to stop an overwrite nobody knew they were
      // making, and pressing the same button twice is not being told.
      const expected = options.overwrite ? null : loadedVersion.value
      const result = await api.updateNote(noteId.value!, {
        title: entity.value.title.trim(),
        body_md: entity.value.bodyMd,
        tags: entity.value.tags,
        summary: entity.value.summary.trim(),
        ...(expected !== null ? { expected_version: expected } : {}),
        ...(options.discardDrafts ? { discard_proposals: true } : {}),
      })
      if (!lifetime.current(mine)) return
      toastSuccess(t('editor.updated'), savedWith(result.note.title, result.suggested_tags))
    }
    if (!lifetime.current(mine)) return
    conflict.value = false
    leaving = true
    router.push({ name: 'search' })
  } catch (e) {
    if (!lifetime.current(mine)) return
    // 409 is not a failure to report as one: nothing is wrong with what was
    // typed, somebody else simply got there first. Saying so, and leaving the
    // text in the editor where it can be copied, is the whole handling — an
    // automatic merge would be guessing at which version the user meant.
    if (e instanceof ApiError && e.status === 409 && e.data.conflict === 'pending_proposals') {
      // Not a failure and not a race: somebody's work is waiting, and saving
      // over it is a choice to make once it has been named.
      pendingDrafts.value = (e.data.pending_proposals as EditProposalItem[]) ?? []
      pendingCount.value = pendingDrafts.value.length
      discardDialogOpen.value = true
    } else if (e instanceof ApiError && e.status === 409) {
      conflict.value = true
      toastError(t('editor.conflict.toast_title'), t('editor.conflict.toast_body'))
    } else {
      saveError.value = e instanceof Error ? e.message : t('common.unknown_error')
      toastError(t('editor.save_failed_toast'), saveError.value)
    }
  } finally {
    if (lifetime.current(mine)) saving.value = false
  }
}

function confirmDiscardDrafts() {
  discardDialogOpen.value = false
  saveEntity({ discardDrafts: true })
}

/** Who filed what, for the dialog: a name and the headline each one carries. */
const draftSummary = computed(() =>
  pendingDrafts.value
    .map((p) => (p.change_title ? `${p.proposed_by} — ${p.change_title}` : p.proposed_by))
    .join('; '),
)

// ---- Deleting ----------------------------------------------------------
const deleteDialogOpen = ref(false)
const deleteButton = ref<HTMLButtonElement | null>(null)

/**
 * Name the held drafts before the dialog asks, not after the server refuses.
 * The note carries only a COUNT, so the documents themselves are fetched the
 * once, and only when there is something to warn about.
 */
async function openDeleteDialog() {
  const mine = lifetime.capture()
  const id = noteId.value
  deleteDialogOpen.value = true
  if (pendingCount.value === 0 || pendingDrafts.value.length > 0) return
  try {
    const { proposals } = await api.proposals()
    if (!lifetime.current(mine)) return
    pendingDrafts.value = proposals.filter((p) => p.note?.id === id)
  } catch {
    // The server refuses the delete and names them anyway; a failed lookup
    // must not stand between the owner and their own note.
  }
}

function closeDeleteDialog() {
  deleteDialogOpen.value = false
  nextTick(() => deleteButton.value?.focus())
}

async function deleteEntity() {
  if (!noteId.value || saving.value || outcomeUnknown.value) return
  const mine = lifetime.capture()
  saving.value = true
  try {
    // Named in the dialog before the click, so discarding them is a choice
    // rather than a consequence. The note is restorable for 30 days; a draft
    // held against it is not.
    await api.deleteNote(noteId.value, pendingDrafts.value.length > 0 ? { discard_proposals: true } : {})
    if (!lifetime.current(mine)) return
    toastSuccess(t('editor.deleted'), entity.value.title)
    leaving = true
    deleteDialogOpen.value = false
    router.push({ name: 'search' })
  } catch (e) {
    if (!lifetime.current(mine)) return
    // A draft that landed between opening this note and pressing Delete. The
    // dialog stays open and now names it.
    if (e instanceof ApiError && e.status === 409 && e.data.conflict === 'pending_proposals') {
      pendingDrafts.value = (e.data.pending_proposals as EditProposalItem[]) ?? []
      pendingCount.value = pendingDrafts.value.length
    } else {
      closeDeleteDialog()
      toastError(t('editor.delete_failed'), e instanceof Error ? e.message : t('common.unknown_error'))
    }
  } finally {
    if (lifetime.current(mine)) saving.value = false
  }
}

// ---- Loading -----------------------------------------------------------
async function load() {
  outcomeUnknown.value = false
  lifetime.invalidate()
  const mine = lifetime.capture()
  loading.value = true
  loadFailed.value = false
  // No snapshot until the note is in the form, or the blank reset below reads
  // as an edit and a second navigation asks about changes nobody made.
  pristine.value = null
  deleteDialogOpen.value = false
  previewMode.value = false
  intakeRequest++
  takenLinks.value = []
  analyzedDraft.value = null
  conflict.value = false
  pendingCount.value = 0
  pendingDrafts.value = []
  discardDialogOpen.value = false
  saveError.value = null
  intakeError.value = null
  analysis.value = null
  analyzeError.value = null
  analyzing.value = false
  saving.value = false
  errors.value = { title: false, body: false }
  loadedVersion.value = null
  entity.value = { title: '', bodyMd: '', sourceUrl: '', summary: '', tags: [] }
  if (isAddForm.value && route.query.skill === '1') {
    entity.value.tags = ['skill']
    entity.value.bodyMd = t('skills.scaffold')
  }
  try {
    const vocabulary = (await api.tags()).tags
    if (!lifetime.current(mine)) return
    existingTagNames.value = vocabulary.map((t) => t.name)
    tagNameById.value = Object.fromEntries(vocabulary.map((t) => [t.id, t.name]))
  } catch {
    if (!lifetime.current(mine)) return
    existingTagNames.value = []
    tagNameById.value = {}
  }
  if (!isAddForm.value) {
    const wanted = noteId.value!
    try {
      const note = await api.getNote(wanted)
      if (!lifetime.current(mine)) return
      // What this edit is written against. Sent back on save so that a note
      // rewritten by a curator token while this form sat open is a conflict to
      // resolve, not work silently overwritten.
      loadedVersion.value = note.version ?? null
      pendingCount.value = note.pending_proposals ?? 0
      entity.value.title = note.title
      entity.value.bodyMd = note.body_md
      entity.value.sourceUrl = note.source_url || ''
      entity.value.summary = note.summary || ''
      entity.value.tags = note.tags.map((t) => t.name)
    } catch (e) {
      if (!lifetime.current(mine)) return
      loadFailed.value = true
      toastError(t('editor.load_failed_toast'), e instanceof Error ? e.message : t('common.unknown_error'))
    }
  }
  if (!lifetime.current(mine)) return
  loading.value = false
  snapshot()
  await nextTick()
  if (lifetime.current(mine)) growTitle()
}

async function loadAllowance() {
  const mine = lifetime.capture()
  try {
    const answer = await api.analyzeAllowance()
    if (lifetime.current(mine)) allowance.value = answer
  } catch {
    // The sentence is a courtesy; the card works without it.
  }
}

onMounted(() => {
  load()
  loadAllowance()
  window.addEventListener('resize', growTitle)
})
onBeforeUnmount(() => window.removeEventListener('resize', growTitle))
</script>

<template>
  <div class="container">
    <OperationOutcomeNotice v-if="outcomeUnknown" />
    <template v-if="loading">
      <div class="mm-writing-skeleton" aria-hidden="true">
        <div class="app-skeleton mm-skeleton-header"></div>
        <div class="mm-writing-desk">
          <div class="app-skeleton mm-skeleton-paper"></div>
          <div class="mm-skeleton-rail">
            <div class="app-skeleton mm-skeleton-panel"></div>
            <div class="app-skeleton mm-skeleton-panel"></div>
          </div>
        </div>
      </div>
      <p class="visually-hidden" role="status">{{ $t('editor.loading') }}</p>
    </template>

    <template v-else-if="loadFailed">
      <nav class="app-breadcrumbs mm-editor-breadcrumbs" :aria-label="$t('editor.breadcrumb')">
        <RouterLink :to="{ name: 'search' }">{{ $t('editor.notes') }}</RouterLink>
        <span aria-hidden="true">›</span>
        <strong aria-current="page">{{ $t('common.edit') }}</strong>
      </nav>
      <div class="app-state app-state-error mm-editor-failure">
        <h1>{{ $t('editor.load_failed.title') }}</h1>
        <p>{{ $t('editor.load_failed.body') }}</p>
        <div class="mm-editor-failure-actions">
          <button type="button" class="btn btn-primary" @click="load">{{ $t('common.retry') }}</button>
          <RouterLink class="btn btn-outline-secondary" :to="{ name: 'search' }">{{ $t('editor.load_failed.back') }}</RouterLink>
        </div>
      </div>
    </template>

    <template v-else>
      <nav class="app-breadcrumbs mm-editor-breadcrumbs" :aria-label="$t('editor.breadcrumb')">
        <RouterLink :to="{ name: 'search' }">{{ $t('editor.notes') }}</RouterLink>
        <span aria-hidden="true">›</span>
        <template v-if="!isAddForm">
          <RouterLink :to="{ name: 'note', params: { id: noteId } }">{{ entity.title || $t('editor.note_fallback') }}</RouterLink>
          <span aria-hidden="true">›</span>
        </template>
        <strong aria-current="page">{{ isAddForm ? $t('editor.new_note') : $t('common.edit') }}</strong>
      </nav>

      <form @submit.prevent="saveEntity()">
        <!-- The same block Note View uses, down to the class names, so the
             paper's edge and the actions land at the same height on both and
             opening the editor does not move the note. -->
        <header class="mm-note-heading">
          <div class="mm-note-identity">
            <h1 class="app-eyebrow mm-note-eyebrow">
              {{ isAddForm ? $t('editor.new_note') : $t('editor.editing_note', { id: noteId }) }}
            </h1>
            <label for="title" class="visually-hidden">{{ $t('editor.title_label') }}</label>
            <!-- A textarea rather than an input, because Note View's title
                 WRAPS and a one-line input would clip the same words — which
                 is the whole reason this block sits here. -->
            <textarea class="mm-note-title mm-title-input"
                      id="title"
                      ref="titleInput"
                      rows="1"
                      v-model="entity.title"
                      maxlength="500"
                      :placeholder="$t('editor.title_placeholder')"
                      :disabled="saving || outcomeUnknown"
                      :aria-invalid="errors.title || undefined"
                      :aria-describedby="errors.title ? 'title-error' : undefined"
                      @input="growTitle"
                      @keydown.enter.prevent></textarea>
            <div class="mm-note-subline">
              <!-- Text and a shape, never colour alone. -->
              <span v-if="saveState" class="mm-save-state" :class="`is-${saveState.tone}`" role="status">
                <i :class="saveState.icon"></i>{{ saveState.text }}
              </span>
              <span v-else>{{ $t('editor.nothing_to_save') }}</span>
            </div>
            <p class="mm-field-error" id="title-error" v-if="errors.title">
              {{ $t('editor.title_error') }}
            </p>
          </div>

          <div class="mm-note-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving || outcomeUnknown" @click="cancel">
              {{ $t('common.cancel') }}
            </button>
            <button type="submit" class="btn btn-sm btn-primary" :disabled="saving || outcomeUnknown">
              <i class="fa-regular fa-circle-check"></i>
              {{ isAddForm ? $t('editor.create_note') : $t('editor.save_changes') }}
              <span class="mm-shortcut" aria-hidden="true">⌘S</span>
            </button>
          </div>
        </header>

        <!-- Creating only. On an edit an import would REPLACE the content, which
             is not what editing is for (operator, 2026-08-23). -->
        <section class="mm-intake-dock" v-if="isAddForm">
          <button type="button" class="btn btn-sm btn-outline-secondary nowrap" :title="$t('editor.intake.title')" @click="chooseFile">
            <i class="fa-solid fa-file-arrow-down"></i> {{ $t('editor.intake.button') }}
          </button>
          <input type="file" class="d-none" ref="fileInput" accept=".md,.markdown,.txt" @change="onFileChosen">
          <p class="mm-intake-error" v-if="intakeError">{{ intakeError }}</p>
        </section>

        <!-- Before the save rather than after it: the choice this notice
             leads to is only a real choice while there is still time to go and
             read what is waiting. -->
        <div class="app-notice app-notice-pending mm-proposal-notice" v-if="pendingCount > 0">
          <span class="app-notice-mark"><i class="fa-solid fa-file-pen"></i></span>
          <i18n-t keypath="editor.pending_drafts.notice" tag="p" :plural="pendingCount" scope="global">
            <template #link>
              <router-link :to="{ name: 'inbox' }">{{ $t('editor.pending_drafts.link') }}</router-link>
            </template>
          </i18n-t>
        </div>

        <!-- A refused save, kept in front of the operator rather than left in a
             toast that has already gone. Their text is still in the form; what
             they need is to know it did not land, and what the choices are. -->
        <div class="app-notice app-notice-warning mm-conflict-notice" role="alert" v-if="conflict">
          <span class="app-notice-mark"><i class="fa-solid fa-triangle-exclamation"></i></span>
          <div>
            <strong>{{ $t('editor.conflict.title') }}</strong>
            <p>{{ $t('editor.conflict.body') }}</p>
            <div class="mm-conflict-actions">
              <a class="btn btn-sm btn-outline-secondary"
                 :href="`/notes/${noteId}`"
                 target="_blank"
                 rel="noopener">
                {{ $t('editor.conflict.open_current') }} <i class="fa-solid fa-arrow-up-right-from-square"></i>
              </a>
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="conflict = false">
                {{ $t('editor.conflict.keep_editing') }}
              </button>
              <button type="button" class="btn btn-sm btn-warning" :disabled="saving || outcomeUnknown"
                      @click="saveEntity({ overwrite: true })">
                {{ $t('editor.conflict.overwrite') }}
              </button>
            </div>
          </div>
        </div>

        <div class="app-notice app-notice-danger mm-save-error" role="alert" v-if="saveError">
          <span class="app-notice-mark"><i class="fa-solid fa-circle-exclamation"></i></span>
          <div>
            <strong>{{ $t('editor.save_error.title') }}</strong>
            <p>{{ $t('editor.save_error.body', { error: saveError }) }}</p>
          </div>
        </div>

        <div class="mm-writing-desk" :class="{ 'is-preview': previewMode, 'is-wide': layout.railHidden.editor }">
          <section class="app-paper mm-writing-paper" :aria-label="$t('editor.draft')">
            <PaneToggle pane="editor" class="mm-desk-toggle" />
            <div class="mm-paper-body" ref="bodyRegion">
              <MarkdownEditor v-model="entity.bodyMd"
                              :disabled="saving || outcomeUnknown"
                              :label="$t('editor.body_label')"
                              :invalid="errors.body"
                              :described-by="errors.body ? 'body-error' : undefined"
                              :placeholder="$t('editor.body_placeholder')">
                <template #rail-end>
                  <div class="app-segmented mm-mode-toggle" role="group" :aria-label="$t('editor.mode.label')">
                    <button type="button" class="app-segment" :class="{ 'is-active': !previewMode }"
                            :aria-pressed="!previewMode" @click="previewMode = false">{{ $t('editor.mode.write') }}</button>
                    <button type="button" class="app-segment" :class="{ 'is-active': previewMode }"
                            :aria-pressed="previewMode" @click="previewMode = true">{{ $t('editor.mode.preview') }}</button>
                  </div>
                </template>
              </MarkdownEditor>
              <!-- eslint-disable-next-line vue/no-v-html — renderNote escapes source markdown -->
              <div v-show="previewMode" class="mm-paper-preview note-body" v-html="rendered"></div>
            </div>

            <p class="mm-field-error mm-body-error" id="body-error" v-if="errors.body">{{ $t('editor.body_error') }}</p>

            <!-- The stats sit in the FOOTER of the sheet they describe
                 (operator, 2026-08-23) rather than in a column of their own
                 beside Tags, where they were a third box competing for width
                 with two fields you actually type into. -->
            <footer class="mm-paper-stats">
              <span>{{ counted('editor.stats.chars', stats.chars) }}</span>
              <span>{{ counted('editor.stats.words', stats.words) }}</span>
              <span :title="$t('editor.stats.token_formula')">{{ counted('editor.stats.tokens', stats.tokens) }}</span>
              <span>{{ $t('editor.stats.reading', stats.readingMinutes) }}</span>
              <span>{{ $t('editor.stats.headings', stats.headings) }}</span>
              <span>{{ $t('editor.stats.wiki_links', stats.wikiLinks) }}</span>
            </footer>
          </section>

          <!-- What the note is ABOUT, beside what it says: on a desktop the
               pair fits, and a title stretched across 1180px was the cost of
               stacking them (operator, 2026-08-28). Below `lg` they stack in
               the order they are written here. -->
          <aside class="mm-editor-rail" :aria-label="$t('editor.rail_label')">
            <section class="app-panel mm-editor-panel">
              <label for="editor_summary" class="mm-field-label">
                <span>{{ $t('editor.summary_label') }}</span>
                <!-- A count, not a limit. Nothing here refuses a longer
                     description; going amber is the whole enforcement, because
                     the point is a target to write to rather than a wall to hit
                     mid-sentence (2026-08-23). -->
                <span class="mm-field-note" :class="{ 'is-over': summaryOverCap }">
                  {{ entity.summary.length }} / {{ SUMMARY_SOFT_CAP }}
                </span>
              </label>
              <textarea id="editor_summary"
                        class="form-control form-control-sm mm-summary-input"
                        v-model="entity.summary"
                        :disabled="saving || outcomeUnknown"
                        rows="5"></textarea>
            </section>

            <section class="app-panel mm-editor-panel">
              <label for="tags" class="mm-field-label"><span>{{ $t('editor.tags_label') }}</span></label>
              <TagInput v-model="entity.tags" :options="existingTagNames" :disabled="saving || outcomeUnknown" />
            </section>

            <!-- Only when the note actually came from somewhere, and never
                 editable (operator, 2026-08-23): provenance is a record of
                 where the text was fetched from, not a field. An empty box
                 inviting one to be typed made it look like metadata anybody
                 could assert. -->
            <section class="app-panel mm-editor-panel" v-if="entity.sourceUrl">
              <p class="mm-field-label">
                <span>{{ $t('editor.source.label') }}</span>
                <span class="mm-field-note">{{ $t('editor.source.read_only') }}</span>
              </p>
              <input type="text"
                     class="form-control form-control-sm mm-source-input"
                     id="source-link"
                     :aria-label="$t('editor.source.label')"
                     :value="entity.sourceUrl"
                     readonly>
            </section>

            <!-- What Analyze found. Everything here is applied by pressing it:
                 a link rewrites the body, a tag joins the field above, a summary
                 or title fills its box. Nothing is saved until Save. -->
            <section class="app-panel mm-editor-panel mm-assistant">
              <header class="mm-assistant-head">
                <span class="mm-assistant-mark"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                <div>
                  <h2>{{ $t('editor.assistant.title') }}</h2>
                  <small>{{ analysis ? $t('editor.assistant.choose') : $t('editor.assistant.ready') }}</small>
                </div>
                <button v-if="analysis" type="button" class="btn-close btn-close-sm"
                        :aria-label="$t('editor.assistant.dismiss')" @click="analysis = null"></button>
              </header>

              <template v-if="!analysis">
                <p class="mm-assistant-lede">{{ $t('editor.assistant.lede') }}</p>
                <AnalyzeAllowanceNote :allowance="allowance" />
                <p class="mm-assistant-error" v-if="analyzeError">{{ analyzeError }}</p>
                <button type="button" class="btn btn-sm btn-outline-primary w-100"
                        :disabled="saving || analyzing || outcomeUnknown" @click="analyze">
                  <span v-if="analyzing" class="spinner-border spinner-border-sm me-1" role="status"></span>
                  {{ analyzing ? $t('editor.assistant.analyzing') : $t('editor.assistant.analyze') }}
                </button>
              </template>

              <template v-else>
                <p class="mm-assistant-error" v-if="analyzeError">
                  {{ $t('editor.assistant.previous_run', { error: analyzeError }) }}
                </p>
                <p class="mm-assistant-stale" v-else-if="analysisIsStale">
                  {{ $t('editor.assistant.stale') }}
                </p>

                <p class="mm-assistant-lede" v-if="analysisIsEmpty">
                  {{ $t('editor.assistant.nothing_to_flag') }}
                </p>

                <div class="mm-finding" v-if="analysis.hints.duplicates?.length">
                  <p class="mm-finding-label">
                    {{ analysis.hints.duplicates_total
                      ? $t('editor.assistant.possible_duplicate_counted', analysis.hints.duplicates_total)
                      : $t('editor.assistant.possible_duplicate') }}
                  </p>
                  <div class="mm-finding-row" v-for="d in analysis.hints.duplicates" :key="d.note_id">
                    <router-link :to="{ name: 'note', params: { id: d.note_id } }" target="_blank">
                      {{ d.title }}
                    </router-link>
                    <span class="mm-similarity">{{ Math.round(d.similarity * 100) }}%</span>
                    <p>{{ d.reading }}</p>
                  </div>
                </div>

                <div class="mm-finding" v-if="openLinks.length">
                  <p class="mm-finding-label">{{ $t('editor.assistant.named_not_linked') }}</p>
                  <div class="mm-finding-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            v-for="l in openLinks" :key="l.find"
                            @click="takeLink(l.find, l.replace)">
                      <i class="fa-solid fa-plus"></i>{{ l.title }}
                    </button>
                  </div>
                </div>

                <div class="mm-finding" v-if="openTags.length">
                  <p class="mm-finding-label">{{ $t('editor.assistant.from_vocabulary') }}</p>
                  <div class="mm-finding-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            v-for="t in openTags" :key="t" @click="takeTag(t)">
                      <i class="fa-solid fa-plus"></i>{{ t }}
                    </button>
                  </div>
                </div>

                <div class="mm-finding" v-if="analysis.suggested_title">
                  <p class="mm-finding-label">{{ $t('editor.assistant.stronger_title') }}</p>
                  <p class="mm-finding-text">{{ analysis.suggested_title }}</p>
                  <div class="mm-finding-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            @click="applySuggestion(() => (entity.title = analysis!.suggested_title!))">{{ $t('editor.assistant.use_title') }}</button>
                  </div>
                </div>

                <div class="mm-finding" v-if="analysis.summary && !entity.summary.trim()">
                  <p class="mm-finding-label">{{ $t('editor.assistant.a_summary') }}</p>
                  <p class="mm-finding-text">{{ analysis.summary }}</p>
                  <div class="mm-finding-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            @click="entity.summary = analysis!.summary!">{{ $t('editor.assistant.use_summary') }}</button>
                  </div>
                </div>

                <!-- Named, never clickable: accepting an invented word by
                     pressing a button is how a vocabulary stops being one
                     (operator, 2026-08-23). Typing it in is the deliberate act. -->
                <div class="mm-finding" v-if="analysis.suggested_tags.new_tags.length">
                  <p class="mm-finding-label">{{ $t('editor.assistant.not_in_vocabulary') }}</p>
                  <i18n-t keypath="editor.assistant.new_term" tag="p" class="mm-finding-text" scope="global">
                    <template #tags><strong>{{ analysis.suggested_tags.new_tags.join(', ') }}</strong></template>
                  </i18n-t>
                </div>

                <AnalyzeAllowanceNote :allowance="allowance" />

                <footer class="mm-assistant-foot">
                  <span>{{ $t('editor.assistant.nothing_saved_until', { action: isAddForm ? $t('editor.create_note') : $t('editor.save_changes') }) }}</span>
                  <button type="button" class="btn btn-link btn-sm" :disabled="analyzing || outcomeUnknown" @click="analyze">
                    {{ analyzing ? $t('editor.assistant.analyzing') : $t('editor.assistant.analyze_again') }}
                  </button>
                </footer>
              </template>
            </section>
          </aside>
        </div>

        <!-- Apart from Save and Cancel, and never in the header beside them
             (operator, 2026-08-28). -->
        <section class="app-danger-zone mm-editor-danger" v-if="!isAddForm">
          <div>
            <h2>{{ $t('editor.danger.title') }}</h2>
            <p>{{ $t('editor.danger.body') }}</p>
          </div>
          <button type="button" class="btn btn-danger" ref="deleteButton" :disabled="saving || outcomeUnknown"
                  @click="openDeleteDialog">
            <i class="fa-regular fa-trash-can"></i> {{ $t('editor.danger.delete') }}
          </button>
        </section>
      </form>

      <ConfirmDialog v-if="discardDialogOpen"
                     :title="$t('editor.pending_drafts.dialog_title')"
                     :confirm-label="$t('editor.pending_drafts.confirm')"
                     :cancel-label="$t('editor.pending_drafts.cancel')"
                     danger
                     :busy="saving"
                     @confirm="confirmDiscardDrafts"
                     @close="discardDialogOpen = false">
        <i18n-t keypath="editor.pending_drafts.dialog_body" tag="span"
                :plural="pendingDrafts.length" scope="global">
          <template #drafts>{{ draftSummary }}</template>
        </i18n-t>
      </ConfirmDialog>

      <NoteDeleteDialog v-if="deleteDialogOpen"
                        :title="entity.title"
                        :busy="saving"
                        :drafts="pendingDrafts.length > 0 ? draftSummary : undefined"
                        :draft-count="pendingDrafts.length"
                        @close="closeDeleteDialog"
                        @confirm="deleteEntity" />
    </template>
  </div>
</template>
