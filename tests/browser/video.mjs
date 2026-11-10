// Real native decoding through the app, share and embed UIs. The test-only
// proxy throttles video bytes without changing the application's transport.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { createServer, request } from 'node:http';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium, expect } from '@playwright/test';
import { Input, Output, Conversion, ALL_FORMATS, BlobSource, BufferTarget, WebMOutputFormat } from 'mediabunny';

const storage = await mkdtemp(join(tmpdir(), 'wb-encryption-browser-video-'));
const env = { ...process.env, WB_BROWSER_TEST_STORAGE: storage };
const fixture = spawnSync('php', ['tests/browser/runtime.php'], { env, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr + fixture.stdout);
const portProbe = createServer();
await new Promise(done => portProbe.listen(0, '127.0.0.1', done));
const phpPort = portProbe.address().port;
await new Promise(done => portProbe.close(done));
const php = spawn('php', ['-S', `127.0.0.1:${phpPort}`, '-t', '.', 'tests/browser/runtime.php'], {
  env, stdio: ['ignore', 'ignore', 'pipe'],
});
let bytesPerSecond = Infinity;
let browser;
const proxy = createServer((req, res) => {
  const upstream = request({ host: '127.0.0.1', port: phpPort, path: req.url, method: req.method, headers: req.headers }, async source => {
    res.writeHead(source.statusCode, source.headers);
    const isVideo = String(source.headers['content-type']).startsWith('video/');
    if (!isVideo || !Number.isFinite(bytesPerSecond)) return source.pipe(res);
    try {
      for await (const chunk of source) {
        for (let offset = 0; offset < chunk.length && !res.destroyed; offset += 4096) {
          const part = chunk.subarray(offset, offset + 4096);
          await delay(part.length * 1000 / bytesPerSecond);
          if (!res.destroyed) res.write(part);
        }
        if (res.destroyed) break;
      }
      res.end();
    } catch { res.destroy(); }
  });
  upstream.on('error', () => res.destroy());
  res.on('close', () => upstream.destroy());
  req.pipe(upstream);
});

try {
  await new Promise((done, reject) => {
    php.stderr.once('data', done);
    php.once('error', reject);
    php.once('exit', () => reject(new Error('PHP server exited during startup')));
  });
  await new Promise(done => proxy.listen(0, '127.0.0.1', done));
  const base = `http://127.0.0.1:${proxy.address().port}`;
  browser = await chromium.launch({ headless: true, channel: process.env.WB_TEST_BROWSER_CHANNEL || undefined });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(base);
  await page.locator('input[autocomplete="username"]').fill('browseradmin');
  await page.locator('input[autocomplete="current-password"]').fill('BrowserTestPassword123!');
  await page.locator('button[type="submit"]').click();
  await expect(page.getByRole('button', { name: 'Logout', exact: true })).toBeEnabled();
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
  await api('admin.settings.save', { uploads: { encryption_mode: 'off' } });
  // Generate a real twelve-second WebM without an external ffmpeg install.
  const recording = await page.evaluate(async () => {
    const canvas = document.createElement('canvas');
    canvas.width = 320; canvas.height = 180;
    const ctx = canvas.getContext('2d');
    const stream = canvas.captureStream(24);
    const recorder = new MediaRecorder(stream, { mimeType: 'video/webm;codecs=vp8', videoBitsPerSecond: 700000 });
    const chunks = [];
    recorder.ondataavailable = event => chunks.push(event.data);
    const stopped = new Promise(resolve => { recorder.onstop = resolve; });
    const animation = setInterval(() => {
      for (let y = 0; y < 180; y += 12) for (let x = 0; x < 320; x += 12) {
        ctx.fillStyle = `hsl(${Math.random() * 360},70%,50%)`;
        ctx.fillRect(x, y, 12, 12);
      }
    }, 1000 / 24);
    recorder.start(250);
    await new Promise(resolve => setTimeout(resolve, 12000));
    recorder.stop();
    await stopped;
    clearInterval(animation);
    stream.getTracks().forEach(track => track.stop());
    const blob = new Blob(chunks, { type: 'video/webm' });
    return new Promise(resolve => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result.split(',')[1]);
      reader.readAsDataURL(blob);
    });
  });
  // Finalize the recording's duration/seek index by remuxing (no re-encode).
  const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(new Blob([Buffer.from(recording, 'base64')])) });
  const target = new BufferTarget();
  const output = new Output({ format: new WebMOutputFormat(), target });
  const conversion = await Conversion.init({ input, output });
  await conversion.execute();
  input.dispose();
  const video = Buffer.from(target.buffer);
  const init = await api('upload.init', { folder_id: 1, original_name: 'Buffer test.webm', size: video.length, mime_type: 'video/webm', total_chunks: 1 });
  const session = await api('auth.session');
  const uploaded = await page.request.post(`${base}/api/index.php?action=upload.chunk`, { multipart: {
    csrf_token: session.csrf_token, upload_token: init.data.upload_token, chunk_index: '0',
    chunk: { name: 'chunk.part', mimeType: 'application/octet-stream', buffer: video },
  } });
  assert.equal(uploaded.ok(), true, await uploaded.text());
  const completed = await api('upload.complete', { upload_token: init.data.upload_token });
  const share = (await api('files.share.create', { file_id: completed.file.id, allow_embed: true })).share;
  await page.reload();
  bytesPerSecond = video.length / 12 * 1.25;
  console.log(`Throttling ${video.length} bytes to ${Math.round(bytesPerSecond)} B/s (1.25x average media bitrate).`);
  await page.addInitScript(() => {
    window.videoFetches = 0;
    const original = window.fetch;
    window.fetch = (...args) => {
      if (/(?:files|share)\.stream/.test(String(args[0]))) window.videoFetches++;
      return original(...args);
    };
  });

  async function checkPlayback(target, playButton) {
    await target.waitForFunction(() => document.querySelector('video')?.readyState >= 1);
    const startedAt = Date.now();
    await playButton.click();
    await expect(target.locator('.media-player__buffer')).toBeVisible();
    await expect(target.locator('.media-player__buffer')).toHaveText('');
    await expect(target.locator('.media-player__buffer button, .media-player__buffer progress')).toHaveCount(0);
    if (process.env.WB_VIDEO_SCREENSHOT && target === page) {
      await target.screenshot({ path: process.env.WB_VIDEO_SCREENSHOT });
    }
    await target.waitForFunction(() => document.querySelector('video')?.currentTime > 0.25, undefined, { timeout: 20000 });
    const startupMs = Date.now() - startedAt;
    assert.ok(startupMs < 11000, `Startup should remain bounded, took ${startupMs}ms`);
    await target.evaluate(() => {
      window.unexpectedStops = 0;
      const video = document.querySelector('video');
      video.addEventListener('waiting', () => window.unexpectedStops++);
      video.addEventListener('pause', () => window.unexpectedStops++);
    });
    await target.waitForFunction(() => document.querySelector('video')?.currentTime > 4, undefined, { timeout: 15000 });
    assert.equal(await target.evaluate(() => window.unexpectedStops), 0, 'Playback should stay continuous after the initial buffer');
    console.log({ startupMs, ...await target.locator('video').evaluate(video => {
      const quality = video.getVideoPlaybackQuality();
      return { surface: location.pathname, decodedFrames: quality.totalVideoFrames, droppedFrames: quality.droppedVideoFrames };
    }) });
    await playButton.click();
    await expect(target.locator('.media-player__buffer')).toBeHidden();
    assert.equal(await target.locator('video').evaluate(video => video.paused), true);
    assert.equal(await target.evaluate(() => window.videoFetches || 0), 0, 'No duplicate JS media fetch');
  }
  await page.reload();
  await page.locator('[data-entry-surface="true"]').filter({ hasText: 'Buffer test.webm' }).first().click();
  await checkPlayback(page, page.locator('.media-player__btn--play'));
  await page.getByRole('slider', { name: 'Seek', exact: true }).fill('8');
  await page.locator('.media-player__btn--play').click();
  await page.waitForFunction(() => document.querySelector('video')?.currentTime > 8.2);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(page.locator('video')).toHaveCount(0);
  for (const url of [share.url, share.embed_url]) {
    const shared = await browser.newPage();
    shared.on('pageerror', error => errors.push(error.message));
    await shared.goto(url);
    await expect(shared.locator('[data-share-view-count]')).toHaveCount(0);
    await api('admin.settings.save', { access: { public_view_counts_enabled: true } });
    await shared.reload();
    await expect(shared.locator('[data-share-view-count]')).toBeVisible();
    await checkPlayback(shared, shared.locator('[data-mp="play"]'));
    await api('admin.settings.save', { access: { public_view_counts_enabled: false } });
    await shared.reload();
    await expect(shared.locator('[data-share-view-count]')).toHaveCount(0);
    await shared.close();
  }
  assert.deepEqual(errors, []);
  console.log('PASS: spinner-only automatic buffering, continuous throttled playback, pause, seek, close, public share/embed view-count visibility.');
} finally {
  await browser?.close();
  proxy.closeAllConnections();
  await new Promise(done => proxy.close(done));
  php.kill();
  await new Promise(done => php.exitCode !== null ? done() : php.once('exit', done));
  // mkdtemp returned this exact test-owned directory under the system temp root.
  assert.ok(resolve(storage).startsWith(resolve(tmpdir()) + sep + 'wb-encryption-browser-video-'));
  await rm(storage, { recursive: true, force: true });
}
