import {
  compressedFileName,
  computeTargetAudioBitrate,
  computeTargetDimensions,
  computeTargetVideoBitrate,
  fileExtensionOf,
  formatBytesPlain,
  hasSufficientSavings,
  inspectSummaryIsCompliant,
  isVideoCandidate,
  normalizeVideoPolicy,
  shouldConsiderForCompression,
} from '../../frontend/src/lib/videoCompressionPolicy.js';

function policy(overrides = {}) {
  return normalizeVideoPolicy({
    video_compression: {
      mode: 'required',
      max_width: 1920,
      max_height: 1080,
      max_fps: 60,
      max_video_bitrate_kbps: 8000,
      max_audio_bitrate_kbps: 192,
      min_source_mb: 20,
      min_savings_pct: 5,
      ffmpeg_fallback: true,
      ...overrides,
    },
  });
}

function compliantInfo(overrides = {}) {
  return {
    container: 'MP4',
    videoCodec: 'avc',
    width: 1920,
    height: 1080,
    fps: 30,
    videoBitrate: 5_000_000,
    audioCodec: 'aac',
    audioBitrate: 128_000,
    videoTrackCount: 1,
    audioTrackCount: 1,
    duration: 42,
    ...overrides,
  };
}

describe('normalizeVideoPolicy', () => {
  it('falls back to safe defaults for missing or malformed input', () => {
    const normalized = normalizeVideoPolicy(null);

    expect(normalized.mode).toBe('off');
    expect(normalized.max_width).toBe(1920);
    expect(normalized.max_height).toBe(1080);
    expect(normalized.max_fps).toBe(60);
    expect(normalized.min_source_bytes).toBe(20 * 1024 * 1024);
    expect(normalized.ffmpeg_fallback).toBe(true);
  });

  it('rejects unknown modes and clamps savings', () => {
    expect(normalizeVideoPolicy({ video_compression: { mode: 'sometimes' } }).mode).toBe('off');
    expect(normalizeVideoPolicy({ video_compression: { min_savings_pct: 500 } }).min_savings_pct).toBe(90);
  });
});

describe('isVideoCandidate / shouldConsiderForCompression', () => {
  it('detects videos by MIME type and by extension', () => {
    expect(isVideoCandidate({ name: 'clip.bin', type: 'video/mp4' })).toBe(true);
    expect(isVideoCandidate({ name: 'clip.mkv', type: '' })).toBe(true);
    expect(isVideoCandidate({ name: 'notes.txt', type: 'text/plain' })).toBe(false);
  });

  it('ignores videos when the policy is off or the file is below the threshold', () => {
    const small = { name: 'clip.mp4', size: 1024, type: 'video/mp4' };
    const large = { name: 'clip.mp4', size: 21 * 1024 * 1024, type: 'video/mp4' };

    expect(shouldConsiderForCompression(large, policy({ mode: 'off' }))).toBe(false);
    expect(shouldConsiderForCompression(small, policy())).toBe(false);
    expect(shouldConsiderForCompression(large, policy())).toBe(true);
  });
});

describe('inspectSummaryIsCompliant', () => {
  it('accepts a compliant H.264/AAC MP4', () => {
    expect(inspectSummaryIsCompliant(compliantInfo(), policy())).toBe(true);
  });

  it('accepts portrait video within swapped bounds', () => {
    expect(inspectSummaryIsCompliant(compliantInfo({ width: 1080, height: 1920 }), policy())).toBe(true);
  });

  it('rejects wrong container, codec, resolution, frame rate, and bitrates', () => {
    const p = policy();
    expect(inspectSummaryIsCompliant(compliantInfo({ container: 'matroska' }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ container: 'QuickTime File Format' }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ videoCodec: 'vp9' }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ audioCodec: 'opus' }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ width: 3840, height: 2160 }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ fps: 144 }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ videoBitrate: 20_000_000 }), p)).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ audioBitrate: 400_000 }), p)).toBe(false);
  });

  it('treats silent video as compliant', () => {
    expect(inspectSummaryIsCompliant(compliantInfo({ audioCodec: null, audioBitrate: null, audioTrackCount: 0 }), policy())).toBe(true);
  });

  it('rejects multi-track layouts the server would refuse', () => {
    expect(inspectSummaryIsCompliant(compliantInfo({ videoTrackCount: 2 }), policy())).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ audioTrackCount: 2 }), policy())).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ videoTrackCount: 0 }), policy())).toBe(false);
  });

  it('fails closed on unknown values', () => {
    expect(inspectSummaryIsCompliant(compliantInfo({ fps: null }), policy())).toBe(false);
    expect(inspectSummaryIsCompliant(compliantInfo({ videoBitrate: null }), policy())).toBe(false);
    expect(inspectSummaryIsCompliant(null, policy())).toBe(false);
  });
});

describe('compressedFileName', () => {
  it('rewrites the extension to .mp4 and keeps folder-free stems', () => {
    expect(compressedFileName('vacation.mov')).toBe('vacation.mp4');
    expect(compressedFileName('a.b.c.mkv')).toBe('a.b.c.mp4');
    expect(compressedFileName('clip')).toBe('clip.mp4');
    expect(compressedFileName('weird.old.mp4')).toBe('weird.old.mp4');
  });
});

describe('hasSufficientSavings', () => {
  it('requires the configured minimum percentage', () => {
    expect(hasSufficientSavings(1000, 500, 5)).toBe(true);
    expect(hasSufficientSavings(1000, 960, 5)).toBe(false);
    expect(hasSufficientSavings(1000, 1000, 5)).toBe(false);
    expect(hasSufficientSavings(1000, 1100, 5)).toBe(false);
    expect(hasSufficientSavings(0, 100, 5)).toBe(false);
  });
});

describe('computeTargetDimensions', () => {
  it('never upscales and only downscales to even numbers', () => {
    expect(computeTargetDimensions(1280, 720, policy())).toEqual({ width: 1280, height: 720 });
    expect(computeTargetDimensions(1920, 1080, policy())).toEqual({ width: 1920, height: 1080 });

    const downscaled = computeTargetDimensions(3840, 2160, policy());
    expect(downscaled.width).toBe(1920);
    expect(downscaled.height).toBe(1080);

    const odd = computeTargetDimensions(1279, 719, policy());
    expect(odd.width % 2).toBe(0);
    expect(odd.height % 2).toBe(0);
  });

  it('allows portrait video that fits the swapped bounds untouched', () => {
    expect(computeTargetDimensions(1080, 1920, policy())).toEqual({ width: 1080, height: 1920 });
  });
});

describe('computeTargetVideoBitrate / computeTargetAudioBitrate', () => {
  it('targets below the server cap with headroom, bounded by the source', () => {
    const p = policy();
    expect(computeTargetVideoBitrate(null, p)).toBe(Math.round(8000 * 1000 * 0.8));
    expect(computeTargetVideoBitrate(4_000_000, p)).toBe(Math.round(4_000_000 * 0.85));
    expect(computeTargetVideoBitrate(50_000_000, p)).toBe(Math.round(8000 * 1000 * 0.8));
    expect(computeTargetAudioBitrate(p)).toBe(Math.round(192 * 1000 * 0.9));
  });
});

describe('fileExtensionOf / formatBytesPlain', () => {
  it('extracts lowercase extensions', () => {
    expect(fileExtensionOf('Movie.MOV')).toBe('mov');
    expect(fileExtensionOf('noext')).toBe('');
  });

  it('formats byte counts like the app does', () => {
    expect(formatBytesPlain(0)).toBe('0 B');
    expect(formatBytesPlain(2048)).toBe('2.0 KB');
    expect(formatBytesPlain(24 * 1024)).toBe('24 KB');
    expect(formatBytesPlain(5 * 1024 * 1024)).toBe('5.0 MB');
    expect(formatBytesPlain(50 * 1024 * 1024)).toBe('50 MB');
  });
});
