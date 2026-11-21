<?php

namespace App\Support;

class CpfElectionPositions
{
    /**
     * Ordered parliamentary election slate per CPF Constitution & By-Laws,
     * followed by Year-Level Representatives (optional if upper years unavailable).
     *
     * @return array<int, array{key: string, label: string, optional: bool, required_year: string|null, seat_order: int}>
     */
    public static function ordered(): array
    {
        return [
            ['key' => 'president', 'label' => 'President', 'optional' => false, 'required_year' => null, 'seat_order' => 1],
            ['key' => 'vice_president', 'label' => 'Vice President', 'optional' => false, 'required_year' => null, 'seat_order' => 2],
            ['key' => 'secretary', 'label' => 'Secretary', 'optional' => false, 'required_year' => null, 'seat_order' => 3],
            ['key' => 'treasurer', 'label' => 'Treasurer', 'optional' => false, 'required_year' => null, 'seat_order' => 4],
            ['key' => 'auditor', 'label' => 'Auditor', 'optional' => false, 'required_year' => null, 'seat_order' => 5],
            ['key' => 'business_manager', 'label' => 'Business Manager', 'optional' => false, 'required_year' => null, 'seat_order' => 6],
            ['key' => 'pio', 'label' => 'Public Information Officer (PIO)', 'optional' => false, 'required_year' => null, 'seat_order' => 7],
            ['key' => 'sergeant_at_arms', 'label' => 'Sergeant-at-Arms', 'optional' => false, 'required_year' => null, 'seat_order' => 8],
            ['key' => 'first_year_rep', 'label' => '1st Year Representative', 'optional' => true, 'required_year' => '1', 'seat_order' => 9],
            ['key' => 'second_year_rep', 'label' => '2nd Year Representative', 'optional' => true, 'required_year' => '2', 'seat_order' => 10],
            ['key' => 'third_year_rep', 'label' => '3rd Year Representative', 'optional' => true, 'required_year' => '3', 'seat_order' => 11],
            ['key' => 'fourth_year_rep', 'label' => '4th Year Representative', 'optional' => true, 'required_year' => '4', 'seat_order' => 12],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_column(self::ordered(), 'key');
    }

    /**
     * @return array{key: string, label: string, optional: bool, required_year: string|null, seat_order: int}|null
     */
    public static function find(?string $key): ?array
    {
        if (! $key) {
            return null;
        }

        foreach (self::ordered() as $pos) {
            if ($pos['key'] === $key) {
                return $pos;
            }
        }

        return null;
    }

    public static function nextAfter(?string $key): ?string
    {
        $keys = self::keys();
        if (! $key) {
            return $keys[0] ?? null;
        }

        $idx = array_search($key, $keys, true);
        if ($idx === false) {
            return null;
        }

        return $keys[$idx + 1] ?? null;
    }

    public static function isOptional(?string $key): bool
    {
        return (bool) (self::find($key)['optional'] ?? false);
    }

    public static function requiredYear(?string $key): ?string
    {
        return self::find($key)['required_year'] ?? null;
    }

    public static function normalizeYearDigit(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^([1-5])(?:st|nd|rd|th)?(?:\s*year)?$/i', $trimmed, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
