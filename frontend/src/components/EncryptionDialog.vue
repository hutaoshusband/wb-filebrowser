<script setup>
import { ref } from 'vue';

const props = defineProps({ required: Boolean, decrypt: Boolean, name: String, downloadUrl: String });
const emit = defineEmits(['answer']);
const password = ref('');
const confirmation = ref('');
const error = ref('');

function answer(choice) {
  if (choice === 'encrypt') {
    const length = new TextEncoder().encode(password.value).length;
    if (!length || length > 1024 || (!props.decrypt && password.value.length < 12)) {
      error.value = props.decrypt ? 'Enter a password of at most 1024 UTF-8 bytes.' : 'Use at least 12 characters and at most 1024 UTF-8 bytes.';
      return;
    }
    if (!props.decrypt && password.value !== confirmation.value) {
      error.value = 'The passwords do not match.';
      return;
    }
  }
  const result = { choice, password: choice === 'encrypt' ? password.value : '' };
  password.value = '';
  confirmation.value = '';
  emit('answer', result);
}
</script>

<template>
  <div class="modal-scrim" @click.self="answer('cancel')">
    <form class="help-modal encryption-dialog" role="dialog" aria-modal="true" aria-labelledby="encryption-title" @submit.prevent="answer('encrypt')" @keydown.esc.prevent.stop="answer('cancel')">
      <p class="panel-kicker">{{ decrypt ? 'Local decryption' : 'Local encryption' }}</p>
      <h2 id="encryption-title">{{ decrypt ? 'Decrypt file locally' : 'Encrypt before uploading?' }}</h2>
      <p class="encryption-dialog__name">{{ name }}</p>
      <p>{{ decrypt ? 'The password and the complete file are verified on your computer before a download is offered.' : 'AES-256 encryption runs on your computer. The server receives only encrypted contents. Keep your password: it cannot be recovered.' }}</p>
      <p v-if="!decrypt">File names and sizes remain visible. {{ required ? 'Encryption is required by the administrator.' : 'You can also upload without encryption.' }}</p>
      <label class="encryption-dialog__field">Password<input v-model="password" type="password" autocomplete="off" autofocus :minlength="decrypt ? undefined : 12" maxlength="1024" required></label>
      <label v-if="!decrypt" class="encryption-dialog__field">Confirm password<input v-model="confirmation" type="password" autocomplete="off" maxlength="1024" required></label>
      <p v-if="error" class="encryption-dialog__error" role="alert">{{ error }}</p>
      <div class="encryption-dialog__actions">
        <button class="header-button primary-button" type="submit">{{ decrypt ? 'Verify and decrypt' : 'Encrypt and upload' }}</button>
        <button v-if="decrypt && downloadUrl" class="header-button" type="button" @click="answer('download')">Download anyway</button>
        <button v-if="!required && !decrypt" class="header-button" type="button" @click="answer('plain')">Upload without encryption</button>
        <button class="header-button" type="button" @click="answer('cancel')">Cancel</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.encryption-dialog { width: min(540px, 100%); display: grid; gap: 14px; }
.encryption-dialog__name { margin: 0; padding: 8px 12px; border: 1px solid var(--c-line); border-radius: var(--r-sm); background: var(--c-surface-2); font-weight: 600; color: var(--c-title); overflow-wrap: anywhere; }
.encryption-dialog__field { display: grid; gap: 8px; font-size: .95rem; font-weight: 600; margin: 4px 0; }
.encryption-dialog__error { color: var(--c-danger); }
.encryption-dialog__actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
</style>
