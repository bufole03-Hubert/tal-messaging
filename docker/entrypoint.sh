#!/bin/sh
set -e
cd /var/www/html
echo "[tal] Migrations..."
for i in 1 2 3 4 5 6 7 8 9 10; do
  if php database/migrate.php; then break; fi
  echo "[tal] Base indisponible, nouvel essai ($i/10)..."
  sleep 3
done
echo "[tal] Seed..."
php database/seed.php
exec "$@"
