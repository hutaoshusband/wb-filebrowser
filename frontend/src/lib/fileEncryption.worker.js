import init, { FileCipher } from './wasm/wb_file_crypto.js';
import wasmUrl from './wasm/wb_file_crypto_bg.wasm?url';

const CHUNK_SIZE = 1024 * 1024;
const HEADER_SIZE = 64;

// All password derivation, format validation and cryptography execute in Rust/WASM.
self.onmessage = async ({ data }) => {
  let cipher;
  const parts = [];
  try {
    await init({ module_or_path: wasmUrl });
    const { file, decrypt } = data;
    const password = data.password;
    try {
      cipher = decrypt
        ? FileCipher.decrypt(password, new Uint8Array(await file.slice(0, HEADER_SIZE).arrayBuffer()), BigInt(file.size))
        : FileCipher.encrypt(password, BigInt(file.size));
    } finally {
      password.fill(0);
      data.password = null;
    }
    if (!decrypt) parts.push(new Blob([cipher.header()]));
    const stride = CHUNK_SIZE + (decrypt ? 16 : 0);
    for (let offset = decrypt ? HEADER_SIZE : 0; offset < file.size; offset += stride) {
      const input = new Uint8Array(await file.slice(offset, offset + stride).arrayBuffer());
      let output;
      try {
        output = cipher.chunk(input);
        parts.push(new Blob([output]));
      } finally {
        input.fill(0);
        output?.fill(0);
      }
      self.postMessage({ progress: Math.min(1, (offset + stride) / file.size) });
    }
    cipher.finish();
    self.postMessage({ file: new Blob(parts, { type: 'application/octet-stream' }) });
  } catch (error) {
    self.postMessage({ error: error instanceof Error ? error.message : 'Local encryption failed.' });
  } finally {
    data.password?.fill(0);
    parts.length = 0;
    cipher?.free();
  }
};
