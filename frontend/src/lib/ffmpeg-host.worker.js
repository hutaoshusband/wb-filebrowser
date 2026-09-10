// Bundled copy of @ffmpeg/ffmpeg's worker implementation, emitted by Vite as
// a self-contained module worker asset. The FFmpeg class instantiated inside
// our compression worker points its `classWorkerURL` at this file; it cannot
// resolve its own relative worker import there because the compression worker
// may be a blob or cross-directory URL.
import '@ffmpeg/ffmpeg/worker';
