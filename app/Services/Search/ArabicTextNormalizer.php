<?php

declare(strict_types=1);

namespace App\Services\Search;

final class ArabicTextNormalizer
{
    /**
     * Normalize Arabic text for uniform matching and searching.
     */
    public static function normalize(string $text): string
    {
        // 1. Remove Tashkeel (diacritics: Fatha, Damma, Kasra, Tanwin, Shadda, Sukun) & Tatweel
        $normalized = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        // 2. Normalize Alef variants: أ, إ, آ, ٱ -> ا
        $normalized = (string) preg_replace('/[أإآٱ]/u', 'ا', $normalized);

        // 3. Normalize Yaa / Alef Maksura / Hamza on Yaa: ي, ى, ئ -> ي
        $normalized = (string) preg_replace('/[يىئ]/u', 'ي', $normalized);

        // 4. Normalize word-ending Taa Marbuta / Haa / Alef interchangeable vowels (e.g. شاورمة/شاورما, بيتزة/بيتزا)
        // Convert any word-final ة or ه preceded by consonants into ا, or unify [ةه]$ with ا$
        $words = explode(' ', (string) preg_replace('/\s+/u', ' ', trim($normalized)));
        $normalizedWords = array_map(function (string $word): string {
            // Replace trailing ة or ه with ا for phonetic catalog matching
            return (string) preg_replace('/[ةه]$/u', 'ا', $word);
        }, $words);

        $normalized = implode(' ', $normalizedWords);

        return mb_strtolower(trim($normalized), 'UTF-8');
    }
}
