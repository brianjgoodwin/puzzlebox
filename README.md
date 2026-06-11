# Puzzlebox

A lightweight, browser-based puzzle game site. Clean UI, no bloat, optional accounts. Anonymous play works out of the box — creating an account persists scores and streaks across sessions.

Built with Laravel 12, Alpine.js, and Tailwind CSS.

**Games:** Sudoku (live), Cryptogram and KenKen (planned).

---

## Requirements

- PHP 8.4
- Node.js + npm
- MySQL 8.4 (via Docker) **or** SQLite (local dev shortcut)
- Docker (for MySQL)

---

## Local Setup

```bash
# 1. Clone and install dependencies
git clone <repo-url> puzzlebox
cd puzzlebox
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
```

### Option A — SQLite (quickest for local testing)

The default `.env.example` uses SQLite. No Docker needed.

```bash
php artisan migrate
php artisan puzzle:generate easy --count=5
php artisan puzzle:generate medium --count=5
php artisan puzzle:generate hard --count=5
php artisan puzzle:generate expert --count=5
```

### Option B — MySQL via Docker

Edit `.env` and uncomment the MySQL lines:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=puzzlebox
DB_USERNAME=root
DB_PASSWORD=
```

Then:

```bash
docker compose up mysql -d
php artisan migrate
```

---

## Running the App

```bash
# Start the server
php artisan serve --port=8000

# (Optional) Hot-reload assets during frontend work
npm run dev
```

Visit `http://localhost:8000/sudoku`.

---

## Production

The app runs at **https://puzzlebox.brianjgoodwin.dev** on the development server.

- **App process** — `puzzlebox.service` systemd user service (`php artisan serve --host=0.0.0.0 --port=8000`), enabled at boot via `loginctl enable-linger`.
- **Reverse proxy** — Caddy (`~/developer/caddy-proxy`) handles TLS (Let's Encrypt via Cloudflare DNS challenge) and proxies to port 8000.
- **Database** — MySQL 8.4 in Docker (`docker compose up mysql -d`).

### Deploying updates

```bash
# 1. Pull latest code
git pull

# 2. Rebuild frontend assets (required any time Blade templates or CSS/JS change)
npm run build

# 3. Run any new migrations
php artisan migrate --force

# 4. Restart the app
systemctl --user restart puzzlebox
```

---

## Database Security

### Credential model

Two separate MySQL credentials are required:

| Variable | Purpose | Who uses it |
|----------|---------|-------------|
| `DB_ROOT_PASSWORD` | MySQL root account | Container healthcheck only; never used by the app |
| `DB_PASSWORD` | `puzzlebox` app user | Laravel; restricted privileges (no `GRANT`, no `FILE`, `puzzlebox` database only) |

These must be different values. The old setup used one password for both root and the app user — that is what the ransom incident exploited.

### compose.yaml security notes

- The `laravel.test` Sail service has been removed. PHP runs on the host; the container only runs MySQL.
- MySQL is bound to `127.0.0.1:3306` only — never `0.0.0.0`. The `FORWARD_DB_PORT` env var defaults to `3306` if unset.
- The `puzzlebox` app user has `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES` on `puzzlebox.*` only.

### UFW rules

Port 3306 is not open in UFW. Port 8000 (the app) is restricted to the `caddy-proxy` Docker network (`172.18.0.0/16`) — not exposed to the internet directly.

Verify with:

```bash
sudo ufw status verbose
```

### Setting credentials for a fresh install

Generate separate passwords for root and the app user:

```bash
openssl rand -base64 32   # use one value for DB_ROOT_PASSWORD
openssl rand -base64 32   # use a different value for DB_PASSWORD
```

Add both to `.env`:

```env
DB_USERNAME=puzzlebox
DB_PASSWORD=<app-user-password>
DB_ROOT_PASSWORD=<root-password>
```

On first `docker compose up mysql -d` the container initialises the database, creates the `puzzlebox` user, and sets both passwords from these env vars. If the volume already exists from a previous install, destroy it first (`docker compose down -v`) so the init scripts run clean.

---

## HTTP Security

### Security headers

The Caddy reverse proxy sets the following headers on all `puzzlebox.brianjgoodwin.dev` responses:

| Header | Value |
|--------|-------|
| `X-Frame-Options` | `SAMEORIGIN` — prevents clickjacking |
| `X-Content-Type-Options` | `nosniff` — prevents MIME sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — enforces HTTPS |

These are defined as a reusable `(security_headers)` snippet in `~/developer/caddy-proxy/Caddyfile`.

### Rate limiting

All game session API endpoints are throttled at **60 requests per minute per IP** via Laravel's `throttle:60,1` middleware. Login is separately throttled at 5 attempts per minute by Breeze.

### Trusted proxies

`bootstrap/app.php` trusts `X-Forwarded-*` headers only from `172.18.0.0/16` (the caddy-proxy Docker network). This ensures Laravel generates correct HTTPS URLs without trusting arbitrary client-supplied headers.

---

## Local Debug Tools

Set `SUDOKU_SOLVER_ENABLED=true` in `.env` to show a **Solve** button in-game that fills the board instantly. Useful for testing the completion flow. Never set this in production.

A `debug` difficulty (only 3 blank cells) can also be generated locally for quick end-to-end testing:

```bash
php artisan puzzle:generate debug --count=3
```

---

## Running Tests

```bash
php artisan test
```

---

## Documentation

See [DEVELOPMENT.md](DEVELOPMENT.md) for architecture, database schema, puzzle generation details, game flow, routes, and the development roadmap.
