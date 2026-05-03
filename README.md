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
