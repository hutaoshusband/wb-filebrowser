// Keep decoding and range downloads in the native player. Buffer growth measures
// delivery in playable seconds/second, avoiding a duplicate bandwidth probe.
export function bufferedAhead(media) {
  for (let i = 0; i < media.buffered.length; i++) {
    if (media.buffered.start(i) <= media.currentTime + 0.05 && media.buffered.end(i) > media.currentTime) {
      return media.buffered.end(i) - media.currentTime;
    }
  }
  return 0;
}

export function bufferTarget(remaining, rate, recovery = false, playbackRate = 1) {
  const floor = recovery ? 6 : 3;
  const ceiling = recovery ? 15 : 10;
  // If delivery cannot keep up, save some of the predicted deficit, but never
  // make a long video wait for a complete download. Rates include a 20% margin.
  const deficit = rate === null ? floor : rate * 0.8 >= playbackRate ? 0
    : Number.isFinite(remaining) ? remaining * (1 - rate * 0.8 / playbackRate) : ceiling * playbackRate;
  return Math.min(remaining, Math.max(floor * playbackRate, Math.min(ceiling * playbackRate, deficit)));
}

export function attachVideoPlayback(media, { onState = () => {} } = {}) {
  const root = media.closest('.media-player');
  const stage = root.querySelector('.media-player__stage');
  const overlay = document.createElement('div');
  overlay.className = 'media-player__buffer';
  overlay.hidden = true;
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-label', 'Loading video');
  overlay.innerHTML = '<span class="media-player__spinner" aria-hidden="true"></span>';
  stage.append(overlay);
  let wanted = !media.paused;
  let buffering = false;
  let recovery = false;
  let startedAt = 0;
  let timer = null;
  let disposed = false;
  let attempt = 0;
  let samples = [];
  let sampleStart = media.currentTime;
  let lastEnd = 0;
  let suspend = false;
  let graceUntil = 0;
  const listeners = [];
  const listen = (event, handler) => {
    media.addEventListener(event, handler);
    listeners.push([event, handler]);
  };

  function render(ahead = bufferedAhead(media), target = 3) {
    overlay.hidden = !buffering;
    root.classList.toggle('is-buffering', buffering);
    root.setAttribute('aria-busy', String(buffering));
    onState({ buffering, wanted, ahead, target });
  }

  function stopTimer() {
    clearInterval(timer);
    timer = null;
  }

  function cancel() {
    wanted = false;
    buffering = false;
    attempt++;
    stopTimer();
    media.pause();
    media.preload = 'metadata';
    render();
  }

  function play() {
    const token = ++attempt;
    // The initial click calls this synchronously to unlock media on mobile.
    try {
      Promise.resolve(media.play()).catch((error) => {
        if (disposed || token !== attempt || error?.name === 'AbortError') return;
        wanted = false;
        buffering = false;
        // If browser policy requires another gesture, the existing Play
        // control remains available; never add a second buffering action.
        stopTimer();
        render();
      });
    } catch {
      wanted = false;
      buffering = false;
      stopTimer();
      render();
    }
  }

  function resume() {
    buffering = false;
    stopTimer();
    // A browser may cap its paused preload buffer. Avoid an immediate gate
    // loop when using the bounded-wait / suspended-download escape hatch.
    graceUntil = performance.now() + 2000;
    render();
    play();
  }

  function resetSamples() {
    samples = [];
    sampleStart = media.currentTime;
    lastEnd = media.currentTime + bufferedAhead(media);
    samples.push({ time: performance.now(), end: lastEnd });
    suspend = false;
  }

  function tick() {
    if (disposed || !buffering) return;
    const now = performance.now();
    const ahead = bufferedAhead(media);
    const end = media.currentTime + ahead;
    if (Math.abs(media.currentTime - sampleStart) > 0.5 || end < lastEnd - 0.1) resetSamples();
    lastEnd = end;
    samples.push({ time: now, end });
    while (samples.length > 2 && now - samples[0].time > 6000) samples.shift();
    const first = samples[0];
    const elapsed = (now - first.time) / 1000;
    const rate = elapsed >= 0.75 && end > first.end ? (end - first.end) / elapsed : null;
    const remaining = Number.isFinite(media.duration) ? Math.max(0, media.duration - media.currentTime) : Infinity;
    const target = bufferTarget(remaining, rate, recovery, Math.max(0.25, media.playbackRate));
    render(ahead, target);
    const waited = now - startedAt;
    if (!media.seeking && media.readyState >= 2 && ahead > 0 && (
      ahead >= target - 0.05
      || (waited >= (recovery ? 12000 : 8000) && ahead >= 0.5)
      || (suspend && waited >= 1500 && ahead >= 1)
    )) resume();
  }

  function begin(isRecovery) {
    wanted = true;
    buffering = true;
    recovery = isRecovery;
    startedAt = performance.now();
    attempt++; // Ignore rejection of the play() interrupted by our pause.
    media.pause();
    media.preload = 'auto';
    resetSamples();
    stopTimer();
    timer = setInterval(tick, 250);
    tick();
  }

  function toggle() {
    if (wanted || !media.paused) return cancel();
    if (media.ended) media.currentTime = 0;
    wanted = true;
    media.preload = 'auto';
    play();
    if (bufferedAhead(media) < 3 && wanted) begin(false);
  }

  listen('progress', () => { suspend = false; tick(); });
  listen('suspend', () => { suspend = true; tick(); });
  for (const event of ['loadedmetadata', 'canplay', 'canplaythrough', 'seeked']) listen(event, tick);
  listen('waiting', () => {
    if (wanted && !buffering && bufferedAhead(media) < 0.5) begin(true);
  });
  listen('seeking', () => {
    if (wanted) begin(true);
    else resetSamples();
  });
  listen('timeupdate', () => {
    if (wanted && !buffering && performance.now() >= graceUntil
      && bufferedAhead(media) < 0.35 && media.duration - media.currentTime > 1) begin(true);
  });
  listen('pause', () => {
    // A queued pause from the gate must not cancel playback intent.
    if (!buffering && media.paused && wanted) cancel();
  });
  listen('play', () => {
    if (!buffering && !media.paused && !wanted) {
      wanted = true;
      render();
    }
  });
  listen('ended', cancel);
  listen('error', () => {
    cancel();
    overlay.hidden = false;
    overlay.setAttribute('aria-label', 'Video playback error');
    overlay.textContent = 'Unable to play this video. Try downloading it or using another browser.';
  });

  return {
    get wanted() { return wanted; },
    toggle,
    dispose() {
      if (disposed) return;
      disposed = true;
      attempt++;
      stopTimer();
      for (const [event, handler] of listeners) media.removeEventListener(event, handler);
      media.pause();
      // Abort the old range request when closing or switching previews.
      media.removeAttribute('src');
      media.load();
      overlay.remove();
      root.classList.remove('is-buffering');
      root.removeAttribute('aria-busy');
    },
  };
}
