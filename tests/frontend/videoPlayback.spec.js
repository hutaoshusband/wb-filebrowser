import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { attachVideoPlayback, bufferedAhead, bufferTarget } from '../../media/videoPlayback.js';

let player;
let media;
let ranges;
let current;
let duration;
let ready;
let paused;

function event(name) { media.dispatchEvent(new Event(name)); }

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(performance, 'now').mockImplementation(() => Date.now());
  document.body.innerHTML = '<div class="media-player"><div class="media-player__stage"><video src="/movie.mp4"></video></div></div>';
  media = document.querySelector('video');
  ranges = [];
  current = 0;
  duration = 120;
  ready = 2;
  paused = true;
  Object.defineProperties(media, {
    buffered: { get: () => ({ length: ranges.length, start: i => ranges[i][0], end: i => ranges[i][1] }) },
    currentTime: { get: () => current, set: value => { current = value; } },
    duration: { get: () => duration },
    readyState: { get: () => ready },
    paused: { get: () => paused },
  });
  media.play = vi.fn(() => { paused = false; return Promise.resolve(); });
  media.pause = vi.fn(() => { paused = true; });
  media.load = vi.fn();
  player = attachVideoPlayback(media);
});

afterEach(() => {
  player.dispose();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('native video buffering', () => {
  it('only counts the contiguous range containing the playhead', () => {
    ranges = [[0, 2], [20, 60]];
    current = 1;
    expect(bufferedAhead(media)).toBe(1);
    current = 10;
    expect(bufferedAhead(media)).toBe(0);
  });

  it('bounds targets on slow links and adjusts for playback speed and short clips', () => {
    expect(bufferTarget(600, 0.5)).toBe(10);
    expect(bufferTarget(600, 0.5, true)).toBe(15);
    expect(bufferTarget(600, 3)).toBe(3);
    expect(bufferTarget(600, 3, false, 2)).toBe(6);
    expect(bufferTarget(1.5, null)).toBe(1.5);
    expect(bufferTarget(Infinity, 3)).toBe(3);
    expect(bufferTarget(Infinity, 0.5)).toBe(10);
  });

  it('does no background prefetch until play and gates the first click', () => {
    expect(media.play).not.toHaveBeenCalled();
    expect(vi.getTimerCount()).toBe(0);
    player.toggle();
    expect(player.wanted).toBe(true);
    expect(media.paused).toBe(true);
    expect(media.preload).toBe('auto');
    expect(document.querySelector('[role="status"]').textContent).toBe('');
    expect(document.querySelector('.media-player__spinner')).not.toBe(null);
    expect(document.querySelector('.media-player__buffer progress, .media-player__buffer button')).toBe(null);
    expect(media.closest('.media-player').classList.contains('is-buffering')).toBe(true);
    ranges = [[0, 4]];
    vi.advanceTimersByTime(1000);
    expect(media.paused).toBe(false);
    expect(document.querySelector('.media-player__buffer').hidden).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('increases the cushion when delivery is slower than consumption', () => {
    player.toggle();
    ranges = [[0, 1]];
    vi.advanceTimersByTime(4000);
    ranges = [[0, 3.2]];
    event('progress');
    expect(media.paused).toBe(true);
    ranges = [[0, 10]];
    event('progress');
    expect(media.paused).toBe(false);
  });

  it('does not wait for a full long video and escapes after eight seconds', () => {
    player.toggle();
    ranges = [[0, 0.75]];
    vi.advanceTimersByTime(8000);
    expect(media.paused).toBe(false);
  });

  it('does not start a timer-driven play/wait loop when no data arrives', () => {
    player.toggle();
    ready = 1;
    vi.advanceTimersByTime(20000);
    expect(media.play).toHaveBeenCalledTimes(1);
    expect(media.paused).toBe(true);
    expect(document.querySelector('.media-player__buffer').hidden).toBe(false);
    expect(document.querySelector('[role="status"]').textContent).toBe('');
  });

  it('escapes browsers that suspend paused preloads before the target', () => {
    player.toggle();
    ranges = [[0, 1.5]];
    event('suspend');
    vi.advanceTimersByTime(1500);
    expect(media.paused).toBe(false);
  });

  it('starts short and already buffered clips immediately', () => {
    duration = 1;
    ranges = [[0, 1]];
    player.toggle();
    expect(media.paused).toBe(false);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('holds playback during recovery instead of resuming tiny fragments', () => {
    ranges = [[0, 5]];
    player.toggle();
    current = 5;
    event('waiting');
    expect(media.paused).toBe(true);
    expect(player.wanted).toBe(true);
    ranges = [[0, 7]];
    vi.advanceTimersByTime(1000);
    expect(media.paused).toBe(true);
    ranges = [[0, 20]];
    event('progress');
    expect(media.paused).toBe(false);
  });

  it('cancels pending playback and timers on a second click', () => {
    player.toggle();
    player.toggle();
    ranges = [[0, 20]];
    event('progress');
    vi.advanceTimersByTime(15000);
    expect(media.paused).toBe(true);
    expect(player.wanted).toBe(false);
    expect(vi.getTimerCount()).toBe(0);
  });

  it('rebuilds the buffer at a seek destination instead of using old downloaded ranges', () => {
    ranges = [[0, 30]];
    player.toggle();
    current = 40;
    event('seeking');
    expect(media.paused).toBe(true);
    ranges = [[0, 30], [40, 41]];
    event('progress');
    expect(media.paused).toBe(true);
    ranges = [[0, 30], [40, 47]];
    event('seeked');
    expect(media.paused).toBe(false);
  });

  it('uses the ordinary Play control if browser policy rejects automatic resume', async () => {
    player.toggle();
    media.play.mockRejectedValueOnce(new DOMException('Gesture required', 'NotAllowedError'));
    ranges = [[0, 10]];
    event('progress');
    await Promise.resolve();
    expect(document.querySelector('.media-player__buffer').hidden).toBe(true);
    expect(player.wanted).toBe(false);
    player.toggle();
    expect(media.play).toHaveBeenCalledTimes(3);
  });

  it('aborts the request and removes timers/listeners when the preview closes', () => {
    player.toggle();
    const playCalls = media.play.mock.calls.length;
    player.dispose();
    event('waiting');
    expect(media.getAttribute('src')).toBe(null);
    expect(media.load).toHaveBeenCalledOnce();
    expect(vi.getTimerCount()).toBe(0);
    expect(document.querySelector('.media-player__buffer')).toBe(null);
    expect(media.play).toHaveBeenCalledTimes(playCalls);
  });
});
