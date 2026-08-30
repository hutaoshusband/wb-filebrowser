import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const styles = readFileSync(resolve(import.meta.dirname, '../../frontend/src/styles.css'), 'utf8');
const sharePage = readFileSync(resolve(import.meta.dirname, '../../share/index.php'), 'utf8');
const installPage = readFileSync(resolve(import.meta.dirname, '../../install/index.php'), 'utf8');

describe('forum-matched design tokens', () => {
  it('uses the CleanWBB dark palette and Hanken Grotesk', () => {
    expect(styles).toContain("--c-bg:#141416");
    expect(styles).toContain("--c-accent:#30d158");
    expect(styles).toContain("--c-text:#e1e1e6");
    expect(styles).toContain("--c-text-dim:#8e8e93");
    expect(styles).toContain("@font-face");
    expect(styles).toContain("'Hanken Grotesk'");
    expect(styles).toContain("./fonts/hanken-grotesk-latin.woff2");
  });

  it('pins the sidebar layout while the main shell owns vertical scrolling', () => {
    expect(styles).toContain('.wb-shell{display:grid;grid-template-columns:260px minmax(0,1fr);height:100vh;overflow:hidden;color:var(--c-text)}');
    expect(styles).toContain('.wb-main{position:relative;padding:18px 22px 22px;display:flex;flex-direction:column;gap:16px;min-width:0;min-height:0;overflow-y:auto}');
    expect(styles).toContain('@media (max-width:980px){');
    expect(styles).toContain('.wb-shell.is-mobile-nav-open');
    expect(styles).toContain('.preview-frame__image');
    expect(styles).toContain('object-fit:contain');
    expect(styles).toContain('overscroll-behavior-y:none');
  });

  it('keeps shared checkbox controls and summary-row helpers', () => {
    expect(styles).toContain('.checkbox-control{');
    expect(styles).toContain('.checkbox-control__indicator');
    expect(styles).toContain('.admin-summary-row--two');
    expect(styles).toContain('.admin-summary-row--three');
    expect(styles).toContain('.admin-summary-row--four');
  });

  it('keeps shared code previews vertically scrollable inside the preview frame', () => {
    expect(styles).toContain('.share-text-preview{width:100%;min-height:100%;display:grid;grid-template-rows:minmax(0,1fr) auto}');
    expect(sharePage).toContain('.preview-frame:has(.share-text-preview) {');
    expect(sharePage).toContain('overflow: auto;');
    expect(sharePage).toContain('overflow-x: auto;');
    expect(sharePage).toContain('overflow-y: visible;');
  });

  it('uses the shared checkbox markup in the share and install forms', () => {
    expect(sharePage).toContain('checkbox-control');
    expect(installPage).toContain('checkbox-control');
  });

  it('renders the public direct-link field from the shared file stream URL', () => {
    expect(sharePage).toContain("value=\"<?= wb_h(\$file['direct_url']) ?>\"");
    expect(sharePage).toContain("href=\"<?= wb_h(\$file['direct_url']) ?>\"");
    expect(sharePage).toContain('Open direct link');
    expect(sharePage).not.toContain("value=\"<?= wb_h(\$share['url']) ?>\"");
  });
});

describe('custom media player', () => {
  it('ships themed player styles for both surfaces', () => {
    expect(styles).toContain('.media-player{');
    expect(styles).toContain('.media-player__bar');
    expect(styles).toContain('.media-player--video');
    expect(styles).toContain('.media-player__btn--play{background:var(--c-accent)');
  });

  it('renders the custom player in the Vue preview and drops native controls', () => {
    const app = readFileSync(resolve(import.meta.dirname, '../../frontend/src/App.vue'), 'utf8');
    expect(app).toContain('class="media-player media-player--video"');
    expect(app).toContain('class="media-player media-player--audio"');
    expect(app).toContain('ref="mediaRef"');
    expect(app).toContain('togglePlay');
    expect(app).not.toContain('<video controls>');
    expect(app).not.toContain('<audio controls>');
  });

  it('renders the custom player on the share page and wires it with vanilla JS', () => {
    expect(sharePage).toContain('class="media-player media-player--video"');
    expect(sharePage).toContain('class="media-player media-player--audio"');
    expect(sharePage).toContain('id="share-media"');
    expect(sharePage).toContain('data-mp="play"');
    expect(sharePage).toContain('data-mp="seek"');
    expect(sharePage).toContain('data-mp="volume"');
    expect(sharePage).not.toContain('<video controls>');
    expect(sharePage).not.toContain('<audio controls>');
  });
});
