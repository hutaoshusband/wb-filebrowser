import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { chromium, expect } from '@playwright/test';

const storage = await mkdtemp(join(tmpdir(), 'wb-encryption-browser-audio-'));
const env = { ...process.env, WB_BROWSER_TEST_STORAGE: storage };
const fixture = spawnSync('php', ['tests/browser/runtime.php'], { env, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr + fixture.stdout);
const probe = createServer();
await new Promise((done) => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port;
await new Promise((done) => probe.close(done));
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', 'tests/browser/runtime.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });
let browser;
let page;
const password = 'BrowserTestPassword123!';
async function login(username) {
  await page.locator('input[autocomplete="username"]').fill(username);
  await page.locator('input[autocomplete="current-password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await expect(page.getByRole('button', { name: 'Logout', exact: true })).toBeEnabled();
}
async function api(action, body) {
  return page.evaluate(async ({ action, body }) => {
    const session = await (await fetch('/api/index.php?action=auth.session')).json();
    const response = await fetch(`/api/index.php?action=${action}`, body === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...body, csrf_token: session.csrf_token }),
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(`${action}: ${JSON.stringify(payload)}`);
    return payload;
  }, { action, body });
}
try {
  await new Promise((done, reject) => {
    server.stderr.once('data', done);
    server.once('error', reject);
    server.once('exit', () => reject(new Error('PHP server exited during startup')));
  });
  browser = await chromium.launch({ headless: process.env.WB_TEST_VISIBLE !== '1', args: ['--mute-audio'], slowMo: 100, channel: process.env.WB_TEST_BROWSER_CHANNEL || undefined });
  page = await browser.newPage();
  await page.addInitScript(() => {
    window.audioChecks = { fetches: 0, decodes: 0, contexts: 0, closed: 0, draws: 0 };
    const fetch = window.fetch;
    window.fetch = (...args) => {
      if (/(?:files|share)\.stream/.test(String(args[0]))) window.audioChecks.fetches++;
      return fetch(...args);
    };
    for (const name of ['AudioContext', 'OfflineAudioContext']) {
      const Original = window[name];
      if (!Original) continue;
      Original.prototype.decodeAudioData = () => { window.audioChecks.decodes++; throw new Error('Full-file decode forbidden'); };
      window[name] = class extends Original {
        constructor(...args) { super(...args); window.audioChecks.contexts++; }
        close() { window.audioChecks.closed++; return super.close(); }
      };
    }
    const fillRect = CanvasRenderingContext2D.prototype.fillRect;
    CanvasRenderingContext2D.prototype.fillRect = function(...args) {
      if (this.canvas.matches('.media-player__waveform-canvas, [data-mp="wave"]')) window.audioChecks.draws++;
      return fillRect.apply(this, args);
    };
  });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${port}/#/folder/1`);
  await login('browseradmin');
  await expect(page.getByRole('button', { name: 'Logout', exact: true })).toBeVisible();
  await api('admin.settings.save', { uploads: { encryption_mode: 'off' } });
  const samples = 44100 * 8;
  const wav = Buffer.alloc(44 + samples * 2);
  wav.write('RIFF'); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
  wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
  wav.writeUInt32LE(44100, 24); wav.writeUInt32LE(88200, 28); wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
  wav.write('data', 36); wav.writeUInt32LE(samples * 2, 40);
  // A changing mix across the spectrum makes the silent visual test useful.
  const frequencies = [140, 400, 900, 1800, 3200, 5000, 7200, 9600, 12000, 14500];
  for (let i=0; i<samples; i++) {
    const sample = frequencies.reduce((sum, frequency, band) => sum + Math.sin(i * frequency * 2 * Math.PI / 44100)
      * (0.03 + 0.025 * Math.sin(i / (4000 + band * 1200))), 0);
    wav.writeInt16LE(Math.round(sample * 32767), 44 + i * 2);
  }
  const upload = await api('upload.init', { folder_id: 1, original_name: 'Audio animation test.wav', size: wav.length, mime_type: 'audio/wav', total_chunks: 1 });
  const session = await api('auth.session');
  const chunk = await page.request.post(`http://127.0.0.1:${port}/api/index.php?action=upload.chunk`, { multipart: {
    csrf_token: session.csrf_token, upload_token: upload.data.upload_token, chunk_index: '0',
    chunk: { name: 'chunk.part', mimeType: 'application/octet-stream', buffer: wav },
  } });
  assert.equal(chunk.ok(), true);
  const completed = await api('upload.complete', { upload_token: upload.data.upload_token });
  await page.reload();
  await page.locator('[data-entry-surface="true"]').filter({ hasText: 'Audio animation test.wav' }).first().click();
  const canvas = page.locator('.media-player__waveform-canvas');
  await expect(canvas).toBeVisible();
  const bounds = await canvas.boundingBox();
  const controls = await page.locator('.media-player__bar').boundingBox();
  assert.ok(bounds.y + bounds.height < controls.y, 'Visualization belongs above the controls');
  assert.ok(bounds.height >= 150, 'Visualization occupies the central stage');
  async function assertIdle() {
    const before = await page.evaluate(() => window.audioChecks.draws);
    await page.waitForTimeout(250);
    assert.equal(await page.evaluate(() => window.audioChecks.draws), before, 'Paused canvas must not animate');
  }
  await assertIdle();
  await page.locator('.preview-modal').getByRole('button', { name: 'Play', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('audio')?.currentTime > 0.5);
  const before = await page.evaluate(() => window.audioChecks.draws);
  await page.waitForTimeout(250);
  assert.ok(await page.evaluate(() => window.audioChecks.draws) > before, 'Canvas animates during playback');
  await page.locator('.preview-modal').getByRole('button', { name: 'Pause', exact: true }).click();
  await assertIdle();
  await page.getByRole('slider', { name: 'Seek', exact: true }).fill('5');
  await page.waitForFunction(() => Math.abs(document.querySelector('audio').currentTime - 5) < 0.2);
  await page.locator('.preview-modal').getByRole('button', { name: 'Play', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('audio')?.currentTime > 5.3);
  await page.screenshot({ path: '/tmp/wb-current-audio-preview.png' });
  await page.locator('.preview-modal').getByRole('button', { name: 'Close', exact: true }).click();
  assert.equal(await page.evaluate(() => window.audioChecks.contexts - window.audioChecks.closed), 0, 'Closing releases the audio context');
  for (let i=0; i<3; i++) {
    await page.locator('[data-entry-surface="true"]').filter({ hasText: 'Audio animation test.wav' }).first().click();
    await page.locator('.preview-modal').getByRole('button', { name: 'Play', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('audio')?.currentTime > 0.1);
    await page.locator('.preview-modal').getByRole('button', { name: 'Close', exact: true }).click();
  }
  assert.equal(await page.evaluate(() => window.audioChecks.contexts - window.audioChecks.closed), 0, 'Rapid switches release every context');
  assert.equal(await page.evaluate(() => window.audioChecks.fetches), 0, 'No extra full-file fetch');
  assert.equal(await page.evaluate(() => window.audioChecks.decodes), 0, 'No full-file decode');
  const share = (await api('files.share.create', { file_id: completed.file.id, allow_embed: true })).share;
  for (const url of [share.url, share.embed_url]) {
    const shared = await browser.newPage();
    shared.on('pageerror', error => errors.push(error.message));
    await shared.goto(url);
    const sharedCanvas = shared.locator('[data-mp="wave"]');
    await expect(sharedCanvas).toBeVisible();
    assert.ok(await sharedCanvas.evaluate(c => Boolean(c.closest('.media-player__stage'))));
    await shared.locator('[data-mp="play"]').click();
    await shared.waitForFunction(() => document.querySelector('audio')?.currentTime > 0.3);
    await shared.waitForFunction(() => {
      const c = document.querySelector('[data-mp="wave"]');
      const pixels = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
      let filled = 0; for (let i=3; i<pixels.length; i+=4) if (pixels[i]) filled++;
      return filled > c.width * 2;
    });
    await shared.locator('[data-mp="play"]').click();
    const still = await sharedCanvas.evaluate(c => c.toDataURL());
    await shared.waitForTimeout(250);
    assert.equal(await sharedCanvas.evaluate(c => c.toDataURL()), still);
    await shared.close();
  }
  // Keep the application preview ready for manual testing.
  await page.locator('[data-entry-surface="true"]').filter({ hasText: 'Audio animation test.wav' }).first().click();
  assert.deepEqual(errors, []);
  console.log('Preview URL:', page.url());
  console.log('PASS: current build; central animation; idle/pause stop; playback and seek; repeated preview cleanup; zero full-file fetches or decodes; share and embed animation.');
  if (process.env.WB_TEST_KEEP_OPEN === '1') await new Promise(() => {});
} catch (error) { console.error(await page?.locator('body').innerText()); throw error; } finally {
  await browser?.close();
  server.kill();
  await rm(storage, { recursive: true, force: true });
}
