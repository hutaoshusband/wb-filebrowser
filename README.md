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
