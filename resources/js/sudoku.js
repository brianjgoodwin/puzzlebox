export function sudokuGame(config) {
    return {
        // --- Static puzzle data --------------------------------------------------
        puzzleId:   config.id,
        difficulty: config.difficulty,
        given:      config.puzzle.map(v => v !== null), // which cells are clues

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
        mistakes:    0,         // total erroneous entries this session
        complete:    false,
        stats:       null,      // filled on completion

        // --- Session -------------------------------------------------------------
        sessionId:    null,
        sessionToken: null,
        saveTimer:    null,

        // --- Timer ---------------------------------------------------------------
        elapsed:       0,
        timerInterval: null,

        // =========================================================================

        init() {
            this.sessionToken = this.getOrCreateToken();
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
            const res = await this.post(`/sudoku/${this.puzzleId}/session`, {
                session_token: this.sessionToken,
            });
            const data = await res.json();

            this.sessionId = data.session_id;
            this.elapsed   = data.elapsed_seconds ?? 0;
            this.mistakes  = data.mistakes ?? 0;

            // Restore saved board state (may differ from the original puzzle if resuming).
            if (data.board_state?.cells) {
                this.cells = data.board_state.cells.map((v, i) => ({
                    value: v,
                    notes: data.board_state.notes?.[i] ?? [],
                }));
                this.checkConflicts();
            }
        },

        scheduleSave() {
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
                cell.value = null; // can't have value and notes simultaneously
                const idx = cell.notes.indexOf(num);
                if (idx === -1) {
                    cell.notes = [...cell.notes, num].sort((a, b) => a - b);
                } else {
                    cell.notes = cell.notes.filter(n => n !== num);
                }
                this.scheduleSave();
                return;
            }

            // Entering the same value a second time clears the cell.
            if (cell.value === num) {
                this.clearCell();
                return;
            }

            cell.value  = num;
            cell.notes  = [];
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
                cell.value          = null;
                this.highlightValue = null;
                this.checkConflicts();
            } else {
                cell.notes = [];
            }
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
                'font-bold text-gray-900 dark:text-gray-100': isGiven && !isConflict && !isWrong,
                'text-blue-600 dark:text-blue-400':           !isGiven && !isConflict && !isWrong && cell.value !== null,
                'text-red-500 dark:text-red-400':             isConflict || isWrong,

                // Interaction
                'cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30': !isSelected,
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
