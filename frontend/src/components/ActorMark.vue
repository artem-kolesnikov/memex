<!--
  A byline: who wrote this, as a mark and a name.

  One component, because there is one shape (`Actor`) coming from one producer
  on the server. Before this the notes list rendered a joined token object and
  the note page rendered a bare string that had been built a different way, so
  the same connection appeared under two different names on two screens — the
  registered one in one place and the name the operator chose in the other.

  A person gets the same initial and colour the account menu uses for the signed-in
  user, so "that one was me" is answerable without reading the name.

  `memex` is the third kind (2026-08-23): work the product did itself, in a
  scheduled enrichment pass. Grey rather than the agent colour, because it is
  not one of your connections and looking like one would send somebody to
  Settings to find it.
-->
<script setup lang="ts">
import AgentMark from '@/components/AgentMark.vue'
import type { Actor } from '@/api/client'

withDefaults(defineProps<{ actor: Actor; withName?: boolean }>(), { withName: true })
</script>

<template>
  <span class="mm-agent">
    <AgentMark
      :icon="actor.icon"
      :icon-url="actor.icon_url"
      :icon-url-dark="actor.icon_url_dark"
      :initial="actor.initial"
      :round="actor.kind === 'person'"
      :glyph-class="{
        'mm-actor is-human': actor.kind === 'person',
        'mm-actor is-memex': actor.kind === 'memex',
      }"
    />
    <span class="mm-agent-name" v-if="withName">{{ actor.name }}</span>
  </span>
</template>
