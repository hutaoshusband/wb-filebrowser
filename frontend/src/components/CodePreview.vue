<script setup>
import { computed, ref, watch } from 'vue';
import { sourceLanguage } from '../lib/sourceLanguage.js';

const props = defineProps({
  item: { type: Object, required: true },
  text: { type: String, default: '' },
  loading: Boolean,
  error: { type: String, default: '' },
});
const language = computed(() => sourceLanguage(props.item));
const host = ref(null);
const lineCount = ref(null);
const viewerError = ref('');
const copyStatus = ref('');

watch([host, () => props.text, language], async ([element, text, source], _, onCleanup) => {
  lineCount.value = null;
  viewerError.value = '';
  copyStatus.value = '';
  if (!element) return;

  let disposed = false;
  let viewer;
  onCleanup(() => {
    disposed = true;
    viewer?.destroy();
  });

  try {
    const { mountCodeViewer } = await import('../lib/codeViewer.js');
    if (disposed) return;
    viewer = mountCodeViewer(element, text, source);
    lineCount.value = viewer.lineCount;
  } catch {
    if (!disposed) viewerError.value = 'Unable to open the source viewer. Copy code is still available.';
  }
}, { flush: 'post' });

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
        <span><strong>{{ item.name }}</strong><small>{{ language.label }}<span v-if="lineCount !== null"> · {{ lineCount }} {{ lineCount === 1 ? 'line' : 'lines' }}</span></small></span>
      </div>
      <button type="button" class="header-button" :disabled="loading || !!error" @click="copyCode">Copy code</button>
      <span class="code-view__copy-status" role="status">{{ copyStatus }}</span>
    </header>
    <p v-if="loading" class="code-view__message" role="status">Loading file…</p>
    <p v-else-if="error" class="code-view__message" role="alert">{{ error }}</p>
    <template v-else>
      <p v-if="viewerError" class="code-view__message" role="alert">{{ viewerError }}</p>
      <p v-else-if="lineCount === null" class="code-view__message" role="status">Loading source viewer…</p>
      <div ref="host" class="code-view__scroll"></div>
    </template>
  </section>
</template>
