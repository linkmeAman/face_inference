<script setup>
import "face-capture";

defineProps({
  apiUrl: { type: String, required: true },
  token: { type: String, required: true },
  collection: { type: String, default: "default" },
});
const emit = defineEmits(["result", "error"]);
</script>

<template>
  <face-capture
    :api-url="apiUrl"
    :token="token"
    :collection="collection"
    mode="recognize"
    autostart
    @face-result="(e) => emit('result', e.detail)"
    @face-error="(e) => emit('error', e.detail)"
  />
</template>
