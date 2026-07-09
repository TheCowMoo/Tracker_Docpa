#!/bin/bash
# ============================================================
# DOCPA Tracker — VPS Installation Script
# ============================================================
#
# This script deploys the DOCPA Tracker server to your VPS.
# It assumes HestiaCP is installed with Apache/Nginx + PHP + MySQL.
#
# Usage:
#   ssh user@your-vps
#   cd /tmp
#   # Upload the server/ directory to your VPS, then run:
#   bash install.sh
#
# This script will:
#   1. Create the MySQL database and user
#   2. Import the schema
#   3. Copy files to the web root
#   4. Create storage directories with correct permissions
#   5. Create a config.local.php with your settings
#   6. Register an admin user (displays API key)
# ============================================================

set -e

# ---- CONFIGURATION (EDIT THESE) ----
VPS_DOMAIN="tracker.docharteredaccountant.com"
DB_NAME="docpa_tracker"
DB_USER="docpa"
DB_PASS="$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 20)"
ADMIN_USERNAME="admin"
ADMIN_EMAIL="admin@${VPS_DOMAIN}"

# ---- COLORS ----
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}   DOCPA Tracker — VPS Installation${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""

# ---- CHECK ROOT ----
if [[ $EUID -eq 0 ]]; then
   echo -e "${YELLOW}Running as root. It's safer to run as a regular user with sudo.${NC}"
fi

# ---- FIND WEB ROOT ----
# HestiaCP typically puts domains under /home/<user>/web/<domain>/public_html/
# Try to find the right path
POSSIBLE_ROOTS=(
    "/home/*/web/${VPS_DOMAIN}/public_html"
    "/var/www/${VPS_DOMAIN}/html"
    "/var/www/html"
    "/usr/share/nginx/${VPS_DOMAIN}"
    "/usr/local/hestia/data/users/*/${VPS_DOMAIN}"
)

WEB_ROOT=""
for pattern in "${POSSIBLE_ROOTS[@]}"; do
    for dir in $pattern; do
        if [[ -d "$dir" ]]; then
            WEB_ROOT="$dir"
            break 2
        fi
    done
done

if [[ -z "$WEB_ROOT" ]]; then
    # Default to current directory
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    WEB_ROOT="$SCRIPT_DIR"
    echo -e "${YELLOW}Could not detect web root automatically.${NC}"
    echo -e "Using current directory: ${WEB_ROOT}"
    echo -e "Make sure this is accessible via http://${VPS_DOMAIN}/"
else
    echo -e "${GREEN}Detected web root: ${WEB_ROOT}${NC}"
fi

# ---- STEP 1: SETUP DATABASE ----
echo ""
echo -e "${YELLOW}[1/6] Setting up MySQL database...${NC}"

# Try to create database and user (may require sudo)
if command -v mysql &> /dev/null; then
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    echo -e "${GREEN}  Database '${DB_NAME}' created.${NC}"
else
    echo -e "${RED}  MySQL CLI not found. Please create the database manually:${NC}"
    echo "  CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo "  CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    echo "  GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    echo "  FLUSH PRIVILEGES;"
fi

# ---- STEP 2: IMPORT SCHEMA ----
echo -e "${YELLOW}[2/6] Importing database schema...${NC}"

SCHEMA_FILE="schema.sql"
if [[ -f "$SCHEMA_FILE" ]]; then
    if command -v mysql &> /dev/null; then
        mysql -u root "${DB_NAME}" < "$SCHEMA_FILE"
        echo -e "${GREEN}  Schema imported.${NC}"
    else
        echo -e "${RED}  MySQL CLI not found. Please import schema.sql manually.${NC}"
    fi
else
    echo -e "${RED}  schema.sql not found in current directory. Skipping.${NC}"
fi

# ---- STEP 3: COPY FILES ----
echo -e "${YELLOW}[3/6] Copying server files to web root...${NC}"

# Copy all server files except install.sh and config.local.php
mkdir -p "${WEB_ROOT}/api"
mkdir -p "${WEB_ROOT}/dashboard"
mkdir -p "${WEB_ROOT}/includes"
mkdir -p "${WEB_ROOT}/data/screenshots"
mkdir -p "${WEB_ROOT}/data/thumbnails"

# Copy files preserving structure
cp -r api/* "${WEB_ROOT}/api/"
cp -r dashboard/* "${WEB_ROOT}/dashboard/"
cp -r includes/* "${WEB_ROOT}/includes/"

echo -e "${GREEN}  Files copied.${NC}"

# ---- STEP 4: STORAGE DIRECTORIES ----
echo -e "${YELLOW}[4/6] Setting up storage directories...${NC}"

# Create .htaccess to prevent direct access to screenshots
cat > "${WEB_ROOT}/data/.htaccess" << 'HTACCESS'
Deny from all
HTACCESS

# Ensure web server can write to data directories
if command -v getent &> /dev/null; then
    # Find web server user
    if getent passwd www-data &>/dev/null; then
        WWW_USER="www-data"
    elif getent passwd nginx &>/dev/null; then
        WWW_USER="nginx"
    elif getent passwd apache &>/dev/null; then
        WWW_USER="apache"
    else
        WWW_USER="www-data"
    fi

    chown -R "${WWW_USER}:${WWW_USER}" "${WEB_ROOT}/data/"
    chmod -R 755 "${WEB_ROOT}/data/"
    echo -e "${GREEN}  Directory permissions set for user '${WWW_USER}'.${NC}"
fi

# ---- STEP 5: CONFIG ----
echo -e "${YELLOW}[5/6] Creating config.local.php...${NC}"

cat > "${WEB_ROOT}/includes/config.local.php" << PHP
<?php
// Local configuration override — generated by install.sh
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');

define('SCREENSHOTS_DIR', '${WEB_ROOT}/data/screenshots/');
define('THUMBNAILS_DIR', '${WEB_ROOT}/data/thumbnails/');
PHP

echo -e "${GREEN}  config.local.php created.${NC}"

# ---- STEP 6: REGISTER ADMIN USER ----
echo -e "${YELLOW}[6/6] Registering admin user...${NC}"

# Generate API key via PHP if available
if command -v php &> /dev/null; then
    API_KEY=$(php -r "echo bin2hex(random_bytes(32));")
    mysql -u root "${DB_NAME}" -e "
        DELETE FROM users WHERE username = '${ADMIN_USERNAME}';
        INSERT INTO users (username, display_name, api_key, email)
        VALUES ('${ADMIN_USERNAME}', 'Administrator', '${API_KEY}', '${ADMIN_EMAIL}');
    "
else
    API_KEY="<run 'php -r \"echo bin2hex(random_bytes(32));\"' to generate>"
fi

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}   Installation Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "Website:     https://${VPS_DOMAIN}/dashboard/"
echo -e "API URL:     https://${VPS_DOMAIN}/api/"
echo ""
echo -e "${YELLOW}=== ADMIN CREDENTIALS ===${NC}"
echo -e "  Username:   ${ADMIN_USERNAME}"
echo -e "  API Key:    ${API_KEY}"
echo ""
echo -e "${YELLOW}=== DATABASE ===${NC}"
echo -e "  Database:   ${DB_NAME}"
echo -e "  Username:   ${DB_USER}"
echo -e "  Password:   ${DB_PASS}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Place the 'api_key' from above into your client config.json"
echo "  2. Open https://${VPS_DOMAIN}/dashboard/ and log in with the API key"
echo "  3. Register additional users via: POST /api/auth.php?action=register"
echo ""
echo -e "${GREEN}Done!${NC}"