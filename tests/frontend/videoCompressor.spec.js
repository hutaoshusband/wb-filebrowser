import { describe, expect, it, vi } from 'vitest';

// The default (non-injected) worker factory must be exercised too: it once
// returned a Promise instead of a Worker, which only reproduced without an
// injected createWorker.
vi.mock('../../frontend/src/lib/videoCompression.worker.js?worker', () => {
  const instances = [];

  class DefaultWorkerStub {
    constructor() {
      this.listeners = new Map();
      this.posted = [];
      this.terminated = false;
      instances.push(this);
    }

    addEventListener(type, handler) {
      this.listeners.set(type, handler);
    }

    postMessage(data) {
      this.posted.push(data);
    }

    terminate() {
      this.terminated = true;
    }
  }

  return { default: DefaultWorkerStub, __instances: instances };
});

import { createVideoCompressor } from '../../frontend/src/lib/videoCompressor.js';
import { __instances as defaultWorkerInstances } from '../../frontend/src/lib/videoCompression.worker.js?worker';

function createFakeWorkerEnvironment() {
  const workers = [];

  class FakeWorker {
    constructor() {
      this.listeners = new Map();
      this.posted = [];
      this.terminated = false;
      workers.push(this);
    }

    addEventListener(type, handler) {
      this.listeners.set(type, handler);
    }

    emit(data) {
      this.listeners.get('message')?.({ data });
    }

    emitError(event) {
      this.listeners.get('error')?.(event);
    }

    postMessage(data) {
      this.posted.push(data);
    }

    terminate() {
      this.terminated = true;
    }
  }

  return { FakeWorker, workers };
}

function flushMicrotasks() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function normalizePolicy() {
  return {
    mode: 'required',
    max_width: 1920,
    max_height: 1080,
    max_fps: 60,
    max_video_bitrate_kbps: 8000,
    max_audio_bitrate_kbps: 192,
    min_source_mb: 0,
    min_savings_pct: 5,
    ffmpeg_fallback: true,
    min_source_bytes: 0,
  };
}

describe('createVideoCompressor', () => {
  it('spawns the default worker synchronously and talks to it', async () => {
    const compressor = createVideoCompressor();

    const supportPromise = compressor.checkSupport();
    await flushMicrotasks();

    // The factory must have produced a real Worker-like object immediately -
    // a Promise here is the regression this guards against.
    const spawned = defaultWorkerInstances.at(-1);
    expect(spawned).toBeDefined();
    expect(typeof spawned.addEventListener).toBe('function');

    const request = spawned.posted.find(({ type }) => type === 'inspect-support');
    expect(request).toBeDefined();

    spawned.listeners.get('message')({ data: { id: request.id, ok: true, result: { supported: true } } });

    await expect(supportPromise).resolves.toEqual({ supported: true });
  });

  it('reports support through the worker probe', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const supportPromise = compressor.checkSupport();
    await flushMicrotasks();

    const request = workers[0].posted.find(({ type }) => type === 'inspect-support');
    expect(request).toBeDefined();

    workers[0].emit({ id: request.id, ok: true, result: { supported: true } });

    await expect(supportPromise).resolves.toEqual({ supported: true });
  });

  it('returns unsupported when the worker errors while probing', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const supportPromise = compressor.checkSupport();
    await flushMicrotasks();

    workers[0].emitError({ message: 'module script failed' });

    const support = await supportPromise;
    expect(support.supported).toBe(false);
    expect(support.reason).toContain('module script failed');
  });

  it('passes policy-derived options and returns worker results for compress jobs', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const file = new File(['x'.repeat(100)], 'movie.mov', { type: 'video/quicktime' });
    const resultFile = new File(['y'.repeat(20)], 'movie.mp4', { type: 'video/mp4' });
    const compressPromise = compressor.compress(file, normalizePolicy(), { sourceInfo: { width: 3840, height: 2160, videoBitrate: 50_000_000 } });

    await flushMicrotasks();

    const request = workers[0].posted.find(({ type }) => type === 'compress');

    expect(request.options.width).toBe(1920);
    expect(request.options.height).toBe(1080);
    expect(request.options.maxFps).toBe(60);
    expect(request.options.videoBitrate).toBe(6_400_000);
    expect(request.options.audioBitrate).toBe(Math.round(192 * 1000 * 0.9));
    expect(request.options.ffmpegFallback).toBe(true);
    expect(request.options.outputName).toBe('movie.mp4');
    expect(request.file).toBe(file);

    workers[0].emit({ id: request.id, ok: true, result: { file: resultFile, engine: 'mediabunny', opfsToken: 'token' } });

    const result = await compressPromise;

    expect(result.file).toBe(resultFile);
    expect(result.engine).toBe('mediabunny');
    expect(result.opfsToken).toBe('token');
    expect(result.originalSize).toBe(file.size);
    expect(result.newSize).toBe(resultFile.size);
  });

  it('forwards progress events to the compress callback', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const onProgress = vi.fn();
    const compressPromise = compressor.compress(new File(['abc'], 'v.mp4', { type: 'video/mp4' }), normalizePolicy(), { onProgress });

    await flushMicrotasks();

    const worker = workers[0];
    const request = worker.posted.find(({ type }) => type === 'compress');

    worker.emit({ id: request.id, type: 'progress', value: 0.25 });
    worker.emit({ id: request.id, type: 'progress', value: 0.75 });

    expect(onProgress).toHaveBeenNthCalledWith(1, 0.25);
    expect(onProgress).toHaveBeenNthCalledWith(2, 0.75);

    worker.emit({ id: request.id, ok: true, result: { file: new File(['z'], 'v.mp4'), engine: 'mediabunny' } });
    await compressPromise;
  });

  it('surfaces worker errors with their code', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const compressPromise = compressor.compress(new File(['abc'], 'v.mkv', { type: 'video/x-matroska' }), normalizePolicy());

    await flushMicrotasks();

    const request = workers[0].posted.find(({ type }) => type === 'compress');
    workers[0].emit({ id: request.id, ok: false, error: { message: 'This browser cannot decode the video track of this file.', code: 'UNSUPPORTED' } });

    await expect(compressPromise).rejects.toMatchObject({ code: 'UNSUPPORTED' });
  });

  it('rejects the active job locally when canceled', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const compressPromise = compressor.compress(new File(['abc'], 'v.mp4', { type: 'video/mp4' }), normalizePolicy());

    await flushMicrotasks();

    compressor.cancelActive();

    const cancelRequest = workers[0].posted.find(({ type }) => type === 'cancel');

    expect(cancelRequest).toBeDefined();
    await expect(compressPromise).rejects.toMatchObject({ code: 'CANCELED' });
  });

  it('rejects pending jobs when the worker errors out', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const compressPromise = compressor.compress(new File(['abc'], 'v.mp4', { type: 'video/mp4' }), normalizePolicy());

    await flushMicrotasks();

    workers[0].emitError({ message: 'blob fetch failed' });

    await expect(compressPromise).rejects.toMatchObject({ code: 'WORKER_ERROR' });
  });

  it('sends cleanup requests for OPFS tokens and swallows their failures', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const cleanupPromise = compressor.cleanup('wb-compress-token.mp4');

    await flushMicrotasks();

    const request = workers[0].posted.find(({ type }) => type === 'cleanup');

    expect(request.token).toBe('wb-compress-token.mp4');

    workers[0].emit({ id: request.id, ok: false, error: { message: 'gone', code: 'FAILED' } });
    await expect(cleanupPromise).resolves.toBeUndefined();
  });

  it('skips cleanup calls without a token', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    await expect(compressor.cleanup(null)).resolves.toBeUndefined();

    expect(workers).toHaveLength(0);
  });

  it('terminates the worker on dispose', async () => {
    const { FakeWorker, workers } = createFakeWorkerEnvironment();
    const compressor = createVideoCompressor({ createWorker: () => new FakeWorker() });

    const supportPromise = compressor.checkSupport();
    await flushMicrotasks();
    compressor.dispose();

    expect(workers[0].terminated).toBe(true);

    // checkSupport reports failure instead of hanging on the dead worker.
    await expect(supportPromise).resolves.toMatchObject({ supported: false });
  });
});
