<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { copyText } from '@/lib/clipboard'
import type { SkillRow } from '@/api/client'
const props = withDefaults(defineProps<{ skill: SkillRow; compact?: boolean; canCopy?: boolean }>(), { compact: false, canCopy: true })
const { t } = useI18n()
const copied = ref(false)
const error = ref(false)
const example = computed(() => t('skills.example.prompt', { name: props.skill.slug }))
watch(example, () => { copied.value = false; error.value = false })
async function copy() {
  copied.value = await copyText(example.value)
  error.value = !copied.value
}
</script>

<template>
  <div class="skill-example">
    <p v-if="!compact" class="small text-muted mb-2">{{ $t('skills.example.help') }}</p>
    <blockquote class="small mb-2">
      {{ example }}
      <button type="button" class="btn btn-sm btn-link prompt-copy" :disabled="!canCopy"
              :aria-label="copied ? $t('skills.example.copied') : $t('skills.example.copy')"
              :title="copied ? $t('skills.example.copied') : $t('skills.example.copy')" @click="copy">
        <i :class="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'" aria-hidden="true"></i>
      </button>
    </blockquote>
    <p v-if="error" role="alert" class="small text-warning mt-2">{{ $t('skills.example.copy_failed') }}</p>
  </div>
</template>

<style scoped>
.skill-example { min-width: 0; }
blockquote { position: relative; padding: .9rem 3rem .9rem 1rem; border: 1px solid var(--mm-line); border-radius: .6rem; line-height: 1.65; background: var(--mm-bg); overflow-wrap: anywhere; }
.prompt-copy { position: absolute; top: .45rem; right: .45rem; min-width: 2rem; min-height: 2rem; color: var(--mm-muted); box-shadow: none; }
</style>
