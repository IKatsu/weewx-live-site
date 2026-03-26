<?php

declare(strict_types=1);

function page_view_context(array $config): array
{
    $cssConfig = (array) (($config['ui']['css'] ?? []));
    $timeConfig = (array) (($config['ui']['time'] ?? []));

    return [
        'css_base' => (string) ($cssConfig['base'] ?? 'assets/css/base.css'),
        'css_themes' => (array) ($cssConfig['themes'] ?? ['bright' => 'assets/css/theme-bright.css']),
        'default_theme' => (string) ($cssConfig['default_theme'] ?? 'bright'),
        'css_custom' => (string) ($cssConfig['custom'] ?? ''),
        'time_format' => (string) ($timeConfig['format'] ?? '24h'),
    ];
}

function render_page_head(string $title, array $view): void
{
    $defaultTheme = (string) ($view['default_theme'] ?? 'bright');
    ?>
<!doctype html>
<html lang="en" data-theme="<?= htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8') ?>">
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

/**
 * @param array<int, array{href:string,label:string}> $navLinks
 * @param array<int, string> $statusHtml
 */
function render_site_header(string $title, array $navLinks, array $statusHtml = []): void
{
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
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
                <span>Theme:</span>
                <select id="theme-select"></select>
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
function default_nav_links(?array $config = null): array
{
    $links = [
        ['href' => 'index.php', 'label' => 'Dashboard'],
        ['href' => 'trends.php', 'label' => 'Trends'],
        ['href' => 'history.php', 'label' => 'History'],
        ['href' => 'prediction.php', 'label' => 'Prediction'],
    ];

    $cfg = $config ?? app_config();
    $debug = (array) ($cfg['debug'] ?? []);
    if (($debug['enabled'] ?? false) === true && ($debug['show_nav'] ?? false) === true) {
        $links[] = ['href' => 'debug.php', 'label' => 'Debug'];
    }

    return $links;
}
