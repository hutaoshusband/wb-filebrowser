// Visualize the samples currently playing. Never fetch or decode a second copy
// of the file: memory use stays fixed even for long or highly compressed audio.
export function attachAudioVisualizer(media, canvas) {
  let context, source, analyser, data;
  let frame = 0;
  let disposed = false;
  let attempted = false;
  let lastDraw = -Infinity;

  function draw() {
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (!width || !height) return;
    const g = canvas.getContext('2d');
    if (!g) return;
    const dpr = Math.min(globalThis.devicePixelRatio || 1, 2);
    if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
      canvas.width = Math.round(width * dpr);
      canvas.height = Math.round(height * dpr);
    }
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, width, height);
    const playing = !media.paused && !media.ended && !media.seeking;
    if (playing && analyser) analyser.getByteFrequencyData(data);
    const bars = 64;
    const step = width / bars;
    g.fillStyle = '#30d158';
    for (let i = 0; i < bars; i++) {
      const energy = playing && data ? data[Math.floor(i / bars * data.length * 0.75)] / 255 : 0;
      const barHeight = Math.max(2, energy * (height - 8));
      g.fillRect(i * step, (height - barHeight) / 2, Math.max(1, step - 3), barHeight);
    }
  }

  function prepare() {
    if (disposed) return;
    if (!attempted) {
      attempted = true;
      const AudioContextCtor = globalThis.AudioContext || globalThis.webkitAudioContext;
      // MediaElementSource silences media from a different origin without CORS.
      if (!AudioContextCtor || new URL(media.currentSrc || media.src, location.href).origin !== location.origin) return;
      try {
        context = new AudioContextCtor();
        analyser = context.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.7;
        data = new Uint8Array(analyser.frequencyBinCount);
        source = context.createMediaElementSource(media);
        source.connect(analyser);
        analyser.connect(context.destination);
      } catch {
        // If routing started, restore a direct output before giving up analysis.
        try { source?.disconnect(); source?.connect(context.destination); } catch { /* best effort */ }
        analyser = null;
        data = null;
      }
    }
    if (context?.state === 'suspended') context.resume().catch(() => {});
  }

  function stop() {
    if (frame) cancelAnimationFrame(frame);
    frame = 0;
    draw();
  }

  function tick(time) {
    frame = 0;
    if (disposed || media.paused || media.ended || document.hidden) return;
    // Cap canvas work at 30fps, including on high-refresh-rate displays.
    if (time - lastDraw >= 1000 / 30) { draw(); lastDraw = time; }
    frame = requestAnimationFrame(tick);
  }

  function start() {
    if (disposed || media.paused || media.ended || document.hidden) return;
    prepare();
    if (!frame && analyser) frame = requestAnimationFrame(tick);
  }
  function visibility() { if (document.hidden) stop(); else start(); }
  const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(draw) : null;
  observer?.observe(canvas);
  const events = { play: start, pause: stop, ended: stop, seeking: draw, seeked: draw, loadedmetadata: draw };
  for (const [name, handler] of Object.entries(events)) media.addEventListener(name, handler);
  document.addEventListener('visibilitychange', visibility);
  window.addEventListener('resize', draw);
  draw();
  return {
    prepare,
    dispose() {
      disposed = true;
      stop();
      observer?.disconnect();
      for (const [name, handler] of Object.entries(events)) media.removeEventListener(name, handler);
      document.removeEventListener('visibilitychange', visibility);
      window.removeEventListener('resize', draw);
      source?.disconnect();
      analyser?.disconnect();
      context?.close().catch(() => {});
      data = null;
    },
  };
}
