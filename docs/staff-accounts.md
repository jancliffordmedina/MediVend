# Staff accounts and stopped orders

Everyone (administrators and staff) signs in on the same page: **staff.php**. The kiosk has a **Staff login** button in its header and in the "Please contact clinic staff" dialog. **Back to kiosk** signs out before returning, because the touchscreen is shared.

| Role | Can do |
| --- | --- |
| Staff | Add stock, resolve stopped orders and stray coins |
| Administrator | Everything staff can do, plus add, edit, deactivate and reset users |

* **One sign-in per user.** Signing in on another device ends the earlier session, and that device shows "signed out because this account signed in on another device". Give every person their own account.
* Sessions end after 15 minutes without activity. After 8 failed sign-ins, that username is locked for 15 minutes.
* Passwords are 12 to 72 characters and stored only as hashes. Changing a user's role or password, or deactivating them, signs that user out.
* The system always keeps at least one active administrator. Administrators can't deactivate themselves or remove their own admin role.

## First administrator

Apply migration `007_users_and_resolutions.sql` (included in `scripts/migrate.php`). Then:

```powershell
# XAMPP (Windows). The password is typed twice and IS visible on screen:
& 'C:\xampp\php\php.exe' scripts/create-user.php --username admin --name "Clinic Admin" --role admin --password-stdin
```
```sh
# Orange Pi (hidden prompts):
sudo -u medivend php /srv/medivend/scripts/create-user.php --username admin --name "Clinic Admin" --role admin
```

For local testing only, `php scripts/seed-demo-users.php` creates three dummy accounts with known passwords (`admin`, `nurse.santos`, `clinic.staff`). It refuses to run in hardware mode, and `--remove` deactivates those accounts again.

Then sign in at staff.php, open **Users** and add the other staff. If every administrator is locked out, run `create-user.php --username admin --reset-password`. This also reactivates the account.

An older installation that used `MEDIVEND_STAFF_USERNAME` / `MEDIVEND_STAFF_PASSWORD_HASH` (from `setup-staff.php`) can still sign in with that account, but only while **no active administrator account exists**. Use it once to create a real administrator. After that it stops working.

## Resolving a stopped order (unblocking the kiosk)

An order stops for review after a jam, a missing sensor confirmation, an extra coin and similar faults. The kiosk then can't be cleared by reloading, because the customer's recorded credit is protected. To clear it:

1. On the kiosk, tap **Staff login** (or **Staff assistance**, then **Staff login**) and sign in.
2. **Needs attention** lists every stopped order and every coin accepted with no open order. Help the customer first, then tap **Resolve** and record what you actually did:
   * **Returned the money by hand**: give back the full recorded amount. Stock doesn't change.
   * **Handed over the medicine by hand**: take the remaining units from the machine. Stock goes down by that number. This is only allowed when the order was fully paid, and any excess must be returned by hand.
   * **Closed without refund or medicine**: for test orders, or when no money was taken.
3. Add a short note, for example "Returned ₱30 cash to student; jam cleared".
4. Tap **Back to kiosk**, then on the kiosk tap **Refresh status** (hardware mode refreshes by itself), then **Finish session**. The kiosk returns to the welcome screen.

Each order can be resolved only once. Retrying after a lost connection never records it twice. Resolutions show the staff user and the time under **Recently resolved**. The software never claims a refund or delivery on its own; it only records what staff confirm.

In hardware mode the machine stays unavailable while any stopped order or stray coin is unresolved.
