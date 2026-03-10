import { flushPromises, mount } from '@vue/test-utils';
vi.mock('../../frontend/src/lib/thumbnails.js', () => ({
  renderPdfThumbnail: vi.fn(async () => 'data:image/png;base64,pdf-thumb'),
}));

import { renderPdfThumbnail } from '../../frontend/src/lib/thumbnails.js';
import App from '../../frontend/src/App.vue';

function adminUser(role = 'admin') {
  return {
    id: 1,
    username: 'admin',
    role,
    status: 'active',
    force_password_reset: false,
    is_immutable: false,
    storage_used_bytes: 0,
    storage_used_label: '0 B',
    storage_quota_bytes: null,
    storage_quota_label: 'Unlimited',
    last_login_at: null,
  };
}

function uploadPolicy() {
  return {
    max_file_size_mb: 256,
    max_file_size_bytes: 268435456,
    max_file_size_label: '256 MB',
    has_app_limit: true,
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
    maintenance: {
      enabled: false,
      scope: 'app_only',
      message: 'The file browser is temporarily unavailable while maintenance is in progress. Please try again later.',
      blocks_current_user: false,
    },
    display: {
      grid_thumbnails_enabled: true,
    },
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

function errorResponse(payload) {
  return {
    ok: false,
    json: async () => ({ ok: false, ...payload }),
  };
}

function browserFile(overrides = {}) {
  return {
    id: 7,
    type: 'file',
    name: 'brochure.pdf',
    folder_id: 1,
    size: 1024,
    size_label: '1 KB',
    mime_type: 'application/pdf',
    updated_at: '2026-03-09T00:00:00Z',
    updated_relative: 'just now',
    checksum: 'abc123',
    description: '',
    extension: 'pdf',
    can_edit: true,
    can_delete: true,
    preview_mode: 'pdf',
    fallback_variant: null,
    fallback_icon_url: null,
    fallback_label: null,
    preview_url: 'http://localhost/api/index.php?action=files.stream&id=7&disposition=inline',
    download_url: 'http://localhost/api/index.php?action=files.stream&id=7&disposition=attachment',
    ...overrides,
  };
}

function browserTreePayload(fileOverrides = {}) {
  return {
    data: {
      folder: { id: 1, type: 'folder', name: 'Home', parent_id: null, updated_relative: 'just now', size_label: '-', description: '' },
      breadcrumbs: [{ id: 1, name: 'Home' }],
      folders: [],
      files: [browserFile(fileOverrides)],
      can_upload: true,
      can_create_folders: true,
      can_edit: true,
      can_delete: true,
    },
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
        {
          ...adminUser('user'),
          id: 2,
          username: 'member',
          role: 'user',
          storage_used_bytes: 1024,
          storage_used_label: '1 KB',
          storage_quota_bytes: 2048,
          storage_quota_label: '2 KB',
        },
      ],
    }),
    'admin.permissions.get': (input) => {
      const url = new URL(String(input));
      const principalType = url.searchParams.get('principal_type');
      const principalId = url.searchParams.get('principal_id');

      return jsonResponse({
        folders: [
          { id: 1, name: 'Home', parent_id: null, type: 'folder' },
          { id: 2, name: 'Projects', parent_id: 1, type: 'folder' },
        ],
        permissions: principalType === 'user' && principalId === '2'
          ? [{
            folder_id: 2,
            can_view: 1,
            can_upload: 1,
            can_edit: 1,
            can_delete: 0,
            can_create_folders: 1,
          }]
          : [],
      });
    },
    'admin.settings.get': () => jsonResponse({
      settings: {
        access: {
          public_access: false,
          maintenance_enabled: false,
          maintenance_scope: 'app_only',
          maintenance_message: 'The file browser is temporarily unavailable while maintenance is in progress. Please try again later.',
          share_terms_enabled: false,
          share_terms_message: 'By opening or downloading this shared file, you confirm that you are authorized to access it and will handle it according to the applicable terms and confidentiality requirements.',
        },
        uploads: { max_file_size_mb: 256, allowed_extensions: '', stale_upload_ttl_hours: 24 },
        automation: {
          runner_enabled: true,
          diagnostic_interval_minutes: 30,
          cleanup_interval_minutes: 60,
          storage_alert_threshold_pct: 85,
          folder_size_interval_minutes: 1440,
        },
        security: {
          audit_enabled: false,
          audit_retention_days: 30,
          log_auth_success: true,
          log_auth_failure: true,
          log_file_views: true,
          log_file_downloads: true,
          log_file_uploads: true,
          log_file_management: true,
          log_deletions: true,
          log_admin_actions: true,
          log_security_actions: true,
        },
        display: {
          grid_thumbnails_enabled: true,
        },
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
        access: {
          public_access: true,
          maintenance_enabled: true,
          maintenance_scope: 'app_and_share',
          maintenance_message: 'Updates in progress',
          share_terms_enabled: true,
          share_terms_message: 'Accept the published terms before opening or downloading shared files.',
        },
        uploads: { max_file_size_mb: 64, allowed_extensions: 'png, pdf', stale_upload_ttl_hours: 8 },
        automation: {
          runner_enabled: true,
          diagnostic_interval_minutes: 30,
          cleanup_interval_minutes: 60,
          storage_alert_threshold_pct: 85,
          folder_size_interval_minutes: 1440,
        },
        security: {
          audit_enabled: true,
          audit_retention_days: 14,
          log_auth_success: true,
          log_auth_failure: true,
          log_file_views: true,
          log_file_downloads: true,
          log_file_uploads: true,
          log_file_management: true,
          log_deletions: true,
          log_admin_actions: true,
          log_security_actions: true,
        },
        display: {
          grid_thumbnails_enabled: false,
        },
      },
      diagnostics: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
      automation: { jobs: [] },
    }),
    'admin.audit.list': (input) => {
      const url = new URL(String(input));
      return jsonResponse({
        entries: [
          {
            id: 1,
            event_type: 'file.view',
            category: url.searchParams.get('category') || 'file_views',
            category_label: 'File views',
            actor_user_id: 1,
            actor_username: 'admin',
            ip_address: '127.0.0.1',
            target_type: 'file',
            target_id: 7,
            target_label: 'Home / brochure.pdf',
            summary: 'Viewed file brochure.pdf',
            metadata: {},
            created_at: '2026-03-09T00:00:00Z',
          },
        ],
        page: Number(url.searchParams.get('page') || '1'),
        page_size: 25,
        total_items: 26,
        total_pages: 2,
        query: url.searchParams.get('query') || '',
        category: url.searchParams.get('category') || '',
        categories: [
          { key: 'file_views', label: 'File views' },
          { key: 'admin_actions', label: 'Admin actions' },
        ],
      });
    },
    'admin.security.get': () => jsonResponse({
      settings: {
        access: {
          public_access: false,
          maintenance_enabled: false,
          maintenance_scope: 'app_only',
          maintenance_message: 'The file browser is temporarily unavailable while maintenance is in progress. Please try again later.',
          share_terms_enabled: false,
          share_terms_message: 'By opening or downloading this shared file, you confirm that you are authorized to access it and will handle it according to the applicable terms and confidentiality requirements.',
        },
        uploads: { max_file_size_mb: 256, allowed_extensions: '', stale_upload_ttl_hours: 24 },
        automation: {
          runner_enabled: true,
          diagnostic_interval_minutes: 30,
          cleanup_interval_minutes: 60,
          storage_alert_threshold_pct: 85,
          folder_size_interval_minutes: 1440,
        },
        security: {
          audit_enabled: false,
          audit_retention_days: 30,
          log_auth_success: true,
          log_auth_failure: true,
          log_file_views: true,
          log_file_downloads: true,
          log_file_uploads: true,
          log_file_management: true,
          log_deletions: true,
          log_admin_actions: true,
          log_security_actions: true,
        },
        display: {
          grid_thumbnails_enabled: true,
        },
      },
      diagnostics: { exposed: false, checked_at: '', message: 'Shield healthy.', probe_path: 'probe/file.txt', probe_url: '/storage/probe/file.txt' },
      upload_policy: uploadPolicy(),
      can_manage_settings: true,
      active_bans: [
        {
          id: 3,
          ip_address: '203.0.113.42',
          reason: 'Abuse',
          created_by_username: 'admin',
          created_at: '2026-03-09T00:00:00Z',
          expires_at: null,
          revoked_at: null,
          revoked_reason: null,
          revoked_by_username: null,
          is_active: true,
        },
      ],
      ban_history: [
        {
          id: 2,
          ip_address: '198.51.100.7',
          reason: 'Expired block',
          created_by_username: 'admin',
          created_at: '2026-03-08T00:00:00Z',
          expires_at: '2026-03-08T01:00:00Z',
          revoked_at: '2026-03-08T01:00:00Z',
          revoked_reason: 'expired',
          revoked_by_username: null,
          is_active: false,
        },
      ],
    }),
    'admin.security.ban': () => jsonResponse({
      ban: { id: 4, ip_address: '192.0.2.55' },
      active_bans: [],
      ban_history: [],
    }),
    'admin.security.unban': () => jsonResponse({
      ban: { id: 3, ip_address: '203.0.113.42' },
      active_bans: [],
      ban_history: [],
    }),
    'admin.users.update': () => jsonResponse({}),
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

async function mountAdminApp({ hash = '#/dashboard', handlers = {}, bootstrapUser = adminUser() } = {}) {
  window.location.hash = hash;
  document.body.dataset.shell = 'admin';
  window.WB_BOOTSTRAP = {
    surface: 'admin',
    base_path: '',
    csrf_token: 'csrf-token',
    user: bootstrapUser,
    app_version: '1.0.0-alpha',
  };
  const fetchState = installFetchStub(handlers);
  const wrapper = mount(App);
  await flushPromises();
  await flushPromises();
  return { wrapper, ...fetchState };
}

async function mountBrowserApp({ hash = '', handlers = {}, bootstrapUser = adminUser() } = {}) {
  window.location.hash = hash;
  document.body.dataset.shell = 'app';
  window.WB_BOOTSTRAP = {
    surface: 'app',
    base_path: '',
    csrf_token: 'csrf-token',
    user: bootstrapUser,
    app_version: '1.0.0-alpha',
  };
  const fetchState = installFetchStub({
    'tree.list': () => jsonResponse(browserTreePayload()),
    ...handlers,
  });
  const wrapper = mount(App);
  await flushPromises();
  await flushPromises();
  return { wrapper, ...fetchState };
}

afterEach(() => {
  vi.restoreAllMocks();
  localStorage.clear();
  sessionStorage.clear();
  document.body.style.overflow = '';
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

  it('renders summary-row wrappers and shared checkbox hooks across admin surfaces', async () => {
    const dashboard = await mountAdminApp();
    expect(dashboard.wrapper.find('.admin-summary-row.admin-summary-row--four').exists()).toBe(true);
    dashboard.wrapper.unmount();

    const users = await mountAdminApp({ hash: '#/users' });
    expect(users.wrapper.find('.admin-summary-row.admin-summary-row--two').exists()).toBe(true);
    expect(users.wrapper.find('label.checkbox-control.checkbox-control--row').exists()).toBe(true);
    expect(users.wrapper.find('label.checkbox-control .checkbox-control__indicator').exists()).toBe(true);
    users.wrapper.unmount();

    const permissions = await mountAdminApp({ hash: '#/permissions' });
    expect(permissions.wrapper.find('.admin-summary-row.admin-summary-row--two').exists()).toBe(true);
    expect(permissions.wrapper.find('tbody label.checkbox-control.checkbox-control--compact').exists()).toBe(true);
    permissions.wrapper.unmount();

    const audit = await mountAdminApp({ hash: '#/audit' });
    expect(audit.wrapper.find('.admin-summary-row.admin-summary-row--three').exists()).toBe(true);
    audit.wrapper.unmount();

    const security = await mountAdminApp({ hash: '#/security' });
    expect(security.wrapper.find('.settings-pane label.checkbox-control.checkbox-control--row').exists()).toBe(true);
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

  it('loads a user detail route and saves quota plus granular permissions', async () => {
    const { wrapper, calls } = await mountAdminApp({ hash: '#/users/2' });

    expect(wrapper.text()).toContain('member');
    expect(wrapper.text()).toContain('User storage allowance');

    const quotaInput = wrapper.find('input[type="number"]');
    await quotaInput.setValue('4096');

    const saveAccountButton = wrapper.findAll('button').find((button) => button.text() === 'Save account');
    await saveAccountButton.trigger('click');

    const saveUserCall = calls.find((call) => call.action === 'admin.users.update');
    const saveUserBody = JSON.parse(saveUserCall.init.body);
    expect(saveUserBody.storage_quota_bytes).toBe(4096);

    const checkboxes = wrapper.findAll('tbody input[type="checkbox"]');
    await checkboxes[3].setValue(true);

    const savePermissionsButton = wrapper.findAll('button').find((button) => button.text() === 'Save permissions');
    await savePermissionsButton.trigger('click');

    const savePermissionCall = calls.filter((call) => call.action === 'admin.permissions.save').at(-1);
    const savePermissionBody = JSON.parse(savePermissionCall.init.body);
    expect(savePermissionBody.principal_type).toBe('user');
    expect(savePermissionBody.principal_id).toBe(2);
    expect(savePermissionBody.entries.some((entry) => entry.can_edit === true || entry.can_create_folders === true)).toBe(true);
  });

  it('submits grouped settings changes', async () => {
    const { wrapper, calls } = await mountAdminApp({ hash: '#/settings' });

    const accessCheckboxes = wrapper.findAll('.settings-pane input[type="checkbox"]');
    await accessCheckboxes[1].setValue(true);
    await wrapper.find('.settings-pane select').setValue('app_and_share');
    await wrapper.find('.settings-pane textarea').setValue('Updates in progress');

    const displayTab = wrapper.findAll('.settings-tabs button').find((button) => button.text() === 'Display');
    await displayTab.trigger('click');
    await flushPromises();

    const displayCheckbox = wrapper.find('.settings-pane input[type="checkbox"]');
    await displayCheckbox.setValue(false);

    const uploadsTab = wrapper.findAll('.settings-tabs button').find((button) => button.text() === 'Uploads');
    await uploadsTab.trigger('click');
    await flushPromises();

    const inputs = wrapper.findAll('.settings-pane input[type="number"]');
    await inputs[0].setValue('64');
    await wrapper.find('.settings-pane textarea').setValue('png, pdf');
    await inputs[1].setValue('8');
    const automationTab = wrapper.findAll('.settings-tabs button').find((button) => button.text() === 'Automation');
    await automationTab.trigger('click');
    await flushPromises();

    const automationInputs = wrapper.findAll('.settings-pane input[type="number"]');
    await automationInputs[3].setValue('720');

    await uploadsTab.trigger('click');
    await flushPromises();
    await wrapper.find('.primary-button').trigger('click');

    const saveCall = calls.find((call) => call.action === 'admin.settings.save');
    const body = JSON.parse(saveCall.init.body);

    expect(body.access.maintenance_enabled).toBe(true);
    expect(body.access.maintenance_scope).toBe('app_and_share');
    expect(body.access.maintenance_message).toBe('Updates in progress');
    expect(body.display.grid_thumbnails_enabled).toBe(false);
    expect(body.uploads.max_file_size_mb).toBe(64);
    expect(body.uploads.allowed_extensions).toBe('png, pdf');
    expect(body.uploads.stale_upload_ttl_hours).toBe(8);
    expect(body.automation.folder_size_interval_minutes).toBe(720);
  });

  it('submits share terms settings from the access tab', async () => {
    const { wrapper, calls } = await mountAdminApp({ hash: '#/settings' });

    expect(wrapper.text()).toContain('Shared file terms');

    const accessCheckboxes = wrapper.findAll('.settings-pane input[type="checkbox"]');
    await accessCheckboxes[2].setValue(true);

    const accessTextareas = wrapper.findAll('.settings-pane textarea');
    await accessTextareas[1].setValue('Accept the published terms before opening or downloading shared files.');

    await wrapper.find('.primary-button').trigger('click');

    const saveCall = calls.filter((call) => call.action === 'admin.settings.save').at(-1);
    const body = JSON.parse(saveCall.init.body);

    expect(body.access.share_terms_enabled).toBe(true);
    expect(body.access.share_terms_message).toBe('Accept the published terms before opening or downloading shared files.');
  });

  it('loads audit logs, applies category filters, and paginates', async () => {
    const { wrapper, calls } = await mountAdminApp({ hash: '#/audit' });

    expect(wrapper.text()).toContain('Recorded activity');
    expect(calls.some((call) => call.action === 'admin.audit.list')).toBe(true);
    expect(wrapper.find('.audit-pagination__status').text()).toBe('Page 1 of 2');

    const categorySelect = wrapper.find('select');
    await categorySelect.setValue('file_views');
    await flushPromises();
    await flushPromises();

    const nextButton = wrapper.findAll('button').find((button) => button.text() === 'Next');
    await nextButton.trigger('click');
    await flushPromises();
    await flushPromises();

    const lastAuditCall = calls.filter((call) => call.action === 'admin.audit.list').at(-1);

    expect(lastAuditCall).toBeTruthy();
    expect(lastAuditCall.action).toBe('admin.audit.list');
    expect(lastAuditCall.init.method ?? 'GET').toBe('GET');
    expect(wrapper.find('.audit-pagination__status').text()).toBe('Page 2 of 2');
    expect(wrapper.text()).toContain('Viewed file brochure.pdf');
  });

  it('submits audit cleanup actions and reloads the first audit page', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const auditPages = [];
    let cleanupBody = null;
    const { wrapper } = await mountAdminApp({
      hash: '#/audit',
      bootstrapUser: adminUser('super_admin'),
      handlers: {
        'auth.session': () => jsonResponse(sessionPayload(adminUser('super_admin'))),
        'admin.audit.list': (input) => {
          const url = new URL(String(input));
          const page = Number(url.searchParams.get('page') || '1');
          auditPages.push(page);

          return jsonResponse({
            entries: [
              {
                id: page,
                event_type: 'file.view',
                category: 'file_views',
                category_label: 'File views',
                actor_user_id: 1,
                actor_username: 'admin',
                ip_address: '127.0.0.1',
                target_type: 'file',
                target_id: 7,
                target_label: 'Home / brochure.pdf',
                summary: `Viewed file brochure.pdf (page ${page})`,
                metadata: {},
                created_at: '2026-03-09T00:00:00Z',
              },
            ],
            page,
            page_size: 25,
            total_items: 26,
            total_pages: 2,
            query: url.searchParams.get('query') || '',
            category: url.searchParams.get('category') || '',
            categories: [
              { key: 'file_views', label: 'File views' },
              { key: 'admin_actions', label: 'Admin actions' },
            ],
          });
        },
        'admin.audit.cleanup': (_input, init) => {
          cleanupBody = JSON.parse(init.body);
          return jsonResponse({
            deleted_count: 5,
            remaining_count: 21,
          });
        },
      },
    });

    const nextButton = wrapper.findAll('button').find((button) => button.text() === 'Next');
    await nextButton.trigger('click');
    await flushPromises();
    await flushPromises();

    expect(auditPages.at(-1)).toBe(2);
    expect(wrapper.text()).toContain('Audit Cleanup');

    await wrapper.find('.audit-cleanup select').setValue('older_than_days');
    await wrapper.find('.audit-cleanup input[type="number"]').setValue('7');

    const cleanupButton = wrapper.findAll('button').find((button) => button.text() === 'Run cleanup');
    await cleanupButton.trigger('click');
    await flushPromises();
    await flushPromises();

    expect(confirmSpy).toHaveBeenCalled();
    expect(cleanupBody).toMatchObject({ mode: 'older_than_days', days: 7 });
    expect(auditPages.at(-1)).toBe(1);
  });

  it('renders the security surface, saves audit settings, and submits ban actions', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { wrapper, calls } = await mountAdminApp({ hash: '#/security' });

    expect(wrapper.text()).toContain('Audit logging and protection');
    expect(wrapper.text()).toContain('Blocked IP addresses');
    expect(wrapper.text()).toContain('Permanently');

    const securityCheckboxes = wrapper.findAll('.settings-pane input[type="checkbox"]');
    await securityCheckboxes[0].setValue(true);
    const retentionInput = wrapper.find('.settings-pane input[type="number"]');
    await retentionInput.setValue('14');
    const saveButton = wrapper.findAll('button').find((button) => button.text() === 'Save security settings');
    await saveButton.trigger('click');

    const saveCall = calls.find((call) => call.action === 'admin.settings.save');
    const saveBody = JSON.parse(saveCall.init.body);
    expect(saveBody.security.audit_enabled).toBe(true);
    expect(saveBody.security.audit_retention_days).toBe(14);

    const banInputs = wrapper.findAll('.security-ban-form input');
    await banInputs[0].setValue('192.0.2.55');
    await banInputs[1].setValue('Test block');
    await wrapper.find('.security-ban-form').trigger('submit');
    await flushPromises();

    const banCall = calls.find((call) => call.action === 'admin.security.ban');
    const banBody = JSON.parse(banCall.init.body);
    expect(banBody.ip_address).toBe('192.0.2.55');
    expect(banBody.reason).toBe('Test block');

    const unbanButton = wrapper.findAll('button').find((button) => button.text() === 'Unban');
    await unbanButton.trigger('click');
    await flushPromises();

    const unbanCall = calls.find((call) => call.action === 'admin.security.unban');
    const unbanBody = JSON.parse(unbanCall.init.body);
    expect(confirmSpy).toHaveBeenCalled();
    expect(unbanBody.ban_id).toBe(3);
  });

  it('creates a share link from the file preview in the browser shell', async () => {
    const clipboardWrite = vi.fn().mockResolvedValue();
    Object.defineProperty(window.navigator, 'clipboard', {
      value: { writeText: clipboardWrite },
      configurable: true,
    });

    const { wrapper, calls } = await mountBrowserApp({
      handlers: {
        'files.share.create': () => jsonResponse({
          share: {
            file_id: 7,
            token: 'feedfacefeedfacefeedfacefeedface',
            url: 'http://localhost/share/?token=feedfacefeedfacefeedfacefeedface',
            download_url: 'http://localhost/api/index.php?action=share.stream&token=feedfacefeedfacefeedfacefeedface&disposition=attachment',
            created_at: '2026-03-09T00:00:00Z',
            updated_at: '2026-03-09T00:00:00Z',
            expires_at: null,
            max_views: null,
            view_count: 0,
            remaining_views: null,
            revoked_at: null,
            requires_password: false,
          },
        }),
        'files.share.get': () => jsonResponse({ share: null }),
      },
    });

    await wrapper.find('tbody tr').trigger('click');
    await flushPromises();

    const shareButton = wrapper.findAll('button').find((button) => button.text() === 'Share link');
    await shareButton.trigger('click');

    const shareCall = calls.find((call) => call.action === 'files.share.create');
    const body = JSON.parse(shareCall.init.body);

    expect(body.file_id).toBe(7);
    expect(clipboardWrite).toHaveBeenCalledWith('http://localhost/share/?token=feedfacefeedfacefeedfacefeedface');
  });

  it('renders a branded fallback card for jar files', async () => {
    const { wrapper } = await mountBrowserApp({
      handlers: {
        'tree.list': () => jsonResponse(browserTreePayload({
          name: 'client.jar',
          mime_type: 'application/zip',
          extension: 'jar',
          preview_mode: 'download',
          fallback_variant: 'jar',
          fallback_icon_url: '/media/file-fallbacks/jar.svg',
          fallback_label: 'Java archive',
        })),
      },
    });

    await wrapper.find('tbody tr').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Java archive');
    expect(wrapper.find('.file-fallback__badge').text()).toBe('JAR');
    expect(wrapper.find('.file-fallback__icon').attributes('src')).toBe('/media/file-fallbacks/jar.svg');
  });

  it('upserts share settings with expiry and max views', async () => {
    const { wrapper, calls } = await mountBrowserApp({
      handlers: {
        'files.share.create': () => jsonResponse({
          share: {
            file_id: 7,
            token: 'feedfacefeedfacefeedfacefeedface',
            url: 'http://localhost/share/?token=feedfacefeedfacefeedfacefeedface',
            download_url: 'http://localhost/api/index.php?action=share.stream&token=feedfacefeedfacefeedfacefeedface&disposition=attachment',
            created_at: '2026-03-09T00:00:00Z',
            updated_at: '2026-03-09T00:00:00Z',
            expires_at: '2026-03-10T09:30:00.000Z',
            max_views: 5,
            view_count: 0,
            remaining_views: 5,
            revoked_at: null,
            requires_password: true,
          },
        }),
        'files.share.get': () => jsonResponse({ share: null }),
      },
    });

    await wrapper.find('tbody tr').trigger('click');
    await flushPromises();

    const shareInputs = wrapper.findAll('.share-panel__input');
    expect(shareInputs).toHaveLength(3);
    await shareInputs[0].setValue('2026-03-10T10:30');
    await shareInputs[1].setValue('5');
    await shareInputs[2].setValue('Secret 123');

    const shareButton = wrapper.findAll('button').find((button) => button.text() === 'Share link');
    await shareButton.trigger('click');

    const shareCall = calls.find((call) => call.action === 'files.share.create');
    const body = JSON.parse(shareCall.init.body);

    expect(body.max_views).toBe(5);
    expect(body.expires_at).toContain('2026-03-10T');
    expect(body.password).toBe('Secret 123');
  });

  it('removes an existing share password with an explicit action', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { wrapper, calls } = await mountBrowserApp({
      handlers: {
        'files.share.get': () => jsonResponse({
          share: {
            file_id: 7,
            token: 'feedfacefeedfacefeedfacefeedface',
            url: 'http://localhost/share/?token=feedfacefeedfacefeedfacefeedface',
            download_url: 'http://localhost/api/index.php?action=share.stream&token=feedfacefeedfacefeedfacefeedface&disposition=attachment',
            created_at: '2026-03-09T00:00:00Z',
            updated_at: '2026-03-09T00:00:00Z',
            expires_at: null,
            max_views: null,
            view_count: 0,
            remaining_views: null,
            revoked_at: null,
            requires_password: true,
          },
        }),
        'files.share.create': () => jsonResponse({
          share: {
            file_id: 7,
            token: 'feedfacefeedfacefeedfacefeedface',
            url: 'http://localhost/share/?token=feedfacefeedfacefeedfacefeedface',
            download_url: 'http://localhost/api/index.php?action=share.stream&token=feedfacefeedfacefeedfacefeedface&disposition=attachment',
            created_at: '2026-03-09T00:00:00Z',
            updated_at: '2026-03-09T00:00:00Z',
            expires_at: null,
            max_views: null,
            view_count: 0,
            remaining_views: null,
            revoked_at: null,
            requires_password: false,
          },
        }),
      },
    });

    await wrapper.find('tbody tr').trigger('click');
    await flushPromises();

    const removeButton = wrapper.findAll('button').find((button) => button.text() === 'Remove password');
    await removeButton.trigger('click');
    await flushPromises();

    const shareCall = calls.filter((call) => call.action === 'files.share.create').at(-1);
    const body = JSON.parse(shareCall.init.body);
    expect(confirmSpy).toHaveBeenCalled();
    expect(body.clear_password).toBe(true);
    expect(body.password).toBeNull();
  });

  it('renders the full-page blocked state for blocked API responses', async () => {
    const blockedUntil = new Date(Date.now() + 5 * 60 * 1000).toISOString();
    const { wrapper } = await mountBrowserApp({
      bootstrapUser: null,
      handlers: {
        'auth.session': () => jsonResponse(sessionPayload(null)),
        'auth.login': () => errorResponse({
          message: 'You have been blocked.',
          blocked: {
            source: 'auth_login',
            blocked_until: blockedUntil,
            blocked_permanently: false,
            retry_after_seconds: 300,
          },
        }),
      },
    });

    await wrapper.find('input[type="text"]').setValue('superadmin');
    await wrapper.find('input[type="password"]').setValue('wrong-password');
    await wrapper.find('.auth-form').trigger('submit');
    await flushPromises();
    await flushPromises();

    expect(wrapper.text()).toContain('You have been blocked');
    expect(wrapper.text()).not.toContain('Sign in to continue');
  });

  it('renders the maintenance state for blocked browser sessions', async () => {
    const { wrapper } = await mountBrowserApp({
      bootstrapUser: null,
      handlers: {
        'auth.session': () => jsonResponse({
          ...sessionPayload(null),
          maintenance: {
            enabled: true,
            scope: 'app_only',
            message: 'Scheduled updates are running.\nPlease come back shortly.',
            blocks_current_user: true,
          },
        }),
      },
    });

    expect(wrapper.text()).toContain('The file browser is temporarily unavailable');
    expect(wrapper.text()).toContain('Scheduled updates are running.');
    expect(wrapper.text()).not.toContain('Sign in to continue');
  });

  it('hides the sidebar storage card when the browser user is logged out', async () => {
    const { wrapper } = await mountBrowserApp({
      bootstrapUser: null,
      handlers: {
        'auth.session': () => jsonResponse(sessionPayload(null)),
      },
    });

    expect(wrapper.text()).toContain('Sign in to continue');
    expect(wrapper.find('.storage-meter').exists()).toBe(false);
    expect(wrapper.find('.sidebar-meta').text()).toContain('1.0.0-alpha');
  });

  it('saves file descriptions from the info drawer', async () => {
    const { wrapper, calls } = await mountBrowserApp({
      handlers: {
        'files.share.get': () => jsonResponse({ share: null }),
        'files.notes.save': () => jsonResponse({
          item: browserFile({
            description: 'Pinned reference',
          }),
        }),
      },
    });

    await wrapper.find('tbody tr').trigger('click');
    await flushPromises();

    const infoButton = wrapper.findAll('button').find((button) => button.text() === 'Info');
    await infoButton.trigger('click');
    await flushPromises();

    const textarea = wrapper.find('.note-panel textarea');
    await textarea.setValue('Pinned reference');

    const saveButton = wrapper.findAll('.note-panel button').find((button) => button.text() === 'Save description');
    await saveButton.trigger('click');
    await flushPromises();

    const saveCall = calls.find((call) => call.action === 'files.notes.save');
    const body = JSON.parse(saveCall.init.body);
    expect(body.file_id).toBe(7);
    expect(body.description).toBe('Pinned reference');
    expect(wrapper.find('.note-panel textarea').element.value).toBe('Pinned reference');
  });

  it('renders cached folder sizes and PDF thumbnails in grid view', async () => {
    localStorage.setItem('wb-filebrowser:view-mode', 'grid');

    const { wrapper } = await mountBrowserApp({
      handlers: {
        'tree.list': () => jsonResponse({
          data: {
            folder: { id: 1, type: 'folder', name: 'Home', parent_id: null, size_label: '-', updated_relative: 'just now', description: '' },
            breadcrumbs: [{ id: 1, name: 'Home' }],
            folders: [
              {
                id: 2,
                type: 'folder',
                name: 'Reports',
                parent_id: 1,
                size: 8192,
                size_label: '8 KB',
                mime_type: 'inode/directory',
                description: '',
                updated_at: '2026-03-09T00:00:00Z',
                updated_relative: 'just now',
                cached_size_calculated_at: '2026-03-09T00:00:00Z',
                child_count: 0,
                can_open: true,
                can_upload: false,
                can_create_folders: false,
                can_edit: true,
                can_delete: true,
              },
            ],
            files: [browserFile()],
            can_upload: true,
            can_create_folders: true,
            can_edit: true,
            can_delete: true,
          },
        }),
      },
    });

    await flushPromises();

    expect(renderPdfThumbnail).toHaveBeenCalled();
    expect(wrapper.text()).toContain('8 KB');
    expect(wrapper.find('.grid-card__thumb').attributes('src')).toBe('data:image/png;base64,pdf-thumb');
  });

  it('shows a username-aware upload toast for a single file', async () => {
    const { wrapper } = await mountBrowserApp({
      handlers: {
        'upload.init': () => jsonResponse({
          data: {
            upload_token: 'upload-token',
            chunk_size: 2097152,
          },
        }),
        'upload.chunk': () => jsonResponse({}),
        'upload.complete': () => jsonResponse({}),
      },
    });

    const input = wrapper.find('input[type="file"]');
    const file = new File(['hello'], 'setup.jar', { type: 'application/java-archive' });
    Object.defineProperty(input.element, 'files', {
      value: [file],
      configurable: true,
    });

    await input.trigger('change');
    await flushPromises();
    await flushPromises();

    expect(wrapper.text()).toContain('Uploaded file by admin: setup.jar.');
  });

  it('shows a username-aware upload toast for multiple files', async () => {
    const { wrapper } = await mountBrowserApp({
      handlers: {
        'upload.init': () => jsonResponse({
          data: {
            upload_token: 'upload-token',
            chunk_size: 2097152,
          },
        }),
        'upload.chunk': () => jsonResponse({}),
        'upload.complete': () => jsonResponse({}),
      },
    });

    const input = wrapper.find('input[type="file"]');
    const files = [
      new File(['one'], 'first.jar', { type: 'application/java-archive' }),
      new File(['two'], 'second.exe', { type: 'application/vnd.microsoft.portable-executable' }),
    ];
    Object.defineProperty(input.element, 'files', {
      value: files,
      configurable: true,
    });

    await input.trigger('change');
    await flushPromises();
    await flushPromises();

    expect(wrapper.text()).toContain('Uploaded 2 files by admin.');
  });

  it('toggles the mobile drawer shell state and closes it after navigation actions', async () => {
    const { wrapper } = await mountBrowserApp();

    expect(wrapper.find('.wb-shell').classes()).not.toContain('is-mobile-nav-open');

    const mobileButton = wrapper.find('.mobile-nav-toggle');
    await mobileButton.trigger('click');

    expect(wrapper.find('.wb-shell').classes()).toContain('is-mobile-nav-open');
    expect(document.body.style.overflow).toBe('hidden');

    await wrapper.find('.sidebar-link').trigger('click');

    expect(wrapper.find('.wb-shell').classes()).not.toContain('is-mobile-nav-open');
    expect(document.body.style.overflow).toBe('');
  });

  it('opens an item context menu on right click in the browser file table', async () => {
    const { wrapper } = await mountBrowserApp();

    await wrapper.find('tbody tr').trigger('contextmenu', { clientX: 48, clientY: 52 });
    await flushPromises();

    const menu = wrapper.find('.context-menu');
    expect(menu.exists()).toBe(true);
    expect(menu.text()).toContain('Rename');
    expect(menu.text()).toContain('Delete');
  });

  it('opens a workspace context menu on empty browser area right click', async () => {
    const { wrapper } = await mountBrowserApp();

    await wrapper.find('.table-wrap').trigger('contextmenu', { clientX: 24, clientY: 36 });
    await flushPromises();

    const menu = wrapper.find('.context-menu');
    expect(menu.exists()).toBe(true);
    expect(menu.text()).toContain('Upload');
    expect(menu.text()).toContain('New folder');
    expect(menu.text()).toContain('Refresh');
  });

  it('shows queue progress and sends relative path segments for dropped folder uploads', async () => {
    let releaseChunk;
    const chunkGate = new Promise((resolve) => {
      releaseChunk = resolve;
    });
    const { wrapper, calls } = await mountBrowserApp({
      handlers: {
        'folders.ensure_path': () => jsonResponse({
          folder: { id: 9, type: 'folder', name: 'Empty', parent_id: 1, size_label: '-', updated_relative: 'just now' },
        }),
        'upload.init': () => jsonResponse({
          data: {
            upload_token: 'upload-token',
            chunk_size: 2097152,
          },
        }),
        'upload.chunk': async () => {
          await chunkGate;
          return jsonResponse({});
        },
        'upload.complete': () => jsonResponse({}),
      },
    });

    const fileEntry = {
      isFile: true,
      isDirectory: false,
      name: 'brief.txt',
      file: (resolve) => {
        resolve(new File(['brief'], 'brief.txt', { type: 'text/plain' }));
      },
    };
    const emptyDirectory = {
      isFile: false,
      isDirectory: true,
      name: 'Empty',
      createReader: () => {
        let done = false;
        return {
          readEntries(resolve) {
            if (done) {
              resolve([]);
              return;
            }
            done = true;
            resolve([]);
          },
        };
      },
    };
    const rootDirectory = {
      isFile: false,
      isDirectory: true,
      name: 'Projects',
      createReader: () => {
        let done = false;
        return {
          readEntries(resolve) {
            if (done) {
              resolve([]);
              return;
            }
            done = true;
            resolve([
              {
                isFile: false,
                isDirectory: true,
                name: '2026',
                createReader: () => {
                  let childDone = false;
                  return {
                    readEntries(childResolve) {
                      if (childDone) {
                        childResolve([]);
                        return;
                      }
                      childDone = true;
                      childResolve([fileEntry]);
                    },
                  };
                },
              },
              emptyDirectory,
            ]);
          },
        };
      },
    };

    const dropEvent = new Event('drop');
    Object.defineProperty(dropEvent, 'dataTransfer', {
      value: {
        items: [
          {
            kind: 'file',
            webkitGetAsEntry: () => rootDirectory,
          },
        ],
        files: [],
      },
    });
    dropEvent.preventDefault = vi.fn();

    window.dispatchEvent(dropEvent);
    await flushPromises();

    expect(wrapper.text()).toContain('Uploading 0 of 1 files');
    expect(wrapper.text()).toContain('Projects/2026/brief.txt');

    const ensurePathCall = calls.find((call) => call.action === 'folders.ensure_path');
    expect(ensurePathCall).toBeTruthy();
    expect(JSON.parse(ensurePathCall.init.body).path_segments).toEqual(['Projects', 'Empty']);

    const initCall = calls.find((call) => call.action === 'upload.init');
    expect(initCall).toBeTruthy();
    expect(JSON.parse(initCall.init.body).relative_path_segments).toEqual(['Projects', '2026']);

    releaseChunk();
    await flushPromises();
    await flushPromises();

    expect(wrapper.text()).toContain('Uploaded file by admin: brief.txt.');
  });
});
