import { afterEach, describe, expect, it, vi } from 'vitest';

function renderInstallDom() {
  document.body.dataset.installBlocked = '0';
  document.body.innerHTML = `
    <script id="wb-bootstrap" type="application/json">{"base_path":""}</script>
    <form id="install-form" class="install-form">
      <input type="hidden" name="csrf_token" value="csrf-token">
      <input type="text" name="username" value="superadmin">
      <input type="password" name="password" value="SuperSecurePass123!">
      <input type="password" name="password_confirm" value="SuperSecurePass123!">
      <input type="checkbox" name="access[public_access]">
      <input type="number" name="uploads[max_file_size_mb]" value="0">
      <input type="text" name="uploads[allowed_extensions]" value="">
      <input type="number" name="uploads[stale_upload_ttl_hours]" value="24">
      <input type="checkbox" name="automation[runner_enabled]" checked>
      <input type="number" name="automation[diagnostic_interval_minutes]" value="30">
      <input type="number" name="automation[cleanup_interval_minutes]" value="60">
      <input type="number" name="automation[storage_alert_threshold_pct]" value="85">
      <select id="database-driver" name="database[driver]">
        <option value="sqlite" selected>SQLite</option>
        <option value="mysql">MySQL</option>
        <option value="pgsql">PostgreSQL</option>
      </select>
      <div id="database-sqlite-fields">
        <input id="database-sqlite-path" type="text" name="database[path]" value="storage/app.sqlite">
      </div>
      <div id="database-network-fields" hidden>
        <input id="database-host" type="text" name="database[host]" value="db.internal">
        <input id="database-port" type="number" name="database[port]" value="3306">
        <input id="database-name" type="text" name="database[name]" value="files">
        <input id="database-username" type="text" name="database[username]" value="wb">
        <input id="database-password" type="password" name="database[password]" value="Secret123!">
      </div>
    </form>
    <div id="install-password-length"></div>
    <div id="install-password-match"></div>
    <div id="summary-access"></div>
    <div id="summary-uploads"></div>
    <div id="summary-automation"></div>
    <div id="summary-database"></div>
    <button id="install-submit" type="submit">Install wb-filebrowser</button>
    <p id="install-feedback"></p>
  `;
}

async function loadInstallerScript() {
  vi.resetModules();
  await import('../../install/install.js');
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
  delete document.body.dataset.installBlocked;
});

describe('install installer script', () => {
  it('toggles database-specific fields and updates the summary', async () => {
    renderInstallDom();

    await loadInstallerScript();

    const driver = document.getElementById('database-driver');
    const sqliteFields = document.getElementById('database-sqlite-fields');
    const networkFields = document.getElementById('database-network-fields');
    const sqlitePath = document.getElementById('database-sqlite-path');
    const host = document.getElementById('database-host');

    expect(sqliteFields.hidden).toBe(false);
    expect(networkFields.hidden).toBe(true);
    expect(document.getElementById('summary-database').textContent).toContain('storage/app.sqlite');

    driver.value = 'mysql';
    driver.dispatchEvent(new Event('input', { bubbles: true }));

    expect(sqliteFields.hidden).toBe(true);
    expect(networkFields.hidden).toBe(false);
    expect(sqlitePath.disabled).toBe(true);
    expect(host.disabled).toBe(false);
    expect(document.getElementById('summary-database').textContent).toContain('db.internal:3306');
    expect(document.getElementById('summary-database').textContent).not.toContain('Secret123!');
  });

  it('submits a driver-aware database payload', async () => {
    renderInstallDom();
    window.history.replaceState({}, '', '/install/');

    const fetchSpy = vi.fn(async () => ({
      ok: false,
      json: async () => ({ ok: false, message: 'Stop after payload capture.' }),
    }));
    global.fetch = fetchSpy;

    await loadInstallerScript();

    const driver = document.getElementById('database-driver');
    driver.value = 'pgsql';
    driver.dispatchEvent(new Event('input', { bubbles: true }));

    document.getElementById('database-port').value = '5432';
    document.getElementById('install-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const [, init] = fetchSpy.mock.calls[0];
    const payload = JSON.parse(init.body);

    expect(payload.database).toEqual({
      driver: 'pgsql',
      path: '',
      host: 'db.internal',
      port: 5432,
      name: 'files',
      username: 'wb',
      password: 'Secret123!',
    });
  });
});
