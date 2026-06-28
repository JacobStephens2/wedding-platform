# Wedding Website

A reusable wedding-website platform. The code under `public/` is couple-agnostic;
all couple-specific text (names, date, venues, story, page prose) lives in data,
not templates. This instance serves Jacob Stephens and Melissa Longua, live at
[wedding.stephens.page](https://wedding.stephens.page).

## Couple content lives in data, not code

Every couple-specific value is read through the helpers in `private/content.php`:

- `content('key')` returns a scalar (names, date, venues, emails, links).
- `contentBlocks('page')` returns the ordered prose sections for a page
  (`story`, `about`, `travel`, `blessing`).

Resolution order is database first, then file fallback:

1. The `site_content` (scalars) and `content_blocks` (page prose) MySQL tables,
   edited from the admin **Site Content** page (`/admin-content`).
2. `private/content_defaults.php`, the committed reference instance. It is the
   runtime fallback when a row is missing or the database is down, the seed
   source, and the documented list of every couple-specific field.

Story prose may place photo groups inline with `{{carousel:KEY}}` (swipeable) or
`{{blockimages:KEY}}` (grid), where `KEY` matches a photo's `story_section` set
in Manage Gallery.

### Standing up a new couple

1. Create the tables: `private/sql/create_content_tables.sql`.
2. Edit `private/content_defaults.php` with the new couple's details and prose
   (or leave it and edit everything in the admin after seeding).
3. Seed the editable copy: `php private/seed_content.php`
   (`--force` overwrites existing rows from the defaults file).
4. Refine names, dates, venues, and page sections at `/admin-content`.

## Tech Stack

- **Backend**: PHP 8.3+ / Apache / MySQL 8
- **Frontend**: Vanilla JavaScript, CSS3 with custom properties
- **Email**: PHPMailer via Mandrill SMTP
- **Dependencies**: Composer (`vlucas/phpdotenv`, `phpmailer/phpmailer`)

## Features

### Guest-Facing

- **Home** — countdown timer, background video
- **RSVP** — name lookup, group RSVP (ceremony & reception), dietary restrictions, song requests
- **Story** — relationship timeline with photo carousels and embedded video
- **Registry** — gift list with purchase tracking and fund progress bars
- **Gallery** — photo grid with lightbox and swipe navigation
- **About** — details about the Nuptial Mass and Holy Communion
- **Travel** — parking, transportation, and hotel room blocks
- **Contact** — visitor message form

### Admin

- **Guest Management** — add/edit/delete guests, plus-ones, rehearsal invites, child/infant tracking
- **RSVP Dashboard** — filterable/sortable view of all RSVPs with bulk operations
- **Seating Chart** — drag-and-drop table assignments, floor plan visualization, grid and card views, undo support
- **Registry Management** — add/edit items, mark purchases, control publication
- **Fund Tracking** — house fund and honeymoon fund contribution management
- **Gallery Management** — upload and organize photos by story section

### Other

- Dark mode toggle with `localStorage` persistence
- Responsive design with mobile hamburger navigation
- Private asset serving through `assets.php`
- Cron jobs for registry low-stock alerts and uptime monitoring

## Project Structure

```
public/                     Web root (Apache DocumentRoot)
├── css/style.css           Stylesheet with light/dark themes
├── js/main.js              Client-side logic
├── api/                    JSON API endpoints
├── includes/               Shared header, footer, theme init
├── images/                 Static images
└── *.php                   Page and admin files

private/                    Not web-accessible
├── config.php              App constants; loads content helpers
├── content.php             content() / contentBlocks() accessors
├── content_defaults.php    Couple-specific content (reference instance + fallback)
├── seed_content.php        Seed content tables from the defaults file
├── db.php                  PDO database connection
├── admin_auth.php          Session-based authentication
├── email_handler.php       Mandrill SMTP wrapper
├── sql/                    Database schema and migrations
├── cron/                   Scheduled scripts
├── photos/                 Organized by story section
└── videos/                 Private video storage
```

## Setup

See [SETUP.md](SETUP.md) for full installation instructions including Apache config, environment variables, SSL, and cron setup.

### Quick Start

```bash
composer install
cp private/.env.example private/.env   # edit with your credentials
# Run SQL migrations in private/sql/
# Point Apache DocumentRoot to public/
```

## Environment Variables

Configured in `private/.env`:

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL connection |
| `MANDRILL_SMTP_HOST`, `MANDRILL_SMTP_PORT`, `MANDRILL_SMTP_USER`, `MANDRILL_SMTP_PASS` | Email delivery |
| `ADMIN_PASSWORD` | Admin area access |
| `RSVP_CHECK_PASSWORD` | RSVP dashboard access |
| `RSVP_EMAIL`, `CONTACT_EMAIL` | Notification recipients |
| `REGISTRY_LOW_AVAILABLE_THRESHOLD` | Low-stock alert threshold |
| `REGISTRY_CHECK_COOLDOWN_HOURS` | Alert cooldown period |
