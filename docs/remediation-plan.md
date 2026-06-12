# Puzzlebox — Remediation Plan

Based on findings in [audit-2026-06-12.md](audit-2026-06-12.md). Low priority items are noted at the bottom as deferred.

Batches are ordered by impact and logical grouping — items in the same batch touch the same files and should be done together.

---

## Batch A — JS Infrastructure (unlocks everything else)

Both high-priority event listener leaks and the timer cleanup live here. Fixing these first means subsequent JS work starts from a clean foundation. Also extracts shared utilities that later batches depend on.

**Touches:** `resources/js/sudoku.js`, `resources/js/cryptogram.js`, `resources/js/utils.js` (new)

### A1 — Add Alpine `destroy()` hooks to both game components
_Fixes: timer interval leak (High), keydown listener leak (High)_

In both `sudoku.js` and `cryptogram.js`, add a `destroy()` method:
```js
destroy() {
    clearInterval(this.timerInterval);
    clearTimeout(this.saveTimer);
    document.removeEventListener('keydown', this._keyHandler);
},
```

Change the `init()` listener registration to store a named reference:
```js
this._keyHandler = e => this.handleKey(e);
document.addEventListener('keydown', this._keyHandler);
```

### A2 — Extract shared utilities to `resources/js/utils.js`
_Fixes: `getOrCreateToken`/`post`/`patch` duplication (Medium), timer duplication (Medium)_

Create `resources/js/utils.js` exporting:
- `getOrCreateToken()` — shared localStorage token logic
- `post(url, body)` / `patch(url, body)` — fetch wrappers with CSRF header
- `timerMixin` — object with `startTimer()`, `formatTime()`, `toggleTimer()`, `timerHidden`, `elapsed`, `timerInterval`

Import and spread/use in both game files. Remove duplicated code.

---

## Batch B — Input Validation Hardening

All server-side validation gaps in one pass. No UI changes required.

**Touches:** `SudokuController.php`, `CryptogramController.php`

### B1 — Sudoku `board_state` per-element validation
_Fixes: unbounded cell/note values (High)_

In `saveSession` and `completeSession` validation rules, add:
```php
'board_state.cells.*'    => 'nullable|integer|min:1|max:9',
'board_state.notes.*'    => 'array',
'board_state.notes.*.*'  => 'integer|min:1|max:9',
```

### B2 — Cryptogram `guesses` map validation
_Fixes: unbounded guesses map (High)_

In `saveSession`, `completeSession`, and `hintSession` validation rules, add:
```php
'board_state.guesses'   => 'required|array|max:26',
'board_state.guesses.*' => 'nullable|string|size:1|regex:/^[A-Z]$/',
```

### B3 — Clamp `elapsed_seconds` and `mistakes` on save
_Fixes: client-controlled score fabrication (High)_

In both controllers' `saveSession` and `completeSession`, add after validation:
```php
abort_if($data['elapsed_seconds'] < $session->elapsed_seconds, 422);
abort_if(isset($data['mistakes']) && $data['mistakes'] < $session->mistakes, 422);
```
Long-term: compute `elapsed_seconds` server-side from `created_at` + pauses.

### B4 — Fix cryptogram hint to use server-authoritative guesses
_Fixes: hint inflation via empty client payload (Medium)_

In `CryptogramController::hintSession()`, replace:
```php
$guesses = $data['guesses'];
```
with:
```php
$guesses = $session->board_state['guesses'] ?? [];
```
Remove `guesses` from the `hintSession` validation rules entirely.

---

## Batch C — Controller Cleanup

Shared logic, eager loading, and the model mass-assignment gap.

**Touches:** `SudokuController.php`, `CryptogramController.php`, `app/Models/GameSession.php`

### C1 — Extract `authorizeSession` to a shared trait
_Fixes: duplicated authorization logic (Medium)_

Create `app/Http/Concerns/AuthorizesGameSession.php`:
```php
trait AuthorizesGameSession {
    private function authorizeSession(GameSession $session, string $token): void {
        $authorized = auth()->check()
            ? $session->user_id === auth()->id() ||
              ($session->user_id === null && $session->session_token === $token)
            : $session->session_token === $token;
        abort_unless($authorized, 403);
    }
}
```
Use the trait in both controllers.

### C2 — Eager-load puzzle on GameSession; add null guard
_Fixes: N+1 query / missing null guard (High)_

In `app/Models/GameSession.php`, add:
```php
protected $with = ['puzzle'];
```
In each controller action that accesses `$session->puzzle`, add at the top:
```php
abort_unless($session->puzzle, 404);
```

### C3 — Remove `hints_used` from `$fillable`
_Fixes: mass-assignment reset risk (Medium)_

In `GameSession.php`, remove `hints_used` from `$fillable`. It is already only ever touched via `$session->increment('hints_used')` — the `$fillable` entry is dead and dangerous.

### C4 — Fix `SUDOKU_MAX_HINTS` config name and zero-value comment
_Fixes: misleading config name and inverted comment (Medium)_

Rename env var to `PUZZLEBOX_MAX_HINTS` in `config/puzzlebox.php` and `.env.example`. Update comment:
```php
// null = unlimited; 0 = no hints allowed; positive integer = cap
'max_hints' => env('PUZZLEBOX_MAX_HINTS'),
```
Update both controllers to read the renamed key.

### C5 — Batch Sudoku index queries
_Fixes: up to 10 queries per index page load (Medium)_

Replace the per-difficulty loop in `SudokuController::index()` with:
1. One `whereIn('difficulty', $difficulties)->where('publish_date', $today)` query, keyed by difficulty.
2. One fallback `whereIn` for missing difficulties, returning one random puzzle per difficulty.

---

## Batch D — Session Security

Higher-effort security items that require a migration.

**Touches:** `app/Models/GameSession.php`, migration, `routes/web.php`, both controllers, both JS files

### D1 — Add UUID route key to `game_sessions`
_Fixes: sequential ID enumeration (Medium)_

Create a migration adding a `uuid` column:
```php
$table->uuid('uuid')->unique()->after('id');
```

In `GameSession.php`:
```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

protected static function booted(): void {
    static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
}

public function getRouteKeyName(): string { return 'uuid'; }
```

Update route definitions — no other changes needed (route model binding resolves by the new key automatically).

### D2 — Scope per-session token to one session
_Fixes: single shared token authorizes all sessions (High)_

Generate a unique `session_token` server-side in both `startSession` methods instead of trusting the client-supplied token for identity. Return it to the client and store it in `sessionStorage` (not `localStorage`) keyed by puzzle ID, so it is scoped to the tab and not shared across puzzle types.

This is the largest change in scope. Coordinate with D1 as both touch the same session bootstrap path.

---

## Batch E — Accessibility: Keyboard & Focus (Critical)

The three critical findings. These make both games playable for keyboard users.

**Touches:** `sudoku/show.blade.php`, `cryptogram/show.blade.php`, `sudoku.js`, `cryptogram.js`

### E1 — Make Sudoku board keyboard-accessible
_Fixes: non-focusable board cells (Critical), requires-mouse-click-to-start (Medium)_

- Add `role="grid"` to the board `<div>`, `role="row"` wrappers per row, `role="gridcell"` and `tabindex="0"` to each cell.
- Add `:aria-label="\`Row ${row + 1}, column ${col + 1}, ${cells[i].value ?? 'empty'}\`"` to each cell.
- In `sudoku.js` `init()`, add `this.selected = 0` so arrow-key navigation works immediately on page load.
- Add a visible `focus-visible` ring (or leverage the existing `bg-blue-200` selected state) so keyboard focus is clearly visible.

### E2 — Make Cryptogram alphabet tiles keyboard-accessible
_Fixes: non-focusable cipher spans/tiles (Critical), requires-mouse-click-to-start (Medium)_

- Convert the alphabet key `<div>` tiles to `<button>` elements. Remove the `@click="selectLetter(letter)"` handler and replace with a native `button` click.
- In `cryptogram.js` `init()`, add:
  ```js
  const first = this.cipherLetters().find(c => !this.isLocked(c));
  if (first) this.selected = first;
  ```

### E3 — Fix Tab key focus trap in Cryptogram
_Fixes: Tab unconditionally swallowed (Medium)_

In `cryptogram.js` `handleKey()`, change the Tab handler to only cycle within the cipher alphabet, and call `e.preventDefault()` only when cycling (not when the user wants to leave the game area):
```js
if (e.key === 'Tab') {
    // Only intercept if there are unlocked letters to cycle through
    const unlocked = this.cipherLetters().filter(c => !this.isLocked(c));
    if (unlocked.length > 0) {
        e.preventDefault();
        this.moveSelection(e.shiftKey ? -1 : 1);
    }
}
```

### E4 — Fix completion modal: dialog role, focus management, Escape
_Fixes: non-dialog completion modal (Critical)_

In both `show.blade.php` modal inner panels:
- Add `id="completion-modal"`, `role="dialog"`, `aria-modal="true"`, `aria-labelledby="completion-title"`.
- Add `id="completion-title"` to the "Puzzle solved!" heading.

In both JS files, in `submitSolution()` after setting `this.complete = true`:
```js
this.$nextTick(() => {
    document.getElementById('completion-modal')?.focus();
});
```

Add an Escape handler in `handleKey()`:
```js
if (e.key === 'Escape' && this.complete) {
    // allow closing/navigating away — or just ensure focus is inside modal
}
```

---

## Batch F — Accessibility: ARIA & Screen Reader Support

Lower-risk, purely additive ARIA improvements. No logic changes.

**Touches:** `sudoku/show.blade.php`, `cryptogram/show.blade.php`, `layouts/navigation.blade.php`

### F1 — Add live regions for dynamic state changes
_Fixes: no live regions (High)_

- Add `aria-live="polite" aria-atomic="true"` to the session error banner and wrong-answer message divs in both show views.
- Add a visually-hidden `<p aria-live="assertive" x-text="statusMessage">` driven by an Alpine `statusMessage` property. Set it on hint reveals ("Hint revealed: X = Y"), wrong answers, etc.

### F2 — Number pad ARIA labels and pressed state
_Fixes: number-pad buttons unlabeled (High)_

In `sudoku/show.blade.php` digit buttons:
```html
:aria-label="`Enter ${n}`"
:aria-pressed="highlightValue === n"
```

### F3 — Notes mode toggle `aria-pressed`
_Fixes: notes toggle missing pressed state (High)_

Add `:aria-pressed="notesMode"` to the Notes toggle button.

### F4 — Hamburger button label
_Fixes: hamburger unlabeled (High)_

In `navigation.blade.php`:
```html
aria-label="Open navigation menu"
:aria-expanded="open"
```
Add `aria-hidden="true"` to both SVG paths inside the button.

### F5 — Back link arrow hidden from screen readers
_Fixes: arrow character read aloud (Medium)_

In both show views, change `← All puzzles` to:
```html
<span aria-hidden="true">←</span> All puzzles
```

### F6 — Nav dropdown ARIA attributes
_Fixes: dropdown missing haspopup/expanded (Medium)_

In `navigation.blade.php` user dropdown trigger button, add `aria-haspopup="menu"` and `:aria-expanded`. Add `aria-hidden="true"` to the chevron SVG.

### F7 — Cryptogram quote accessible structure
_Fixes: quote tokens have no accessible structure (Medium)_

Wrap the encoded quote container in `role="group" aria-label="Encoded quote"`. Add per-token `:aria-label` binding. Add a visually-hidden reactive `<p>` showing current decoded state.

---

## Batch G — Cryptogram UX Fix

### G1 — Surface `missing_letters` feedback to player
_Fixes: missing_letters returned but never read (Medium)_

In `cryptogram.js` `submitSolution()`, handle the new field:
```js
this.wrongLetters  = data.wrong_letters  ?? [];
this.missingLetters = data.missing_letters ?? [];
```
Add a reactive message to `cryptogram/show.blade.php` distinguishing blank vs. wrong.

---

## Execution Order

| Batch | Effort | Risk | Dependencies |
|-------|--------|------|--------------|
| A — JS Infrastructure | Medium | Low | None |
| B — Input Validation | Low | Low | None |
| C — Controller Cleanup | Low-Medium | Low | None |
| D — Session Security | High | Medium | C |
| E — Keyboard & Focus (Critical) | Medium | Low | A |
| F — ARIA & Screen Reader | Low | Low | E (recommended) |
| G — Cryptogram UX Fix | Low | Low | None |

Suggested sequence: **B → C → A → E → F → G → D**

- B and C are pure hardening with no user-visible change — safest to ship first.
- A fixes leaks and sets up shared utilities needed before touching more JS.
- E fixes the three critical accessibility gaps.
- F layers ARIA on top of the keyboard work from E.
- G is a small self-contained UX fix.
- D (session UUID + token scoping) is the most invasive change and benefits from everything else being stable first.

---

## Deferred — Low Priority

The following findings are acknowledged but not scheduled. Revisit when the above batches are complete.

| # | Finding |
|---|---------|
| 1 | Accessibility: "Unavailable" badge is color-only |
| 2 | Accessibility: Progress counter "X of 81" lacks SR label |
| 3 | Accessibility: "HC" toggle label cryptic on touch |
| 4 | Security: `SESSION_SECURE_COOKIE` not in `.env.example` |
| 5 | Security: Solver endpoint has no auth middleware |
| 6 | Security: `elapsed_seconds`/`mistakes` have no upper-bound (`max:`) validation |
| 7 | Code Quality: `fillGrid` backtrack signal uses `!empty()` — prefer `null` |
| 8 | Code Quality: Arrow-key left/right wraps across row boundaries |
| 9 | Code Quality: `highContrast` preference not carried over to Cryptogram |
| 10 | Code Quality: `puzzle:generate --schedule` gives raw exception on duplicate-date collision |
