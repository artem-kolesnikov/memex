<script setup lang="ts">
// How memex looks and reads. The theme, the note text size and the typeface are
// per device, and all three are applied by public/theme-boot.js
// before the app mounts, so nothing resizes under the reader half a second in.
// The layout is per device too, kept with the sidebar's state.
//
// The reader no longer picks colours. Two themes are the whole choice, and any
// accent an account stored while the picker existed is cleared on arrival here
// — leaving it applied would render an account in a colour with no control to
// change it, and a permitted accent could put the primary button at 1.54:1.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api, type InstalledLocale } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useLayoutStore, type LayoutMode } from '@/stores/layout'
import { sessionEpoch, useOperationLifetime } from '@/lib/operationLifetime'
import OperationOutcomeNotice from '@/components/OperationOutcomeNotice.vue'
import { toastError } from '@/components/toastService'
import { BUNDLED, currentLocale, languageName } from '@/i18n'
import ThemeChoice from '@/components/ThemeChoice.vue'
import {
  DATE_FORMATS,
  dateFormat,
  formatDate,
  setDateFormat,
  setTimeZone,
  systemZone,
  timeZone,
  timeZones,
} from '@/lib/datetime'

const TEXT_SIZES = window.appearanceChoices?.('text') ?? []
const TEXT_MIN = TEXT_SIZES[0]
const TEXT_MAX = TEXT_SIZES[TEXT_SIZES.length - 1]

// The layouts as pictures of themselves, as the themes are: a page at a time,
// a list page in two frames, or frames only on a window wide enough.
const LAYOUTS: { id: LayoutMode; labelKey: string; subKey: string; adviceKey: string }[] = [
  { id: 'windowed', labelKey: 'general.layout.windowed', subKey: 'general.layout.windowed_kind', adviceKey: 'general.layout.windowed_advice' },
  { id: 'framed', labelKey: 'general.layout.framed', subKey: 'general.layout.framed_kind', adviceKey: 'general.layout.framed_advice' },
  { id: 'dynamic', labelKey: 'general.layout.dynamic', subKey: 'general.layout.dynamic_kind', adviceKey: 'general.layout.dynamic_advice' },
]

const MD_FONTS = [
  { id: 'sans', labelKey: 'general.fonts.sans' },
  { id: 'serif', labelKey: 'general.fonts.serif' },
  { id: 'mono', labelKey: 'general.fonts.mono' },
]

const auth = useAuthStore()
const layout = useLayoutStore()
const lifetime = useOperationLifetime()
const { t } = useI18n()
const installed = ref<InstalledLocale[]>([])
const languages = computed(() => [
  { id: BUNDLED, label: languageName(BUNDLED) },
  ...installed.value.map((l) => ({ id: l.code, label: languageName(l.code) })),
])

const textSize = ref(window.getAppearance?.('text') ?? '15')
const mdFont = ref(window.getAppearance?.('font') ?? 'sans')
const language = ref(auth.user?.locale ?? currentLocale())
const switching = ref(false)
const unknown = ref(false)
watch(sessionEpoch, () => { if (switching.value) unknown.value = true })

onMounted(async () => {
  try {
    installed.value = (await api.locales()).locales
  } catch {
    /* the dropdown offers English alone */
  }
})

async function chooseLanguage(code: string) {
  const mine = lifetime.capture()
  const before = language.value
  language.value = code
  switching.value = true
  try {
    await auth.chooseLocale(code)
    if (!lifetime.current(mine)) return
  } catch (e) {
    if (!lifetime.current(mine)) return
    language.value = before
    toastError(t('general.language.not_changed'), e instanceof Error ? e.message : t('common.unknown_error'))
  } finally {
    if (lifetime.current(mine)) switching.value = false
  }
}

const zones = timeZones()
const sample = new Date().toISOString()

// Once, on arrival: a colour chosen before the picker was retired has no way
// back to the standard palette otherwise.
onMounted(() => {
  window.resetThemed?.()
  api.updateAppearance({
    accent: { light: null, dark: null },
    link: { light: null, dark: null },
    bodyText: { light: null, dark: null },
  }).catch(() => {
    // A failed write leaves the account's stored colour behind, which the next
    // visit tries again. Nothing on screen depends on it having landed.
  })
})

function chooseAppearance(which: 'text' | 'font', value: string) {
  if (which === 'text') textSize.value = value
  if (which === 'font') mdFont.value = value
  window.setAppearance?.(which, value)
}
</script>

<template>
  <div class="mm-block">
    <h3 class="mm-block-title">{{ $t('general.layout.title') }}</h3>
    <p class="mm-note">{{ $t('general.layout.select') }}</p>

    <div class="mm-wiz-scope mm-settings-themes">
      <div class="mm-wiz-theme-options mm-layout-options" role="radiogroup" :aria-label="$t('general.layout.title')">
        <label v-for="option in LAYOUTS" :key="option.id" class="mm-wiz-theme-option">
          <input :id="`pref-layout-${option.id}`" type="radio" name="pref-layout" :value="option.id"
                 :checked="layout.layoutMode === option.id"
                 :aria-label="$t('general.layout.aria', { name: $t(option.labelKey), kind: $t(option.subKey) })"
                 @change="layout.setLayoutMode(option.id)">
          <span class="mm-wiz-theme-image mm-layout-image" aria-hidden="true">
            <span class="mm-layout-mini" :class="{ 'is-framed': option.id !== 'windowed' }">
              <span class="mm-layout-mini-sidebar"><img src="/favicon.svg" alt=""><i></i><i></i><i></i></span>
              <template v-if="option.id === 'windowed'">
                <span class="mm-layout-mini-page"><b></b><i></i><i></i><i></i><i></i></span>
              </template>
              <template v-else>
                <span class="mm-layout-mini-list"><b></b><i class="is-open"></i><i></i><i></i><i></i></span>
                <span class="mm-layout-mini-detail"><span><b></b><i></i><i></i><i></i></span></span>
              </template>
            </span>
            <!-- Dynamic: the same app on a narrow screen, in front of the wide one. -->
            <span v-if="option.id === 'dynamic'" class="mm-layout-mini mm-layout-mini-narrow">
              <span class="mm-layout-mini-page"><b></b><i></i><i></i><i></i><i></i></span>
            </span>
          </span>
          <span class="mm-wiz-theme-caption">
            <span class="mm-wiz-radio-dot" aria-hidden="true"></span>{{ $t(option.labelKey) }}
          </span>
          <span class="mm-choice-kind">{{ $t(option.subKey) }}</span>
          <span class="mm-layout-advice">{{ $t(option.adviceKey) }}</span>
        </label>
      </div>
    </div>
  </div>

  <div class="mm-block">
    <h3 class="mm-block-title">{{ $t('general.appearance.title') }}</h3>
    <p class="mm-note">{{ $t('general.appearance.select_theme') }}</p>

    <div class="mm-wiz-scope mm-settings-themes">
      <ThemeChoice />
    </div>

    <div class="mm-settings-grid mt-3">
      <div class="mm-settings-field">
        <label for="pref-text" class="form-label">
          {{ $t('general.appearance.text_size') }} <span class="mm-note-inline">{{ textSize }}px</span>
        </label>
        <input id="pref-text" type="range" class="form-range" :value="textSize"
               :min="TEXT_MIN" :max="TEXT_MAX" step="1"
               @input="chooseAppearance('text', ($event.target as HTMLInputElement).value)">
      </div>

      <div class="mm-settings-field">
        <label for="pref-font" class="form-label">{{ $t('general.appearance.typeface') }}</label>
        <select id="pref-font" class="form-select" :value="mdFont"
                @change="chooseAppearance('font', ($event.target as HTMLSelectElement).value)">
          <option v-for="f in MD_FONTS" :key="f.id" :value="f.id">{{ $t(f.labelKey) }}</option>
        </select>
      </div>
    </div>

  </div>

  <div class="mm-block">
    <h3 class="mm-block-title">{{ $t('general.language.title') }}</h3>

    <OperationOutcomeNotice v-if="unknown" />
    <div v-else class="mm-settings-half">
      <p class="mm-note mb-1" id="pref-language-hint">{{ $t('general.language.select') }}</p>
      <select id="pref-language" class="form-select" :value="language" :disabled="switching"
              aria-labelledby="pref-language-hint"
              @change="chooseLanguage(($event.target as HTMLSelectElement).value)">
        <option v-for="l in languages" :key="l.id" :value="l.id">{{ l.label }}</option>
      </select>
    </div>
  </div>

  <div class="mm-block">
    <h3 class="mm-block-title">{{ $t('general.datetime.title') }}</h3>

    <div class="mm-settings-grid">
      <div class="mm-settings-field">
        <label for="pref-date" class="form-label">{{ $t('general.datetime.select_format') }}</label>
        <select id="pref-date" class="form-select" :value="dateFormat"
                @change="setDateFormat(($event.target as HTMLSelectElement).value as never)">
          <option v-for="f in DATE_FORMATS" :key="f.id" :value="f.id">{{ $t(f.labelKey) }}</option>
        </select>
        <p class="mm-note mb-0">{{ $t('general.datetime.today_is', { date: formatDate(sample) }) }}</p>
      </div>

      <div class="mm-settings-field">
        <label for="pref-zone" class="form-label">{{ $t('general.datetime.select_zone') }}</label>
        <select id="pref-zone" class="form-select" :value="timeZone"
                @change="setTimeZone(($event.target as HTMLSelectElement).value)">
          <option value="system">{{ $t('general.datetime.system_zone', { zone: systemZone() }) }}</option>
          <option v-for="z in zones" :key="z" :value="z">{{ z }}</option>
        </select>
        <p class="mm-note mb-0">{{ $t('general.datetime.zone_note') }}</p>
      </div>
    </div>
  </div>
</template>
