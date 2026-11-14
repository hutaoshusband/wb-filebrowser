import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readdir, symlink, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, basename, dirname } from 'node:path';
import { createServer } from 'node:net';
import http from 'node:http';

// Run in a disposable checkout: the dev router intentionally uses that
// checkout's storage, whereas the application fixture has its own temp root.
const root = resolve('.');
assert.equal(process.env.WB_DISPOSABLE_TEST_CHECKOUT, '1',
  'Run this HTTP suite in a disposable checkout, with WB_DISPOSABLE_TEST_CHECKOUT=1.');
const phpArgs = [];
const storage = await mkdtemp(join(tmpdir(), 'wb-encryption-browser-security-'));
const env = { ...process.env, WB_BROWSER_TEST_STORAGE: storage };
const fixture = spawnSync('php', [...phpArgs, 'tests/browser/runtime.php'], { env, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr + fixture.stdout);

async function server(router, documentRoot = root) {
  const probe = createServer();
  await new Promise(done => probe.listen(0, '127.0.0.1', done));
  const port = probe.address().port;
  await new Promise(done => probe.close(done));
  const child = spawn('php', [...phpArgs, '-S', `127.0.0.1:${port}`, '-t', documentRoot, router],
    { env, stdio: ['ignore', 'ignore', 'pipe'] });
  let errors = '';
  child.stderr.on('data', chunk => { errors += chunk; });
  await new Promise((done, reject) => {
    child.stderr.once('data', done);
    child.once('error', reject);
    child.once('exit', () => reject(new Error(errors)));
  });
  return { child, base: `http://127.0.0.1:${port}`, errors: () => errors };
}

// http.request preserves raw dot segments and encoded paths for router tests.
function request(base, path, method = 'GET', headers = {}, data) {
  return new Promise((done, reject) => {
    const req = http.request(base, { path, method, headers }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => done({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks) }));
    });
    req.on('error', reject);
    req.end(data);
  });
}

const apiServer = await server('tests/browser/runtime.php');
try {
  let cookie = '';
  let csrf = '';
  async function api(action, method = 'GET', data, token = csrf) {
    const body = data === undefined ? undefined : JSON.stringify({ csrf_token: token, ...data });
    const response = await request(apiServer.base, `/api/index.php?action=${action}`, method,
      { Cookie: cookie, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body);
    if (response.headers['set-cookie']) cookie = response.headers['set-cookie'][0].split(';')[0];
    const payload = response.body.length ? JSON.parse(response.body) : {};
    if (payload.csrf_token) csrf = payload.csrf_token;
    return { ...response, payload };
  }
  await api('auth.session');
  assert.equal((await api('auth.login', 'POST', { username: 'browseradmin', password: 'BrowserTestPassword123!' })).status, 200);
  const initialBackups = await readdir(join(storage, 'backups')).catch(() => []);
  for (const method of ['GET', 'HEAD', 'PUT']) {
    assert.equal((await api('dbbackup.create', method)).status, 405, `${method} must not create backups`);
    assert.equal((await api('auth.logout', method)).status, 405, `${method} must not log out`);
  }
  assert.deepEqual(await readdir(join(storage, 'backups')).catch(() => []), initialBackups);
  assert.equal((await api('auth.session')).payload.user.username, 'browseradmin');
  assert.equal((await api('dbbackup.create', 'POST', {}, 'invalid')).status, 400);
  assert.equal((await api('auth.logout', 'POST', {}, 'invalid')).status, 400);
  const absent = await request(apiServer.base, '/api/index.php?action=dbbackup.create', 'POST',
    { Cookie: cookie, 'Content-Type': 'application/json' }, '{}');
  assert.equal(absent.status, 400);
  assert.equal((await api('dbbackup.create', 'POST', {})).payload.ok, true);
  assert.equal((await api('auth.logout', 'POST', {})).payload.ok, true);
  assert.equal((await api('auth.session')).payload.user, null);
} finally {
  apiServer.child.kill();
}

await mkdir(join(root, 'storage'), { recursive: true });
await writeFile(join(root, 'storage', 'private.txt'), 'private-test-data');
await writeFile(join(root, 'storage', 'private.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
await writeFile(join(root, 'media', 'blocked.PHP'), '<?php echo "must-not-execute";');
await unlink(join(root, 'media', 'private-link')).catch(error => { if (error.code !== 'ENOENT') throw error; });
await symlink(join(root, 'storage'), join(root, 'media', 'private-link'), process.platform === 'win32' ? 'junction' : 'dir');
for (const [documentRoot, prefix] of [[root, ''], [dirname(root), `/${basename(root)}`]]) {
  const dev = await server(resolve('dev-router.php'), documentRoot);
  try {
    for (const path of ['/storage/private.txt', '/storage/.gitignore', '/app/helpers.php', '/tests/browser/runtime.php',
      '/tools/phpunit.phar', '/.git/config', '/composer.json', '/unknown', '/media/../storage/private.txt',
      '/media/%2e%2e/storage/private.txt', '/media/%252e%252e/storage/private.txt',
      '/media%2f../storage/private.txt', '/media/%5c../storage/private.txt', '/media/%00.svg', '/media/%zz.svg',
      '/media/blocked.PHP', '/media/private-link/private.svg']) {
      assert.equal((await request(dev.base, prefix + path)).status, 404, path);
    }
    const js = await request(dev.base, prefix + '/media/blocked-countdown.js?v=1');
    assert.equal(js.status, 200);
    assert.match(js.headers['content-type'], /javascript/);
    assert.equal(js.headers['cross-origin-embedder-policy'], 'require-corp');
    assert.ok(js.body.length > 0);
    const head = await request(dev.base, prefix + '/media/blocked-countdown.js', 'HEAD');
    assert.equal(head.status, 200);
    assert.equal(head.headers['content-length'], String(js.body.length));
    assert.equal(head.body.length, 0);
    assert.equal((await request(dev.base, prefix + '/install/install.js')).status, 200);
    assert.equal((await request(dev.base, prefix + '/media/file-fallbacks/php.svg')).status, 200);
    assert.equal((await request(dev.base, prefix + '/install/')).status, 200);
    assert.equal((await request(dev.base, prefix + '/')).status, 302);
    for (const route of ['/share', '/embed', '/api']) {
      const redirected = await request(dev.base, prefix + route + '?token=test&action=auth.session');
      assert.equal(redirected.status, 302);
      assert.equal(redirected.headers.location, prefix + route + '/?token=test&action=auth.session');
    }
  } finally {
    dev.child.kill();
  }
}
console.log('Security HTTP checks passed: mutation methods/CSRF, authorized backup/logout, private paths, encoded traversal, public assets, HEAD and subdirectory routing.');
