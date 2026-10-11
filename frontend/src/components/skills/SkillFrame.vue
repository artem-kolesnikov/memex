<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, type SkillConnection, type SkillRow } from '@/api/client'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { renderNote } from '@/lib/markdown'
import { useAuthStore } from '@/stores/auth'
import SkillRail from './SkillRail.vue'
import SkillSwitch from './SkillSwitch.vue'
import SkillUsageFacts from './SkillUsageFacts.vue'

// A skill that is not a note of the owner's — one built into memex, or a
// template not yet taken — in the detail frame, laid out as a note is: its
// switch and name, its instructions on a sheet, and the rail beside them. The
// owner's own skills open as their notes, and so does one of memex's once it
// is unlocked: Unlock stands where Edit would, behind a warning.
const props = defineProps<{ skill: SkillRow; connections: SkillConnection[]; saving: boolean; failure: string | null }>()
const emit = defineEmits<{ close: []; toggle: [row: SkillRow]; unlocked: [noteId: number] }>()
const router = useRouter()
const auth = useAuthStore()

const number = (value: number) => new Intl.NumberFormat().format(value)
const confirming = ref(false)
const unlocking = ref(false)
const unlockFailed = ref(false)
async function unlock() {
  unlocking.value = true
  unlockFailed.value = false
  try {
    const row = (await api.unlockSkill(props.skill.slug)).skill
    confirming.value = false
    if (row.note_id !== null) emit('unlocked', row.note_id)
  } catch {
    unlockFailed.value = true
  } finally {
    unlocking.value = false
  }
}
const rendered = computed(() => renderNote(props.skill.body, [], auth.user?.team.handle))

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
      </div>

      <div class="mm-note-actions">
        <button v-if="skill.unlockable && !skill.unlocked" type="button" class="btn btn-sm btn-primary" @click="confirming = true">
          <i class="fa-solid fa-lock-open"></i> {{ $t('skills.unlock.button') }}
        </button>
        <a v-if="skill.kind !== 'catalogue'" class="btn btn-sm btn-charcoal" :href="api.exportServedSkillUrl(skill.slug)">
          <i class="fa-regular fa-circle-down"></i> {{ $t('skills.panel.download') }}
        </a>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary mm-pane-close" :title="$t('note.pane.close')"
              :aria-label="$t('note.pane.close')" @click="emit('close')">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>

    <p v-if="failure" class="alert alert-warning py-1" role="alert">{{ failure }}</p>
    <p v-if="unlockFailed" class="alert alert-warning py-1" role="alert">{{ $t('skills.unlock.failed') }}</p>
    <ConfirmDialog v-if="confirming" :title="$t('skills.unlock.title', { title: skill.title })" :confirm-label="$t('skills.unlock.confirm')"
                   danger :busy="unlocking" @confirm="unlock" @close="confirming = false">
      {{ $t('skills.unlock.warning') }}
    </ConfirmDialog>
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
        <SkillRail :skill="skill" :connections="connections" />
        <section class="app-panel mm-context-panel mm-context-details">
          <h2 class="mm-context-title">{{ $t('skills.details.title') }}</h2>
          <dl class="mm-note-facts">
            <SkillUsageFacts :skill="skill" :connections="connections" />
            <dt>{{ $t('note.details.source') }}</dt>
            <dd>{{ skill.kind === 'catalogue' ? $t('skills.details.source_template') : $t('skills.groups.system') }}</dd>
            <dt>{{ $t('note.details.size') }}</dt>
            <dd>{{ $t('skills.cards.tokens', { count: number(skill.size_tokens) }) }}</dd>
          </dl>
        </section>
      </aside>
    </div>
  </div>
</template>
