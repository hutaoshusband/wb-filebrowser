function readAllDirectoryEntries(reader) {
  return new Promise((resolve, reject) => {
    const entries = [];

    const readNextBatch = () => {
      reader.readEntries((batch) => {
        if (!Array.isArray(batch) || batch.length === 0) {
          resolve(entries);
          return;
        }

        entries.push(...batch);
        readNextBatch();
      }, reject);
    };

    readNextBatch();
  });
}

function fileFromEntry(entry) {
  return new Promise((resolve, reject) => {
    entry.file(resolve, reject);
  });
}

async function walkEntry(entry, pathSegments, files, emptyDirectories) {
  if (!entry) {
    return;
  }

  if (entry.isFile) {
    const file = await fileFromEntry(entry);
    files.push({
      file,
      relativePathSegments: pathSegments,
      relativePath: [...pathSegments, file.name].join('/'),
    });
    return;
  }

  if (!entry.isDirectory) {
    return;
  }

  const reader = entry.createReader?.();
  const children = reader ? await readAllDirectoryEntries(reader) : [];
  const nextSegments = [...pathSegments, entry.name];

  if (children.length === 0) {
    emptyDirectories.push({
      relativePathSegments: nextSegments,
      relativePath: nextSegments.join('/'),
    });
    return;
  }

  for (const child of children) {
    await walkEntry(child, nextSegments, files, emptyDirectories);
  }
}

export async function collectDroppedItems(dataTransfer) {
  const items = Array.from(dataTransfer?.items ?? []);
  const files = [];
  const emptyDirectories = [];
  const entryItems = items
    .map((item) => (typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null))
    .filter(Boolean);

  if (entryItems.length > 0) {
    for (const entry of entryItems) {
      await walkEntry(entry, [], files, emptyDirectories);
    }

    return {
      files,
      emptyDirectories,
      usedEntryApi: true,
    };
  }

  return {
    files: Array.from(dataTransfer?.files ?? []).map((file) => ({
      file,
      relativePathSegments: [],
      relativePath: file.name,
    })),
    emptyDirectories: [],
    usedEntryApi: false,
  };
}
