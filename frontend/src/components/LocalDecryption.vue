<script setup>
import { onBeforeUnmount, ref } from 'vue';
import EncryptionDialog from './EncryptionDialog.vue';
import { ENCRYPTION_FORMAT, transformFile } from '../lib/fileEncryption.js';

const props = defineProps({ url: String, name: String });
const dialog = ref(false);
const busy = ref(false);
const error = ref('');
const progress = ref(0);
const name = ref('');
let source;
let controller;
let item;
let revokeTimer = null;
const liveUrls = new Set();

function triggerDownload(target, downloadName) {
  const anchor = document.createElement('a');
  anchor.href = target;
  anchor.download = downloadName ?? '';
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
}

function reset() {
  controller?.abort();
  controller = null;
  if (revokeTimer) { clearTimeout(revokeTimer); revokeTimer = null; }
  for (const url of liveUrls) URL.revokeObjectURL(url);
  liveUrls.clear();
  source = null;
  error.value = '';
}

function open(file = { download_url: props.url, name: props.name, encryption_format: ENCRYPTION_FORMAT }) {
  if (busy.value) return;
  reset();
  item = file;
  name.value = file.name;
  dialog.value = true;
}

function openLocal(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || busy.value) return;
  open({ name: file.name.replace(/\.wbencrypted$/i, '') || 'decrypted-file', encryption_format: ENCRYPTION_FORMAT });
  source = file;
}

function pickLocal() {
  if (busy.value) return;
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.wbencrypted';
  input.addEventListener('change', openLocal, { once: true });
  input.click();
}

async function answer(result) {
  dialog.value = false;
  if (result.choice === 'cancel') { reset(); return; }
  if (result.choice === 'download') {
    const target = item?.download_url;
    reset();
    if (target) triggerDownload(target, '');
    return;
  }
  busy.value = true;
  error.value = '';
  progress.value = 0;
  const operation = new AbortController();
  controller = operation;
  try {
    if (item.encryption_format !== ENCRYPTION_FORMAT) throw new Error('Unsupported encryption format. Update the application to decrypt this file.');
    if (!source) {
      const response = await fetch(item.download_url, { credentials: 'same-origin', cache: 'no-store', signal: operation.signal });
      if (!response.ok) throw new Error('Unable to download the encrypted file. Check your access and try again.');
      source = await response.blob();
    }
    const pending = transformFile(source, result.password, {
      decrypt: true,
      signal: operation.signal,
      onProgress: (value) => { progress.value = Math.round(value * 100); },
    });
    result.password = '';
    const plain = await pending;
    operation.signal.throwIfAborted();
    // Verified: hand the plaintext straight to the browser's download flow.
    const ready = URL.createObjectURL(plain);
    liveUrls.add(ready);
    const timer = setTimeout(() => { URL.revokeObjectURL(ready); liveUrls.delete(ready); if (revokeTimer === timer) revokeTimer = null; }, 60000);
    revokeTimer = timer;
    source = null;
    triggerDownload(ready, item.name || name.value);
  } catch (failure) {
    if (failure.name !== 'AbortError') error.value = failure.message || 'Unable to decrypt this file.';
  } finally {
    result.password = '';
    busy.value = false;
  }
}

defineExpose({ open, pickLocal });
onBeforeUnmount(reset);
</script>

<template>
  <button v-if="url" class="header-button primary-button" type="button" :disabled="busy" @click="open()">Decrypt locally</button>
  <EncryptionDialog v-if="dialog" decrypt :name="name" :download-url="(dialog && item && item.download_url) || url || ''" @answer="answer" />
  <section v-if="busy || error" class="upload-queue-card" aria-live="polite">
    <p v-if="busy">Downloading, verifying and decrypting {{ name }} locally: {{ progress }}%</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <button v-if="error" class="header-button" type="button" @click="dialog = true">Retry password</button>
    <button class="header-button" type="button" @click="reset">{{ busy ? 'Cancel' : 'Dismiss' }}</button>
  </section>
</template>
