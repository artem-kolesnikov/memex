<script setup lang="ts">
// The two themes as pictures of themselves, chosen with a radio. One control
// for the first-run wizard and Settings › General, so the two cannot drift.
import { useTheme, type Theme } from '@/lib/theme'
import WizardIcon from '@/components/welcome/WizardIcon.vue'

const { theme, set: setTheme } = useTheme()

const THEMES: { id: Theme; labelKey: string; subKey: string; icon: string }[] = [
  { id: 'light', labelKey: 'general.themes.sunrise', subKey: 'general.themes.light_kind', icon: 'sun' },
  { id: 'dark', labelKey: 'general.themes.midnight', subKey: 'general.themes.dark_kind', icon: 'moon' },
]
</script>

<template>
  <div class="mm-wiz-theme-options">
    <label v-for="option in THEMES" :key="option.id" class="mm-wiz-theme-option">
      <input type="radio" name="wiz-theme" :value="option.id" :checked="theme === option.id"
             :aria-label="$t('general.themes.aria', { name: $t(option.labelKey), kind: $t(option.subKey) })"
             @change="setTheme(option.id)">
      <span class="mm-wiz-theme-image" :class="{ 'is-night': option.id === 'dark' }" aria-hidden="true">
        <span class="mm-wiz-mini-window">
          <span class="mm-wiz-mini-sidebar"><img src="/favicon.svg" alt=""><i></i><i></i><i></i></span>
          <span class="mm-wiz-mini-content"><b>{{ $t('general.themes.mini_title') }}</b><i></i><i></i><em></em></span>
        </span>
      </span>
      <span class="mm-wiz-theme-caption">
        <WizardIcon :name="option.icon" />{{ $t(option.labelKey) }}<small>{{ $t(option.subKey) }}</small>
        <span class="mm-wiz-radio-dot" aria-hidden="true"></span>
      </span>
    </label>
  </div>
</template>
