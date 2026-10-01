import { resolvePatch } from '@/lib/diffs'

/** One field of the resulting document, and what it replaces. */
export interface DocField<T> {
  before: T
  after: T
}

export interface ProposedDoc {
  heading: string
  title: string
  /** The title this replaces, or null when the proposal does not change it. */
  titleBefore: string | null
  noteId: number | null
  body: DocField<string>
  tags: DocField<string[]>
  summary: DocField<string>
  /** A merge's absorbed note: named and readable, never editable. */
  destroyed: { title: string; id: number | null; body: string } | null
  /** An anchored edit the server will refuse, and why. The button is disabled
   *  either way; this is what says so on the card. */
  refusal: 'stale' | 'empties' | null
}

/** The subset of a proposal or applied-proposal row this needs. */
export interface DocSource {
  type: 'edit' | 'delete' | 'merge' | 'create' | 'report'
  note: { id: number; title: string } | null
  merge_into: { id: number; title: string } | null
  proposed_title: string | null
  proposed_body_md: string | null
  proposed_patch: { find: string; replace: string }[] | null
  proposed_tags: string[] | null
  proposed_summary: string | null
  currentBody?: string
  currentSummary?: string | null
  currentTags?: string[]
  keeperBody?: string
  keeperTags?: string[]
  keeperSummary?: string | null
}

/**
 * What an edit's body resolves to.
 *
 * A patch is a way of WRITING an edit, not a different thing to review, so it
 * is resolved to the body it produces. One the server would refuse — a moved
 * anchor — has no resulting body to show, and falls back to the note as it
 * stands so the field is not silently blank; the card says separately that it
 * cannot be applied.
 */
function editedBody(source: DocSource): { body: string; refusal: 'stale' | 'empties' | null } {
  const current = source.currentBody ?? ''
  if (source.proposed_patch?.length) {
    const resolved = resolvePatch(current, source.proposed_patch)
    if (resolved.ok) return { body: resolved.body, refusal: null }

    return resolved.reason === 'empties'
      ? { body: '', refusal: 'empties' }
      : { body: current, refusal: 'stale' }
  }

  return { body: source.proposed_body_md ?? current, refusal: null }
}

/**
 * The document a proposal leaves behind.
 *
 * Every kind answers the same four questions — what it is called, what it
 * says, how it is filed and how it is described — so the card can draw one
 * shape and the reader learns it once. What differs is only which side of each
 * field is empty: a create has no before, a delete has no after, and a merge's
 * fields belong to the note that survives rather than the one being consumed.
 */
export function proposedDoc(source: DocSource, t: (key: string) => string): ProposedDoc {
  if (source.type === 'create') {
    return {
      heading: t('inbox.doc.heading_new'),
      title: source.proposed_title ?? '',
      titleBefore: null,
      noteId: null,
      body: { before: '', after: source.proposed_body_md ?? '' },
      tags: { before: [], after: source.proposed_tags ?? [] },
      summary: { before: '', after: source.proposed_summary ?? '' },
      destroyed: null,
      refusal: null,
    }
  }

  if (source.type === 'delete') {
    return {
      heading: t('inbox.doc.heading_delete'),
      title: source.note?.title ?? '',
      titleBefore: null,
      noteId: source.note?.id ?? null,
      body: { before: source.currentBody ?? '', after: '' },
      tags: { before: source.currentTags ?? [], after: [] },
      summary: { before: source.currentSummary ?? '', after: '' },
      destroyed: null,
      refusal: null,
    }
  }

  if (source.type === 'merge') {
    const keeperTags = source.keeperTags ?? []
    const absorbed = source.currentTags ?? []
    const keeperSummary = source.keeperSummary ?? ''

    return {
      heading: t('inbox.doc.heading_merge'),
      title: source.merge_into?.title ?? '',
      titleBefore: null,
      noteId: source.merge_into?.id ?? null,
      body: { before: source.keeperBody ?? '', after: source.proposed_body_md ?? source.keeperBody ?? '' },
      // The union the merge performs, shown as the additions it makes rather
      // than asserted in a sentence beside the tags.
      tags: { before: keeperTags, after: [...keeperTags, ...absorbed.filter((tag) => !keeperTags.includes(tag))] },
      summary: { before: keeperSummary, after: keeperSummary },
      destroyed: source.note === null
        ? null
        : { title: source.note.title, id: source.note.id, body: source.currentBody ?? '' },
      refusal: null,
    }
  }

  // A report proposes nothing, so every field is its own before: the document
  // as it stands is the evidence, against what the reporter says about it.
  if (source.type === 'report') {
    const body = source.currentBody ?? ''
    const tags = source.currentTags ?? []
    const summary = source.currentSummary ?? ''

    return {
      heading: t('inbox.doc.heading_report'),
      title: source.note?.title ?? '',
      titleBefore: null,
      noteId: source.note?.id ?? null,
      body: { before: body, after: body },
      tags: { before: tags, after: tags },
      summary: { before: summary, after: summary },
      destroyed: null,
      refusal: null,
    }
  }

  const currentTitle = source.note?.title ?? ''
  const currentSummary = source.currentSummary ?? ''
  const edited = editedBody(source)

  return {
    heading: t('inbox.doc.heading_edit'),
    title: source.proposed_title ?? currentTitle,
    titleBefore: source.proposed_title === null ? null : currentTitle,
    noteId: source.note?.id ?? null,
    body: { before: source.currentBody ?? '', after: edited.body },
    tags: { before: source.currentTags ?? [], after: source.proposed_tags ?? source.currentTags ?? [] },
    summary: { before: currentSummary, after: source.proposed_summary ?? currentSummary },
    destroyed: null,
    refusal: edited.refusal,
  }
}
