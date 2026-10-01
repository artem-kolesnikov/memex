<!--
  One mark, whatever kind it is.

  There are three kinds — a Font Awesome glyph, an uploaded image, and a shipped
  provider logo — and at least four places that draw one: the connections table,
  the icon picker's well, the picker's tiles, and every byline. Each of those had
  its own two lines of template, which is how the notes list and the note page
  once ended up disagreeing about a connection's name. One component, so a fourth
  kind is added once.

  ## Why a logo is two images and not one

  A glyph inherits `currentColor` and follows the theme for free. A logo cannot:
  the mark is the mark. Marks with real colour read on either background from one
  file; marks that are monochrome by design need one file per background, or an
  assistant wearing OpenAI's black rosette is invisible to every reader in dark
  mode. The server sends both URLs — equal when one file does both — and the
  choice is made in CSS rather than in script, so switching theme is instant and
  nothing here has to know which theme is current.

  Both images are always in the DOM and one is hidden. That is deliberate: the
  browser fetches only what it paints, and the alternative — binding `src` to a
  theme ref — would make every mark on the page re-decide on each toggle.
-->
<script setup lang="ts">
import { computed, type HTMLAttributes } from 'vue'

const props = withDefaults(
  defineProps<{
    /** Font Awesome classes, when the mark is a glyph. */
    icon?: string | null
    /** The image for a light background: uploaded bytes, or a logo. */
    iconUrl?: string | null
    /** The same image for a dark background. Absent means "the same file". */
    iconUrlDark?: string | null
    /** Extra classes for the glyph only — bylines colour a person and memex. */
    glyphClass?: HTMLAttributes['class']
    initial?: string | null
    /** A face is a circle; a vendor's logo is not, and cropping one ruins it. */
    round?: boolean
  }>(),
  { icon: null, iconUrl: null, iconUrlDark: null, glyphClass: undefined, initial: null, round: false },
)

/**
 * Only a genuine pair gets the theme rules. The class is what the CSS keys on,
 * so a single file serving both themes is never hidden by the dark-mode rule —
 * which is exactly what would happen if the rule applied to every image.
 */
const hasPair = computed(
  () => props.iconUrl !== null && props.iconUrlDark !== null && props.iconUrlDark !== props.iconUrl,
)
</script>

<template>
  <span class="mm-agent-mark" :class="{ 'has-pair': hasPair, 'is-round': round }"
        v-if="iconUrl || initial || icon">
    <template v-if="iconUrl">
      <img class="mm-on-light" :src="iconUrl" alt="" />
      <img v-if="hasPair" class="mm-on-dark" :src="iconUrlDark!" alt="" />
    </template>
    <span v-else-if="initial" class="mm-agent-initial" :class="glyphClass">{{ initial }}</span>
    <i v-else :class="[icon, glyphClass]"></i>
  </span>
</template>
