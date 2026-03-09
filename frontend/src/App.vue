<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { describePermissionPrincipal, filterPermissionRows, filterUsers, getSearchConfig, jobTone } from './lib/admin.js';
import { validateUploadCandidate } from './lib/uploadPolicy.js';

const ADMIN_SECTIONS = ['dashboard', 'users', 'permissions', 'settings'];
const SETTING_TABS = ['access', 'uploads', 'automation', 'security'];

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

function createDefaultSettings() {
  return {
    access: {
      public_access: false,
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
    },
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
});

const authForm = reactive({ username: '', password: '' });
const newUserForm = reactive({ username: '', password: '', role: 'user', force_password_reset: false });

const searchQuery = ref('');
const sortBy = ref('name');
const sortDirection = ref('asc');
const viewMode = ref(window.localStorage.getItem('wb-filebrowser:view-mode') ?? 'list');
const selectMode = ref(false);
const selectedKey = ref('');
const previewItem = ref(null);
const previewText = ref('');
const infoItem = ref(null);
const helpOpen = ref(false);
const contextMenu = ref(null);
const statusMessage = ref('');
const uploadProgress = ref(null);
const dragDepth = ref(0);
const isBooting = ref(true);
const fileInput = ref(null);

let searchTimer = 0;
let automationTimer = 0;

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
const breadcrumbItems = computed(() => searchActive.value
  ? [{ id: session.rootFolderId, name: 'Home' }, { id: -1, name: 'Search results' }]
  : folderState.breadcrumbs);
const filteredUsers = computed(() => filterUsers(adminState.users, searchQuery.value, route.section));
const filteredPermissionRows = computed(() => route.section === 'permissions'
  ? filterPermissionRows(adminState.permissionRows, searchQuery.value)
  : adminState.permissionRows);
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
  const payload = await api('auth.session');
  session.user = payload.user ?? null;
  session.publicAccess = Boolean(payload.public_access);
  session.rootFolderId = payload.root_folder_id ?? 1;
  session.appVersion = payload.app_version ?? session.appVersion;
  session.storage = payload.storage ?? session.storage;
  session.diagnostic = payload.diagnostic ?? session.diagnostic;
  session.help = payload.help ?? session.help;
  session.uploadPolicy = payload.upload_policy ?? session.uploadPolicy;
  session.csrfToken = payload.csrf_token ?? session.csrfToken;
}

function showMessage(message) {
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
  applyAutomationState(payload.automation ?? payload);
}

async function refreshCurrentView() {
  if (isAdminShell.value) {
    if (isAdmin.value) {
      await loadAdminSection();
    }
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
  const nextHash = `#/folder/${folderId}`;
  if (window.location.hash === nextHash) {
    route.folderId = folderId;
    loadFolder(folderId).catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to load this folder.'));
    return;
  }
  window.location.hash = nextHash;
}

function setAdminSection(section) {
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
  redirectTo(`${basePath}/`);
}

function openAdminPanel() {
  redirectTo(`${basePath}/admin/#/${isSuperAdmin.value ? 'dashboard' : 'users'}`);
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

function handleContextMenu(event, item) {
  if (shell !== 'app' || !canShowItemActions(item)) {
    return;
  }

  event.preventDefault();
  selectEntry(item);
  contextMenu.value = { x: event.clientX, y: event.clientY, item };
}

function previewMode(item) {
  const mime = item?.mime_type ?? '';
  const extension = item?.extension ?? '';

  if (mime.startsWith('image/')) return 'image';
  if (mime === 'application/pdf') return 'pdf';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  if (mime.startsWith('text/') || ['json', 'md', 'markdown', 'xml', 'yml', 'yaml', 'js', 'ts', 'php', 'css', 'html', 'sql'].includes(extension)) return 'text';
  return 'download';
}

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

async function submitLogin() {
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
}

async function logout() {
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
  viewMode.value = viewMode.value === 'list' ? 'grid' : 'list';
  window.localStorage.setItem('wb-filebrowser:view-mode', viewMode.value);
}

function openSettings() {
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
  if (!canUploadHere.value) {
    showMessage('You do not have upload permission in this folder.');
    return;
  }

  fileInput.value?.click();
}

async function handleFilePicker(event) {
  const files = Array.from(event.target.files ?? []);
  if (files.length > 0) {
    await uploadFiles(files);
  }
  event.target.value = '';
}

async function uploadFiles(files) {
  if (!canUploadHere.value) {
    showMessage('You do not have upload permission in this folder.');
    return;
  }

  for (const file of files) {
    const uploadError = validateUploadCandidate(file, session.uploadPolicy);

    if (uploadError) {
      showMessage(uploadError);
      return;
    }
  }

  for (const file of files) {
    const totalChunks = Math.max(1, Math.ceil(file.size / 2097152));
    uploadProgress.value = { name: file.name, sent: 0, total: totalChunks };
    const initPayload = await api('upload.init', {
      method: 'POST',
      body: {
        folder_id: route.folderId,
        original_name: file.name,
        size: file.size,
        mime_type: file.type || 'application/octet-stream',
        total_chunks: totalChunks,
      },
    });
    const token = initPayload.data.upload_token;
    const chunkSize = initPayload.data.chunk_size;

    for (let index = 0; index < totalChunks; index += 1) {
      const formData = new FormData();
      formData.append('upload_token', token);
      formData.append('chunk_index', String(index));
      formData.append('chunk', file.slice(index * chunkSize, (index + 1) * chunkSize), `${file.name}.part`);
      await api('upload.chunk', { method: 'POST', formData });
      uploadProgress.value = { name: file.name, sent: index + 1, total: totalChunks };
    }

    await api('upload.complete', { method: 'POST', body: { upload_token: token } });
  }

  uploadProgress.value = null;
  await refreshSession();
  await loadFolder(route.folderId);
  showMessage('Upload complete.');
}

async function createFolder() {
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

async function renameSelected(item = selectedItem.value) {
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
}

function shareOptionsFor(item) {
  if (!item || item.type !== 'file') {
    return { expires_at: null, max_views: null };
  }

  const source = shareForm.fileId === item.id
    ? shareForm
    : { expiresAtLocal: '', maxViews: '' };
  const maxViews = Number(source.maxViews);

  return {
    expires_at: fromLocalDateTimeInput(source.expiresAtLocal),
    max_views: Number.isInteger(maxViews) && maxViews > 0 ? maxViews : null,
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

async function revokeShareLink(item = shareContextItem.value) {
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
  showMessage('Settings updated.');
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
  const files = Array.from(event.dataTransfer?.files ?? []);
  if (files.length > 0) {
    await uploadFiles(files);
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
  if (shell === 'app') {
    debounceSearch();
  }
});

watch(() => route.section, async (section) => {
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
  if (shell === 'admin' && route.section === 'users' && userId > 0 && isAdmin.value && !isBooting.value) {
    try {
      await loadAdminSection();
    } catch (error) {
      showMessage(error instanceof Error ? error.message : 'Unable to load this user.');
    }
  }
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

onMounted(async () => {
  window.addEventListener('hashchange', syncRouteFromHash);
  window.addEventListener('click', handleGlobalClick);
  window.addEventListener('dragenter', onDragEnter);
  window.addEventListener('dragover', onDragOver);
  window.addEventListener('dragleave', onDragLeave);
  window.addEventListener('drop', onDrop);

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
  stopAutomationPulse();
});
</script>

<template>
  <div class="wb-shell" :class="`shell-${shell}`">
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
      <div v-if="uploadProgress" class="status-banner status-banner--upload">
        Uploading {{ uploadProgress.name }} ({{ uploadProgress.sent }}/{{ uploadProgress.total }})
      </div>

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

        <section v-else class="admin-grid">
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
                {{ tab === 'security' ? 'Security & Health' : tab.charAt(0).toUpperCase() + tab.slice(1) }}
              </button>
            </div>

            <div v-if="adminState.settingsTab === 'access'" class="settings-pane">
              <p class="panel-kicker">Access</p>
              <h2>Published browsing</h2>
              <label class="checkbox-row">
                <input v-model="adminState.settings.access.public_access" type="checkbox" :disabled="!adminState.canManageSettings">
                <span>Allow published folders to be browsed without login</span>
              </label>
              <p class="panel-meta">Guests only see folders you publish in the Permissions tab.</p>
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

            <div v-else class="settings-pane">
              <p class="panel-kicker">Security & Health</p>
              <h2>Current status</h2>
              <div class="health-stack">
                <div class="health-card" :class="{ 'is-warning': session.diagnostic.exposed }">
                  <strong>{{ session.diagnostic.exposed ? 'Storage is exposed' : 'Storage shield looks healthy' }}</strong>
                  <p>{{ session.diagnostic.message }}</p>
                  <small>Last checked: {{ formatDateLabel(session.diagnostic.checked_at) }}</small>
                </div>
                <div class="health-card">
                  <strong>Upload policy preview</strong>
                  <p>Limit: {{ uploadLimitLabel(session.uploadPolicy) }} | Allowed: {{ session.uploadPolicy.allowed_extensions_label }}</p>
                  <small>Abandoned uploads are cleared after {{ session.uploadPolicy.stale_upload_ttl_hours }} hours.</small>
                </div>
              </div>
              <div class="quick-actions">
                <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('storage_shield_check')">Run shield check</button>
                <button type="button" :disabled="adminState.automationBusy" @click="runAutomationJob('storage_usage_alert')">Check storage alert</button>
              </div>
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



        <section v-if="viewMode === 'grid'" class="grid-board">
          <button
            v-for="item in currentEntries"
            :key="rowKey(item)"
            class="grid-card"
            :class="{ selected: selectedKey === rowKey(item) }"
            @click="handleEntryClick(item)"
            @contextmenu="handleContextMenu($event, item)"
          >
            <div class="grid-card__icon">{{ item.type === 'folder' ? '📁' : '📄' }}</div>
            <strong>{{ item.name }}</strong>
            <span>{{ item.type === 'folder' ? '-' : item.size_label }}</span>
            <small>{{ item.updated_relative }}</small>
          </button>
        </section>

        <section v-else class="table-wrap">
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
                @click="handleEntryClick(item)"
                @contextmenu="handleContextMenu($event, item)"
              >
                <td class="name-cell"><span class="row-icon">{{ item.type === 'folder' ? '📁' : '📄' }}</span><span>{{ item.name }}</span></td>
                <td>{{ item.type === 'folder' ? '-' : item.size_label }}</td>
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
            <img v-if="previewMode(previewItem) === 'image'" :src="previewItem.preview_url" :alt="previewItem.name">
            <iframe v-else-if="previewMode(previewItem) === 'pdf'" :src="previewItem.preview_url" title="PDF preview"></iframe>
            <video v-else-if="previewMode(previewItem) === 'video'" :src="previewItem.preview_url" controls></video>
            <audio v-else-if="previewMode(previewItem) === 'audio'" :src="previewItem.preview_url" controls></audio>
            <pre v-else-if="previewMode(previewItem) === 'text'">{{ previewText }}</pre>
            <div v-else class="empty-state compact">
              <h3>Preview not available</h3>
              <p>Office files and other non-browser-native formats fall back to secure download.</p>
              <button type="button" @click="downloadSelected">Download file</button>
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
              <label>
                <span>Expires at</span>
                <input v-model="shareForm.expiresAtLocal" type="datetime-local">
              </label>
              <label>
                <span>Max page opens</span>
                <input v-model="shareForm.maxViews" type="number" min="1" step="1" placeholder="Unlimited">
              </label>
              <small class="panel-meta">
                <template v-if="shareState.fileId === previewItem.id && shareState.link">
                  Views: {{ shareState.link.view_count }}<span v-if="shareState.link.remaining_views !== null"> · Remaining: {{ shareState.link.remaining_views }}</span>
                </template>
              </small>
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
        <div><dt>Size</dt><dd>{{ infoItem.type === 'folder' ? '-' : infoItem.size_label }}</dd></div>
        <div><dt>Last modified</dt><dd>{{ infoItem.updated_relative }}</dd></div>
      </dl>
      <div v-if="canManageShares && infoItem.type === 'file'" class="share-panel">
        <strong>Public share</strong>
        <p v-if="shareState.loading && shareState.fileId === infoItem.id">Checking share link...</p>
        <p v-else-if="shareState.fileId === infoItem.id && shareState.link" class="share-panel__url">{{ shareState.link.url }}</p>
        <p v-else>No public share link is active for this file yet.</p>
        <label>
          <span>Expires at</span>
          <input v-model="shareForm.expiresAtLocal" type="datetime-local">
        </label>
        <label>
          <span>Max page opens</span>
          <input v-model="shareForm.maxViews" type="number" min="1" step="1" placeholder="Unlimited">
        </label>
        <small class="panel-meta">
          <template v-if="shareState.fileId === infoItem.id && shareState.link">
            Views: {{ shareState.link.view_count }}<span v-if="shareState.link.remaining_views !== null"> · Remaining: {{ shareState.link.remaining_views }}</span>
          </template>
        </small>
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
      <button v-if="canManageShares && contextMenu.item.type === 'file'" type="button" @click="createShareLink(contextMenu.item)">Share link</button>
      <button v-if="canEditItem(contextMenu.item)" type="button" @click="renameSelected(contextMenu.item)">Rename</button>
      <button v-if="canEditItem(contextMenu.item)" type="button" @click="moveSelected(contextMenu.item)">Move</button>
      <button v-if="canDeleteItem(contextMenu.item)" type="button" class="danger" @click="deleteSelected(contextMenu.item)">Delete</button>
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
