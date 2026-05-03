export function sudokuGame(config) {
    return {
        // --- Static puzzle data --------------------------------------------------
        puzzleId:      config.id,
        difficulty:    config.difficulty,
        given:         config.puzzle.map(v => v !== null), // which cells are clues
        solverEnabled: config.solverEnabled ?? false,

        // --- Cell state (81 elements) --------------------------------------------
        // Each: { value: null|1-9, notes: int[] }
        cells: config.puzzle.map(v => ({ value: v, notes: [] })),

        // --- UI state ------------------------------------------------------------
        selected:       null,   // index of selected cell, or null
        notesMode:      false,
        highlightValue: null,   // dim cells that don't share this value

        // --- Game state ----------------------------------------------------------
        conflicts:   [],        // indices of cells currently in conflict
        wrongCells:  [],        // indices the server flagged as wrong
        hintCells:   [],        // indices revealed by hints
        mistakes:    0,         // total erroneous entries this session
        hintsUsed:   0,
        hinting:     false,
        complete:    false,
        stats:       null,      // filled on completion
        history:     [],        // cell snapshots for undo; capped at 50

        // --- Session -------------------------------------------------------------
        sessionId:    null,
        sessionToken: null,
        sessionError: false,
        saveTimer:    null,

        // --- Timer ---------------------------------------------------------------
        elapsed:       0,
        timerInterval: null,
        timerHidden:   false,   // persisted to localStorage

        // =========================================================================

        init() {
            this.sessionToken = this.getOrCreateToken();

            // Restore timer visibility preference before first render.
            if (localStorage.getItem('puzzlebox_timer_hidden') === 'true') {
                this.timerHidden = true;
            }

            this.startSessionRequest();
            this.startTimer();
            document.addEventListener('keydown', e => this.handleKey(e));
        },

        // --- Token ---------------------------------------------------------------

        getOrCreateToken() {
            let token = localStorage.getItem('puzzlebox_token');
            if (!token) {
                token = crypto.randomUUID();
                localStorage.setItem('puzzlebox_token', token);
            }
            return token;
        },

        // --- Session API ---------------------------------------------------------

        async startSessionRequest() {
            try {
                const res = await this.post(`/sudoku/${this.puzzleId}/session`, {
                    session_token: this.sessionToken,
                });

                if (!res.ok) throw new Error(`Session start failed: ${res.status}`);

                const data = await res.json();

                this.sessionId  = data.session_id;
                this.elapsed    = data.elapsed_seconds ?? 0;
                this.mistakes   = data.mistakes ?? 0;
                this.hintsUsed  = data.hints_used ?? 0;

                // Restore saved board state (may differ from the original puzzle if resuming).
                if (data.board_state?.cells) {
                    this.cells = data.board_state.cells.map((v, i) => ({
                        value: v,
                        notes: data.board_state.notes?.[i] ?? [],
                    }));
                    this.checkConflicts();
                }

                // Server is authoritative — clear the local backup.
                localStorage.removeItem(`puzzlebox_board_${this.puzzleId}`);
            } catch (e) {
                console.error('Failed to start session:', e);
                this.sessionError = true;

                // Fall back to localStorage backup so a refresh doesn't wipe progress.
                try {
                    const raw = localStorage.getItem(`puzzlebox_board_${this.puzzleId}`);
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (Array.isArray(parsed.cells) && parsed.cells.length === 81) {
                            this.cells = parsed.cells.map((v, i) => ({
                                value: v,
                                notes: parsed.notes?.[i] ?? [],
                            }));
                            this.checkConflicts();
                        }
                    }
                } catch (_) {
                    // Corrupt backup — silently ignore, existing puzzle_data remains.
                }
            }
        },

        scheduleSave() {
            // Write to localStorage immediately so a refresh never loses the last move.
            localStorage.setItem(
                `puzzlebox_board_${this.puzzleId}`,
                JSON.stringify(this.serialiseBoard())
            );

            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.saveState(), 4000);
        },

        async saveState() {
            if (!this.sessionId) return;
            await this.patch(`/sudoku/sessions/${this.sessionId}`, {
                session_token:   this.sessionToken,
                board_state:     this.serialiseBoard(),
                elapsed_seconds: this.elapsed,
                mistakes:        this.mistakes,
            });
        },

        async submitSolution() {
            const res = await this.post(`/sudoku/sessions/${this.sessionId}/complete`, {
                session_token:   this.sessionToken,
                board_state:     this.serialiseBoard(),
                elapsed_seconds: this.elapsed,
                mistakes:        this.mistakes,
            });
            const data = await res.json();

            if (data.ok) {
                this.complete = true;
                this.stats    = data.stats;
                clearInterval(this.timerInterval);
            } else {
                this.wrongCells = data.wrong_cells ?? [];
            }
        },

        serialiseBoard() {
            return {
                cells: this.cells.map(c => c.value),
                notes: this.cells.map(c => c.notes),
            };
        },

        // --- Cell interaction ----------------------------------------------------

        selectCell(index) {
            if (this.complete) return;
            this.selected       = index;
            this.highlightValue = this.cells[index].value;
            this.wrongCells     = []; // clear server-flagged errors on interaction
        },

        enterValue(num) {
            if (this.selected === null || this.given[this.selected] || this.complete) return;

            const cell = this.cells[this.selected];

            if (this.notesMode) {
                // Push snapshot before toggling a note.
                this.pushHistory();
                const notes = cell.notes.includes(num)
                    ? cell.notes.filter(n => n !== num)
                    : [...cell.notes, num].sort((a, b) => a - b);
                this.cells[this.selected] = { value: null, notes };
                this.scheduleSave();
                return;
            }

            // Entering the same value a second time clears the cell.
            // clearCell() will push its own history snapshot.
            if (cell.value === num) {
                this.clearCell();
                return;
            }

            // Push snapshot before changing the cell value.
            this.pushHistory();

            this.cells[this.selected] = { value: num, notes: [] };
            this.clearRelatedNotes(this.selected, num);
            this.highlightValue = num;

            const wasConflict = this.checkConflicts();
            if (wasConflict) this.mistakes++;

            this.scheduleSave();
            this.checkComplete();
        },

        clearCell() {
            if (this.selected === null || this.given[this.selected] || this.complete) return;

            const cell = this.cells[this.selected];
            if (cell.value !== null) {
                this.pushHistory();
                this.cells[this.selected] = { value: null, notes: cell.notes };
                this.highlightValue = null;
                this.checkConflicts();
            } else if (cell.notes.length > 0) {
                this.pushHistory();
                this.cells[this.selected] = { value: null, notes: [] };
            }
            this.scheduleSave();
        },

        // --- History / Undo ------------------------------------------------------

        pushHistory() {
            this.history.push(JSON.parse(JSON.stringify(this.cells)));
            if (this.history.length > 50) this.history.shift();
        },

        undo() {
            if (this.history.length === 0 || this.complete) return;

            this.cells = this.history.pop();
            this.highlightValue = this.selected !== null
                ? this.cells[this.selected].value
                : null;
            this.checkConflicts();
            this.scheduleSave();
        },

        // --- Conflict detection --------------------------------------------------

        // Returns true if the cell just filled is in conflict.
        checkConflicts() {
            const next = [];
            for (let i = 0; i < 81; i++) {
                if (this.cells[i].value !== null && this.cellHasConflict(i)) {
                    next.push(i);
                }
            }
            this.conflicts = next;
            return next.includes(this.selected);
        },

        cellHasConflict(pos) {
            const val = this.cells[pos].value;
            if (val === null) return false;

            const row    = Math.floor(pos / 9);
            const col    = pos % 9;
            const boxRow = Math.floor(row / 3) * 3;
            const boxCol = Math.floor(col / 3) * 3;

            for (let i = 0; i < 9; i++) {
                const r = row * 9 + i;
                const c = i * 9 + col;
                if (r !== pos && this.cells[r].value === val) return true;
                if (c !== pos && this.cells[c].value === val) return true;
            }

            for (let r = boxRow; r < boxRow + 3; r++) {
                for (let c = boxCol; c < boxCol + 3; c++) {
                    const p = r * 9 + c;
                    if (p !== pos && this.cells[p].value === val) return true;
                }
            }

            return false;
        },

        // --- Pencil mark helpers -------------------------------------------------

        // After confirming a value in a cell, remove that number from notes of
        // every cell in the same row, column, and box.
        clearRelatedNotes(index, value) {
            const ir = Math.floor(index / 9);
            const ic = index % 9;

            for (let i = 0; i < 81; i++) {
                if (i === index) continue;
                if (!this.cells[i].notes.includes(value)) continue;

                const cr = Math.floor(i / 9);
                const cc = i % 9;

                const related = ir === cr ||
                    ic === cc ||
                    (Math.floor(ir / 3) === Math.floor(cr / 3) &&
                     Math.floor(ic / 3) === Math.floor(cc / 3));

                if (related) {
                    this.cells[i] = {
                        value: this.cells[i].value,
                        notes: this.cells[i].notes.filter(n => n !== value),
                    };
                }
            }
        },

        // --- Progress ------------------------------------------------------------

        filledCount() {
            return this.cells.filter(c => c.value !== null).length;
        },

        // --- Completion ----------------------------------------------------------

        checkComplete() {
            const allFilled   = this.cells.every(c => c.value !== null);
            const noConflicts = this.conflicts.length === 0;
            if (allFilled && noConflicts) {
                this.submitSolution();
            }
        },

        // --- Keyboard ------------------------------------------------------------

        handleKey(e) {
            // Undo works regardless of selected cell; check before the selected guard.
            if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
                e.preventDefault();
                this.undo();
                return;
            }

            if (this.selected === null || this.complete) return;

            const arrows = { ArrowUp: -9, ArrowDown: 9, ArrowLeft: -1, ArrowRight: 1 };

            if (e.key in arrows) {
                e.preventDefault();
                const next = this.selected + arrows[e.key];
                if (next >= 0 && next < 81) this.selectCell(next);
                return;
            }

            if (e.key >= '1' && e.key <= '9') {
                e.preventDefault();
                this.enterValue(parseInt(e.key));
                return;
            }

            if (e.key === 'Backspace' || e.key === 'Delete' || e.key === '0') {
                e.preventDefault();
                this.clearCell();
            }
        },

        // --- Cell class helper ---------------------------------------------------

        cellClasses(index) {
            const cell        = this.cells[index];
            const isGiven     = this.given[index];
            const isHint      = this.hintCells.includes(index);
            const isSelected  = this.selected === index;
            const isConflict  = this.conflicts.includes(index);
            const isWrong     = this.wrongCells.includes(index);
            const isRelated   = this.isRelated(index);
            const isSameVal   = this.highlightValue !== null && cell.value === this.highlightValue && !isSelected;

            return {
                // Background
                'bg-blue-200 dark:bg-blue-700':              isSelected,
                'bg-blue-50 dark:bg-blue-900/40':            !isSelected && (isRelated || isSameVal),
                'bg-white dark:bg-gray-800':                  !isSelected && !isRelated && !isSameVal,

                // Text / value colour
                'font-bold text-gray-900 dark:text-gray-100': isGiven && !isHint && !isConflict && !isWrong,
                'font-bold text-amber-500 dark:text-amber-400': isHint && !isConflict && !isWrong,
                'text-blue-600 dark:text-blue-400':           !isGiven && !isConflict && !isWrong && cell.value !== null,
                'text-red-500 dark:text-red-400':             isConflict || isWrong,

                // Interaction — only show hover highlight on cells the player can actually edit
                'cursor-pointer': true,
                'hover:bg-blue-50 dark:hover:bg-blue-900/30': !isSelected && !isRelated && !isSameVal,
            };
        },

        isRelated(pos) {
            if (this.selected === null) return false;
            const sr = Math.floor(this.selected / 9), sc = this.selected % 9;
            const pr = Math.floor(pos / 9),           pc = pos % 9;
            return sr === pr || sc === pc ||
                   (Math.floor(sr / 3) === Math.floor(pr / 3) &&
                    Math.floor(sc / 3) === Math.floor(pc / 3));
        },

        // --- Timer ---------------------------------------------------------------

        startTimer() {
            this.timerInterval = setInterval(() => {
                if (!this.complete) this.elapsed++;
            }, 1000);
        },

        formatTime(s) {
            const m = Math.floor(s / 60).toString().padStart(2, '0');
            const sec = (s % 60).toString().padStart(2, '0');
            return `${m}:${sec}`;
        },

        toggleTimer() {
            this.timerHidden = !this.timerHidden;
            localStorage.setItem('puzzlebox_timer_hidden', this.timerHidden);
        },

        // --- Hint ----------------------------------------------------------------

        async hint() {
            if (!this.sessionId || this.complete || this.hinting) return;
            this.hinting = true;

            try {
                const res  = await this.post(`/sudoku/sessions/${this.sessionId}/hint`, {
                    session_token: this.sessionToken,
                    cells:         this.cells.map(c => c.value),
                    selected:      this.selected,
                });

                if (!res.ok) return;
                const data = await res.json();
                if (!data.ok) return;

                this.cells[data.index] = { value: data.value, notes: [] };
                this.given[data.index] = true; // lock it — hints can't be erased
                this.hintCells = [...this.hintCells, data.index];
                this.hintsUsed++;

                // Clear pencil marks for the revealed value in related cells.
                this.clearRelatedNotes(data.index, data.value);

                this.highlightValue = data.value;
                this.selected       = data.index;
                this.wrongCells     = this.wrongCells.filter(i => i !== data.index);
                this.checkConflicts();
                this.checkComplete();
            } finally {
                this.hinting = false;
            }
        },

        // --- Solver (local debug only) -------------------------------------------

        async solve() {
            if (!this.sessionId || this.complete) return;

            const res  = await this.post(`/sudoku/sessions/${this.sessionId}/solve`, {
                session_token: this.sessionToken,
            });
            const data = await res.json();

            data.solution.forEach((val, i) => {
                if (!this.given[i]) {
                    this.cells[i] = { value: val, notes: [] };
                }
            });

            this.wrongCells = [];
            this.checkConflicts();
            this.checkComplete();
        },

        // --- Fetch helpers -------------------------------------------------------

        post(url, body) {
            return fetch(url, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken },
                body:    JSON.stringify(body),
            });
        },

        patch(url, body) {
            return fetch(url, {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken },
                body:    JSON.stringify(body),
            });
        },
    };
}
