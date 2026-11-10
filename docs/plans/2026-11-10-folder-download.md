# Folder download implementation plan

Goal: Download the current folder and its accessible descendants as a ZIP, preserving folders, including empty folders.

Architecture: Native ZipArchive with STORE entries; no compression or file contents in PHP memory. Private temporary archive, removed on failure or request shutdown. Reuse Permissions scopes and blob resolution, preserve encrypted bytes. Limit concurrent archive creation without locking uploads or browsing.

1. Add PHP tests for recursive ZIP contents, stored bytes, permissions, share boundaries and unsafe entry names. Run with `php tools/phpunit.phar --filter FolderArchiveTest` and confirm failure.
2. Implement `app/FolderArchive.php`, register in bootstraps, and connect `folders.download` in both API surfaces. Reuse IP bans, maintenance checks and folder-share authentication.
3. Replace the upper single-file download action with Download folder (ZIP), targeting the loaded folder independently of selection or search.
4. Run PHP tests, frontend tests and Vite build; inspect the final diff.
