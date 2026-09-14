# wb-filebrowser

A web-based file browser built with PHP and Vue.

## Requirements

- PHP 8.0+ with `pdo_sqlite`, `fileinfo`, and `mbstring`
- Composer
- Node.js 18+ and npm
- A web server (Apache, Nginx, etc.)

## Quick Start

```bash
git clone https://github.com/hutaoshusband/wb-filebrowser.git
cd wb-filebrowser
chmod +x install.sh
sudo ./install.sh
```

The script handles everything: installs composer if missing, installs node via nvm if missing, pulls PHP and JS dependencies, builds the frontend, creates the storage directories, and sets permissions.

Once it finishes, point your web server at the project directory and open `/install/` in a browser to create your admin account.

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

Then open `http://your-domain/install/` to finish.

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
```

### Storage diagnostic origin

Set `WB_PUBLIC_ORIGIN` in the PHP service environment to the trusted site origin
(for example `https://files.example.com`, without a path). Storage diagnostics
use this configured destination and do not follow redirects. Without it, the
network probe reports a configuration error instead of trusting the HTTP Host header.

Password resets invalidate existing sessions. Upgrading from sessions without a
credential fingerprint requires users to sign in again. Accounts marked for a
password change can access only session, logout, and password-change API actions
until they choose a new password. Mutating API actions require POST and a CSRF token.
JSON request bodies are limited to 1 MiB; binary upload chunks use multipart requests.
