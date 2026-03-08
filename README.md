# wb-filebrowser

A sleek, fast, and secure web-based file browser.

## Requirements

- PHP 8.0 or newer (with `sqlite3`, `fileinfo`, and `mbstring` extensions enabled)
- A web server (Apache, Nginx, IIS, etc.)
- SQLite (included with PHP by default)

## Installation

### Method 1: Auto-Installer (Linux/macOS)
The easiest way to setup dependencies and folder permissions is by running the included setup script via your terminal.
1. **Download the latest release:** Go to the [Releases](https://github.com/hutaoshusband/wb-filebrowser/releases) tab and download the latest `.zip` release.
2. **Extract and Upload:** Extract the zip file and upload the contents to your web server's directory (e.g., `/var/www/html/filebrowser`).
3. **Run the script:** Navigate to the folder in your terminal and execute:

```bash
chmod +x install.sh
sudo ./install.sh
```

### Method 2: Manual Installation
1. **Download the latest release:** Go to the [Releases](https://github.com/hutaoshusband/wb-filebrowser/releases) tab on GitHub and download the latest `.zip` release. *(Do not download the source code zip, as it requires manual building of frontend assets and PHP dependencies).*
2. **Extract and Upload:** Extract the zip file and upload the contents to your web server's directory (e.g., `public_html/filebrowser` or `/var/www/html/filebrowser`).
3. **Folder Permissions:** Ensure that the `storage/` directory and all of its contents are readable and writable by your web server software (such as `www-data` or `apache`).
   ```bash
   chmod -R 775 storage/
   # If necessary, give explicit ownership to the web server user:
   # chown -R www-data:www-data storage/
   ```

### Finalizing Setup

4. **Run the Installer:** Open your web browser and navigate to the application's install directory: `http://your-domain.com/install/`. Follow the on-screen instructions to set up your administrator account and initialize the database.
5. **Delete Installer (Optional but Recommended):** After successful installation, you can delete the `install/` directory for an extra layer of security.

## Server Configuration & Security (IMPORTANT)

It is **critical** to configure your web server to block direct access to internal application directories—especially the `storage/` folder, which contains your SQLite database, logs, and sensitive session data.

### Apache

If you are using Apache, the application includes `.htaccess` files to automatically protect sensitive directories. However, your server must be configured to allow them:
- Ensure your Apache VirtualHost configuration has `AllowOverride All` enabled for your document root so that the `.htaccess` files are read and processed.

### Nginx

Nginx ignores `.htaccess` files. You **must** manually add the following rules to your Nginx site configuration (inside your `server { ... }` block) to ensure your data is secure:

```nginx
    # Block access to the storage directory and other sensitive internal folders
    location ~ ^/(storage|vendor|node_modules|tests|\.git)/ {
        deny all;
        return 404;
    }

    # Block direct access to specific sensitive file extensions
    location ~ \.(sqlite|sqlite3|db|json|lock|xml|md)$ {
        deny all;
        return 404;
    }
```
