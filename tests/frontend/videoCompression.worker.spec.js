import { beforeEach, describe, expect, it, vi } from 'vitest';

// The ffmpeg compatibility assets are plain build-time URLs in the worker;
// replace them so the spec can run under jsdom without bundling wasm.
vi.mock('@ffmpeg/core?url', () => ({ default: 'assets/ffmpeg-core.js' }));
vi.mock('@ffmpeg/core/wasm?url', () => ({ default: 'assets/ffmpeg-core.wasm' }));
vi.mock('@ffmpeg/core-mt?url', () => ({ default: 'assets/ffmpeg-core-mt.js' }));
vi.mock('@ffmpeg/core-mt/wasm?url', () => ({ default: 'assets/ffmpeg-core-mt.wasm' }));
vi.mock('@ffmpeg/core-mt/worker?url&no-inline', () => ({ default: 'assets/ffmpeg-core-mt.worker.js' }));
vi.mock('../../frontend/src/lib/ffmpeg-host.worker.js?worker&url', () => ({ default: 'assets/ffmpeg-host.worker.js' }));

// Mediabunny is fully mocked; the worker is exercised on the jsdom main
// thread by dispatching MessageEvents on `self` and spying on postMessage.
vi.mock('mediabunny', () => {
  const state = {
    videoTrack: null,
    audioTrack: null,
    verifyVideoTrack: undefined,
    format: { name: 'MP4' },
    inputCount: 0,
    bufferTarget: null,
    conversionInit: null,
    canDecodeVideoResult: true,
    canEncodeVideoResult: true,
    canEncodeAudioResult: false,
  };

  class FakeInput {
    constructor(options) {
      state.lastInput = options;
      state.inputCount += 1;
      this.index = state.inputCount;
    }

    async getPrimaryVideoTrack() {
      // The second Input instance a compress job opens is the integrity
      // verification pass over the produced file.
      if (this.index > 1 && state.verifyVideoTrack !== undefined) {
        return state.verifyVideoTrack;
      }

      return state.videoTrack;
    }

    async getPrimaryAudioTrack() {
      return state.audioTrack;
    }

    async getVideoTracks() {
      return state.videoTrack ? [state.videoTrack] : [];
    }

    async getAudioTracks() {
      return state.audioTrack ? [state.audioTrack] : [];
    }

    async getFormat() {
      return state.format;
    }

    async getDurationFromMetadata() {
      return 12.5;
    }
  }

  class FakeConversion {
    constructor() {
      this.isValid = true;
      this.discardedTracks = [];
      this.state = 'idle';
      this.onProgress = null;
    }

    async execute() {
      this.state = 'done';
      this.onProgress?.(0.5);
      this.onProgress?.(1);

      if (state.bufferTarget) {
        state.bufferTarget.buffer = state.bufferTarget.buffer ?? new ArrayBuffer(64);
      }
    }

    async cancel() {
      this.state = 'canceled';
    }
  }

  return {
    ALL_FORMATS: ['mp4'],
    BlobSource: class {
      constructor(file) {
        state.lastSource = file;
      }
    },
    BufferTarget: class {
      constructor() {
        state.bufferTarget = this;
        this.buffer = null;
      }
    },
    Conversion: {
      init: async (options) => {
        state.conversionInit = options;
        return new FakeConversion(options);
      },
    },
    Input: FakeInput,
    Mp4OutputFormat: class {},
    Output: class {
      constructor(options) {
        state.lastOutput = options;
      }
    },
    Quality: class {
      constructor(options) {
        this.options = options;
      }
    },
    StreamTarget: class {
      constructor(writable) {
        state.lastStreamTarget = writable;
      }
    },
    canDecodeAudio: vi.fn(async (codec) => codec === 'aac'),
    canDecodeVideo: vi.fn(async () => state.canDecodeVideoResult),
    canEncodeAudio: vi.fn(async () => state.canEncodeAudioResult),
    canEncodeVideo: vi.fn(async () => state.canEncodeVideoResult),
    __state: state,
  };
});

vi.mock('@mediabunny/aac-encoder', () => ({
  registerAacEncoder: vi.fn(),
}));

vi.mock('@ffmpeg/ffmpeg', () => {
  const instances = [];
  const engineState = {
    loadFailureFor: null, // core URL substring that makes load() reject
    mountFails: false,
  };

  class FakeFFmpeg {
    constructor() {
      this.handlers = {};
      this.files = new Map();
      this.loadConfig = null;
      this.execArgs = null;
      this.terminated = false;
      this.mounted = null;
      instances.push(this);
    }

    async load(config) {
      if (engineState.loadFailureFor && config.coreURL?.includes(engineState.loadFailureFor)) {
        throw new Error('core failed to start');
      }

      this.loadConfig = config;
    }

    on(event, handler) {
      this.handlers[event] = handler;
    }

    async writeFile(name, data) {
      this.files.set(name, data);
    }

    createDir() {}

    mount(type, options, mountPoint) {
      if (engineState.mountFails) {
        throw new Error('WORKERFS unavailable');
      }

      this.mounted = { type, options, mountPoint };
    }

    unmount() {}

    deleteDir() {}

    async exec(args) {
      this.execArgs = args;
      this.handlers.progress?.({ progress: 0.5 });
      return 0;
    }

    async readFile() {
      return new Uint8Array([1, 2, 3, 4]);
    }

    async deleteFile() {}

    terminate() {
      this.terminated = true;
    }
  }

  return {
    FFmpeg: FakeFFmpeg,
    FFFSType: { WORKERFS: 'WORKERFS' },
    __instances: instances,
    __engineState: engineState,
  };
});

// jsdom does not implement Blob.arrayBuffer; polyfill it via FileReader so
// the ffmpeg fallback path can read the input file like a browser would.
if (typeof Blob.prototype.arrayBuffer !== 'function') {
  Blob.prototype.arrayBuffer = function arrayBuffer() {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(reader.error);
      reader.readAsArrayBuffer(this);
    });
  };
}

import '../../frontend/src/lib/videoCompression.worker.js';
import { registerAacEncoder } from '@mediabunny/aac-encoder';
import { __state as mediabunnyState } from 'mediabunny';

function makeVideoTrack(overrides = {}) {
  return {
    codec: 'avc',
    displayWidth: 1280,
    displayHeight: 720,
    computePacketStats: async () => ({ packetCount: 300, averagePacketRate: 30, averageBitrate: 2_000_000 }),
    ...overrides,
  };
}

function makeAudioTrack(overrides = {}) {
  return {
    codec: 'aac',
    numberOfChannels: 2,
    sampleRate: 48000,
    computePacketStats: async () => ({ packetCount: 300, averagePacketRate: 48000, averageBitrate: 128_000 }),
    ...overrides,
  };
}

function compressOptions(overrides = {}) {
  return {
    width: 1280,
    height: 720,
    maxFps: 60,
    videoBitrate: 4_000_000,
    audioBitrate: 120_000,
    ffmpegFallback: false,
    outputName: 'movie.mp4',
    ...overrides,
  };
}

async function sendJob(data) {
  self.dispatchEvent(new MessageEvent('message', { data }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
  return data.id;
}

function responsesFor(id) {
  return postMessageSpy.mock.calls
    .map(([message]) => message)
    .filter((message) => message.id === id && message.type !== 'progress');
}

let postMessageSpy;

describe('videoCompression.worker', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    postMessageSpy = vi.spyOn(self, 'postMessage').mockImplementation(() => {});
    // jsdom has no WebCodecs; the support probe needs the globals to exist.
    self.VideoEncoder = self.VideoEncoder ?? class VideoEncoder {};
    self.VideoDecoder = self.VideoDecoder ?? class VideoDecoder {};
    mediabunnyState.videoTrack = makeVideoTrack();
    mediabunnyState.audioTrack = makeAudioTrack();
    mediabunnyState.verifyVideoTrack = undefined;
    mediabunnyState.format = { name: 'MP4' };
    mediabunnyState.inputCount = 0;
    mediabunnyState.bufferTarget = null;
    mediabunnyState.conversionInit = null;
    mediabunnyState.canDecodeVideoResult = true;
    mediabunnyState.canEncodeVideoResult = true;
  });

  it('reports encoder support from WebCodecs and H.264 encoding', async () => {
    const id = await sendJob({ id: 'support-1', type: 'inspect-support' });

    expect(responsesFor(id)[0].result).toEqual({
      supported: true,
      fallbackCapable: true,
      reason: null,
    });
  });

  it('reports no native encoder but a usable fallback (Firefox case)', async () => {
    mediabunnyState.canEncodeVideoResult = false;

    const id = await sendJob({ id: 'support-2', type: 'inspect-support' });

    expect(responsesFor(id)[0].result).toEqual({
      supported: false,
      fallbackCapable: true,
      reason: 'This browser cannot encode H.264 video natively.',
    });
  });

  it('reports no fallback either when WebCodecs is missing entirely', async () => {
    const savedEncoder = self.VideoEncoder;
    const savedDecoder = self.VideoDecoder;
    delete self.VideoEncoder;
    delete self.VideoDecoder;

    try {
      const id = await sendJob({ id: 'support-2b', type: 'inspect-support' });

      expect(responsesFor(id)[0].result).toMatchObject({
        supported: false,
        reason: 'This browser has no WebCodecs support.',
      });
    } finally {
      self.VideoEncoder = savedEncoder;
      self.VideoDecoder = savedDecoder;
    }
  });

  it('inspects a video and reports its policy-relevant summary', async () => {
    const id = await sendJob({ id: 'inspect-1', type: 'inspect', file: new File(['abc'], 'v.mov', { type: 'video/quicktime' }) });

    expect(responsesFor(id)[0].result).toMatchObject({
      container: 'MP4',
      videoCodec: 'avc',
      width: 1280,
      height: 720,
      fps: 30,
      videoBitrate: 2_000_000,
      audioCodec: 'aac',
      audioBitrate: 128_000,
      videoTrackCount: 1,
      audioTrackCount: 1,
      duration: 12.5,
      decodable: true,
    });
  });

  it('reports NO_VIDEO for files without a video track', async () => {
    mediabunnyState.videoTrack = null;

    const id = await sendJob({ id: 'inspect-2', type: 'inspect', file: new File(['abc'], 'a.mp3') });

    expect(responsesFor(id)[0]).toMatchObject({ ok: false, error: { code: 'NO_VIDEO' } });
  });

  it('transcodes to policy-compliant H.264/AAC MP4 with metadata stripped', async () => {
    const id = await sendJob({
      id: 'compress-1',
      type: 'compress',
      file: new File(['x'.repeat(64)], 'movie.mov', { type: 'video/quicktime' }),
      options: compressOptions(),
    });

    const init = mediabunnyState.conversionInit;

    expect(init.tracks).toBe('primary');
    expect(init.copy).toBe(false);
    expect(init.tags()).toEqual({});
    expect(init.video).toMatchObject({ codec: 'avc', width: 1280, height: 720, forceTranscode: true });
    expect(init.video.quality.options).toEqual({ bitrate: 4_000_000, bitrateMode: 'variable' });
    expect(init.audio).toMatchObject({ codec: 'aac', forceTranscode: true });
    expect(init.audio.quality.options).toEqual({ bitrate: 120_000, bitrateMode: 'variable' });
    // 30 FPS source under a 60 FPS cap: no frame rate override.
    expect(init.video.frameRate).toBeUndefined();

    const response = responsesFor(id)[0];

    expect(response.ok).toBe(true);
    expect(response.result.engine).toBe('mediabunny');
    expect(response.result.file).toBeInstanceOf(File);
    expect(response.result.file.name).toBe('movie.mp4');
    expect(response.result.file.type).toBe('video/mp4');
    expect(response.result.file.size).toBe(64);
  });

  it('caps the frame rate only when the source exceeds the policy', async () => {
    mediabunnyState.videoTrack = makeVideoTrack({
      computePacketStats: async () => ({ packetCount: 900, averagePacketRate: 90, averageBitrate: 8_000_000 }),
    });

    await sendJob({
      id: 'compress-2',
      type: 'compress',
      file: new File(['x'], 'fast.mkv', { type: 'video/x-matroska' }),
      options: compressOptions({ outputName: 'fast.mp4' }),
    });

    expect(mediabunnyState.conversionInit.video.frameRate).toBe(60);
  });

  it('emits progress messages during conversion', async () => {
    const id = await sendJob({
      id: 'compress-3',
      type: 'compress',
      file: new File(['x'], 'v.mp4', { type: 'video/mp4' }),
      options: compressOptions(),
    });

    const progress = postMessageSpy.mock.calls
      .map(([message]) => message)
      .filter((message) => message.id === id && message.type === 'progress')
      .map((message) => message.value);

    expect(progress).toEqual([0.5, 1]);
  });

  it('registers the WASM AAC encoder when native AAC is unavailable', async () => {
    mediabunnyState.canEncodeAudioResult = false;

    await sendJob({
      id: 'compress-4',
      type: 'compress',
      file: new File(['x'], 'v.mp4', { type: 'video/mp4' }),
      options: compressOptions(),
    });

    expect(registerAacEncoder).toHaveBeenCalled();
  });

  it('drops audio it cannot decode instead of failing the job', async () => {
    mediabunnyState.audioTrack = makeAudioTrack({ codec: 'vorbis' });

    const id = await sendJob({
      id: 'compress-5',
      type: 'compress',
      file: new File(['x'], 'v.webm', { type: 'video/webm' }),
      options: compressOptions(),
    });

    expect(responsesFor(id)[0].ok).toBe(true);
    expect(mediabunnyState.conversionInit.audio).toEqual({ discard: true });
  });

  it('reports UNREADABLE sources as UNSUPPORTED when the fallback is disabled', async () => {
    mediabunnyState.canDecodeVideoResult = false;

    const id = await sendJob({
      id: 'compress-6',
      type: 'compress',
      file: new File(['x'], 'old.avi', { type: 'video/x-msvideo' }),
      options: compressOptions(),
    });

    expect(responsesFor(id)[0]).toMatchObject({ ok: false, error: { code: 'UNSUPPORTED' } });
  });

  it('fails the local integrity check when the output cannot be reparsed', async () => {
    mediabunnyState.verifyVideoTrack = null;

    const id = await sendJob({
      id: 'compress-7',
      type: 'compress',
      file: new File(['x'], 'v.mp4', { type: 'video/mp4' }),
      options: compressOptions(),
    });

    expect(responsesFor(id)[0]).toMatchObject({ ok: false, error: { code: 'FAILED' } });
  });

  it('acknowledges cleanup jobs for OPFS temp files', async () => {
    const id = await sendJob({ id: 'cleanup-1', type: 'cleanup', token: 'wb-compress-token.mp4' });

    expect(responsesFor(id)).toEqual([{ id: 'cleanup-1', ok: true, result: undefined }]);
  });

  it('falls back to the bundled ffmpeg engine for undecodable sources', async () => {
    const { __instances: ffmpegInstances, __engineState } = await import('@ffmpeg/ffmpeg');
    __engineState.loadFailureFor = null;
    __engineState.mountFails = false;
    mediabunnyState.canDecodeVideoResult = false;

    const file = new File(['legacy'], 'legacy.avi', { type: 'video/x-msvideo' });
    const id = await sendJob({
      id: 'compress-8',
      type: 'compress',
      file,
      options: compressOptions({ ffmpegFallback: true, sourceFps: 90, outputName: 'legacy.mp4' }),
    });

    const response = responsesFor(id)[0];

    expect(response.ok).toBe(true);
    expect(response.result.engine).toBe('ffmpeg-wasm');

    const ffmpeg = ffmpegInstances.at(-1);
    // Not cross-origin isolated in jsdom: single-threaded core, no workerURL.
    expect(ffmpeg.loadConfig).toMatchObject({
      classWorkerURL: expect.stringContaining('ffmpeg-host'),
      coreURL: expect.stringContaining('ffmpeg-core'),
      wasmURL: expect.any(String),
    });
    expect(ffmpeg.loadConfig.workerURL).toBeUndefined();

    // WORKERFS mount streams the input instead of copying it onto the heap.
    expect(ffmpeg.mounted).toMatchObject({ mountPoint: '/wb-input' });
    expect(ffmpeg.mounted.options.files[0]).toBe(file);

    const args = ffmpeg.execArgs;
    expect(args[args.indexOf('-i') + 1]).toBe('/wb-input/legacy.avi');
    expect(args).toContain('-c:v');
    expect(args[args.indexOf('-c:v') + 1]).toBe('libx264');
    expect(args[args.indexOf('-preset') + 1]).toBe('ultrafast');
    expect(args).toContain('-map_metadata');
    expect(args[args.indexOf('-map_metadata') + 1]).toBe('-1');
    expect(args.join(' ')).toContain('fps=60');
    expect(args.join(' ')).toContain('force_original_aspect_ratio=decrease');

    // Progress from the fallback must carry the job id so the UI can show it.
    const progress = postMessageSpy.mock.calls
      .map(([message]) => message)
      .filter((message) => message.id === id && message.type === 'progress');
    expect(progress.map((message) => message.value)).toEqual([0.5]);
  });

  it('prefers the multithreaded core when cross-origin isolated', async () => {
    const { __instances: ffmpegInstances, __engineState } = await import('@ffmpeg/ffmpeg');
    __engineState.loadFailureFor = null;
    __engineState.mountFails = false;
    mediabunnyState.canDecodeVideoResult = false;

    self.crossOriginIsolated = true;

    try {
      const id = await sendJob({
        id: 'compress-mt',
        type: 'compress',
        file: new File(['legacy'], 'legacy.avi', { type: 'video/x-msvideo' }),
        options: compressOptions({ ffmpegFallback: true }),
      });

      const response = responsesFor(id)[0];

      expect(response.ok).toBe(true);
      expect(response.result.engine).toBe('ffmpeg-wasm-mt');

      const ffmpeg = ffmpegInstances.at(-1);
      expect(ffmpeg.loadConfig).toMatchObject({
        coreURL: expect.stringContaining('ffmpeg-core-mt'),
        wasmURL: expect.stringContaining('ffmpeg-core-mt'),
        workerURL: expect.stringContaining('ffmpeg-core-mt.worker'),
      });
    } finally {
      delete self.crossOriginIsolated;
    }
  });

  it('falls back to the single-threaded core when the MT core cannot start', async () => {
    const { __instances: ffmpegInstances, __engineState } = await import('@ffmpeg/ffmpeg');
    __engineState.loadFailureFor = 'ffmpeg-core-mt';
    __engineState.mountFails = false;
    mediabunnyState.canDecodeVideoResult = false;

    self.crossOriginIsolated = true;

    try {
      const id = await sendJob({
        id: 'compress-mt-fail',
        type: 'compress',
        file: new File(['legacy'], 'legacy.avi', { type: 'video/x-msvideo' }),
        options: compressOptions({ ffmpegFallback: true }),
      });

      const response = responsesFor(id)[0];

      expect(response.ok).toBe(true);
      expect(response.result.engine).toBe('ffmpeg-wasm');

      const coreURL = ffmpegInstances.at(-1).loadConfig.coreURL;
      expect(coreURL).toContain('ffmpeg-core');
      expect(coreURL).not.toContain('ffmpeg-core-mt');
    } finally {
      delete self.crossOriginIsolated;
      __engineState.loadFailureFor = null;
    }
  });

  it('copies the input through MEMFS when WORKERFS mounting fails', async () => {
    const { __instances: ffmpegInstances, __engineState } = await import('@ffmpeg/ffmpeg');
    __engineState.loadFailureFor = null;
    __engineState.mountFails = true;
    mediabunnyState.canDecodeVideoResult = false;

    const id = await sendJob({
      id: 'compress-memfs',
      type: 'compress',
      file: new File(['legacy'], 'legacy.avi', { type: 'video/x-msvideo' }),
      options: compressOptions({ ffmpegFallback: true }),
    });

    const response = responsesFor(id)[0];

    expect(response.ok).toBe(true);

    const ffmpeg = ffmpegInstances.at(-1);
    expect(ffmpeg.files.has('input.avi')).toBe(true);
    expect(ffmpeg.execArgs[ffmpeg.execArgs.indexOf('-i') + 1]).toBe('input.avi');

    __engineState.mountFails = false;
  });

  it('refuses oversized inputs for the in-browser compatibility engine', async () => {
    mediabunnyState.canDecodeVideoResult = false;

    const file = new File(['x'], 'huge.avi', { type: 'video/x-msvideo' });
    Object.defineProperty(file, 'size', { value: 600 * 1024 * 1024 });

    const id = await sendJob({
      id: 'compress-9',
      type: 'compress',
      file,
      options: compressOptions({ ffmpegFallback: true }),
    });

    expect(responsesFor(id)[0]).toMatchObject({ ok: false, error: { code: 'TOO_LARGE' } });
  });

  it('ignores jobs without an id or type', async () => {
    self.dispatchEvent(new MessageEvent('message', { data: { hello: 'world' } }));
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(postMessageSpy).not.toHaveBeenCalled();
  });
});
