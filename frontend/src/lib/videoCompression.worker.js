// Dedicated worker that transcodes videos locally before upload. The primary
// engine is Mediabunny on top of WebCodecs (hardware accelerated where
// available); sources that cannot be decoded natively fall back to a
// self-hosted ffmpeg.wasm build. Output is normalized to a policy-compliant
// MP4 (H.264 video, AAC audio, no extra tracks, no metadata).
//
// Message protocol (all requests carry a unique `id`):
//   -> { id, type: 'inspect', file }
//   <- { id, ok: true, result } | { id, ok: false, error: { message, code } }
//   -> { id, type: 'compress', file, options }
//   <- { id, type: 'progress', value }  (0..1, before the final result)
//   -> { id, type: 'cancel' }           (cancels the running job with that id)
//   -> { id, type: 'cleanup', token }   (removes a finished OPFS temp file)

import {
  ALL_FORMATS,
  BlobSource,
  BufferTarget,
  Conversion,
  Input,
  Mp4OutputFormat,
  Output,
  Quality,
  StreamTarget,
  canDecodeAudio,
  canDecodeVideo,
  canEncodeAudio,
  canEncodeVideo,
} from 'mediabunny';
import { registerAacEncoder } from '@mediabunny/aac-encoder';

// Self-hosted ffmpeg.wasm compatibility pack, emitted into assets/ at build
// time from node_modules (assets/ is gitignored; run `npm run build` to
// regenerate). Loaded lazily and only when the primary engine cannot decode
// the source. The multithreaded core (-mt) is preferred when the page is
// cross-origin isolated; the single-threaded core remains the last resort.
// The pthread worker must be a real file (?no-inline): a data:-URL worker
// is blocked by the CSP and could not receive the SharedArrayBuffer anyway.
import ffmpegCoreUrl from '@ffmpeg/core?url';
import ffmpegWasmUrl from '@ffmpeg/core/wasm?url';
import ffmpegMtCoreUrl from '@ffmpeg/core-mt?url';
import ffmpegMtWasmUrl from '@ffmpeg/core-mt/wasm?url';
import ffmpegMtWorkerUrl from '@ffmpeg/core-mt/worker?url&no-inline';
import ffmpegHostWorkerUrl from './ffmpeg-host.worker.js?worker&url';

const OPFS_MIN_INPUT_BYTES = 256 * 1024 * 1024;
// The single-threaded wasm engine holds input + output + encoder working
// set on the WASM heap (~2 GB ceiling); larger inputs die mid-encode after
// many minutes, so refuse them up front with an actionable message.
const FFMPEG_MAX_INPUT_BYTES = 256 * 1024 * 1024;
const OPFS_DIRECTORY = 'video-compression';

const runningJobs = new Map();

self.addEventListener('message', (event) => {
  const { id, type } = event.data ?? {};

  if (!id || !type) {
    return;
  }

  if (type === 'inspect') {
    runJob(id, () => inspectVideo(event.data.file));
    return;
  }

  if (type === 'inspect-support') {
    runJob(id, () => checkEncoderSupport());
    return;
  }

  if (type === 'compress') {
    runJob(id, (cancelSignal) => compressVideo(event.data.file, event.data.options ?? {}, cancelSignal, id));
    return;
  }

  if (type === 'cancel') {
    const cancel = runningJobs.get(id);

    if (cancel) {
      cancel(new Error('Compression was canceled.'));
    }

    return;
  }

  if (type === 'cleanup') {
    runJob(id, () => cleanupOpfsFile(event.data.token));
  }
});

// A fresh worker cannot have a transcode in flight, so any temp files left
// behind by crashed or canceled previous sessions are safe to remove.
sweepOpfsDirectory().catch(() => {});

/**
 * Reports what this browser can do. `supported` refers to the fast
 * WebCodecs path only; `fallbackCapable` says whether the in-browser
 * ffmpeg.wasm engine could run at all (WebAssembly + worker), which needs
 * no WebCodecs. Browsers like Firefox without a native H.264 encoder take
 * the fallback path in required mode.
 */
async function checkEncoderSupport() {
  const hasWebCodecs = typeof self.VideoEncoder !== 'undefined'
    && typeof self.VideoDecoder !== 'undefined';

  const fallbackCapable = typeof self.WebAssembly !== 'undefined';

  if (!hasWebCodecs) {
    return { supported: false, fallbackCapable, reason: 'This browser has no WebCodecs support.' };
  }

  if (!(await canEncodeVideo('avc'))) {
    return { supported: false, fallbackCapable, reason: 'This browser cannot encode H.264 video natively.' };
  }

  return { supported: true, fallbackCapable, reason: null };
}

function runJob(id, task) {
  const cancelHandlers = [];
  const cancelSignal = (handler) => cancelHandlers.push(handler);
  runningJobs.set(id, (error) => cancelHandlers.forEach((handler) => handler(error)));

  Promise.resolve()
    .then(() => task(cancelSignal))
    .then(
      (result) => postMessage({ id, ok: true, result }),
      (error) => postMessage({
        id,
        ok: false,
        error: {
          message: error instanceof Error ? error.message : String(error),
          code: error?.code ?? 'FAILED',
        },
      }),
    )
    .finally(() => runningJobs.delete(id));
}

function jobError(message, code) {
  const error = new Error(message);
  error.code = code;
  return error;
}

async function ensureAacEncoder() {
  if (!(await canEncodeAudio('aac'))) {
    // Registration is idempotent and cheap once the WASM module is warm.
    registerAacEncoder();
  }
}

async function inspectVideo(file) {
  const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(file) });
  const videoTrack = await input.getPrimaryVideoTrack();

  if (videoTrack === null) {
    throw jobError('The file does not contain a video track.', 'NO_VIDEO');
  }

  const format = await input.getFormat();
  const audioTrack = await input.getPrimaryAudioTrack();
  const videoTrackCount = (await input.getVideoTracks()).length;
  const audioTrackCount = (await input.getAudioTracks()).length;
  const stats = await videoTrack.computePacketStats(120).catch(() => null);
  const audioStats = audioTrack ? await audioTrack.computePacketStats(120).catch(() => null) : null;
  const duration = await input.getDurationFromMetadata().catch(() => null);

  return {
    container: format?.name ?? null,
    videoCodec: videoTrack.codec,
    width: videoTrack.displayWidth,
    height: videoTrack.displayHeight,
    fps: stats && stats.packetCount > 0 ? stats.averagePacketRate : null,
    videoBitrate: stats && stats.packetCount > 0 ? Math.round(stats.averageBitrate) : null,
    audioCodec: audioTrack ? audioTrack.codec : null,
    audioChannels: audioTrack ? audioTrack.numberOfChannels : null,
    audioBitrate: audioStats && audioStats.packetCount > 0 ? Math.round(audioStats.averageBitrate) : null,
    videoTrackCount,
    audioTrackCount,
    duration,
    decodable: videoTrack.codec !== null && await canDecodeVideo(videoTrack.codec),
    audioDecodable: audioTrack === null || (audioTrack.codec !== null && await canDecodeAudio(audioTrack.codec)),
  };
}

async function compressVideo(file, options, cancelSignal, jobId) {
  try {
    return await compressWithMediabunny(file, options, cancelSignal, jobId);
  } catch (error) {
    if (error?.code === 'CANCELED') {
      throw error;
    }

    const fallbackEligible = error?.code === 'UNSUPPORTED'
      && options.ffmpegFallback === true
      && file.size <= FFMPEG_MAX_INPUT_BYTES;

    if (!fallbackEligible) {
      if (error?.code === 'UNSUPPORTED' && options.ffmpegFallback === true) {
        throw jobError(
          'This video is too large for the in-browser compatibility engine. '
          + 'Compress it with a local tool (e.g. HandBrake) and upload the MP4.',
          'TOO_LARGE',
        );
      }

      throw error;
    }

    return compressWithFfmpeg(file, options, cancelSignal, jobId, error);
  }
}

async function compressWithMediabunny(file, options, cancelSignal, jobId) {
  const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(file) });
  const videoTrack = await input.getPrimaryVideoTrack();

  if (videoTrack === null) {
    throw jobError('The file does not contain a video track.', 'NO_VIDEO');
  }

  if (videoTrack.codec === null || !(await canDecodeVideo(videoTrack.codec))) {
    throw jobError('This browser cannot decode the video track of this file.', 'UNSUPPORTED');
  }

  const audioTrack = await input.getPrimaryAudioTrack();
  const hasAudio = audioTrack !== null;

  if (hasAudio && (audioTrack.codec === null || !(await canDecodeAudio(audioTrack.codec)))) {
    // Audio we cannot decode is dropped rather than failing the whole job;
    // silent video still complies with the policy.
    options = { ...options, dropAudio: true };
  }

  await ensureAacEncoder();

  if (!(await canEncodeVideo('avc', { width: options.width, height: options.height, bitrate: options.videoBitrate }))) {
    throw jobError('This browser cannot encode H.264 video.', 'UNSUPPORTED');
  }

  const stats = await videoTrack.computePacketStats(120).catch(() => null);
  const sourceFps = stats && stats.packetCount > 0 ? stats.averagePacketRate : null;
  const sourceBitrate = stats && stats.packetCount > 0 ? stats.averageBitrate : null;

  const destination = await createOutputDestination(file.size);
  const output = new Output({
    format: new Mp4OutputFormat(),
    target: destination.target,
  });

  const videoConfig = {
    codec: 'avc',
    quality: new Quality({ bitrate: options.videoBitrate, bitrateMode: 'variable' }),
    width: options.width,
    height: options.height,
    fit: 'fill',
    forceTranscode: true,
  };

  if (sourceFps && sourceFps > options.maxFps + 0.5) {
    videoConfig.frameRate = options.maxFps;
  }

  const conversion = await Conversion.init({
    input,
    output,
    tracks: 'primary',
    video: videoConfig,
    audio: hasAudio && !options.dropAudio
      ? {
        codec: 'aac',
        quality: new Quality({ bitrate: options.audioBitrate, bitrateMode: 'variable' }),
        forceTranscode: true,
      }
      : { discard: true },
    copy: false,
    tags: () => ({}),
    showWarnings: false,
  });

  const undecodable = conversion.discardedTracks.some(({ track, reason }) => track === videoTrack && reason === 'undecodable_source_codec');

  if (undecodable || !conversion.isValid) {
    await safeCancelConversion(conversion);
    await destination.discard();
    throw jobError('This browser cannot decode this video for local compression.', 'UNSUPPORTED');
  }

  cancelSignal(async (cancelError) => {
    await safeCancelConversion(conversion);
    await destination.discard();
    throw cancelError ?? jobError('Compression was canceled.', 'CANCELED');
  });

  try {
    conversion.onProgress = (value) => {
      postMessage({ id: jobId, type: 'progress', value: Math.min(1, Math.max(0, value)) });
    };

    await conversion.execute();

    if (conversion.state !== 'done') {
      throw jobError('Compression was canceled.', 'CANCELED');
    }

    const outputFile = await destination.finish(`wb-compress-${jobId}.mp4`);

    await assertOutputIsPlayable(outputFile, file.name);

    return {
      file: new File([outputFile], options.outputName ?? 'video.mp4', { type: 'video/mp4' }),
      engine: 'mediabunny',
      opfsToken: destination.token ?? null,
      sourceFps,
      sourceBitrate,
    };
  } catch (error) {
    await destination.discard();
    throw error;
  }
}

async function compressWithFfmpeg(file, options, cancelSignal, jobId, primaryError) {
  const { FFmpeg, FFFSType } = await import('@ffmpeg/ffmpeg');

  let activeFfmpeg = null;
  let canceled = false;

  cancelSignal(async (cancelError) => {
    canceled = true;

    try {
      activeFfmpeg?.terminate();
    } catch {
      // The worker may already be gone.
    }

    throw cancelError;
  });

  function createEngine() {
    const instance = new FFmpeg();
    activeFfmpeg = instance;
    instance.on('progress', ({ progress }) => {
      if (Number.isFinite(progress)) {
        postMessage({ id: jobId, type: 'progress', value: Math.min(1, Math.max(0, progress)) });
      }
    });
    return instance;
  }

  // Multithreaded core needs SharedArrayBuffer and therefore cross-origin
  // isolation; load it when available and fall back to the single-threaded
  // core if it cannot start (e.g. headers missing after all).
  let ffmpeg = createEngine();
  let engine = 'ffmpeg-wasm-mt';

  if (self.crossOriginIsolated === true) {
    try {
      await ffmpeg.load({
        classWorkerURL: resolveAsset(ffmpegHostWorkerUrl),
        coreURL: resolveAsset(ffmpegMtCoreUrl),
        wasmURL: resolveAsset(ffmpegMtWasmUrl),
        workerURL: resolveAsset(ffmpegMtWorkerUrl),
      });
    } catch {
      ffmpeg = createEngine();
      engine = 'ffmpeg-wasm';
    }
  } else {
    engine = 'ffmpeg-wasm';
  }

  if (engine === 'ffmpeg-wasm') {
    await ffmpeg.load({
      classWorkerURL: resolveAsset(ffmpegHostWorkerUrl),
      coreURL: resolveAsset(ffmpegCoreUrl),
      wasmURL: resolveAsset(ffmpegWasmUrl),
    });
  }

  const outputName = 'output.mp4';
  const filters = [`scale='min(iw,${options.width})':'min(ih,${options.height})':force_original_aspect_ratio=decrease:force_divisible_by=2`];

  if (options.sourceFps && options.sourceFps > options.maxFps + 0.5) {
    filters.push(`fps=${options.maxFps}`);
  }

  const maxVideoBitrate = Math.round(options.videoBitrate / 0.8);

  // WORKERFS lets the encoder stream the input straight from the File handle
  // instead of copying the whole file onto the WASM heap first. Falls back
  // to a MEMFS copy when mounting is unavailable.
  const mountDir = '/wb-input';
  let inputPath = null;

  try {
    ffmpeg.createDir(mountDir);
    ffmpeg.mount(FFFSType.WORKERFS, { files: [file] }, mountDir);
    inputPath = `${mountDir}/${file.name}`;
  } catch {
    inputPath = null;
  }

  if (inputPath === null) {
    const inputName = `input${file.name && file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')) : ''}`;
    await ffmpeg.writeFile(inputName, new Uint8Array(await file.arrayBuffer()));
    inputPath = inputName;
  }

  const returnCode = await ffmpeg.exec([
    '-i', inputPath,
    '-map', '0:v:0', '-map', '0:a:0?',
    // This is the rescue path, not the quality path: the bitrate policy
    // bounds the output size, so spend CPU as little as possible.
    '-c:v', 'libx264', '-preset', 'ultrafast', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
    '-b:v', String(options.videoBitrate),
    '-maxrate', String(maxVideoBitrate),
    '-bufsize', String(maxVideoBitrate * 2),
    '-vf', filters.join(','),
    '-c:a', 'aac', '-b:a', String(options.audioBitrate),
    '-map_metadata', '-1', '-sn', '-dn',
    '-movflags', '+faststart',
    '-y', outputName,
  ]);

  if (canceled) {
    throw jobError('Compression was canceled.', 'CANCELED');
  }

  if (returnCode !== 0) {
    throw jobError(
      `The compatibility engine could not compress this video (${primaryError.message}).`,
      'UNSUPPORTED',
    );
  }

  const data = await ffmpeg.readFile(outputName);

  if (!data || data.byteLength === 0) {
    throw jobError('The compatibility engine produced an empty file.', 'FAILED');
  }

  const outputFile = new File([data], options.outputName ?? 'video.mp4', { type: 'video/mp4' });
  await assertOutputIsPlayable(outputFile, file.name);

  try {
    ffmpeg.unmount(mountDir);
    ffmpeg.deleteDir(mountDir);
    ffmpeg.deleteFile(outputName);
  } catch {
    // Best-effort filesystem cleanup; the engine worker is discarded anyway.
  }

  return {
    file: outputFile,
    engine,
    opfsToken: null,
    sourceFps: options.sourceFps ?? null,
    sourceBitrate: null,
  };
}

async function assertOutputIsPlayable(outputFile, originalName) {
  if (!(outputFile.size > 0)) {
    throw jobError(`Compressing ${originalName} produced an empty file.`, 'FAILED');
  }

  const verifyInput = new Input({ formats: ALL_FORMATS, source: new BlobSource(outputFile) });
  const videoTrack = await verifyInput.getPrimaryVideoTrack();
  const format = await verifyInput.getFormat();

  // Mediabunny names the format "MP4" (and "QuickTime File Format" for MOV),
  // so compare case-insensitively: only the MP4 family passes.
  if (videoTrack === null || format?.name?.toLowerCase() !== 'mp4') {
    throw jobError(`The compressed copy of ${originalName} failed the local integrity check.`, 'FAILED');
  }
}

/**
 * Picks the output destination: in-memory for small outputs, an OPFS temp
 * file for large inputs so multi-gigabyte results never sit in RAM. The
 * returned object routes to `finish()` on success (yielding a File handle)
 * and `discard()` on failure or cancellation.
 */
async function createOutputDestination(inputSize, cancelSignal) {
  if (inputSize >= OPFS_MIN_INPUT_BYTES && navigator.storage?.getDirectory) {
    try {
      const root = await navigator.storage.getDirectory();
      const directory = await root.getDirectoryHandle(OPFS_DIRECTORY, { create: true });
      const token = `wb-compress-${Date.now()}-${Math.random().toString(36).slice(2, 10)}.mp4`;
      const handle = await directory.getFileHandle(token, { create: true });
      const writable = await handle.createWritable();

      return {
        token,
        target: new StreamTarget(writable),
        finish: async () => {
          await writable.close();
          return handle.getFile();
        },
        discard: async () => {
          try {
            await writable.abort();
          } catch {
            // Already closed or broken.
          }

          await cleanupOpfsFile(token);
        },
      };
    } catch {
      // No OPFS access (private mode, quota): fall back to memory.
    }
  }

  const target = new BufferTarget();

  return {
    token: null,
    target,
    finish: async () => {
      if (!target.buffer) {
        throw jobError('The compressed file could not be written.', 'FAILED');
      }

      // Wrap the buffer so the shared integrity check (which reads .size)
      // and the File constructor both work for either destination type.
      return new Blob([target.buffer], { type: 'video/mp4' });
    },
    discard: async () => {},
  };
}

async function cleanupOpfsFile(token) {
  if (!token || !navigator.storage?.getDirectory) {
    return;
  }

  try {
    const root = await navigator.storage.getDirectory();
    const directory = await root.getDirectoryHandle(OPFS_DIRECTORY, { create: false });
    await directory.removeEntry(token);
  } catch {
    // The temp file is gone or OPFS is unavailable; nothing to do.
  }
}

async function sweepOpfsDirectory() {
  if (!navigator.storage?.getDirectory) {
    return;
  }

  const root = await navigator.storage.getDirectory();
  const directory = await root.getDirectoryHandle(OPFS_DIRECTORY, { create: false }).catch(() => null);

  if (!directory) {
    return;
  }

  for await (const [name, handle] of directory.entries()) {
    if (handle.kind === 'file') {
      await directory.removeEntry(name).catch(() => {});
    }
  }
}

async function safeCancelConversion(conversion) {
  try {
    if (conversion.state === 'executing' || conversion.state === 'idle') {
      await conversion.cancel();
    }
  } catch {
    // Cancellation races are fine; the job is being torn down either way.
  }
}

/**
 * Bundled asset references may be relative paths, root-relative paths, or
 * full URLs depending on the Vite version and base. They all describe files
 * that live next to this worker (the flat assets/ directory), so normalize
 * them against this worker's own directory.
 */
function resolveAsset(path) {
  if (/^(https?:|blob:|data:)/i.test(path)) {
    return path;
  }

  const directory = new URL('.', self.location.href);
  const clean = String(path).replace(/^\//, '').replace(/^assets\//, '');

  return new URL(clean, directory).href;
}
