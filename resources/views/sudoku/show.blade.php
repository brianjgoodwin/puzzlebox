<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Sudoku
            </h2>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                {{ match($puzzle->difficulty) {
                    'easy'   => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                    'medium' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                    'hard'   => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                    'expert' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                    default  => 'bg-gray-100 text-gray-800',
                } }}">
                {{ ucfirst($puzzle->difficulty) }}
            </span>
        </div>
    </x-slot>

    @php
        $gameConfig = [
            'id'         => $puzzle->id,
            'difficulty' => $puzzle->difficulty,
            'puzzle'     => $puzzle->puzzle_data,
        ];
    @endphp

    <div
        class="py-8"
        x-data="sudokuGame(@js($gameConfig))"
        x-init="init()"
    >
        <div class="max-w-lg mx-auto px-4 space-y-6">

            {{-- Timer + status bar --}}
            <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                <span class="font-mono text-lg font-semibold" x-text="formatTime(elapsed)">00:00</span>
                <span x-show="mistakes > 0" x-cloak>
                    Mistakes: <span x-text="mistakes" class="font-semibold text-red-500"></span>
                </span>
                <template x-if="wrongCells.length > 0">
                    <span class="text-red-500 text-xs font-medium">Something's not right — keep looking</span>
                </template>
            </div>

            {{-- Board --}}
            <div class="flex justify-center">
                <div class="inline-grid grid-cols-9 border-2 border-gray-800 dark:border-gray-200">
                    @for ($i = 0; $i < 81; $i++)
                        @php
                            $row = intdiv($i, 9);
                            $col = $i % 9;

                            // Internal right border: thick after columns 2 and 5
                            $borderR = ($col === 2 || $col === 5)
                                ? 'border-r-2 border-r-gray-800 dark:border-r-gray-200'
                                : ($col < 8 ? 'border-r border-r-gray-300 dark:border-r-gray-600' : '');

                            // Internal bottom border: thick after rows 2 and 5
                            $borderB = ($row === 2 || $row === 5)
                                ? 'border-b-2 border-b-gray-800 dark:border-b-gray-200'
                                : ($row < 8 ? 'border-b border-b-gray-300 dark:border-b-gray-600' : '');
                        @endphp
                        <div
                            class="relative w-10 h-10 sm:w-11 sm:h-11 flex items-center justify-center
                                   select-none transition-colors duration-75
                                   {{ $borderR }} {{ $borderB }}"
                            :class="cellClasses({{ $i }})"
                            @click="selectCell({{ $i }})"
                        >
                            {{-- Filled value --}}
                            <span
                                x-show="cells[{{ $i }}].value !== null"
                                x-text="cells[{{ $i }}].value"
                                class="text-base leading-none"
                            ></span>

                            {{-- Pencil marks (3×3 mini grid) --}}
                            <div
                                x-show="cells[{{ $i }}].value === null && cells[{{ $i }}].notes.length > 0"
                                class="absolute inset-0 grid grid-cols-3 p-px"
                            >
                                @for ($n = 1; $n <= 9; $n++)
                                    <span
                                        x-text="cells[{{ $i }}].notes.includes({{ $n }}) ? '{{ $n }}' : ''"
                                        class="text-[7px] text-center text-gray-400 dark:text-gray-500 leading-tight"
                                    ></span>
                                @endfor
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Controls --}}
            <div class="space-y-3">
                {{-- Notes toggle --}}
                <div class="flex justify-center">
                    <button
                        @click="notesMode = !notesMode"
                        :class="notesMode
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600'"
                        class="px-4 py-1.5 text-sm font-medium border rounded-full transition-colors"
                    >
                        <span x-text="notesMode ? 'Notes On' : 'Notes Off'"></span>
                    </button>
                </div>

                {{-- Number pad --}}
                <div class="grid grid-cols-9 gap-1">
                    @for ($n = 1; $n <= 9; $n++)
                        <button
                            @click="enterValue({{ $n }})"
                            class="h-10 sm:h-11 rounded font-semibold text-base
                                   bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200
                                   border border-gray-300 dark:border-gray-600
                                   hover:bg-blue-50 dark:hover:bg-blue-900/30
                                   active:bg-blue-100 dark:active:bg-blue-900/50
                                   transition-colors"
                        >{{ $n }}</button>
                    @endfor
                </div>

                {{-- Erase --}}
                <div class="flex justify-center">
                    <button
                        @click="clearCell()"
                        class="px-6 py-1.5 text-sm font-medium rounded-full
                               bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300
                               border border-gray-300 dark:border-gray-600
                               hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600
                               transition-colors"
                    >
                        Erase
                    </button>
                </div>
            </div>

        </div>

        {{-- Completion modal --}}
        <div
            x-show="complete"
            x-cloak
            class="fixed inset-0 flex items-center justify-center z-50"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

            <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 mx-4 max-w-sm w-full text-center">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">
                    Puzzle solved!
                </h2>
                <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">
                    {{ ucfirst($puzzle->difficulty) }} &mdash; #{{ $puzzle->id }}
                </p>

                <dl class="grid grid-cols-2 gap-4 mb-8">
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-4">
                        <dt class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Time</dt>
                        <dd class="text-2xl font-mono font-bold text-gray-900 dark:text-gray-100"
                            x-text="stats ? formatTime(stats.elapsed_seconds) : '--:--'"></dd>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-4">
                        <dt class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Mistakes</dt>
                        <dd class="text-2xl font-bold text-gray-900 dark:text-gray-100"
                            x-text="stats ? stats.mistakes : 0"></dd>
                    </div>
                </dl>

                <a
                    href="{{ route('sudoku.index') }}"
                    class="block w-full py-3 px-6 bg-indigo-600 hover:bg-indigo-700
                           text-white font-semibold rounded-xl transition-colors"
                >
                    Play another
                </a>
            </div>
        </div>

    </div>
</x-app-layout>
