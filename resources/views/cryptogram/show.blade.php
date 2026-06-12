<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('cryptogram.index') }}"
                   class="shrink-0 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                    ← All puzzles
                </a>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight truncate">
                    Cryptogram
                </h2>
            </div>
            <span class="shrink-0 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                         bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                Standard
            </span>
        </div>
    </x-slot>

    @php
        $gameConfig = [
            'id'          => $puzzle->id,
            'ciphertext'  => $puzzle->puzzle_data['ciphertext'],
            'attribution' => $puzzle->puzzle_data['attribution'],
            'revealed'    => $puzzle->puzzle_data['revealed'],
        ];
    @endphp

    <div
        class="py-8"
        x-data="cryptogramGame(@js($gameConfig))"
    >
        <div class="max-w-2xl mx-auto px-4 space-y-6">

            {{-- Session error banner --}}
            <div
                x-show="sessionError"
                x-cloak
                class="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-300 text-center"
            >
                Could not connect to the server. Progress won't be saved — try refreshing the page.
            </div>

            {{-- Status bar --}}
            <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                <div class="flex items-center gap-1.5">
                    <span
                        x-show="!timerHidden"
                        class="font-mono text-lg font-semibold tabular-nums"
                        x-text="formatTime(elapsed)"
                    >00:00</span>
                    <span
                        x-show="timerHidden"
                        class="font-mono text-lg font-semibold text-gray-300 dark:text-gray-600"
                        aria-hidden="true"
                    >--:--</span>
                    <button
                        @click="toggleTimer()"
                        class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                        :aria-label="timerHidden ? 'Show timer' : 'Hide timer'"
                        x-text="timerHidden ? 'Show' : 'Hide'"
                    ></button>
                </div>

                <span class="tabular-nums text-gray-500 dark:text-gray-400"
                      x-text="`${filledCount()} of ${totalUnique()} letters`"></span>
            </div>

            {{-- Wrong letters message --}}
            <div
                x-show="wrongLetters.length > 0"
                x-cloak
                class="text-center text-red-500 text-xs font-medium -mt-3"
            >
                Some letters are wrong — keep looking
            </div>

            {{-- Encoded quote --}}
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex flex-wrap gap-x-3 gap-y-4 font-mono leading-loose justify-center">
                    <template x-for="(word, wi) in parsedWords()" :key="wi">
                        <div class="flex gap-0.5">
                            <template x-for="(tok, ti) in word" :key="ti">
                                <div class="flex flex-col items-center">
                                    {{-- Plain-text row (player's guess) --}}
                                    <span
                                        class="text-sm font-semibold w-6 text-center leading-none mb-0.5 transition-colors"
                                        :class="tokenClasses(tok.cipher)"
                                        x-text="tok.isPunct ? tok.cipher : (guesses[tok.cipher] ?? '_')"
                                        @click="!tok.isPunct && selectLetter(tok.cipher)"
                                    ></span>
                                    {{-- Cipher letter --}}
                                    <span
                                        class="text-xs w-6 text-center text-gray-400 dark:text-gray-500 leading-none"
                                        x-text="tok.isPunct ? '' : tok.cipher"
                                    ></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <p class="text-right text-xs text-gray-400 dark:text-gray-500 mt-4 italic"
                   x-text="`— ${attribution}`"></p>
            </div>

            {{-- Alphabet key --}}
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-3 text-center">
                    Cipher alphabet — click a letter to edit
                </p>
                <div class="flex flex-wrap gap-2 justify-center">
                    <template x-for="letter in cipherLetters()" :key="letter">
                        <div
                            class="flex flex-col items-center w-10 rounded-lg border px-1 py-1.5 transition-colors"
                            :class="letterClasses(letter)"
                            @click="selectLetter(letter)"
                        >
                            <span class="text-xs text-gray-400 dark:text-gray-500 leading-none">
                                <span x-text="letter"></span>
                            </span>
                            <span
                                class="text-sm font-bold leading-none mt-0.5"
                                :class="{
                                    'text-gray-800 dark:text-gray-200': !wrongLetters.includes(letter),
                                    'text-red-500': wrongLetters.includes(letter),
                                    'text-gray-300 dark:text-gray-600': !guesses[letter],
                                }"
                                x-text="guesses[letter] ?? '?'"
                            ></span>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Controls: hint --}}
            <div class="flex justify-center gap-3">
                <button
                    @click="hint()"
                    :disabled="complete || hinting"
                    class="px-6 py-1.5 text-sm font-medium rounded-full
                           bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                           border border-gray-300 dark:border-gray-600
                           hover:bg-amber-50 dark:hover:bg-amber-900/20 hover:text-amber-600
                           disabled:opacity-40 disabled:cursor-not-allowed
                           transition-colors"
                >
                    Hint<span x-show="hintsUsed > 0" x-text="` (${hintsUsed})`"></span>
                </button>
            </div>

        </div>

        {{-- Completion modal --}}
        <div
            x-show="complete"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="fixed inset-0 flex items-center justify-center z-50"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

            <div
                x-show="complete"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                style="transition-delay: 100ms"
                class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 mx-4 max-w-sm w-full text-center"
            >
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">
                    Puzzle solved!
                </h2>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">
                    Cryptogram &mdash; #{{ $puzzle->id }}
                </p>

                <dl class="grid grid-cols-2 gap-4 mb-8">
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-4">
                        <dt class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Time</dt>
                        <dd class="text-2xl font-mono font-bold text-gray-900 dark:text-gray-100"
                            x-text="stats ? formatTime(stats.elapsed_seconds) : '--:--'"></dd>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-4">
                        <dt class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Hints</dt>
                        <dd class="text-2xl font-bold text-gray-900 dark:text-gray-100"
                            x-text="stats ? stats.hints_used : 0"></dd>
                    </div>
                </dl>

                <a
                    href="{{ route('cryptogram.index') }}"
                    class="block w-full py-3 px-6 bg-indigo-600 hover:bg-indigo-700
                           text-white font-semibold rounded-xl transition-colors"
                >
                    Play another
                </a>
            </div>
        </div>

    </div>
</x-app-layout>
