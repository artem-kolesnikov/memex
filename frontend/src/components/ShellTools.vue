<script setup lang="ts">
// The header's right end, on every page: the activity log, Docs, the theme and
// Settings. In the stage above the page head on a desktop, in the top bar on a
// phone.
import { useRoute } from 'vue-router'
import { docsPage } from '@/lib/docs'
import { useTheme } from '@/lib/theme'

const route = useRoute()
const { theme, set: setTheme } = useTheme()
const THEMES = [
  { id: 'light', labelKey: 'general.themes.sunrise', icon: 'fa-solid fa-sun' },
  { id: 'dark', labelKey: 'general.themes.midnight', icon: 'fa-solid fa-moon' },
] as const
</script>

<template>
  <div class="app-shell-tools">
    <router-link class="app-segment-button" :class="{ 'is-active': route.name === 'activity' }"
                 :to="{ name: 'activity' }" :title="$t('app.nav.activity')">
      <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
      <span class="app-shell-tools-label">{{ $t('app.nav.activity') }}</span>
    </router-link>
    <a class="app-segment-button" :href="docsPage()" target="_blank" rel="noopener" :title="$t('app.nav.docs')">
      <i class="fa-solid fa-book-open" aria-hidden="true"></i>
      <span class="app-shell-tools-label">{{ $t('app.nav.docs') }}</span>
    </a>
    <span class="app-segmented" role="group" :aria-label="$t('app.shell.appearance')">
      <button v-for="option in THEMES" :key="option.id" type="button" class="app-segment"
              :class="{ 'is-active': theme === option.id }" :aria-pressed="theme === option.id"
              :title="$t(option.labelKey)" :aria-label="$t(option.labelKey)" @click="setTheme(option.id)">
        <i :class="option.icon" aria-hidden="true"></i>
      </button>
    </span>
    <router-link class="app-segment-button" :to="{ name: 'settings' }"
                 :title="$t('app.nav.settings')" :aria-label="$t('app.nav.settings')">
      <i class="fa-solid fa-gear" aria-hidden="true"></i>
    </router-link>
  </div>
</template>
