#!/bin/bash
#
# install.sh - Bootstrap quetoo-stats on a Debian server.
#
# Run as root. Sets up LAMP stack, Let's Encrypt, database, and deploys the app.
#
# Usage: sudo bash install.sh

set -euo pipefail

DOMAIN="stats.quetoo.org"
REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
WWW_DIR="/var/www/quetoo-stats"
DB_NAME="quetoo_stats"
DB_USER="quetoo"

# --- Install packages ---
apt-get update
apt-get install -y apache2 php libapache2-mod-php php-mysql php-mbstring mariadb-server certbot python3-certbot-apache

a2enmod rewrite
systemctl enable --now apache2 mariadb

# --- Database setup ---
echo "Creating database and user..."
DB_PASS="$(openssl rand -base64 24)"

mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql "${DB_NAME}" < "${REPO_DIR}/schema.sql"

# --- Deploy app ---
echo "Deploying to ${WWW_DIR}..."
rsync -a --exclude='install.sh' --exclude='.git' --exclude='config.local.php' \
  "${REPO_DIR}/" "${WWW_DIR}/"

# STATS_SALT and ANALYTICS_SALT have no defaults, and config.php exits without
# them. They must also differ from each other: a shared salt would let a
# sessions row be joined to a frags row, which carries a player name.
#
# NEVER regenerate a salt that is already in use. A new STATS_SALT orphans every
# frags, captures and matches row permanently, with no raw GUID left to rehash
# against. So an existing config.local.php is left exactly as it is.
if [ -e "${WWW_DIR}/config.local.php" ]; then
  echo "Keeping the existing ${WWW_DIR}/config.local.php."
  echo "If it predates the analytics endpoint, add ANALYTICS_SALT to it by hand:"
  echo "  define('ANALYTICS_SALT', '$(openssl rand -hex 32)');"
else
  cat > "${WWW_DIR}/config.local.php" <<PHP
<?php
\$db_config['pass'] = '${DB_PASS}';
define('STATS_SALT', '$(openssl rand -hex 32)');
define('ANALYTICS_SALT', '$(openssl rand -hex 32)');
PHP
fi

chown -R www-data:www-data "${WWW_DIR}"
chmod 640 "${WWW_DIR}/config.local.php"

# --- Apache vhost ---
cp "${REPO_DIR}/apache/quetoo-stats.conf" /etc/apache2/sites-available/
a2ensite quetoo-stats
a2dissite 000-default || true
systemctl reload apache2

# --- Let's Encrypt ---
echo "Obtaining TLS certificate for ${DOMAIN}..."
certbot --apache -d "${DOMAIN}" --non-interactive --agree-tos --redirect \
  --email admin@quetoo.org

echo ""
echo "Done! quetoo-stats is live at https://${DOMAIN}"
echo "DB password written to ${WWW_DIR}/config.local.php"
