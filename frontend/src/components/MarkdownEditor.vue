<!-- Obsidian-style markdown editor (CodeMirror 6).

     Three things distinguish it from the plain textarea it replaced:
     1. the text is STYLED as you write (headings large, bold bold, code mono);
     2. the syntax that produces that styling ("##", "**", "[[ ]]") is hidden
        until the cursor enters that line — Obsidian's live-preview rule, so the
        markup is always editable but never in the way;
     3. line numbers, which make "fix line 42" a workable instruction.

     [[wiki-link]] completion is a CodeMirror completion source here (the old
     textarea-coordinate popup is gone with the textarea). -->
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import {
  Compartment,
  EditorState,
  type Extension,
  type Range,
  type TransactionSpec,
} from '@codemirror/state'
import {
  Decoration,
  EditorView,
  ViewPlugin,
  drawSelection,
  highlightActiveLine,
  highlightActiveLineGutter,
  keymap,
  lineNumbers,
  placeholder as cmPlaceholder,
  type DecorationSet,
  type ViewUpdate,
} from '@codemirror/view'
import { defaultKeymap, history, historyKeymap, redo, redoDepth, undo, undoDepth } from '@codemirror/commands'
import { HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language'
import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { unifiedMergeView } from '@codemirror/merge'
import {
  autocompletion,
  closeBrackets,
  completionKeymap,
  startCompletion,
  type Completion,
  type CompletionContext,
} from '@codemirror/autocomplete'
import { tags } from '@lezer/highlight'
import { api } from '@/api/client'
import {
  headingSpec,
  linkSpec,
  listSpec,
  tableSpec,
  wikiLinkSpec,
  wrapSpec,
  type ListKind,
} from '@/lib/editorCommands'

const props = withDefaults(
  defineProps<{
    modelValue: string
    disabled?: boolean
    placeholder?: string
    label?: string
    invalid?: boolean
    describedBy?: string
    /**
     * Show the text as a change AGAINST this, the way a diff is read in code:
     * removed lines as red widgets, added lines green, each chunk with accept
     * and reject beside it — and the whole thing still typed into. Undefined
     * for text that replaces nothing, where every line would be an addition
     * and the marking says nothing.
     */
    trackChangesAgainst?: string
  }>(),
  {
    disabled: false,
    placeholder: '',
    label: undefined,
    invalid: false,
    describedBy: undefined,
    trackChangesAgainst: undefined,
  },
)
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const host = ref<HTMLDivElement | null>(null)
const view = shallowRef<EditorView | null>(null)
const editableCompartment = new Compartment()
const fieldStateCompartment = new Compartment()
const trackChangesCompartment = new Compartment()
const canUndo = ref(false)
const canRedo = ref(false)

// Note titles for [[wiki-link]] completion. Fetched once; the completion source
// closes over this ref, so it starts working the moment the request lands.
const titles = ref<string[]>([])

// --- Styling: what the text LOOKS like -------------------------------------
// Colors come from CSS custom properties so the dark theme needs no second
// HighlightStyle (see .mm-cm rules in main.css).
const markdownHighlight = HighlightStyle.define([
  { tag: tags.heading1, fontSize: '1.55em', fontWeight: '700', lineHeight: '1.3' },
  { tag: tags.heading2, fontSize: '1.35em', fontWeight: '700', lineHeight: '1.3' },
  { tag: tags.heading3, fontSize: '1.18em', fontWeight: '700' },
  { tag: [tags.heading4, tags.heading5, tags.heading6], fontSize: '1.05em', fontWeight: '700' },
  { tag: tags.strong, fontWeight: '700' },
  { tag: tags.emphasis, fontStyle: 'italic' },
  { tag: tags.strikethrough, textDecoration: 'line-through' },
  { tag: tags.link, color: 'var(--mm-primary)', textDecoration: 'underline' },
  { tag: tags.url, color: 'var(--mm-muted)' },
  { tag: tags.monospace, color: 'var(--mm-code-ink, #b02a55)' },
  { tag: tags.quote, color: 'var(--mm-ink-soft)', fontStyle: 'italic' },
  { tag: tags.list, color: 'var(--mm-primary)' },
  { tag: tags.processingInstruction, color: 'var(--mm-muted)' }, // the marks themselves, when revealed
])

// --- Live preview: hide the markup unless the caret is on that line ---------
const HIDDEN_NODES = new Set([
  'HeaderMark',
  'EmphasisMark',
  'StrikethroughMark',
  'LinkMark',
  'CodeMark',
  'URL',
])
const hiddenMark = Decoration.replace({})
const wikiLinkMark = Decoration.mark({ class: 'cm-wikilink' })
const WIKI_LINK_RE = /\[\[([^[\]|\n]+)(\|[^[\]\n]*)?\]\]/g

function activeLineNumbers(view: EditorView): Set<number> {
  const lines = new Set<number>()
  for (const range of view.state.selection.ranges) {
    const first = view.state.doc.lineAt(range.from).number
    const last = view.state.doc.lineAt(range.to).number
    for (let n = first; n <= last; n++) lines.add(n)
  }
  return lines
}

function buildDecorations(view: EditorView): DecorationSet {
  const active = activeLineNumbers(view)
  // Collected out of order (tree walk, then a regex pass for wiki-links) and
  // handed to Decoration.set(…, true), which sorts by position AND side — a
  // RangeSetBuilder would reject the overlap of a mark and the replaces inside it.
  const ranges: Range<Decoration>[] = []

  for (const { from, to } of view.visibleRanges) {
    syntaxTree(view.state).iterate({
      from,
      to,
      enter: (node) => {
        if (!HIDDEN_NODES.has(node.name)) return
        // Fence markers stay visible (a hidden ``` reads as lost content);
        // only inline-code backticks fold away.
        if (node.name === 'CodeMark' && node.node.parent?.name !== 'InlineCode') return
        // Hide a URL only as part of [label](url) — bare autolinks stay whole.
        if (node.name === 'URL' && node.node.parent?.name !== 'Link') return
        if (active.has(view.state.doc.lineAt(node.from).number)) return

        // "## " — swallow the separating space too, or headings sit indented.
        let end = node.to
        if (node.name === 'HeaderMark' && view.state.doc.sliceString(end, end + 1) === ' ') end++
        if (end > node.from) ranges.push(hiddenMark.range(node.from, end))
      },
    })

    // [[wiki-links]] are ours, not markdown's — no grammar node to walk.
    const text = view.state.doc.sliceString(from, to)
    for (const match of text.matchAll(WIKI_LINK_RE)) {
      const start = from + (match.index ?? 0)
      const end = start + match[0].length
      ranges.push(wikiLinkMark.range(start, end))
      if (!active.has(view.state.doc.lineAt(start).number)) {
        // A [[target|label]] shows the label alone: fold "[[target|" and "]]".
        const target = match[1] ?? ''
        const openTo = match[2] ? start + 2 + target.length + 1 : start + 2
        ranges.push(hiddenMark.range(start, openTo))
        ranges.push(hiddenMark.range(end - 2, end))
      }
    }
  }

  return Decoration.set(ranges, true)
}

const livePreview = ViewPlugin.fromClass(
  class {
    decorations: DecorationSet
    constructor(view: EditorView) {
      this.decorations = buildDecorations(view)
    }
    update(update: ViewUpdate) {
      // Selection changes matter as much as edits here: moving the caret onto a
      // line is what reveals its markup.
      if (update.docChanged || update.selectionSet || update.viewportChanged) {
        this.decorations = buildDecorations(update.view)
      }
    }
  },
  { decorations: (plugin) => plugin.decorations },
)

// --- [[wiki-link]] completion ----------------------------------------------
function wikiLinkSource(context: CompletionContext) {
  const before = context.matchBefore(/\[\[[^\]\n|]{0,100}/)
  if (!before || titles.value.length === 0) return null
  const query = before.text.slice(2).trim().toLowerCase()

  const options = titles.value
    .filter((t) => t.toLowerCase().includes(query))
    .sort((a, b) => {
      const aStarts = a.toLowerCase().startsWith(query) ? 0 : 1
      const bStarts = b.toLowerCase().startsWith(query) ? 0 : 1
      return aStarts - bStarts || a.localeCompare(b)
    })
    .slice(0, 8)
    .map((title) => ({
      label: title,
      type: 'text',
      apply: (target: EditorView, _completion: Completion, from: number, to: number) => {
        // Don't double the closing brackets when completing inside [[…]].
        const closing = target.state.doc.sliceString(to, to + 2) === ']]' ? '' : ']]'
        const insert = title + closing
        target.dispatch({
          changes: { from, to, insert },
          selection: { anchor: from + title.length + 2 },
        })
      },
    }))

  if (options.length === 0) return null
  return { from: before.from + 2, options, validFor: /^[^\]\n|]*$/ }
}

function extensions(): Extension[] {
  return [
    lineNumbers(),
    highlightActiveLine(),
    highlightActiveLineGutter(),
    history(),
    drawSelection(),
    closeBrackets(),
    EditorView.lineWrapping,
    // markdownLanguage = GFM: strikethrough, tables, task lists. The plain
    // CommonMark base parses ~~text~~ as literal text, so its marks would
    // neither style nor fold.
    markdown({ base: markdownLanguage }),
    syntaxHighlighting(markdownHighlight),
    livePreview,
    autocompletion({ override: [wikiLinkSource], icons: false }),
    cmPlaceholder(props.placeholder),
    // CodeMirror gives the editable surface `role="textbox"` and nothing to
    // call it by, so the field's name and its validity live here.
    fieldStateCompartment.of(fieldStateExtension()),
    trackChangesCompartment.of(trackChangesExtension()),
    keymap.of([...defaultKeymap, ...historyKeymap, ...completionKeymap]),
    editableCompartment.of(editableExtension(props.disabled)),
    EditorView.updateListener.of((update) => {
      if (update.docChanged) emit('update:modelValue', update.state.doc.toString())
      canUndo.value = undoDepth(update.state) > 0
      canRedo.value = redoDepth(update.state) > 0
    }),
  ]
}

/**
 * The document stays the NEW text and stays editable; the original is held
 * beside it and rendered as deletions. So the value this component emits is
 * unchanged by tracking — what the operator sees marked up is what they get.
 */
function trackChangesExtension(): Extension {
  if (props.trackChangesAgainst === undefined) return []

  return unifiedMergeView({
    original: props.trackChangesAgainst,
    highlightChanges: true,
    gutter: true,
    mergeControls: true,
    syntaxHighlightDeletions: true,
  })
}

function fieldStateExtension(): Extension {
  return EditorView.contentAttributes.of({
    ...(props.label ? { 'aria-label': props.label } : {}),
    ...(props.invalid ? { 'aria-invalid': 'true' } : {}),
    ...(props.describedBy ? { 'aria-describedby': props.describedBy } : {}),
  })
}

function editableExtension(disabled: boolean): Extension {
  return [EditorState.readOnly.of(disabled), EditorView.editable.of(!disabled)]
}

onMounted(async () => {
  view.value = new EditorView({
    state: EditorState.create({ doc: props.modelValue, extensions: extensions() }),
    parent: host.value!,
  })
  try {
    titles.value = (await api.noteTitles()).titles.map((t) => t.title)
  } catch {
    titles.value = [] // completion silently unavailable
  }
})

onBeforeUnmount(() => {
  view.value?.destroy()
  view.value = null
})

watch(
  () => [props.label, props.invalid, props.describedBy],
  () => view.value?.dispatch({ effects: fieldStateCompartment.reconfigure(fieldStateExtension()) }),
)

// Parent-driven changes (file import, linkify, load) replace the
// doc; typing does not round-trip because the value already matches.
watch(
  () => props.modelValue,
  (value) => {
    const cm = view.value
    if (!cm || value === cm.state.doc.toString()) return
    cm.dispatch({ changes: { from: 0, to: cm.state.doc.length, insert: value } })
  },
)

watch(
  () => props.disabled,
  (disabled) => {
    view.value?.dispatch({ effects: editableCompartment.reconfigure(editableExtension(disabled)) })
  },
)

watch(
  () => props.trackChangesAgainst,
  () => view.value?.dispatch({ effects: trackChangesCompartment.reconfigure(trackChangesExtension()) }),
)

function run(command: (view: EditorView) => void) {
  const cm = view.value
  if (!cm || props.disabled) return
  command(cm)
}

function apply(build: (state: EditorState) => TransactionSpec) {
  run((cm) => {
    cm.dispatch(build(cm.state))
    cm.focus()
  })
}

const headingLevels = [1, 2, 3]

function wrap(mark: string) {
  apply((state) => wrapSpec(state, mark))
}

function list(kind: ListKind) {
  apply((state) => listSpec(state, kind))
}

function heading(level: number) {
  apply((state) => headingSpec(state, level))
}

function link() {
  apply(linkSpec)
}

function table() {
  apply(tableSpec)
}

function wikiLink() {
  apply(wikiLinkSpec)
  const cm = view.value
  if (cm) startCompletion(cm)
}

defineExpose({
  focus: () => view.value?.focus(),
})
</script>

<template>
  <!-- The toolbar sits OUTSIDE .mm-cm, which clips its children so a long
       document scrolls inside its own box; a dropdown opened from within it
       would be cut off at the first row. -->
  <div class="mm-editor">
    <div class="mm-editor-bar" role="toolbar" :aria-label="$t('editor.toolbar.formatting')">
      <div class="mm-editor-group">
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.undo')" :aria-label="$t('editor.toolbar.undo')"
                :disabled="disabled || !canUndo"
                @mousedown.prevent @click="run(undo)">
          <i class="fa-solid fa-rotate-left"></i>
        </button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.redo')" :aria-label="$t('editor.toolbar.redo')"
                :disabled="disabled || !canRedo"
                @mousedown.prevent @click="run(redo)">
          <i class="fa-solid fa-rotate-right"></i>
        </button>
      </div>

      <div class="mm-editor-group">
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.bold')" :aria-label="$t('editor.toolbar.bold')"
                :disabled="disabled" @mousedown.prevent @click="wrap('**')">
          <i class="fa-solid fa-bold"></i>
        </button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.italic')" :aria-label="$t('editor.toolbar.italic')"
                :disabled="disabled" @mousedown.prevent @click="wrap('*')">
          <i class="fa-solid fa-italic"></i>
        </button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.strikethrough')" :aria-label="$t('editor.toolbar.strikethrough')"
                :disabled="disabled" @mousedown.prevent @click="wrap('~~')">
          <i class="fa-solid fa-strikethrough"></i>
        </button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.inline_code')" :aria-label="$t('editor.toolbar.inline_code')"
                :disabled="disabled" @mousedown.prevent @click="wrap('`')">
          <i class="fa-solid fa-code"></i>
        </button>
      </div>

      <div class="mm-editor-group">
        <div class="dropdown">
          <button type="button" class="mm-tool mm-tool-caret dropdown-toggle"
                  :title="$t('editor.toolbar.heading')" :aria-label="$t('editor.toolbar.heading')"
                  :disabled="disabled" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa-solid fa-heading"></i>
          </button>
          <ul class="dropdown-menu">
            <li v-for="level in headingLevels" :key="level">
              <button type="button" class="dropdown-item" @click="heading(level)">
                {{ $t('editor.toolbar.heading_level', { marks: '#'.repeat(level), level }) }}
              </button>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <button type="button" class="dropdown-item" @click="heading(0)">{{ $t('editor.toolbar.normal_text') }}</button>
            </li>
          </ul>
        </div>
      </div>

      <div class="mm-editor-group d-none d-sm-flex">
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.bullet_list')" :aria-label="$t('editor.toolbar.bullet_list')"
                :disabled="disabled" @mousedown.prevent @click="list('bullet')">
          <i class="fa-solid fa-list-ul"></i>
        </button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.numbered_list')" :aria-label="$t('editor.toolbar.numbered_list')"
                :disabled="disabled" @mousedown.prevent @click="list('ordered')">
          <i class="fa-solid fa-list-ol"></i>
        </button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.task_list')" :aria-label="$t('editor.toolbar.task_list')"
                :disabled="disabled" @mousedown.prevent @click="list('task')">
          <i class="fa-solid fa-list-check"></i>
        </button>
      </div>

      <div class="mm-editor-group d-none d-md-flex">
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.link')" :aria-label="$t('editor.toolbar.link')"
                :disabled="disabled" @mousedown.prevent @click="link()">
          <i class="fa-solid fa-link"></i>
        </button>
        <button type="button" class="mm-tool mm-tool-wiki" :title="$t('editor.toolbar.note_link')"
                :aria-label="$t('editor.toolbar.note_link')"
                :disabled="disabled" @mousedown.prevent @click="wikiLink()">[[ ]]</button>
        <button type="button" class="mm-tool" :title="$t('editor.toolbar.table')" :aria-label="$t('editor.toolbar.table')"
                :disabled="disabled" @mousedown.prevent @click="table()">
          <i class="fa-solid fa-table"></i>
        </button>
      </div>

      <!-- What does not fit a narrow screen, rather than a second row that
           pushes the text down or a strip nobody thinks to swipe. -->
      <div class="mm-editor-group d-md-none ms-auto">
        <div class="dropdown">
          <button type="button" class="mm-tool" :title="$t('editor.toolbar.more')" :aria-label="$t('editor.toolbar.more_formatting')"
                  :disabled="disabled" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa-solid fa-ellipsis-vertical"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="d-sm-none">
              <button type="button" class="dropdown-item" @click="list('bullet')">
                <i class="fa-solid fa-list-ul fa-fw me-2"></i>{{ $t('editor.toolbar.bullet_list') }}
              </button>
            </li>
            <li class="d-sm-none">
              <button type="button" class="dropdown-item" @click="list('ordered')">
                <i class="fa-solid fa-list-ol fa-fw me-2"></i>{{ $t('editor.toolbar.numbered_list') }}
              </button>
            </li>
            <li class="d-sm-none">
              <button type="button" class="dropdown-item" @click="list('task')">
                <i class="fa-solid fa-list-check fa-fw me-2"></i>{{ $t('editor.toolbar.task_list') }}
              </button>
            </li>
            <li class="d-sm-none"><hr class="dropdown-divider"></li>
            <li>
              <button type="button" class="dropdown-item" @click="link()">
                <i class="fa-solid fa-link fa-fw me-2"></i>{{ $t('editor.toolbar.link') }}
              </button>
            </li>
            <li>
              <button type="button" class="dropdown-item" @click="wikiLink()">
                <i class="fa-solid fa-diagram-project fa-fw me-2"></i>{{ $t('editor.toolbar.note_link') }}
              </button>
            </li>
            <li>
              <button type="button" class="dropdown-item" @click="table()">
                <i class="fa-solid fa-table fa-fw me-2"></i>{{ $t('editor.toolbar.table') }}
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- The writing desk puts its Write/Preview toggle at the right edge of
           this rail rather than above it, so the rail stays the one instrument
           panel attached to the sheet. -->
      <slot name="rail-end" />
    </div>
    <div class="mm-cm" :class="{ 'mm-cm-disabled': disabled }" ref="host"></div>
  </div>
</template>
