#!/bin/bash
set -e

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

err() { echo "[error] $1"; exit 1; }

# php
command -v php >/dev/null || err "php not found"
[ "$(php -r 'echo PHP_VERSION_ID;')" -ge 80000 ] || err "php 8.0+ required"
for ext in pdo_sqlite fileinfo mbstring; do
    php -m | grep -qi "^${ext}$" || err "missing php extension: ${ext}"
done

# composer
if ! command -v composer >/dev/null; then
    echo "composer not found, installing locally..."
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    php /tmp/composer-setup.php --install-dir="$DIR" --filename=composer
    rm -f /tmp/composer-setup.php
    COMPOSER="$DIR/composer"
else
    COMPOSER="composer"
fi

# node / npm
if ! command -v node >/dev/null || ! command -v npm >/dev/null; then
    if ! command -v node >/dev/null; then
        echo "node not found, installing via nvm..."
        export NVM_DIR="$HOME/.nvm"
        if [ ! -d "$NVM_DIR" ]; then
            curl -so- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
        fi
        [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
        nvm install --lts
        nvm use --lts
    fi
fi
command -v node >/dev/null || err "node still not available after install attempt"
command -v npm  >/dev/null || err "npm still not available after install attempt"

echo "-- php ok ($(php -r 'echo PHP_VERSION;'))"
echo "-- node ok ($(node -v))"
echo "-- npm ok ($(npm -v))"

# dependencies
echo "-- composer install"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction 2>&1

echo "-- npm install"
npm ci --no-audit --no-fund 2>&1

# build frontend
echo "-- building frontend"
npm run build 2>&1

# storage dirs
for d in storage storage/uploads storage/chunks storage/sessions storage/logs storage/probe; do
    mkdir -p "$DIR/$d"
done
chmod -R 775 "$DIR/storage"

# chown to webserver user if running as root
if [ "$EUID" -eq 0 ]; then
    for u in www-data nginx apache http nobody; do
        if id "$u" &>/dev/null; then
            chown -R "$u:$u" "$DIR/storage"
            echo "-- storage owned by $u"
            break
        fi
    done
fi

echo ""
echo "done. open http://your-domain/install/ in a browser to finish setup."
