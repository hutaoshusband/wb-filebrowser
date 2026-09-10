// Main-thread orchestration for the compression worker: job correlation,
// progress forwarding, and cancellation. Compression jobs run one at a time
// (the caller sequences them), so a single active-job cancel slot is enough.
//
// The worker constructor is imported statically, but it only *spawns* the
// worker (and downloads the ~1.5 MB bundle) when first used - keep this
// factory synchronous, callers attach listeners to its result immediately.
import VideoCompressionWorker from './videoCompression.worker.js?worker';

import {
  computeTargetAudioBitrate,
  computeTargetDimensions,
  computeTargetVideoBitrate,
  compressedFileName,
} from './videoCompressionPolicy.js';

export function createVideoCompressor({ createWorker } = {}) {
  let worker = null;
  let nextRequestId = 0;
  let activeCompressionId = null;
  const pending = new Map();
  const progressHandlers = new Map();

  function ensureWorker() {
    if (worker) {
      return worker;
    }

    const instance = createWorker ? createWorker() : new VideoCompressionWorker();

    instance.addEventListener('message', (event) => {
      const { id, type } = event.data ?? {};

      if (type === 'progress' && progressHandlers.has(id)) {
        progressHandlers.get(id)(Number(event.data.value) || 0);
        return;
      }

      const settle = pending.get(id);

      if (!settle) {
        return;
      }

      pending.delete(id);
      progressHandlers.delete(id);

      if (event.data.ok) {
        settle.resolve(event.data.result);
      } else {
        const error = new Error(event.data.error?.message ?? 'Compression failed.');
        error.code = event.data.error?.code ?? 'FAILED';
        settle.reject(error);
      }
    });

    instance.addEventListener('error', (event) => {
      const error = new Error(
        'The video compression engine could not be started in this browser.'
          + (event.message ? ` (${event.message})` : ''),
      );
      error.code = 'WORKER_ERROR';
      [...pending.values()].forEach(({ reject }) => reject(error));
      pending.clear();
      progressHandlers.clear();
    });

    worker = instance;
    return instance;
  }

  function request(type, payload, { onProgress, trackActive = false } = {}) {
    const id = `wb-video-${++nextRequestId}`;
    const target = ensureWorker();

    if (trackActive) {
      activeCompressionId = id;
    }

    return new Promise((resolve, reject) => {
      pending.set(id, { resolve, reject });

      if (onProgress) {
        progressHandlers.set(id, onProgress);
      }

      target.postMessage({ id, type, ...payload });
    });
  }

  return {
    /**
     * Cheap capability probe: verifies the worker starts and the browser can
     * encode H.264. Never throws; failures are reported in the result.
     */
    async checkSupport() {
      try {
        const result = await request('inspect-support');
        return result ?? { supported: false, reason: 'Unknown compression support.' };
      } catch (error) {
        return { supported: false, reason: error instanceof Error ? error.message : String(error) };
      }
    },

    async inspect(file) {
      return request('inspect', { file });
    },

    async compress(file, policy, { onProgress, sourceInfo } = {}) {
      const dims = computeTargetDimensions(
        sourceInfo?.width ?? policy.max_width,
        sourceInfo?.height ?? policy.max_height,
        policy,
      );

      try {
        const result = await request('compress', {
          file,
          options: {
            width: dims.width,
            height: dims.height,
            maxFps: policy.max_fps,
            videoBitrate: computeTargetVideoBitrate(sourceInfo?.videoBitrate, policy),
            audioBitrate: computeTargetAudioBitrate(policy),
            ffmpegFallback: policy.ffmpeg_fallback,
            sourceFps: sourceInfo?.fps ?? null,
            outputName: compressedFileName(file.name),
          },
        }, { onProgress, trackActive: true });

        return {
          file: result.file,
          engine: result.engine,
          opfsToken: result.opfsToken ?? null,
          originalSize: file.size,
          newSize: result.file.size,
        };
      } finally {
        activeCompressionId = null;
      }
    },

    /**
     * Cancels the running compression job. The pending promise rejects with a
     * CANCELED error immediately; the worker cleans up its temp files on its
     * own schedule.
     */
    cancelActive() {
      if (activeCompressionId && worker) {
        worker.postMessage({ id: activeCompressionId, type: 'cancel' });
        const settle = pending.get(activeCompressionId);

        if (settle) {
          pending.delete(activeCompressionId);
          progressHandlers.delete(activeCompressionId);
          const error = new Error('Compression was canceled.');
          error.code = 'CANCELED';
          settle.reject(error);
        }
      }
    },

    async cleanup(opfsToken) {
      if (!opfsToken) {
        return;
      }

      try {
        await request('cleanup', { token: opfsToken });
      } catch {
        // Temp file cleanup is best-effort; the browser evicts OPFS data.
      }
    },

    dispose() {
      if (worker) {
        worker.terminate();
        worker = null;
      }

      const error = new Error('The video compression engine was shut down.');
      error.code = 'WORKER_ERROR';
      [...pending.values()].forEach(({ reject }) => reject(error));
      pending.clear();
      progressHandlers.clear();
    },
  };
}
