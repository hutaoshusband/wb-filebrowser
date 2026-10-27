import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createServer } from 'node:net';

const storage = await mkdtemp(join(tmpdir(), 'wb-encryption-browser-transfer-'));
const env = { ...process.env, WB_BROWSER_TEST_STORAGE: storage };
const fixture = spawnSync('php', ['tests/browser/runtime.php'], { env, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr + fixture.stdout);
const probe = createServer();
await new Promise((done) => probe.listen(0, '127.0.0.1', done));
const port = probe.address().port;
await new Promise((done) => probe.close(done));
const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-d', 'upload_max_filesize=16M', '-d', 'post_max_size=20M', '-S', `127.0.0.1:${port}`, '-t', '.', 'tests/browser/runtime.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });

try {
  await new Promise((done, reject) => {
    server.stderr.once('data', done);
    server.once('error', reject);
    server.once('exit', () => reject(new Error('PHP server exited during startup')));
  });

  let cookie = '';
  let csrf = '';
  async function request(action, data, extra = {}) {
    const isForm = data instanceof FormData;
    const response = await fetch(`${base}/api/index.php?action=${action}`, {
      method: data === undefined ? 'GET' : 'POST',
      headers: { ...(cookie ? { Cookie: cookie } : {}), ...(isForm ? {} : data !== undefined ? { 'Content-Type': 'application/json' } : {}), ...extra },
      body: data === undefined ? undefined : isForm ? data : JSON.stringify({ csrf_token: csrf, ...data }),
    });
    const setCookie = response.headers.get('set-cookie');
    if (setCookie) cookie = setCookie.split(';')[0];
    const result = await response.json();
    if (result.csrf_token) csrf = result.csrf_token;
    return { status: response.status, result };
  }

  await request('auth.session');
  assert.equal((await request('auth.login', { username: 'browseradmin', password: 'BrowserTestPassword123!' })).status, 200);
  assert.equal((await request('admin.settings.save', { uploads: { encryption_mode: 'off' } })).status, 200);
  const session = await request('auth.session');
  const chunkSize = session.result.upload_chunk_size;
  assert.equal(chunkSize, 8 * 1024 * 1024);

  const bytes = Buffer.alloc(chunkSize * 2 + 17, 0x51);
  Buffer.from('Transfer check!').copy(bytes, bytes.length - 15);
  const init = await request('upload.init', {
    folder_id: session.result.root_folder_id,
    original_name: 'transfer.bin',
    size: bytes.length,
    mime_type: 'application/octet-stream',
    total_chunks: 3,
    chunk_size: chunkSize,
  });
  assert.equal(init.status, 201, JSON.stringify(init.result));
  const token = init.result.data.upload_token;
  assert.equal(init.result.data.chunk_size, chunkSize);

  const results = await Promise.all([2, 0, 1].map(async (index) => {
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('upload_token', token);
    form.append('chunk_index', String(index));
    form.append('chunk', new Blob([bytes.subarray(index * chunkSize, Math.min(bytes.length, (index + 1) * chunkSize))]), 'chunk.part');
    return request('upload.chunk', form);
  }));
  assert.ok(results.every(({ status }) => status === 200), JSON.stringify(results));

  const completed = await request('upload.complete', { upload_token: token });
  assert.equal(completed.status, 201, JSON.stringify(completed.result));
  assert.equal(completed.result.file.size, bytes.length);
  const url = new URL(completed.result.file.download_url, base).toString();
  const headers = { Cookie: cookie };
  const download = await fetch(url, { headers });
  assert.equal(download.status, 200);
  const received = Buffer.from(await download.arrayBuffer());
  assert.equal(createHash('sha256').update(received).digest('hex'), createHash('sha256').update(bytes).digest('hex'));

  const range = await fetch(url, { headers: { ...headers, Range: 'bytes=8388600-8388623' } });
  assert.equal(range.status, 206);
  assert.equal(range.headers.get('content-range'), `bytes 8388600-8388623/${bytes.length}`);
  assert.deepEqual(Buffer.from(await range.arrayBuffer()), bytes.subarray(8388600, 8388624));

  const suffix = await fetch(url, { headers: { ...headers, Range: 'bytes=-17' } });
  assert.equal(suffix.status, 206);
  assert.deepEqual(Buffer.from(await suffix.arrayBuffer()), bytes.subarray(-17));

  const missing = await fetch(url, { headers: { ...headers, Range: `bytes=${bytes.length}-` } });
  assert.equal(missing.status, 416);
  assert.equal(missing.headers.get('content-range'), `bytes */${bytes.length}`);

  const head = await fetch(url, { method: 'HEAD', headers });
  assert.equal(head.status, 200);
  assert.equal(head.headers.get('content-length'), String(bytes.length));
  assert.equal((await head.arrayBuffer()).byteLength, 0);

  const unauthenticated = await fetch(url);
  assert.equal(unauthenticated.status, 401);
  console.log('PASS: multi-chunk parallel HTTP upload, 8 MiB negotiation, checksum, range/suffix/416/HEAD, private download access.');
} finally {
  server.kill();
  await new Promise((done) => server.exitCode !== null ? done() : server.once('exit', done));
  const target = resolve(storage);
  const parent = resolve(tmpdir());
  if (target.startsWith(parent + '\\wb-encryption-browser-transfer-') || target.startsWith(parent + '/wb-encryption-browser-transfer-')) {
    await rm(storage, { recursive: true, force: true });
  }
}
