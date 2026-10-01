# wb-filebrowser

A web-based file browser built with PHP and Vue.

## Requirements

- PHP 8.1+ with `fileinfo`, `mbstring`, and at least one supported PDO driver: `pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql`
- Composer
- Node.js 18+ and npm
- A web server (Apache, Nginx, etc.)
- For MySQL/PostgreSQL installs: an already-created, dedicated database the installer can connect to

## Quick Start

```bash
git clone https://github.com/hutaoshusband/wb-filebrowser.git
cd wb-filebrowser
chmod +x install.sh
sudo ./install.sh
```

The script handles everything: installs composer if missing, installs node via nvm if missing, pulls PHP and JS dependencies, builds the frontend, creates the storage directories, and sets permissions.

Once it finishes, point your web server at the project directory and open `/install/` in a browser to create your admin account and choose either SQLite or an external MySQL/PostgreSQL database.

## Manual Setup

If you prefer to do it yourself:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
mkdir -p storage/{uploads,chunks,sessions,logs,probe}
chmod -R 775 storage/
sudo chown -R www-data:www-data storage/
```

Then open `http://your-domain/install/` to finish. SQLite defaults to `storage/app.sqlite`; MySQL/PostgreSQL targets must already exist before you submit the installer.

## Testing

Run the existing automated suites with:

```bash
php tools/phpunit.phar --configuration phpunit.xml
npm run test:frontend
```

Optional MySQL/PostgreSQL smoke tests are skipped by default. To enable them, set DSN credentials such as `WB_TEST_MYSQL_DSN` / `WB_TEST_PGSQL_DSN` before running PHPUnit.

## Client-Side Video Optimization

Uploads can be transcoded to a normalized, policy-compliant MP4 (H.264 video, AAC audio, no extra tracks or metadata) **in the uploader's browser** before anything is sent to the server. The server never transcodes; its only job is verification. Admin → Settings → Uploads → *Video optimization* controls the policy:

- **Disabled** – uploads pass through unchanged (the default after upgrading).
- **Ask users** – browsers that support compression are offered a one-click "Compress & upload" dialog per batch; declining uploads the originals.
- **Required** – every video upload above the configured minimum size must already comply with the policy (resolution, frame rate, bitrates, MP4/H.264/AAC). Non-compliant videos are rejected with a clear message and an audit entry.

How it works:

- The primary engine is [Mediabunny](https://mediabunny.dev/) on top of WebCodecs (hardware accelerated where available), running in a dedicated module worker. Sources the browser cannot decode (or browsers without a native H.264 encoder, like Firefox) fall back to a self-hosted ffmpeg.wasm build: the **multithreaded core** when the page is cross-origin isolated (`Cross-Origin-Opener-Policy`/`Cross-Origin-Embedder-Policy` are sent because the self-only CSP makes isolation safe), otherwise the single-threaded core. All core files are emitted by `npm run build` from node_modules; nothing is loaded from CDNs. AAC gaps are covered by the `@mediabunny/aac-encoder` WASM encoder.
- Large outputs stream into the browser's OPFS instead of RAM; small ones stay in memory. Temporary files are removed after upload.
- Videos that already match the policy are skipped without re-encoding.

Server-side verification (`app/MediaValidator.php`) trusts the artifact, not the client: after chunk assembly, ffprobe (fixed argv, timeout, output cap) checks the container, codecs, stream layout, dimensions, frame rate, bitrates, duration, and overall bytes-per-second. Anything unknown or non-compliant fails closed. Renamed or mislabeled files are detected by both sniffed MIME type and extension.

**"Required" mode needs `ffprobe` on the server** (auto-detected from `PATH`, or set explicitly in the admin form). Saving required mode without it is refused, and if it disappears later, video uploads are rejected until it is fixed rather than being accepted unverified. Older installations migrate automatically on the next request: the new settings keys are seeded and no existing data is touched.

## Web Server Security

### Apache
The included `.htaccess` files block direct access to `storage/`. Make sure `AllowOverride All` is enabled in your VirtualHost config.

### Nginx
Add this to your server block:

```nginx
location ~ ^/(storage|vendor|node_modules|tests|\.git)/ {
    deny all;
    return 404;
}

# The PHP pages send COOP/COEP for cross-origin isolation (needed by the
# multithreaded ffmpeg fallback). Under COEP every subresource response must
# declare a Cross-Origin-Resource-Policy, and worker scripts must carry
# their own COEP header to join the isolated context - so set both.
add_header Cross-Origin-Resource-Policy same-origin always;
add_header Cross-Origin-Embedder-Policy require-corp always;
```
