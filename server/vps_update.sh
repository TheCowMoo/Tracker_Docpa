#!/bin/bash
# Run this on the VPS to pull latest fixes from GitHub and update public_html

set -e

cd /home/Admin/web/tracker.docharteredaccountant.com/Tracker_Docpa
git pull origin main

# Copy API files
cp server/api/auth.php ../public_html/api/
cp server/api/upload.php ../public_html/api/
cp server/api/sessions.php ../public_html/api/
cp server/api/screenshots.php ../public_html/api/
cp server/api/stats.php ../public_html/api/
cp server/api/heartbeat.php ../public_html/api/

# Copy includes
cp server/includes/db.php ../public_html/includes/
cp server/includes/config.php ../public_html/includes/
cp server/includes/auth_middleware.php ../public_html/includes/

# Copy dashboard
cp server/dashboard/index.html ../public_html/dashboard/
cp server/dashboard/css/style.css ../public_html/dashboard/css/
cp server/dashboard/js/app.js ../public_html/dashboard/js/

echo "Update complete. Testing API..."

# Test login
curl -s -X POST 'http://tracker.docharteredaccountant.com/api/auth.php?action=login' \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"8c9fc8361335efc35ca9c302b0de5fef59bd76fa9fe23711699dafee34891447"}'

echo ""
echo "Done."