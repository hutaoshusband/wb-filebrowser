import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const styles = readFileSync(resolve(import.meta.dirname, '../../frontend/src/styles.css'), 'utf8');
const sharePage = readFileSync(resolve(import.meta.dirname, '../../share/index.php'), 'utf8');

describe('shared control styles', () => {
  it('use press-in hover motion instead of the old gold gradient treatment', () => {
    expect(styles).toContain('--button-press-shadow:');
    expect(styles).toContain('transform:translateY(1px)');
    expect(styles).not.toContain('--button-face-accent:linear-gradient');
    expect(styles).not.toContain('transform: translateY(-1px) !important;');
  });

  it('includes mobile drawer and non-stretching preview image rules', () => {
    expect(styles).toContain('.wb-shell.is-mobile-nav-open');
    expect(styles).toContain('.preview-frame__image');
    expect(styles).toContain('object-fit:contain');
  });

  it('keeps shared code previews vertically scrollable inside the preview frame', () => {
    expect(styles).toContain('.share-text-preview{width:100%;min-height:100%;display:grid;grid-template-rows:minmax(0,1fr) auto}');
    expect(sharePage).toContain('.preview-frame:has(.share-text-preview) {');
    expect(sharePage).toContain('overflow: auto;');
    expect(sharePage).toContain('overflow-x: auto;');
    expect(sharePage).toContain('overflow-y: visible;');
    expect(sharePage).toContain('scrollbar-color: rgba(250, 204, 21, 0.88) rgba(7, 15, 28, 0.96);');
    expect(sharePage).toContain('::-webkit-scrollbar-thumb');
    expect(sharePage).toContain('background: linear-gradient(180deg, rgba(250, 204, 21, 0.95), rgba(121, 192, 255, 0.82));');
  });
});
