<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PersonalizationSettings, WritingOption } from '@/api/client'
import SettingSaveState from './SettingSaveState.vue'
import type { PersonalizationPatch, PersonalizationState } from './usePersonalization'

const props = defineProps<{ settings: PersonalizationState }>()
const { t } = useI18n()

const view = computed(() => props.settings.view.value!)
const busy = computed(() => props.settings.saving.value)

// What the controls show: the server's settings, moved ahead by a click and
// put back when the save does not land.
const local = reactive<PersonalizationSettings>({ ...view.value.settings })
watch(() => props.settings.view.value, (v) => { if (v) Object.assign(local, v.settings) })

async function save(row: string, patch: PersonalizationPatch) {
  Object.assign(local, patch)
  if (!(await props.settings.update(row, patch))) Object.assign(local, view.value.settings)
}

function choose(o: WritingOption, value: string) {
  const patch: PersonalizationPatch = { [o.axis]: value }
  for (const a of o.add_ons) {
    if (local[a.key] && a.conflicts_with.includes(value)) patch[a.key] = false
  }
  void save(o.axis, patch)
}

function blockedBy(o: WritingOption, conflicts: string[]): string | null {
  const current = local[o.axis]
  return conflicts.includes(current) ? current : null
}

function says(o: WritingOption): string[] {
  const chosen = o.choices.find((c) => c.value === local[o.axis])
  return [
    ...(chosen ? [chosen.text] : []),
    ...o.add_ons.filter((a) => local[a.key] && blockedBy(o, a.conflicts_with) === null).map((a) => a.text),
  ]
}

const checked = (e: Event) => (e.target as HTMLInputElement).checked
const loaded = computed(() => view.value.skill.loaded_by)
</script>

<template>
  <div class="mm-block" data-writing>
    <h3 class="mm-block-title">{{ $t('personalization.writing.title') }}</h3>
    <p class="mm-note">{{ $t('personalization.writing.intro') }}</p>

    <div class="mm-pers-source" data-writing-source>
      <div class="mm-pers-switch">
        <div class="mm-pers-source-row">
          <label for="pers-writing" id="pers-writing-memex" :class="{ 'is-active': local.writing }"
                 @click.prevent="!busy && !local.writing && save('writing', { writing: true })">{{ $t('personalization.writing.memex') }}</label>
          <span class="form-switch mm-pers-source-switch">
            <input id="pers-writing" class="form-check-input" type="checkbox" aria-labelledby="pers-writing-own"
                   :checked="!local.writing" :disabled="busy" @change="save('writing', { writing: !checked($event) })">
          </span>
          <label for="pers-writing" id="pers-writing-own" :class="{ 'is-active': !local.writing }"
                 @click.prevent="!busy && local.writing && save('writing', { writing: false })">{{ $t('personalization.writing.own') }}</label>
        </div>
        <SettingSaveState :settings="settings" setting="writing" />
      </div>
      <i18n-t keypath="personalization.writing.source_help" tag="p" class="mm-pers-source-help" scope="global">
        <template #skills><router-link :to="{ name: 'skills' }">{{ $t('personalization.writing.skills_page') }}</router-link></template>
      </i18n-t>
    </div>

    <div class="table-responsive mm-pers-presets" :class="{ 'is-off': !local.writing }">
      <table class="table app-table mb-0 mm-settings-table mm-stack-sm">
        <thead>
          <tr>
            <th>{{ $t('personalization.presets.setting') }}</th>
            <th>{{ $t('personalization.presets.choice') }}</th>
            <th>{{ $t('personalization.presets.says') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="o in view.options" :key="o.axis" :data-axis="o.axis">
            <td>
              <div class="mm-pers-axis">
                <strong>{{ $t(`personalization.presets.${o.axis}.label`) }}</strong>
                <SettingSaveState :settings="settings" :setting="o.axis" />
              </div>
            </td>
            <td :data-label="$t('personalization.presets.choice')">
              <fieldset :disabled="busy || !local.writing">
                <legend class="visually-hidden">{{ $t(`personalization.presets.${o.axis}.label`) }}</legend>
                <div class="form-check" v-for="c in o.choices" :key="c.value">
                  <input class="form-check-input" type="radio" :name="`pers-${o.axis}`" :id="`pers-${o.axis}-${c.value}`"
                         :value="c.value" :checked="local[o.axis] === c.value" @change="choose(o, c.value)">
                  <label class="form-check-label" :for="`pers-${o.axis}-${c.value}`">{{ $t(`personalization.presets.${o.axis}.${c.value}`) }}</label>
                </div>
                <div class="mm-pers-addons" v-if="o.add_ons.length">
                  <div class="form-check" v-for="a in o.add_ons" :key="a.key" :data-add-on="a.key">
                    <input class="form-check-input" type="checkbox" :id="`pers-${a.key}`"
                           :checked="local[a.key] && blockedBy(o, a.conflicts_with) === null"
                           :disabled="blockedBy(o, a.conflicts_with) !== null"
                           :aria-describedby="blockedBy(o, a.conflicts_with) !== null ? `pers-${a.key}-why` : undefined"
                           @change="save(o.axis, { [a.key]: checked($event) })">
                    <label class="form-check-label" :for="`pers-${a.key}`">{{ $t(`personalization.presets.${o.axis}.${a.key}`) }}</label>
                    <p class="mm-pers-why" :id="`pers-${a.key}-why`" v-if="blockedBy(o, a.conflicts_with) !== null">
                      {{ t('personalization.presets.not_with', { choice: t(`personalization.presets.${o.axis}.${blockedBy(o, a.conflicts_with)}`) }) }}
                    </p>
                  </div>
                </div>
              </fieldset>
            </td>
            <td class="mm-pers-says" :data-label="$t('personalization.presets.says')">
              <p v-for="line in says(o)" :key="line">{{ line }}</p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="mm-pers-reach" data-writing-reach v-if="local.writing">
      {{ $t('personalization.writing.reach') }}
      <span v-if="loaded.length">{{ $t('personalization.writing.loaded_by', { names: loaded.join(', ') }) }}</span>
      <span v-else>{{ $t('personalization.writing.loaded_by_none') }}</span>
    </p>

    <details class="mm-pers-disclosure" data-writing-skill>
      <summary>{{ $t('personalization.writing.whole') }}</summary>
      <pre class="mm-pers-skill">{{ view.skill.text }}</pre>
    </details>
  </div>
</template>
