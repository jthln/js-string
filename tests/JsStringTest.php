<?php

// SPDX-License-Identifier: MIT
// Copyright (c) 2022-2026 Jürgen Thelen

declare(strict_types=1);

namespace Jthln\JsString\Tests;

use Jthln\JsString\JsString;
use PHPUnit\Framework\TestCase;

final class JsStringTest extends TestCase
{
    public function testOperationsUseUtf16CodeUnitIndexes(): void
    {
        $value = "A😀B😀";

        self::assertSame(6, JsString::length($value));
        self::assertSame(0xD83D, JsString::charCodeAt($value, 1));
        self::assertSame(0xDE00, JsString::charCodeAt($value, 2));
        self::assertSame(0x1F600, JsString::codePointAt($value, 1));
        self::assertSame("\u{FFFD}", JsString::charAt($value, 1));
        self::assertSame('B', JsString::at($value, -3));
        self::assertSame(
            ['A', "\u{FFFD}", "\u{FFFD}", 'B', "\u{FFFD}", "\u{FFFD}"],
            JsString::split($value),
        );
        self::assertSame("\u{FFFD}B", JsString::substring($value, 2, 4));
        self::assertSame("\u{FFFD}B😀", JsString::slice($value, -4));
        self::assertSame(3, JsString::indexOf($value, 'B'));
        self::assertSame(4, JsString::lastIndexOf($value, '😀'));
        self::assertTrue(JsString::includes($value, 'B', 2));
        self::assertSame("😀", JsString::fromCharCode(0xD83D, 0xDE00));
    }

    public function testRegexHelpersExposeUtf16MatchIndexes(): void
    {
        $value = "😀cat cat";

        self::assertSame(
            [
                ['match' => 'cat', 'index' => 2],
                ['match' => 'cat', 'index' => 6],
            ],
            JsString::match($value, '/cat/u'),
        );
        self::assertSame(
            "😀dog cat",
            JsString::replace(
                $value,
                '/cat/u',
                static fn (string $match, int $index): string => $index === 2 ? 'dog' : $match,
            ),
        );
    }

    public function testCaseConversionHandlesNonAsciiText(): void
    {
        self::assertSame('ä', JsString::toLowerCase('Ä'));
        self::assertSame('Ä', JsString::toUpperCase('ä'));
        self::assertSame('SS', JsString::toUpperCase('ß'));
    }
}
