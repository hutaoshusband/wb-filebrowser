export const ENCRYPTION_FORMAT = 'WBENC001';

export function transformFile(file, password, { decrypt = false, signal, onProgress = () => {} } = {}) {
  return new Promise((resolve, reject) => {
    if (!globalThis.isSecureContext || typeof Worker === 'undefined') {
      reject(new Error('Local encryption requires HTTPS (or localhost), WebAssembly and Web Workers.'));
      return;
    }
    const worker = new Worker(new URL('./fileEncryption.worker.js', import.meta.url), { type: 'module' });
    const finish = (error, result) => {
      worker.terminate();
      signal?.removeEventListener('abort', abort);
      error ? reject(error) : resolve(result);
    };
    const abort = () => finish(new DOMException('Local encryption canceled.', 'AbortError'));
    signal?.addEventListener('abort', abort, { once: true });
    if (signal?.aborted) { abort(); return; }
    worker.onerror = () => finish(new Error('Unable to run the local encryption module. No file was uploaded.'));
    worker.onmessageerror = () => finish(new Error('Unable to receive the locally processed file.'));
    worker.onmessage = ({ data }) => {
      if (data.error) finish(new Error(data.error));
      else if (data.file) finish(null, data.file);
      else if (typeof data.progress === 'number') onProgress(data.progress);
    };
    const bytes = new TextEncoder().encode(password);
    password = '';
    try {
      worker.postMessage({ file, password: bytes, decrypt }, [bytes.buffer]);
    } catch (error) {
      bytes.fill(0);
      finish(error);
    }
  });
}
