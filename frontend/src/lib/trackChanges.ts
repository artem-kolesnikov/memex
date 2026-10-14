// Tracked changes inside the editor, as a word processor shows them: the text
// stays the new text and stays typed into, what it added is marked green, and
// what it removed sits in place, struck in red (operator, 2026-10-13). The
// changes are the ones the read-only cards show, from the same trackedChanges(),
// so a change reads the same whether it is being read or edited.
import {
  EditorState,
  StateEffect,
  StateField,
  type Extension,
  type Range,
  type TransactionSpec,
} from '@codemirror/state'
import { Decoration, EditorView, WidgetType, showTooltip, type DecorationSet, type Tooltip } from '@codemirror/view'
import { trackedChanges } from '@/lib/diffs'

/** One change: the added text at from..to in the document, and the text it removed, which stood at oldFrom..oldTo. */
export interface TrackedChange {
  from: number
  to: number
  removed: string
  oldFrom: number
  oldTo: number
}

export function changesBetween(original: string, text: string): TrackedChange[] {
  const changes: TrackedChange[] = []
  let oldPos = 0
  let newPos = 0
  let open: TrackedChange | null = null
  for (const piece of trackedChanges(original, text)) {
    const length = piece.text.length
    if (piece.kind === 'same') {
      if (open !== null) changes.push(open)
      open = null
      oldPos += length
      newPos += length
      continue
    }
    open ??= { from: newPos, to: newPos, removed: '', oldFrom: oldPos, oldTo: oldPos }
    if (piece.kind === 'removed') {
      open.removed += piece.text
      open.oldTo += length
      oldPos += length
    } else {
      open.to += length
      newPos += length
    }
  }
  if (open !== null) changes.push(open)

  return changes
}

interface Tracked {
  original: string
  changes: TrackedChange[]
}

const acceptedEffect = StateEffect.define<string>()

const tracked = StateField.define<Tracked>({
  create: () => ({ original: '', changes: [] }),
  update(value, tr) {
    const original = tr.effects.reduce((text, effect) => (effect.is(acceptedEffect) ? effect.value : text), value.original)
    if (!tr.docChanged && original === value.original) return value

    return { original, changes: changesBetween(original, tr.state.doc.toString()) }
  },
})

export function trackedChangesIn(state: EditorState): TrackedChange[] {
  return state.field(tracked, false)?.changes ?? []
}

/** The change the cursor is in or beside, if any. */
export function changeAt(state: EditorState, pos: number): TrackedChange | null {
  return trackedChangesIn(state).find((change) => pos >= change.from && pos <= change.to) ?? null
}

/** Keep the change: it stays in the text and is no longer marked. */
export function acceptSpec(state: EditorState, change: TrackedChange): TransactionSpec {
  const original = state.field(tracked).original
  const kept = state.sliceDoc(change.from, change.to)

  return { effects: acceptedEffect.of(original.slice(0, change.oldFrom) + kept + original.slice(change.oldTo)) }
}

/** Undo the change: the text it removed goes back in place of what it added. */
export function rejectSpec(state: EditorState, change: TrackedChange): TransactionSpec {
  return {
    changes: { from: change.from, to: change.to, insert: change.removed },
    selection: { anchor: change.from + state.toText(change.removed).length },
    userEvent: 'input.reject',
  }
}

class RemovedText extends WidgetType {
  readonly text: string

  constructor(text: string) {
    super()
    this.text = text
  }

  eq(other: RemovedText): boolean {
    return other.text === this.text
  }

  toDOM(): HTMLElement {
    const del = document.createElement('del')
    del.className = 'mm-tc-del'
    // A removed blank line has nothing to strike; it shows as a paragraph sign.
    del.textContent = this.text.trim() === '' && this.text.includes('\n') ? '¶' : this.text

    return del
  }

  ignoreEvent(): boolean {
    return false
  }
}

const addedMark = Decoration.mark({ class: 'mm-tc-ins' })
const changedLine = Decoration.line({ class: 'mm-tc-line' })

function decorationsOf(state: EditorState): DecorationSet {
  const ranges: Range<Decoration>[] = []
  const lines = new Set<number>()
  for (const change of trackedChangesIn(state)) {
    if (change.removed !== '') ranges.push(Decoration.widget({ widget: new RemovedText(change.removed), side: -1 }).range(change.from))
    if (change.to > change.from) ranges.push(addedMark.range(change.from, change.to))
    const first = state.doc.lineAt(change.from).number
    const last = state.doc.lineAt(change.to).number
    for (let line = first; line <= last; line++) lines.add(line)
  }
  for (const line of lines) ranges.push(changedLine.range(state.doc.line(line).from))

  return Decoration.set(ranges, true)
}

export interface TrackChangesLabels {
  accept: string
  reject: string
}

function controlsTooltip(state: EditorState, labels: TrackChangesLabels): Tooltip | null {
  if (state.readOnly || !state.selection.main.empty) return null
  const change = changeAt(state, state.selection.main.head)
  if (change === null) return null

  return {
    pos: change.from,
    above: true,
    create(view) {
      const dom = document.createElement('div')
      dom.className = 'mm-tc-controls'
      const button = (label: string, cls: string, spec: (state: EditorState, change: TrackedChange) => TransactionSpec) => {
        const el = document.createElement('button')
        el.type = 'button'
        el.className = cls
        el.textContent = label
        el.addEventListener('mousedown', (event) => event.preventDefault())
        el.addEventListener('click', () => {
          const current = changeAt(view.state, view.state.selection.main.head)
          if (current !== null) view.dispatch(spec(view.state, current))
          view.focus()
        })
        dom.append(el)
      }
      button(labels.accept, 'mm-tc-accept', acceptSpec)
      button(labels.reject, 'mm-tc-reject', rejectSpec)

      return { dom }
    },
  }
}

export function trackChanges(original: string, labels: TrackChangesLabels): Extension {
  return [
    // The editor holds its text with \n line breaks whatever the note was saved
    // with (a Markdown file from Windows keeps \r\n), so the original is read
    // the same way, or every line of such a note would count as changed.
    tracked.init((state) => {
      const base = state.toText(original).toString()

      return { original: base, changes: changesBetween(base, state.doc.toString()) }
    }),
    EditorView.decorations.compute([tracked], decorationsOf),
    showTooltip.compute([tracked, 'selection'], (state) => controlsTooltip(state, labels)),
  ]
}
