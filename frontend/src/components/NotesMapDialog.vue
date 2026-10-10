<script setup lang="ts">
// The map of the notes list, in frames: a dialog wide enough for the map and
// its side panels, over the list whose search still dims it. A note opened from
// it opens in the reading frame, so the reader stays in the frames.
import { ref } from 'vue'
import NotesMap from '@/components/NotesMap.vue'
import { useBootstrapModal } from '@/lib/bootstrapModal'
import { useAuthStore } from '@/stores/auth'

defineProps<{ filterIds: number[] | null; notice?: string; searching?: boolean }>()
const emit = defineEmits<{ close: []; open: [id: number]; 'clear-filters': []; tag: [name: string] }>()

const auth = useAuthStore()
const dialog = ref<HTMLElement | null>(null)
let opening: number | null = null
// The map measures the space it is given once it is drawn, so it is drawn
// after the dialog has finished arriving rather than while it slides in.
const { shown, hide } = useBootstrapModal(dialog, {
  onHidden: () => (opening !== null ? emit('open', opening) : emit('close')),
})

// The map's own link to a note goes to the note page; here it goes to the
// reading frame instead. Caught on the way down, before the link navigates.
function onClick(event: MouseEvent) {
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  const href = (event.target as HTMLElement).closest('a')?.getAttribute('href') ?? ''
  const own = auth.user ? `/${auth.user.team.handle}/notes/` : null
  const id = own !== null && href.startsWith(own) ? /^(\d+)$/.exec(href.slice(own.length)) : null
  if (!id) return
  event.preventDefault()
  opening = Number(id[1])
  hide()
}
</script>

<template>
  <Teleport to="body">
    <div ref="dialog" class="modal fade app-dialog mm-map-dialog" tabindex="-1" aria-labelledby="map-dialog-title">
      <div class="modal-dialog">
        <div class="modal-content" @click.capture="onClick">
          <div class="modal-header">
            <h5 id="map-dialog-title" class="modal-title h6 mb-0">{{ $t('search.view.map') }}</h5>
            <button type="button" class="btn-close" :aria-label="$t('common.close')" @click="hide"></button>
          </div>
          <div class="modal-body">
            <NotesMap v-if="shown" contained :filter-ids="filterIds" :notice="notice" :searching="searching"
                      @clear-filters="emit('clear-filters')" @tag="emit('tag', $event)" />
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
