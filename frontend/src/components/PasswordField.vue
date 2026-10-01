<script setup lang="ts">
import { ref } from 'vue'

// Everything a password field is given — id, autocomplete, placeholder,
// disabled, the enter handler — belongs on the input, not on the wrapper that
// only exists to hang the eye off.
defineOptions({ inheritAttrs: false })

defineProps<{ modelValue: string }>()
defineEmits<{ 'update:modelValue': [value: string] }>()

const shown = ref(false)
</script>

<template>
  <div class="mm-password">
    <input v-bind="$attrs" class="form-control" :type="shown ? 'text' : 'password'"
           :value="modelValue"
           @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)">
    <button type="button" class="mm-password-eye"
            :aria-label="shown ? $t('app.password.hide') : $t('app.password.show')" :aria-pressed="shown"
            @click="shown = !shown">
      <i class="fa-solid" :class="shown ? 'fa-eye-slash' : 'fa-eye'"></i>
    </button>
  </div>
</template>
