<?php
declare(strict_types=1);

/* =============================================================
   One-time setup: creates the first admin account.
   - Requires SETUP_CODE from the private config file.
   - Refuses to run once any admin exists.
   - Deletes itself after success (or tells you to do it).
   ============================================================= */

require dirname(__DIR__) . '/_core/bootstrap.php';
gp_load_config('groupportal-admin-config.php');
gp_security_headers();
i18n_init('sv', 'default');

if ((int) db_val(db_admin(), 'SELECT COUNT(*) FROM adm_users') > 0) {
    http_response_code(404);
    exit('Not found');
}
if (!defined('SETUP_CODE') || strlen((string) SETUP_CODE) < 12) {
    gp_fail('SETUP_CODE missing in admin config');
}
foreach (['sodium_crypto_secretbox', 'password_hash'] as $fn) {
    if (!function_exists($fn)) {
        gp_fail('missing PHP function: ' . $fn);
    }
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $username = clean_line(post_str('username'), 60);
    $password = post_str('password');
    if ($site !== '' && $site !== 'same-origin') {
        $error = t('err.form_expired');
    } elseif (!hash_equals((string) SETUP_CODE, post_str('setup_code'))) {
        sleep(2);
        $error = t('setup.code_wrong');
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        $error = t('setup.username_rules');
    } elseif ($password !== post_str('password2')) {
        $error = t('pw.mismatch');
    } elseif (($k = pw_policy_error($password, $username)) !== null) {
        $error = t($k, ['min' => PW_MIN_LEN]);
    } else {
        db_exec(db_admin(), 'INSERT INTO adm_users (username, password_hash, must_change_pw, failed_logins, created_at) VALUES (?, ?, 0, 0, ?)', [$username, pw_hash($password), now_db()]);
        db_exec(db_admin(), 'INSERT INTO adm_log (admin_id, action, detail, created_at) VALUES (?, ?, ?, ?)', [(int) db_admin()->lastInsertId(), 'setup', 'first admin created', now_db()]);
        $deleted = @unlink(__FILE__);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="sv"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup</title><link rel="stylesheet" href="' . e(asset('app.css')) . '"></head><body><main class="wrap"><section class="card narrow"><h1>'
            . e(t('setup.done')) . '</h1><p>' . e($deleted ? t('setup.deleted') : t('setup.delete_manually')) . '</p><p><a class="btn" href="' . e(path('/group-admin/')) . '">' . e(t('auth.login')) . '</a></p></section></main></body></html>';
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('setup.title')) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<main class="wrap">
  <section class="card narrow">
    <h1><?= e(t('setup.title')) ?></h1>
    <p class="muted"><?= e(t('setup.intro')) ?></p>
    <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(path('/group-admin/setup.php')) ?>" class="stack">
      <label for="setup_code"><?= e(t('setup.code')) ?></label>
      <input id="setup_code" name="setup_code" type="password" required autocomplete="off">
      <label for="username"><?= e(t('field.username')) ?></label>
      <input id="username" name="username" required autocomplete="username" autocapitalize="none" value="<?= e(post_str('username')) ?>">
      <label for="password"><?= e(t('field.password')) ?></label>
      <input id="password" name="password" type="password" required autocomplete="new-password" minlength="<?= PW_MIN_LEN ?>">
      <small class="hint"><?= e(t('pw.rules', ['min' => PW_MIN_LEN])) ?></small>
      <label for="password2"><?= e(t('pw.repeat')) ?></label>
      <input id="password2" name="password2" type="password" required autocomplete="new-password">
      <button class="btn" type="submit"><?= e(t('setup.create')) ?></button>
    </form>
  </section>
</main>
</body>
</html>
