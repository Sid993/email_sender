<?php

declare(strict_types=1);

namespace App\Template;

/**
 * Personalization placeholders:
 *   {{first_name}}            -> value of the "first_name" column
 *   {{ first_name | there }}  -> value, or "there" when the cell is empty
 *
 * Values are HTML-escaped in the HTML body (so recipient data can't inject markup)
 * and left as plain text in the subject. Templates are never executed as code.
 */
final class Renderer
{
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*(?:\|\s*([^}]*?)\s*)?\}\}/u';

    public function __construct(private readonly string $template)
    {
    }

    public function render(array $data, bool $html): string
    {
        return (string) preg_replace_callback(self::PATTERN, static function (array $m) use ($data, $html): string {
            $key = strtolower($m[1]);
            $value = (string) ($data[$key] ?? '');
            if ($value === '' && isset($m[2])) {
                $value = $m[2];
            }
            return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
        }, $this->template);
    }

    /** Placeholders used in the template that don't exist in the uploaded file. */
    public static function unknownFields(string $template, array $fields): array
    {
        preg_match_all(self::PATTERN, $template, $m);
        $used = array_unique(array_map('strtolower', $m[1] ?? []));
        return array_values(array_diff($used, array_map('strtolower', $fields)));
    }

    /** Plain-text alternative part generated from the HTML body. */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('~<(script|style|head)[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $text = preg_replace_callback('~<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', static function ($m) {
            $label = trim(strip_tags($m[2]));
            return $label !== '' && $label !== $m[1] ? "$label ({$m[1]})" : $m[1];
        }, $text) ?? $text;
        $text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
        $text = preg_replace('~</(p|div|h[1-6]|li|tr|table|blockquote)>~i', "\n\n", $text) ?? $text;
        $text = preg_replace('~<li[^>]*>~i', '- ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
