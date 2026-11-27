<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Lightweight, low-end-server-friendly multilingual profanity filter covering
 * English, Spanish, Hiligaynon (Ilonggo), Cebuano (Bisaya), and Tagalog (Filipino).
 * Normalizes case, diacritics, leetspeak, and spaced-out bypass attempts.
 */
class ProfanityFilterService
{
    /**
     * Canonical normalized phrases and tokens blocked across all 5 languages.
     *
     * @var array<int, string>
     */
    private const BLOCKED_TERMS = [
        // English
        'fuck', 'fucker', 'fucking', 'motherfucker', 'shit', 'bullshit',
        'bitch', 'bastard', 'asshole', 'dickhead', 'cunt', 'whore', 'slut',
        'dumbass', 'jackass', 'stfu', 'gtfo',

        // Spanish
        'puta', 'putamadre', 'pendejo', 'pendeja', 'cabron', 'chinga',
        'chingada', 'chingar', 'mierda', 'cojones', 'culero', 'maricon',
        'gilipollas', 'joder', 'verga', 'pinche', 'carajo', 'concha', 'malparido',

        // Tagalog / Filipino
        'putangina', 'putanginamo', 'tangina', 'tanginamo', 'punyeta', 'pakyu',
        'gago', 'gaga', 'ulol', 'tarantado', 'tarantada', 'leche', 'letse',
        'hayopka', 'bobo', 'tanga', 'inutil', 'kupal', 'hindot', 'kantot',
        'jakol', 'tite', 'bayag', 'puki', 'puke', 'bwisit', 'hudas',

        // Hiligaynon / Ilonggo
        'yudiputa', 'yodiputa', 'diputa', 'linti', 'lintian', 'bilat', 'bilatibay',
        'buray', 'burayni', 'piste', 'pisti', 'pistingyawa', 'bwesit',
        'udiputa', 'tunto', 'bigaon', 'iyot', 'bastoska',

        // Cebuano / Bisaya
        'yawa', 'yawaa', 'ataya', 'piste', 'pisti', 'pistengyawa',
        'giatay', 'buang', 'boang', 'animala', 'amaw', 'kayata', 'kayat',
        'oten', 'utin', 'bisong', 'bilat', 'bogo', 'bugo', 'kolera', 'koleraha',
        'hinampak', 'pakyu', 'lecheha',
    ];

    /**
     * Terms that should match only as whole words (to prevent false positives
     * like "assistant", "class", "hello", "coaching a", "kaya't", or "Prof. Vergara").
     *
     * @var array<int, string>
     */
    private const WHOLE_WORD_ONLY = [
        'ass', 'hell', 'crap', 'damn', 'tite', 'oten', 'utin',
        'bobo', 'tanga', 'bogo', 'bugo', 'amaw', 'puta',
        'gago', 'gaga', 'ulol', 'puki', 'puke', 'iyot', 'leche', 'letse',
        'linti', 'yawa', 'bilat', 'buray', 'piste', 'pisti', 'buang', 'boang',
        'fuck', 'shit', 'cunt', 'slut', 'stfu', 'gtfo',
        'chinga', 'kayat', 'verga', 'concha', 'diputa', 'animala', 'pinche',
    ];

    /**
     * Common Cyrillic and Greek homoglyph lookalikes mapped to ASCII Latin equivalents.
     *
     * @var array<string, string>
     */
    private const HOMOGLYPH_MAP = [
        // Cyrillic lowercase
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c',
        'х' => 'x', 'у' => 'y', 'і' => 'i', 'ї' => 'i', 'ј' => 'j',
        'к' => 'k', 'м' => 'm', 'н' => 'h', 'т' => 't', 'в' => 'b',
        'ѕ' => 's', 'ԁ' => 'd', 'ɡ' => 'g', 'ѵ' => 'v', 'ԝ' => 'w',
        // Greek lowercase
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'ι' => 'i', 'κ' => 'k',
        'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u',
        'χ' => 'x', 'η' => 'n', 'μ' => 'u', 'ω' => 'w',
    ];

    public function normalize(string $text): string
    {
        // Strip invisible / zero-width Unicode format characters and soft hyphens
        $visible = (string) preg_replace('/[\p{Cf}\x{00AD}]+/u', '', $text);
        $lower = mb_strtolower($visible, 'UTF-8');

        // Map common Cyrillic/Greek lookalikes before ASCII transliteration
        $lower = strtr($lower, self::HOMOGLYPH_MAP);

        // Strip accents / diacritics
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if (is_string($transliterated) && $transliterated !== '') {
            $lower = $transliterated;
        }

        // Decode common leetspeak substitutions
        $decoded = strtr($lower, [
            '@' => 'a',
            '4' => 'a',
            '3' => 'e',
            '1' => 'i',
            '!' => 'i',
            '0' => 'o',
            '$' => 's',
            '5' => 's',
            '7' => 't',
            '+' => 't',
        ]);

        // Replace punctuation/symbols with spaces and collapse whitespace
        $cleaned = (string) preg_replace('/[^a-z\s]+/', ' ', $decoded);

        return trim((string) preg_replace('/\s+/', ' ', $cleaned));
    }

    /**
     * Find the first matched restricted term (or null if clean).
     */
    public function detectRestrictedTerm(string $text): ?string
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return null;
        }

        // Squeeze 3+ repeats ("fuuuuck" -> "fuck") and deduplicate doubled letters ("fuuck" -> "fuck", "yaawa" -> "yawa")
        $squeezedWords = (string) preg_replace('/([a-z])\1{2,}/', '$1', $normalized);
        $dedupedWords = (string) preg_replace('/([a-z])\1+/', '$1', $normalized);
        $compact = str_replace(' ', '', $squeezedWords);

        $wholeWordSet = array_flip(self::WHOLE_WORD_ONLY);

        foreach (self::BLOCKED_TERMS as $term) {
            $quoted = preg_quote($term, '/');

            // Check exact word boundary in normalized, squeezed-repeat, and deduplicated forms
            if (
                preg_match('/\b'.$quoted.'\b/', $normalized) === 1
                || preg_match('/\b'.$quoted.'\b/', $squeezedWords) === 1
                || preg_match('/\b'.$quoted.'\b/', $dedupedWords) === 1
            ) {
                return $term;
            }

            // Also catch doubled-letter evasion on multi-char terms that natively contain doubled letters (e.g., "bullshiit")
            $dedupedTerm = (string) preg_replace('/([a-z])\1+/', '$1', $term);
            if ($dedupedTerm !== $term && strlen($dedupedTerm) >= 6 && preg_match('/\b'.preg_quote($dedupedTerm, '/').'\b/', $dedupedWords) === 1) {
                return $term;
            }

            // Check spaced-out or concatenated evasion for multi-char unambiguous terms (len >= 5 and not whole-word-only)
            if (strlen($term) >= 5 && ! isset($wholeWordSet[$term]) && str_contains($compact, $term)) {
                return $term;
            }

            // Catch single-letter spaced-out evasion (e.g. "f u c k", "y a w a", "g a g o")
            if (strlen($term) >= 4) {
                $spacedPattern = implode('\s+', str_split($term));
                if (preg_match('/\b'.$spacedPattern.'\b/', $normalized) === 1) {
                    return $term;
                }
            }
        }

        return null;
    }

    public function containsProfanity(string $text): bool
    {
        return $this->detectRestrictedTerm($text) !== null;
    }

    /**
     * Throw a 422 ValidationException if restricted language is detected.
     */
    public function assertClean(string $text, string $field = 'message_body'): void
    {
        if ($this->containsProfanity($text)) {
            throw ValidationException::withMessages([
                $field => 'Message blocked: please keep campus communication respectful (restricted language detected across EN/ES/HIL/CEB/TL).',
            ]);
        }
    }
}
