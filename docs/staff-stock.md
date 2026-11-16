# Staff stock room

Staff can view medicine quantities, add stock and review the latest 20 restocks at:

- Orange Pi: **http://192.168.0.110/staff.php** (use the Pi's current address if it changes).
- Local XAMPP: **http://localhost/MediVend/staff.php**.

Open this address separately from the customer kiosk. There is no staff link on the customer screens. Staff access has no default username or password and stays unavailable until an administrator configures it.

## Update the existing Orange Pi installation

These steps assume the app files have been copied to `/srv/medivend`, MariaDB already contains the working `medivend_db`, and Apache already reads `/etc/medivend/medivend.env`. Keep the existing database credentials and `MEDIVEND_MODE=simulation` setting. This prototype has no connected coin acceptor, motors or delivery sensor.

In an SSH terminal on the **Orange Pi**, back up the database and apply only the additive staff migration:

```bash
sudo sh -c 'umask 077; mariadb-dump --single-transaction medivend_db > /root/medivend-before-staff.sql'
cd /srv/medivend
sudo mariadb medivend_db < database/migrations/005_staff_stock.sql
```

The migration creates `staff_stock_events`; it does not change existing medicine quantities, prices, orders or payment records. Existing runtime `SELECT, INSERT, UPDATE` grants on `medivend_db.*` cover the new table. The runtime database account does not need schema privileges.

For this update, do not run the full `scripts/migrate.php` runner: it also reapplies the earlier current-price update and sets current medicine prices to 1000 centavos. The full runner is still suitable for a fresh development installation where that price update is intended.

Create staff access using the existing private configuration file:

```bash
sudo php scripts/setup-staff.php --username staff --env-file /etc/medivend/medivend.env
sudo systemctl restart apache2
```

Choose a password of **12 to 72 bytes** and enter it twice. Input is hidden. Use your chosen password when signing in as `staff`; do not put it in a command, source file or chat. The script stores a password hash and preserves the other private settings, file ownership and restricted permissions. On the current Pi, the file is `root:www-data`, mode `0640`, in a restricted parent directory. Keep Apache's read access when using a different service group.

The same command changes the staff password later. Credential changes invalidate existing staff sessions. There is one configured staff account; the recorded username identifies that account rather than an individual when staff share it.

## Add medicine stock

1. Open the staff address in your laptop browser and sign in.
2. Find the medicine and compartment, then choose **Add stock**.
3. Enter **only the extra units**, plus a short restock note. For example, current stock 4 plus an addition of 10 becomes 14. One unit means one dispensing unit, not one box unless the machine dispenses a whole box.
4. Choose **Add 10 units** (the button follows your entered quantity).
5. Check the updated quantity and its **Recent restocks** entry, then sign out.

Each addition must be a whole number from 1 to 10,000. Notes must be 3 to 240 UTF-8 bytes without line breaks. The page shows active and inactive medicines, but only active medicines can receive additions. It cannot create medicines, change prices, set an absolute stock count, subtract stock or erase history.

In simulation mode the page clearly shows **Test inventory** and manages demo medicine rows only. Recorded dispensing confirmations can reduce these quantities. Staff restocking does not enable physical dispensing. In a future commissioned hardware installation, count the units physically loaded into each compartment before recording additions; real inventory belongs to hardware mode and remains separate from test inventory.

## Interrupted requests and sessions

If a connection fails while recording stock, use **Retry same restock**. The browser saves the original request in the same tab, and the server records its unique event only once. Repeating that request confirms the existing entry without adding the quantity again. Keep the same tab and sign back in with the same staff account if requested. Do not start a replacement addition just because a response was lost.

Stock and its audit entry commit in one database transaction. Restocks and dispensing lock the same product row, so concurrent updates cannot overwrite each other's quantities. History records the medicine, compartment, quantity added, before/after counts, note, staff username and time. Purchases after a restock can make the current quantity differ from that entry's after count.

Staff sessions expire after 15 minutes without an authenticated request. Login and stock changes require CSRF tokens; login attempts are rate limited across browser cookies. A locked account must wait for the rate-limit window to pass. Sign out after use, especially on a shared touchscreen.

## Developer checks

```powershell
& 'C:\xampp\php\php.exe' tests/staff-stock.test.php
```

The test creates a uniquely named temporary database and private test credentials. It covers authentication, CSRF, validation, stock/audit transactions, duplicate and concurrent requests, rollback, expiry, logout, credential setup and login rate limits. It does not restock existing medicine rows. Database-admin access and PHP's curl extension are required for this local test.

Configuration lives in `config/staff.php`. The public routes are `api/staff-session.php` and `api/staff-stock.php`; product mode and the recorded staff identity come from server state. Optional `MEDIVEND_STAFF_RATE_DIR` selects a private writable directory for login rate-limit state. Keep that directory outside the web project and retain its records across requests.
