<?php

namespace App\Support;

/**
 * Normalizes Arabic text so searches match regardless of hamza forms,
 * taa marbuta, alef maqsura, diacritics and tatweel.
 */
final class ArabicText
{
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = mb_strtolower(trim($text));

        // Strip harakat (diacritics) and tatweel.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;

        $map = [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            'ؤ' => 'و',
            'ئ' => 'ي',
            // Arabic-Indic digits to Latin digits.
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        $text = strtr($text, $map);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /** Escape LIKE wildcards in user input. */
    public static function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
