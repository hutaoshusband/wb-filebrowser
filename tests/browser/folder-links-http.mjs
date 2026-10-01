import assert from 'node:assert/strict';

const base = process.env.WB_FOLDER_TEST_URL || 'http://127.0.0.1:8093';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Local fixture only.');
function client() {
  let cookie = '', csrf = '';
  async function send(path, data, raw = false) {
    const form = data instanceof FormData;
    const r = await fetch(base + path, { headers: { Cookie: cookie, 'X-CSRF-Token': csrf, ...(!form && data && { 'Content-Type': 'application/json' }) }, method: data ? 'POST' : 'GET', body: data ? (form ? data : JSON.stringify(data)) : undefined });
    if (r.headers.get('set-cookie')) cookie = r.headers.get('set-cookie').split(';')[0];
    const body = raw ? await r.text() : await r.json();
    if (body.csrf_token) csrf = body.csrf_token;
    return { status: r.status, body };
  }
  return { send, api: (action, data) => send('/api/index.php?action=' + action, data) };
}
const admin = client();
await admin.api('auth.session');
assert.equal((await admin.api('auth.login', { username: 'browseradmin', password: 'BrowserTestPassword123!' })).status, 200);
assert.equal((await admin.api('admin.settings.save', { spaces: { enabled: true }, uploads: { encryption_mode: 'optional' } })).status, 200);
const suffix = Date.now();
const username = `folderowner${suffix}`;
assert.equal((await admin.api('admin.users.create', { username, password: 'BrowserTestPassword123!', role: 'user', space_enabled: true })).status, 201);
const owner = client();
await owner.api('auth.session');
assert.equal((await owner.api('auth.login', { username, password: 'BrowserTestPassword123!' })).status, 200);
const session = (await owner.api('auth.session')).body;
const folderId = session.home_folder_id;
assert.ok(folderId);
const permissions = await owner.api(`space.permissions.get&folder_id=${folderId}`);
assert.equal(permissions.status, 200);
assert.equal('users' in permissions.body, false, 'Recipient directory must not be exposed');
const created = await owner.api('folders.share.create', { folder_id: folderId, access_level: 'write' });
assert.equal(created.status, 201, JSON.stringify(created.body));
const link = created.body.share;
const guest = client();
const page = await guest.send(`/share/folder.php?token=${link.token}`, undefined, true);
assert.equal(page.status, 200);
assert.match(page.body, /id="app"/);
assert.match(page.body, /assets\/app.js/);
// Obtain CSRF from the ordinary session endpoint, as a guest.
await guest.api('auth.session');
const shareSession = await guest.send(`/share/folder-api.php?token=${link.token}&action=auth.session`);
assert.equal(shareSession.body.home_folder_id, folderId);
assert.equal(shareSession.body.user, null);
const tree = await guest.send(`/share/folder-api.php?token=${link.token}&action=tree.list&folder_id=${folderId}`);
assert.equal(tree.body.data.folder.id, folderId);
assert.equal(tree.body.data.can_upload, true);
assert.equal(tree.body.data.folder.can_share, false);
const path = `/share/folder-api.php?token=${link.token}&action=`;
const sub = await guest.send(path + 'folders.create', { parent_id: folderId, name: 'Shared documents' });
assert.equal(sub.status, 201, JSON.stringify(sub.body));
const init = await guest.send(path + 'upload.init', { folder_id: sub.body.folder.id, original_name: 'Welcome.txt', size: Buffer.byteLength('Folder links work!\n'), total_chunks: 1, mime_type: 'text/plain' });
assert.equal(init.status, 200, JSON.stringify(init.body));
const form = new FormData();
form.append('upload_token', init.body.data.upload_token); form.append('chunk_index', '0');
form.append('chunk', new Blob(['Folder links work!\n']), 'chunk');
assert.equal((await guest.send(path + 'upload.chunk', form)).status, 200);
const complete = await guest.send(path + 'upload.complete', { upload_token: init.body.data.upload_token });
assert.equal(complete.status, 201, JSON.stringify(complete.body));
const file = complete.body.file;
assert.equal((await guest.send(path + `files.stream&id=${file.id}`, undefined, true)).body, 'Folder links work!\n');
assert.equal((await guest.send(path + 'folders.create', { parent_id: 1, name: 'Escape' })).status, 403);
assert.equal((await owner.api('folders.share.create', { folder_id: folderId, access_level: 'view' })).status, 201);
assert.equal((await guest.send(path + 'folders.create', { parent_id: folderId, name: 'Forbidden' })).status, 403);
assert.equal((await guest.send(path + `files.stream&id=${file.id}`, undefined, true)).body, 'Folder links work!\n');
console.log(JSON.stringify({ result: 'PASS: standard-user API, private recipients, guest upload/download, subtree boundary, view-only denial', username, folderId, url: base + `/share/folder.php?token=${link.token}`, admin: base + '/admin/#/settings' }));
