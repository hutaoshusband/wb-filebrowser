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
