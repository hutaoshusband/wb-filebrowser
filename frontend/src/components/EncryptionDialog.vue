<script setup>
import { ref } from 'vue';

const props = defineProps({ required: Boolean, decrypt: Boolean, name: String });
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
  <div class="encryption-overlay" @keydown.esc.prevent.stop="answer('cancel')">
    <form class="encryption-dialog" role="dialog" aria-modal="true" aria-labelledby="encryption-title" @submit.prevent="answer('encrypt')">
      <h2 id="encryption-title">{{ decrypt ? 'Decrypt file locally' : 'Encrypt before uploading?' }}</h2>
      <p>{{ name }}</p>
      <p>{{ decrypt ? 'The password and the complete file are verified on your computer before a download is offered.' : 'AES-256 encryption runs on your computer. The server receives only encrypted contents. Keep your password: it cannot be recovered.' }}</p>
      <p v-if="!decrypt">File names and sizes remain visible. {{ required ? 'Encryption is required by the administrator.' : 'You can also upload without encryption.' }}</p>
      <label>Password<input v-model="password" type="password" autocomplete="off" autofocus :minlength="decrypt ? undefined : 12" maxlength="1024" required></label>
      <label v-if="!decrypt">Confirm password<input v-model="confirmation" type="password" autocomplete="off" maxlength="1024" required></label>
      <p v-if="error" role="alert">{{ error }}</p>
      <div class="quick-actions">
        <button class="header-button primary-button" type="submit">{{ decrypt ? 'Verify and decrypt' : 'Encrypt and upload' }}</button>
        <button v-if="!required && !decrypt" class="header-button" type="button" @click="answer('plain')">Upload without encryption</button>
        <button class="header-button" type="button" @click="answer('cancel')">Cancel</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.encryption-overlay { position: fixed; inset: 0; z-index: 10000; background: #000b; display: grid; place-items: center; padding: 24px; }
.encryption-dialog { width: min(540px, 100%); max-height: 90vh; overflow: auto; padding: 28px; border: 1px solid #64748b; border-radius: 16px; background: #151923; color: #f1f5f9; }
.encryption-dialog label { display: grid; gap: 8px; margin: 16px 0; }
.encryption-dialog input { width: 100%; padding: 10px; color: #f1f5f9; background: #0f172a; border: 1px solid #64748b; border-radius: 6px; }
</style>
