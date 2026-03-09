import { describe, expect, it } from 'vitest';
import { collectDroppedItems } from '../../frontend/src/lib/folderDrop.js';

function createFileEntry(name, fileContents, path = '') {
  return {
    isFile: true,
    isDirectory: false,
    name,
    file: (resolve) => {
      const file = new File([fileContents], name, { type: 'text/plain' });
      Object.defineProperty(file, 'webkitRelativePath', {
        value: path,
        configurable: true,
      });
      resolve(file);
    },
  };
}

function createDirectoryEntry(name, children = []) {
  return {
    isFile: false,
    isDirectory: true,
    name,
    createReader() {
      let index = 0;

      return {
        readEntries(resolve) {
          if (index > 0) {
            resolve([]);
            return;
          }

          index += 1;
          resolve(children);
        },
      };
    },
  };
}

describe('collectDroppedItems', () => {
  it('walks webkit entries recursively and reports empty directories separately', async () => {
    const dataTransfer = {
      items: [
        {
          kind: 'file',
          webkitGetAsEntry: () => createDirectoryEntry('Projects', [
            createDirectoryEntry('2026', [
              createFileEntry('brief.txt', 'notes'),
            ]),
            createDirectoryEntry('Empty'),
          ]),
        },
      ],
      files: [],
    };

    const result = await collectDroppedItems(dataTransfer);

    expect(result.usedEntryApi).toBe(true);
    expect(result.files).toHaveLength(1);
    expect(result.files[0].relativePathSegments).toEqual(['Projects', '2026']);
    expect(result.files[0].relativePath).toBe('Projects/2026/brief.txt');
    expect(result.emptyDirectories).toEqual([
      {
        relativePath: 'Projects/Empty',
        relativePathSegments: ['Projects', 'Empty'],
      },
    ]);
  });

  it('falls back to flat files when the entries API is unavailable', async () => {
    const file = new File(['hello'], 'plain.txt', { type: 'text/plain' });
    const dataTransfer = {
      items: [],
      files: [file],
    };

    const result = await collectDroppedItems(dataTransfer);

    expect(result.usedEntryApi).toBe(false);
    expect(result.files).toEqual([
      {
        file,
        relativePath: 'plain.txt',
        relativePathSegments: [],
      },
    ]);
    expect(result.emptyDirectories).toEqual([]);
  });
});
