# Wedding Website

The wedding website of Jacob Stephens and Melissa Longua, live at [wedding.stephens.page](https://wedding.stephens.page).

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
├── config.php              App constants and configuration
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
