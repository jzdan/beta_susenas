#!/bin/sh
set -e

cd /var/www/html

# APP_KEY wajib
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force
fi

# tunggu database siap (maksimal ~30 dtk)
tries=0
until php artisan migrate:status >/dev/null 2>&1 || [ "$tries" -ge 15 ]; do
  echo "menunggu database…"
  tries=$((tries + 1))
  sleep 2
done

php artisan migrate --force

# seed metadata hanya bila belum ada versi kuesioner
if [ "$(php artisan tinker --execute='echo \App\Models\QuestionnaireVersion::count();' 2>/dev/null | tr -dc 0-9)" = "0" ]; then
  echo "seed metadata awal…"
  php artisan db:seed --class=MetadataSeeder --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
