# Wedding Website

A reusable wedding-website platform: RSVPs, registry with funds, story, gallery,
seating charts, and guest announcements. The code is couple-agnostic; every
couple-specific value (names, date, venues, story, page prose, photos, guest
list) lives in data, not in the repository. Fork it or use it as a template, and
a fresh install renders a fictional sample couple until you add your own.

## Couple content lives in data, not code

Every couple-specific value is read through the helpers in `private/content.php`:

- `content('key')` returns a scalar (names, date, venues, emails, links).
- `contentBlocks('page')` returns the ordered prose sections for a page
  (`story`, `about`, `travel`, `blessing`).

Resolution order is database first, then file fallback:

1. The `site_content` (scalars) and `content_blocks` (page prose) MySQL tables,
   edited from the admin **Site Content** page (`/admin-content`).
2. The file fallback: `private/content_defaults.local.php` if it exists
   (git-ignored, your couple's real content), otherwise the committed sample
   couple in `private/content_defaults.php`. It is the runtime fallback when a
   row is missing or the database is down, the seed source, and the documented
   list of every couple-specific field.

Story prose may place photo groups inline with `{{carousel:KEY}}` (swipeable) or
`{{blockimages:KEY}}` (grid), where `KEY` matches a photo's `story_section` set
in Manage Gallery. Manage Gallery offers exactly the keys placed in the story.

Photos, videos, guest lists, and other instance files are git-ignored and live
only on the server: photos under `private/photos/`, videos under
`private/videos/`, public images (fund and gallery card images) under
`public/images/`.

### Standing up a new couple

1. Create the tables: `private/sql/create_content_tables.sql`.
2. Copy `private/content_defaults.php` to `private/content_defaults.local.php`
   and fill in the couple's details and prose (or skip this and edit everything
   in the admin after seeding the sample).
3. Seed the editable copy: `php private/seed_content.php`
   (`--force` overwrites existing rows from the defaults file).
4. Refine names, dates, venues, and page sections at `/admin-content`.

## Contributing

See [CODING_STANDARDS.md](CODING_STANDARDS.md).

## Tech Stack

- **Backend**: PHP 8.3+ / Apache / MySQL 8
- **Frontend**: Vanilla JavaScript, CSS3 with custom properties
- **Email**: PHPMailer over SMTP (e.g. Resend)
- **Dependencies**: Composer (`vlucas/phpdotenv`, `phpmailer/phpmailer`)

## Features

### Guest-Facing

- **Home** — countdown timer, background video
- **RSVP** — name lookup, group RSVP (ceremony & reception), dietary restrictions, song requests
- **Story** — relationship timeline with photo carousels and embedded video
- **Registry** — gift list with purchase tracking and fund progress bars
- **Gallery** — photo grid with lightbox and swipe navigation
- **About** — schedule and ceremony details
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
├── content_defaults.php    Sample couple content (fallback + seed source)
├── content_defaults.local.php  Your couple's content (git-ignored, overrides the sample)
├── seed_content.php        Seed content tables from the defaults file
├── db.php                  PDO database connection
├── admin_auth.php          Session-based authentication
├── email_handler.php       SMTP email wrapper
├── sql/                    Database schema and migrations
├── cron/                   Scheduled scripts
├── photos/                 Private photos (git-ignored)
└── videos/                 Private videos (git-ignored)
```

## Setup

See [SETUP.md](SETUP.md) for full installation instructions including Apache config, environment variables, SSL, and cron setup. `private/.env.example` lists every environment variable.

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
| `BASE_URL` | Public site URL (used in emails and cron scripts) |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` | Email delivery |
| `ADMIN_PASSWORD` | Admin area access |
| `RSVP_CHECK_PASSWORD` | RSVP dashboard access |
| `RSVP_EMAIL`, `CONTACT_EMAIL` | Fallback notification recipients. The active recipients are set in the admin Site Content page (`rsvp_notify_emails`, `contact_notify_email`); these env vars are only used when those fields are blank. |
| `REGISTRY_LOW_AVAILABLE_THRESHOLD` | Low-stock alert threshold |
| `REGISTRY_CHECK_COOLDOWN_HOURS` | Alert cooldown period |
