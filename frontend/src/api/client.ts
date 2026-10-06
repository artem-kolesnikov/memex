import router from '@/router'
import { i18n } from '@/i18n'
import { sessionEpoch, StaleOperationError } from '@/lib/operationLifetime'

export interface TagRef {
  id: number
  name: string
}

export interface SystemTag {
  name: string
  reason: string
}

export interface Actor {
  kind: 'assistant' | 'person' | 'memex'
  name: string
  initial: string | null
  icon: string | null
  icon_url: string | null
  icon_url_dark: string | null
}

export interface NoteListItem {
  id: number
  title: string
  source: string
  source_url: string | null
  status: string
  last_actor: string
  edited_by: Actor | null
  summary: string | null
  summary_by: string | null
  tags: TagRef[]
  created_at: string
  updated_at: string
  version?: number
  flagged?: boolean
}

/** What the profile screens read: the account's profile notes, live on every call. */
export interface WelcomeFacts {
  /** Every note carrying `user-profile`, oldest first; read live, never remembered. */
  profiles: ProfileRef[]
}

export interface ProfileRef {
  id: number
  title: string
  status: 'verified' | 'pending'
}

export type WritingAxis = 'scope' | 'opening' | 'format' | 'reasoning'
export type WritingAddOn = 'scope_short' | 'format_minimal' | 'reasoning_confidence' | 'reasoning_sources'

/** Settings › Personalization: the presets for memex-writing, closed vocabulary only. */
export interface PersonalizationSettings {
  /** memex-writing, or the owner's own writing skills only. */
  writing: boolean
  scope: 'one_subject' | 'one_idea' | 'whole_topic'
  opening: 'summary' | 'answer' | 'context'
  format: 'mixed' | 'prose' | 'bullets'
  reasoning: 'reasons' | 'bare' | 'rationale'
  scope_short: boolean
  format_minimal: boolean
  reasoning_confidence: boolean
  reasoning_sources: boolean
}

/** One row of the page: an axis, its choices and add-ons, each with the exact text it puts in the skill. */
export interface WritingOption {
  axis: WritingAxis
  choices: { value: string; text: string }[]
  add_ons: { key: WritingAddOn; text: string; conflicts_with: string[] }[]
}

export interface PersonalizationView {
  revision: string
  settings: PersonalizationSettings
  defaults: PersonalizationSettings
  options: WritingOption[]
  skill: {
    slug: string
    /** The whole skill as the presets make it, whether or not it is switched on. */
    text: string
    /** Connections that have loaded this text. Loaded is not followed. */
    loaded_by: string[]
  }
  /** What a connection is told about the profile at connect time, verbatim. */
  profile_paragraph: string
}

/** The setup banner's four facts. Keys are the server's; the words are ours. */
export interface CurationFlag {
  comment: string
  flagged_by: string
  flagged_at: string
  reworded_at: string | null
}

export interface NoteDetail extends NoteListItem {
  added_by: Actor | null
  added_by_token_id: number | null
  described_at: string | null
  embedded: boolean
  body_md: string
  pending_proposals: number
  links: { target: string; note_id: number | null; title: string | null }[]
  backlinks: { note_id: number; title: string }[]
  curation_flag: CurationFlag | null
}

export interface EditProposalItem {
  id: number
  revision: number
  type: 'edit' | 'delete' | 'merge' | 'report'
  note: { id: number; title: string; status: string; version: number }
  merge_into: { id: number; title: string; version: number } | null
  change_title: string | null
  proposed_title: string | null
  proposed_body_md: string | null
  proposed_patch: { find: string; replace: string }[] | null
  proposed_tags: string[] | null
  proposed_summary: string | null
  kinds: string[]
  comment: string | null
  proposed_by: string
  created_at: string
  /** When its author last folded a further edit into it; null if never. */
  revised_at: string | null
}

export interface SuggestedTags {
  tag_ids: number[]
  new_tags: string[]
}

export interface NoteWriteResponse {
  note: NoteDetail
  suggested_tags: SuggestedTags
}

export interface HintedNote {
  note_id: number
  title: string
  summary: string | null
  status: string
  distance: number
  similarity: number
  reading: string
}

export interface HintedLink {
  note_id: number | null
  title: string
  find: string
  replace: string
}

export interface WriteHints {
  duplicates?: HintedNote[]
  duplicates_total?: number
  links?: HintedLink[]
  tags?: string[]
}

export interface AiProvider {
  id: string
  label: string
  key_url: string
  key_steps: string
  key_prefix: string
  default_model: string | null
  kind: 'text' | 'fetch'
}

export interface AiKey {
  id: number
  name: string
  provider: string
  provider_label: string
  hint: string
  verified_at: string | null
  last_used_at: string | null
  created_at: string
  readable: boolean
}

export interface AiRole {
  enabled: boolean
  credential_id: number | null
  provider: string | null
  model: string | null
}

/** What this knowledge base may make the box buy. A section on the team's own
 *  key carries no ceiling at all, which is what `own_*_key` says. */
export interface SpendAllowance {
  tier: string
  embed_hourly: number
  embed_daily: number
  search_hourly: number
  search_daily: number
  analyze_hourly: number
  analyze_daily: number
  text_hourly: number
  text_daily: number
  own_embed_key: boolean
  own_text_key: boolean
  /** What is left of today on each surface. Null where no ceiling applies —
   *  the unlimited tier, or a section on the team's own key. */
  left_today: {
    embed: number | null
    analyze: number | null
    search: number | null
    text: number | null
  }
}

export interface AiSettings {
  enabled: boolean
  credential_id: number | null
  provider: string | null
  model: string | null
  embed_credential_id: number | null
  search: SearchModel
  /** The tier writes descriptions on memex's key when the account brings none. */
  included: boolean
  included_model: string | null
  /** Null when nothing is capped. */
  limits: SpendAllowance | null
  keys: AiKey[]
  providers: AiProvider[]
}

/** The vault's embedding model, those this server offers, and how far the notes are embedded. */
export interface SearchModel {
  model: string
  models: string[]
  /** False when the model is OpenAI's and there is no key to buy with. */
  ready: boolean
  embedded: number
  notes: number
}

export interface AiSectionPatch {
  embed_credential_id?: number
}

export interface AiRolePatch {
  enabled?: boolean
  credential_id?: number
  model?: string
}

export interface RetiredTag {
  name: string
  note_count: number
  merged_into: string | null
  retired_at: string
}

export interface TagVocabularyEntry extends TagRef {
  note_count: number
  system: boolean
  system_reason: string | null
}

export interface VaultStats {
  notes: { total: number; verified: number; pending: number; undescribed: number }
  limbo: { restorable: number }
  indexing: { embedded: number; waiting: number }
}

export interface AnalyzeResult {
  summary: string | null
  suggested_tags: SuggestedTags
  suggested_title: string | null
  ai_enabled: boolean
  allowance: AnalyzeAllowance
  hints: WriteHints
}

/** Whose key writes Analyze's summaries and titles; `left` counts clicks today. */
export interface AnalyzeAllowance {
  suggestions: 'own' | 'included' | 'none'
  model: string | null
  left: number | null
}

export interface LinkTarget {
  id: number
  title: string
  summary: string | null
  status: string
  tags: string[]
  backlinks: number
  updated_at: string
}

export interface SearchResult {
  items: NoteListItem[]
  total: number
  semantic_unavailable: boolean
  /** The query carried OR, NOT or a phrase: matched as written, never by meaning. */
  keyword_only: boolean
}

/** Per theme, and absent until somebody chooses. Vocabulary: App\Service\Appearance. */
export interface AppearancePrefs {
  accent?: { light?: string; dark?: string }
  surface?: { light?: string; dark?: string }
}

/**
 * The map's saved shape. Every key optional: what is missing is that control's
 * default, which lives in `MAP_DEFAULTS` so a first paint needs nothing from
 * the server. The words are pinned server-side by App\Service\MapSettings.
 */
export interface MapPrefs {
  view?: '2d' | '3d'
  nodeShape?: 'dot' | 'square'
  links?: 'curve' | 'line' | 'arrow'
  labels?: 'title' | 'id' | 'none'
}

/**
 * Every word a control accepts, first one first — which is also its default.
 * Pinned against App\Service\MapSettings by MapSettingsTest, which reads this
 * declaration rather than a copy of it.
 */
export const MAP_VOCABULARY = {
  view: ['2d', '3d'],
  nodeShape: ['dot', 'square'],
  links: ['curve', 'line', 'arrow'],
  labels: ['title', 'id', 'none'],
} as const

export const MAP_DEFAULTS: Required<MapPrefs> = {
  view: MAP_VOCABULARY.view[0],
  nodeShape: MAP_VOCABULARY.nodeShape[0],
  links: MAP_VOCABULARY.links[0],
  labels: MAP_VOCABULARY.labels[0],
}

/**
 * The account's map settings, filled in and checked.
 *
 * Spreading the payload straight over the defaults trusts it: `view: '4d'` is
 * not '2d', and a renderer chosen with `v-else` would answer that by drawing
 * the 3D one. A word this build does not know is the default instead.
 */
export function readMapPrefs(stored: MapPrefs | undefined): Required<MapPrefs> {
  const out = { ...MAP_DEFAULTS }
  for (const key of Object.keys(MAP_VOCABULARY) as (keyof MapPrefs)[]) {
    const value = stored?.[key]
    const words: readonly string[] = MAP_VOCABULARY[key]
    if (typeof value === 'string' && words.includes(value)) out[key] = value as never
  }

  return out
}

export interface Me {
  email: string
  name: string
  /** `handle` is the vault's segment in a note URL. */
  team: { name: string; handle: string }
  system_tags?: SystemTag[]
  appearance?: AppearancePrefs
  /** How this account wants the map drawn. Absent keys are the SPA's defaults. */
  map?: MapPrefs
  /** Interface language: `en`, or a code the operator installed. */
  locale?: string
  /** Whether ChatGPT, Claude and Gemini can reach this memex, which decides what Settings offers to connect. */
  web_assistants?: boolean
  /** `upload` when a picture is stored, null for the silhouette. */
  icon_key?: string | null
  /** That face as a byline renders it: the fallback glyph class. */
  icon?: string | null
  initial?: string | null
  /** The uploaded picture, when there is one. */
  icon_url?: string | null
  icon_url_dark?: string | null
}

/** A language the operator installed. English is bundled and never listed. */
export interface InstalledLocale {
  code: string
  keys: number
  updated_at: string
}

export interface SignInProvider {
  id: string
  label: string
  icon: string
}

export interface BrowserSession {
  id: string
  current: boolean
  browser: string
  ip: string | null
  created_at: string | null
  last_seen_at: string | null
  expires_at: string | null
}

export interface CurationCandidate {
  note_id: number
  title: string
  status: string
  reasons: string[]
  defects: number
  last_curated_at: string | null
  updated_at: string
  clean_passes?: number
  rest_days?: number
  changed_neighbours?: number
  neighbour_changed_at?: string | null
  operator_flag?: {
    comment: string
    flagged_by: string
    flagged_at: string
    reworded_at: string | null
  }
}

export interface CurationFlagRow {
  note_id: number
  title: string
  status: string
  comment: string
  flagged_by: string
  flagged_at: string
  reworded_at: string | null
  awaiting_review: boolean
}

export interface CurationBriefFields {
  notes_per_run: number
  work_first: string
  cooldown_days: number
  never_touch_tags: string[]
  boldness: string
  report_back: string[]
}

export interface CurationPreset {
  id: number
  name: string
  is_standard: boolean
  is_shipped: boolean
  version: number
  updated_at: string
  fields: CurationBriefFields
  brief_text: string
}

export interface CurationInstructions {
  canon: { title: string; description: string; body: string }
  presets: CurationPreset[]
  field_options: {
    work_first: string[]
    boldness: string[]
    report_back: string[]
    notes_per_run: { min: number; max: number }
    cooldown_days: { min: number; max: number }
  }
}

export interface CurationConnection {
  id: number
  name: string
  label: string
  icon: string | null
  icon_url: string | null
  icon_url_dark: string | null
  preset_id: number | null
  preset_name: string
  last_run_at: string | null
  charter_last_loaded_at: string | null
  charter_loads: number
}

/** What the notes list and the notes map both search by. */
export interface SearchCriteria {
  q?: string
  tags?: number[]
  source?: string
  status?: string
  flagged?: boolean
  undescribed?: boolean
  added_by?: number
}

function searchQuery(params: SearchCriteria): URLSearchParams {
  const query = new URLSearchParams()
  if (params.q) query.set('q', params.q)
  if (params.tags?.length) query.set('tags', params.tags.join(','))
  if (params.source) query.set('source', params.source)
  if (params.status) query.set('status', params.status)
  if (params.flagged) query.set('flagged', '1')
  if (params.undescribed) query.set('undescribed', '1')
  if (params.added_by) query.set('added_by', String(params.added_by))
  return query
}

export interface MatchingNotes {
  ids: number[]
  semantic_unavailable: boolean
  keyword_only: boolean
}

export interface GraphNode {
  id: number
  title: string
  status: string
  /** The collection map's rail only. Null on a note's own neighbourhood. */
  summary: string | null
  tags: string[]
  updated_at: string | null
  /** Links away from the centre note, or null for a note only meaning puts nearby. */
  hop: number | null
  defects: string[]
  flagged: boolean
}

export interface GraphEdge {
  from: number
  to: number
  kind: 'link' | 'semantic'
  distance?: number
}

export interface NoteGraphData {
  center: number | null
  depth: number | null
  nodes: GraphNode[]
  edges: GraphEdge[]
  truncated: boolean
  total_notes: number
}

export interface BlastRadiusNote {
  note_id: number
  title: string
  status: string
  changed_neighbours: number
  last_curated_at: string | null
  updated_at: string
  changed: { note_id: number; title: string; last_actor: string; updated_at: string }[]
}

export interface BlastRadiusData {
  total: number
  offset: number
  since_days: number
  notes: BlastRadiusNote[]
}

export interface CurationAttention {
  last_run_at: string | null
  last_run_by: string | null
  notes_changed_since: number
  queue_total: number
  never_read: number
  defect_free: number
  reason_counts: Record<string, number>
  due: boolean
  due_reasons: string[]
  candidates: CurationCandidate[]
  open_flags: CurationFlagRow[]
  open_flags_total: number
  queue_shown: number
}

/** A saved notes-list filter. Tags come back resolved, so a rename follows the preset. */
export interface SearchPreset {
  id: number
  name: string
  icon: string
  icon_class: string
  q: string
  tags: TagRef[]
  status: string
  added_by: number | null
  updated_at: string
}

export interface SearchPresetInput {
  name: string
  icon: string
  q: string
  tags: number[]
  status: string
  added_by: number | null
}

export interface TokenInfo {
  id: number
  name: string
  label: string
  auth: 'oauth' | 'manual'
  role: 'agent' | 'curator'
  display_name: string | null
  description: string | null
  icon_key: string | null
  icon: string | null
  icon_url: string | null
  icon_url_dark: string | null
  created_at: string
  last_used_at: string | null
  revoked: boolean
  /** memex holds the token, so Settings can copy it again. */
  token_kept: boolean
}

export interface IconChoice {
  key: string
  icon: string
  label: string
  group: string
}

export interface LogoChoice {
  key: string
  label: string
  light_url: string
  dark_url: string
}

export interface NoteRevision {
  id: number
  title: string
  body_md: string | null
  summary: string | null
  tags: string[]
  replaced_by: string
  operation: string | null
  replaced_by_actor: Actor | null
  change_title: string | null
  amended_by_operator: boolean
  replaced_at: string
  content_updated_at: string
}

export interface DeletedNote {
  note_id: number
  title: string
  summary: string | null
  status: string
  source: string
  source_url: string | null
  import_path: string | null
  tags: string[]
  deleted_at: string
  deleted_by: string
  deleted_reason: string | null
  purge_after: string
  purged_at: string | null
  restorable: boolean
  days_left: number
}

export interface OperatorVerdict {
  comment?: string
  is_precedent?: boolean
}

/** The operator's own version of a pending note, sent with the approval. */
export interface NoteApprovalEdits {
  title?: string
  body_md?: string
  tags?: string[]
  summary?: string
}

export interface NoteApprovalSnapshot {
  expected_version: number
}

export interface ProposalApprovalSnapshot extends NoteApprovalSnapshot {
  expected_revision: number
  expected_merge_version?: number
}

export type InboxApprovalItem =
  | ({ kind: 'note'; id: number } & NoteApprovalSnapshot)
  | ({ kind: 'proposal'; id: number } & ProposalApprovalSnapshot)

export type InboxBatchRequest = { comment?: string } & (
  | { action: 'approve'; items: InboxApprovalItem[] }
  | { action: 'reject'; items: { kind: 'proposal' | 'note'; id: number }[] }
)

export function noteApprovalSnapshot(version: number | undefined): NoteApprovalSnapshot {
  if (version === undefined || !Number.isSafeInteger(version) || version < 1) {
    throw new Error('The review version is unavailable. Refresh and review this item before approving.')
  }
  return { expected_version: version }
}

export function proposalApprovalSnapshot(proposal: EditProposalItem): ProposalApprovalSnapshot {
  if (!Number.isSafeInteger(proposal.revision) || proposal.revision < 0) {
    throw new Error('The proposal revision is unavailable. Refresh and review this item before approving.')
  }
  return {
    ...noteApprovalSnapshot(proposal.note.version),
    expected_revision: proposal.revision,
    ...(proposal.type === 'merge'
      ? { expected_merge_version: noteApprovalSnapshot(proposal.merge_into?.version).expected_version }
      : {}),
  }
}

export interface CuratorLogRow {
  id: number
  created_at: string
  by: string
  /** Who wrote it: `human` (the owner), `agent`, `curator`, or `memex`. */
  actor: string
  action: string
  description: string
  operator_comment: string | null
  is_precedent: boolean
  note: { id: number; title: string } | null
  note_title: string | null
  affected: { id: number; title: string }[]
  affected_total: number
  curation_run: number | null
  diff: {
    type: 'edit' | 'create'
  change_title: string | null
  proposed_title: string | null
    proposed_body_md: string | null
    proposed_patch: { find: string; replace: string }[] | null
    proposed_tags: string[] | null
    proposed_summary: string | null
    prev_summary: string | null
    prev_title: string | null
    prev_body_md: string | null
    prev_tags: string[] | null
  } | null
}

export interface ActivityFilterOptions {
  actions: { value: string; count: number }[]
  writers: { value: string; label: string; count: number }[]
}

export interface ActivityQuery {
  action?: string
  writer?: string
  q?: string
  run?: number
  page?: number
  perPage?: number
  curationOnly?: boolean
  /** Calendar days, YYYY-MM-DD, both inclusive. */
  from?: string
  to?: string
}

export interface CurationRun {
  log_id: number
  at: string
  by: string
  writer: string
  window_from: string | null
  window_bounded: boolean
  description: string
  counts: {
    examined: number
    created: number
    edited: number
    held: number
    proposed: number
    flags_resolved: number
    observations: number
    tooling_gaps: number
  }
  claims: Record<string, number> | null
  mismatches: { claim: string; claimed: number; logged: number }[]
  still_defective: { note_id: number; title: string; reasons: string[] }[]
  still_defective_total: number
  waiting: { log_id: number; kind: 'delete' | 'merge'; note_id: number; title: string }[]
  waiting_total: number
  written: { note_id: number | null; title: string; kind: 'created' | 'edited'; rows: number }[]
  written_total: number
  examined: { note_id: number | null; title: string }[]
}

export interface ActivityRunScope {
  log_id: number
  by: string
  at: string
  window_from: string | null
  window_bounded: boolean
}

/** The journal's filters, shared by the page it lists and the file it exports. */
function activityParams(query: ActivityQuery): URLSearchParams {
  const params = new URLSearchParams()
  if (query.action) params.set('action', query.action)
  if (query.writer) params.set('writer', query.writer)
  if (query.q) params.set('q', query.q)
  if (query.run) params.set('run', String(query.run))
  if (query.curationOnly) params.set('curation', '1')
  if (query.from) params.set('from', query.from)
  if (query.to) params.set('to', query.to)
  if (query.page && query.page > 1) params.set('page', String(query.page))
  if (query.perPage) params.set('per_page', String(query.perPage))

  return params
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    /** The decoded error body. A 409 says which of the two conflicts it is. */
    public data: Record<string, unknown> = {},
  ) {
    super(message)
  }
}

/** A suspended account is sent to sign-in, which says why. */
function showSuspension(status: number, data: Record<string, unknown>) {
  if (status === 403 && data.code === 'suspended' && router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login', query: { error: 'suspended' } })
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const epoch = sessionEpoch.value
  const assertCurrent = () => { if (epoch !== sessionEpoch.value) throw new StaleOperationError(i18n.global.t('common.session_changed')) }
  const res = await fetch(path, {
    credentials: 'same-origin',
    headers: init.body instanceof FormData ? {} : { 'Content-Type': 'application/json' },
    ...init,
  }).catch(error => { assertCurrent(); throw error })
  assertCurrent()
  if (path !== '/api/logout' && res.status === 401 && router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
    throw new ApiError(401, 'Authentication required')
  }
  if (!res.ok) {
    let message = `HTTP ${res.status}`
    let data: Record<string, unknown> = {}
    try {
      data = await res.json()
      message = (data.error as string) ?? (data.message as string) ?? message
    } catch {
    }
    assertCurrent()
    showSuspension(res.status, data)
    throw new ApiError(res.status, message, data)
  }
  const data = await res.json() as T
  assertCurrent()
  return data
}

function verdictPost(verdict?: OperatorVerdict, extra: Record<string, unknown> = {}): RequestInit {
  const comment = verdict?.comment?.trim()
  const fields = { ...extra, ...(comment ? { comment, is_precedent: verdict?.is_precedent === true } : {}) }
  if (Object.keys(fields).length === 0) return { method: 'POST' }

  return { method: 'POST', body: JSON.stringify(fields) }
}

export type SkillStatus = 'served' | 'paused' | 'pending' | 'built_in' | 'switched_off' | 'offered'
export interface SkillUsage { total_30d: number; last_at: string | null; last_token_id: number | null; by_token: { token_id: number; count: number; last_at: string }[] }
export interface SkillLintFinding { code: string; message: string }
export interface SkillRow {
  kind: 'note' | 'shipped' | 'catalogue'; status: SkillStatus
  slug: string; title: string; description: string; short: string | null; body: string
  updated_at: string | null; note_id: number | null
  enabled: boolean; auto: boolean; command: boolean; grants: number[]
  usage: SkillUsage; lint: SkillLintFinding[]; size_tokens: number
}
export interface SkillConnection {
  id: number; name: string; label: string; display_name: string | null; role: 'agent' | 'curator'
  icon_key: string | null; icon: string | null; icon_url: string | null; icon_url_dark: string | null
}
export interface SkillPatch { enabled?: boolean; auto?: boolean; command?: boolean; slug?: string; grants?: number[] }

export const api = {
  logout: () => request<unknown>('/api/logout', { method: 'POST' }),
  me: () => request<Me>('/api/me'),
  updateName: (name: string) =>
    request<Me>('/api/me', { method: 'PATCH', body: JSON.stringify({ name }) }),
  clearPicture: () => request<Me>('/api/me/icon', { method: 'DELETE' }),
  uploadPicture: (file: File) => {
    const form = new FormData()
    form.append('icon', file)

    return request<Me>('/api/me/icon', { method: 'POST', body: form })
  },
  updateMemexName: (name: string) =>
    request<Me>('/api/me/memex', { method: 'PATCH', body: JSON.stringify({ name }) }),
  // Partial: send only the theme that changed; null clears one.
  updateAppearance: (patch: Record<string, Record<string, string | null>>) =>
    request<{ appearance: AppearancePrefs }>('/api/me/appearance', {
      method: 'PATCH',
      body: JSON.stringify(patch),
    }),
  // Partial, like the appearance patch: one control at a time, null resets one.
  updateMapSettings: (patch: MapPrefs | Record<string, null>) =>
    request<{ map: MapPrefs }>('/api/me/map', { method: 'PATCH', body: JSON.stringify(patch) }),
  updateLocale: (locale: string | null) =>
    request<Me>('/api/me/locale', { method: 'PATCH', body: JSON.stringify({ locale }) }),
  locales: () => request<{ locales: InstalledLocale[] }>('/api/locales'),
  health: () => request<{ status: string; version: string }>('/api/health'),
  deleteAccount: (confirmEmail: string) =>
    request<{ deleted: boolean }>('/api/me', {
      method: 'DELETE',
      body: JSON.stringify({ confirm_email: confirmEmail }),
    }),

  signInProviders: () =>
    request<{ providers: SignInProvider[] }>('/api/auth/providers'),
  signInMethods: () =>
    request<{
      identities: {
        id: string
        provider: string
        label: string
        icon: string
        email: string | null
        linked_at: string
        last_used_at: string | null
      }[]
      available: SignInProvider[]
      own_way_in: boolean
    }>('/api/me/identities'),
  removeSignInMethod: (id: string) =>
    request<{ removed: boolean }>(`/api/me/identities/${id}`, { method: 'DELETE' }),
  welcome: () => request<{ facts: WelcomeFacts }>('/api/me/welcome'),
  sessions: () => request<{ sessions: BrowserSession[] }>('/api/me/sessions'),
  endSession: (id: string) =>
    request<{ ended: number }>(`/api/me/sessions/${id}`, { method: 'DELETE' }),
  endOtherSessions: () =>
    request<{ ended: number }>('/api/me/sessions/end-others', { method: 'POST' }),

  signInUrl: (provider: string, params: { invite?: string; link?: boolean; next?: string } = {}) => {
    const query = new URLSearchParams()
    if (params.invite) query.set('invite', params.invite)
    if (params.link) query.set('link', '1')
    if (params.next) query.set('next', params.next)
    const suffix = query.toString()
    return `/api/auth/${provider}/start${suffix ? `?${suffix}` : ''}`
  },

  searchNotes: (params: SearchCriteria & { page?: number; per_page?: number }) => {
    const query = searchQuery(params)
    if (params.page) query.set('page', String(params.page))
    if (params.per_page) query.set('per_page', String(params.per_page))
    return request<SearchResult>(`/api/notes?${query}`)
  },
  /** Every note the same search matches, unpaged: what the notes map lights. */
  matchingNoteIds: (params: SearchCriteria) =>
    request<MatchingNotes>(`/api/notes/ids?${searchQuery(params)}`),
  getNote: (id: number) => request<NoteDetail>(`/api/notes/${id}`),
  noteTitles: () => request<{ titles: { id: number; title: string }[] }>('/api/notes/titles'),
  linkTargets: (ids: number[]) =>
    request<{ targets: LinkTarget[] }>(`/api/notes/link-targets?ids=${ids.join(',')}`),
  inboxCount: () =>
    request<{ pending_notes: number; edit_proposals: number; total: number }>(
      '/api/inbox/count',
    ),
  createNote: (data: { title: string; body_md: string; tags?: string[]; source_url?: string; summary?: string }) =>
    request<NoteWriteResponse>('/api/notes', { method: 'POST', body: JSON.stringify(data) }),
  updateNote: (
    id: number,
    data: {
      title?: string
      body_md?: string
      tags?: string[]
      summary?: string
      expected_version?: number
      discard_proposals?: boolean
    },
  ) =>
    request<NoteWriteResponse>(`/api/notes/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  deleteNote: (id: number, body: { discard_proposals?: boolean } = {}) =>
    request<unknown>(`/api/notes/${id}`, { method: 'DELETE', body: JSON.stringify(body) }),
  approveNote: (id: number, snapshot: NoteApprovalSnapshot, verdict?: OperatorVerdict, edits?: NoteApprovalEdits) =>
    request<{ note: NoteListItem }>(`/api/notes/${id}/approve`, verdictPost(verdict, { ...edits, ...snapshot })),
  rejectNote: (id: number, verdict?: OperatorVerdict) =>
    request<unknown>(`/api/notes/${id}/reject`, verdictPost(verdict)),

  flagNote: (id: number, comment: string) =>
    request<{ curation_flag: CurationFlag }>(`/api/notes/${id}/flag`, {
      method: 'POST',
      body: JSON.stringify({ comment }),
    }),
  unflagNote: (id: number) =>
    request<{ withdrawn: boolean }>(`/api/notes/${id}/flag`, { method: 'DELETE' }),

  proposals: () => request<{ proposals: EditProposalItem[] }>('/api/proposals'),
  getProposal: (id: number) =>
    request<
      EditProposalItem & {
        note: {
          id: number
          title: string
          status: string
          version: number
          body_md: string
          summary: string | null
          tags: string[]
          backlinks?: { id: number; title: string }[]
        }
        merge_into: { id: number; title: string; version: number; body_md?: string; tags?: string[]; summary?: string | null } | null
      }
    >(`/api/proposals/${id}`),
  /** `bodyMd` is the operator's own version of an edit, applied instead of the proposed one. */
  approveProposal: (id: number, snapshot: ProposalApprovalSnapshot, verdict?: OperatorVerdict, edits?: NoteApprovalEdits) =>
    request<{ note: NoteWriteResponse['note'] | null; suggested_tags: SuggestedTags }>(
      `/api/proposals/${id}/approve`,
      verdictPost(verdict, { ...edits, ...snapshot }),
    ),

  rejectProposal: (id: number, verdict?: OperatorVerdict) =>
    request<unknown>(`/api/proposals/${id}/reject`, verdictPost(verdict)),

  analyzeAllowance: () => request<AnalyzeAllowance>('/api/analyze'),
  analyze: (title: string, body_md: string, note_id?: number | null, tags?: string[]) =>
    request<AnalyzeResult>('/api/analyze', {
      method: 'POST',
      body: JSON.stringify({ title, body_md, note_id, tags }),
    }),
  upload: (files: File[]) => {
    const form = new FormData()
    files.forEach((f, i) => form.append(`files[${i}]`, f))
    return request<{ created: NoteListItem[]; errors: { file: string; error: string }[] }>('/api/upload', {
      method: 'POST',
      body: form,
    })
  },

  tags: () =>
    request<{ tags: TagVocabularyEntry[]; retired: RetiredTag[] }>('/api/tags'),

  presets: () => request<{ presets: SearchPreset[]; icons: IconChoice[] }>('/api/presets'),
  createPreset: (body: SearchPresetInput) =>
    request<{ preset: SearchPreset }>('/api/presets', { method: 'POST', body: JSON.stringify(body) }),
  updatePreset: (id: number, body: SearchPresetInput) =>
    request<{ preset: SearchPreset }>(`/api/presets/${id}`, { method: 'PATCH', body: JSON.stringify(body) }),
  removePreset: (id: number) => request<{ removed: number }>(`/api/presets/${id}`, { method: 'DELETE' }),

  removeTag: (id: number, mergeInto?: number) =>
    request<{ removed: string; merged_into?: string; notes_changed: number }>(
      `/api/tags/${id}${mergeInto ? `?merge_into=${mergeInto}` : ''}`,
      { method: 'DELETE' },
    ),

  restoreTag: (name: string) =>
    request<{ restored: string }>(`/api/tags/retired/${encodeURIComponent(name)}`, {
      method: 'DELETE',
    }),

  importVault: (
    archive: File,
    options: {
      dryRun: boolean
      skipDuplicates: boolean
      folderTags: boolean
      tags: string
      resurrectRetired?: boolean
    },
  ) => {
    const form = new FormData()
    form.append('archive', archive)
    form.append('dry_run', options.dryRun ? '1' : '0')
    form.append('skip_duplicates', options.skipDuplicates ? '1' : '0')
    form.append('folder_tags', options.folderTags ? '1' : '0')
    form.append('tags', options.tags)
    form.append('resurrect_retired', options.resurrectRetired ? '1' : '0')
    return request<{
      dry_run?: boolean
      importable?: number
      titles?: string[]
      created?: number
      notes?: { id: number; title: string }[]
      duplicates?: string[]
      duplicates_skipped?: string[]
      previously_retired?: {
        title: string
        note_id: number
        deleted_at: string
        reason: string | null
        restorable: boolean
      }[]
      previously_retired_held?: string[]
      resurrected_retired?: boolean
      skipped: { file: string; reason: string }[]
      links_resolved?: number
      note?: string
    }>('/api/import', { method: 'POST', body: form })
  },

  noteGraph: (id: number, depth = 1) =>
    request<NoteGraphData>(`/api/notes/${id}/graph?depth=${depth}`),

  graphMap: (semantic = false) =>
    request<NoteGraphData>(`/api/graph${semantic ? '?semantic=1' : ''}`),

  graphBlast: (sinceDays: number) =>
    request<BlastRadiusData>(`/api/graph/blast?since_days=${sinceDays}`),

  curationAttention: () => request<CurationAttention>('/api/curation/attention'),

  curatorLog: (query: ActivityQuery = {}) =>
    request<{
      entries: CuratorLogRow[]
      filters: ActivityFilterOptions
      run: ActivityRunScope | null
      total: number
      page: number
      pages: number
      per_page: number
    }>(`/api/curator-log?${activityParams(query).toString()}`),

  /** How many rows a set of filters holds, without the rows or the filter menus. */
  curatorLogCount: (query: ActivityQuery = {}) => {
    const params = activityParams(query)
    params.delete('page')
    params.delete('per_page')
    params.set('count_only', '1')

    return request<{ total: number }>(`/api/curator-log?${params.toString()}`)
  },

  /** The journal as a file. A link rather than a fetch: the browser saves it. */
  curatorLogExportUrl: (query: ActivityQuery, format: 'md' | 'csv') => {
    const params = activityParams(query)
    params.delete('page')
    params.delete('per_page')
    params.set('format', format)

    return `/api/curator-log/export?${params.toString()}`
  },

  curationDigest: (limit = 10) =>
    request<{ runs: CurationRun[]; runs_total: number }>(`/api/curation/digest?limit=${limit}`),

  curationInstructions: () => request<CurationInstructions>('/api/curation/instructions'),

  personalization: () => request<PersonalizationView>('/api/personalization'),

  updatePersonalization: (patch: { [K in keyof PersonalizationSettings]?: PersonalizationSettings[K] | null } & { expected_revision?: string }) =>
    request<PersonalizationView>('/api/personalization', { method: 'PATCH', body: JSON.stringify(patch) }),

  curationSavePreset: (id: number, body: { name?: string; fields?: CurationBriefFields }) =>
    request<CurationPreset>(`/api/curation/presets/${id}`, {
      method: 'PUT',
      body: JSON.stringify(body),
    }),

  curationCreatePreset: (body: { name: string; fields: CurationBriefFields }) =>
    request<CurationPreset>('/api/curation/presets', {
      method: 'POST',
      body: JSON.stringify(body),
    }),

  curationDeletePreset: (id: number) =>
    request<{ deleted: boolean }>(`/api/curation/presets/${id}`, { method: 'DELETE' }),

  curationWiring: () =>
    request<{ connections: CurationConnection[]; other_count: number }>('/api/curation/wiring'),

  curationPreview: (name: string, fields: CurationBriefFields) =>
    request<{ short: string; full: string }>('/api/curation/preview', {
      method: 'POST',
      body: JSON.stringify({ name, fields }),
    }),

  curationSetConnectionPreset: (id: number, presetId: number | null) =>
    request<{ preset_id: number | null }>(`/api/curation/wiring/${id}`, {
      method: 'PUT',
      body: JSON.stringify({ preset_id: presetId }),
    }),

  deletedNotes: (includePurged = false, page = 1, perPage = 20, query = '') => {
    const params = new URLSearchParams({ page: String(page), per_page: String(perPage) })
    if (includePurged) params.set('include_purged', '1')
    if (query !== '') params.set('q', query)

    return request<{
      limbo_days: number
      total: number
      page: number
      pages: number
      per_page: number
      notes: DeletedNote[]
    }>(`/api/deleted?${params.toString()}`)
  },
  restoreNote: (id: number) =>
    request<{ restored: boolean; note: { id: number; title: string } }>(
      `/api/deleted/${id}/restore`,
      { method: 'POST' },
    ),
  purgeNote: (id: number) => request<{ purged: boolean }>(`/api/deleted/${id}`, { method: 'DELETE' }),

  noteRevisions: (id: number) =>
    request<{ note_id: number; keep_per_note: number; revisions: NoteRevision[] }>(
      `/api/notes/${id}/revisions`,
    ),
  restoreRevision: (id: number, revisionId: number) =>
    request<{ restored: boolean; from_revision: number; note: { id: number; title: string } }>(
      `/api/notes/${id}/revisions/${revisionId}/restore`,
      { method: 'POST' },
    ),
  forgetRevisions: (id: number) =>
    request<{ forgotten: number }>(`/api/notes/${id}/revisions`, { method: 'DELETE' }),
  setTokenRole: (id: number, role: 'agent' | 'curator') =>
    request<{ id: number; role: string }>(`/api/tokens/${id}/role`, {
      method: 'PATCH',
      body: JSON.stringify({ role }),
    }),
  aiSettings: () => request<AiSettings>('/api/settings/ai'),
  saveAiSettings: (patch: AiRolePatch) =>
    request<AiSettings>('/api/settings/ai', { method: 'PUT', body: JSON.stringify(patch) }),
  saveAiSections: (patch: AiSectionPatch) =>
    request<AiSettings>('/api/settings/ai/sections', {
      method: 'PUT',
      body: JSON.stringify(patch),
    }),
  saveSearchModel: (model: string) =>
    request<AiSettings>('/api/settings/ai/search-model', {
      method: 'PUT',
      body: JSON.stringify({ model }),
    }),
  /** `section` is the one the key was added FOR: it, and only it, adopts the
   *  key. Omitted, the key is stored and adopted by nothing. */
  addAiKey: (provider: string, name: string, apiKey: string, section?: 'embed' | 'fetch' | 'text') =>
    request<AiSettings>('/api/settings/ai/keys', {
      method: 'POST',
      body: JSON.stringify({ provider, name, api_key: apiKey, section }),
    }),
  stats: () => request<VaultStats>('/api/stats'),

  exportAll: async (): Promise<{ blob: Blob; filename: string }> => {
    const res = await fetch('/api/export/all', { credentials: 'same-origin' })
    if (!res.ok) {
      let message = 'Export failed'
      let data: Record<string, unknown> = {}
      try {
        data = await res.json()
        message = (data.error as string) ?? message
      } catch {
      }
      showSuspension(res.status, data)
      throw new ApiError(res.status, message, data)
    }
    const disposition = res.headers.get('Content-Disposition') ?? ''
    const match = /filename="([^"]+)"/.exec(disposition)
    return { blob: await res.blob(), filename: match?.[1] ?? 'memex-vault.zip' }
  },

  deleteAiKey: (id: number) =>
    request<AiSettings>(`/api/settings/ai/keys/${id}`, { method: 'DELETE' }),
  aiModels: (credentialId: number) =>
    request<{ provider: string; default_model: string; models: { id: string; label: string }[] }>(
      `/api/settings/ai/models/${credentialId}`,
    ),

  tokens: () => request<{ tokens: TokenInfo[]; icons: IconChoice[]; logos: LogoChoice[] }>('/api/tokens'),
  updateToken: (id: number, patch: Record<string, unknown>) =>
    request<{ id: number; display_name: string | null; icon_key: string | null; icon_url: string | null; icon_url_dark: string | null }>(
      `/api/tokens/${id}`,
      { method: 'PATCH', body: JSON.stringify(patch) },
    ),
  uploadTokenIcon: (id: number, file: File) => {
    const body = new FormData()
    body.append('icon', file)

    return request<{ id: number; icon_key: string; icon_url: string }>(`/api/tokens/${id}/icon`, {
      method: 'POST',
      body,
    })
  },
  createToken: (name: string) =>
    request<{ id: number; name: string; token: string }>('/api/tokens', {
      method: 'POST',
      body: JSON.stringify({ name }),
    }),
  revokeToken: (id: number) => request<unknown>(`/api/tokens/${id}`, { method: 'DELETE' }),
  tokenSecret: (id: number) => request<{ token: string }>(`/api/tokens/${id}/token`).then((r) => r.token),
  reissueToken: (id: number) =>
    request<{ token: string }>(`/api/tokens/${id}/token`, { method: 'POST' }).then((r) => r.token),

  inboxBatch: (body: InboxBatchRequest) =>
    request<{ action: string; done: number; failed: { kind: string; id: number; error: string }[] }>(
      '/api/inbox/batch',
      { method: 'POST', body: JSON.stringify(body) },
    ),

  skills: () => request<{ skills: SkillRow[]; connections: SkillConnection[] }>('/api/skills'),
  updateSkill: (noteId: number, patch: SkillPatch) =>
    request<{ skill: SkillRow }>(`/api/skills/${noteId}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  importSkills: (files: File[]) => {
    const form = new FormData()
    files.forEach((f) => form.append('files[]', f))
    return request<{ created: SkillRow[]; errors: { file: string; error: string }[]; ignored: string[] }>(
      '/api/skills/import', { method: 'POST', body: form },
    )
  },
  exportSkillsUrl: () => '/api/skills/export',
  exportServedSkillUrl: (slug: string) => `/api/skills/served/${encodeURIComponent(slug)}/export`,
  addSkill: (slug: string) =>
    request<{ note: NoteDetail }>(`/api/skills/${slug}/add`, { method: 'POST' }),

  exportNoteUrl: (id: number) => `/api/notes/${id}/export`,
  exportSetUrl: (params: { q?: string; tags?: number[]; ids?: number[] }) => {
    const query = new URLSearchParams()
    if (params.q) query.set('q', params.q)
    if (params.tags?.length) query.set('tags', params.tags.join(','))
    if (params.ids?.length) query.set('ids', params.ids.join(','))
    return `/api/export?${query}`
  },
}
