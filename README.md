# wb-filebrowser

A web-based file browser built with PHP and Vue.

## Requirements

- PHP 8.1+ with `fileinfo`, `mbstring`, and at least one supported PDO driver: `pdo_sqlite`, `pdo_mysql`, or `pdo_pgsql`
- Composer
- Node.js 18+ and npm
- A web server (Apache, Nginx, etc.)
- PHP `zip` extension for folder downloads
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

The top-bar **Download folder (ZIP)** action exports the current folder and its accessible subfolders, including empty folders. ZIP entries are stored without compression to reduce CPU use; encrypted files remain encrypted (`.wbencrypted`). Only contents readable by the current user or folder-share link are exported.

Archives are built in a private temporary file and removed at request shutdown. Allow temporary disk space roughly equal to the exported contents plus ZIP metadata and 64 MiB headroom. One archive is prepared at a time per storage volume, with three requests per IP per five minutes and a maximum of 100,000 entries per archive. The download releases the PHP session lock so browsing can continue.

Run the existing automated suites with:

```bash
php tools/phpunit.phar --configuration phpunit.xml
npm run test:frontend
npm run test:video:browser
```

The video browser test requires a Playwright Chromium install (or `WB_TEST_BROWSER_CHANNEL=msedge` / `chrome`). It generates a small video locally, throttles its delivery, and checks native playback in the app, public share and embed views.

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

## Video Playback and Streaming

Video previews, public shares, and embedded players use native browser decoding and HTTP byte ranges. Opening a preview requests metadata only. Pressing Play enables native preloading behind a dimmed loading circle, then starts playback automatically. No separate speed test, full-file JavaScript fetch, playback transcoding, or second download is used.

The player measures growth of the contiguous playable buffer in media seconds per wall-clock second. Startup targets 3–10 seconds (6–15 after a stall), adjusted for delivery rate, remaining duration, and playback speed. After 8 seconds at startup or 12 seconds during recovery it proceeds with available playable data; a browser that suspends its paused preload can proceed sooner. These are limits on the extra buffer wait, not a timeout on an unavailable network. The ordinary Pause control cancels a pending start. Seeking recalculates the buffer; closing a preview aborts its request.

Admin → Settings → Access → **Show view counts on public file shares** controls public visibility of existing share counts across all file-share pages and embedded players. It defaults to off and has no per-file override. The existing boot-time migration seeds the setting for SQLite, MySQL and PostgreSQL; no new counter column is needed. Counts and view-limit enforcement continue while hidden, and managers retain their existing counts. “Share views” means successful share-page opens, including repeat opens, not unique viewers or video plays. Embedded players display that same count; loading an embed, seeking, buffering, and range requests do not increment it. Counts update on page load without background polling.

Buffering cannot make a permanently slower connection keep up with the video's bitrate, or fix a device that cannot decode the source smoothly. For those files, use the existing client-side upload optimization to reduce bitrate/resolution. MP4 files with metadata at the end also need an extra tail-range request before playback. Existing files are served unchanged.

The PHP fallback streams fixed-size chunks, releases its session lock, stops on disconnect, and sends `X-Accel-Buffering: no` to avoid proxy buffering/disk spooling. It still occupies one PHP worker for each active response. For installations with many simultaneous viewers, Nginx can deliver authenticated uploads directly and release PHP immediately:

```nginx
# Use the absolute path of this installation's storage/uploads directory.
# Keep the trailing slashes. This location must remain internal.
location ^~ /_wb_uploads/ {
    internal;
    alias /var/www/wb-filebrowser/storage/uploads/;
    sendfile on;
    gzip off;
    add_header X-Content-Type-Options nosniff always;
    add_header Content-Security-Policy "default-src 'none'; sandbox" always;
    add_header Cross-Origin-Resource-Policy cross-origin always;
    add_header X-Robots-Tag "noindex, nofollow" always;
}
```

After configuring and testing that location, set `WB_X_ACCEL_PREFIX=/_wb_uploads/` in the PHP-FPM pool environment and reload PHP-FPM. This is opt-in and requires Nginx; enabling it without the matching location breaks downloads. PHP still authorizes each request, then hands only real files inside `storage/uploads` to Nginx using `X-Accel-Redirect`. Nginx handles the response body and byte ranges; private/share/embed cache policies and content disposition come from PHP. Other storage paths always use the PHP fallback. Check authenticated and shared video playback, seeking, HEAD/range responses, and direct denial of `/_wb_uploads/...` after enabling it. Keep PHP/API responses out of any shared proxy cache.

See [Nginx FastCGI response controls](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html) for the handoff and buffering headers.

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
