(function () {
  const bootstrapNode = document.getElementById('wb-bootstrap');
  const bootstrap = bootstrapNode ? JSON.parse(bootstrapNode.textContent || '{}') : {};
  const basePath = bootstrap.base_path || '';
  const form = document.getElementById('install-form');
  const feedback = document.getElementById('install-feedback');
  const submitButton = document.getElementById('install-submit');
  const passwordLengthHint = document.getElementById('install-password-length');
  const passwordMatchHint = document.getElementById('install-password-match');
  const summaryAccess = document.getElementById('summary-access');
  const summaryUploads = document.getElementById('summary-uploads');
  const summaryAutomation = document.getElementById('summary-automation');
  const installBlocked = document.body.dataset.installBlocked === '1';

  if (!form || !feedback || !submitButton || !passwordLengthHint || !passwordMatchHint || !summaryAccess || !summaryUploads || !summaryAutomation) {
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

  const checkboxValue = (formData, name) => formData.get(name) === 'on';

  const numberValue = (formData, name, fallback) => {
    const value = Number(formData.get(name) || fallback);
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

  form.addEventListener('input', syncInstallSummary);
  syncInstallSummary();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFeedback('');

    if (installBlocked) {
      setFeedback('Resolve the failed environment checks above before starting the installer.', 'is-error');
      return;
    }

    const formData = new FormData(form);
    const password = String(formData.get('password') || '');
    const passwordConfirm = String(formData.get('password_confirm') || '');

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
}());
