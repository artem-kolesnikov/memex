import {
  EditorSelection,
  type ChangeSpec,
  type EditorState,
  type Line,
  type TransactionSpec,
} from '@codemirror/state'

export type ListKind = 'bullet' | 'ordered' | 'task'

const LIST_PREFIX = /^(\s*)(?:[-*+] \[[ xX]\] |[-*+] |\d+[.)] )/
const HEADING_PREFIX = /^(\s*)#{1,6} ?/
const INDENT = /^\s*/

/**
 * The lines a command works on.
 *
 * A selection that ends at column 0 stops at the line above it: dragging to the
 * start of the next line is how a whole line gets selected, and treating that
 * as two lines bullets one the user never touched.
 */
function selectedLines(state: EditorState): Line[] {
  const lines: Line[] = []
  const seen = new Set<number>()
  for (const range of state.selection.ranges) {
    const first = state.doc.lineAt(range.from)
    let last = state.doc.lineAt(range.to)
    if (range.to > range.from && range.to === last.from && last.number > first.number) {
      last = state.doc.line(last.number - 1)
    }
    for (let n = first.number; n <= last.number; n++) {
      if (seen.has(n)) continue
      seen.add(n)
      lines.push(state.doc.line(n))
    }
  }
  return lines.sort((a, b) => a.from - b.from)
}

function listKindOf(text: string): ListKind | null {
  const body = text.replace(INDENT, '')
  if (/^[-*+] \[[ xX]\] /.test(body)) return 'task'
  if (/^[-*+] /.test(body)) return 'bullet'
  if (/^\d+[.)] /.test(body)) return 'ordered'
  return null
}

function listMarker(kind: ListKind, ordinal: number): string {
  if (kind === 'bullet') return '- '
  if (kind === 'task') return '- [ ] '
  return `${ordinal}. `
}

export function wrapSpec(state: EditorState, mark: string): TransactionSpec {
  const len = mark.length
  const doc = state.doc
  return state.changeByRange((range) => {
    const { from, to } = range

    // With nothing selected there is nothing to unwrap: pressing bold means
    // "start bold here". Unwrapping on a bare cursor is what turned a caret
    // between the two stars of **bold** into `bold**`.
    if (from === to) {
      return {
        changes: [
          { from, insert: mark },
          { from, insert: mark },
        ],
        range: EditorSelection.cursor(from + len),
      }
    }

    const before = doc.sliceString(Math.max(0, from - len), from)
    const after = doc.sliceString(to, Math.min(doc.length, to + len))
    // A "*" next to ** is the bold marker, not an italic one. Unwrapping it
    // would demote bold to italic; wrapping gives ***both***, which is what
    // pressing italic on bold text is asking for.
    const strongerOutside = mark === '*' && doc.sliceString(Math.max(0, from - 2), from) === '**'

    if (before === mark && after === mark && !strongerOutside) {
      return {
        changes: [
          { from: from - len, to: from },
          { from: to, to: to + len },
        ],
        range: EditorSelection.range(from - len, to - len),
      }
    }

    const inner = doc.sliceString(from, to)
    const strongerInside = mark === '*' && inner.startsWith('**') && inner.endsWith('**')
    if (
      !strongerInside &&
      inner.length >= 2 * len &&
      inner.startsWith(mark) &&
      inner.endsWith(mark)
    ) {
      return {
        changes: [
          { from, to: from + len },
          { from: to - len, to },
        ],
        range: EditorSelection.range(from, to - 2 * len),
      }
    }

    return {
      changes: [
        { from, insert: mark },
        { from: to, insert: mark },
      ],
      range: EditorSelection.range(from + len, to + len),
    }
  })
}

export function headingSpec(state: EditorState, level: number): TransactionSpec {
  const changes: ChangeSpec[] = []
  for (const line of selectedLines(state)) {
    const heading = HEADING_PREFIX.exec(line.text)
    const indent = (heading ? heading[1] : INDENT.exec(line.text)![0]) ?? ''
    const bodyFrom = line.from + (heading ? heading[0].length : indent.length)
    const insert = level > 0 ? `${indent}${'#'.repeat(level)} ` : indent
    changes.push({ from: line.from, to: bodyFrom, insert })
  }
  return { changes }
}

export function listSpec(state: EditorState, kind: ListKind): TransactionSpec {
  const lines = selectedLines(state)
  const removing = lines.every((line) => listKindOf(line.text) === kind)
  const changes: ChangeSpec[] = []
  lines.forEach((line, i) => {
    const list = LIST_PREFIX.exec(line.text)
    const indent = (list ? list[1] : INDENT.exec(line.text)![0]) ?? ''
    const bodyFrom = line.from + (list ? list[0].length : indent.length)
    const insert = removing ? indent : indent + listMarker(kind, i + 1)
    changes.push({ from: line.from, to: bodyFrom, insert })
  })
  return { changes }
}

export function linkSpec(state: EditorState): TransactionSpec {
  return state.changeByRange((range) => {
    const text = state.sliceDoc(range.from, range.to)
    if (text) {
      const urlFrom = range.from + text.length + 3
      return {
        changes: { from: range.from, to: range.to, insert: `[${text}](url)` },
        range: EditorSelection.range(urlFrom, urlFrom + 3),
      }
    }
    return {
      changes: { from: range.from, insert: '[text](url)' },
      range: EditorSelection.range(range.from + 1, range.from + 5),
    }
  })
}

export function wikiLinkSpec(state: EditorState): TransactionSpec {
  return state.changeByRange((range) => {
    const text = state.sliceDoc(range.from, range.to)
    // No note title spans lines, so a multi-line selection is not a target:
    // leave the text alone and open an empty link after it.
    if (text.includes('\n')) {
      return {
        changes: { from: range.to, insert: '[[]]' },
        range: EditorSelection.cursor(range.to + 2),
      }
    }
    return {
      changes: { from: range.from, to: range.to, insert: `[[${text}]]` },
      range: EditorSelection.cursor(range.from + 2 + text.length),
    }
  })
}

/** One table at the main cursor: a table per cursor is not what several
 *  cursors ask for. */
export function tableSpec(state: EditorState): TransactionSpec {
  const line = state.doc.lineAt(state.selection.main.to)
  const table = '| Column | Column | Column |\n| --- | --- | --- |\n|  |  |  |'
  const lead = line.text.trim() === '' ? '' : '\n\n'
  const headFrom = line.to + lead.length + 2
  return {
    changes: { from: line.to, insert: lead + table + '\n' },
    selection: EditorSelection.range(headFrom, headFrom + 6),
  }
}
