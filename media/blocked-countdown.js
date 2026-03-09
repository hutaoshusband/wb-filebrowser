(function () {
  var el = document.querySelector("[data-blocked-until-ts]");

  if (!el) {
    return;
  }

  var raw = Number(el.getAttribute("data-blocked-until-ts") || "0");

  if (!Number.isFinite(raw) || raw <= 0) {
    el.textContent = "Temporarily";
    return;
  }

  var pad = function (value) {
    return String(value).padStart(2, "0");
  };

  var render = function () {
    var remaining = Math.max(0, raw - Math.floor(Date.now() / 1000));
    var hours = Math.floor(remaining / 3600);
    var minutes = Math.floor((remaining % 3600) / 60);
    var seconds = remaining % 60;

    el.textContent = hours > 0
      ? pad(hours) + ":" + pad(minutes) + ":" + pad(seconds)
      : pad(minutes) + ":" + pad(seconds);

    if (remaining <= 0) {
      window.location.href = window.location.pathname + window.location.search;
    }
  };

  render();
  window.setInterval(render, 1000);
})();
