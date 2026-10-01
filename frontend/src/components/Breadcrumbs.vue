<script setup lang="ts">
// mm2's breadcrumb pattern: static section/action trail per route name,
// "Home" prepended (links to the search list — the app's single landing).
import { computed } from 'vue'
import { useRoute } from 'vue-router'

interface Crumb {
  labelKey: string
  to?: string
}

const TRAILS: Record<string, Crumb[]> = {
  search: [{ labelKey: 'app.nav.notes' }],
  'note-new': [{ labelKey: 'app.nav.notes', to: '/' }, { labelKey: 'app.breadcrumbs.create' }],
  note: [{ labelKey: 'app.nav.notes', to: '/' }, { labelKey: 'app.breadcrumbs.view' }],
  'note-edit': [{ labelKey: 'app.nav.notes', to: '/' }, { labelKey: 'common.edit' }],
  inbox: [{ labelKey: 'app.breadcrumbs.review_inbox' }],
  activity: [{ labelKey: 'app.nav.activity' }],
  map: [{ labelKey: 'app.nav.map' }],
  skills: [{ labelKey: 'app.nav.skills' }],
  deleted: [{ labelKey: 'app.breadcrumbs.deleted_notes' }],
  settings: [{ labelKey: 'app.nav.settings' }],
}

const route = useRoute()

const crumbs = computed<Crumb[]>(() => {
  const name = String(route.name ?? '')
  if (!name || name === 'login') {
    return []
  }
  const trail = TRAILS[name] || []
  return [{ labelKey: 'app.breadcrumbs.home', to: '/' }, ...trail]
})
</script>

<template>
  <nav :aria-label="$t('app.breadcrumbs.aria')" v-if="crumbs.length">
    <ol class="breadcrumb mm-breadcrumb mb-0">
      <li
        v-for="(crumb, index) in crumbs"
        :key="index"
        class="breadcrumb-item"
        :class="{ active: index === crumbs.length - 1 }"
        :aria-current="index === crumbs.length - 1 ? 'page' : undefined"
      >
        <router-link v-if="crumb.to && index !== crumbs.length - 1" :to="crumb.to">{{ $t(crumb.labelKey) }}</router-link>
        <span v-else>{{ $t(crumb.labelKey) }}</span>
      </li>
    </ol>
  </nav>
</template>

<style scoped>
.mm-breadcrumb {
  font-size: 0.95rem;
}
.mm-breadcrumb .breadcrumb-item.active {
  font-weight: 600;
}
</style>
