import { describe, expect, it, vi } from 'vitest';
import { uploadChunks } from '../../frontend/src/lib/uploadChunks.js';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

function fileOfSize(size) {
  return new Blob([new Uint8Array(size)]);
}

describe('uploadChunks', () => {
  it('respects the concurrency bound and starts the next chunk as a slot opens', async () => {
    const pending = new Map();
    const started = [];
    let active = 0;
    let peak = 0;
    const sendChunk = vi.fn((index, chunk) => {
      started.push(index);
      expect(chunk.size).toBe(index === 4 ? 1 : 4);
      active++;
      peak = Math.max(peak, active);
      const gate = deferred();
      pending.set(index, gate);
      return gate.promise.finally(() => { active--; });
    });

    const task = uploadChunks({ file: fileOfSize(17), chunkSize: 4, concurrency: 2, sendChunk });
    await Promise.resolve();
    expect(started).toEqual([0, 1]);
    expect(peak).toBe(2);

    pending.get(1).resolve();
    await vi.waitFor(() => expect(started).toEqual([0, 1, 2]));

    pending.get(0).resolve();
    pending.get(2).resolve();
    await vi.waitFor(() => expect(started).toEqual([0, 1, 2, 3, 4]));
    pending.get(3).resolve();
    pending.get(4).resolve();
    await task;
    expect(started).toEqual([0, 1, 2, 3, 4]);
    expect(peak).toBeLessThanOrEqual(2);
  });

  it('reports monotonic progress for chunks completed out of order', async () => {
    const pending = new Map();
    const progress = [];
    const task = uploadChunks({
      file: fileOfSize(10),
      chunkSize: 4,
      concurrency: 3,
      sendChunk: (index) => {
        const gate = deferred();
        pending.set(index, gate);
        return gate.promise;
      },
      onProgress: (value) => progress.push(value),
    });

    await Promise.resolve();
    expect([...pending.keys()]).toEqual([0, 1, 2]);
    pending.get(2).resolve();
    await Promise.resolve();
    pending.get(0).resolve();
    await Promise.resolve();
    pending.get(1).resolve();
    await task;

    expect(progress.length).toBeGreaterThan(0);
    expect(progress.every((value) => Number.isFinite(value))).toBe(true);
    expect(progress.every((value, index) => index === 0 || value >= progress[index - 1])).toBe(true);
    expect(progress.at(-1)).toBe(1);
  });

  it('waits for active requests to settle and stops scheduling after an error', async () => {
    const pending = new Map();
    const started = [];
    let settled = false;
    const task = uploadChunks({
      file: fileOfSize(16),
      chunkSize: 4,
      concurrency: 2,
      sendChunk: (index) => {
        started.push(index);
        const gate = deferred();
        pending.set(index, gate);
        return gate.promise;
      },
    });
    task.catch(() => { settled = true; });

    await Promise.resolve();
    expect(started).toEqual([0, 1]);
    pending.get(0).reject(new Error('chunk failed'));
    await Promise.resolve();
    await Promise.resolve();
    expect(settled).toBe(false);
    expect(started).toEqual([0, 1]);

    pending.get(1).resolve();
    await expect(task).rejects.toThrow('chunk failed');
    expect(started).toEqual([0, 1]);
  });

  it('uploads empty files as a single zero-byte chunk', async () => {
    const sendChunk = vi.fn(async () => {});
    await uploadChunks({ file: fileOfSize(0), chunkSize: 4, concurrency: 2, sendChunk });
    expect(sendChunk).toHaveBeenCalledTimes(1);
    expect(sendChunk.mock.calls[0][0]).toBe(0);
    expect(sendChunk.mock.calls[0][1].size).toBe(0);
  });
});
