<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Cryptogram
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

            <p class="text-center text-gray-500 dark:text-gray-400 mb-10 text-sm tracking-wide uppercase">
                Today's Puzzle &mdash; {{ now()->format('F j, Y') }}
            </p>

            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Cryptogram
                    </h3>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                 bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                        {{ $puzzle ? 'Available' : 'Unavailable' }}
                    </span>
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Decode the encrypted quote by substituting each cipher letter with the correct letter of the alphabet. A few common letters are revealed to get you started.
                </p>

                @if ($puzzle)
                    <a href="{{ route('cryptogram.show', $puzzle) }}"
                       class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-white text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500">
                        Play
                    </a>
                @else
                    <span class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500 cursor-not-allowed">
                        No puzzle available
                    </span>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
