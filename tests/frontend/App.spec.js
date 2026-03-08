import { flushPromises, mount } from '@vue/test-utils';
import App from '../../frontend/src/App.vue';

function adminUser(role = 'admin') {
  return {
    id: 1,
    username: 'admin',
    role,
    status: 'active',
    force_password_reset: false,
    is_immutable: false,
    last_login_at: null,
  };
}

function uploadPolicy() {
  return {
    max_file_size_mb: 256,
    max_file_size_bytes: 268435456,
    allowed_extensions: [],
    allowed_extensions_label: 'Any file type',
    stale_upload_ttl_hours: 24,
  };
}

function sessionPayload(user = adminUser()) {
  return {
    user,
    public_access: false,
    root_folder_id: 1,
    app_version: '1.0.0-alpha',
    storage: { used_label: '0 B', total_label: '100 GB' },
    diagnostic: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
    upload_policy: uploadPolicy(),
    help: { title: 'Help', body: 'Help text' },
  };
}

function jsonResponse(payload) {
  return {
    ok: true,
    json: async () => ({ ok: true, ...payload }),
  };
}

function installFetchStub(overrides = {}) {
  const calls = [];
  const handlers = {
    'auth.session': () => jsonResponse(sessionPayload()),
    'admin.automation.tick': () => jsonResponse({
      jobs: [
        {
          job_key: 'storage_shield_check',
          label: 'Storage shield check',
          last_result: 'success',
          last_message: 'Shield healthy.',
          last_run_at: '',
          next_run_at: '',
          is_due: true,
        },
      ],
      locked: false,
      diagnostic: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
    }),
    'admin.dashboard': () => jsonResponse({
      stats: {
        files: 2,
        folders: 1,
        users: 1,
        used_label: '4 MB',
        total_label: '100 GB',
      },
      diagnostic: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
      automation: {
        jobs: [
          {
            job_key: 'storage_shield_check',
            label: 'Storage shield check',
            last_result: 'success',
            last_message: 'Shield healthy.',
            last_run_at: '',
            next_run_at: '',
            is_due: true,
          },
        ],
      },
      upload_policy: uploadPolicy(),
      public_access: false,
    }),
    'admin.users.list': () => jsonResponse({
      users: [
        adminUser('super_admin'),
        { ...adminUser('user'), id: 2, username: 'member', role: 'user' },
      ],
    }),
    'admin.permissions.get': () => jsonResponse({
      folders: [
        { id: 1, name: 'Home', parent_id: null, type: 'folder' },
        { id: 2, name: 'Projects', parent_id: 1, type: 'folder' },
      ],
      permissions: [],
    }),
    'admin.settings.get': () => jsonResponse({
      settings: {
        access: { public_access: false },
        uploads: { max_file_size_mb: 256, allowed_extensions: '', stale_upload_ttl_hours: 24 },
        automation: { runner_enabled: true, diagnostic_interval_minutes: 30, cleanup_interval_minutes: 60, storage_alert_threshold_pct: 85 },
      },
      diagnostics: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
      upload_policy: uploadPolicy(),
      automation: {
        jobs: [
          {
            job_key: 'storage_shield_check',
            label: 'Storage shield check',
            last_result: 'success',
            last_message: 'Shield healthy.',
            last_run_at: '',
            next_run_at: '',
            is_due: true,
          },
        ],
      },
      can_manage_settings: true,
    }),
    'admin.settings.save': () => jsonResponse({
      settings: {
        access: { public_access: true },
        uploads: { max_file_size_mb: 64, allowed_extensions: 'png, pdf', stale_upload_ttl_hours: 8 },
        automation: { runner_enabled: true, diagnostic_interval_minutes: 30, cleanup_interval_minutes: 60, storage_alert_threshold_pct: 85 },
      },
      diagnostics: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
      automation: { jobs: [] },
    }),
    'admin.permissions.save': () => jsonResponse({}),
    ...overrides,
  };

  global.fetch = vi.fn(async (input, init = {}) => {
    const url = new URL(String(input));
    const action = url.searchParams.get('action');
    calls.push({ action, init });
    const handler = handlers[action];

    if (!handler) {
      throw new Error(`Unhandled action ${action}`);
    }

    return handler(input, init, calls);
  });

  return { calls };
}

async function mountAdminApp({ hash = '#/dashboard', handlers = {} } = {}) {
  window.location.hash = hash;
  document.body.dataset.shell = 'admin';
  window.WB_BOOTSTRAP = {
    surface: 'admin',
    base_path: '',
    csrf_token: 'csrf-token',
    user: adminUser(),
    app_version: '1.0.0-alpha',
  };
  const fetchState = installFetchStub(handlers);
  const wrapper = mount(App);
  await flushPromises();
  await flushPromises();
  return { wrapper, ...fetchState };
}

afterEach(() => {
  vi.restoreAllMocks();
  localStorage.clear();
  delete window.WB_BOOTSTRAP;
  delete window.__WB_REDIRECT__;
  delete document.body.dataset.shell;
});

describe('Admin app shell', () => {
  it('loads a new admin section after hash changes', async () => {
    const { calls } = await mountAdminApp();

    expect(calls.some((call) => call.action === 'admin.dashboard')).toBe(true);

    window.location.hash = '#/users';
    window.dispatchEvent(new Event('hashchange'));
    await flushPromises();
    await flushPromises();

    expect(calls.some((call) => call.action === 'admin.users.list')).toBe(true);
  });

  it('routes My files to the browser root from admin', async () => {
    const redirectSpy = vi.fn();
    window.__WB_REDIRECT__ = redirectSpy;
    const { wrapper } = await mountAdminApp();

    await wrapper.find('.sidebar-link').trigger('click');

    expect(redirectSpy).toHaveBeenCalledWith('/');
  });

  it('renders permission guidance and saves toggled matrix entries', async () => {
    const { wrapper, calls } = await mountAdminApp({ hash: '#/permissions' });

    expect(wrapper.text()).toContain('Published folders for guests');

    const firstCheckbox = wrapper.find('tbody input[type="checkbox"]');
    await firstCheckbox.setValue(true);
    const saveButton = wrapper.findAll('button').find((button) => button.text() === 'Save permissions');
    await saveButton.trigger('click');

    const saveCall = calls.find((call) => call.action === 'admin.permissions.save');
    const body = JSON.parse(saveCall.init.body);

    expect(body.principal_type).toBe('guest');
    expect(body.entries.some((entry) => entry.can_view === true)).toBe(true);
  });

  it('submits grouped settings changes', async () => {
    const { wrapper, calls } = await mountAdminApp({ hash: '#/settings' });

    const uploadsTab = wrapper.findAll('.settings-tabs button').find((button) => button.text() === 'Uploads');
    await uploadsTab.trigger('click');
    await flushPromises();

    const inputs = wrapper.findAll('.settings-pane input[type="number"]');
    await inputs[0].setValue('64');
    await wrapper.find('.settings-pane textarea').setValue('png, pdf');
    await inputs[1].setValue('8');
    await wrapper.find('.primary-button').trigger('click');

    const saveCall = calls.find((call) => call.action === 'admin.settings.save');
    const body = JSON.parse(saveCall.init.body);

    expect(body.uploads.max_file_size_mb).toBe(64);
    expect(body.uploads.allowed_extensions).toBe('png, pdf');
    expect(body.uploads.stale_upload_ttl_hours).toBe(8);
  });
});
