import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

const storage = await mkdtemp(join(tmpdir(), 'wb-encryption-browser-'));
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
try {
  await new Promise((done, reject) => {
    server.stderr.once('data', done);
    server.once('error', reject);
    server.once('exit', () => reject(new Error('PHP server exited during startup')));
  });
  browser = await chromium.launch({ channel: process.env.WB_TEST_BROWSER_CHANNEL || undefined });
  page = await browser.newPage();
  page.on('console', (message) => { if (message.type() === 'error') console.error(message.text()); });
  page.on('requestfailed', (request) => console.error('Request failed:', request.url(), request.failure()?.errorText));
  const requests = [];
  page.on('request', (request) => { if (request.method() === 'POST') requests.push({ url: request.url(), body: request.postDataBuffer() }); });
  await page.goto(`http://127.0.0.1:${port}/`);
  await page.locator('input[autocomplete="username"]').fill('browseradmin');
  await page.locator('input[autocomplete="current-password"]').fill('BrowserTestPassword123!');
  await page.locator('button[type="submit"]').click();
  await page.locator('button:not([disabled])').filter({ hasText: /^Upload$/ }).waitFor();
  const content = Buffer.from('Private browser round trip contents\n'.repeat(200));
  await page.locator('input[type="file"]').setInputFiles({ name: 'private.txt', mimeType: 'text/plain', buffer: content });
  const dialog = page.getByRole('dialog');
  await dialog.waitFor();
  assert.equal(requests.some((request) => request.url.includes('upload.init')), false);
  await dialog.locator('input').nth(0).fill('EncryptionTestPassword🔐123!');
  await dialog.locator('input').nth(1).fill('EncryptionTestPassword🔐123!');
  const completeResponse = page.waitForResponse((response) => response.url().includes('action=upload.complete'));
  await dialog.getByRole('button', { name: 'Encrypt and upload', exact: true }).click();
  const completed = await (await completeResponse).json();
  await page.getByText('Uploaded file by browseradmin: private.txt.', { exact: true }).waitFor();
  const uploadRequests = requests.filter((request) => request.url.includes('upload.'));
  assert.ok(uploadRequests.length >= 3);
  for (const request of uploadRequests) {
    assert.equal(request.body?.includes(content) ?? false, false, 'Plaintext uploaded');
    assert.equal(request.body?.includes(Buffer.from('EncryptionTestPassword')) ?? false, false, 'Encryption password uploaded');
  }
  const storedResponse = await page.request.get(new URL(completed.file.download_url, page.url()).href);
  const stored = await storedResponse.body();
  assert.equal(stored.subarray(0, 8).toString(), 'WBENC001');
  assert.equal(stored.includes(content), false, 'Stored file contains plaintext');
  await page.getByText('private.txt', { exact: true }).first().click();
  await page.locator('.preview-modal').getByRole('button', { name: 'Download', exact: true }).click();
  await page.getByRole('dialog').locator('input').fill('incorrect password');
  await page.getByRole('button', { name: 'Verify and decrypt' }).click();
  await page.getByText('Incorrect password or damaged encrypted file.', { exact: true }).waitFor();
  assert.equal(await page.getByRole('link', { name: 'Save decrypted file' }).count(), 0);
  await page.getByRole('button', { name: 'Retry password' }).click();
  await page.getByRole('dialog').locator('input').fill('EncryptionTestPassword🔐123!');
  await page.getByRole('button', { name: 'Verify and decrypt' }).click();
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Save decrypted file' }).click();
  const download = await downloadPromise;
  assert.deepEqual(await readFile(await download.path()), content);
  console.log('PASS: real PHP/SQLite upload, ciphertext-only requests, wrong-password rejection, verified downloaded bytes.');
} catch (error) {
  console.error(await page?.locator('body').innerText());
  throw error;
} finally {
  await browser?.close();
  server.kill();
  await new Promise((done) => server.exitCode !== null ? done() : server.once('exit', done));
  if (resolve(storage).startsWith(resolve(tmpdir()) + '\\wb-encryption-browser-') || resolve(storage).startsWith(resolve(tmpdir()) + '/wb-encryption-browser-')) {
    await rm(storage, { recursive: true, force: true });
  }
}
