<?php

namespace App\Telegram\Support;

/**
 * A "label: value" line whose value starts with Latin/digit characters (an
 * IP, a name like "de", a key) can get its base paragraph direction
 * misdetected by some Telegram clients, visually reordering the line. Force
 * RTL by prepending U+200F (Right-to-Left Mark) — an invisible character —
 * to every non-empty line.
 */
trait FormatsRtlText
{
    protected static function rtl(string $text): string
    {
        return implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : "\u{200F}{$line}",
            explode("\n", $text)
        ));
    }
}
