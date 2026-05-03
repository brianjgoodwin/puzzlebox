<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Sudoku
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

            <p class="text-center text-gray-500 dark:text-gray-400 mb-10 text-sm tracking-wide uppercase">
                Today's Puzzles &mdash; {{ now()->format('F j, Y') }}
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                @php
                    $styles = [
                        'debug'  => ['badge' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200', 'btn' => 'bg-purple-600 hover:bg-purple-700 focus:ring-purple-500'],
                        'easy'   => ['badge' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',  'btn' => 'bg-green-600 hover:bg-green-700 focus:ring-green-500'],
                        'medium' => ['badge' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200', 'btn' => 'bg-yellow-500 hover:bg-yellow-600 focus:ring-yellow-400'],
                        'hard'   => ['badge' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200', 'btn' => 'bg-orange-500 hover:bg-orange-600 focus:ring-orange-400'],
                        'expert' => ['badge' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',   'btn' => 'bg-red-600 hover:bg-red-700 focus:ring-red-500'],
                    ];

                    $descriptions = [
                        'debug'  => 'Only 3 blank cells. For when you just need to click Play.',
                        'easy'   => 'A gentle start. Plenty of clues to guide you.',
                        'medium' => 'A step up. Some deduction required.',
                        'hard'   => 'Fewer clues. More patience needed.',
                        'expert' => 'Minimal clues. Not for the faint-hearted.',
                    ];
                @endphp

                {{-- Each card is written out explicitly so Tailwind sees the full class strings at build time --}}

                @php
                    $cards = [
                        'debug'  => ['badge' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200', 'btn' => 'bg-purple-600 hover:bg-purple-700 focus:ring-purple-500'],
                        'easy'   => ['badge' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',     'btn' => 'bg-green-600 hover:bg-green-700 focus:ring-green-500'],
                        'medium' => ['badge' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200', 'btn' => 'bg-yellow-500 hover:bg-yellow-600 focus:ring-yellow-400'],
                        'hard'   => ['badge' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200', 'btn' => 'bg-orange-500 hover:bg-orange-600 focus:ring-orange-400'],
                        'expert' => ['badge' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',             'btn' => 'bg-red-600 hover:bg-red-700 focus:ring-red-500'],
                    ];
                @endphp

                @foreach (['debug', 'easy', 'medium', 'hard', 'expert'] as $difficulty)
                    @php
                        $puzzle    = $puzzles[$difficulty];
                        $badgeCls  = $cards[$difficulty]['badge'];
                        $btnCls    = $cards[$difficulty]['btn'];
                    @endphp

                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {{ ucfirst($difficulty) }}
                            </h3>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $badgeCls }}">
                                {{ $puzzle ? 'Available' : 'Unavailable' }}
                            </span>
                        </div>

                        <p class="text-sm text-gray-500 dark:text-gray-400 flex-1">
                            {{ $descriptions[$difficulty] }}
                        </p>

                        @if ($puzzle)
                            <a href="{{ route('sudoku.show', $puzzle) }}"
                               class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-white text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $btnCls }}">
                                Play
                            </a>
                        @else
                            <span class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500 cursor-not-allowed">
                                No puzzle available
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</x-app-layout>
