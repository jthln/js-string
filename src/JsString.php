<?php

// SPDX-License-Identifier: MIT
// Copyright (c) 2022-2026 Jürgen Thelen

declare(strict_types=1);

namespace Jthln\JsString;

use InvalidArgumentException;

final class JsString
{
    /**
     * JavaScript's `length` counts UTF-16 code units, not UTF-8 bytes or Unicode
     * code points. Supplementary characters therefore count as two.
     *
     * @param string $value
     * @return int
     */
    public static function length(string $value): int
    {
        return count(self::codeUnits($value));
    }

    /**
     * JavaScript indexing returns one UTF-16 code unit. PHP strings cannot
     * represent an isolated surrogate as valid UTF-8, so that one-unit result
     * is exposed as U+FFFD; a complete surrogate pair is returned as its UTF-8
     * character.
     *
     * Example: `charAt('😀', 0)` returns `"\u{FFFD}"`, because index 0 selects
     * only the high surrogate, not the whole emoji.
     *
     * @param string $value
     * @param int $index
     * @return string
     */
    public static function charAt(string $value, int $index): string
    {
        if ($index < 0) {
            return '';
        }

        $units = self::codeUnits($value);
        $index = min($index, count($units));

        return $index >= count($units) ? '' : self::fromCodeUnits([$units[$index]]);
    }

    /**
     * JavaScript's `at()` accepts negative UTF-16 code-unit indexes, unlike byte-based PHP
     * offsets. An isolated surrogate is represented as U+FFFD in UTF-8.
     *
     * @param string $value
     * @param int $index
     * @return string|null
     */
    public static function at(string $value, int $index): ?string
    {
        $units = self::codeUnits($value);
        $index = $index < 0 ? count($units) + $index : $index;

        return isset($units[$index]) ? self::fromCodeUnits([$units[$index]]) : null;
    }

    /**
     * JavaScript's `codePointAt` is indexed by UTF-16 units but combines a valid
     * surrogate pair into one Unicode code point. PHP's `mb_ord` alone instead
     * indexes by Unicode characters and therefore cannot honor the JavaScript index.
     *
     * Example: `codePointAt('😀', 0)` returns `0x1F600`, combining the pair.
     *
     * @param string $value
     * @param int $index
     * @return int|null
     */
    public static function codePointAt(string $value, int $index): ?int
    {
        $units = self::codeUnits($value);

        if ($index < 0 || !isset($units[$index])) {
            return null;
        }

        $unit = $units[$index];

        // Recognize a UTF-16 surrogate pair and combine its two 16-bit units into one Unicode code point
        if (
            $unit >= 0xD800 && $unit <= 0xDBFF
            && isset($units[$index + 1])
            && $units[$index + 1] >= 0xDC00
            && $units[$index + 1] <= 0xDFFF
        ) {
            return 0x10000 + (($unit - 0xD800) << 10) + ($units[$index + 1] - 0xDC00);
        }

        return $unit;
    }

    /**
     * Splitting on the empty string is defined in JavaScript in terms of
     * UTF-16 code units. PHP's byte-oriented split would break UTF-8 and its
     * Unicode-aware split would incorrectly keep a surrogate pair together.
     *
     * Isolated surrogate pieces are returned as U+FFFD (PHP has no UTF-8
     * representation for them).
     *
     * Example: `split('😀')` returns `["\u{FFFD}", "\u{FFFD}"]`.
     *
     * @param string $value
     * @param string $separator
     * @param int|null $limit
     * @return array
     */
    public static function split(string $value, string $separator = '', ?int $limit = null): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $max = $limit ?? PHP_INT_MAX;

        if ($separator === '') {
            $units = self::codeUnits($value);
            $pieces = [];

            foreach (array_slice($units, 0, $max) as $unit) {
                $pieces[] = self::fromCodeUnits([$unit]);
            }

            return $pieces;
        }

        $haystack = self::codeUnits($value);
        $needle = self::codeUnits($separator);
        $pieces = [];
        $start = 0;

        while (
            count($pieces) < $max - 1
            && ($at = self::findUnits($haystack, $needle, $start)) !== null
        ) {
            $pieces[] = self::fromCodeUnits(array_slice($haystack, $start, $at - $start));
            $start = $at + count($needle);
        }

        $pieces[] = self::fromCodeUnits(array_slice($haystack, $start));

        return $pieces;
    }

    /**
     * JavaScript's `charCodeAt` reports a UTF-16 code unit, not a Unicode code point.
     * It returns `null` for an out-of-range index (the PHP equivalent of JS `NaN`).
     *
     * @param string $value
     * @param int $index
     * @return int|null
     */
    public static function charCodeAt(string $value, int $index): ?int
    {
        if ($index < 0) {
            return null;
        }

        $units = self::codeUnits($value);
        $index = min($index, count($units));

        return $units[$index] ?? null;
    }

    /**
     * JavaScript's `substring` endpoints are UTF-16 code-unit indexes; PHP substring
     * functions use bytes, and Unicode-aware ones use code points instead.
     *
     * Unlike `slice()`, `substring()` swaps its endpoints when start is greater
     * than end: `substring('abc', 2, 0)` returns `'ab'`.
     *
     * @param string $value
     * @param int $start
     * @param int|null $end
     * @return string
     */
    public static function substring(string $value, int $start, ?int $end = null): string
    {
        $units = self::codeUnits($value);
        $length = count($units);
        $start = max(0, min($start, $length));
        $end = $end === null ? $length : max(0, min($end, $length));

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return self::fromCodeUnits(array_slice($units, $start, $end - $start));
    }

    /**
     * JavaScript's `slice` uses UTF-16 indexes and resolves negative indexes from the end;
     * PHP `substr` instead interprets offsets as bytes.
     *
     * Unlike `substring()`, `slice()` does not swap reversed endpoints:
     * `slice('abc', 2, 0)` returns an empty string.
     *
     * @param string $value
     * @param int $start
     * @param int|null $end
     * @return string
     */
    public static function slice(string $value, int $start, ?int $end = null): string
    {
        $units = self::codeUnits($value);
        $length = count($units);
        $start = self::normalizeIndex($start, $length);
        $end = $end === null ? $length : self::normalizeIndex($end, $length);

        return self::fromCodeUnits(array_slice($units, $start, max(0, $end - $start)));
    }

    /**
     * JavaScript's `indexOf` returns a UTF-16 code-unit offset, whereas PHP string-search
     * functions return a byte offset (and multibyte searches often a code-point offset).
     *
     * @param string $value
     * @param string $search
     * @param int $fromIndex
     * @return int
     */
    public static function indexOf(string $value, string $search, int $fromIndex = 0): int
    {
        $haystack = self::codeUnits($value);
        $needle = self::codeUnits($search);
        $start = min(max(0, $fromIndex), count($haystack));
        $at = self::findUnits($haystack, $needle, $start);

        return $at ?? -1;
    }

    /**
     * JavaScript's `lastIndexOf` returns a UTF-16 code-unit offset. The optional position is
     * likewise measured in code units, unlike PHP's byte-oriented search.
     *
     * @param string $value
     * @param string $search
     * @param int|null $position
     * @return int
     */
    public static function lastIndexOf(string $value, string $search, ?int $position = null): int
    {
        $haystack = self::codeUnits($value);
        $needle = self::codeUnits($search);
        $last = count($haystack) - count($needle);
        $start = $position === null
            ? $last
            : min($last, max(0, $position));

        if ($needle === []) {
            return min(max(0, $position ?? count($haystack)), count($haystack));
        }

        for ($at = $start; $at >= 0; $at--) {
            if (array_slice($haystack, $at, count($needle)) === $needle) {
                return $at;
            }
        }

        return -1;
    }

    /**
     * JavaScript's `includes()` accepts a UTF-16 position, so delegate to the UTF-16-aware
     * indexOf rather than PHP's byte-oriented `strpos`.
     *
     * @param string $value
     * @param string $search
     * @param int $position
     * @return bool
     */
    public static function includes(string $value, string $search, int $position = 0): bool
    {
        return self::indexOf($value, $search, $position) !== -1;
    }

    /**
     * JavaScript's `fromCharCode` truncates each argument to a 16-bit UTF-16 unit.
     * PHP's `chr()` only creates a byte, while code-point encoders combine or reject
     * surrogate values; paired units are combined here and unpaired units
     * become U+FFFD because valid UTF-8 cannot encode them.
     *
     * @param int ...$codes
     * @return string
     */
    public static function fromCharCode(int ...$codes): string
    {
        return self::fromCodeUnits(array_values(array_map(
            static fn (int $code): int => $code & 0xFFFF,
            $codes,
        )));
    }

    /**
     * JavaScript regex matches expose UTF-16 index positions. This helper
     * returns each PCRE match together with its index converted from PCRE's
     * UTF-8 byte offset to a UTF-16 code-unit offset. The pattern is PCRE
     * syntax and must be delimited, typically with the `u` modifier.
     *
     * @param string $value
     * @param string $pattern
     * @return array
     */
    public static function match(string $value, string $pattern): array
    {
        $matched = preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE);

        if ($matched === false) {
            throw new InvalidArgumentException('Invalid regular expression or UTF-8 string.');
        }

        $result = [];

        foreach ($matches[0] as [$match, $byteOffset]) {
            $prefix = substr($value, 0, $byteOffset);
            $result[] = ['match' => $match, 'index' => self::length($prefix)];
        }

        return $result;
    }

    /**
     * Regex replacements run over UTF-8 in PHP, but replacement callback
     * offsets follow PHP's byte-based PCRE API. Convert the offset to UTF-16
     * before invoking a JS-like callback. The pattern uses PCRE syntax; a
     * string replacement follows `preg_replace` replacement syntax.
     *
     * @param string $value
     * @param string $pattern
     * @param (callable(string, int): string)|string $replacement
     * @return string
     */
    public static function replace(string $value, string $pattern, callable|string $replacement): string
    {
        if (is_string($replacement)) {
            $result = preg_replace($pattern, $replacement, $value, 1);

            if ($result === null) {
                throw new InvalidArgumentException('Invalid regular expression or UTF-8 string.');
            }

            return $result;
        }

        $result = preg_replace_callback(
            $pattern,
            /** @param array{0: array{0: string, 1: int}} $matches */
            static function (array $matches) use ($value, $replacement): string {
                $match = $matches[0][0];
                $byteOffset = (int) $matches[0][1];
                $index = self::length(substr($value, 0, $byteOffset));

                return $replacement($match, $index);
            },
            $value,
            1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        if ($result === null) {
            throw new InvalidArgumentException('Invalid regular expression or UTF-8 string.');
        }

        return $result;
    }

    /**
     * Trim the whitespace characters defined by JavaScript's trim operation.
     * PHP's trim() uses a narrower, byte-oriented default character list.
     *
     * @param string $value
     * @return string
     */
    public static function trim(string $value): string
    {
        $whitespace = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}'
            . '\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}'
            . '\x{3000}\x{FEFF}]';
        $trimmed = preg_replace(
            '/\A' . $whitespace . '+|' . $whitespace . '+\z/u',
            '',
            $value,
        );

        if ($trimmed === null) {
            throw new InvalidArgumentException(
                'Expected a valid UTF-8 string.',
            );
        }

        return $trimmed;
    }

    /**
     * Convert text to lowercase using UTF-8-aware case mapping. PHP's
     * `strtolower()` only handles ASCII characters.
     *
     * @param string $value
     * @return string
     */
    public static function toLowerCase(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Convert text to uppercase using UTF-8-aware case mapping. PHP's
     * `strtoupper()` only handles ASCII characters.
     *
     * Example: `toUpperCase('ß')` returns `'SS'` (case conversion can change length).
     *
     * @param string $value
     * @return string
     */
    public static function toUpperCase(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Convert valid UTF-8 characters into JavaScript UTF-16 code units,
     * expanding supplementary code points into surrogate pairs.
     *
     * @param string $value
     * @return array
     */
    private static function codeUnits(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            throw new InvalidArgumentException('Expected a valid UTF-8 string.');
        }

        $units = [];

        foreach ($characters as $character) {
            $codePoint = mb_ord($character, 'UTF-8');

            if ($codePoint <= 0xFFFF) {
                $units[] = $codePoint;
                continue;
            }

            $codePoint -= 0x10000;
            $units[] = 0xD800 | ($codePoint >> 10);
            $units[] = 0xDC00 | ($codePoint & 0x3FF);
        }

        return $units;
    }

    /**
     * Encode UTF-16 code units as valid UTF-8, combining surrogate pairs and
     * replacing unpaired surrogates with U+FFFD.
     *
     * @param array $units
     * @return string
     */
    private static function fromCodeUnits(array $units): string
    {
        $value = '';
        $count = count($units);

        for ($i = 0; $i < $count; $i++) {
            $unit = $units[$i];

            if (
                $unit >= 0xD800 && $unit <= 0xDBFF
                && isset($units[$i + 1])
                && $units[$i + 1] >= 0xDC00
                && $units[$i + 1] <= 0xDFFF
            ) {
                $low = $units[++$i];
                $value .= mb_chr(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
                continue;
            }

            $value .= $unit >= 0xD800 && $unit <= 0xDFFF
                ? "\u{FFFD}"
                : mb_chr($unit, 'UTF-8');
        }

        return $value;
    }

    /**
     * Resolve a slice-style index and clamp it to the string's length.
     *
     * @param int $index
     * @param int $length
     * @return int
     */
    private static function normalizeIndex(int $index, int $length): int
    {
        return max(0, min($index < 0 ? $length + $index : $index, $length));
    }

    /**
     * Find the first exact UTF-16 code-unit subsequence at or after $start.
     * An empty needle matches at the start position; null means no match.
     *
     * @param array $haystack
     * @param array $needle
     * @param int $start
     * @return int|null
     */
    private static function findUnits(array $haystack, array $needle, int $start): ?int
    {
        if ($needle === []) {
            return min($start, count($haystack));
        }

        for ($at = $start, $last = count($haystack) - count($needle); $at <= $last; $at++) {
            if (array_slice($haystack, $at, count($needle)) === $needle) {
                return $at;
            }
        }

        return null;
    }
}
