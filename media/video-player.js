import { attachVideoPlayback } from './videoPlayback.js';

const media = document.querySelector('video#share-media');
if (media) {
  const root = media.closest('.media-player');
  const button = root.querySelector('[data-mp="play"]');
  media.playsInline = true;
  media.wbVideoPlayback = attachVideoPlayback(media, {
    onState: ({ wanted }) => {
      root.classList.toggle('is-playing', wanted);
      root.classList.toggle('is-paused', !wanted);
      button?.setAttribute('aria-label', wanted ? 'Pause' : 'Play');
    },
  });
  window.addEventListener('pagehide', (event) => {
    if (event.persisted) return;
    media.wbVideoPlayback.dispose();
  });
}
