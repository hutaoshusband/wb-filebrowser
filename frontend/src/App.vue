<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const bootstrap = window.WB_BOOTSTRAP ?? {};
const shell = bootstrap.surface ?? document.body.dataset.shell ?? 'app';
const basePath = bootstrap.base_path ?? '';
const session = reactive({ csrfToken: bootstrap.csrf_token ?? '', user: bootstrap.user ?? null, publicAccess: false, rootFolderId: 1, appVersion: bootstrap.app_version ?? '1.0.0-alpha', storage: { used_label: '0 B', total_label: 'Unknown' }, diagnostic: { exposed: false, checked_at: '', message: '', probe_url: '' }, help: { title: 'Help', body: 'Use the admin panel to publish folders and review the storage shield diagnostic.' } });
const route = reactive({ folderId: 1, section: shell === 'admin' ? 'dashboard' : 'browse' });
const folderState = reactive({ loading: false, folder: null, breadcrumbs: [], folders: [], files: [], can_upload: false, can_manage: false });
const searchState = reactive({ folders: [], files: [] });
const adminState = reactive({ loading: false, dashboard: null, users: [], settings: { public_access: false, app_version: '1.0.0-alpha' }, canManageSettings: false, permissionRows: [], permissionEntries: {}, permissionPrincipalType: 'guest', permissionPrincipalId: 0 });
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

const isAdmin = computed(() => ['admin', 'super_admin'].includes(session.user?.role ?? ''));
const isSuperAdmin = computed(() => session.user?.role === 'super_admin');
const needsLogin = computed(() => shell === 'admin' ? session.user === null : session.user === null && !session.publicAccess);
const accessDenied = computed(() => shell === 'admin' && session.user !== null && !isAdmin.value);
const showDiagnosticWarning = computed(() => isAdmin.value && session.diagnostic.exposed);
const searchActive = computed(() => shell !== 'admin' && searchQuery.value.trim() !== '');
const currentEntries = computed(() => (searchActive.value ? [...searchState.folders, ...searchState.files] : [...folderState.folders, ...folderState.files]));
const selectedItem = computed(() => currentEntries.value.find((item) => rowKey(item) === selectedKey.value) ?? null);
const canUploadHere = computed(() => shell === 'app' && session.user !== null && folderState.can_upload);
const breadcrumbItems = computed(() => searchActive.value ? [{ id: session.rootFolderId, name: 'Home' }, { id: -1, name: 'Search results' }] : folderState.breadcrumbs);
const principalUsers = computed(() => adminState.users.filter((user) => user.role === 'user'));
const filteredUsers = computed(() => route.section !== 'users' || searchQuery.value.trim() === '' ? adminState.users : adminState.users.filter((user) => user.username.toLowerCase().includes(searchQuery.value.trim().toLowerCase())));

function apiUrl(action, params = {}) {
  const url = new URL(`${window.location.origin}${basePath}/api/index.php`);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  });
  return url.toString();
}

async function api(action, options = {}) {
  const { method = 'GET', params = {}, body = null, formData = null } = options;
  const fetchOptions = { method, credentials: 'same-origin', headers: {} };
  if (formData instanceof FormData) {
    if (!formData.has('csrf_token')) formData.append('csrf_token', session.csrfToken);
    fetchOptions.body = formData;
  } else if (body !== null) {
    fetchOptions.headers['Content-Type'] = 'application/json';
    fetchOptions.body = JSON.stringify({ ...(method !== 'GET' ? { csrf_token: session.csrfToken } : {}), ...body });
  }
  const response = await fetch(apiUrl(action, params), fetchOptions);
  const payload = await response.json();
  if (!response.ok || !payload.ok) throw new Error(payload.message ?? 'Request failed.');
  if (payload.csrf_token) session.csrfToken = payload.csrf_token;
  return payload;
}

function syncRouteFromHash() {
  if (shell === 'admin') {
    route.section = window.location.hash.replace(/^#\//, '') || 'dashboard';
    return;
  }
  const folderMatch = window.location.hash.match(/^#\/folder\/(\d+)/);
  if (folderMatch) {
    route.folderId = Number(folderMatch[1]);
    return;
  }
  route.folderId = session.rootFolderId || 1;
  if (window.location.hash === '') window.location.hash = `#/folder/${route.folderId}`;
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
  session.csrfToken = payload.csrf_token ?? session.csrfToken;
}

async function refreshCurrentView() {
  if (shell === 'admin') {
    if (isAdmin.value) await loadAdminSection();
    return;
  }
  if (needsLogin.value) {
    folderState.folders = [];
    folderState.files = [];
    folderState.breadcrumbs = [];
    return;
  }
  if (searchActive.value) await runSearch(); else await loadFolder(route.folderId);
}

async function loadFolder(folderId = route.folderId) {
  folderState.loading = true;
  try {
    const payload = await api('tree.list', { params: { folder_id: folderId, sort: sortBy.value, direction: sortDirection.value } });
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
  const payload = await api('tree.search', { params: { query: searchQuery.value.trim(), sort: sortBy.value, direction: sortDirection.value } });
  searchState.folders = payload.data.folders;
  searchState.files = payload.data.files;
  selectedKey.value = '';
}

function debounceSearch() {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(async () => {
    try {
      if (shell === 'admin') return;
      if (searchQuery.value.trim() === '') await loadFolder(route.folderId); else await runSearch();
    } catch (error) {
      showMessage(error instanceof Error ? error.message : 'Search failed.');
    }
  }, 220);
}

function rowKey(item) { return `${item.type}:${item.id}`; }
function toggleSort(column) {
  if (sortBy.value === column) sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'; else { sortBy.value = column; sortDirection.value = column === 'updated_at' ? 'desc' : 'asc'; }
  refreshCurrentView().catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to refresh the view.'));
}
function navigateToFolder(folderId) { window.location.hash = `#/folder/${folderId}`; }
function selectEntry(item) { selectedKey.value = rowKey(item); infoItem.value = item; }
function closeContextMenu() { contextMenu.value = null; }
function handleEntryClick(item) {
  closeContextMenu();
  if (selectMode.value) { selectEntry(item); return; }
  if (item.type === 'folder') { navigateToFolder(item.id); return; }
  openPreview(item).catch((error) => showMessage(error instanceof Error ? error.message : 'Unable to open the preview.'));
}

function handleContextMenu(event, item) {
  if (!folderState.can_manage || shell !== 'app') return;
  event.preventDefault();
  selectEntry(item);
  contextMenu.value = { x: event.clientX, y: event.clientY, item };
}

function showMessage(message) {
  statusMessage.value = message;
  window.clearTimeout(showMessage.timer);
  showMessage.timer = window.setTimeout(() => { statusMessage.value = ''; }, 4200);
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
  infoItem.value = item;
  previewText.value = '';
  if (previewMode(item) === 'text') {
    const response = await fetch(item.preview_url, { credentials: 'same-origin', cache: 'no-store' });
    previewText.value = await response.text();
  }
}

function closePreview() { previewItem.value = null; previewText.value = ''; }

async function submitLogin() {
  const payload = await api('auth.login', { method: 'POST', body: { username: authForm.username, password: authForm.password } });
  session.user = payload.user;
  session.csrfToken = payload.csrf_token ?? session.csrfToken;
  authForm.password = '';
  await refreshSession();
  syncRouteFromHash();
  await refreshCurrentView();
  if (shell === 'admin' && !isAdmin.value) showMessage('This account does not have access to the admin panel.');
}

async function logout() {
  await api('auth.logout', { method: 'POST', body: {} });
  session.user = null;
  searchQuery.value = '';
  previewItem.value = null;
  infoItem.value = null;
  selectedKey.value = '';
  if (shell === 'admin') { window.location.href = `${basePath}/`; return; }
  await refreshSession();
  await refreshCurrentView();
}

function toggleViewMode() { viewMode.value = viewMode.value === 'list' ? 'grid' : 'list'; window.localStorage.setItem('wb-filebrowser:view-mode', viewMode.value); }
function openSettings() { if (isAdmin.value) { window.location.href = `${basePath}/admin/#/${isSuperAdmin.value ? 'dashboard' : 'users'}`; return; } helpOpen.value = true; }
function triggerUpload() { if (!canUploadHere.value) { showMessage('You do not have upload permission in this folder.'); return; } fileInput.value?.click(); }

async function handleFilePicker(event) {
  const files = Array.from(event.target.files ?? []);
  if (files.length > 0) await uploadFiles(files);
  event.target.value = '';
}

async function uploadFiles(files) {
  if (!canUploadHere.value) { showMessage('You do not have upload permission in this folder.'); return; }
  for (const file of files) {
    const totalChunks = Math.max(1, Math.ceil(file.size / 2097152));
    uploadProgress.value = { name: file.name, sent: 0, total: totalChunks };
    const initPayload = await api('upload.init', { method: 'POST', body: { folder_id: route.folderId, original_name: file.name, size: file.size, mime_type: file.type || 'application/octet-stream', total_chunks: totalChunks } });
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
  if (!folderState.can_manage) { showMessage('Only administrators can create folders.'); return; }
  const name = window.prompt('New folder name');
  if (!name) return;
  await api('folders.create', { method: 'POST', body: { parent_id: route.folderId, name } });
  await loadFolder(route.folderId);
}

async function renameSelected(item = selectedItem.value) {
  if (!item) return;
  const name = window.prompt(`Rename ${item.type}`, item.name);
  if (!name || name === item.name) return;
  await api(item.type === 'folder' ? 'folders.rename' : 'files.rename', { method: 'POST', body: item.type === 'folder' ? { folder_id: item.id, name } : { file_id: item.id, name } });
  await refreshCurrentView();
}

async function ensureMoveTargets() {
  const payload = await api('admin.permissions.get', { params: { principal_type: 'guest', principal_id: 0 } });
  return buildFolderRows(payload.folders);
}

function buildFolderRows(folders) {
  const map = new Map(folders.map((folder) => [folder.id, { ...folder, children: [] }]));
  for (const folder of map.values()) if (folder.parent_id && map.has(folder.parent_id)) map.get(folder.parent_id).children.push(folder);
  const roots = Array.from(map.values()).filter((folder) => !folder.parent_id || !map.has(folder.parent_id)).sort((left, right) => left.name.localeCompare(right.name));
  const rows = [];
  const visit = (folder, depth, trail) => {
    const name = folder.id === session.rootFolderId ? 'Home' : folder.name;
    rows.push({ ...folder, depth, path: [...trail, name].join(' / ') });
    folder.children.sort((left, right) => left.name.localeCompare(right.name)).forEach((child) => visit(child, depth + 1, [...trail, name]));
  };
  roots.forEach((folder) => visit(folder, 0, []));
  return rows;
}

async function moveSelected(item = selectedItem.value) {
  if (!item) return;
  const folderList = await ensureMoveTargets();
  const choices = folderList.map((folder) => `${folder.id}: ${folder.path}`).join('\n');
  const destination = window.prompt(`Move "${item.name}" to folder ID:\n${choices}`, String(route.folderId));
  if (!destination) return;
  await api(item.type === 'folder' ? 'folders.move' : 'files.move', { method: 'POST', body: item.type === 'folder' ? { folder_id: item.id, target_parent_id: Number(destination) } : { file_id: item.id, target_folder_id: Number(destination) } });
  await refreshCurrentView();
}

async function deleteSelected(item = selectedItem.value) {
  if (!item) return;
  if (!window.confirm(`Delete "${item.name}"? This cannot be undone.`)) return;
  await api(item.type === 'folder' ? 'folders.delete' : 'files.delete', { method: 'POST', body: item.type === 'folder' ? { folder_id: item.id } : { file_id: item.id } });
  previewItem.value = null;
  infoItem.value = null;
  selectedKey.value = '';
  await refreshCurrentView();
}

function downloadSelected() {
  const item = selectedItem.value ?? previewItem.value;
  if (!item || item.type !== 'file') { showMessage('Choose a file first.'); return; }
  window.location.href = item.download_url;
}

function selectCurrentItem() { selectMode.value = !selectMode.value; if (!selectMode.value) selectedKey.value = ''; }
function browseHome() { if (shell === 'admin') { window.location.hash = '#/dashboard'; return; } navigateToFolder(session.rootFolderId); }
function goToBrowserRoot() { window.location.href = `${basePath}/`; }
function setAdminSection(section) { window.location.hash = `#/${section}`; }

async function loadAdminSection() {
  adminState.loading = true;
  try {
    if (route.section === 'dashboard') { adminState.dashboard = await api('admin.dashboard'); await runDiagnostic(); }
    if (route.section === 'users' || route.section === 'permissions') adminState.users = (await api('admin.users.list')).users;
    if (route.section === 'permissions') await loadPermissions();
    if (route.section === 'settings') {
      const payload = await api('admin.settings.get');
      adminState.settings = payload.settings;
      adminState.canManageSettings = payload.can_manage_settings;
      await runDiagnostic();
    }
  } finally { adminState.loading = false; }
}

async function createUser() {
  await api('admin.users.create', { method: 'POST', body: { ...newUserForm } });
  newUserForm.username = ''; newUserForm.password = ''; newUserForm.role = 'user'; newUserForm.force_password_reset = false;
  await loadAdminSection();
  showMessage('User created.');
}

async function saveUser(user) {
  await api('admin.users.update', { method: 'POST', body: { user_id: user.id, role: user.role, status: user.status, force_password_reset: user.force_password_reset } });
  showMessage(`Saved ${user.username}.`);
}

async function resetPassword(user) {
  const password = window.prompt(`New password for ${user.username}`);
  if (!password) return;
  await api('admin.users.password', { method: 'POST', body: { user_id: user.id, password, force_password_reset: true } });
  showMessage(`Password reset for ${user.username}.`);
}

function canEditUser(user) {
  if (!isAdmin.value) return false;
  if (user.is_immutable) return isSuperAdmin.value;
  if (!isSuperAdmin.value && user.role !== 'user') return false;
  return true;
}

async function loadPermissions() {
  if (adminState.permissionPrincipalType === 'user' && adminState.permissionPrincipalId === 0) adminState.permissionPrincipalId = principalUsers.value[0]?.id ?? 0;
  const payload = await api('admin.permissions.get', { params: { principal_type: adminState.permissionPrincipalType, principal_id: adminState.permissionPrincipalType === 'guest' ? 0 : adminState.permissionPrincipalId } });
  adminState.permissionRows = buildFolderRows(payload.folders);
  adminState.permissionEntries = Object.fromEntries(adminState.permissionRows.map((row) => [row.id, { can_view: false, can_upload: false }]));
  for (const permission of payload.permissions) adminState.permissionEntries[permission.folder_id] = { can_view: Number(permission.can_view) === 1, can_upload: Number(permission.can_upload) === 1 };
}

function togglePermission(folderId, field) {
  const entry = adminState.permissionEntries[folderId] ?? { can_view: false, can_upload: false };
  entry[field] = !entry[field];
  if (field === 'can_upload' && entry.can_upload) entry.can_view = true;
  adminState.permissionEntries[folderId] = entry;
}

async function savePermissions() {
  await api('admin.permissions.save', { method: 'POST', body: { principal_type: adminState.permissionPrincipalType, principal_id: adminState.permissionPrincipalType === 'guest' ? 0 : adminState.permissionPrincipalId, entries: adminState.permissionRows.map((row) => ({ folder_id: row.id, can_view: adminState.permissionEntries[row.id]?.can_view ?? false, can_upload: adminState.permissionEntries[row.id]?.can_upload ?? false })) } });
  showMessage('Permissions saved.');
}

async function saveSettings() { await api('admin.settings.save', { method: 'POST', body: { public_access: adminState.settings.public_access } }); await refreshSession(); showMessage('Settings updated.'); }

async function runDiagnostic() {
  if (!isAdmin.value || !session.diagnostic.probe_url) return;
  let exposed = false;
  try {
    exposed = (await fetch(`${session.diagnostic.probe_url}?check=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' })).ok;
  } catch { exposed = false; }
  await api('admin.diagnostic.update', { method: 'POST', body: { exposed } });
  await refreshSession();
}

function topActionInfo() { if (selectedItem.value) { infoItem.value = selectedItem.value; return; } if (previewItem.value) { infoItem.value = previewItem.value; return; } showMessage('Choose a file or folder first.'); }
function startTerminalAction() { if (isAdmin.value) { window.location.href = `${basePath}/admin/#/dashboard`; return; } helpOpen.value = true; }
function handleGlobalClick() { closeContextMenu(); }
function onDragEnter(event) { if (!canUploadHere.value) return; event.preventDefault(); dragDepth.value += 1; }
function onDragOver(event) { if (!canUploadHere.value) return; event.preventDefault(); }
function onDragLeave(event) { if (!canUploadHere.value) return; event.preventDefault(); dragDepth.value = Math.max(0, dragDepth.value - 1); }
async function onDrop(event) { if (!canUploadHere.value) return; event.preventDefault(); dragDepth.value = 0; const files = Array.from(event.dataTransfer?.files ?? []); if (files.length > 0) await uploadFiles(files); }

watch(searchQuery, debounceSearch);
watch(() => [adminState.permissionPrincipalType, adminState.permissionPrincipalId], async () => {
  if (shell === 'admin' && route.section === 'permissions' && isAdmin.value) {
    try { await loadPermissions(); } catch (error) { showMessage(error instanceof Error ? error.message : 'Unable to load permissions.'); }
  }
});

onMounted(async () => {
  window.addEventListener('hashchange', syncRouteFromHash);
  window.addEventListener('click', handleGlobalClick);
  window.addEventListener('dragenter', onDragEnter);
  window.addEventListener('dragover', onDragOver);
  window.addEventListener('dragleave', onDragLeave);
  window.addEventListener('drop', onDrop);
  try { await refreshSession(); syncRouteFromHash(); await refreshCurrentView(); } catch (error) { showMessage(error instanceof Error ? error.message : 'Unable to initialize the app.'); } finally { isBooting.value = false; }
});

onBeforeUnmount(() => {
  window.removeEventListener('hashchange', syncRouteFromHash);
  window.removeEventListener('click', handleGlobalClick);
  window.removeEventListener('dragenter', onDragEnter);
  window.removeEventListener('dragover', onDragOver);
  window.removeEventListener('dragleave', onDragLeave);
  window.removeEventListener('drop', onDrop);
});
</script>

<template>
  <div class="wb-shell" :class="`shell-${shell}`">
    <aside class="wb-sidebar">
      <div class="sidebar-brand" @click="browseHome">
        <span class="brand-mark">WB</span>
        <div>
          <strong>wb-filebrowser</strong>
          <p>Drop-in file hub</p>
        </div>
      </div>
      <nav class="sidebar-nav">
        <button class="sidebar-link" @click="browseHome">My files</button>
        <button class="sidebar-link" :disabled="shell === 'admin' || !folderState.can_manage" @click="createFolder">New folder</button>
        <button class="sidebar-link" :disabled="shell === 'admin' || !canUploadHere" @click="triggerUpload">New file</button>
        <button class="sidebar-link" @click="openSettings">Settings</button>
        <button class="sidebar-link" :disabled="!session.user" @click="logout">Logout</button>
      </nav>
      <div class="sidebar-footer">
        <div class="storage-meter">
          <div class="storage-meter__label">Storage Used</div>
          <strong>{{ session.storage.used_label }}</strong>
          <span>of {{ session.storage.total_label }} used</span>
        </div>
        <div class="sidebar-meta">
          <span>v{{ session.appVersion }}</span>
          <button class="text-link" @click="helpOpen = true">Help</button>
        </div>
      </div>
    </aside>

    <main class="wb-main">
      <header class="wb-header">
        <label class="search-shell">
          <span class="search-icon">⌕</span>
          <input v-model="searchQuery" :placeholder="shell === 'admin' ? 'Search users...' : 'Search...'" type="search">
        </label>
        <div class="header-actions">
          <button class="icon-button" title="Admin / Terminal" @click="startTerminalAction">&lt;/&gt;</button>
          <button class="icon-button" title="Grid or list view" @click="toggleViewMode">{{ viewMode === 'list' ? '▥' : '☰' }}</button>
          <button class="icon-button" title="Download" @click="downloadSelected">⇩</button>
          <button class="icon-button" :disabled="shell === 'admin' || !canUploadHere" title="Upload" @click="triggerUpload">⇧</button>
          <button class="icon-button" title="Info" @click="topActionInfo">i</button>
          <button class="icon-button" title="Select" @click="selectCurrentItem">{{ selectMode ? '×' : '✓' }}</button>
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
          <button :class="{ active: route.section === 'dashboard' }" @click="setAdminSection('dashboard')">Dashboard</button>
          <button :class="{ active: route.section === 'users' }" @click="setAdminSection('users')">Users</button>
          <button :class="{ active: route.section === 'permissions' }" @click="setAdminSection('permissions')">Permissions</button>
          <button :class="{ active: route.section === 'settings' }" @click="setAdminSection('settings')">Settings</button>
        </div>

        <section v-if="route.section === 'dashboard'" class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Diagnostic</p>
            <h2>{{ session.diagnostic.exposed ? 'Storage is exposed' : 'Storage shield looks healthy' }}</h2>
            <p>{{ session.diagnostic.message }}</p>
            <button type="button" @click="runDiagnostic">Refresh diagnostic</button>
          </article>
          <article v-if="adminState.dashboard" class="panel">
            <p class="panel-kicker">Library</p>
            <h2>{{ adminState.dashboard.stats.files }} files</h2>
            <p>{{ adminState.dashboard.stats.folders }} folders across {{ adminState.dashboard.stats.users }} user accounts.</p>
          </article>
          <article v-if="adminState.dashboard" class="panel">
            <p class="panel-kicker">Storage</p>
            <h2>{{ adminState.dashboard.stats.used_label }}</h2>
            <p>of {{ adminState.dashboard.stats.total_label }} used.</p>
          </article>
        </section>

        <section v-else-if="route.section === 'users'" class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Create user</p>
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

          <article class="panel panel-table">
            <p class="panel-kicker">User management</p>
            <table>
              <thead>
                <tr>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Status</th>
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
                  <td>
                    <select v-model="user.role" :disabled="!canEditUser(user)">
                      <option value="user">User</option>
                      <option value="admin">Admin</option>
                      <option value="super_admin">Super-Admin</option>
                    </select>
                  </td>
                  <td>
                    <select v-model="user.status" :disabled="!canEditUser(user)">
                      <option value="active">Active</option>
                      <option value="suspended">Suspended</option>
                    </select>
                  </td>
                  <td>{{ user.last_login_at || 'Never' }}</td>
                  <td class="table-actions">
                    <button type="button" :disabled="!canEditUser(user)" @click="saveUser(user)">Save</button>
                    <button type="button" :disabled="!canEditUser(user)" @click="resetPassword(user)">Reset password</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </article>
        </section>

        <section v-else-if="route.section === 'permissions'" class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Target principal</p>
            <label>
              <span>Mapping mode</span>
              <select v-model="adminState.permissionPrincipalType">
                <option value="guest">Published folders (Guests)</option>
                <option value="user">Specific user</option>
              </select>
            </label>
            <label v-if="adminState.permissionPrincipalType === 'user'">
              <span>User</span>
              <select v-model="adminState.permissionPrincipalId">
                <option v-for="user in principalUsers" :key="user.id" :value="user.id">{{ user.username }}</option>
              </select>
            </label>
            <button type="button" @click="savePermissions">Save permissions</button>
          </article>

          <article class="panel panel-table">
            <p class="panel-kicker">Folder matrix</p>
            <table>
              <thead>
                <tr>
                  <th>Folder</th>
                  <th>View</th>
                  <th>Upload</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in adminState.permissionRows" :key="row.id">
                  <td :style="{ paddingLeft: `${1 + row.depth * 1.2}rem` }">{{ row.path }}</td>
                  <td>
                    <input type="checkbox" :checked="adminState.permissionEntries[row.id]?.can_view" @change="togglePermission(row.id, 'can_view')">
                  </td>
                  <td>
                    <input type="checkbox" :checked="adminState.permissionEntries[row.id]?.can_upload" :disabled="adminState.permissionPrincipalType === 'guest'" @change="togglePermission(row.id, 'can_upload')">
                  </td>
                </tr>
              </tbody>
            </table>
          </article>
        </section>

        <section v-else class="admin-grid">
          <article class="panel">
            <p class="panel-kicker">Global settings</p>
            <label class="checkbox-row">
              <input v-model="adminState.settings.public_access" type="checkbox" :disabled="!adminState.canManageSettings">
              <span>Allow published folders to be browsed without login</span>
            </label>
            <button type="button" :disabled="!adminState.canManageSettings" @click="saveSettings">Save settings</button>
          </article>
          <article class="panel">
            <p class="panel-kicker">Hardening help</p>
            <p>If the storage probe is reachable, add an equivalent deny rule for `/storage/` in Nginx or IIS before sharing the app.</p>
            <button type="button" @click="runDiagnostic">Run diagnostic again</button>
          </article>
        </section>
      </template>

      <template v-else>
        <div class="breadcrumb-bar">
          <button class="crumb-home" type="button" @click="browseHome">⌂</button>
          <template v-for="crumb in breadcrumbItems" :key="crumb.id">
            <span class="crumb-separator">/</span>
            <button class="crumb-link" type="button" @click="crumb.id > 0 && navigateToFolder(crumb.id)">{{ crumb.name }}</button>
          </template>
        </div>

        <section v-if="viewMode === 'grid'" class="grid-board">
          <button v-for="item in currentEntries" :key="rowKey(item)" class="grid-card" :class="{ selected: selectedKey === rowKey(item) }" @click="handleEntryClick(item)" @contextmenu="handleContextMenu($event, item)">
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
              <tr v-for="item in currentEntries" :key="rowKey(item)" :class="{ selected: selectedKey === rowKey(item) }" @click="handleEntryClick(item)" @contextmenu="handleContextMenu($event, item)">
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
            <button class="icon-button" @click="downloadSelected">⇩</button>
            <button class="icon-button" @click="closePreview">×</button>
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
          </aside>
        </div>
      </section>
    </div>

    <div v-if="helpOpen" class="modal-scrim" @click.self="helpOpen = false">
      <section class="help-modal">
        <h2>{{ session.help.title }}</h2>
        <p>{{ session.help.body }}</p>
        <p>Files are always delivered through PHP after a session and permission check. The admin diagnostic catches hosts that ignore the generated `.htaccess` file.</p>
        <button type="button" @click="helpOpen = false">Close</button>
      </section>
    </div>

    <aside v-if="infoItem" class="info-drawer">
      <header>
        <h2>Info</h2>
        <button class="icon-button" @click="infoItem = null">×</button>
      </header>
      <dl>
        <div><dt>Name</dt><dd>{{ infoItem.name }}</dd></div>
        <div><dt>Type</dt><dd>{{ infoItem.type }}</dd></div>
        <div><dt>Size</dt><dd>{{ infoItem.type === 'folder' ? '-' : infoItem.size_label }}</dd></div>
        <div><dt>Last modified</dt><dd>{{ infoItem.updated_relative }}</dd></div>
      </dl>
      <div v-if="shell === 'app' && folderState.can_manage" class="drawer-actions">
        <button type="button" @click="renameSelected(infoItem)">Rename</button>
        <button type="button" @click="moveSelected(infoItem)">Move</button>
        <button type="button" class="danger" @click="deleteSelected(infoItem)">Delete</button>
      </div>
    </aside>

    <div v-if="contextMenu" class="context-menu" :style="{ left: `${contextMenu.x}px`, top: `${contextMenu.y}px` }">
      <button type="button" @click="renameSelected(contextMenu.item)">Rename</button>
      <button type="button" @click="moveSelected(contextMenu.item)">Move</button>
      <button type="button" class="danger" @click="deleteSelected(contextMenu.item)">Delete</button>
    </div>

    <input ref="fileInput" type="file" hidden multiple @change="handleFilePicker">

    <div v-if="dragDepth > 0 && canUploadHere" class="drop-overlay">
      <div class="drop-overlay__card">
        <strong>Drop files to upload</strong>
        <span>Chunks are streamed in 2 MiB pieces.</span>
      </div>
    </div>
  </div>
</template>
