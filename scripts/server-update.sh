#!/bin/bash
# Runs ON the server, sent by nasery-deploy.sh. Arguments: migrations to apply.
set -euo pipefail

D=/home/ubuntu/deploy
R=/var/www/nasery
B=$R/backend
# From the app's folder: the web user can't enter ubuntu's home, and commands it starts (pg_dump) would fail there.
artisan() { (cd "$B" && sudo -u www-data php artisan "$@"); }

command -v rsync >/dev/null || apt-get -y -qq install rsync

tar -xzf "$D/backend.tgz" -C "$D"
mkdir -p "$D/web"
tar -xzf "$D/web.tgz" -C "$D/web" --strip-components=1

lock_before="$(sha256sum "$B/composer.lock" | cut -d' ' -f1)"

# New code in place; the server's own settings, uploads and packages stay.
rsync -a --delete --chown=ubuntu:www-data \
	--exclude=/.env --exclude=/vendor --exclude=/storage --exclude=/bootstrap/cache \
	"$D/backend/" "$B/"
rsync -a --delete --chown=ubuntu:www-data "$D/web/" "$R/web/"

if [ "$lock_before" != "$(sha256sum "$B/composer.lock" | cut -d' ' -f1)" ]; then
	echo "  packages changed: composer install"
	sudo -u ubuntu -H bash -c "cd $B && composer install --no-dev --no-interaction --optimize-autoloader --no-progress --no-scripts -q"
fi

artisan config:clear -q
artisan route:clear -q
artisan event:clear -q
sudo -u ubuntu -H bash -c "cd $B && composer dump-autoload --optimize --no-interaction --no-scripts -q"
artisan package:discover -q

if [ $# -gt 0 ]; then
	for file in "$@"; do
		echo "  migration: $file"
		artisan nasery:db:sql "$file" --apply
	done
fi

artisan config:cache -q
artisan route:cache -q
artisan event:cache -q
# Uploads: pictures up to 5 MB, documents up to 10 MB.
sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 12M/; s/^post_max_size = .*/post_max_size = 14M/' /etc/php/8.5/fpm/php.ini
# Nightly backup at 3 AM Philippine time (19:00 UTC on the server's clock).
install -m 700 "$D/server-backup.sh" /usr/local/bin/nasery-backup
printf '0 19 * * * root /usr/local/bin/nasery-backup >> /var/log/nasery-backup.log 2>&1\n' > /etc/cron.d/nasery-backup
chmod 644 /etc/cron.d/nasery-backup

systemctl daemon-reload
systemctl restart php8.5-fpm
systemctl restart nasery-queue nasery-scheduler

artisan nasery:doctor | tail -1
rm -rf "$D"
