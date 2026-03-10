<?php

declare(strict_types=1);

function page_view_context(array $config): array
{
    init_i18n($config);
    $cssConfig = (array) (($config['ui']['css'] ?? []));
    $timeConfig = (array) (($config['ui']['time'] ?? []));
    $languages = (array) ($cssConfig['languages'] ?? ['en']);

    return [
        'css_base' => (string) ($cssConfig['base'] ?? 'assets/css/base.css'),
        'css_themes' => (array) ($cssConfig['themes'] ?? ['bright' => 'assets/css/theme-bright.css']),
        'default_theme' => (string) ($cssConfig['default_theme'] ?? 'bright'),
        'default_language' => (string) ($cssConfig['language_default'] ?? 'en'),
        'languages' => $languages,
        'current_language' => current_language(),
        'css_custom' => (string) ($cssConfig['custom'] ?? ''),
        'time_format' => (string) ($timeConfig['format'] ?? '24h'),
    ];
}

function render_page_head(string $title, array $view): void
{
    $defaultTheme = (string) ($view['default_theme'] ?? 'bright');
    $currentLanguage = (string) ($view['current_language'] ?? 'en');
    ?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLanguage, ENT_QUOTES, 'UTF-8') ?>" data-theme="<?= htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars((string) ($view['css_base'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ((array) ($view['css_themes'] ?? []) as $themePath): ?>
<?php if (is_string($themePath) && $themePath !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($themePath, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php $customCss = (string) ($view['css_custom'] ?? ''); ?>
<?php if ($customCss !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
</head>
<?php
}

function language_switch_url(string $langCode): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri === '') {
        $requestUri = (string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    }
    $parts = parse_url($requestUri);
    $path = (string) ($parts['path'] ?? 'index.php');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    $query['lang'] = $langCode;
    return $path . '?' . http_build_query($query);
}

/**
 * @param array<int, array{href:string,label:string}> $navLinks
 * @param array<int, string> $statusHtml
 */
function render_site_header(string $title, array $navLinks, array $statusHtml = []): void
{
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $languages = $GLOBALS['PWS_I18N']['available_languages'] ?? ['en'];
    $currentLanguage = current_language();
    ?>
    <header class="header">
        <h1 class="title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="status-row">
<?php foreach ($navLinks as $link): ?>
<?php
    $href = (string) ($link['href'] ?? '#');
    $label = (string) ($link['label'] ?? $href);
    $linkClass = basename($href) === $current ? 'status-pill is-active' : 'status-pill';
?>
            <a class="<?= $linkClass ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
            <label class="status-pill" for="theme-select">
                <span><?= htmlspecialchars(tr('common.theme', 'Theme') . ':', ENT_QUOTES, 'UTF-8') ?></span>
                <select id="theme-select"></select>
            </label>
            <label class="status-pill" for="language-select">
                <span><?= htmlspecialchars(tr('common.language', 'Language') . ':', ENT_QUOTES, 'UTF-8') ?></span>
                <select id="language-select" onchange="if (this.value) window.location = this.value;">
<?php foreach ($languages as $langCode): ?>
                    <option value="<?= htmlspecialchars(language_switch_url((string) $langCode), ENT_QUOTES, 'UTF-8') ?>"<?= $currentLanguage === $langCode ? ' selected' : '' ?>><?= htmlspecialchars(tr('language_names.' . $langCode, strtoupper((string) $langCode)), ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
                </select>
            </label>
<?php foreach ($statusHtml as $fragment): ?>
<?= $fragment . PHP_EOL ?>
<?php endforeach; ?>
        </div>
    </header>
<?php
}

/**
 * @return array<int, array{href:string,label:string}>
 */
function default_nav_links(): array
{
    return [
        ['href' => 'index.php', 'label' => tr('nav.dashboard', 'Dashboard')],
        ['href' => 'trends.php', 'label' => tr('nav.trends', 'Trends')],
        ['href' => 'history.php', 'label' => tr('nav.history', 'History')],
        ['href' => 'prediction.php', 'label' => tr('nav.prediction', 'Prediction')],
        ['href' => 'debug.php', 'label' => tr('nav.debug', 'Debug')],
    ];
}
