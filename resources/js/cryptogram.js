export function cryptogramGame(config) {
    return {
        // --- Static puzzle data --------------------------------------------------
        puzzleId:    config.id,
        ciphertext:  config.ciphertext,   // e.g. "XYZ QRS..."
        attribution: config.attribution,
        revealed:    config.revealed,     // cipher letters pre-revealed

        // --- Guess state ---------------------------------------------------------
        // Maps cipher letter (A-Z) → player's current guess (single plain letter or null)
        guesses: {},

        // --- UI state ------------------------------------------------------------
        selected:    null,   // currently focused cipher letter, or null
        wrongLetters:   [],  // cipher letters the server flagged as wrong
        hintCells:   [],     // cipher letters revealed via hints

        // --- Game state ----------------------------------------------------------
        hintsUsed:   0,
        hinting:     false,
        complete:    false,
        stats:       null,

        // --- Session -------------------------------------------------------------
        sessionId:    null,
        sessionToken: null,
        sessionError: false,
        saveTimer:    null,

        // --- Timer ---------------------------------------------------------------
        elapsed:       0,
        timerInterval: null,
        timerHidden:   false,

        // =========================================================================

        init() {
            this.sessionToken = this.getOrCreateToken();

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

        // --- Computed helpers ----------------------------------------------------

        // Unique cipher letters that actually appear in the ciphertext (A-Z only).
        cipherLetters() {
            return [...new Set(this.ciphertext.replace(/[^A-Z]/g, '').split(''))].sort();
        },

        // The display value for a cipher letter: the player's guess or the pre-revealed plain letter.
        guessFor(cipherLetter) {
            return this.guesses[cipherLetter] ?? null;
        },

        isRevealed(cipherLetter) {
            return this.revealed.includes(cipherLetter) || this.hintCells.includes(cipherLetter);
        },

        isLocked(cipherLetter) {
            return this.isRevealed(cipherLetter);
        },

        filledCount() {
            return this.cipherLetters().filter(c => this.guesses[c]).length;
        },

        totalUnique() {
            return this.cipherLetters().length;
        },

        // Render the ciphertext with guesses filled in.
        // Returns array of word arrays, each word an array of {cipher, plain, isSpace} tokens.
        parsedWords() {
            const tokens = this.ciphertext.split('').map(ch => {
                const upper = ch.toUpperCase();
                if (/[A-Z]/.test(upper)) {
                    return { cipher: upper, plain: this.guesses[upper] ?? null, isPunct: false };
                }
                return { cipher: ch, plain: ch, isPunct: true };
            });

            // Group into words split on space boundaries.
            const words = [];
            let current = [];
            for (const tok of tokens) {
                if (tok.cipher === ' ') {
                    if (current.length) words.push(current);
                    current = [];
                } else {
                    current.push(tok);
                }
            }
            if (current.length) words.push(current);
            return words;
        },

        // --- Session API ---------------------------------------------------------

        async startSessionRequest() {
            try {
                const res = await this.post(`/cryptogram/${this.puzzleId}/session`, {
                    session_token: this.sessionToken,
                });

                if (!res.ok) throw new Error(`Session start failed: ${res.status}`);

                const data = await res.json();

                this.sessionId = data.session_id;
                this.elapsed   = data.elapsed_seconds ?? 0;
                this.hintsUsed = data.hints_used ?? 0;
                this.guesses   = data.board_state?.guesses ?? {};

                localStorage.removeItem(`puzzlebox_cryptogram_${this.puzzleId}`);
            } catch (e) {
                console.error('Failed to start session:', e);
                this.sessionError = true;

                try {
                    const raw = localStorage.getItem(`puzzlebox_cryptogram_${this.puzzleId}`);
                    if (raw) {
                        const parsed = JSON.parse(raw);
                        if (parsed.guesses) this.guesses = parsed.guesses;
                    }
                } catch (_) {}
            }
        },

        scheduleSave() {
            localStorage.setItem(
                `puzzlebox_cryptogram_${this.puzzleId}`,
                JSON.stringify({ guesses: this.guesses })
            );
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.saveState(), 4000);
        },

        async saveState() {
            if (!this.sessionId) return;
            await this.patch(`/cryptogram/sessions/${this.sessionId}`, {
                session_token:   this.sessionToken,
                board_state:     { guesses: this.guesses },
                elapsed_seconds: this.elapsed,
            });
        },

        async submitSolution() {
            const res = await this.post(`/cryptogram/sessions/${this.sessionId}/complete`, {
                session_token:   this.sessionToken,
                board_state:     { guesses: this.guesses },
                elapsed_seconds: this.elapsed,
            });
            const data = await res.json();

            if (data.ok) {
                this.complete = true;
                this.stats    = data.stats;
                clearInterval(this.timerInterval);
            } else {
                this.wrongLetters = data.wrong_letters ?? [];
            }
        },

        // --- Interaction ---------------------------------------------------------

        selectLetter(cipherLetter) {
            if (this.complete || this.isLocked(cipherLetter)) return;
            this.selected     = cipherLetter;
            this.wrongLetters = [];
        },

        enterGuess(plainLetter) {
            if (this.selected === null || this.isLocked(this.selected) || this.complete) return;

            const upper = plainLetter.toUpperCase();
            if (!/^[A-Z]$/.test(upper)) return;

            this.guesses = { ...this.guesses, [this.selected]: upper };
            this.wrongLetters = this.wrongLetters.filter(l => l !== this.selected);

            this.scheduleSave();
            this.advanceSelection();
            this.checkComplete();
        },

        clearGuess(cipherLetter) {
            if (!cipherLetter || this.isLocked(cipherLetter) || this.complete) return;
            const updated = { ...this.guesses };
            delete updated[cipherLetter];
            this.guesses = updated;
            this.scheduleSave();
        },

        // Move selection to the next unfilled cipher letter.
        advanceSelection() {
            const letters = this.cipherLetters().filter(c => !this.isLocked(c));
            const idx     = letters.indexOf(this.selected);
            if (idx === -1) return;

            const unfilled = letters.slice(idx + 1).find(c => !this.guesses[c]);
            if (unfilled) this.selected = unfilled;
        },

        checkComplete() {
            const allFilled = this.cipherLetters().every(c => this.guesses[c]);
            if (allFilled) this.submitSolution();
        },

        // --- Keyboard ------------------------------------------------------------

        handleKey(e) {
            if (this.selected === null || this.complete) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;

            if (e.key.length === 1 && /^[a-zA-Z]$/.test(e.key)) {
                e.preventDefault();
                this.enterGuess(e.key);
                return;
            }

            if (e.key === 'Backspace' || e.key === 'Delete') {
                e.preventDefault();
                this.clearGuess(this.selected);
            }

            if (e.key === 'ArrowRight' || e.key === 'Tab') {
                e.preventDefault();
                this.moveSelection(1);
            }

            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                this.moveSelection(-1);
            }
        },

        moveSelection(dir) {
            const letters = this.cipherLetters().filter(c => !this.isLocked(c));
            if (!letters.length) return;
            const idx  = letters.indexOf(this.selected);
            const next = (idx + dir + letters.length) % letters.length;
            this.selected = letters[next];
        },

        // --- Hint ----------------------------------------------------------------

        async hint() {
            if (!this.sessionId || this.complete || this.hinting) return;
            this.hinting = true;

            try {
                const res = await this.post(`/cryptogram/sessions/${this.sessionId}/hint`, {
                    session_token: this.sessionToken,
                    guesses:       this.guesses,
                });

                if (!res.ok) return;
                const data = await res.json();
                if (!data.ok) return;

                this.guesses    = { ...this.guesses, [data.cipher]: data.plain };
                this.hintCells  = [...this.hintCells, data.cipher];
                this.hintsUsed++;
                this.wrongLetters = this.wrongLetters.filter(l => l !== data.cipher);
                this.scheduleSave();
                this.checkComplete();
            } finally {
                this.hinting = false;
            }
        },

        // --- Timer ---------------------------------------------------------------

        startTimer() {
            this.timerInterval = setInterval(() => {
                if (!this.complete) this.elapsed++;
            }, 1000);
        },

        formatTime(s) {
            const m   = Math.floor(s / 60).toString().padStart(2, '0');
            const sec = (s % 60).toString().padStart(2, '0');
            return `${m}:${sec}`;
        },

        toggleTimer() {
            this.timerHidden = !this.timerHidden;
            localStorage.setItem('puzzlebox_timer_hidden', this.timerHidden);
        },

        // --- Letter classes ------------------------------------------------------

        letterClasses(cipherLetter) {
            const isSelected = this.selected === cipherLetter;
            const isWrong    = this.wrongLetters.includes(cipherLetter);
            const isHinted   = this.hintCells.includes(cipherLetter);
            const isPreRevealed = this.revealed.includes(cipherLetter);

            return {
                'bg-blue-200 dark:bg-blue-700 border-blue-400':          isSelected,
                'bg-red-50 dark:bg-red-900/30 border-red-400':           !isSelected && isWrong,
                'bg-amber-50 dark:bg-amber-900/20 border-amber-300':     !isSelected && (isHinted || isPreRevealed),
                'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600': !isSelected && !isWrong && !isHinted && !isPreRevealed,
                'cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30': !this.isLocked(cipherLetter) && !this.complete,
                'cursor-default opacity-70': this.isLocked(cipherLetter),
            };
        },

        tokenClasses(cipher) {
            if (!/^[A-Z]$/.test(cipher)) return {};
            return {
                'text-blue-600 dark:text-blue-400': !!this.guesses[cipher] && !this.wrongLetters.includes(cipher) && !this.hintCells.includes(cipher) && !this.revealed.includes(cipher),
                'text-amber-500 dark:text-amber-400': this.hintCells.includes(cipher) || this.revealed.includes(cipher),
                'text-red-500 dark:text-red-400': this.wrongLetters.includes(cipher),
                'text-gray-300 dark:text-gray-600': !this.guesses[cipher],
                'cursor-pointer': true,
            };
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
