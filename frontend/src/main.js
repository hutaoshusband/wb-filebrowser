import { createApp } from 'vue';
import App from './App.vue';
import LocalDecryption from './components/LocalDecryption.vue';
import './styles.css';

const bootstrapNode = document.getElementById('wb-bootstrap');

if (bootstrapNode && !window.WB_BOOTSTRAP) {
  try {
    window.WB_BOOTSTRAP = JSON.parse(bootstrapNode.textContent || '{}');
  } catch (error) {
    window.WB_BOOTSTRAP = {};
  }
}

const decryptionNode = document.getElementById('wb-local-decryption');
if (decryptionNode) {
  createApp(LocalDecryption, { url: decryptionNode.dataset.url, name: decryptionNode.dataset.name }).mount(decryptionNode);
} else if (document.getElementById('app')) {
  createApp(App).mount('#app');
}
