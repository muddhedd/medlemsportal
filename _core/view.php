<?php
declare(strict_types=1);

/**
 * Minimal page shell. $nav is a list of [href, label, active].
 * $body is already-escaped HTML produced by the page.
 */
function render_page(string $title, string $body, array $nav = [], string $headerExtra = '', array $opts = []): never
{
    $flashes = flash_take();
    $manifest = $opts['manifest'] ?? '';
    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#3F6E63">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="icon" href="<?= e(asset('icon.svg')) ?>" type="image/svg+xml">
<?php if ($manifest !== ''): ?>
<link rel="manifest" href="<?= e($manifest) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('icon-192.png')) ?>">
<?php endif; ?>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <span class="brand"><?= e($opts['brand'] ?? $title) ?></span>
    <?= $headerExtra ?>
  </div>
  <?php if ($nav): ?>
  <nav class="tabs" aria-label="<?= e(t('nav.menu')) ?>">
    <?php foreach ($nav as [$href, $label, $active]): ?>
      <a href="<?= e($href) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</header>
<main class="wrap">
  <?php if (is_demo()): ?><div class="demo-banner"><?= e(t('demo.banner', ['hour' => sprintf('%02d:00', DEMO_RESET_HOUR)])) ?></div><?php endif; ?>
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?= $body ?>
</main>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
<?php
    exit;
}

/** A POST form button with CSRF token and optional confirmation text. */
function post_button(string $action, string $label, array $hidden = [], string $confirm = '', string $class = 'btn btn-small'): string
{
    $h = '<form method="post" action="' . e($action) . '" class="inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    return $h . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}

/** Box that shows a freshly generated one-time password exactly once. */
function credentials_box(string $url, string $username, string $otp): string
{
    return '<div class="card credentials">'
        . '<h2>' . e(t('cred.title')) . '</h2>'
        . '<p class="muted">' . e(t('cred.only_once')) . '</p>'
        . '<dl class="cred">'
        . '<dt>' . e(t('cred.link')) . '</dt><dd><code data-copy>' . e($url) . '</code></dd>'
        . '<dt>' . e(t('field.username')) . '</dt><dd><code data-copy>' . e($username) . '</code></dd>'
        . '<dt>' . e(t('cred.otp')) . '</dt><dd><code data-copy>' . e($otp) . '</code></dd>'
        . '</dl>'
        . '<p class="muted small">' . e(t('cred.howto')) . '</p>'
        . '</div>';
}
