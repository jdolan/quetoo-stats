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
apt-get install -y apache2 php libapache2-mod-php php-mysql mariadb-server certbot python3-certbot-apache

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

cat > "${WWW_DIR}/config.local.php" <<PHP
<?php
\$db_config['pass'] = '${DB_PASS}';
PHP

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
