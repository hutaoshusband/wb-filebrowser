// Pure decision logic for client-side video compression. Mirrors the checks
// the PHP server enforces with ffprobe (app/MediaValidator.php): when the
// client says a file is already compliant, the server must agree, so unknown
// values fail closed here (the file simply gets compressed, which is always
// safe) while the server stays lenient on metrics it cannot read.

export const VIDEO_FILE_EXTENSIONS = [
  'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'wmv', 'flv', 'mpg', 'mpeg',
  'mp2v', 'm2ts', 'mts', 'ts', '3gp', '3g2', 'ogv', 'divx', 'vob', 'mxf',
  'asf', 'rm', 'rmvb', 'f4v', 'm1v', 'm2v', 'mpe', 'ogm', 'qt', 'svi',
  'amv', 'drc', 'mng', 'roq', 'viv', 'wtv', 'yuv', 'dv', 'bik',
];

const FPS_TOLERANCE = 0.5;
const STREAM_BITRATE_TOLERANCE = 1.1;

export function createDefaultVideoPolicy() {
  return {
    mode: 'off',
    max_width: 1920,
    max_height: 1080,
    max_fps: 60,
    max_video_bitrate_kbps: 8000,
    max_audio_bitrate_kbps: 192,
    min_source_mb: 20,
    min_savings_pct: 5,
    ffmpeg_fallback: true,
  };
}

export function normalizeVideoPolicy(uploadPolicy) {
  const raw = uploadPolicy?.video_compression ?? {};
  const defaults = createDefaultVideoPolicy();
  const policy = { ...defaults, ...raw };

  policy.mode = ['off', 'optional', 'required'].includes(policy.mode) ? policy.mode : 'off';
  policy.max_width = toPositiveInt(policy.max_width, defaults.max_width);
  policy.max_height = toPositiveInt(policy.max_height, defaults.max_height);
  policy.max_fps = toPositiveInt(policy.max_fps, defaults.max_fps);
  policy.max_video_bitrate_kbps = toPositiveInt(policy.max_video_bitrate_kbps, defaults.max_video_bitrate_kbps);
  policy.max_audio_bitrate_kbps = toPositiveInt(policy.max_audio_bitrate_kbps, defaults.max_audio_bitrate_kbps);
  // These two allow zero as a meaningful "no threshold" value.
  policy.min_source_mb = Math.min(20480, Math.max(0, toNonNegativeInt(policy.min_source_mb, defaults.min_source_mb)));
  policy.min_savings_pct = Math.min(90, Math.max(0, toNonNegativeInt(policy.min_savings_pct, defaults.min_savings_pct)));
  policy.ffmpeg_fallback = policy.ffmpeg_fallback !== false;
  policy.min_source_bytes = policy.min_source_mb * 1024 * 1024;

  return policy;
}

export function fileExtensionOf(name) {
  const match = /\.([a-z0-9]+)$/i.exec(String(name ?? ''));

  return match ? match[1].toLowerCase() : '';
}

export function isVideoCandidate(file) {
  if (!file) {
    return false;
  }

  if (typeof file.type === 'string' && file.type.startsWith('video/')) {
    return true;
  }

  return VIDEO_FILE_EXTENSIONS.includes(fileExtensionOf(file.name));
}

export function shouldConsiderForCompression(file, policy) {
  if (policy.mode === 'off') {
    return false;
  }

  if (!isVideoCandidate(file)) {
    return false;
  }

  return file.size >= policy.min_source_bytes;
}

/**
 * Decides whether an inspected video already satisfies the server policy, so
 * compression can be skipped entirely. `info` is the summary produced by the
 * compression worker's inspect job. Anything unknown counts as
 * non-compliant - re-encoding is always accepted by the server.
 *
 * Mirrors MediaValidator's stream-layout rules (one video track, at most one
 * audio track). Subtitle tracks are invisible to the client-side inspector,
 * so an MP4 carrying one can still be rejected server-side in required mode;
 * that residual case surfaces with a clear message rather than a dead end,
 * because any re-encode here strips them.
 */
export function inspectSummaryIsCompliant(info, policy) {
  if (!info) {
    return false;
  }

  // Mediabunny reports the container as "MP4" (capitalized); MOV reports as
  // "QuickTime File Format" and stays non-compliant under this rule.
  if (String(info.container ?? '').toLowerCase() !== 'mp4') {
    return false;
  }

  if (info.videoCodec !== 'avc') {
    return false;
  }

  const videoTrackCount = info.videoTrackCount ?? 0;
  const audioTrackCount = info.audioTrackCount ?? 0;

  if (videoTrackCount !== 1 || audioTrackCount > 1) {
    return false;
  }

  const width = info.width ?? 0;
  const height = info.height ?? 0;

  if (width <= 0 || height <= 0) {
    return false;
  }

  const fitsLandscape = width <= policy.max_width && height <= policy.max_height;
  const fitsPortrait = width <= policy.max_height && height <= policy.max_width;

  if (!fitsLandscape && !fitsPortrait) {
    return false;
  }

  const fps = info.fps ?? null;

  if (fps === null || fps <= 0) {
    return false;
  }

  if (fps > policy.max_fps + FPS_TOLERANCE) {
    return false;
  }

  if (info.videoBitrate === null || info.videoBitrate === undefined) {
    return false;
  }

  if (info.videoBitrate > policy.max_video_bitrate_kbps * 1000 * STREAM_BITRATE_TOLERANCE) {
    return false;
  }

  if (info.audioCodec === null || info.audioCodec === undefined) {
    return true;
  }

  if (info.audioCodec !== 'aac') {
    return false;
  }

  if (info.audioBitrate === null || info.audioBitrate === undefined) {
    return false;
  }

  return info.audioBitrate <= policy.max_audio_bitrate_kbps * 1000 * STREAM_BITRATE_TOLERANCE;
}

export function compressedFileName(name) {
  const base = String(name ?? 'video').replace(/\.[^./\\]+$/, '');

  return `${base || 'video'}.mp4`;
}

export function hasSufficientSavings(originalSize, newSize, minSavingsPct) {
  if (!(newSize > 0) || !(originalSize > 0)) {
    return false;
  }

  if (newSize >= originalSize) {
    return false;
  }

  const savedPct = ((originalSize - newSize) / originalSize) * 100;

  return savedPct >= Math.max(0, minSavingsPct);
}

/**
 * Computes the even-pixel output dimensions for a source video under the
 * policy: only ever downscale, never upscale, aspect ratio preserved.
 */
export function computeTargetDimensions(width, height, policy) {
  const fitsLandscape = width <= policy.max_width && height <= policy.max_height;
  const fitsPortrait = width <= policy.max_height && height <= policy.max_width;

  if (fitsLandscape || fitsPortrait) {
    return { width: evenFloor(width), height: evenFloor(height) };
  }

  const scale = Math.min(policy.max_width / width, policy.max_height / height);

  return { width: evenFloor(width * scale), height: evenFloor(height * scale) };
}

/**
 * The bitrate the client encoder should target: comfortably below the server
 * cap (VBR overshoot headroom), but never more than ~85% of the source.
 */
export function computeTargetVideoBitrate(sourceBitrate, policy) {
  const cap = policy.max_video_bitrate_kbps * 1000 * 0.8;
  const floor = 500000;

  if (!sourceBitrate || sourceBitrate <= 0) {
    return Math.round(cap);
  }

  return Math.round(Math.min(cap, Math.max(floor, sourceBitrate * 0.85)));
}

export function computeTargetAudioBitrate(policy) {
  return Math.round(policy.max_audio_bitrate_kbps * 1000 * 0.9);
}

export function formatBytesPlain(bytes) {
  if (!Number.isFinite(bytes) || bytes === 0) {
    return '0 B';
  }

  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  const scaled = bytes / (1024 ** power);

  return `${scaled >= 10 || power === 0 ? Math.round(scaled) : scaled.toFixed(1)} ${units[power]}`;
}

function toPositiveInt(value, fallback) {
  const parsed = Number.parseInt(value, 10);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function toNonNegativeInt(value, fallback) {
  const parsed = Number.parseInt(value, 10);

  return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
}

function evenFloor(value) {
  return Math.max(2, Math.floor(value / 2) * 2);
}
