#!/usr/bin/env bash
# Install the stock-room update on the existing Orange Pi deployment.
# Run as root with the extracted update directory as the only argument.
set -euo pipefail
umask 077
[[ $EUID -eq 0 && $# -eq 1 ]] || { echo 'Usage: sudo bash update-staff.sh /path/to/extracted-update' >&2; exit 1; }
stage=$(realpath -- "$1")
target=/srv/medivend
[[ -d "$stage" && -f "$stage/files.sha256" && -d "$target" && ! -L "$target" ]] || { echo 'Expected staging directory and existing /srv/medivend deployment.' >&2; exit 1; }
cd "$stage"
sha256sum --check files.sha256
files=(staff.php index.html assets/staff.css assets/staff.js assets/app.js assets/demo.js assets/style.css assets/welcome.css api/staff-session.php api/staff-stock.php config/staff.php includes/staff.php includes/stock.php database/migrations/005_staff_stock.sql scripts/setup-staff.php scripts/check-deployment.php scripts/migrate.php docs/staff-stock.md README.md deploy/medivend.env.example deploy/update-staff.sh)
for file in "${files[@]}"; do
    [[ -f "$file" && ! -L "$file" ]] || { echo "Missing regular file: $file" >&2; exit 1; }
    if [[ "$file" == *.php ]]; then php -l "$file"; fi
done
backup_dir=$(mktemp -d /root/medivend-staff-backup-XXXXXXXX)
tar -czf "$backup_dir/app-before.tar.gz" -C "$target" .
mariadb-dump --single-transaction --result-file="$backup_dir/database-before.sql" medivend_db
mariadb --batch --skip-column-names medivend_db -e 'SELECT id,sku,stock_quantity,price_centavos FROM products ORDER BY id' > "$backup_dir/stock-before.tsv"
# Apply only the new additive migration. Older migrations also change prices.
mariadb medivend_db < database/migrations/005_staff_stock.sql
mariadb --batch --skip-column-names medivend_db -e 'SELECT id,sku,stock_quantity,price_centavos FROM products ORDER BY id' > "$backup_dir/stock-after.tsv"
cmp "$backup_dir/stock-before.tsv" "$backup_dir/stock-after.tsv"
for file in "${files[@]}"; do
    install -D -o root -g www-data -m 0644 -- "$file" "$target/$file"
done
apache2ctl configtest
systemctl reload apache2
curl --fail --silent --show-error http://127.0.0.1/api/staff-session.php
printf '\nUpdate installed. Stock and prices are unchanged. Backup: %s\n' "$backup_dir"
printf 'Choose your private staff password with:\nphp /srv/medivend/scripts/setup-staff.php --username staff --env-file /etc/medivend/medivend.env\n'
