<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, type SkillConnection, type SkillRow } from '@/api/client'
import AgentMark from '@/components/AgentMark.vue'
import { formatDateTime } from '@/lib/datetime'
import { renderNote } from '@/lib/markdown'
import { useAuthStore } from '@/stores/auth'
import SkillConnections from './SkillConnections.vue'
import SkillUseExample from './SkillUseExample.vue'
import SkillSwitch from './SkillSwitch.vue'
import { skillOffered } from './skillGroups'
import { useSkillEditing, type SkillDraft } from './skillEditing'

// A skill in the detail frame, laid out as a note is: its switch and name, and
// what can be done to it, in one row, its instructions on a sheet, and who can
// use it, its alias, how to ask for it and how often it was loaded as cards
// beside them. The page switches it, as it does from the list.
const props = defineProps<{ skill: SkillRow; connections: SkillConnection[]; saving: boolean; failure: string | null; draft: SkillDraft }>()
const emit = defineEmits<{ close: []; updated: [row: SkillRow]; toggle: [row: SkillRow] }>()
const router = useRouter()
const auth = useAuthStore()

const editable = computed(() => props.skill.kind === 'note')
const available = computed(() => skillOffered(props.skill))
const { slugDraft, error, saving: editing, patch, rename } = useSkillEditing(() => props.skill, props.draft, (row) => emit('updated', row))
const byId = computed(() => new Map(props.connections.map((c) => [c.id, c])))
const number = (value: number) => new Intl.NumberFormat().format(value)

// A skill that is a note may link to other notes: its links come with the
// note, so they resolve as they do on the note's own page.
const links = ref<{ target: string; note_id: number | null }[]>([])
onMounted(async () => {
  if (props.skill.note_id === null) return
  try {
    links.value = (await api.getNote(props.skill.note_id)).links
  } catch {
    // Unresolved links still read as their text.
  }
})
const rendered = computed(() => renderNote(props.skill.body, links.value, auth.user?.team.handle))

function onBodyClick(event: MouseEvent) {
  if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  const anchor = (event.target as HTMLElement).closest('a')
  const href = anchor?.getAttribute('href')
  const own = auth.user ? `/${auth.user.team.handle}/` : null
  if (anchor && href && (anchor.classList.contains('wiki-link') || (own !== null && href.startsWith(own)))) {
    event.preventDefault()
    router.push(href)
  }
}

function connectionName(id: number): string {
  const c = byId.value.get(id)
  return c ? c.display_name || c.label : String(id)
}
</script>

<template>
  <div class="mm-pane-note mm-skill-frame" :data-skill="skill.slug">
    <header class="mm-note-heading">
      <div class="mm-note-identity">
        <p class="app-eyebrow mm-note-eyebrow">{{ $t('skills.pane.eyebrow') }}</p>
        <div class="mm-skill-title-line">
          <SkillSwitch :skill="skill" :saving="saving" @toggle="emit('toggle', skill)" />
          <h1 class="mm-note-title">{{ skill.title }}</h1>
        </div>
        <div class="mm-note-subline">
          <span>{{ skill.usage.last_at ? $t('skills.list.last_used', { when: formatDateTime(skill.usage.last_at) }) : $t('skills.list.never_used') }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ $t('skills.cards.loads', { count: skill.usage.total_30d }, skill.usage.total_30d) }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ $t('skills.cards.tokens', { count: number(skill.size_tokens) }) }}</span>
        </div>
      </div>

      <div class="mm-note-actions">
        <router-link v-if="skill.note_id" class="btn btn-sm btn-primary" :to="{ name: 'note-edit', params: { id: skill.note_id } }">
          <i class="fa-solid fa-pen"></i> {{ $t('skills.panel.edit_note') }}
        </router-link>
        <a v-if="skill.kind !== 'catalogue'" class="btn btn-sm btn-charcoal" :href="api.exportServedSkillUrl(skill.slug)">
          <i class="fa-regular fa-circle-down"></i> {{ $t('skills.panel.download') }}
        </a>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary mm-pane-close" :title="$t('note.pane.close')"
              :aria-label="$t('note.pane.close')" @click="emit('close')">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>

    <p v-if="error || failure" class="alert alert-warning py-1" role="alert">{{ error || failure }}</p>
    <div v-if="skill.status === 'pending'" class="app-notice app-notice-pending mm-proposal-notice">
      <span class="app-notice-mark"><i class="fa-regular fa-clock"></i></span>
      <p>{{ $t('skills.panel.pending_note') }}</p>
    </div>
    <div v-if="skill.status === 'switched_off'" class="app-notice mm-proposal-notice">
      <span class="app-notice-mark"><i class="fa-solid fa-power-off"></i></span>
      <p><router-link :to="{ name: 'settings', params: { pane: 'personalization' } }">{{ $t('skills.cards.switched_off') }}</router-link></p>
    </div>

    <div class="mm-reading-desk">
      <article class="app-paper mm-note-paper">
        <div v-if="skill.description" class="mm-note-abstract">{{ skill.description }}</div>
        <!-- eslint-disable-next-line vue/no-v-html — renderNote escapes source markdown -->
        <div class="note-body" v-html="rendered" @click="onBodyClick"></div>
      </article>

      <aside class="mm-note-context">
        <section v-if="skill.kind !== 'catalogue'" class="app-panel mm-context-panel mm-skill-access">
          <SkillConnections v-if="editable" :grants="skill.grants" :connections="connections" :draft="draft"
                            :disabled="saving || editing || skill.status === 'pending'" @save="patch({ grants: $event })" />
          <template v-else>
            <h2 class="mm-context-title">{{ $t('skills.panel.connections') }}</h2>
            <p class="mm-skill-aside">{{ $t('skills.panel.origin_shipped') }}</p>
          </template>
        </section>

        <section class="app-panel mm-context-panel">
          <label class="mm-context-title d-block" :for="editable ? 'skill-frame-slug' : undefined">{{ $t('skills.panel.slug') }}</label>
          <p id="skill-frame-slug-help" class="mm-skill-aside">{{ $t('skills.panel.slug_help') }}</p>
          <div v-if="editable" class="mm-skill-alias">
            <input id="skill-frame-slug" class="form-control form-control-sm font-monospace" v-model="slugDraft"
                   :disabled="editing || skill.status === 'pending'" aria-describedby="skill-frame-slug-help" />
            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="editing || skill.status === 'pending' || slugDraft === skill.slug"
                    @click="rename">{{ $t('common.save') }}</button>
          </div>
          <code v-else>{{ skill.slug }}</code>
        </section>

        <section class="app-panel mm-context-panel">
          <h2 class="mm-context-title">{{ $t('skills.panel.how_to_use') }}</h2>
          <SkillUseExample :skill="skill" compact :can-copy="available" />
        </section>

        <section class="app-panel mm-context-panel">
          <h2 class="mm-context-title">{{ $t('skills.panel.usage') }}</h2>
          <ul v-if="skill.usage.total_30d > 0" class="mm-skill-usage">
            <li v-for="u in skill.usage.by_token" :key="u.token_id">
              <AgentMark v-if="byId.get(u.token_id)" :icon="byId.get(u.token_id)!.icon" :icon-url="byId.get(u.token_id)!.icon_url" :icon-url-dark="byId.get(u.token_id)!.icon_url_dark" />
              <span>{{ $t('skills.panel.usage_line', { name: connectionName(u.token_id), count: u.count, when: formatDateTime(u.last_at) }, u.count) }}</span>
            </li>
          </ul>
          <p v-else class="mm-skill-aside">{{ $t('skills.panel.usage_none') }}</p>
        </section>

        <section v-if="skill.lint.length" class="app-panel mm-context-panel">
          <h2 class="mm-context-title">{{ $t('skills.panel.findings') }}</h2>
          <ul class="mm-skill-usage"><li v-for="f in skill.lint" :key="f.code">{{ f.message }}</li></ul>
        </section>
      </aside>
    </div>
  </div>
</template>
