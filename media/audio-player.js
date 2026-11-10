import { attachAudioVisualizer } from './audioVisualizer.js';

const media = document.getElementById('share-media');
const root = media?.closest('.media-player');
const canvas = root?.querySelector('[data-mp="wave"]');
if (canvas) {
  const visualizer = attachAudioVisualizer(media, canvas);
  const prepare = () => visualizer.prepare();
  const button = root.querySelector('[data-mp="play"]');
  button?.addEventListener('click', prepare, { capture: true });
  window.addEventListener('pagehide', (event) => {
    if (event.persisted) return;
    button?.removeEventListener('click', prepare, { capture: true });
    visualizer.dispose();
  });
}
