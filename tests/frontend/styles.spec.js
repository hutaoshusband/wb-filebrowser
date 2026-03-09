import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const styles = readFileSync(resolve(import.meta.dirname, '../../frontend/src/styles.css'), 'utf8');

describe('shared control styles', () => {
  it('use press-in hover motion instead of the old gold gradient treatment', () => {
    expect(styles).toContain('--button-press-shadow:');
    expect(styles).toContain('transform:translateY(1px)');
    expect(styles).not.toContain('--button-face-accent:linear-gradient');
    expect(styles).not.toContain('transform: translateY(-1px) !important;');
  });
});
