<script setup>
import { onBeforeUnmount, ref } from 'vue';
import EncryptionDialog from './EncryptionDialog.vue';
import { ENCRYPTION_FORMAT, transformFile } from '../lib/fileEncryption.js';

const props = defineProps({ url: String, name: String });
const dialog = ref(false);
const busy = ref(false);
const error = ref('');
const progress = ref(0);
const readyUrl = ref('');
const name = ref('');
let source;
let controller;
let item;

function reset() {
  controller?.abort();
  controller = null;
  if (readyUrl.value) URL.revokeObjectURL(readyUrl.value);
  readyUrl.value = '';
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
    readyUrl.value = URL.createObjectURL(plain);
    source = null;
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
  <EncryptionDialog v-if="dialog" decrypt :name="name" @answer="answer" />
  <section v-if="busy || error || readyUrl" class="upload-queue-card" aria-live="polite">
    <p v-if="busy">Downloading, verifying and decrypting {{ name }} locally: {{ progress }}%</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="readyUrl">Password and file integrity verified. Your decrypted file is ready.</p>
    <a v-if="readyUrl" class="header-button primary-button" :href="readyUrl" :download="name">Save decrypted file</a>
    <button v-if="error" class="header-button" type="button" @click="dialog = true">Retry password</button>
    <button class="header-button" type="button" @click="reset">{{ busy ? 'Cancel' : 'Dismiss' }}</button>
  </section>
</template>
