import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, readdir, stat } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { chromium } from 'playwright';

const root = resolve('assets');
const files = await readdir(resolve(root, 'assets'));
const workers = await Promise.all(files.filter((name) => /^fileEncryption\.worker-.*\.js$/.test(name))
  .map(async (name) => ({ name, time: (await stat(resolve(root, 'assets', name))).mtimeMs })));
const workerName = workers.sort((a, b) => b.time - a.time)[0]?.name;
assert.ok(workerName, 'Run npm run build first.');
const server = createServer(async (request, response) => {
  if (request.url === '/') {
    response.setHeader('Content-Type', 'text/html');
    response.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self'; connect-src 'self'");
    response.end('<!doctype html><title>Built encryption verification</title>');
    return;
  }
  const path = resolve(root, '.' + request.url);
  if (!path.startsWith(root + sep)) { response.writeHead(403).end(); return; }
  try {
    response.setHeader('Content-Type', path.endsWith('.wasm') ? 'application/wasm' : 'text/javascript');
    response.end(await readFile(path));
  } catch { response.writeHead(404).end(); }
});
await new Promise((done) => server.listen(0, '127.0.0.1', done));
let browser;
try {
  browser = await chromium.launch({ channel: process.env.WB_TEST_BROWSER_CHANNEL || undefined });
  const page = await browser.newPage();
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  const result = await page.evaluate(async (workerName) => {
    const transform = (file, password, decrypt = false) => new Promise((resolve, reject) => {
      const worker = new Worker(`/assets/${workerName}`, { type: 'module' });
      worker.onerror = (event) => { worker.terminate(); reject(new Error(event.message)); };
      worker.onmessage = ({ data }) => {
        if (data.error) { worker.terminate(); reject(new Error(data.error)); }
        if (data.file) { worker.terminate(); resolve(data.file); }
      };
      const bytes = new TextEncoder().encode(password);
      worker.postMessage({ file, password: bytes, decrypt }, [bytes.buffer]);
    });
    const check = (condition, message) => { if (!condition) throw new Error(message); };
    const rejects = async (file, password) => {
      try { await transform(file, password, true); } catch { return; }
      throw new Error('Invalid password or file unexpectedly decrypted');
    };
    const password = 'browser test 🔐 password';
    for (const size of [0, 1, 1048576, 2097152 + 9]) {
      const plain = new Uint8Array(size).fill(83);
      const encrypted = await transform(new Blob([plain]), password);
      check(encrypted.size === 64 + size + Math.ceil(size / 1048576) * 16, 'Container length');
      const output = new Uint8Array(await (await transform(encrypted, password, true)).arrayBuffer());
      check(output.length === size && output.every((byte) => byte === 83), 'Round trip');
      await rejects(encrypted, 'wrong password');
      await rejects(encrypted.slice(0, encrypted.size - 1), password);
      await rejects(new Blob([encrypted, 'x']), password);
      if (size > 1048576) {
        const bytes = new Uint8Array(await encrypted.arrayBuffer());
        bytes[70] ^= 1;
        await rejects(new Blob([bytes]), password);
        const reordered = new Blob([encrypted.slice(0, 64), encrypted.slice(64 + 1048592, 64 + 2 * 1048592), encrypted.slice(64, 64 + 1048592), encrypted.slice(64 + 2 * 1048592)]);
        await rejects(reordered, password);
      }
    }
    const start = performance.now();
    const encrypted = await transform(new Blob([new Uint8Array(16 * 1024 * 1024)]), password);
    const encryptMs = Math.round(performance.now() - start);
    const decryptStart = performance.now();
    await transform(encrypted, password, true);
    return { roundTrips: 4, encrypt16MiBMs: encryptMs, decrypt16MiBMs: Math.round(performance.now() - decryptStart) };
  }, workerName);
  console.log(JSON.stringify(result));
} finally {
  await browser?.close();
  await new Promise((done) => server.close(done));
}
