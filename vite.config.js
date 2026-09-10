import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';

export default defineConfig({
  base: './',
  plugins: [vue()],
  // The video compression worker (and the ffmpeg fallback it loads) needs
  // ES module workers: dynamic imports and import.meta.url asset resolution
  // don't survive the classic-worker transformation.
  worker: {
    format: 'es'
  },
  test: {
    environment: 'jsdom',
    globals: true
  },
  build: {
    outDir: resolve(__dirname, 'assets'),
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: resolve(__dirname, 'frontend/src/main.js'),
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'app.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'app.css';
          }

          return '[name][extname]';
        },
        inlineDynamicImports: true
      }
    }
  }
});
