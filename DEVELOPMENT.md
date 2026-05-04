# Puzzlebox — Development Notes

A lightweight, browser-based puzzle game site. Think NYTimes Games in spirit: clean UI, no bloat, optional accounts. Anonymous play works out of the box; creating an account persists scores and streaks across sessions.

**Planned games:** Sudoku (active), Cryptogram, KenKen.

---

## Tech Stack

| Layer | Choice | Why |
|-------|--------|-----|
| Framework | Laravel 12 | Learning project; great conventions for this kind of data-driven app |
| Auth scaffolding | Laravel Breeze (Blade stack) | Minimal — gives login/register/profile with no heavy JS framework |
| Frontend | Blade + Alpine.js + Tailwind CSS | Lightweight; Alpine handles interactivity without a full SPA |
| Database | MySQL 8.4 | Runs in Docker (see below); production-realistic |
| PHP | 8.4 (system install) | Runs locally, not in Docker — see infrastructure note |

---

## Infrastructure

### Why PHP runs locally (not in Docker)

Laravel Sail's standard Docker image builds by pulling PHP extensions from the Ondrej PPA (`ppa.launchpadcontent.net`). That host is unreachable from inside Docker containers on this server due to a network restriction. Rather than fight it, the setup uses:

- **MySQL** — runs in Docker via `compose.yaml`
- **PHP / Laravel** — runs on the host's system PHP 8.4 (already installed)

This is a perfectly valid dev setup. The `docker/8.4/` directory contains a stripped-down Dockerfile for future reference, but it isn't used in local development.

### Starting the dev environment

```bash
cd ~/developer/puzzlebox

# 1. Start the database
docker compose up mysql -d

# 2. Start the app server
php artisan serve --port=8000

# 3. (Optional) Hot-reload CSS/JS during active frontend work
npm run dev
```

The app is then available at `http://localhost:8000`.

To access it from a local browser over SSH, use port forwarding:

```bash
ssh -L 8000:localhost:8000 your-server
```

### Stopping

```bash
# Ctrl+C in the terminal running `php artisan serve`
docker compose down
```

---

## Production Infrastructure

The app is live at **https://puzzlebox.brianjgoodwin.dev**.

### Components

- **App process** — `~/.config/systemd/user/puzzlebox.service` runs `php artisan serve --host=0.0.0.0 --port=8000`. Enabled at boot via `loginctl enable-linger brian`. Manage with `systemctl --user {start,stop,restart,status} puzzlebox`.
- **Reverse proxy** — Caddy (`~/developer/caddy-proxy/Caddyfile`) proxies `puzzlebox.brianjgoodwin.dev` → `host.docker.internal:8000`. TLS is automatic via Let's Encrypt (Cloudflare DNS-01 challenge).
- **Database** — MySQL 8.4 in Docker, persistent volume `sail-mysql`. Start with `docker compose up mysql -d` from the project root.
- **Firewall** — UFW is active. Port 8000 is intentionally not open to the internet; a rule allows the `caddy-proxy` Docker network (`172.18.0.0/16`) to reach it.

### Trusted proxies

`bootstrap/app.php` sets `trustProxies(at: '*')` so that Laravel respects the `X-Forwarded-Proto: https` header from Caddy and generates correct HTTPS asset URLs.

### Deploying updates

```bash
git pull
npm run build                  # always rebuild when Blade or CSS/JS files change
php artisan migrate --force    # if there are new migrations
php artisan optimize           # rebuilds route + config caches
systemctl --user restart puzzlebox
```

**Important:** Always run `npm run build` after pulling — Tailwind's production build only includes classes found in the source files at build time. Skipping it will cause missing styles.

**Important:** Always run `php artisan optimize` after pulling — Laravel caches routes and config at deploy time. A stale route cache will silently 404 any routes added since the last cache rebuild.

### Networking note

The Caddy container is on the `caddy-proxy_default` Docker network (gateway `172.18.0.1`). `host.docker.internal` is mapped to that gateway via `extra_hosts` in `~/developer/caddy-proxy/docker-compose.yml`. If the caddy-proxy network is ever recreated and gets a different subnet, update that mapping.

When editing `~/developer/caddy-proxy/Caddyfile`, use `tee` to write in-place rather than a standard editor — atomic file writes (new inode) are not picked up by the running container without a restart:

```bash
# Safe in-place edit
tee ~/developer/caddy-proxy/Caddyfile > /dev/null << 'EOF'
...
EOF
docker compose -f ~/developer/caddy-proxy/docker-compose.yml exec caddy caddy reload --config /etc/caddy/Caddyfile
```

---

## Project Structure

```
puzzlebox/
├── app/
│   ├── Console/Commands/
│   │   └── GeneratePuzzles.php     # puzzle:generate artisan command
│   ├── Http/Controllers/
│   │   ├── Auth/                   # Breeze auth controllers
│   │   ├── ProfileController.php
│   │   └── SudokuController.php    # Game routes: index, show, session start/save/hint/complete/solve
│   ├── Models/
│   │   ├── GameSession.php         # Tracks a play session (anonymous or authed)
│   │   ├── Puzzle.php              # Puzzle definitions and solutions
│   │   └── User.php
│   └── Services/
│       └── Sudoku/
│           ├── Generator.php       # Generates valid puzzles by difficulty
│           └── Solver.php          # Backtracking solver used by Generator + validation
├── database/
│   └── migrations/
│       ├── ..._create_users_table.php
│       ├── ..._create_puzzles_table.php
│       ├── ..._create_game_sessions_table.php
│       └── ..._add_debug_difficulty_to_puzzles_table.php
├── docker/
│   └── 8.4/                        # Custom Dockerfile (not used in local dev)
├── resources/
│   ├── js/
│   │   ├── app.js                  # Alpine.js init; registers sudokuGame globally
│   │   ├── bootstrap.js            # Sets window.csrfToken for fetch requests
│   │   └── sudoku.js               # Alpine component: board state, input, autosave, timer
│   └── views/
│       ├── layouts/
│       │   ├── app.blade.php
│       │   └── navigation.blade.php  # Handles guest and auth nav states
│       └── sudoku/
│           ├── index.blade.php     # Daily puzzle landing page — four difficulty cards
│           └── show.blade.php      # 9×9 board, number pad, hint, completion modal
├── tests/
│   └── Unit/Services/Sudoku/
│       ├── GeneratorTest.php       # 20 tests across all 4 difficulties
│       └── SolverTest.php          # 17 tests: solve, uniqueness, validation, placement
└── compose.yaml                    # Docker Compose — MySQL service only
```

---

## Database Schema

### `puzzles`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | Primary key |
| `type` | enum | `sudoku`, `cryptogram`, `kenken` |
| `difficulty` | enum | `debug`, `easy`, `medium`, `hard`, `expert` |
| `puzzle_data` | json | Starting state. For Sudoku: 81-element array, `null` = blank cell |
| `solution_data` | json | Complete solved state — never sent to the client |
| `publish_date` | date | Optional. Unique per `(type, difficulty, publish_date)` — enables one puzzle per difficulty per day |
| `created_at/updated_at` | timestamps | |

### `game_sessions`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | Primary key |
| `puzzle_id` | bigint FK | References `puzzles` |
| `user_id` | bigint FK, nullable | `null` = anonymous player |
| `session_token` | varchar(64) | UUID stored in `localStorage`; identifies anonymous players |
| `board_state` | json | `{ cells: [null\|1-9, …×81], notes: [[…], …×81] }` |
| `hints_used` | smallint | Count of hints requested |
| `mistakes` | smallint | Total count of conflicting entries made |
| `elapsed_seconds` | int | Total time played |
| `is_completed` | boolean | Whether the puzzle was successfully finished |
| `completed_at` | timestamp, nullable | |
| `created_at/updated_at` | timestamps | |

**Indexes:** `(session_token, puzzle_id)` and `(user_id, puzzle_id)`.

### Anonymous → Authenticated flow

A UUID `session_token` is generated on first visit and stored in `localStorage`. All game sessions (anonymous or not) are keyed to this token. If the player logs in, `user_id` is set on the session — linking their history without losing progress.

---

## Puzzle Generation

Puzzles are pre-generated and stored in the database. Generation uses a two-phase backtracking algorithm:

1. **Fill** — a blank grid is filled with a valid solution using randomised backtracking.
2. **Remove** — cells are removed one at a time in random order. After each removal the solver checks that exactly one solution still exists. Cells that would break uniqueness are restored.

**Target clue counts by difficulty:**

| Difficulty | Target clues | Cells removed | Notes |
|------------|-------------|---------------|-------|
| Debug | ~78 | ~3 | Local testing only |
| Easy | ~46 | ~35 | |
| Medium | ~34 | ~47 | |
| Hard | ~28 | ~53 | |
| Expert | ~24 | ~57 | |

### Generating puzzles

```bash
# Generate one puzzle
php artisan puzzle:generate easy

# Generate a batch
php artisan puzzle:generate hard --count=10

# Generate and schedule (assigns sequential publish_date values)
php artisan puzzle:generate hard --count=10 --schedule
```

Valid difficulties: `easy`, `medium`, `hard`, `expert`. Puzzles are added to the `puzzles` table and immediately available to players.

The `--schedule` flag assigns sequential `publish_date` values starting the day after the last scheduled puzzle for that difficulty (or today if none exist). Each difficulty's schedule is independent.

`debug` is also a valid difficulty (only 3 blank cells) but should not be generated on the production server.

### Initial puzzle bank seeding (production)

Run once on the remote server to seed ~60 days of puzzles per difficulty:

```bash
php artisan puzzle:generate easy   --count=60 --schedule
php artisan puzzle:generate medium --count=60 --schedule
php artisan puzzle:generate hard   --count=60 --schedule
php artisan puzzle:generate expert --count=60 --schedule
```

Expert puzzles take the longest to generate — allow a few minutes. To top up the bank later, run the same commands again with the desired count; the schedule will extend from where it left off.

---

## Sudoku Game Flow

1. `GET /sudoku` — landing page showing today's puzzle for each difficulty. Prefers a puzzle with a matching `publish_date`; falls back to a random unscheduled puzzle if none exists.
2. `GET /sudoku/{puzzle}` — renders the board; puzzle data (no solution) embedded in the page via `@js()`.
3. On load, Alpine calls `POST /sudoku/{puzzle}/session` with the browser's `session_token`. The server finds an existing incomplete session or creates a new one and returns the saved board state.
4. As the player fills cells, conflicts are highlighted client-side (same row/column/box check).
5. Board state is autosaved via `PATCH /sudoku/sessions/{session}` after a 4-second debounce.
6. The player can request a hint at any time via `POST /sudoku/sessions/{session}/hint`. The server reveals one correct cell value (preferring the selected cell if empty/wrong, otherwise random) and increments `hints_used`. Hint cells are locked and rendered in amber.
7. When all 81 cells are filled with no visible conflicts, Alpine calls `POST /sudoku/sessions/{session}/complete`. The server compares against the stored solution and returns either success (with stats) or a list of wrong cell indices (without revealing the correct values).

### Sudoku routes

| Method | Path | Action |
|--------|------|--------|
| GET | `/sudoku` | Landing page — today's puzzle per difficulty |
| GET | `/sudoku/{puzzle}` | Show the game board |
| POST | `/sudoku/{puzzle}/session` | Start or resume a session |
| PATCH | `/sudoku/sessions/{session}` | Autosave board state |
| POST | `/sudoku/sessions/{session}/hint` | Reveal one correct cell |
| POST | `/sudoku/sessions/{session}/complete` | Validate and complete |
| POST | `/sudoku/sessions/{session}/solve` | Fill entire board (local debug only — gated by `SUDOKU_SOLVER_ENABLED`) |

---

## Authentication

Provided by Laravel Breeze. The nav shows Login/Register for guests and a user dropdown for authenticated users.

| Route | Description |
|-------|-------------|
| `GET /register` | Create account |
| `GET /login` | Sign in |
| `GET /dashboard` | Authenticated landing page |
| `GET /profile` | Edit profile / delete account |

The full game is playable without an account.

---

## Running Tests

```bash
# All tests
php artisan test

# Sudoku unit tests only
php artisan test tests/Unit/Services/Sudoku/

# With coverage (requires Xdebug)
php artisan test --coverage
```

Current test count: **37 tests, 499 assertions** — all passing.

---

## Development Roadmap

The project ships games one at a time. Sudoku goes live before Cryptogram is started.

---

### Phase 1 — Sudoku: Complete (current)

The game engine is built. This phase finishes the player-facing experience.

- [x] Puzzle generation service (`Solver`, `Generator`)
- [x] `puzzle:generate` artisan command (with `--schedule` flag)
- [x] Database schema (`puzzles`, `game_sessions`)
- [x] Game controller and routes
- [x] Interactive board — cell selection, keyboard nav, conflict detection, notes mode
- [x] Session persistence — autosave, resume, anonymous + auth support
- [x] Server-side solution validation
- [x] Completion modal with time, mistakes, and hints
- [x] Browser testing and bug fixes
- [x] Daily puzzle infrastructure — `/sudoku` landing page shows today's puzzle per difficulty; falls back to random unscheduled puzzle
- [x] Puzzle bank seeding — 60 puzzles per difficulty scheduled on the production server
- [x] Hint system — reveals one correct cell (selected cell preferred); amber visual indicator; tracked in `hints_used`
- [ ] **Activity heatmap** — calendar-style view of days played (data already in `completed_at`); shown on the user profile page
- [ ] **User game history** — list of past completed games with time, mistakes, and hints used
- [ ] **Open decision: free-play mode** — whether players can generate or access additional puzzles beyond today's. Affects the puzzle picker UI design.

#### Already implemented (noted here to avoid re-work)

- [x] **Conflict highlighting** — `checkConflicts()` runs synchronously inside `enterValue()`; clashing cells turn red immediately
- [x] **Keyboard navigation** — `handleKey()` handles arrow keys (move between cells), 1–9 (enter value), Backspace/Delete/0 (clear)

#### Sudoku UX — Batch 1 (Alpine/JS only, no server changes)

- [x] **Progress indicator** — "37 of 81 filled" counter computed from cell state; displayed in the status bar
- [x] **Hideable timer** — toggle to hide the clock without stopping it; preference persisted to `localStorage`
- [x] **localStorage board backup** — write board state to `localStorage` on every save; restore it if the session API fails on load, so a refresh never wipes progress
- [x] **Auto-remove pencil marks** — when a number is confirmed in a cell, remove that value from notes in every cell in the same row, column, and box
- [x] **Undo button** — maintain a history stack in Alpine state; each `enterValue()` and `clearCell()` pushes a snapshot; Undo pops and restores
- [x] **Completion celebration** — subtle CSS animation on the completion modal (e.g. fade-in scale, or lightweight confetti); rewarding without being jarring

#### Sudoku UX — Batch 2 (template + minor JS)

- [x] **Hide debug difficulty on production** — `SudokuController::index()` filters `debug` when `app()->environment('production')`
- [x] **Remove live mistakes counter** — removed from the status bar; count still shown in the completion modal as "Corrections"
- [x] **Larger cells on mobile** — `w-8 h-8` (base/320px) → `sm:w-10 sm:h-10` → `md:w-11 md:h-11`; sized so the full board fits a 320px screen without horizontal scroll
- [x] **"Back to puzzles" link** — `← All puzzles` added to the game page header, left of the title
- [x] **Positive completion framing** — relabel "Mistakes" in the stats modal to "Corrections" (or remove the label entirely); frame completion as an achievement
- [x] **High-contrast / large-print mode** — `HC` toggle in status bar; sets `data-high-contrast` attribute on the game wrapper; enlarges and bolds cell values and note digits via CSS; preference persisted to `localStorage`
- [x] **Print-friendly CSS** — `@media print` in `app.css` hides nav, header, status bar, and controls; shows only the board

#### Sudoku UX — Batch 3 (new server endpoint)

- [x] **Check puzzle button** — `POST /sudoku/sessions/{session}/check`; compares filled cells against the stored solution, returns wrong cell indices, does not modify the session; empty cells are skipped (partial boards allowed); wired to a "Check" button in the controls row

---

### Phase 2 — Deploy Sudoku

Put the game in front of real users before adding new games. This phase is about production-readiness, not new features.

- [x] **Production environment** — Caddy as reverse proxy in front of `php artisan serve`; SSL via Let's Encrypt (Cloudflare DNS-01 challenge); systemd user service for the app process
- [x] **Domain + DNS** — live at `puzzlebox.brianjgoodwin.dev`
- [x] **Environment hardening** — `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=error`, trusted proxies configured
- [x] **Puzzle bank seeded** — 60 puzzles per difficulty scheduled from 2026-05-03
- [x] **Error pages** — `resources/views/errors/404.blade.php` and `500.blade.php`; self-contained HTML (no Vite/layout dependency so they render even if the app is broken); dark-mode aware via `prefers-color-scheme`
- [x] **Rate limiting** — `throttle` middleware on all game write endpoints: session start 20/min, autosave 60/min, complete/check 20/min, hint 30/min, solve 10/min
- [ ] **Email verification** — All Breeze plumbing is already in place (`verify-email` view, `VerifyEmailController`, routes). Two steps remain:
  1. Uncomment `MustVerifyEmail` on `User` model (`app/Models/User.php:5`)
  2. Add `verified` middleware to any routes that should require a verified email (currently only `dashboard` — the game itself should remain open to unverified users)
  3. Configure a real mailer in `.env` (see Queue worker note below) — without this, verification emails go to the log, not the user's inbox
- [ ] **Queue worker** — `QUEUE_CONNECTION=database` and `MAIL_MAILER=log` are set in `.env`. Before email verification is useful:
  1. Run `php artisan queue:table && php artisan migrate` to create the `jobs` table (if not already done)
  2. Configure a real mail provider in `.env` (Resend, Mailgun, or SMTP — update `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`)
  3. Create a systemd user service (e.g. `~/.config/systemd/user/puzzlebox-queue.service`) running `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`; enable with `loginctl enable-linger` already in place
- [ ] **Basic monitoring** — Netdata is running. Recommended additions:
  - Confirm an alert fires if the `puzzlebox.service` process goes down (Netdata `go.d/systemdunits` collector)
  - Consider a simple external uptime check (e.g. UptimeRobot free tier pinging `https://puzzlebox.brianjgoodwin.dev/up`) for off-server visibility

---

### Phase 3 — Cryptogram

A cryptogram substitutes each letter in a quote with a different letter (A→M, B→X, etc.). The player figures out the mapping.

- [ ] **Quote bank** — import a set of public domain quotes from Project Gutenberg; store as a seedable table
- [ ] **Puzzle model** — extend `puzzles` table or use `puzzle_data` JSON to store the cipher mapping and scrambled text
- [ ] **Generator** — pick a quote, generate a random letter-substitution cipher, verify it's solvable and unambiguous
- [ ] **Interactive board** — click a cipher letter, type the decoded letter; very different UI from Sudoku but same session/autosave pattern
- [ ] **Validation** — server-side check against the original quote (same pattern as Sudoku)
- [ ] **Daily puzzle** — plug into the same `publish_date` infrastructure from Phase 1

---

### Phase 4 — KenKen (someday / maybe)

KenKen uses a grid like Sudoku but cells are grouped into "cages" with arithmetic targets (e.g. three cells that must multiply to 12). Significantly more complex to generate and validate than either Sudoku or Cryptogram. Deferred until the first two games are stable.

---

### Future considerations (no phase assigned)

- Public opt-in leaderboard
- Shareable completion card ("I solved today's Hard Sudoku in 4:23 with 0 mistakes")
- Push/email notifications for daily puzzles
- Mobile app wrapper (PWA or native)
