<script setup>
import { computed } from 'vue';
import { formatBytesPlain } from '../lib/videoCompressionPolicy.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  // 'optional' asks the user; 'required' only confirms and explains.
  mode: { type: String, required: true },
  // [{ name, size }]
  files: { type: Array, default: () => [] },
});

const emit = defineEmits(['confirm', 'dismiss']);

const totalBytes = computed(() => props.files.reduce((sum, file) => sum + (file.size || 0), 0));
const isRequired = computed(() => props.mode === 'required');
const fileListLabel = computed(() => props.files.length === 1 ? props.files[0].name : `${props.files.length} videos`);
</script>

<template>
  <div v-if="open" class="modal-scrim" @click.self="emit('dismiss')">
    <section class="help-modal video-compression-modal">
      <p class="panel-kicker">{{ isRequired ? 'Video optimization required' : 'Video optimization' }}</p>
      <h2>{{ isRequired ? 'Optimize videos before upload?' : 'Make these videos smaller?' }}</h2>

      <p v-if="isRequired">
        This server requires videos to be optimized before they are uploaded.
        {{ fileListLabel }} ({{ formatBytesPlain(totalBytes) }}) will be compressed locally on this device
        to a smaller MP4 (H.264/AAC) that satisfies the server's policy.
      </p>
      <p v-else>
        {{ fileListLabel }} ({{ formatBytesPlain(totalBytes) }}) can be compressed locally on this device
        before uploading. Nothing is sent to the server until compression finishes,
        and the upload will use less storage and bandwidth.
      </p>

      <ul v-if="files.length > 0" class="video-compression-modal__files">
        <li v-for="entry in files" :key="entry.name">
          <span>{{ entry.name }}</span>
          <span>{{ formatBytesPlain(entry.size) }}</span>
        </li>
      </ul>

      <p class="panel-meta">
        Compression happens entirely in this browser. The server independently verifies
        every optimized video, so a file that does not match the policy will be rejected.
      </p>

      <div class="video-compression-modal__actions">
        <button type="button" class="video-compression-modal__primary" @click="emit('confirm')">
          {{ isRequired ? 'Optimize & upload' : 'Compress & upload' }}
        </button>
        <button type="button" @click="emit('dismiss')">
          {{ isRequired ? 'Cancel upload' : 'Upload originals' }}
        </button>
      </div>
    </section>
  </div>
</template>
