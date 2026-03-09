import { createApp } from 'vue';
import App from './App.vue';
import './styles.css';

const bootstrapNode = document.getElementById('wb-bootstrap');

if (bootstrapNode && !window.WB_BOOTSTRAP) {
  try {
    window.WB_BOOTSTRAP = JSON.parse(bootstrapNode.textContent || '{}');
  } catch (error) {
    window.WB_BOOTSTRAP = {};
  }
}

createApp(App).mount('#app');
