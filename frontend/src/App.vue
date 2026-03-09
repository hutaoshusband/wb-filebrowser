<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { describePermissionPrincipal, filterPermissionRows, filterUsers, getSearchConfig, jobTone } from './lib/admin.js';
import { collectDroppedItems } from './lib/folderDrop.js';
import { renderPdfThumbnail } from './lib/thumbnails.js';
import { validateUploadCandidate } from './lib/uploadPolicy.js';

const ADMIN_SECTIONS = ['dashboard', 'users', 'permissions', 'settings', 'audit', 'security'];
const SETTING_TABS = ['access', 'display', 'uploads', 'automation'];
const BLOCKED_STORAGE_KEY = 'wb-filebrowser:blocked-state';
const THUMB_OBSERVER_KEY = Symbol('wb-thumb-observer');
const DEFAULT_CHUNK_SIZE = 2097152;

function createDefaultUploadPolicy() {
  return {
    max_file_size_mb: 0,
    max_file_size_bytes: null,
    max_file_size_label: 'No app limit',
    has_app_limit: false,
    allowed_extensions: [],
    allowed_extensions_label: 'Any file type',
    stale_upload_ttl_hours: 24,
  };
}

function createDefaultMaintenance() {
  return {
    enabled: false,
    scope: 'app_only',
    message: 'The file browser is temporarily unavailable while maintenance is in progress. Please try again later.',
    blocks_current_user: false,
  };
}

function createDefaultDisplaySettings() {
  return {
    grid_thumbnails_enabled: true,
  };
}

function createDefaultSettings() {
  return {
    access: {
      public_access: false,
      maintenance_enabled: false,
      maintenance_scope: 'app_only',
      maintenance_message: createDefaultMaintenance().message,
      share_terms_enabled: false,
      share_terms_message: 'By opening or downloading this shared file, you confirm that you are authorized to access it and will handle it according to the applicable terms and confidentiality requirements.',
    },
    uploads: {
      max_file_size_mb: 0,
      allowed_extensions: '',
      stale_upload_ttl_hours: 24,
    },
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
    display: createDefaultDisplaySettings(),
  };
}

function cloneSettings(settings) {
  return JSON.parse(JSON.stringify(settings ?? createDefaultSettings()));
}

const bootstrap = window.WB_BOOTSTRAP ?? {};
const shell = bootstrap.surface ?? document.body.dataset.shell ?? 'app';
const basePath = bootstrap.base_path ?? '';

const session = reactive({
  csrfToken: bootstrap.csrf_token ?? '',
  user: bootstrap.user ?? null,
  publicAccess: false,
  rootFolderId: 1,
  appVersion: bootstrap.app_version ?? '1.0.0-alpha',
  storage: { used_label: '0 B', total_label: 'Unknown' },
  diagnostic: { exposed: false, checked_at: '', message: '', probe_path: '', probe_url: '' },
  maintenance: { ...createDefaultMaintenance(), ...(bootstrap.maintenance ?? {}) },
  display: { ...createDefaultDisplaySettings(), ...(bootstrap.display ?? {}) },
  help: { title: 'Help', body: 'Use the admin panel to publish folders and review the storage shield diagnostic.' },
  uploadPolicy: createDefaultUploadPolicy(),
});

const route = reactive({
  folderId: 1,
  section: shell === 'admin' ? 'dashboard' : 'browse',
  userId: 0,
});

const folderState = reactive({
  loading: false,
  folder: null,
  breadcrumbs: [],
  folders: [],
  files: [],
  can_upload: false,
  can_create_folders: false,
  can_edit: false,
  can_delete: false,
});

const searchState = reactive({
  folders: [],
  files: [],
});

const adminState = reactive({
  loading: false,
  dashboard: null,
  users: [],
  settings: createDefaultSettings(),
  settingsTab: 'access',
  canManageSettings: false,
  permissionRows: [],
  permissionEntries: {},
  userPermissionRows: [],
  userPermissionEntries: {},
  automationJobs: [],
  automationBusy: false,
  auditLogs: [],
  auditPage: 1,
  auditTotalPages: 1,
  auditTotalItems: 0,
  auditCategory: '',
  auditCategories: [],
  auditCleanupMode: 'older_than_days',
  auditCleanupDays: 30,
  auditCleanupBusy: false,
  activeBans: [],
  banHistory: [],
  securityBusy: false,
});

const shareState = reactive({
  fileId: 0,
  loading: false,
  link: null,
});
const shareForm = reactive({
  fileId: 0,
  expiresAtLocal: '',
  maxViews: '',
  password: '',
});

const authForm = reactive({ username: '', password: '' });
const newUserForm = reactive({ username: '', password: '', role: 'user', force_password_reset: false });
const banForm = reactive({ ipAddress: '', reason: '', expiresAtLocal: '' });

const searchQuery = ref('');
const sortBy = ref('name');
const sortDirection = ref('asc');
const viewMode = ref(window.localStorage.getItem('wb-filebrowser:view-mode') ?? 'list');
const mobileNavOpen = ref(false);
const selectMode = ref(false);
const selectedKey = ref('');
const previewItem = ref(null);
const previewText = ref('');
const infoItem = ref(null);
const helpOpen = ref(false);
const contextMenu = ref(null);
const statusMessage = ref('');
const uploadQueue = ref(null);
const dragDepth = ref(0);
const isBooting = ref(true);
const fileInput = ref(null);
const blockedCountdown = ref('');
const descriptionDraft = ref('');
const descriptionSaving = ref(false);

const thumbnailState = reactive({
  imageErrors: {},
  pdfUrls: {},
  pdfLoading: {},
  pdfErrors: {},
});

const blockedState = reactive({
  active: false,
  source: '',
  blockedUntil: '',
  blockedPermanently: false,
  retryAfterSeconds: null,
});

let searchTimer = 0;
let automationTimer = 0;
let blockedTimer = 0;

const isAdmin = computed(() => ['admin', 'super_admin'].includes(session.user?.role ?? ''));
const isSuperAdmin = computed(() => session.user?.role === 'super_admin');
const isAdminShell = computed(() => shell === 'admin');
const needsLogin = computed(() => isAdminShell.value ? session.user === null : session.user === null && !session.publicAccess);
const accessDenied = computed(() => isAdminShell.value && session.user !== null && !isAdmin.value);
const showDiagnosticWarning = computed(() => isAdmin.value && session.diagnostic.exposed);
const searchConfig = computed(() => getSearchConfig(shell, route.section, { userId: route.userId }));
const searchActive = computed(() => shell !== 'admin' && searchQuery.value.trim() !== '');
const currentEntries = computed(() => (searchActive.value ? [...searchState.folders, ...searchState.files] : [...folderState.folders, ...folderState.files]));
const selectedItem = computed(() => currentEntries.value.find((item) => rowKey(item) === selectedKey.value) ?? null);
const canUploadHere = computed(() => shell === 'app' && session.user !== null && folderState.can_upload);
const canCreateFoldersHere = computed(() => shell === 'app' && folderState.can_create_folders);
const canManageShares = computed(() => shell === 'app' && isAdmin.value);
const canEditDescription = computed(() => Boolean(infoItem.value?.can_edit));
const breadcrumbItems = computed(() => searchActive.value
  ? [{ id: session.rootFolderId, name: 'Home' }, { id: -1, name: 'Search results' }]
  : folderState.breadcrumbs);
const filteredUsers = computed(() => filterUsers(adminState.users, searchQuery.value, route.section));
const filteredPermissionRows = computed(() => route.section === 'permissions'
  ? filterPermissionRows(adminState.permissionRows, searchQuery.value)
  : adminState.permissionRows);
const auditCleanupRequiresDays = computed(() => adminState.auditCleanupMode !== 'all');
const canRunAuditCleanup = computed(() => isSuperAdmin.value);
const filteredUserPermissionRows = computed(() => route.section === 'users' && route.userId > 0
  ? filterPermissionRows(adminState.userPermissionRows, searchQuery.value)
  : adminState.userPermissionRows);
const permissionPrincipalCopy = computed(() => describePermissionPrincipal('guest', 0, adminState.users));
const activeAdminUser = computed(() => adminState.users.find((user) => Number(user.id) === Number(route.userId)) ?? null);
const automationJobs = computed(() => adminState.automationJobs);
const dueAutomationCount = computed(() => automationJobs.value.filter((job) => job.is_due).length);
const uploadAccept = computed(() => session.uploadPolicy.allowed_extensions.length === 0
  ? null
  : session.uploadPolicy.allowed_extensions.map((extension) => `.${extension}`).join(','));
const thumbnailsEnabled = computed(() => Boolean(session.display?.grid_thumbnails_enabled));
const descriptionDirty = computed(() => {
  if (!infoItem.value) {
    return false;
  }

  return descriptionDraft.value !== String(infoItem.value.description ?? '');
});
const descriptionTooLong = computed(() => descriptionDraft.value.length > 1000);
const shareContextItem = computed(() => {
  if (!canManageShares.value) {
    return null;
  }

  if (infoItem.value?.type === 'file') {
    return infoItem.value;
  }

  if (previewItem.value?.type === 'file') {
    return previewItem.value;
  }

  return null;
});
const uploadQueueOverallPercent = computed(() => {
  if (!uploadQueue.value || uploadQueue.value.totalBytes === 0) {
    return 0;
  }

  return Math.min(100, Math.round((uploadQueue.value.uploadedBytes / uploadQueue.value.totalBytes) * 100));
});
const uploadQueueCurrentPercent = computed(() => {
  if (!uploadQueue.value || uploadQueue.value.currentFileBytesTotal === 0) {
    return 0;
  }

  return Math.min(100, Math.round((uploadQueue.value.currentFileBytesSent / uploadQueue.value.currentFileBytesTotal) * 100));
});

function apiUrl(action, params = {}) {
  const url = new URL(`${window.location.origin}${basePath}/api/index.php`);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });
  return url.toString();
}

async function api(action, options = {}) {
  const { method = 'GET', params = {}, body = null, formData = null } = options;
  const fetchOptions = { method, credentials: 'same-origin', headers: {} };

  if (formData instanceof FormData) {
    if (!formData.has('csrf_token')) {
      formData.append('csrf_token', session.csrfToken);
    }
    fetchOptions.body = formData;
  } else if (body !== null) {
    fetchOptions.headers['Content-Type'] = 'application/json';
    fetchOptions.body = JSON.stringify({ ...(method !== 'GET' ? { csrf_token: session.csrfToken } : {}), ...body });
  }

  const response = await fetch(apiUrl(action, params), fetchOptions);
  const payload = await response.json();

  if (!response.ok || !payload.ok) {
    if (payload?.blocked) {
      applyBlockedState(payload.blocked);
    }
    if (payload?.maintenance) {
      applyMaintenanceState(payload.maintenance);
    }
    throw new Error(payload.message ?? 'Request failed.');
  }

  if (payload.csrf_token) {
    session.csrfToken = payload.csrf_token;
  }

  return payload;
}

function rowKey(item) {
  return `${item.type}:${item.id}`;
}

function toggleSort(column) {
  if (sortBy.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortBy.value = column;
    sortDirection.value = column === 'updated_at' ? 'desc' : 'asc';
  }

  refreshCurrentView().catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to refresh the view.'));
}

function syncRouteFromHash() {
  if (isAdminShell.value) {
    const userMatch = window.location.hash.match(/^#\/users\/(\d+)/);

    if (userMatch) {
      route.section = 'users';
      route.userId = Number(userMatch[1]);
      return;
    }

    const section = window.location.hash.replace(/^#\//, '') || 'dashboard';
    route.section = ADMIN_SECTIONS.includes(section) ? section : 'dashboard';
    route.userId = 0;
    if (!ADMIN_SECTIONS.includes(section)) {
      window.location.hash = '#/dashboard';
    }
    return;
  }

  const folderMatch = window.location.hash.match(/^#\/folder\/(\d+)/);
  if (folderMatch) {
    route.folderId = Number(folderMatch[1]);
    return;
  }

  route.folderId = session.rootFolderId || 1;
  if (window.location.hash === '') {
    window.location.hash = `#/folder/${route.folderId}`;
  }
}

async function refreshSession() {
  const payload = await api('auth.session', {
    params: { surface: shell },
  });
  session.user = payload.user ?? null;
  session.publicAccess = Boolean(payload.public_access);
  session.rootFolderId = payload.root_folder_id ?? 1;
  session.appVersion = payload.app_version ?? session.appVersion;
  session.storage = payload.storage ?? session.storage;
  session.diagnostic = payload.diagnostic ?? session.diagnostic;
  session.display = { ...createDefaultDisplaySettings(), ...(payload.display ?? session.display) };
  applyMaintenanceState(payload.maintenance);
  session.help = payload.help ?? session.help;
  session.uploadPolicy = payload.upload_policy ?? session.uploadPolicy;
  session.csrfToken = payload.csrf_token ?? session.csrfToken;
}

function showMessage(message) {
  if (blockedState.active || session.maintenance.blocks_current_user) {
    return;
  }

  statusMessage.value = message;
  window.clearTimeout(showMessage.timer);
  showMessage.timer = window.setTimeout(() => {
    statusMessage.value = '';
  }, 4200);
}

function setSearchForSection(section) {
  if (!getSearchConfig(shell, section).enabled) {
    searchQuery.value = '';
  }
}

function applyMaintenanceState(payload = null) {
  session.maintenance = {
    ...createDefaultMaintenance(),
    ...(payload ?? {}),
    blocks_current_user: Boolean(payload?.blocks_current_user),
  };
}

function applyAutomationState(payload) {
  if (payload.jobs) {
    adminState.automationJobs = payload.jobs;
  }
  if (payload.diagnostic) {
    session.diagnostic = payload.diagnostic;
  }
  if (payload.diagnostics) {
    session.diagnostic = payload.diagnostics;
  }
}

function applySettingsPayload(payload) {
  if (payload.settings) {
    adminState.settings = cloneSettings(payload.settings);
  }
  if (payload.can_manage_settings !== undefined) {
    adminState.canManageSettings = Boolean(payload.can_manage_settings);
  }
  if (payload.upload_policy) {
    session.uploadPolicy = payload.upload_policy;
  }
  if (payload.settings?.display) {
    session.display = { ...createDefaultDisplaySettings(), ...payload.settings.display };
  }
  applyAutomationState(payload.automation ?? payload);
}

function applySecurityPayload(payload) {
  applySettingsPayload(payload);
  adminState.activeBans = payload.active_bans ?? [];
  adminState.banHistory = payload.ban_history ?? [];
}

async function refreshCurrentView() {
  if (isAdminShell.value) {
    if (isAdmin.value) {
      await loadAdminSection();
    }
    return;
  }

  if (session.maintenance.blocks_current_user) {
    folderState.folders = [];
    folderState.files = [];
    folderState.breadcrumbs = [];
    searchState.folders = [];
    searchState.files = [];
    return;
  }

  if (needsLogin.value) {
    folderState.folders = [];
    folderState.files = [];
    folderState.breadcrumbs = [];
    return;
  }

  if (searchActive.value) {
    await runSearch();
  } else {
    await loadFolder(route.folderId);
  }
}

async function loadFolder(folderId = route.folderId) {
  folderState.loading = true;
  try {
    const payload = await api('tree.list', {
      params: {
        folder_id: folderId,
        sort: sortBy.value,
        direction: sortDirection.value,
      },
    });
    Object.assign(folderState, payload.data);
    selectedKey.value = '';
  } finally {
    folderState.loading = false;
  }
}

async function runSearch() {
  if (searchQuery.value.trim() === '') {
    searchState.folders = [];
    searchState.files = [];
    return;
  }

  const payload = await api('tree.search', {
    params: {
      query: searchQuery.value.trim(),
      sort: sortBy.value,
      direction: sortDirection.value,
    },
  });
  searchState.folders = payload.data.folders;
  searchState.files = payload.data.files;
  selectedKey.value = '';
}

function debounceSearch() {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(async () => {
    try {
      if (shell === 'admin') {
        if (route.section === 'audit' && isAdmin.value) {
          await loadAuditLogs(1);
        }
        return;
      }

      if (searchQuery.value.trim() === '') {
        await loadFolder(route.folderId);
      } else {
        await runSearch();
      }
    } catch (error) {
      showMessage(error instanceof Error ? error.message : 'Search failed.');
    }
  }, 220);
}

function navigateToFolder(folderId) {
  closeMobileNav();
  const nextHash = `#/folder/${folderId}`;
  if (window.location.hash === nextHash) {
    route.folderId = folderId;
    loadFolder(folderId).catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to load this folder.'));
    return;
  }
  window.location.hash = nextHash;
}

function setAdminSection(section) {
  closeMobileNav();
  const nextHash = section === 'users' ? '#/users' : `#/${section}`;
  if (window.location.hash === nextHash) {
    route.section = section;
    route.userId = 0;
    loadAdminSection().catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to load this section.'));
    return;
  }
  window.location.hash = nextHash;
}

function openUserDetails(user) {
  if (!user) {
    return;
  }

  closeMobileNav();
  const nextHash = `#/users/${user.id}`;

  if (window.location.hash === nextHash) {
    route.section = 'users';
    route.userId = Number(user.id);
    loadAdminSection().catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to load this user.'));
    return;
  }

  window.location.hash = nextHash;
}

function browseHome() {
  closeMobileNav();
  if (isAdminShell.value) {
    goToBrowserRoot();
    return;
  }

  navigateToFolder(session.rootFolderId);
}

function redirectTo(url) {
  if (typeof window.__WB_REDIRECT__ === 'function') {
    window.__WB_REDIRECT__(url);
    return;
  }
  window.location.assign(url);
}

function goToBrowserRoot() {
  closeMobileNav();
  redirectTo(`${basePath}/`);
}

function openAdminPanel() {
  closeMobileNav();
  redirectTo(`${basePath}/admin/#/${isSuperAdmin.value ? 'dashboard' : 'users'}`);
}

function toggleMobileNav() {
  mobileNavOpen.value = !mobileNavOpen.value;
}

function closeMobileNav() {
  mobileNavOpen.value = false;
}

function selectEntry(item) {
  selectedKey.value = rowKey(item);
  infoItem.value = item;
}

function closeContextMenu() {
  contextMenu.value = null;
}

function handleEntryClick(item) {
  closeContextMenu();
  if (selectMode.value) {
    selectEntry(item);
    return;
  }

  if (item.type === 'folder') {
    navigateToFolder(item.id);
    return;
  }

  openPreview(item).catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to open the preview.'));
}

function canEditItem(item) {
  if (!item) {
    return false;
  }

  return Boolean(item.can_edit);
}

function canDeleteItem(item) {
  if (!item) {
    return false;
  }

  return Boolean(item.can_delete);
}

function canShowItemActions(item) {
  if (!item) {
    return false;
  }

  return canEditItem(item) || canDeleteItem(item) || (item.type === 'file' && canManageShares.value);
}

function replaceEntry(list, item) {
  const index = list.findIndex((entry) => rowKey(entry) === rowKey(item));

  if (index !== -1) {
    list[index] = item;
  }
}

function applyItemUpdate(item) {
  if (!item) {
    return;
  }

  replaceEntry(folderState.folders, item);
  replaceEntry(folderState.files, item);
  replaceEntry(searchState.folders, item);
  replaceEntry(searchState.files, item);

  if (infoItem.value && rowKey(infoItem.value) === rowKey(item)) {
    infoItem.value = item;
  }

  if (previewItem.value && rowKey(previewItem.value) === rowKey(item)) {
    previewItem.value = item;
  }
}

function syncDescriptionDraft(item = infoItem.value) {
  descriptionDraft.value = item ? String(item.description ?? '') : '';
}

function handleContextMenu(event, item) {
  if (shell !== 'app' || !canShowItemActions(item)) {
    return;
  }

  event.preventDefault();
  selectEntry(item);
  contextMenu.value = { kind: 'item', x: event.clientX, y: event.clientY, item };
}

function handleWorkspaceContextMenu(event) {
  if (shell !== 'app' || event.target?.closest('[data-entry-surface="true"]')) {
    return;
  }

  event.preventDefault();
  contextMenu.value = { kind: 'workspace', x: event.clientX, y: event.clientY };
}

function previewMode(item) {
  return item?.preview_mode ?? 'download';
}

function fallbackBadge(item) {
  const extension = String(item?.extension ?? '').replace(/^\./, '').trim().toUpperCase();
  return extension === '' ? 'FILE' : extension.slice(0, 10);
}

function fallbackIconUrl(item) {
  return item?.fallback_icon_url ?? `${basePath}/media/file-fallbacks/binary.svg`;
}

function fallbackLabel(item) {
  return item?.fallback_label ?? 'Download-only file';
}

function thumbnailKey(item) {
  return `${rowKey(item)}:${item.preview_url ?? item.download_url ?? ''}`;
}

function thumbnailKind(item) {
  if (!thumbnailsEnabled.value || item?.type !== 'file') {
    return null;
  }

  const mode = previewMode(item);

  if (mode === 'image' || mode === 'pdf') {
    return mode;
  }

  return null;
}

function thumbnailImageUrl(item) {
  const kind = thumbnailKind(item);
  const key = thumbnailKey(item);

  if (kind === 'image' && !thumbnailState.imageErrors[key]) {
    return item.preview_url;
  }

  if (kind === 'pdf' && thumbnailState.pdfUrls[key]) {
    return thumbnailState.pdfUrls[key];
  }

  return null;
}

function markThumbnailError(item) {
  thumbnailState.imageErrors[thumbnailKey(item)] = true;
}

async function ensurePdfThumbnail(item) {
  if (thumbnailKind(item) !== 'pdf') {
    return;
  }

  const key = thumbnailKey(item);

  if (thumbnailState.pdfUrls[key] || thumbnailState.pdfLoading[key] || thumbnailState.pdfErrors[key]) {
    return;
  }

  thumbnailState.pdfLoading[key] = true;

  try {
    thumbnailState.pdfUrls[key] = await renderPdfThumbnail(item.preview_url);
  } catch (_) {
    thumbnailState.pdfErrors[key] = true;
  } finally {
    delete thumbnailState.pdfLoading[key];
  }
}

const vThumbObserve = {
  mounted(element, binding) {
    if (typeof binding.value !== 'function') {
      return;
    }

    if (typeof IntersectionObserver === 'undefined') {
      binding.value();
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) {
        return;
      }

      binding.value();
      observer.disconnect();
      delete element[THUMB_OBSERVER_KEY];
    }, {
      rootMargin: '160px',
    });

    observer.observe(element);
    element[THUMB_OBSERVER_KEY] = observer;
  },
  unmounted(element) {
    element[THUMB_OBSERVER_KEY]?.disconnect?.();
    delete element[THUMB_OBSERVER_KEY];
  },
};

async function openPreview(item) {
  previewItem.value = item;
  previewText.value = '';

  if (previewMode(item) === 'text') {
    const response = await fetch(item.preview_url, { credentials: 'same-origin', cache: 'no-store' });
    previewText.value = await response.text();
  }
}

function closePreview() {
  previewItem.value = null;
  previewText.value = '';
}

async function saveDescription() {
  if (!infoItem.value || !canEditDescription.value) {
    showMessage('You do not have permission to edit this description.');
    return;
  }

  if (descriptionTooLong.value) {
    showMessage('Descriptions must be 1000 characters or fewer.');
    return;
  }

  descriptionSaving.value = true;

  try {
    const payload = await api(infoItem.value.type === 'folder' ? 'folders.notes.save' : 'files.notes.save', {
      method: 'POST',
      body: infoItem.value.type === 'folder'
        ? { folder_id: infoItem.value.id, description: descriptionDraft.value }
        : { file_id: infoItem.value.id, description: descriptionDraft.value },
    });
    applyItemUpdate(payload.item);
    syncDescriptionDraft(payload.item);
    showMessage('Description saved.');
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to save the description.');
  } finally {
    descriptionSaving.value = false;
  }
}

async function submitLogin() {
  try {
    const payload = await api('auth.login', {
      method: 'POST',
      body: {
        username: authForm.username,
        password: authForm.password,
      },
    });

    session.user = payload.user;
    session.csrfToken = payload.csrf_token ?? session.csrfToken;
    authForm.password = '';
    await refreshSession();
    syncRouteFromHash();
    startAutomationPulse();

    if (isAdminShell.value && isAdmin.value) {
      await tickAutomation({ silent: true });
    }

    await refreshCurrentView();

    if (isAdminShell.value && !isAdmin.value) {
      showMessage('This account can use the file browser, but it does not have admin rights.');
    }
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to sign in.');
  }
}

async function logout() {
  closeMobileNav();
  await api('auth.logout', { method: 'POST', body: {} });
  session.user = null;
  searchQuery.value = '';
  previewItem.value = null;
  infoItem.value = null;
  selectedKey.value = '';
  resetShareState();
  stopAutomationPulse();

  if (isAdminShell.value) {
    redirectTo(`${basePath}/`);
    return;
  }

  await refreshSession();
  await refreshCurrentView();
}

function toggleViewMode() {
  closeContextMenu();
  viewMode.value = viewMode.value === 'list' ? 'grid' : 'list';
  window.localStorage.setItem('wb-filebrowser:view-mode', viewMode.value);
}

function openSettings() {
  closeContextMenu();
  closeMobileNav();
  if (isAdminShell.value && isAdmin.value) {
    setAdminSection('settings');
    return;
  }
  if (isAdmin.value) {
    openAdminPanel();
    return;
  }
  helpOpen.value = true;
}

function triggerUpload() {
  closeContextMenu();
  closeMobileNav();
  if (!canUploadHere.value) {
    showMessage('You do not have upload permission in this folder.');
    return;
  }

  fileInput.value?.click();
}

async function handleFilePicker(event) {
  const files = Array.from(event.target.files ?? []);
  if (files.length > 0) {
    await uploadQueuedItems(
      files.map((file) => ({
        file,
        relativePathSegments: [],
        relativePath: file.name,
      })),
    );
  }
  event.target.value = '';
}

function formatBytes(bytes) {
  const value = Number(bytes);

  if (!Number.isFinite(value) || value < 0) {
    return '0 B';
  }

  if (value === 0) {
    return '0 B';
  }

  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
  const scaled = value / (1024 ** power);

  return `${scaled >= 10 || power === 0 ? Math.round(scaled) : scaled.toFixed(1)} ${units[power]}`;
}

function createUploadQueueState(items) {
  return {
    totalFiles: items.length,
    totalBytes: items.reduce((sum, item) => sum + item.file.size, 0),
    completedFiles: 0,
    uploadedBytes: 0,
    currentFileName: '',
    currentFilePath: '',
    currentFileBytesSent: 0,
    currentFileBytesTotal: 0,
    currentFileChunksSent: 0,
    currentFileChunksTotal: 0,
  };
}

async function ensureDroppedDirectories(directories) {
  const seen = new Set();

  for (const directory of directories) {
    if (!directory?.relativePath || seen.has(directory.relativePath)) {
      continue;
    }

    seen.add(directory.relativePath);
    await api('folders.ensure_path', {
      method: 'POST',
      body: {
        parent_id: route.folderId,
        path_segments: directory.relativePathSegments,
      },
    });
  }
}

async function uploadQueuedItems(items, emptyDirectories = []) {
  if (!canUploadHere.value) {
    showMessage('You do not have upload permission in this folder.');
    return;
  }

  const uploadedFileCount = items.length;
  const uploadedFileName = items[0]?.file.name ?? 'file';
  const uploadedBy = session.user?.username ?? 'unknown user';

  for (const item of items) {
    const uploadError = validateUploadCandidate(item.file, session.uploadPolicy);

    if (uploadError) {
      showMessage(uploadError);
      return;
    }
  }

  if (uploadedFileCount === 0 && emptyDirectories.length === 0) {
    return;
  }

  uploadQueue.value = createUploadQueueState(items);
  let completedFiles = 0;
  let completedBytes = 0;

  try {
    await ensureDroppedDirectories(emptyDirectories);

    for (const item of items) {
      const { file, relativePath, relativePathSegments } = item;
      const totalChunks = Math.max(1, Math.ceil(file.size / DEFAULT_CHUNK_SIZE));
      uploadQueue.value = {
        ...uploadQueue.value,
        completedFiles,
        uploadedBytes: completedBytes,
        currentFileName: file.name,
        currentFilePath: relativePath,
        currentFileBytesSent: 0,
        currentFileBytesTotal: file.size,
        currentFileChunksSent: 0,
        currentFileChunksTotal: totalChunks,
      };

      const initPayload = await api('upload.init', {
        method: 'POST',
        body: {
          folder_id: route.folderId,
          original_name: file.name,
          size: file.size,
          mime_type: file.type || 'application/octet-stream',
          total_chunks: totalChunks,
          relative_path_segments: relativePathSegments,
        },
      });
      const token = initPayload.data.upload_token;
      const chunkSize = initPayload.data.chunk_size || DEFAULT_CHUNK_SIZE;

      try {
        for (let index = 0; index < totalChunks; index += 1) {
          const formData = new FormData();
          formData.append('upload_token', token);
          formData.append('chunk_index', String(index));
          formData.append('chunk', file.slice(index * chunkSize, (index + 1) * chunkSize), `${file.name}.part`);
          await api('upload.chunk', { method: 'POST', formData });
          const currentFileBytesSent = Math.min(file.size, (index + 1) * chunkSize);
          uploadQueue.value = {
            ...uploadQueue.value,
            uploadedBytes: completedBytes + currentFileBytesSent,
            currentFileBytesSent,
            currentFileChunksSent: index + 1,
          };
        }

        await api('upload.complete', { method: 'POST', body: { upload_token: token } });
      } catch (error) {
        try {
          await api('upload.cancel', { method: 'POST', body: { upload_token: token } });
        } catch (_) {
          // Best-effort cleanup for incomplete uploads.
        }

        throw error;
      }

      completedFiles += 1;
      completedBytes += file.size;
      uploadQueue.value = {
        ...uploadQueue.value,
        completedFiles,
        uploadedBytes: completedBytes,
        currentFileBytesSent: file.size,
        currentFileChunksSent: totalChunks,
      };
    }

    await refreshSession();
    await loadFolder(route.folderId);
    showMessage(
      uploadedFileCount === 0
        ? (emptyDirectories.length === 1
          ? `Created folder by ${uploadedBy}: ${emptyDirectories[0].relativePath}.`
          : `Created ${emptyDirectories.length} folders by ${uploadedBy}.`)
        : (uploadedFileCount === 1
          ? `Uploaded file by ${uploadedBy}: ${uploadedFileName}.`
          : `Uploaded ${uploadedFileCount} files by ${uploadedBy}.`),
    );
  } catch (error) {
    const failedPath = uploadQueue.value?.currentFilePath || uploadedFileName;
    showMessage(`${failedPath}: ${error instanceof Error ? error.message : 'Upload failed.'}`);
  } finally {
    uploadQueue.value = null;
  }
}

async function createFolder() {
  closeContextMenu();
  if (!canCreateFoldersHere.value) {
    showMessage('You do not have permission to create folders here.');
    return;
  }

  const name = window.prompt('New folder name');
  if (!name) {
    return;
  }

  await api('folders.create', { method: 'POST', body: { parent_id: route.folderId, name } });
  await loadFolder(route.folderId);
}

async function refreshBrowserSection() {
  closeContextMenu();
  await refreshCurrentView();
  showMessage('Folder refreshed.');
}

async function renameSelected(item = selectedItem.value) {
  closeContextMenu();
  if (!item) {
    return;
  }

  if (!canEditItem(item)) {
    showMessage('You do not have permission to rename this item.');
    return;
  }

  const name = window.prompt(`Rename ${item.type}`, item.name);
  if (!name || name === item.name) {
    return;
  }

  await api(item.type === 'folder' ? 'folders.rename' : 'files.rename', {
    method: 'POST',
    body: item.type === 'folder' ? { folder_id: item.id, name } : { file_id: item.id, name },
  });
  await refreshCurrentView();
}

function buildFolderRows(folders) {
  const map = new Map(folders.map((folder) => [folder.id, { ...folder, children: [] }]));

  for (const folder of map.values()) {
    if (folder.parent_id && map.has(folder.parent_id)) {
      map.get(folder.parent_id).children.push(folder);
    }
  }

  const roots = Array.from(map.values())
    .filter((folder) => !folder.parent_id || !map.has(folder.parent_id))
    .sort((left, right) => left.name.localeCompare(right.name));
  const rows = [];

  const visit = (folder, depth, trail) => {
    const name = folder.id === session.rootFolderId ? 'Home' : folder.name;
    rows.push({ ...folder, depth, path: [...trail, name].join(' / ') });
    folder.children
      .sort((left, right) => left.name.localeCompare(right.name))
      .forEach((child) => visit(child, depth + 1, [...trail, name]));
  };

  roots.forEach((folder) => visit(folder, 0, []));
  return rows;
}

async function ensureMoveTargets() {
  const payload = await api('tree.folders');
  return buildFolderRows(payload.folders).filter((folder) => folder.can_edit);
}

async function moveSelected(item = selectedItem.value) {
  closeContextMenu();
  if (!item) {
    return;
  }

  if (!canEditItem(item)) {
    showMessage('You do not have permission to move this item.');
    return;
  }

  const folderList = await ensureMoveTargets();
  const choices = folderList.map((folder) => `${folder.id}: ${folder.path}`).join('\n');
  const destination = window.prompt(`Move "${item.name}" to folder ID:\n${choices}`, String(route.folderId));

  if (!destination) {
    return;
  }

  await api(item.type === 'folder' ? 'folders.move' : 'files.move', {
    method: 'POST',
    body: item.type === 'folder'
      ? { folder_id: item.id, target_parent_id: Number(destination) }
      : { file_id: item.id, target_folder_id: Number(destination) },
  });
  await refreshCurrentView();
}

async function deleteSelected(item = selectedItem.value) {
  closeContextMenu();
  if (!item) {
    return;
  }

  if (!canDeleteItem(item)) {
    showMessage('You do not have permission to delete this item.');
    return;
  }

  if (!window.confirm(`Delete "${item.name}"? This cannot be undone.`)) {
    return;
  }

  await api(item.type === 'folder' ? 'folders.delete' : 'files.delete', {
    method: 'POST',
    body: item.type === 'folder' ? { folder_id: item.id } : { file_id: item.id },
  });
  previewItem.value = null;
  infoItem.value = null;
  selectedKey.value = '';
  if (shareState.fileId === item.id) {
    resetShareState();
  }
  await refreshCurrentView();
}

function resetShareState() {
  shareState.fileId = 0;
  shareState.loading = false;
  shareState.link = null;
  shareForm.fileId = 0;
  shareForm.expiresAtLocal = '';
  shareForm.maxViews = '';
  shareForm.password = '';
}

function toLocalDateTimeInput(value) {
  if (!value) {
    return '';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const pad = (input) => String(input).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromLocalDateTimeInput(value) {
  if (!value) {
    return null;
  }

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function applyShareForm(item, link = null) {
  shareForm.fileId = item?.id ?? 0;
  shareForm.expiresAtLocal = toLocalDateTimeInput(link?.expires_at ?? '');
  shareForm.maxViews = link?.max_views ? String(link.max_views) : '';
  shareForm.password = '';
}

function shareOptionsFor(item) {
  if (!item || item.type !== 'file') {
    return { expires_at: null, max_views: null };
  }

  const source = shareForm.fileId === item.id
    ? shareForm
    : { expiresAtLocal: '', maxViews: '', password: '' };
  const maxViews = Number(source.maxViews);
  const password = typeof source.password === 'string' && source.password.trim() !== ''
    ? source.password
    : null;

  return {
    expires_at: fromLocalDateTimeInput(source.expiresAtLocal),
    max_views: Number.isInteger(maxViews) && maxViews > 0 ? maxViews : null,
    password,
  };
}

async function loadShareState(item = shareContextItem.value) {
  if (!canManageShares.value || item?.type !== 'file') {
    resetShareState();
    return;
  }

  shareState.fileId = item.id;
  shareState.loading = true;

  try {
    const payload = await api('files.share.get', {
      params: { file_id: item.id },
    });

    if (shareState.fileId === item.id) {
      shareState.link = payload.share ?? null;
      applyShareForm(item, payload.share ?? null);
    }
  } finally {
    if (shareState.fileId === item.id) {
      shareState.loading = false;
    }
  }
}

async function writeShareLink(url) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(url);
    return true;
  }

  window.prompt('Copy this share link', url);
  return false;
}

async function createShareLink(item = shareContextItem.value, { open = false } = {}) {
  closeContextMenu();
  if (!item || item.type !== 'file') {
    showMessage('Choose a file first.');
    return;
  }

  try {
    const payload = await api('files.share.create', {
      method: 'POST',
      body: {
        file_id: item.id,
        ...shareOptionsFor(item),
      },
    });
    shareState.fileId = item.id;
    shareState.loading = false;
    shareState.link = payload.share;
    applyShareForm(item, payload.share);

    let copied = false;
    try {
      copied = await writeShareLink(payload.share.url);
    } catch (error) {
      window.prompt('Copy this share link', payload.share.url);
    }

    if (open) {
      window.open(payload.share.url, '_blank', 'noopener');
    }

    showMessage(copied ? 'Share link copied.' : 'Share link ready.');
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to create a share link.');
  }
}

async function openShareLink(item = shareContextItem.value) {
  closeContextMenu();
  if (!item || item.type !== 'file') {
    showMessage('Choose a file first.');
    return;
  }

  const popup = window.open('', '_blank', 'noopener');

  try {
    const payload = await api('files.share.create', {
      method: 'POST',
      body: {
        file_id: item.id,
        ...shareOptionsFor(item),
      },
    });
    shareState.fileId = item.id;
    shareState.loading = false;
    shareState.link = payload.share;
    applyShareForm(item, payload.share);
    popup?.location.replace(payload.share.url);
  } catch (error) {
    popup?.close();
    showMessage(error instanceof Error ? error.message : 'Unable to open the shared view.');
  }
}

async function removeSharePassword(item = shareContextItem.value) {
  closeContextMenu();
  if (!item || item.type !== 'file') {
    showMessage('Choose a file first.');
    return;
  }

  if (!shareState.link?.requires_password || shareState.fileId !== item.id) {
    showMessage('This share link does not have a password.');
    return;
  }

  if (!window.confirm(`Remove the share password for "${item.name}"?`)) {
    return;
  }

  try {
    const payload = await api('files.share.create', {
      method: 'POST',
      body: {
        file_id: item.id,
        ...shareOptionsFor(item),
        password: null,
        clear_password: true,
      },
    });
    shareState.fileId = item.id;
    shareState.loading = false;
    shareState.link = payload.share;
    applyShareForm(item, payload.share);
    showMessage('Share password removed.');
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to update the share password.');
  }
}

async function revokeShareLink(item = shareContextItem.value) {
  closeContextMenu();
  if (!item || item.type !== 'file') {
    showMessage('Choose a file first.');
    return;
  }

  if (shareState.fileId !== item.id || !shareState.link) {
    showMessage('This file does not have an active share link.');
    return;
  }

  if (!window.confirm(`Disable the public share link for "${item.name}"?`)) {
    return;
  }

  try {
    await api('files.share.revoke', {
      method: 'POST',
      body: { file_id: item.id },
    });
    shareState.fileId = item.id;
    shareState.loading = false;
    shareState.link = null;
    applyShareForm(item, null);
    showMessage('Share link disabled.');
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to disable the share link.');
  }
}

function downloadSelected() {
  const item = selectedItem.value ?? previewItem.value;
  if (!item || item.type !== 'file') {
    showMessage('Choose a file first.');
    return;
  }

  window.location.href = item.download_url;
}

function selectCurrentItem() {
  selectMode.value = !selectMode.value;
  if (!selectMode.value) {
    selectedKey.value = '';
  }
}

async function loadAdminUsers() {
  const payload = await api('admin.users.list');
  adminState.users = (payload.users ?? []).map((user) => ({
    ...user,
    storage_quota_input: user.storage_quota_bytes === null ? '' : String(user.storage_quota_bytes),
  }));
}

async function loadAuditLogs(page = adminState.auditPage) {
  const payload = await api('admin.audit.list', {
    params: {
      page,
      query: searchQuery.value.trim(),
      category: adminState.auditCategory,
    },
  });

  adminState.auditLogs = payload.entries ?? [];
  adminState.auditPage = payload.page ?? page;
  adminState.auditTotalPages = payload.total_pages ?? 1;
  adminState.auditTotalItems = payload.total_items ?? 0;
  adminState.auditCategory = payload.category ?? adminState.auditCategory;
  adminState.auditCategories = payload.categories ?? [];
}

function auditCleanupConfirmation() {
  if (adminState.auditCleanupMode === 'all') {
    return 'Delete all audit logs? This cannot be undone.';
  }

  if (adminState.auditCleanupMode === 'keep_last_days') {
    return `Keep only the last ${adminState.auditCleanupDays} days of audit logs? Older entries will be deleted.`;
  }

  return `Delete audit logs older than ${adminState.auditCleanupDays} days?`;
}

async function runAuditCleanup() {
  if (!canRunAuditCleanup.value) {
    return;
  }

  const days = Number(adminState.auditCleanupDays);

  if (auditCleanupRequiresDays.value && (!Number.isInteger(days) || days < 1 || days > 3650)) {
    showMessage('Cleanup days must be a whole number between 1 and 3650.');
    return;
  }

  if (!window.confirm(auditCleanupConfirmation())) {
    return;
  }

  adminState.auditCleanupBusy = true;

  try {
    const payload = await api('admin.audit.cleanup', {
      method: 'POST',
      body: {
        mode: adminState.auditCleanupMode,
        ...(auditCleanupRequiresDays.value ? { days } : {}),
      },
    });
    await loadAuditLogs(1);
    showMessage(`Deleted ${payload.deleted_count ?? 0} audit log${payload.deleted_count === 1 ? '' : 's'}.`);
  } finally {
    adminState.auditCleanupBusy = false;
  }
}

async function loadSecurityState() {
  const payload = await api('admin.security.get');
  applySecurityPayload(payload);
}

function createPermissionEntry() {
  return {
    can_view: false,
    can_upload: false,
    can_edit: false,
    can_delete: false,
    can_create_folders: false,
  };
}

function normalizePermissionEntry(entry, field) {
  if (field !== 'can_view' && entry[field]) {
    entry.can_view = true;
  }

  if (field === 'can_view' && !entry.can_view) {
    entry.can_upload = false;
    entry.can_edit = false;
    entry.can_delete = false;
    entry.can_create_folders = false;
  }

  return entry;
}

async function loadGuestPermissions() {
  const payload = await api('admin.permissions.get', {
    params: {
      principal_type: 'guest',
      principal_id: 0,
    },
  });

  adminState.permissionRows = buildFolderRows(payload.folders);
  adminState.permissionEntries = Object.fromEntries(
    adminState.permissionRows.map((row) => [row.id, createPermissionEntry()]),
  );

  for (const permission of payload.permissions) {
    adminState.permissionEntries[permission.folder_id] = {
      can_view: Number(permission.can_view) === 1,
      can_upload: false,
      can_edit: false,
      can_delete: false,
      can_create_folders: false,
    };
  }
}

async function loadUserPermissions(userId = route.userId) {
  if (!userId) {
    adminState.userPermissionRows = [];
    adminState.userPermissionEntries = {};
    return;
  }

  const payload = await api('admin.permissions.get', {
    params: {
      principal_type: 'user',
      principal_id: userId,
    },
  });

  adminState.userPermissionRows = buildFolderRows(payload.folders);
  adminState.userPermissionEntries = Object.fromEntries(
    adminState.userPermissionRows.map((row) => [row.id, createPermissionEntry()]),
  );

  for (const permission of payload.permissions) {
    adminState.userPermissionEntries[permission.folder_id] = {
      can_view: Number(permission.can_view) === 1,
      can_upload: Number(permission.can_upload) === 1,
      can_edit: Number(permission.can_edit) === 1,
      can_delete: Number(permission.can_delete) === 1,
      can_create_folders: Number(permission.can_create_folders) === 1,
    };
  }
}

function togglePermission(folderId, field) {
  const entry = adminState.permissionEntries[folderId] ?? createPermissionEntry();
  entry[field] = !entry[field];
  adminState.permissionEntries[folderId] = normalizePermissionEntry(entry, field);
}

function toggleUserPermission(folderId, field) {
  const entry = adminState.userPermissionEntries[folderId] ?? createPermissionEntry();
  entry[field] = !entry[field];
  adminState.userPermissionEntries[folderId] = normalizePermissionEntry(entry, field);
}

async function savePermissions() {
  await api('admin.permissions.save', {
    method: 'POST',
    body: {
      principal_type: 'guest',
      principal_id: 0,
      entries: adminState.permissionRows.map((row) => ({
        folder_id: row.id,
        can_view: adminState.permissionEntries[row.id]?.can_view ?? false,
        can_upload: false,
        can_edit: false,
        can_delete: false,
        can_create_folders: false,
      })),
    },
  });
  showMessage('Permissions saved.');
}

async function saveUserPermissions() {
  if (!activeAdminUser.value) {
    return;
  }

  await api('admin.permissions.save', {
    method: 'POST',
    body: {
      principal_type: 'user',
      principal_id: activeAdminUser.value.id,
      entries: adminState.userPermissionRows.map((row) => ({
        folder_id: row.id,
        can_view: adminState.userPermissionEntries[row.id]?.can_view ?? false,
        can_upload: adminState.userPermissionEntries[row.id]?.can_upload ?? false,
        can_edit: adminState.userPermissionEntries[row.id]?.can_edit ?? false,
        can_delete: adminState.userPermissionEntries[row.id]?.can_delete ?? false,
        can_create_folders: adminState.userPermissionEntries[row.id]?.can_create_folders ?? false,
      })),
    },
  });
  showMessage(`Permissions saved for ${activeAdminUser.value.username}.`);
}

async function loadAdminSection() {
  adminState.loading = true;
  try {
    if (route.section === 'dashboard') {
      const payload = await api('admin.dashboard');
      adminState.dashboard = payload;
      session.diagnostic = payload.diagnostic ?? session.diagnostic;
      session.uploadPolicy = payload.upload_policy ?? session.uploadPolicy;
      adminState.automationJobs = payload.automation?.jobs ?? [];
      return;
    }

    if (route.section === 'users') {
      await loadAdminUsers();
      if (route.userId > 0) {
        if (!adminState.users.some((user) => Number(user.id) === Number(route.userId))) {
          setAdminSection('users');
          return;
        }

        await loadUserPermissions(route.userId);
      }
      return;
    }

    if (route.section === 'permissions') {
      await loadGuestPermissions();
      return;
    }

    if (route.section === 'audit') {
      await loadAuditLogs(1);
      return;
    }

    if (route.section === 'security') {
      await loadSecurityState();
      return;
    }

    const payload = await api('admin.settings.get');
    applySettingsPayload(payload);
  } finally {
    adminState.loading = false;
  }
}

function resetNewUserForm() {
  newUserForm.username = '';
  newUserForm.password = '';
  newUserForm.role = 'user';
  newUserForm.force_password_reset = false;
}

async function createUser() {
  await api('admin.users.create', { method: 'POST', body: { ...newUserForm } });
  resetNewUserForm();
  await loadAdminUsers();
  showMessage('User created.');
}

async function saveUser(user) {
  const quotaBytes = user.role === 'user' && user.storage_quota_input !== ''
    ? Number(user.storage_quota_input)
    : null;

  if (quotaBytes !== null && (!Number.isInteger(quotaBytes) || quotaBytes < 1)) {
    showMessage('Storage quota must be a whole number of bytes or left empty for unlimited.');
    return;
  }

  await api('admin.users.update', {
    method: 'POST',
    body: {
      user_id: user.id,
      role: user.role,
      status: user.status,
      force_password_reset: user.force_password_reset,
      storage_quota_bytes: quotaBytes,
    },
  });
  await loadAdminUsers();
  if (route.userId > 0) {
    await loadUserPermissions(route.userId);
  }
  showMessage(`Saved ${user.username}.`);
}

async function resetPassword(user) {
  const password = window.prompt(`New password for ${user.username}`);
  if (!password) {
    return;
  }

  await api('admin.users.password', {
    method: 'POST',
    body: {
      user_id: user.id,
      password,
      force_password_reset: true,
    },
  });
  showMessage(`Password reset for ${user.username}.`);
}

function canEditUser(user) {
  if (!isAdmin.value) {
    return false;
  }
  if (user.is_immutable) {
    return isSuperAdmin.value;
  }
  if (!isSuperAdmin.value && user.role !== 'user') {
    return false;
  }
  return true;
}

async function saveSettings() {
  const payload = await api('admin.settings.save', {
    method: 'POST',
    body: cloneSettings(adminState.settings),
  });
  applySettingsPayload(payload);
  await refreshSession();

  if (route.section === 'security') {
    await loadSecurityState();
  }

  showMessage('Settings updated.');
}

function resetBanForm() {
  banForm.ipAddress = '';
  banForm.reason = '';
  banForm.expiresAtLocal = '';
}

async function createIpBan() {
  await api('admin.security.ban', {
    method: 'POST',
    body: {
      ip_address: banForm.ipAddress,
      reason: banForm.reason,
      expires_at: fromLocalDateTimeInput(banForm.expiresAtLocal),
    },
  });
  resetBanForm();
  await loadSecurityState();
  showMessage('IP address banned.');
}

async function unbanIp(ban) {
  if (!ban) {
    return;
  }

  if (!window.confirm(`Unban ${ban.ip_address}?`)) {
    return;
  }

  await api('admin.security.unban', {
    method: 'POST',
    body: {
      ban_id: ban.id,
    },
  });
  await loadSecurityState();
  showMessage(`Unbanned ${ban.ip_address}.`);
}

async function goToAuditPage(page) {
  const nextPage = Math.max(1, Math.min(page, adminState.auditTotalPages || 1));
  await loadAuditLogs(nextPage);
}

async function tickAutomation({ silent = false } = {}) {
  if (!isAdmin.value) {
    return;
  }

  adminState.automationBusy = true;
  try {
    const payload = await api('admin.automation.tick', {
      method: 'POST',
      body: {},
    });
    applyAutomationState(payload);
    if (adminState.dashboard) {
      adminState.dashboard.automation = { jobs: payload.jobs };
    }
    if (!silent) {
      showMessage(payload.locked ? 'Automation runner is already busy.' : 'Due checks finished.');
    }
  } finally {
    adminState.automationBusy = false;
  }
}

async function runAutomationJob(jobKey) {
  adminState.automationBusy = true;
  try {
    const payload = await api('admin.automation.run', {
      method: 'POST',
      body: { job_key: jobKey },
    });
    applyAutomationState(payload);
    if (adminState.dashboard) {
      adminState.dashboard.automation = { jobs: payload.jobs };
    }
    showMessage(payload.job?.last_message ?? 'Automation job finished.');
  } finally {
    adminState.automationBusy = false;
  }
}

async function refreshAdminSection() {
  await loadAdminSection();
  showMessage('Section refreshed.');
}

function topActionInfo() {
  if (selectedItem.value) {
    infoItem.value = selectedItem.value;
    return;
  }
  if (previewItem.value) {
    infoItem.value = previewItem.value;
    return;
  }
  showMessage('Choose a file or folder first.');
}

function handleGlobalClick() {
  closeContextMenu();
}

function onDragEnter(event) {
  if (!canUploadHere.value) {
    return;
  }
  event.preventDefault();
  dragDepth.value += 1;
}

function onDragOver(event) {
  if (!canUploadHere.value) {
    return;
  }
  event.preventDefault();
}

function onDragLeave(event) {
  if (!canUploadHere.value) {
    return;
  }
  event.preventDefault();
  dragDepth.value = Math.max(0, dragDepth.value - 1);
}

async function onDrop(event) {
  if (!canUploadHere.value) {
    return;
  }
  event.preventDefault();
  dragDepth.value = 0;
  const droppedItems = await collectDroppedItems(event.dataTransfer);

  if (!droppedItems.usedEntryApi && (event.dataTransfer?.items?.length ?? 0) > 0) {
    showMessage('Folder uploads need a compatible browser. Regular files will still upload normally.');
  }

  if (droppedItems.files.length > 0 || droppedItems.emptyDirectories.length > 0) {
    await uploadQueuedItems(droppedItems.files, droppedItems.emptyDirectories);
  }
}

function formatDateLabel(value) {
  if (!value) {
    return 'Not yet';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString();
}

function auditActorLabel(entry) {
  if (entry?.actor_username) {
    return entry.actor_username;
  }

  if (entry?.ip_address) {
    return `IP ${entry.ip_address}`;
  }

  return 'Unknown';
}

function auditTargetLabel(entry) {
  return entry?.target_label || entry?.target_type || 'No target';
}

function banStatusLabel(ban) {
  if (!ban?.revoked_at) {
    return ban?.expires_at ? 'Scheduled expiry' : 'Active';
  }

  return ban.revoked_reason === 'expired' ? 'Expired' : 'Manually lifted';
}

function banExpiryLabel(ban) {
  return ban?.expires_at ? formatDateLabel(ban.expires_at) : 'Permanently';
}

function stopBlockedTimer() {
  if (blockedTimer) {
    window.clearInterval(blockedTimer);
    blockedTimer = 0;
  }
}

function persistBlockedState() {
  if (blockedState.active) {
    window.sessionStorage.setItem(BLOCKED_STORAGE_KEY, JSON.stringify({
      source: blockedState.source,
      blocked_until: blockedState.blockedUntil,
      blocked_permanently: blockedState.blockedPermanently,
      retry_after_seconds: blockedState.retryAfterSeconds,
    }));
    return;
  }

  window.sessionStorage.removeItem(BLOCKED_STORAGE_KEY);
}

function updateBlockedCountdown() {
  if (!blockedState.active) {
    blockedCountdown.value = '';
    return;
  }

  if (blockedState.blockedPermanently) {
    blockedCountdown.value = 'Permanently';
    return;
  }

  const target = Date.parse(blockedState.blockedUntil || '');

  if (Number.isNaN(target)) {
    blockedCountdown.value = 'Temporarily';
    return;
  }

  const remaining = Math.max(0, Math.ceil((target - Date.now()) / 1000));
  const hours = Math.floor(remaining / 3600);
  const minutes = Math.floor((remaining % 3600) / 60);
  const seconds = remaining % 60;
  const pad = (value) => String(value).padStart(2, '0');
  blockedCountdown.value = hours > 0
    ? `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
    : `${pad(minutes)}:${pad(seconds)}`;

  if (remaining <= 0) {
    clearBlockedState();
  }
}

function applyBlockedState(blocked = {}) {
  blockedState.active = true;
  blockedState.source = blocked.source ?? '';
  blockedState.blockedUntil = blocked.blocked_until ?? '';
  blockedState.blockedPermanently = Boolean(blocked.blocked_permanently);
  blockedState.retryAfterSeconds = blocked.retry_after_seconds ?? null;
  updateBlockedCountdown();
  stopBlockedTimer();

  if (!blockedState.blockedPermanently && blockedState.blockedUntil) {
    blockedTimer = window.setInterval(updateBlockedCountdown, 1000);
  }

  persistBlockedState();
}

function clearBlockedState() {
  blockedState.active = false;
  blockedState.source = '';
  blockedState.blockedUntil = '';
  blockedState.blockedPermanently = false;
  blockedState.retryAfterSeconds = null;
  blockedCountdown.value = '';
  stopBlockedTimer();
  persistBlockedState();
}

function restoreBlockedState() {
  const raw = window.sessionStorage.getItem(BLOCKED_STORAGE_KEY);

  if (!raw) {
    return;
  }

  try {
    const parsed = JSON.parse(raw);

    if (parsed?.blocked_permanently) {
      applyBlockedState(parsed);
      return;
    }

    const blockedUntil = Date.parse(parsed?.blocked_until ?? '');

    if (!Number.isNaN(blockedUntil) && blockedUntil > Date.now()) {
      applyBlockedState(parsed);
      return;
    }
  } catch (_) {
  }

  window.sessionStorage.removeItem(BLOCKED_STORAGE_KEY);
}

function uploadLimitLabel(policy = session.uploadPolicy) {
  if (!policy || policy.has_app_limit === false || !Number.isFinite(policy.max_file_size_bytes)) {
    return 'No app limit';
  }

  return policy.max_file_size_label || `${policy.max_file_size_mb} MB`;
}

function startAutomationPulse() {
  stopAutomationPulse();
  if (!isAdminShell.value || !isAdmin.value) {
    return;
  }

  automationTimer = window.setInterval(() => {
    tickAutomation({ silent: true }).catch(() => {});
  }, 60000);
}

function stopAutomationPulse() {
  if (automationTimer) {
    window.clearInterval(automationTimer);
    automationTimer = 0;
  }
}

watch(searchQuery, () => {
  if (shell === 'app' || (shell === 'admin' && route.section === 'audit')) {
    debounceSearch();
  }
});

watch(() => route.section, async (section) => {
  closeMobileNav();
  if (!isAdminShell.value) {
    return;
  }
  setSearchForSection(section);
  if (!isBooting.value && isAdmin.value) {
    try {
      await loadAdminSection();
    } catch (error) {
      showMessage(error instanceof Error ? error.message : 'Unable to load this section.');
    }
  }
});

watch(() => route.folderId, async (folderId) => {
  closeMobileNav();
  if (isAdminShell.value || isBooting.value || needsLogin.value || searchActive.value) {
    return;
  }

  try {
    await loadFolder(folderId);
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to load this folder.');
  }
});

watch(() => route.userId, async (userId) => {
  closeMobileNav();
  if (shell === 'admin' && route.section === 'users' && userId > 0 && isAdmin.value && !isBooting.value) {
    try {
      await loadAdminSection();
    } catch (error) {
      showMessage(error instanceof Error ? error.message : 'Unable to load this user.');
    }
  }
});

watch(() => infoItem.value ? rowKey(infoItem.value) : '', () => {
  syncDescriptionDraft();
});

watch(() => shareContextItem.value?.id ?? 0, async (fileId) => {
  if (!fileId) {
    resetShareState();
    return;
  }

  try {
    await loadShareState(shareContextItem.value);
  } catch (error) {
    resetShareState();
    showMessage(error instanceof Error ? error.message : 'Unable to load the share link.');
  }
});

watch(mobileNavOpen, (isOpen) => {
  document.body.style.overflow = isOpen ? 'hidden' : '';
});

watch(() => session.maintenance.blocks_current_user, (blocked) => {
  if (!blocked) {
    return;
  }

  previewItem.value = null;
  infoItem.value = null;
  selectedKey.value = '';
  closeContextMenu();
  closeMobileNav();
  resetShareState();
});

watch(() => adminState.auditCategory, async () => {
  if (shell === 'admin' && route.section === 'audit' && isAdmin.value && !isBooting.value) {
    try {
      await loadAuditLogs(1);
    } catch (error) {
      showMessage(error instanceof Error ? error.message : 'Unable to filter audit logs.');
    }
  }
});

onMounted(async () => {
  window.addEventListener('hashchange', syncRouteFromHash);
  window.addEventListener('click', handleGlobalClick);
  window.addEventListener('dragenter', onDragEnter);
  window.addEventListener('dragover', onDragOver);
  window.addEventListener('dragleave', onDragLeave);
  window.addEventListener('drop', onDrop);
  restoreBlockedState();

  try {
    await refreshSession();
    syncRouteFromHash();
    startAutomationPulse();

    if (isAdminShell.value && isAdmin.value) {
      await tickAutomation({ silent: true });
    }

    await refreshCurrentView();
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Unable to initialize the app.');
  } finally {
    isBooting.value = false;
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('hashchange', syncRouteFromHash);
  window.removeEventListener('click', handleGlobalClick);
  window.removeEventListener('dragenter', onDragEnter);
  window.removeEventListener('dragover', onDragOver);
  window.removeEventListener('dragleave', onDragLeave);
  window.removeEventListener('drop', onDrop);
  document.body.style.overflow = '';
  stopAutomationPulse();
  stopBlockedTimer();
});
</script>

<template>
  <div v-if="blockedState.active" class="install-shell blocked-shell">
    <main class="install-layout">
      <section class="install-card blocked-card">
        <div class="install-header blocked-card__header">
          <p class="install-kicker">Blocked</p>
          <h1>You have been blocked</h1>
          <p class="blocked-card__status">{{ blockedCountdown || 'Calculating...' }}</p>
        </div>
      </section>
    </main>
  </div>

  <div v-else-if="session.maintenance.blocks_current_user" class="install-shell">
    <main class="install-layout">
      <section class="install-card">
        <div class="install-header">
          <p class="install-kicker">Maintenance</p>
          <h1>The file browser is temporarily unavailable</h1>
          <p class="maintenance-copy">{{ session.maintenance.message }}</p>
        </div>
        <div v-if="session.user" class="quick-actions">
          <button class="header-button" type="button" @click="logout">Logout</button>
        </div>
      </section>
    </main>
  </div>

  <div v-else class="wb-shell" :class="[`shell-${shell}`, { 'is-mobile-nav-open': mobileNavOpen }]">
    <button
      v-if="mobileNavOpen"
      class="mobile-nav-backdrop"
      type="button"
      aria-label="Close navigation"
      @click="closeMobileNav"
    />

    <aside class="wb-sidebar">
      <button class="sidebar-brand sidebar-brand--icon" type="button" @click="browseHome">
        <img :src="basePath + '/media/logo.svg'" alt="wb-filebrowser" class="brand-mark brand-mark--large">
      </button>

      <nav class="sidebar-nav">
        <button class="sidebar-link" type="button" @click="browseHome">My files</button>
        <button class="sidebar-link" type="button" :disabled="shell === 'admin' || !canCreateFoldersHere" @click="createFolder">New folder</button>
        <button class="sidebar-link" type="button" :disabled="shell === 'admin' || !canUploadHere" @click="triggerUpload">New file</button>
        <button class="sidebar-link" type="button" @click="openSettings">Settings</button>
        <button class="sidebar-link" type="button" :disabled="!session.user" @click="logout">Logout</button>
      </nav>

      <p v-if="shell === 'admin'" class="sidebar-note">
        Admin is for setup, access, and diagnostics. Open files to browse the live library.
      </p>

      <div class="sidebar-footer">
        <div class="storage-meter">
          <div class="storage-meter__label">Storage Used</div>
          <strong>{{ session.storage.used_label }}</strong>
          <span>of {{ session.storage.total_label }} used</span>
        </div>
        <div class="sidebar-meta">
          <span>v{{ session.appVersion }}</span>
          <button class="text-link" type="button" @click="helpOpen = true">Help</button>
        </div>
      </div>
    </aside>

    <main class="wb-main">
      <header class="wb-header">
        <button
          class="icon-button mobile-nav-toggle"
          type="button"
          :aria-expanded="mobileNavOpen ? 'true' : 'false'"
          @click="toggleMobileNav"
        >
          Menu
        </button>
        <div class="header-search-group">
          <label class="search-shell" :class="{ 'is-disabled': !searchConfig.enabled }">
            <span class="search-icon">Search</span>
            <input
              v-model="searchQuery"
              :disabled="!searchConfig.enabled"
              :placeholder="searchConfig.placeholder"
              type="search"
            >
          </label>
          <p v-if="shell === 'admin'" class="search-helper">{{ searchConfig.emptyText }}</p>
        </div>

        <div class="header-actions" :class="{ 'header-actions--admin': shell === 'admin' }">
          <template v-if="shell === 'admin'">
            <button class="header-button" type="button" @click="goToBrowserRoot">Open files</button>
            <button class="header-button" type="button" :disabled="adminState.loading" @click="refreshAdminSection">Refresh</button>
            <button class="header-button" type="button" :disabled="adminState.automationBusy" @click="tickAutomation()">Run due checks</button>
            <button
              v-if="route.section === 'dashboard' || route.section === 'settings'"
              class="header-button"
              type="button"
              :disabled="adminState.automationBusy"
              @click="runAutomationJob('storage_shield_check')"
            >
              Shield check
            </button>
          </template>
          <template v-else>
            <button v-if="isAdmin" class="header-button" type="button" @click="openAdminPanel">Admin</button>
            <button class="header-button" type="button" @click="toggleViewMode">{{ viewMode === 'list' ? 'Grid view' : 'List view' }}</button>
            <button class="header-button" type="button" @click="downloadSelected">Download</button>
            <button class="header-button" type="button" :disabled="!canUploadHere" @click="triggerUpload">Upload</button>
            <button class="header-button" type="button" @click="topActionInfo">Info</button>
            <button class="header-button" type="button" @click="selectCurrentItem">{{ selectMode ? 'Cancel select' : 'Select' }}</button>
          </template>
        </div>
      </header>

      <div v-if="showDiagnosticWarning" class="warning-banner">
        <strong>Storage shield warning:</strong>
        {{ session.diagnostic.message }}
      </div>
      <div v-if="statusMessage" class="status-banner">{{ statusMessage }}</div>
      <section v-if="uploadQueue" class="upload-queue-card">
        <div class="upload-queue-card__summary">
          <div>
            <p class="panel-kicker">Upload Queue</p>
            <h2>Uploading {{ uploadQueue.completedFiles }} of {{ uploadQueue.totalFiles }} files</h2>
            <p class="panel-meta">{{ formatBytes(uploadQueue.uploadedBytes) }} of {{ formatBytes(uploadQueue.totalBytes) }}</p>
          </div>
          <strong>{{ uploadQueueOverallPercent }}%</strong>
        </div>
        <div class="upload-meter" aria-hidden="true">
          <span class="upload-meter__fill" :style="{ width: `${uploadQueueOverallPercent}%` }" />
        </div>
        <div class="upload-queue-card__detail">
          <div>
            <strong>{{ uploadQueue.currentFilePath || uploadQueue.currentFileName || 'Preparing upload...' }}</strong>
            <p class="panel-meta">
              {{ formatBytes(uploadQueue.currentFileBytesSent) }} of {{ formatBytes(uploadQueue.currentFileBytesTotal) }}
              - Chunks {{ uploadQueue.currentFileChunksSent }}/{{ uploadQueue.currentFileChunksTotal }}
            </p>
          </div>
          <span>{{ uploadQueueCurrentPercent }}%</span>
        </div>
        <div class="upload-meter upload-meter--file" aria-hidden="true">
          <span class="upload-meter__fill" :style="{ width: `${uploadQueueCurrentPercent}%` }" />
        </div>
      </section>

      <section v-if="isBooting" class="empty-state">
        <h2>Loading wb-filebrowser...</h2>
      </section>

      <section v-else-if="needsLogin" class="auth-card">
        <h1>{{ shell === 'admin' ? 'Admin access required' : 'Sign in to continue' }}</h1>
        <p v-if="shell !== 'admin'">Public browsing is disabled on this board.</p>
        <form class="auth-form" @submit.prevent="submitLogin">
          <label>
            <span>Username</span>
            <input v-model="authForm.username" type="text" autocomplete="username" required>
          </label>
          <label>
            <span>Password</span>
            <input v-model="authForm.password" type="password" autocomplete="current-password" required>
          </label>
          <button type="submit">Sign in</button>
        </form>
      </section>

      <section v-else-if="accessDenied" class="auth-card">
        <h1>Access denied</h1>
        <p>This account can use the file browser, but it does not have admin rights.</p>
        <button type="button" @click="goToBrowserRoot">Open the browser</button>
      </section>

      <template v-else-if="shell === 'admin'">
        <div class="admin-tabs">
          <button :class="{ active: route.section === 'dashboard' }" type="button" @click="setAdminSection('dashboard')">Dashboard</button>
          <button :class="{ active: route.section === 'users' }" type="button" @click="setAdminSection('users')">Users</button>
          <button :class="{ active: route.section === 'permissions' }" type="button" @click="setAdminSection('permissions')">Permissions</button>
          <button :class="{ active: route.section === 'settings' }" type="button" @click="setAdminSection('settings')">Settings</button>
          <button :class="{ active: route.section === 'audit' }" type="button" @click="setAdminSection('audit')">Audit Logs</button>
          <button :class="{ active: route.section === 'security' }" type="button" @click="setAdminSection('security')">Security</button>
        </div>

        <section v-if="route.section === 'dashboard'" class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Storage Shield</p>
            <h2>{{ session.diagnostic.exposed ? 'Storage needs attention' : 'Storage shield looks healthy' }}</h2>
            <p>{{ session.diagnostic.message }}</p>
            <p class="panel-meta">Last checked: {{ formatDateLabel(session.diagnostic.checked_at) }}</p>
            <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('storage_shield_check')">Run shield check</button>
          </article>

          <article v-if="adminState.dashboard" class="panel">
            <p class="panel-kicker">Library</p>
            <h2>{{ adminState.dashboard.stats.files }} files</h2>
            <p>{{ adminState.dashboard.stats.folders }} folders across {{ adminState.dashboard.stats.users }} user accounts.</p>
            <p class="panel-meta">Public browsing: {{ adminState.dashboard.public_access ? 'Enabled' : 'Login required' }}</p>
          </article>

          <article v-if="adminState.dashboard" class="panel">
            <p class="panel-kicker">Storage</p>
            <h2>{{ adminState.dashboard.stats.used_label }}</h2>
            <p>of {{ adminState.dashboard.stats.total_label }} used.</p>
            <p class="panel-meta">Upload limit: {{ uploadLimitLabel(session.uploadPolicy) }}</p>
          </article>

          <article class="panel">
            <p class="panel-kicker">Automation</p>
            <h2>{{ dueAutomationCount }} due job{{ dueAutomationCount === 1 ? '' : 's' }}</h2>
            <p v-if="automationJobs.length === 0">Automation jobs will appear here after the first admin load.</p>
            <p v-else>{{ automationJobs[0].last_message }}</p>
            <button type="button" :disabled="adminState.automationBusy" @click="tickAutomation()">Run due checks</button>
          </article>

          <article class="panel panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Quick Actions</p>
                <h2>Admin essentials</h2>
              </div>
            </div>
            <div class="quick-actions">
              <button type="button" @click="goToBrowserRoot">Open files</button>
              <button type="button" @click="setAdminSection('permissions')">Review permissions</button>
              <button type="button" @click="setAdminSection('settings')">Open settings</button>
              <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('cleanup_abandoned_uploads')">Clean abandoned uploads</button>
              <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('refresh_folder_sizes')">Refresh folder sizes</button>
            </div>
          </article>

          <article class="panel panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Latest Automation Results</p>
                <h2>Background checks</h2>
              </div>
            </div>
            <div class="job-list">
              <div
                v-for="job in automationJobs"
                :key="job.job_key"
                class="job-card"
                :class="`job-card--${jobTone(job)}`"
              >
                <div>
                  <strong>{{ job.label }}</strong>
                  <p>{{ job.last_message }}</p>
                  <small>Last run: {{ formatDateLabel(job.last_run_at) }} · Next: {{ formatDateLabel(job.next_run_at) }}</small>
                </div>
                <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob(job.job_key)">Run now</button>
              </div>
            </div>
          </article>
        </section>

        <section v-else-if="route.section === 'users' && route.userId === 0" class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Create User</p>
            <h2>Add a new account</h2>
            <p>Roles and password reset requirements are applied immediately after creation.</p>
            <form class="auth-form compact" @submit.prevent="createUser">
              <label>
                <span>Username</span>
                <input v-model="newUserForm.username" type="text" required>
              </label>
              <label>
                <span>Password</span>
                <input v-model="newUserForm.password" type="password" minlength="12" required>
              </label>
              <label>
                <span>Role</span>
                <select v-model="newUserForm.role" :disabled="!isSuperAdmin">
                  <option value="user">User</option>
                  <option value="admin">Admin</option>
                </select>
              </label>
              <label class="checkbox-row">
                <input v-model="newUserForm.force_password_reset" type="checkbox">
                <span>Require password reset at next login</span>
              </label>
              <button type="submit">Create account</button>
            </form>
          </article>

          <article class="panel">
            <p class="panel-kicker">Overview</p>
            <h2>{{ adminState.users.length }} account{{ adminState.users.length === 1 ? '' : 's' }}</h2>
            <p>{{ filteredUsers.length }} visible in the current filter.</p>
            <p class="panel-meta">Open a user to edit quota and folder-specific rights.</p>
          </article>

          <article class="panel panel-table panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">User Management</p>
                <h2>Current accounts</h2>
              </div>
            </div>
            <table v-if="filteredUsers.length > 0">
              <thead>
                <tr>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Storage used</th>
                  <th>Last login</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="user in filteredUsers" :key="user.id">
                  <td>
                    <strong>{{ user.username }}</strong>
                    <small v-if="user.is_immutable">Immutable</small>
                  </td>
                  <td>{{ user.role }}</td>
                  <td>{{ user.status }}</td>
                  <td>{{ user.storage_used_label }} / {{ user.storage_quota_label }}</td>
                  <td>{{ user.last_login_at || 'Never' }}</td>
                  <td class="table-actions">
                    <button type="button" :disabled="!canEditUser(user)" @click="openUserDetails(user)">Open</button>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty-inline">
              <strong>No users match this search.</strong>
              <span>Clear the search box or create a new account.</span>
            </div>
          </article>
        </section>

        <section v-else-if="route.section === 'users'" class="admin-grid">
          <article v-if="activeAdminUser" class="panel panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">User Detail</p>
                <h2>{{ activeAdminUser.username }}</h2>
                <p class="panel-meta">Edit account settings, quotas, and folder-level rights for this user.</p>
              </div>
              <div class="table-actions">
                <button type="button" @click="setAdminSection('users')">Back to users</button>
                <button type="button" :disabled="!canEditUser(activeAdminUser)" @click="saveUser(activeAdminUser)">Save account</button>
                <button type="button" :disabled="!canEditUser(activeAdminUser)" @click="saveUserPermissions">Save permissions</button>
              </div>
            </div>
          </article>

          <article v-if="activeAdminUser" class="panel">
            <p class="panel-kicker">Account</p>
            <h2>Profile and access</h2>
            <label>
              <span>Role</span>
              <select v-model="activeAdminUser.role" :disabled="!canEditUser(activeAdminUser)">
                <option value="user">User</option>
                <option value="admin">Admin</option>
                <option value="super_admin">Super-Admin</option>
              </select>
            </label>
            <label>
              <span>Status</span>
              <select v-model="activeAdminUser.status" :disabled="!canEditUser(activeAdminUser)">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
              </select>
            </label>
            <label class="checkbox-row">
              <input v-model="activeAdminUser.force_password_reset" type="checkbox" :disabled="!canEditUser(activeAdminUser)">
              <span>Require password reset at next login</span>
            </label>
            <div class="quick-actions">
              <button type="button" :disabled="!canEditUser(activeAdminUser)" @click="resetPassword(activeAdminUser)">Reset password</button>
            </div>
          </article>

          <article v-if="activeAdminUser" class="panel">
            <p class="panel-kicker">Storage Quota</p>
            <h2>User storage allowance</h2>
            <p>Current usage: {{ activeAdminUser.storage_used_label }}</p>
            <label>
              <span>Quota in bytes</span>
              <input
                v-model="activeAdminUser.storage_quota_input"
                type="number"
                min="1"
                step="1"
                :disabled="!canEditUser(activeAdminUser) || activeAdminUser.role !== 'user'"
                placeholder="Leave empty for unlimited"
              >
              <small class="panel-meta">Leave empty for unlimited. Quotas apply only to standard users.</small>
            </label>
          </article>

          <article v-if="activeAdminUser" class="panel panel-table panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Folder Matrix</p>
                <h2>Permissions for {{ activeAdminUser.username }}</h2>
              </div>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Folder</th>
                  <th>View</th>
                  <th>Upload</th>
                  <th>Edit</th>
                  <th>Delete</th>
                  <th>Create folders</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredUserPermissionRows" :key="row.id">
                  <td :style="{ paddingLeft: `${1 + row.depth * 1.2}rem` }">{{ row.path }}</td>
                  <td>
                    <input
                      type="checkbox"
                      :checked="adminState.userPermissionEntries[row.id]?.can_view"
                      @change="toggleUserPermission(row.id, 'can_view')"
                    >
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      :checked="adminState.userPermissionEntries[row.id]?.can_upload"
                      @change="toggleUserPermission(row.id, 'can_upload')"
                    >
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      :checked="adminState.userPermissionEntries[row.id]?.can_edit"
                      @change="toggleUserPermission(row.id, 'can_edit')"
                    >
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      :checked="adminState.userPermissionEntries[row.id]?.can_delete"
                      @change="toggleUserPermission(row.id, 'can_delete')"
                    >
                  </td>
                  <td>
                    <input
                      type="checkbox"
                      :checked="adminState.userPermissionEntries[row.id]?.can_create_folders"
                      @change="toggleUserPermission(row.id, 'can_create_folders')"
                    >
                  </td>
                </tr>
              </tbody>
            </table>
          </article>
        </section>

        <section v-else-if="route.section === 'permissions'" class="admin-grid">
          <article class="panel panel-form">
            <p class="panel-kicker">Guest Publishing</p>
            <h2>{{ permissionPrincipalCopy.title }}</h2>
            <p>{{ permissionPrincipalCopy.body }}</p>
            <button type="button" @click="savePermissions">Save permissions</button>
          </article>

          <article class="panel">
            <p class="panel-kicker">Guest Access</p>
            <h2>Published folders only</h2>
            <p>Guests only receive browse access. User-specific upload, edit, delete, and create-folder rights are configured from each user detail page.</p>
            <p class="panel-meta">{{ filteredPermissionRows.length }} folder rows visible.</p>
          </article>

          <article class="panel panel-table panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Folder Matrix</p>
                <h2>Access by folder</h2>
              </div>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Folder</th>
                  <th>View</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredPermissionRows" :key="row.id">
                  <td :style="{ paddingLeft: `${1 + row.depth * 1.2}rem` }">{{ row.path }}</td>
                  <td>
                    <input
                      type="checkbox"
                      :checked="adminState.permissionEntries[row.id]?.can_view"
                      @change="togglePermission(row.id, 'can_view')"
                    >
                  </td>
                </tr>
              </tbody>
            </table>
          </article>
        </section>

        <section v-else-if="route.section === 'settings'" class="admin-grid">
          <article class="panel panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Admin Settings</p>
                <h2>Configuration</h2>
              </div>
              <button class="primary-button" type="button" :disabled="!adminState.canManageSettings" @click="saveSettings">Save settings</button>
            </div>
            <div class="settings-tabs">
              <button
                v-for="tab in SETTING_TABS"
                :key="tab"
                :class="{ active: adminState.settingsTab === tab }"
                type="button"
                @click="adminState.settingsTab = tab"
              >
                {{ tab.charAt(0).toUpperCase() + tab.slice(1) }}
              </button>
            </div>

            <div v-if="adminState.settingsTab === 'access'" class="settings-pane">
              <p class="panel-kicker">Access</p>
              <h2>Published browsing and maintenance</h2>
              <label class="checkbox-row">
                <input v-model="adminState.settings.access.public_access" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Allow published folders to be browsed without login</span>
              </label>
              <label class="checkbox-row">
                <input v-model="adminState.settings.access.maintenance_enabled" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Enable maintenance mode for non-admin users</span>
              </label>
              <label>
                <span>Maintenance scope</span>
                <select v-model="adminState.settings.access.maintenance_scope" :disabled="!adminState.canManageSettings || !adminState.settings.access.maintenance_enabled">
                  <option value="app_only">App frontend only</option>
                  <option value="app_and_share">App and share links</option>
                  <option value="all_non_admin">Everything except admin</option>
                </select>
              </label>
              <label>
                <span>Maintenance message</span>
                <textarea
                  v-model="adminState.settings.access.maintenance_message"
                  rows="4"
                  :disabled="!adminState.canManageSettings || !adminState.settings.access.maintenance_enabled"
                  placeholder="Tell users that updates or backups are currently in progress."
                />
              </label>
              <label class="checkbox-row">
                <input v-model="adminState.settings.access.share_terms_enabled" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Require guests to accept shared file terms</span>
              </label>
              <label>
                <span>Shared file terms</span>
                <textarea
                  v-model="adminState.settings.access.share_terms_message"
                  rows="5"
                  :disabled="!adminState.canManageSettings || !adminState.settings.access.share_terms_enabled"
                  placeholder="Explain the conditions guests must accept before shared files open or download."
                />
              </label>
              <p class="panel-meta">Guests only see folders you publish in the Permissions tab.</p>
            </div>

            <div v-else-if="adminState.settingsTab === 'display'" class="settings-pane">
              <p class="panel-kicker">Display</p>
              <h2>Grid thumbnails</h2>
              <label class="checkbox-row">
                <input v-model="adminState.settings.display.grid_thumbnails_enabled" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Generate image and PDF thumbnails in grid view</span>
              </label>
              <p class="panel-meta">Images load lazily, and PDF thumbnails are rendered in the browser from the first page.</p>
            </div>

            <div v-else-if="adminState.settingsTab === 'uploads'" class="settings-pane">
              <p class="panel-kicker">Uploads</p>
              <h2>Upload rules</h2>
              <label>
                <span>App upload limit (MB)</span>
                <input v-model.number="adminState.settings.uploads.max_file_size_mb" type="number" min="0" :disabled="!adminState.canManageSettings">
                <small class="panel-meta">Use 0 for no app-level cap. Uploads still stream in 2 MiB chunks.</small>
              </label>
              <label>
                <span>Allowed extensions</span>
                <textarea
                  v-model="adminState.settings.uploads.allowed_extensions"
                  rows="4"
                  :disabled="!adminState.canManageSettings"
                  placeholder="png, jpg, pdf"
                />
              </label>
              <label>
                <span>Abandoned upload retention (hours)</span>
                <input v-model.number="adminState.settings.uploads.stale_upload_ttl_hours" type="number" min="1" :disabled="!adminState.canManageSettings">
              </label>
              <p class="panel-meta">Empty extension list means any file type is accepted.</p>
            </div>

            <div v-else-if="adminState.settingsTab === 'automation'" class="settings-pane">
              <p class="panel-kicker">Automation</p>
              <h2>Background checks and cleanup</h2>
              <label class="checkbox-row">
                <input v-model="adminState.settings.automation.runner_enabled" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Enable request-driven automation runner</span>
              </label>
              <label>
                <span>Storage shield interval (minutes)</span>
                <input v-model.number="adminState.settings.automation.diagnostic_interval_minutes" type="number" min="5" :disabled="!adminState.canManageSettings">
              </label>
              <label>
                <span>Cleanup interval (minutes)</span>
                <input v-model.number="adminState.settings.automation.cleanup_interval_minutes" type="number" min="5" :disabled="!adminState.canManageSettings">
              </label>
              <label>
                <span>Storage alert threshold (%)</span>
                <input v-model.number="adminState.settings.automation.storage_alert_threshold_pct" type="number" min="50" max="99" :disabled="!adminState.canManageSettings">
              </label>
              <label>
                <span>Folder size refresh interval (minutes)</span>
                <input v-model.number="adminState.settings.automation.folder_size_interval_minutes" type="number" min="60" max="10080" :disabled="!adminState.canManageSettings">
              </label>
              <div class="job-list">
                <div
                  v-for="job in automationJobs"
                  :key="job.job_key"
                  class="job-card"
                  :class="`job-card--${jobTone(job)}`"
                >
                  <div>
                    <strong>{{ job.label }}</strong>
                    <p>{{ job.last_message }}</p>
                    <small>Next: {{ formatDateLabel(job.next_run_at) }}</small>
                  </div>
                  <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob(job.job_key)">Run now</button>
                </div>
              </div>
            </div>
          </article>
        </section>

        <section v-else-if="route.section === 'audit'" class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Audit Logs</p>
            <h2>{{ adminState.auditTotalItems }} event{{ adminState.auditTotalItems === 1 ? '' : 's' }}</h2>
            <p>Search the server-side log stream by event key, actor, IP address, or target.</p>
            <p class="panel-meta">Page {{ adminState.auditPage }} of {{ adminState.auditTotalPages }}</p>
          </article>

          <article class="panel">
            <p class="panel-kicker">Filter</p>
            <h2>Category</h2>
            <label>
              <span>Audit category</span>
              <select v-model="adminState.auditCategory">
                <option value="">All categories</option>
                <option v-for="category in adminState.auditCategories" :key="category.key" :value="category.key">
                  {{ category.label }}
                </option>
              </select>
            </label>
            <p class="panel-meta">Use the search bar above for text filters.</p>
          </article>

          <article class="panel panel-form audit-cleanup">
            <p class="panel-kicker">Audit Cleanup</p>
            <h2>Delete stored events</h2>
            <label>
              <span>Cleanup mode</span>
              <select v-model="adminState.auditCleanupMode" :disabled="adminState.auditCleanupBusy || !canRunAuditCleanup">
                <option value="older_than_days">Delete logs older than X days</option>
                <option value="keep_last_days">Keep only last X days</option>
                <option value="all">Delete all logs</option>
              </select>
            </label>
            <label v-if="auditCleanupRequiresDays">
              <span>Days</span>
              <input
                v-model.number="adminState.auditCleanupDays"
                type="number"
                min="1"
                max="3650"
                :disabled="adminState.auditCleanupBusy || !canRunAuditCleanup"
              >
            </label>
            <p class="panel-meta">Cleanup actions are immediate. Use the built-in retention setting for automatic pruning.</p>
            <button
              class="danger"
              type="button"
              :disabled="adminState.auditCleanupBusy || !canRunAuditCleanup"
              @click="runAuditCleanup"
            >
              Run cleanup
            </button>
          </article>

          <article class="panel panel-table panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Event Stream</p>
                <h2>Recorded activity</h2>
              </div>
              <div class="table-actions">
                <button type="button" :disabled="adminState.auditPage <= 1 || adminState.loading" @click="goToAuditPage(adminState.auditPage - 1)">Previous</button>
                <button type="button" :disabled="adminState.auditPage >= adminState.auditTotalPages || adminState.loading" @click="goToAuditPage(adminState.auditPage + 1)">Next</button>
              </div>
            </div>
            <table v-if="adminState.auditLogs.length > 0">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Event</th>
                  <th>Category</th>
                  <th>User / IP</th>
                  <th>Target</th>
                  <th>Summary</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in adminState.auditLogs" :key="entry.id">
                  <td>{{ formatDateLabel(entry.created_at) }}</td>
                  <td><code>{{ entry.event_type }}</code></td>
                  <td>{{ entry.category_label }}</td>
                  <td>{{ auditActorLabel(entry) }}</td>
                  <td>{{ auditTargetLabel(entry) }}</td>
                  <td>{{ entry.summary }}</td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty-inline">
              <strong>No audit events match this filter.</strong>
              <span>Change the category or search query to widen the result set.</span>
            </div>
          </article>
        </section>

        <section v-else class="admin-grid">
          <article class="panel panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Security</p>
                <h2>Audit logging and protection</h2>
              </div>
              <button class="primary-button" type="button" :disabled="!adminState.canManageSettings" @click="saveSettings">Save security settings</button>
            </div>
            <div class="settings-pane">
              <label class="checkbox-row">
                <input v-model="adminState.settings.security.audit_enabled" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Enable audit logging</span>
              </label>
              <label>
                <span>Audit retention (days)</span>
                <input v-model.number="adminState.settings.security.audit_retention_days" type="number" min="1" max="3650" :disabled="!adminState.canManageSettings">
              </label>
              <div class="security-toggle-grid">
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_auth_success" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>Auth successes</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_auth_failure" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>Auth failures</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_file_views" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>File views</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_file_downloads" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>File downloads</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_file_uploads" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>File uploads</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_file_management" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>File management</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_deletions" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>Deletions</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_admin_actions" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>Admin actions</span>
                </label>
                <label class="checkbox-row">
                  <input v-model="adminState.settings.security.log_security_actions" type="checkbox" :disabled="!adminState.canManageSettings">
                  <span>Security actions</span>
                </label>
              </div>
              <p class="panel-meta">Category switches only apply when the audit master switch is enabled.</p>
            </div>
          </article>

          <article class="panel">
            <p class="panel-kicker">Storage Shield</p>
            <h2>{{ session.diagnostic.exposed ? 'Storage is exposed' : 'Storage shield looks healthy' }}</h2>
            <p>{{ session.diagnostic.message }}</p>
            <p class="panel-meta">Last checked: {{ formatDateLabel(session.diagnostic.checked_at) }}</p>
            <div class="quick-actions">
              <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('storage_shield_check')">Run shield check</button>
              <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('storage_usage_alert')">Check storage alert</button>
            </div>
          </article>

          <article class="panel">
            <p class="panel-kicker">Upload Policy</p>
            <h2>{{ uploadLimitLabel(session.uploadPolicy) }}</h2>
            <p>{{ session.uploadPolicy.allowed_extensions_label }}</p>
            <p class="panel-meta">Abandoned uploads are cleared after {{ session.uploadPolicy.stale_upload_ttl_hours }} hours.</p>
          </article>

          <article class="panel panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">IP Banning</p>
                <h2>Block an address</h2>
              </div>
            </div>
            <form class="auth-form compact security-ban-form" @submit.prevent="createIpBan">
              <label>
                <span>IP address</span>
                <input v-model="banForm.ipAddress" type="text" required placeholder="203.0.113.42">
              </label>
              <label>
                <span>Reason</span>
                <input v-model="banForm.reason" type="text" required placeholder="Repeated abuse or hostile traffic">
              </label>
              <label>
                <span>Expires at</span>
                <input v-model="banForm.expiresAtLocal" type="datetime-local">
                <small class="panel-meta">Leave empty for a permanent ban.</small>
              </label>
              <button type="submit" :disabled="!adminState.canManageSettings">Ban IP</button>
            </form>
          </article>

          <article class="panel panel-table panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Active Bans</p>
                <h2>Blocked IP addresses</h2>
              </div>
            </div>
            <table v-if="adminState.activeBans.length > 0">
              <thead>
                <tr>
                  <th>IP</th>
                  <th>Reason</th>
                  <th>Created</th>
                  <th>Expires</th>
                  <th>By</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="ban in adminState.activeBans" :key="ban.id">
                  <td><strong>{{ ban.ip_address }}</strong></td>
                  <td>{{ ban.reason }}</td>
                  <td>{{ formatDateLabel(ban.created_at) }}</td>
                  <td>{{ banExpiryLabel(ban) }}</td>
                  <td>{{ ban.created_by_username || 'Unknown' }}</td>
                  <td class="table-actions">
                    <button type="button" :disabled="!adminState.canManageSettings" @click="unbanIp(ban)">Unban</button>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty-inline">
              <strong>No active IP bans.</strong>
              <span>The security surface will show new bans here immediately.</span>
            </div>
          </article>

          <article class="panel panel-table panel-wide">
            <div class="panel-header">
              <div>
                <p class="panel-kicker">Ban History</p>
                <h2>Unbanned and expired entries</h2>
              </div>
            </div>
            <table v-if="adminState.banHistory.length > 0">
              <thead>
                <tr>
                  <th>IP</th>
                  <th>Status</th>
                  <th>Reason</th>
                  <th>Created</th>
                  <th>Ended</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="ban in adminState.banHistory" :key="ban.id">
                  <td><strong>{{ ban.ip_address }}</strong></td>
                  <td>{{ banStatusLabel(ban) }}</td>
                  <td>{{ ban.reason }}</td>
                  <td>{{ formatDateLabel(ban.created_at) }}</td>
                  <td>{{ formatDateLabel(ban.revoked_at) }}</td>
                  <td>{{ ban.revoked_by_username || 'System' }}</td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty-inline">
              <strong>No historic IP bans.</strong>
              <span>Manual unbans and automatic expiries will appear here.</span>
            </div>
          </article>
        </section>
      </template>

      <template v-else>
        <div class="breadcrumb-bar">
          <button class="crumb-home" type="button" @click="browseHome">Home</button>
          <template v-for="crumb in breadcrumbItems" :key="crumb.id">
            <span class="crumb-separator">/</span>
            <button class="crumb-link" type="button" @click="crumb.id > 0 && navigateToFolder(crumb.id)">{{ crumb.name }}</button>
          </template>
        </div>



        <section v-if="viewMode === 'grid'" class="grid-board browser-pane" @contextmenu="handleWorkspaceContextMenu">
          <button
            v-for="item in currentEntries"
            :key="rowKey(item)"
            class="grid-card"
            :class="{ selected: selectedKey === rowKey(item) }"
            data-entry-surface="true"
            @click="handleEntryClick(item)"
            @contextmenu="handleContextMenu($event, item)"
          >
            <div v-thumb-observe="() => ensurePdfThumbnail(item)" class="grid-card__media">
              <img
                v-if="thumbnailImageUrl(item)"
                class="grid-card__thumb"
                :src="thumbnailImageUrl(item)"
                :alt="item.name"
                loading="lazy"
                @error="markThumbnailError(item)"
              >
              <div v-else class="grid-card__icon">{{ item.type === 'folder' ? '📁' : '📄' }}</div>
            </div>
            <strong>{{ item.name }}</strong>
            <span>{{ item.size_label }}</span>
            <small>{{ item.updated_relative }}</small>
          </button>
        </section>

        <section v-else class="table-wrap browser-pane" @contextmenu="handleWorkspaceContextMenu">
          <table class="file-table">
            <thead>
              <tr>
                <th><button class="table-sort" type="button" @click="toggleSort('name')">Name {{ sortBy === 'name' ? (sortDirection === 'asc' ? '↑' : '↓') : '' }}</button></th>
                <th><button class="table-sort" type="button" @click="toggleSort('size')">Size {{ sortBy === 'size' ? (sortDirection === 'asc' ? '↑' : '↓') : '' }}</button></th>
                <th><button class="table-sort" type="button" @click="toggleSort('updated_at')">Last modified {{ sortBy === 'updated_at' ? (sortDirection === 'asc' ? '↑' : '↓') : '' }}</button></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in currentEntries"
                :key="rowKey(item)"
                :class="{ selected: selectedKey === rowKey(item) }"
                data-entry-surface="true"
                @click="handleEntryClick(item)"
                @contextmenu="handleContextMenu($event, item)"
              >
                <td class="name-cell"><span class="row-icon">{{ item.type === 'folder' ? '📁' : '📄' }}</span><span>{{ item.name }}</span></td>
                <td>{{ item.size_label }}</td>
                <td>{{ item.updated_relative }}</td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>
    </main>

    <div v-if="previewItem" class="modal-scrim" @click.self="closePreview">
      <section class="preview-modal">
        <header class="preview-modal__header">
          <div>
            <h2>{{ previewItem.name }}</h2>
            <p>{{ previewItem.mime_type }}</p>
          </div>
          <div class="header-actions">
            <button v-if="canManageShares && previewItem.type === 'file'" class="header-button" type="button" @click="createShareLink(previewItem)">Share link</button>
            <button v-if="canManageShares && previewItem.type === 'file'" class="header-button" type="button" @click="openShareLink(previewItem)">Open share</button>
            <button class="header-button" type="button" @click="downloadSelected">Download</button>
            <button class="header-button" type="button" @click="closePreview">Close</button>
          </div>
        </header>
        <div class="preview-modal__body">
          <div class="preview-frame">
            <img v-if="previewMode(previewItem) === 'image'" class="preview-frame__image" :src="previewItem.preview_url" :alt="previewItem.name">
            <iframe v-else-if="previewMode(previewItem) === 'pdf'" :src="previewItem.preview_url" title="PDF preview"></iframe>
            <video v-else-if="previewMode(previewItem) === 'video'" :src="previewItem.preview_url" controls></video>
            <audio v-else-if="previewMode(previewItem) === 'audio'" :src="previewItem.preview_url" controls></audio>
            <pre v-else-if="previewMode(previewItem) === 'text'">{{ previewText }}</pre>
            <div v-else class="file-fallback">
              <img class="file-fallback__icon" :src="fallbackIconUrl(previewItem)" alt="">
              <span class="file-fallback__badge">{{ fallbackBadge(previewItem) }}</span>
              <strong>{{ fallbackLabel(previewItem) }}</strong>
              <p>Browser preview is unavailable for this format. Use the secure download action to open it locally.</p>
              <button class="header-button primary-button" type="button" @click="downloadSelected">Download file</button>
            </div>
          </div>
          <aside class="preview-sidebar">
            <dl>
              <div><dt>Name</dt><dd>{{ previewItem.name }}</dd></div>
              <div><dt>Size</dt><dd>{{ previewItem.size_label }}</dd></div>
              <div><dt>Updated</dt><dd>{{ previewItem.updated_relative }}</dd></div>
              <div><dt>Checksum</dt><dd>{{ previewItem.checksum }}</dd></div>
            </dl>
            <div v-if="canManageShares && previewItem.type === 'file'" class="share-panel">
              <strong>Public share</strong>
              <p v-if="shareState.fileId === previewItem.id && shareState.link" class="share-panel__url">{{ shareState.link.url }}</p>
              <p v-else>No public share link is active for this file yet.</p>
              <p class="share-panel__hint">
                {{ shareState.fileId === previewItem.id && shareState.link?.requires_password ? 'Password protected' : 'No password required' }}
              </p>
              <label>
                <span>Expires at</span>
                <input v-model="shareForm.expiresAtLocal" class="share-panel__input" type="datetime-local">
              </label>
              <label>
                <span>Max page opens</span>
                <input v-model="shareForm.maxViews" class="share-panel__input" type="number" min="1" step="1" placeholder="Unlimited">
              </label>
              <label>
                <span>Share password</span>
                <input
                  v-model="shareForm.password"
                  class="share-panel__input"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Leave blank to keep the current password"
                >
              </label>
              <small class="panel-meta">
                <template v-if="shareState.fileId === previewItem.id && shareState.link">
                  Views: {{ shareState.link.view_count }}<span v-if="shareState.link.remaining_views !== null"> · Remaining: {{ shareState.link.remaining_views }}</span>
                </template>
              </small>
              <button
                v-if="shareState.fileId === previewItem.id && shareState.link?.requires_password"
                type="button"
                @click="removeSharePassword(previewItem)"
              >
                Remove password
              </button>
            </div>
          </aside>
        </div>
      </section>
    </div>

    <div v-if="helpOpen" class="modal-scrim" @click.self="helpOpen = false">
      <section class="help-modal">
        <h2>{{ session.help.title }}</h2>
        <p>{{ session.help.body }}</p>
        <p>Files are always delivered through PHP after a session and permission check. Upload rules are checked before chunked uploads begin.</p>
        <button type="button" @click="helpOpen = false">Close</button>
      </section>
    </div>

    <aside v-if="infoItem" class="info-drawer">
      <header>
        <h2>Info</h2>
        <button class="header-button" type="button" @click="infoItem = null">Close</button>
      </header>
      <dl>
        <div><dt>Name</dt><dd>{{ infoItem.name }}</dd></div>
        <div><dt>Type</dt><dd>{{ infoItem.type }}</dd></div>
        <div><dt>Size</dt><dd>{{ infoItem.size_label }}</dd></div>
        <div><dt>Last modified</dt><dd>{{ infoItem.updated_relative }}</dd></div>
      </dl>
      <div class="note-panel">
        <strong>Description</strong>
        <textarea
          v-model="descriptionDraft"
          class="share-panel__input note-panel__input"
          rows="5"
          :readonly="!canEditDescription"
          :disabled="descriptionSaving"
          placeholder="Add context, a short note, or tags for this item."
        />
        <small class="panel-meta">{{ descriptionDraft.length }}/1000 characters</small>
        <button
          v-if="canEditDescription"
          type="button"
          :disabled="descriptionSaving || !descriptionDirty || descriptionTooLong"
          @click="saveDescription"
        >
          {{ descriptionSaving ? 'Saving...' : 'Save description' }}
        </button>
      </div>
      <div v-if="canManageShares && infoItem.type === 'file'" class="share-panel">
        <strong>Public share</strong>
        <p v-if="shareState.loading && shareState.fileId === infoItem.id">Checking share link...</p>
        <p v-else-if="shareState.fileId === infoItem.id && shareState.link" class="share-panel__url">{{ shareState.link.url }}</p>
        <p v-else>No public share link is active for this file yet.</p>
        <p class="share-panel__hint">
          {{ shareState.fileId === infoItem.id && shareState.link?.requires_password ? 'Password protected' : 'No password required' }}
        </p>
        <label>
          <span>Expires at</span>
          <input v-model="shareForm.expiresAtLocal" class="share-panel__input" type="datetime-local">
        </label>
        <label>
          <span>Max page opens</span>
          <input v-model="shareForm.maxViews" class="share-panel__input" type="number" min="1" step="1" placeholder="Unlimited">
        </label>
        <label>
          <span>Share password</span>
          <input
            v-model="shareForm.password"
            class="share-panel__input"
            type="password"
            autocomplete="new-password"
            placeholder="Leave blank to keep the current password"
          >
        </label>
        <small class="panel-meta">
          <template v-if="shareState.fileId === infoItem.id && shareState.link">
            Views: {{ shareState.link.view_count }}<span v-if="shareState.link.remaining_views !== null"> · Remaining: {{ shareState.link.remaining_views }}</span>
          </template>
        </small>
        <button
          v-if="shareState.fileId === infoItem.id && shareState.link?.requires_password"
          type="button"
          @click="removeSharePassword(infoItem)"
        >
          Remove password
        </button>
      </div>
      <div v-if="shell === 'app' && canShowItemActions(infoItem)" class="drawer-actions">
        <button v-if="canManageShares && infoItem.type === 'file'" type="button" @click="createShareLink(infoItem)">Share link</button>
        <button v-if="canManageShares && infoItem.type === 'file'" type="button" @click="openShareLink(infoItem)">Open share</button>
        <button v-if="canManageShares && infoItem.type === 'file' && shareState.fileId === infoItem.id && shareState.link" type="button" @click="revokeShareLink(infoItem)">Disable share</button>
        <button v-if="canEditItem(infoItem)" type="button" @click="renameSelected(infoItem)">Rename</button>
        <button v-if="canEditItem(infoItem)" type="button" @click="moveSelected(infoItem)">Move</button>
        <button v-if="canDeleteItem(infoItem)" type="button" class="danger" @click="deleteSelected(infoItem)">Delete</button>
      </div>
    </aside>

    <div v-if="contextMenu" class="context-menu" :style="{ left: `${contextMenu.x}px`, top: `${contextMenu.y}px` }">
      <template v-if="contextMenu.kind === 'item'">
        <button v-if="canManageShares && contextMenu.item.type === 'file'" type="button" @click="createShareLink(contextMenu.item)">Share link</button>
        <button v-if="canEditItem(contextMenu.item)" type="button" @click="renameSelected(contextMenu.item)">Rename</button>
        <button v-if="canEditItem(contextMenu.item)" type="button" @click="moveSelected(contextMenu.item)">Move</button>
        <button v-if="canDeleteItem(contextMenu.item)" type="button" class="danger" @click="deleteSelected(contextMenu.item)">Delete</button>
      </template>
      <template v-else>
        <button type="button" :disabled="!canUploadHere" @click="triggerUpload">Upload</button>
        <button type="button" :disabled="!canCreateFoldersHere" @click="createFolder">New folder</button>
        <button type="button" @click="toggleViewMode">{{ viewMode === 'list' ? 'Grid view' : 'List view' }}</button>
        <button type="button" @click="refreshBrowserSection">Refresh</button>
      </template>
    </div>

    <input ref="fileInput" type="file" hidden multiple :accept="uploadAccept || undefined" @change="handleFilePicker">

    <div v-if="dragDepth > 0 && canUploadHere" class="drop-overlay">
      <div class="drop-overlay__card">
        <strong>Drop files to upload</strong>
        <span>Chunks are streamed in 2 MiB pieces.</span>
      </div>
    </div>
  </div>
</template>
