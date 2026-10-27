export async function uploadChunks({ file, chunkSize, sendChunk, onProgress, concurrency = 4 }) {
  const count = Math.max(1, Math.ceil(file.size / chunkSize));
  const workers = Math.min(count, Math.max(1, Math.floor(concurrency)));
  let nextIndex = 0;
  let completedBytes = 0;
  let completedChunks = 0;
  let failure = null;

  await Promise.all(Array.from({ length: workers }, async () => {
    while (nextIndex < count && failure === null) {
      const index = nextIndex++;
      const start = index * chunkSize;
      const chunk = file.slice(start, Math.min(file.size, start + chunkSize));
      try {
        await sendChunk(index, chunk);
        completedBytes += chunk.size;
        completedChunks++;
        onProgress?.(file.size === 0 ? 1 : completedBytes / file.size, completedChunks);
      } catch (error) {
        failure ??= error;
      }
    }
  }));

  if (failure !== null) {
    throw failure;
  }
}
