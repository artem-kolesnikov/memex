<script setup lang="ts">
// The shell every signed-out page wears: the landing page's eggshell band,
// centred, with the mark above whatever the page has to say.
//
// `.mm-public` and data-bs-theme="light" travel together and are the reason
// this is a component rather than a class each view remembers to add. Half of
// them was what shipped: the card was hard-coded eggshell while the Bootstrap
// controls inside it followed the app's theme, so a visitor whose browser had
// the dark theme stored got a black input on a white card.
import BrandMark from '@/components/BrandMark.vue'
import { usePublicChrome } from '@/lib/publicChrome'

usePublicChrome()
</script>

<template>
  <div class="mm-public mm-door" data-bs-theme="light">
    <section class="mm-login-side mm-login-door">
      <div class="mm-login-inner">
        <BrandMark class="mm-login-mark" />
        <slot />
      </div>
    </section>
  </div>
</template>
