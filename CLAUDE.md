# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Unison** is a music listening and sharing web application designed for internal network use. It allows users to upload, search, and stream music with features like playlists, favorites, and user accounts.

- **Tech Stack**: PHP + MySQL/MariaDB + MeiliSearch + Vanilla JavaScript
- **Deployment**: Docker-based (separate dev and production configs)
- **Status**: In active development (frontend responsive design and JavaScript dynamism still in progress)

## Setup & Development Commands

### Initial Setup
```bash
cd docker
nano .env  # Configure database, mail, and Meilisearch credentials
docker compose -f docker-compose-dev.yml up -d
```

### Access Points (Development)
- **Main app**: http://localhost:8082 (or your configured PORT_EXPOSE)
- **MeiliSearch dashboard**: http://localhost:7700/
- **MailHog (email testing)**: http://localhost:8025

### Common Container Commands
```bash
# Access application shell
docker compose exec -it app bash

# Access database shell
docker compose exec db mariadb -u root -p

# View logs
docker compose logs -f app

# Stop containers
docker compose down
```

## Architecture

### Directory Structure
- **src/pages/** - Page templates/views (rendered with PHP)
- **src/actions/** - API endpoints and form handlers (controllers)
- **src/includes/** - Shared utilities:
  - `config.php` - PDO database connection singleton
  - `initSearch.php` - MeiliSearch initialization
  - `auth.php` - Session guards (`exigerConnexion`, `exigerAdmin`, CSRF)
  - `journal.php` - Activity/incident logging (write side, included everywhere)
  - `journalRapport.php` - Log reading, filtering, statistics, purge (admin only)
  - `console.php` - Admin console command interpreter (read-only)
- **src/scripts/** - Frontend JavaScript:
  - `router.js` - Client-side navigation and page routing
  - `player.js` - Audio player functionality
  - `search.js` - Search interface interactions
  - `login.js`, `reset_password.js` - Form handling
  - `journal.js`, `console.js` - Admin journal viewer and terminal
- **src/styles/** - CSS styling
- **src/index.php** - Main entry point (checks session, includes header)
- **docker/** - Docker configuration and initialization scripts
- **meilisearch_init/** - Search index initialization
- **mysql_init/** - Database schema initialization
- **musics_storage/** - Uploaded music files (persisted volume)

### Request Flow
1. User accesses `index.php` or other pages
2. Session validation in page entry point
3. JavaScript router (`router.js`) handles client-side navigation
4. API calls to `/src/actions/*.php` endpoints (form submissions, searches)
5. Actions return JSON responses or redirect
6. MeiliSearch used for music/artist/playlist searching
7. Database operations via PDO in `config.php`

## Key Technologies & Patterns

### Database
- **Connection**: PDO singleton pattern in `src/includes/config.php`
- **Environment Variables**: DB credentials from Docker `.env`
- **Prepared Statements**: Used for all SQL queries to prevent injection

### Authentication
- **Session-based**: PHP `$_SESSION['user']` contains user data
- **Login flow**: `login.php` → `actions/login.php` → redirect to `index.php`
- **Password reset**: Email-based via PHPMailer

### Search
- MeiliSearch instance accessible via environment variable `MS_PASS`
- Handled in `src/includes/initSearch.php`
- Used for music titles, artists, albums

### Logging (journal)
- **Table**: `journal` in the **main** database only. Demo sessions log there too
  (`Config::getConnectionPrincipale()`), otherwise their traces would land in the
  demo database, which the admin section never opens.
- **Writing**: `journaliser($canal, $action, $message, $contexte, $niveau)` plus
  shortcuts `journalInfo/journalAttention/journalErreur/journalCritique`.
  Available everywhere — `auth.php` includes `journal.php`.
- **Never throws**: any failure falls back to `error_log()`. A lost trace must
  never break an import or a playback.
- **Levels**: `debug < info < attention < erreur < critique`. Filtering by a level
  includes everything more severe.
- **Channels**: `auth`, `admin`, `contenu`, `import`, `stockage`, `recherche`,
  `console`, `systeme`.
- **PHP incidents**: `journalInstallerHandlers()` (called from `auth.php`) captures
  uncaught exceptions and fatal errors. Ordinary warnings are deliberately not
  captured — they would drown the signal.
- **Retention**: 90 days, purged at most once a day when the admin section is
  visited (no cron exists in this project). Manual purge on the Journal page.
- **Clock**: MariaDB runs in UTC, PHP in Europe/Paris. Timestamps and SQL filters
  are consistent with each other; only PHP-side display is corrected, via
  `journalDecalageSql()`.

### Admin console
- **Read-only by construction**: no command writes, deletes or modifies anything.
  Destructive operations keep their dedicated pages and confirmations.
- Commands are declared in one table, `consoleCommandes()` in
  `src/includes/console.php` — adding one requires no change elsewhere (help and
  Tab completion both read that table).
- Commands return **typed blocks** (`texte`, `titre`, `tableau`, `paires`,
  `erreur`, `succes`) rendered by `src/scripts/console.js`. Nothing is ever put
  into `innerHTML`: console and journal display attacker-influenced text.
- The `sql` command is an escape hatch guarded by three independent mechanisms:
  read-verb allowlist + single statement, a `START TRANSACTION READ ONLY`
  (MariaDB refuses writes itself), and `max_statement_time`.
- `base principale|demo` switches which database is queried; the journal is always
  read from the main one.

### Frontend
- Vanilla JavaScript (no frameworks)
- CSS with Material Icons from Google Fonts
- Responsive design in progress (mobile version complete, desktop responsive pending)
- Form submissions via `fetch()` to action endpoints

## Configuration

### Environment Variables (docker/.env)
Required variables:
- `DOCKER_TARGET` - Set to `production` or `development`
- `DB_ROOTPASS`, `DB_NAME`, `DB_USER`, `DB_PASS` - Database credentials
- `MS_PASS` - MeiliSearch API key
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS` - Email service (default: Resend SMTP)
- `PORT_EXPOSE` - Port to access the app (default: 8082)

### PHP Configuration
- Development: `docker/php-dev.ini` (error reporting enabled)
- Production: `docker/php-prod.ini` (errors hidden)
- Security settings: `docker/security.ini`

## Development Notes

### Known In-Progress Work
From `notes.md`:
- Frontend responsive design (desktop version) - not yet complete
- JavaScript dynamism enhancements ongoing
- Maintenance phase upcoming

### Ignored Files/Folders
- `.env` files in docker/ (credentials, not committed)
- `vendor/` (Composer dependencies)
- `musics_storage/` (uploaded files)
- `.idea/` (IDE settings)
- `src/prototypes/` (experimental code)

## Common Workflows

### Adding a New Feature
1. Create a new page in `src/pages/feature_name.php` (include session check at top)
2. Create corresponding action in `src/actions/feature_name.php` for API logic
3. Add frontend interactions in `src/scripts/` if needed
4. Database schema changes go in `mysql_init/` for fresh setup

### Working with Databases
- Queries are PDO with prepared statements
- Use `Config::getConnection()` singleton to get PDO instance
- Database initialized on container startup from `mysql_init/` SQL files
- **Prepared statements are native** (`ATTR_EMULATE_PREPARES => false`). Two
  consequences that bite: a named placeholder cannot appear twice in the same
  query, and `LIMIT`/`OFFSET`/`INTERVAL` operands do not accept placeholders —
  bound-check and cast those to `int`, then interpolate.
- `Config::getConnectionPrincipale()` (journal) and `Config::getConnectionDemo()`
  (admin console) bypass the demo routing. Nothing else may use them: the
  automatic routing in `getConnection()` is what keeps demo and real content apart.

### Database migrations
`mysql_init/` is only replayed by Docker on a **blank** database. Schema changes
therefore go in two places:
1. `mysql_init/migrations/NNN_name.sql` — run by hand on a live install, with the
   command documented in the file header;
2. `mysql_init/dump.sql` — so fresh installs get it too.

### Styling Changes
- Main stylesheet: `src/styles/style.css`
- Fonts: Cormorant Garamond and DM Sans from Google Fonts
- Icons: Material Symbols from Google Fonts

## Important Notes
- This is a personal project for internal network music sharing
- No external contributions solicited
- Music storage persists in `musics_storage/` volume
- Email in development uses MailHog; production uses configured SMTP service
- Code is in French; maintain this convention in new code
