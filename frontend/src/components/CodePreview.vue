<script setup>
import { computed, ref } from 'vue';
import hljs from 'highlight.js/lib/common';
import { sourceLanguage } from '../lib/sourceLanguage.js';

const props = defineProps({
  item: { type: Object, required: true },
  text: { type: String, default: '' },
  loading: Boolean,
  error: { type: String, default: '' },
});
const language = computed(() => sourceLanguage(props.item));
const lineCount = computed(() => props.text.split('\n').length);
const numbers = computed(() => Array.from({ length: lineCount.value }, (_, i) => i + 1).join('\n'));
// Keep large source files readable without blocking the UI on tokenization.
const highlighted = computed(() => props.text.length <= 200000 && language.value.id !== 'plaintext'
  ? hljs.highlight(props.text, { language: language.value.id, ignoreIllegals: true }).value : null);
const copyStatus = ref('');
async function copyCode() {
  try {
    await navigator.clipboard.writeText(props.text);
    copyStatus.value = 'Copied';
  } catch {
    copyStatus.value = 'Copy unavailable';
  }
}
</script>

<template>
  <section class="code-view" :aria-label="`${language.label} file preview`" :aria-busy="loading">
    <header class="code-view__toolbar">
      <div class="code-view__identity">
        <img v-if="item.file_icon_url" :src="item.file_icon_url" alt="">
        <span><strong>{{ item.name }}</strong><small>{{ language.label }}<span v-if="!loading && !error"> · {{ lineCount }} {{ lineCount === 1 ? 'line' : 'lines' }}</span></small></span>
      </div>
      <button type="button" class="header-button" :disabled="loading || !!error" @click="copyCode">Copy code</button>
      <span class="code-view__copy-status" role="status">{{ copyStatus }}</span>
    </header>
    <p v-if="loading" class="code-view__message" role="status">Loading file…</p>
    <p v-else-if="error" class="code-view__message" role="alert">{{ error }}</p>
    <div v-else class="code-view__scroll" tabindex="0" aria-label="Source code, scroll to read">
      <pre class="code-view__numbers" aria-hidden="true">{{ numbers }}</pre>
      <pre class="code-view__source"><code v-if="highlighted !== null" class="hljs" :class="`language-${language.id}`" v-html="highlighted"></code><code v-else>{{ text }}</code></pre>
    </div>
  </section>
</template>
