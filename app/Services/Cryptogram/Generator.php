<?php

namespace App\Services\Cryptogram;

class Generator
{
    private const QUOTES = [
        [
            'text'        => 'The only way to do great work is to love what you do.',
            'attribution' => 'Steve Jobs',
        ],
        [
            'text'        => 'In the middle of every difficulty lies opportunity.',
            'attribution' => 'Albert Einstein',
        ],
        [
            'text'        => 'It does not matter how slowly you go as long as you do not stop.',
            'attribution' => 'Confucius',
        ],
        [
            'text'        => 'Life is what happens when you are busy making other plans.',
            'attribution' => 'John Lennon',
        ],
        [
            'text'        => 'The future belongs to those who believe in the beauty of their dreams.',
            'attribution' => 'Eleanor Roosevelt',
        ],
        [
            'text'        => 'Spread love everywhere you go. Let no one ever come from you without feeling happier.',
            'attribution' => 'Mother Teresa',
        ],
        [
            'text'        => 'When you reach the end of your rope, tie a knot in it and hang on.',
            'attribution' => 'Franklin D. Roosevelt',
        ],
        [
            'text'        => 'Always remember that you are absolutely unique. Just like everyone else.',
            'attribution' => 'Margaret Mead',
        ],
        [
            'text'        => 'Do not go where the path may lead, go instead where there is no path and leave a trail.',
            'attribution' => 'Ralph Waldo Emerson',
        ],
        [
            'text'        => 'You will face many defeats in life, but never let yourself be defeated.',
            'attribution' => 'Maya Angelou',
        ],
        [
            'text'        => 'The greatest glory in living lies not in never falling, but in rising every time we fall.',
            'attribution' => 'Nelson Mandela',
        ],
        [
            'text'        => 'In the end, it is not the years in your life that count. It is the life in your years.',
            'attribution' => 'Abraham Lincoln',
        ],
    ];

    /**
     * Generate a cryptogram puzzle from a random quote.
     *
     * @return array{puzzle: array, solution: array}
     *   - puzzle.plaintext:    the original quote text
     *   - puzzle.ciphertext:   the encoded text
     *   - puzzle.attribution:  author of the quote
     *   - puzzle.revealed:     cipher letters pre-revealed (2–3 most frequent)
     *   - solution.mapping:    cipher→plain letter map for all 26 letters
     */
    public function generate(): array
    {
        $quote      = self::QUOTES[array_rand(self::QUOTES)];
        $plaintext  = $quote['text'];
        $mapping    = $this->buildCipherMapping();
        $ciphertext = $this->applyMapping($plaintext, $mapping);
        $revealed   = $this->pickRevealedLetters($ciphertext, 3);

        return [
            'puzzle' => [
                'plaintext'   => $plaintext,
                'ciphertext'  => $ciphertext,
                'attribution' => $quote['attribution'],
                'revealed'    => $revealed,
            ],
            'solution' => [
                'mapping' => $mapping,
            ],
        ];
    }

    /**
     * Build a random bijective A–Z substitution where no letter maps to itself.
     * Returns cipher→plain map (e.g. 'X' => 'A' means cipher X decodes to plain A).
     *
     * @return array<string, string>
     */
    private function buildCipherMapping(): array
    {
        $letters = range('A', 'Z');

        // Generate a derangement (permutation with no fixed points).
        do {
            $shuffled = $letters;
            shuffle($shuffled);
        } while ($this->hasFixedPoint($letters, $shuffled));

        // $shuffled[i] is the plain letter that cipher letter $letters[i] decodes to.
        return array_combine($letters, $shuffled);
    }

    private function hasFixedPoint(array $original, array $permuted): bool
    {
        foreach ($original as $i => $letter) {
            if ($letter === $permuted[$i]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the cipher mapping to plaintext (letters only, preserving case/punctuation).
     */
    private function applyMapping(string $plaintext, array $mapping): string
    {
        // Invert to plain→cipher for encoding.
        $encode = array_flip($mapping);

        $result = '';
        foreach (str_split(strtoupper($plaintext)) as $char) {
            $result .= isset($encode[$char]) ? $encode[$char] : $char;
        }

        return $result;
    }

    /**
     * Pick the N most frequent cipher letters to pre-reveal.
     * Returns array of cipher letters whose plain equivalents are known from the start.
     *
     * @return string[]
     */
    private function pickRevealedLetters(string $ciphertext, int $count): array
    {
        $freq = [];
        foreach (str_split($ciphertext) as $char) {
            if (ctype_alpha($char)) {
                $freq[$char] = ($freq[$char] ?? 0) + 1;
            }
        }

        arsort($freq);

        return array_slice(array_keys($freq), 0, $count);
    }
}
