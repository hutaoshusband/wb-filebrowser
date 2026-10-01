const DEFAULT_PORTS = {
  mysql: 3306,
  pgsql: 5432,
};

const checkboxValue = (formData, name) => formData.get(name) === 'on';

const numberValue = (formData, name, fallback) => {
  const rawValue = formData.get(name);

  if (rawValue === null || rawValue === '') {
    return fallback;
  }

  const value = Number(rawValue);
  return Number.isFinite(value) ? value : fallback;
};

const summarizeExtensions = (value) => {
  const cleaned = String(value || '')
    .split(/[\s,]+/)
    .map((item) => item.trim().replace(/^\./, '').toLowerCase())
    .filter(Boolean);

  return cleaned.length > 0
    ? `Allowed types: .${cleaned.join(', .')}.`
    : 'Any file type is allowed.';
};

function setFieldGroupState(group, isActive) {
  if (!group) {
    return;
  }

  group.hidden = !isActive;
  group.querySelectorAll('input, select, textarea').forEach((field) => {
    field.disabled = !isActive;
  });
}

function selectedDriverIsAvailable(driverSelect) {
  const option = driverSelect?.selectedOptions?.[0];

  if (!option) {
    return false;
  }

  return option.dataset.available !== '0';
}

export function initInstallForm() {
  const bootstrapNode = document.getElementById('wb-bootstrap');
  const bootstrap = bootstrapNode ? JSON.parse(bootstrapNode.textContent || '{}') : {};
  const basePath = bootstrap.base_path || '';
  const form = document.getElementById('install-form');
  const feedback = document.getElementById('install-feedback');
  const submitButton = document.getElementById('install-submit');
  const passwordLengthHint = document.getElementById('install-password-length');
  const passwordMatchHint = document.getElementById('install-password-match');
  const summaryDatabase = document.getElementById('summary-database');
  const summaryAccess = document.getElementById('summary-access');
  const summaryUploads = document.getElementById('summary-uploads');
  const summaryAutomation = document.getElementById('summary-automation');
  const driverSelect = document.getElementById('database-driver');
  const sqliteFields = document.getElementById('database-sqlite-fields');
  const networkFields = document.getElementById('database-network-fields');
  const networkPort = document.getElementById('database-port');
  const installBlocked = document.body.dataset.installBlocked === '1';

  if (
    !form
    || !feedback
    || !submitButton
    || !passwordLengthHint
    || !passwordMatchHint
    || !summaryDatabase
    || !summaryAccess
    || !summaryUploads
    || !summaryAutomation
    || !driverSelect
  ) {
    return;
  }

  const apiUrl = `${window.location.origin}${basePath}/api/index.php?action=install.run`;

  const setFeedback = (message, tone = '') => {
    feedback.textContent = message;
    feedback.classList.remove('is-error', 'is-success');
    if (tone) {
      feedback.classList.add(tone);
    }
  };

  const syncDriverFields = () => {
    const driver = driverSelect.value || 'sqlite';
    const sqliteActive = driver === 'sqlite';

    setFieldGroupState(sqliteFields, sqliteActive);
    setFieldGroupState(networkFields, !sqliteActive);

    if (!sqliteActive && networkPort && networkPort.value === '') {
      networkPort.value = String(DEFAULT_PORTS[driver] || DEFAULT_PORTS.mysql);
    }

    const driverAvailable = selectedDriverIsAvailable(driverSelect);
    submitButton.disabled = installBlocked || !driverAvailable;

    if (!driverAvailable) {
      setFeedback('The selected database driver is not available in PHP. Choose another driver or enable the matching PDO extension.', 'is-error');
      return;
    }

    if (feedback.classList.contains('is-error') && feedback.textContent.includes('database driver is not available')) {
      setFeedback('');
    }
  };

  const syncInstallSummary = () => {
    const formData = new FormData(form);
    const password = String(formData.get('password') || '');
    const passwordConfirm = String(formData.get('password_confirm') || '');
    const publicAccess = checkboxValue(formData, 'access[public_access]');
    const runnerEnabled = checkboxValue(formData, 'automation[runner_enabled]');
    const uploadLimitMb = numberValue(formData, 'uploads[max_file_size_mb]', 0);
    const staleHours = numberValue(formData, 'uploads[stale_upload_ttl_hours]', 24);
    const diagnosticMinutes = numberValue(formData, 'automation[diagnostic_interval_minutes]', 30);
    const cleanupMinutes = numberValue(formData, 'automation[cleanup_interval_minutes]', 60);
    const driver = String(formData.get('database[driver]') || 'sqlite');

    summaryDatabase.textContent = driver === 'sqlite'
      ? `SQLite at ${String(formData.get('database[path]') || 'storage/app.sqlite')}.`
      : `${driver === 'mysql' ? 'MySQL' : 'PostgreSQL'} on ${String(formData.get('database[host]') || 'localhost')}:${numberValue(formData, 'database[port]', DEFAULT_PORTS[driver] || 3306)} using ${String(formData.get('database[name]') || 'the selected database')}.`;

    summaryAccess.textContent = publicAccess
      ? 'Published folders can be shared without login.'
      : 'Private browsing until you publish folders.';
    summaryUploads.textContent = uploadLimitMb > 0
      ? `App limit: ${uploadLimitMb.toLocaleString()} MB per file. ${summarizeExtensions(formData.get('uploads[allowed_extensions]'))}`
      : `No app upload limit. ${summarizeExtensions(formData.get('uploads[allowed_extensions]'))}`;
    summaryAutomation.textContent = runnerEnabled
      ? `Shield checks every ${diagnosticMinutes} minutes and stale uploads expire after ${staleHours} hours with cleanup every ${cleanupMinutes} minutes.`
      : 'Automation runner disabled. Cleanup and storage shield checks will wait until you re-enable it.';

    passwordLengthHint.textContent = password.length >= 12
      ? 'Password length looks good.'
      : 'Use at least 12 characters.';
    passwordLengthHint.classList.toggle('is-good', password.length >= 12);

    const passwordsMatch = password !== '' && password === passwordConfirm;
    passwordMatchHint.textContent = passwordsMatch
      ? 'Passwords match.'
      : 'Passwords must match before install.';
    passwordMatchHint.classList.toggle('is-good', passwordsMatch);
  };

  const syncState = () => {
    syncDriverFields();
    syncInstallSummary();
  };

  form.addEventListener('input', syncState);
  form.addEventListener('change', syncState);
  syncState();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFeedback('');

    if (installBlocked) {
      setFeedback('Resolve the failed environment checks above before starting the installer.', 'is-error');
      return;
    }

    if (!selectedDriverIsAvailable(driverSelect)) {
      setFeedback('The selected database driver is not available in PHP. Choose another driver or enable the matching PDO extension.', 'is-error');
      return;
    }

    const formData = new FormData(form);
    const password = String(formData.get('password') || '');
    const passwordConfirm = String(formData.get('password_confirm') || '');
    const driver = String(formData.get('database[driver]') || 'sqlite');

    if (password !== passwordConfirm) {
      setFeedback('Passwords do not match.', 'is-error');
      return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Installing...';

    try {
      const response = await fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          csrf_token: String(formData.get('csrf_token') || ''),
          username: String(formData.get('username') || ''),
          password,
          database: {
            driver,
            path: driver === 'sqlite' ? String(formData.get('database[path]') || '') : '',
            host: driver === 'sqlite' ? '' : String(formData.get('database[host]') || ''),
            port: driver === 'sqlite' ? null : numberValue(formData, 'database[port]', DEFAULT_PORTS[driver] || 3306),
            name: driver === 'sqlite' ? '' : String(formData.get('database[name]') || ''),
            username: driver === 'sqlite' ? '' : String(formData.get('database[username]') || ''),
            password: driver === 'sqlite' ? '' : String(formData.get('database[password]') || ''),
          },
          access: {
            public_access: checkboxValue(formData, 'access[public_access]'),
          },
          uploads: {
            max_file_size_mb: numberValue(formData, 'uploads[max_file_size_mb]', 0),
            allowed_extensions: String(formData.get('uploads[allowed_extensions]') || ''),
            stale_upload_ttl_hours: numberValue(formData, 'uploads[stale_upload_ttl_hours]', 24),
          },
          automation: {
            runner_enabled: checkboxValue(formData, 'automation[runner_enabled]'),
            diagnostic_interval_minutes: numberValue(formData, 'automation[diagnostic_interval_minutes]', 30),
            cleanup_interval_minutes: numberValue(formData, 'automation[cleanup_interval_minutes]', 60),
            storage_alert_threshold_pct: numberValue(formData, 'automation[storage_alert_threshold_pct]', 85),
          },
        }),
      });
      let payload = null;

      try {
        payload = await response.json();
      } catch (error) {
        payload = null;
      }

      if (!response.ok || !payload?.ok) {
        throw new Error(payload?.message || 'Installation failed.');
      }

      setFeedback('Installation complete. Redirecting...', 'is-success');
      window.location.href = payload.redirect;
    } catch (error) {
      submitButton.disabled = false;
      submitButton.textContent = 'Install wb-filebrowser';
      setFeedback(error instanceof Error ? error.message : 'Installation failed.', 'is-error');
    }
  });
}

initInstallForm();
