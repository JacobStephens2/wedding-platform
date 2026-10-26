# Setup Instructions

## Prerequisites
- PHP 8.3.6 or higher
- Composer
- Apache 2.4.58 or higher
- MySQL 8.0
- SMTP credentials (e.g. Resend)

## Installation Steps

1. **Install Composer Dependencies**
   ```bash
   cd /var/www/wedding.example.com
   composer install
   ```

2. **Configure Environment Variables**
   Copy the example and fill in your values:
   ```bash
   cp private/.env.example private/.env
   ```
   At minimum set `BASE_URL`, the `DB_*` connection, the `SMTP_*` settings, and
   `ADMIN_PASSWORD`. Each variable is described in `private/.env.example`.

3. **Database**
   Create the database and user (edit the password first), then run the table
   scripts in `private/sql/`:
   ```bash
   mysql -u root -p < private/sql/setup_database.sql
   for f in private/sql/create_*.sql; do mysql -u wedding_user -p wedding < "$f"; done
   ```

4. **Apache Configuration**
   Ensure your Apache virtual host is configured to:
   - Point the document root to `/var/www/wedding.example.com/public`
   - Allow `.htaccess` overrides
   - Enable mod_rewrite
   - Enable mod_php

   Example virtual host configuration:
   ```apache
   <VirtualHost *:80>
       ServerName wedding.example.com
       DocumentRoot /var/www/wedding.example.com/public
       
       <Directory /var/www/wedding.example.com/public>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

5. **SSL Certificate (Optional but Recommended)**
   Use certbot to set up SSL:
   ```bash
   sudo certbot --apache -d wedding.example.com
   ```

6. **File Permissions**
   Ensure Apache can read files:
   ```bash
   sudo chown -R www-data:www-data /var/www/wedding.example.com
   sudo chmod -R 755 /var/www/wedding.example.com
   ```

7. **Couple Content**
   Create the content tables and seed them with the values from
   `private/content_defaults.php`:
   ```bash
   mysql -u "$DB_USER" -p "$DB_NAME" < private/sql/create_content_tables.sql
   php private/seed_content.php
   ```
   Then edit names, date, venues, and page prose at `/admin-content`. To start
   from a file instead, copy `private/content_defaults.php` (the sample couple)
   to `private/content_defaults.local.php` (git-ignored), fill it in, and seed
   (or run `php private/seed_content.php --force` to overwrite existing rows).

8. **Photos and images**
   Put photos under `private/photos/<folder>/`, the home video under
   `private/videos/`, and fund / gallery card images under `public/images/`.
   All are git-ignored. Register gallery photos in Manage Gallery and set image
   paths in Site Content. The site still renders from the defaults file if these tables are
   absent, so this step is safe to run at any time. See the README for details.

9. **Guest list (optional)**
   Export your guest-list spreadsheet as CSV (keep it out of the repo, e.g. in
   the git-ignored `private/sources/`) and import it:
   ```bash
   php private/import_guests.php private/sources/guest-list.csv
   php private/import_addresses.php private/sources/guest-list.csv --dry-run
   ```
   Columns: `First Name`, `Last Name`, `Group`, `id`, `Mailing Group`, and for
   addresses `Address 1`, `Address 2`, `City`, `State`, `ZIP`, `Country`.
   Summary rows above the header row are ignored.

## Development

```bash
composer install
composer test      # PHPUnit (tests/)
composer analyse   # PHPStan, max level (src/, tests/)
```

## Testing

1. Visit `https://wedding.example.com` to see the home page
2. Test the RSVP form - check that the notification email arrives
3. Test the contact form - verify email delivery
4. Check all navigation links work on both desktop and mobile views

## Notes

- Photos and videos are served through `assets.php` for security (they remain in the private directory)
- The countdown timer updates hourly
- Mobile navigation uses a hamburger menu
- Desktop navigation shows links below the header

## Crons

### Registry Checker

There is a CLI script that checks whether the registry is getting low on **available** items (published + not purchased) and emails admins.

- **Script**: `private/cron/registry_checker.php`
- **Config (in `private/.env`)**:
  - `REGISTRY_LOW_AVAILABLE_THRESHOLD` (default: `10`)
  - `REGISTRY_CHECK_COOLDOWN_HOURS` (default: `24`)
  - `REGISTRY_CHECK_RECIPIENTS` (optional, comma-separated list of emails)
  - `REGISTRY_CHECK_DRY_RUN=1` (optional, disables sending)

Example cron (runs daily at 8am, logs output):

```bash
0 8 * * * cd /var/www/wedding.example.com && /usr/bin/php private/cron/registry_checker.php >> /var/log/wedding-registry-checker.log 2>&1
```






