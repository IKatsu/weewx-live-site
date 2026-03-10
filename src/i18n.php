<?php

declare(strict_types=1);

function translation_nested_get(array $source, string $key): mixed
{
    $node = $source;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function translation_merge(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
            $base[$key] = translation_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

function load_language_file(string $code): array
{
    $path = __DIR__ . '/lang/' . $code . '.php';
    if (!is_file($path)) {
        return [];
    }
    $data = require $path;
    return is_array($data) ? $data : [];
}

function available_language_codes(array $config): array
{
    $raw = (array) (($config['ui']['css']['languages'] ?? []));
    $out = [];
    foreach ($raw as $code) {
        $lang = strtolower(trim((string) $code));
        if ($lang !== '' && preg_match('/^[a-z]{2}$/', $lang) === 1) {
            $out[] = $lang;
        }
    }
    return $out === [] ? ['en'] : array_values(array_unique($out));
}

function init_i18n(array $config): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $available = available_language_codes($config);
    $default = strtolower((string) (($config['ui']['css']['language_default'] ?? 'en')));
    if (!in_array($default, $available, true)) {
        $default = $available[0];
    }

    $selected = $default;
    $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
    $cookie = strtolower(trim((string) ($_COOKIE['pws_lang'] ?? '')));

    if ($requested !== '' && in_array($requested, $available, true)) {
        $selected = $requested;
        setcookie('pws_lang', $selected, [
            'expires' => time() + (365 * 24 * 3600),
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE['pws_lang'] = $selected;
    } elseif ($cookie !== '' && in_array($cookie, $available, true)) {
        $selected = $cookie;
    }

    $base = load_language_file('en');
    $selectedMap = $selected === 'en' ? $base : translation_merge($base, load_language_file($selected));

    $GLOBALS['PWS_I18N'] = [
        'language' => $selected,
        'default_language' => $default,
        'available_languages' => $available,
        'translations' => $selectedMap,
    ];
    $done = true;
}

function current_language(): string
{
    return (string) (($GLOBALS['PWS_I18N']['language'] ?? 'en'));
}

function current_translations(): array
{
    $map = $GLOBALS['PWS_I18N']['translations'] ?? [];
    return is_array($map) ? $map : [];
}

function tr(string $key, ?string $fallback = null, array $replace = []): string
{
    $value = translation_nested_get(current_translations(), $key);
    $text = is_string($value) ? $value : ($fallback ?? $key);
    foreach ($replace as $name => $replacement) {
        $text = str_replace('{' . $name . '}', (string) $replacement, $text);
    }
    return $text;
}

function tr_array(string $key, array $fallback = []): array
{
    $value = translation_nested_get(current_translations(), $key);
    return is_array($value) ? $value : $fallback;
}
