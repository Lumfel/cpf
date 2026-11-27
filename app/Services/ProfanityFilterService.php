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
        'puta', 'puto', 'putamadre', 'pendejo', 'pendeja', 'cabron', 'chinga',
        'chingada', 'chingar', 'mierda', 'cojones', 'culero', 'maricon',
        'gilipollas', 'joder', 'verga', 'pinche', 'carajo', 'concha', 'malparido',

        // Tagalog / Filipino
        'putangina', 'putanginamo', 'tangina', 'tanginamo', 'punyeta', 'pakyu',
        'gago', 'gaga', 'ulol', 'tarantado', 'tarantada', 'leche', 'letse',
        'hayopka', 'bobo', 'tanga', 'inutil', 'kupal', 'hindot', 'kantot',
        'jakol', 'pepe', 'tite', 'bayag', 'puki', 'puke', 'bwisit', 'hudas',

        // Hiligaynon / Ilonggo
        'yudiputa', 'yodiputa', 'diputa', 'linti', 'lintian', 'bilat', 'bilatibay',
        'buray', 'burayni', 'buto', 'piste', 'pisti', 'pistingyawa', 'bwesit',
        'udiputa', 'tunto', 'bigaon', 'iyot', 'kinanglan', 'bastoska',

        // Cebuano / Bisaya
        'yawa', 'yawaa', 'atay', 'ataya', 'piste', 'pisti', 'pistengyawa',
        'giatay', 'buang', 'boang', 'animala', 'amaw', 'kayata', 'kayat',
        'oten', 'utin', 'bisong', 'bilat', 'bogo', 'bugo', 'kolera', 'koleraha',
        'hinampak', 'pakyu', 'lecheha',
    ];

    /**
     * Terms that should match only as whole words (to prevent false positives
     * like "assistant", "class", "hello", or "analysis").
     *
     * @var array<int, string>
     */
    private const WHOLE_WORD_ONLY = [
        'ass', 'hell', 'crap', 'damn', 'buto', 'pepe', 'tite', 'oten', 'utin',
        'atay', 'bobo', 'tanga', 'bogo', 'bugo', 'amaw', 'puto', 'puta',
        'gago', 'gaga', 'ulol', 'puki', 'puke', 'iyot', 'leche', 'letse',
        'linti', 'yawa', 'bilat', 'buray', 'piste', 'pisti', 'buang', 'boang',
        'fuck', 'shit', 'cunt', 'slut', 'stfu', 'gtfo',
    ];

    public function normalize(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');

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

        // Also build a collapsed version with repeated letters squeezed (e.g., "fuuuuck" -> "fuck", "y a w a" -> "yawa")
        $squeezedWords = (string) preg_replace('/([a-z])\1{2,}/', '$1', $normalized);
        $compact = str_replace(' ', '', $squeezedWords);

        $wholeWordSet = array_flip(self::WHOLE_WORD_ONLY);

        foreach (self::BLOCKED_TERMS as $term) {
            // Check exact word boundary in both normalized and squeezed-repeat forms
            if (
                preg_match('/\b'.preg_quote($term, '/').'\b/', $normalized) === 1
                || preg_match('/\b'.preg_quote($term, '/').'\b/', $squeezedWords) === 1
            ) {
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
