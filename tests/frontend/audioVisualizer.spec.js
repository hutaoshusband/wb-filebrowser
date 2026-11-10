import { afterEach, expect, it, vi } from 'vitest';
import { attachAudioVisualizer } from '../../media/audioVisualizer.js';

afterEach(() => vi.unstubAllGlobals());

it('uses only streaming audio, stops frames on pause and releases resources on disposal', () => {
  const fetch = vi.fn();
  const decodeAudioData = vi.fn();
  const close = vi.fn().mockResolvedValue();
  const disconnect = vi.fn();
  const analyser = { fftSize: 0, frequencyBinCount: 128, connect: vi.fn(), disconnect, getByteFrequencyData: vi.fn() };
  const source = { connect: vi.fn(), disconnect };
  vi.stubGlobal('fetch', fetch);
  vi.stubGlobal('AudioContext', class {
    state = 'running'; destination = {}; close = close; decodeAudioData = decodeAudioData;
    createMediaElementSource() { return source; }
    createAnalyser() { return analyser; }
  });
  const frames = new Map(); let id = 0;
  vi.stubGlobal('requestAnimationFrame', vi.fn(cb => { frames.set(++id, cb); return id; }));
  vi.stubGlobal('cancelAnimationFrame', vi.fn(id => frames.delete(id)));
  const media = document.createElement('audio');
  media.src = '/audio.wav';
  let paused = true;
  Object.defineProperty(media, 'paused', { get: () => paused });
  const canvas = document.createElement('canvas');
  Object.defineProperty(canvas, 'clientWidth', { value: 400 });
  Object.defineProperty(canvas, 'clientHeight', { value: 180 });
  canvas.getContext = () => ({ setTransform() {}, clearRect() {}, fillRect() {} });
  const visualizer = attachAudioVisualizer(media, canvas);
  expect(frames.size).toBe(0);
  paused = false; media.dispatchEvent(new Event('play'));
  expect(frames.size).toBe(1);
  expect(fetch).not.toHaveBeenCalled();
  expect(decodeAudioData).not.toHaveBeenCalled();
  paused = true; media.dispatchEvent(new Event('pause'));
  expect(frames.size).toBe(0);
  paused = false; media.dispatchEvent(new Event('play'));
  visualizer.dispose();
  expect(frames.size).toBe(0);
  expect(close).toHaveBeenCalledOnce();
  expect(disconnect).toHaveBeenCalledTimes(2);
  media.dispatchEvent(new Event('play'));
  expect(frames.size).toBe(0);
});
