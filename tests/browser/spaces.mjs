import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { createServer } from 'node:net';
import { chromium, expect } from '@playwright/test';

const storage = await mkdtemp(join(tmpdir(), 'wb-encryption-browser-spaces-'));
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
  await expect(page.getByRole('button', { name: 'Folder info', exact: true })).toBeVisible();
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
  browser = await chromium.launch({ channel: process.env.WB_TEST_BROWSER_CHANNEL || undefined });
  page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${port}/#/folder/1`);
  await login('browseradmin');
  for (const username of ['alice', 'bob', 'disabled']) {
    await api('admin.users.create', { username, password, role: 'user', space_enabled: username !== 'alice' });
  }
  let users = (await api('admin.users.list')).users;
  assert.equal(users.find((user) => user.username === 'alice').space.status, 'none');
  const disabled = users.find((user) => user.username === 'disabled');
  await api('admin.users.update', { user_id: disabled.id, space_enabled: false });
  await page.goto(`http://127.0.0.1:${port}/admin/#/settings`);
  await page.getByRole('button', { name: 'Spaces', exact: true }).click();
  await page.locator('label').filter({ hasText: 'Enable per-user spaces' }).locator('input').check();
  await page.locator('.primary-button').click();
  await expect(page.getByText('Settings updated.', { exact: true })).toBeVisible();
  users = (await api('admin.users.list')).users;
  const alice = users.find((user) => user.username === 'alice');
  const bob = users.find((user) => user.username === 'bob');
  assert.equal(alice.space.status, 'active');
  assert.equal(users.find((user) => user.username === 'disabled').space.status, 'disabled');
  await api('admin.settings.save', { spaces: { enabled: true, auto_create: true }, uploads: { encryption_mode: 'off' } });
  await api('admin.users.create', { username: 'optout', password, role: 'user', space_enabled: false });
  assert.equal((await api('admin.users.list')).users.find((user) => user.username === 'optout').space.status, 'none');
  await api('auth.logout', {});
  await page.goto(`http://127.0.0.1:${port}/#/folder/1`);
  await login('bob');
  await expect(page).toHaveURL(new RegExp(`#/folder/${bob.space.folder_id}$`));
  const contents = Buffer.from('A real file shared by a standard user.');
  const upload = await api('upload.init', { folder_id: bob.space.folder_id, original_name: 'shared-file.txt', size: contents.length, mime_type: 'text/plain', total_chunks: 1 });
  const session = await api('auth.session');
  const chunk = await page.request.post(`http://127.0.0.1:${port}/api/index.php?action=upload.chunk`, { multipart: {
    csrf_token: session.csrf_token, upload_token: upload.data.upload_token, chunk_index: '0',
    chunk: { name: 'chunk.part', mimeType: 'application/octet-stream', buffer: contents },
  } });
  assert.equal(chunk.ok(), true);
  await api('upload.complete', { upload_token: upload.data.upload_token });
  await page.reload();
  const fileRow = page.getByRole('row').filter({ hasText: 'shared-file.txt' });
  await expect(fileRow).toContainText('bob');
  await fileRow.click();
  const sharedResponse = page.waitForResponse((response) => response.url().includes('action=files.share.create'));
  await page.locator('.preview-modal').getByRole('button', { name: 'Share link', exact: true }).click();
  const link = await (await sharedResponse).json();
  assert.equal(link.ok, true, JSON.stringify(link));
  const publicPage = await page.request.get(link.share.url);
  assert.equal(publicPage.ok(), true);
  assert.ok((await publicPage.text()).includes('shared-file.txt'));
  await page.locator('.preview-modal').getByRole('button', { name: 'Close', exact: true }).click();
  await page.getByRole('button', { name: 'Folder info', exact: true }).click();
  await page.locator('.share-panel select').first().selectOption('alice');
  await page.getByRole('button', { name: 'Grant access', exact: true }).click();
  await expect(page.locator('.space-share-row')).toContainText('alice');
  assert.equal(await page.getByRole('button', { name: 'Rename', exact: true }).count(), 0);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await page.getByRole('button', { name: 'Logout', exact: true }).click();
  await login('alice');
  await expect(page).toHaveURL(new RegExp(`#/folder/${alice.space.folder_id}$`));
  await expect(page.getByRole('button', { name: 'Shared: bob', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Shared: bob', exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`#/folder/${bob.space.folder_id}$`));
  await page.getByRole('button', { name: 'My files', exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`#/folder/${alice.space.folder_id}$`));
  await page.goto(`http://127.0.0.1:${port}/#/folder/999999`);
  await expect(page.getByRole('alert')).toContainText('You do not have access');
  await page.getByRole('button', { name: 'Open my files', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Folder info', exact: true })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: /Menu/ }).click();
  await expect(page.getByRole('button', { name: 'Shared: bob', exact: true })).toBeVisible();
  assert.deepEqual(errors, []);
  console.log('PASS: real settings provisioning, disabled preservation, creation opt-out, user login, root sharing, shared navigation, personal Home, denied-folder recovery, mobile navigation.');
} catch (error) {
  console.error(await page?.locator('body').innerText());
  throw error;
} finally {
  await browser?.close();
  server.kill();
  await new Promise((done) => server.exitCode !== null ? done() : server.once('exit', done));
  if (resolve(storage).startsWith(resolve(tmpdir()) + sep + 'wb-encryption-browser-spaces-')) {
    await rm(storage, { recursive: true, force: true });
  }
}
