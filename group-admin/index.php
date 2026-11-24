<?php
declare(strict_types=1);

/* =============================================================
   Admin portal: create, pause and delete groups.
   By design it can NOT see anything inside a group.
   ============================================================= */

require dirname(__DIR__) . '/_core/bootstrap.php';
gp_load_config('groupportal-admin-config.php');
gp_security_headers();
i18n_init('sv', defined('PORTAL_PROFILE') ? PORTAL_PROFILE : 'default');

const ADMIN_IDLE_SECONDS     = 30 * 60;
const ADMIN_MAX_SECONDS      = 8 * 3600;
const ADMIN_PENDING_SECONDS  = 5 * 60;

define('SELF', path('/group-admin/'));

gp_session_start(db_admin(), 'adm_sessions', 'gpadm', SELF, ADMIN_MAX_SECONDS);
demo_maybe_reset(true);

/* ----------------------------- helpers ----------------------------- */

function admin_log(?int $adminId, string $action, string $detail = ''): void
{
    db_exec(db_admin(), 'INSERT INTO adm_log (admin_id, action, detail, created_at) VALUES (?, ?, ?, ?)', [$adminId, $action, mb_substr($detail, 0, 255), now_db()]);
}

function admin_url(string $a = '', array $q = []): string
{
    if ($a !== '') {
        $q = ['a' => $a] + $q;
    }
    return SELF . ($q ? '?' . http_build_query($q) : '');
}

function site_url(string $path): string
{
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
    return $base . $path;
}

function admin_nav(string $active): array
{
    return [
        [admin_url(), t('adm.nav.groups'), $active === 'groups'],
        [admin_url('create'), t('adm.nav.create'), $active === 'create'],
        [admin_url('log'), t('adm.nav.log'), $active === 'log'],
        [admin_url('password'), t('adm.nav.password'), $active === 'password'],
    ];
}

function admin_header(): string
{
    return post_button(admin_url('logout'), t('nav.logout'), [], '', 'btn btn-ghost btn-small');
}

function page(string $title, string $body, string $active = ''): never
{
    $logged = isset($_SESSION['uid']);
    render_page($title, $body, $logged ? admin_nav($active) : [], $logged ? admin_header() : '', ['brand' => t('adm.brand')]);
}

function complete_login(array $admin): never
{
    unset($_SESSION['adm_pending'], $_SESSION['adm_pending_at'], $_SESSION['totp_new']);
    gp_session_login();
    $_SESSION['uid'] = (int) $admin['id'];
    $_SESSION['login_at'] = time();
    $_SESSION['seen_at'] = time();
    db_exec(db_admin(), 'UPDATE adm_users SET last_login_at = ? WHERE id = ?', [now_db(), $admin['id']]);
    admin_log((int) $admin['id'], 'login');
    redirect((int) $admin['must_change_pw'] === 1 ? admin_url('password') : admin_url());
}

function pending_admin(): ?array
{
    $id = $_SESSION['adm_pending'] ?? null;
    if (!is_int($id) || (time() - (int) ($_SESSION['adm_pending_at'] ?? 0)) > ADMIN_PENDING_SECONDS) {
        unset($_SESSION['adm_pending'], $_SESSION['adm_pending_at'], $_SESSION['totp_new']);
        return null;
    }
    return db_one(db_admin(), 'SELECT * FROM adm_users WHERE id = ?', [$id]);
}

/* ------------------------- session timeouts ------------------------- */

if (isset($_SESSION['uid'])) {
    $tooIdle = time() - (int) ($_SESSION['seen_at'] ?? 0) > ADMIN_IDLE_SECONDS;
    $tooOld  = time() - (int) ($_SESSION['login_at'] ?? 0) > ADMIN_MAX_SECONDS;
    if ($tooIdle || $tooOld) {
        gp_session_logout();
        gp_session_start(db_admin(), 'adm_sessions', 'gpadm', SELF, ADMIN_MAX_SECONDS);
        flash('info', t('auth.timed_out'));
        redirect(SELF);
    }
    $_SESSION['seen_at'] = time();
}

$a = get_str('a');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost && !csrf_check()) {
    flash('error', t('err.form_expired'));
    redirect(SELF);
}

/* ===================== not logged in: login + TOTP ===================== */

if (!isset($_SESSION['uid'])) {

    if ($a === 'totp') {
        $admin = pending_admin();
        if ($admin === null) {
            redirect(SELF);
        }
        $hasTotp = $admin['totp_secret'] !== null;

        if ($isPost) {
            if ($hasTotp) {
                $secret = gp_decrypt((string) $admin['totp_secret']);
                $last = $admin['totp_last_step'] === null ? null : (int) $admin['totp_last_step'];
                $step = $secret === null ? null : totp_verify($secret, post_str('code'), $last);
                if ($step === null) {
                    admin_log((int) $admin['id'], 'totp_failed');
                    flash('error', t('auth.code_wrong'));
                    redirect(admin_url('totp'));
                }
                db_exec(db_admin(), 'UPDATE adm_users SET totp_last_step = ? WHERE id = ?', [$step, $admin['id']]);
                complete_login($admin);
            }
            $new = $_SESSION['totp_new'] ?? '';
            $step = is_string($new) && $new !== '' ? totp_verify($new, post_str('code'), null) : null;
            if ($step === null) {
                flash('error', t('auth.code_wrong'));
                redirect(admin_url('totp'));
            }
            db_exec(db_admin(), 'UPDATE adm_users SET totp_secret = ?, totp_last_step = ? WHERE id = ?', [gp_encrypt($new), $step, $admin['id']]);
            admin_log((int) $admin['id'], 'totp_enabled');
            complete_login($admin);
        }

        if ($hasTotp) {
            page(t('auth.totp_title'), '
<section class="card narrow">
  <h1>' . e(t('auth.totp_title')) . '</h1>
  <p class="muted">' . e(t('auth.totp_enter')) . '</p>
  <form method="post" action="' . e(admin_url('totp')) . '" class="stack">' . csrf_field() . '
    <label for="code">' . e(t('auth.code')) . '</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus>
    <button class="btn" type="submit">' . e(t('auth.verify')) . '</button>
  </form>
</section>');
        }

        $_SESSION['totp_new'] ??= totp_new_secret();
        $secret = (string) $_SESSION['totp_new'];
        $issuer = rawurlencode(t('adm.brand'));
        $uri = 'otpauth://totp/' . $issuer . ':' . rawurlencode((string) $admin['username']) . '?secret=' . $secret . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30';
        page(t('auth.totp_setup_title'), '
<section class="card narrow">
  <h1>' . e(t('auth.totp_setup_title')) . '</h1>
  <p>' . e(t('auth.totp_setup_intro')) . '</p>
  <p class="secret"><code data-copy>' . e(trim(chunk_split($secret, 4, ' '))) . '</code></p>
  <details><summary>' . e(t('auth.totp_uri')) . '</summary><p><code class="wrap" data-copy>' . e($uri) . '</code></p></details>
  <form method="post" action="' . e(admin_url('totp')) . '" class="stack">' . csrf_field() . '
    <label for="code">' . e(t('auth.totp_confirm')) . '</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required>
    <button class="btn" type="submit">' . e(t('auth.verify')) . '</button>
  </form>
</section>');
    }

    if ($isPost && $a === 'login') {
        $username = clean_line(post_str('username'), 60);
        $password = post_str('password');
        $admin = db_one(db_admin(), 'SELECT * FROM adm_users WHERE username = ?', [$username]);
        if ($admin === null) {
            pw_dummy_verify($password);
            flash('error', t('auth.failed'));
            redirect(SELF);
        }
        if ($admin['locked_until'] !== null && $admin['locked_until'] > now_db()) {
            flash('error', t('auth.locked'));
            redirect(SELF);
        }
        if (!password_verify($password, (string) $admin['password_hash'])) {
            $fails = (int) $admin['failed_logins'] + 1;
            $lock = lock_seconds($fails);
            db_exec(db_admin(), 'UPDATE adm_users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails, $lock > 0 ? now_db($lock) : null, $admin['id']]);
            admin_log((int) $admin['id'], 'login_failed');
            flash('error', t('auth.failed'));
            redirect(SELF);
        }
        db_exec(db_admin(), 'UPDATE adm_users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$admin['id']]);
        if (password_needs_rehash((string) $admin['password_hash'], pw_algo())) {
            db_exec(db_admin(), 'UPDATE adm_users SET password_hash = ? WHERE id = ?', [pw_hash($password), $admin['id']]);
        }
        if (is_demo() && demo_protected((string) $admin['username'])) {
            complete_login($admin); // demo admin: no two-step login
        }
        session_regenerate_id(true);
        $_SESSION['adm_pending'] = (int) $admin['id'];
        $_SESSION['adm_pending_at'] = time();
        redirect(admin_url('totp'));
    }

    page(t('auth.login'), '
<section class="card narrow">
  <h1>' . e(t('adm.brand')) . '</h1>
  <form method="post" action="' . e(admin_url('login')) . '" class="stack">' . csrf_field() . '
    <label for="username">' . e(t('field.username')) . '</label>
    <input id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    <label for="password">' . e(t('field.password')) . '</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn" type="submit">' . e(t('auth.login')) . '</button>
  </form>
</section>' . demo_login_help(true));
}

/* ============================ logged in ============================ */

$me = db_one(db_admin(), 'SELECT * FROM adm_users WHERE id = ?', [$_SESSION['uid']]);
if ($me === null) {
    gp_session_logout();
    redirect(SELF);
}
$myId = (int) $me['id'];

if ((int) $me['must_change_pw'] === 1 && $a !== 'password' && $a !== 'logout') {
    redirect(admin_url('password'));
}

/* ----------------------------- logout ----------------------------- */

if ($a === 'logout' && $isPost) {
    admin_log($myId, 'logout');
    gp_session_logout();
    redirect(SELF);
}

/* ------------------------- change password ------------------------- */

if ($a === 'password') {
    if ($isPost) {
        if (demo_protected((string) $me['username'])) {
            flash('error', t('demo.protected'));
            redirect(admin_url('password'));
        }
        $current = post_str('current');
        $new = post_str('new');
        if (!password_verify($current, (string) $me['password_hash'])) {
            flash('error', t('pw.current_wrong'));
            redirect(admin_url('password'));
        }
        if ($new !== post_str('new2')) {
            flash('error', t('pw.mismatch'));
            redirect(admin_url('password'));
        }
        if (($err = pw_policy_error($new, (string) $me['username'])) !== null || hash_equals($current, $new)) {
            flash('error', t($err ?? 'pw.same_as_old', ['min' => PW_MIN_LEN]));
            redirect(admin_url('password'));
        }
        db_exec(db_admin(), 'UPDATE adm_users SET password_hash = ?, must_change_pw = 0 WHERE id = ?', [pw_hash($new), $myId]);
        db_exec(db_admin(), 'DELETE FROM adm_sessions WHERE user_id = ? AND id <> ?', [$myId, hash('sha256', session_id())]);
        admin_log($myId, 'password_changed');
        flash('ok', t('pw.changed'));
        redirect(SELF);
    }
    $forced = (int) $me['must_change_pw'] === 1 ? '<p class="notice">' . e(t('pw.must_change')) . '</p>' : '';
    page(t('adm.nav.password'), '
<section class="card narrow">
  <h1>' . e(t('adm.nav.password')) . '</h1>' . $forced . '
  <form method="post" action="' . e(admin_url('password')) . '" class="stack">' . csrf_field() . '
    <label for="current">' . e(t('pw.current')) . '</label>
    <input id="current" name="current" type="password" autocomplete="current-password" required>
    <label for="new">' . e(t('pw.new')) . '</label>
    <input id="new" name="new" type="password" autocomplete="new-password" minlength="' . PW_MIN_LEN . '" required>
    <small class="hint">' . e(t('pw.rules', ['min' => PW_MIN_LEN])) . '</small>
    <label for="new2">' . e(t('pw.repeat')) . '</label>
    <input id="new2" name="new2" type="password" autocomplete="new-password" required>
    <button class="btn" type="submit">' . e(t('pw.save')) . '</button>
  </form>
</section>', 'password');
}

/* ------------------------------- log ------------------------------- */

if ($a === 'log') {
    db_exec(db_admin(), 'DELETE FROM adm_log WHERE created_at < ?', [now_db(-LOG_KEEP_DAYS * 86400)]);
    $rows = db_all(db_admin(), 'SELECT l.*, u.username FROM adm_log l LEFT JOIN adm_users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 300');
    $html = '<section class="card"><h1>' . e(t('adm.nav.log')) . '</h1><p class="muted">' . e(t('log.kept', ['days' => LOG_KEEP_DAYS])) . '</p><table class="table"><thead><tr><th>' . e(t('log.when')) . '</th><th>' . e(t('log.who')) . '</th><th>' . e(t('log.what')) . '</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr><td class="nowrap">' . e(substr((string) $r['created_at'], 0, 16)) . '</td><td>' . e($r['username'] ?? '–') . '</td><td>' . e($r['action']) . ($r['detail'] !== '' ? ' <span class="muted">' . e($r['detail']) . '</span>' : '') . '</td></tr>';
    }
    page(t('adm.nav.log'), $html . '</tbody></table></section>', 'log');
}

/* --------------------------- create group --------------------------- */

if ($a === 'create') {
    $form = [
        'profile' => defined('PORTAL_PROFILE') ? PORTAL_PROFILE : 'default',
        'lang' => 'sv', 'country' => '', 'city' => '', 'name' => '', 'code' => '', 'slug' => '',
    ];
    $errors = [];

    if ($isPost) {
        $form['profile'] = in_array(post_str('profile'), group_profiles(), true) ? post_str('profile') : $form['profile'];
        $form['lang'] = in_array(post_str('lang'), LANGS, true) ? post_str('lang') : 'sv';
        $single = profile_meta($form['profile'])['levels'] === 1;
        $form['country'] = $single ? '' : clean_line(post_str('country'), 100);
        $form['city'] = $single ? '' : clean_line(post_str('city'), 100);
        $form['name'] = clean_line(post_str('name'), 150);
        $form['code'] = strtoupper(clean_line(post_str('code'), 3));
        $form['slug'] = slug_path_clean(post_str('slug'));
        if ($form['slug'] === '') {
            $form['slug'] = slug_path([$form['country'], $form['city'], $form['name']]);
        }

        if ($form['name'] === '') {
            $errors[] = t('adm.err.name');
        }
        if (!$single && ($form['country'] === '' || $form['city'] === '')) {
            $errors[] = t('adm.err.country_city');
        }
        if (!valid_group_code($form['code'])) {
            $errors[] = t('adm.err.code_format');
        } elseif (group_by_code($form['code']) !== null) {
            $errors[] = t('adm.err.code_taken');
        }
        if ($form['slug'] === '' || slug_is_reserved($form['slug'])) {
            $errors[] = t('adm.err.slug_invalid');
        } elseif (group_by_slug($form['slug']) !== null || db_val(db(), 'SELECT 1 FROM grp_slug_history WHERE old_slug = ?', [$form['slug']]) !== null) {
            $errors[] = t('adm.err.slug_taken');
        }

        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                db_exec($pdo, 'INSERT INTO grp_groups (code, slug, country, city, name, profile, lang, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $form['code'], $form['slug'], $form['country'], $form['city'], $form['name'], $form['profile'], $form['lang'], 'active', now_db(),
                ]);
                $roles = create_fixed_roles($form['code']);
                [$uid, $username, $otp] = create_member($form['code'], null);
                assign_role($form['code'], $uid, $roles['chair']);
                group_log($form['code'], null, 'group.created', 'admin');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('groupportal: create group failed: ' . $e->getMessage());
                flash('error', t('err.generic'));
                redirect(admin_url('create'));
            }
            admin_log($myId, 'group.created', $form['code'] . ' ' . $form['slug']);
            $group = group_by_code($form['code']);
            page(t('adm.created_title'), '
<section class="card"><h1>' . e(t('adm.created_title')) . '</h1>
<p>' . e(t('adm.created_intro', ['name' => $form['name'], 'role' => t('role.chair')])) . '</p></section>'
                . credentials_box(site_url(group_url($group)), $username, $otp)
                . '<p><a class="btn btn-ghost" href="' . e(admin_url()) . '">' . e(t('adm.back')) . '</a></p>', 'create');
        }
    }

    $errHtml = '';
    foreach ($errors as $err) {
        $errHtml .= '<div class="flash flash-error">' . e($err) . '</div>';
    }
    $profileOpts = '';
    foreach (group_profiles() as $p) {
        $meta = profile_meta($p);
        $profileOpts .= '<option value="' . e($p) . '" data-levels="' . $meta['levels'] . '"' . ($form['profile'] === $p ? ' selected' : '') . '>' . e($meta['label']) . '</option>';
    }
    $langOpts = '';
    foreach (LANGS as $l) {
        $langOpts .= '<option value="' . e($l) . '"' . ($form['lang'] === $l ? ' selected' : '') . '>' . e(t('lang.' . $l)) . '</option>';
    }
    page(t('adm.nav.create'), $errHtml . '
<section class="card">
  <h1>' . e(t('adm.nav.create')) . '</h1>
  <form method="post" action="' . e(admin_url('create')) . '" class="stack" data-slug-form>' . csrf_field() . '
    <label for="profile">' . e(t('adm.profile')) . '</label>
    <select id="profile" name="profile" data-profile>' . $profileOpts . '</select>
    <div data-multi>
      <label for="country">' . e(t('adm.country')) . '</label>
      <input id="country" name="country" value="' . e($form['country']) . '" maxlength="100" data-slug-part placeholder="' . e(t('adm.country_ph')) . '">
      <label for="city">' . e(t('adm.city')) . '</label>
      <input id="city" name="city" value="' . e($form['city']) . '" maxlength="100" data-slug-part placeholder="' . e(t('adm.city_ph')) . '">
    </div>
    <label for="name">' . e(t('adm.name')) . '</label>
    <input id="name" name="name" value="' . e($form['name']) . '" maxlength="150" required data-slug-part>
    <label for="code">' . e(t('adm.code')) . '</label>
    <input id="code" name="code" value="' . e($form['code']) . '" maxlength="3" pattern="[A-Za-z]{3}" required class="code-input" autocapitalize="characters">
    <small class="hint">' . e(t('adm.code_hint')) . '</small>
    <label for="slug">' . e(t('adm.slug')) . '</label>
    <input id="slug" name="slug" value="' . e($form['slug']) . '" maxlength="190" data-slug-out>
    <small class="hint">' . e(t('adm.slug_hint')) . '</small>
    <label for="lang">' . e(t('adm.lang')) . '</label>
    <select id="lang" name="lang">' . $langOpts . '</select>
    <button class="btn" type="submit">' . e(t('adm.create_btn', ['role' => t('role.chair')])) . '</button>
  </form>
</section>', 'create');
}

/* ---------------------------- one group ---------------------------- */

if ($a === 'group') {
    $code = strtoupper(get_str('code'));
    $group = valid_group_code($code) ? group_by_code($code) : null;
    if ($group === null) {
        flash('error', t('adm.err.no_group'));
        redirect(SELF);
    }
    $back = admin_url('group', ['code' => $code]);

    if ($isPost) {
        $do = post_str('do');
        if (is_demo() && $code === DEMO_GROUP_CODE && in_array($do, ['suspend', 'delete', 'slug', 'reset_chair'], true)) {
            flash('error', t('demo.protected'));
            redirect($back);
        }
        if ($do === 'suspend' && $group['status'] === 'active') {
            db_exec(db(), 'UPDATE grp_groups SET status = ? WHERE code = ?', ['suspended', $code]);
            db_exec(db(), 'DELETE s FROM grp_sessions s JOIN grp_memberships m ON m.user_id = s.user_id WHERE m.group_code = ?', [$code]);
            admin_log($myId, 'group.suspended', $code);
            flash('ok', t('adm.suspended'));
        } elseif ($do === 'activate' && $group['status'] !== 'active') {
            db_exec(db(), 'UPDATE grp_groups SET status = ?, deleted_at = NULL WHERE code = ?', ['active', $code]);
            admin_log($myId, 'group.activated', $code);
            flash('ok', t('adm.activated'));
        } elseif ($do === 'delete' && $group['status'] !== 'deleted') {
            if (strtoupper(post_str('confirm_code')) !== $code) {
                flash('error', t('adm.err.confirm_code'));
                redirect($back);
            }
            db_exec(db(), 'UPDATE grp_groups SET status = ?, deleted_at = ? WHERE code = ?', ['deleted', now_db(), $code]);
            db_exec(db(), 'DELETE s FROM grp_sessions s JOIN grp_memberships m ON m.user_id = s.user_id WHERE m.group_code = ?', [$code]);
            admin_log($myId, 'group.deleted', $code);
            flash('ok', t('adm.deleted', ['days' => GROUP_PURGE_DAYS]));
        } elseif ($do === 'slug') {
            $slug = slug_path_clean(post_str('slug'));
            $taken = group_by_slug($slug) !== null || db_val(db(), 'SELECT 1 FROM grp_slug_history WHERE old_slug = ? AND group_code <> ?', [$slug, $code]) !== null;
            if ($slug === '' || slug_is_reserved($slug) || $taken) {
                flash('error', t('adm.err.slug_taken'));
                redirect($back);
            }
            db_exec(db(), 'DELETE FROM grp_slug_history WHERE old_slug = ?', [$slug]);
            db_exec(db(), 'INSERT IGNORE INTO grp_slug_history (old_slug, group_code, created_at) VALUES (?, ?, ?)', [$group['slug'], $code, now_db()]);
            db_exec(db(), 'UPDATE grp_groups SET slug = ? WHERE code = ?', [$slug, $code]);
            admin_log($myId, 'group.slug', $code . ' ' . $group['slug'] . ' -> ' . $slug);
            flash('ok', t('adm.slug_changed'));
        } elseif ($do === 'reset_chair') {
            $uid = (int) post_str('user_id');
            if (!user_is_chair($code, $uid)) {
                flash('error', t('err.generic'));
                redirect($back);
            }
            $otp = reset_one_time_password($uid);
            $username = (string) db_val(db(), 'SELECT username FROM grp_users WHERE id = ?', [$uid]);
            admin_log($myId, 'chair.reset', $code . ' ' . $username);
            group_log($code, null, 'user.password_reset', $username . ' (admin)');
            page(t('adm.reset_title'), credentials_box(site_url(group_url($group)), $username, $otp) . '<p><a class="btn btn-ghost" href="' . e($back) . '">' . e(t('adm.back')) . '</a></p>');
        } elseif ($do === 'new_chair') {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                [$uid, $username, $otp] = create_member($code, null);
                assign_role($code, $uid, (int) role_id($code, 'chair'));
                group_log($code, null, 'user.created', $username . ' (admin, ' . t('role.chair') . ')');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('groupportal: new chair failed: ' . $e->getMessage());
                flash('error', t('err.generic'));
                redirect($back);
            }
            admin_log($myId, 'chair.created', $code . ' ' . $username);
            page(t('adm.new_chair_title', ['role' => t('role.chair')]), credentials_box(site_url(group_url($group)), $username, $otp) . '<p><a class="btn btn-ghost" href="' . e($back) . '">' . e(t('adm.back')) . '</a></p>');
        }
        redirect($back);
    }

    $chairs = db_all(db(), 'SELECT u.id, u.username, u.last_login_at FROM grp_member_roles mr JOIN grp_roles r ON r.id = mr.role_id JOIN grp_users u ON u.id = mr.user_id WHERE mr.group_code = ? AND r.role_key = ? ORDER BY u.username', [$code, 'chair']);
    $members = (int) db_val(db(), 'SELECT COUNT(*) FROM grp_memberships WHERE group_code = ? AND status = ?', [$code, 'active']);

    $chairHtml = '';
    foreach ($chairs as $g) {
        $chairHtml .= '<li><code>' . e($g['username']) . '</code> <span class="muted small">' . e(t('adm.last_login')) . ': ' . e($g['last_login_at'] ? substr((string) $g['last_login_at'], 0, 10) : '–') . '</span> '
            . post_button($back, t('adm.reset_otp'), ['do' => 'reset_chair', 'user_id' => $g['id']], t('adm.reset_confirm', ['user' => $g['username']])) . '</li>';
    }

    $statusActions = '';
    if ($group['status'] === 'active') {
        $statusActions .= post_button($back, t('adm.suspend'), ['do' => 'suspend'], t('adm.suspend_confirm'), 'btn btn-ghost');
    } else {
        $statusActions .= post_button($back, $group['status'] === 'deleted' ? t('adm.restore') : t('adm.activate'), ['do' => 'activate'], '', 'btn');
    }

    $deleteHtml = '';
    if ($group['status'] !== 'deleted') {
        $deleteHtml = '
<section class="card danger">
  <h2>' . e(t('adm.delete_title')) . '</h2>
  <p class="muted">' . e(t('adm.delete_intro', ['days' => GROUP_PURGE_DAYS])) . '</p>
  <form method="post" action="' . e($back) . '" class="stack">' . csrf_field() . '
    <input type="hidden" name="do" value="delete">
    <label for="confirm_code">' . e(t('adm.delete_type_code', ['code' => $code])) . '</label>
    <input id="confirm_code" name="confirm_code" maxlength="3" required class="code-input" autocomplete="off">
    <button class="btn btn-danger" type="submit">' . e(t('adm.delete_btn')) . '</button>
  </form>
</section>';
    } else {
        $purge = date('Y-m-d', strtotime((string) $group['deleted_at']) + GROUP_PURGE_DAYS * 86400);
        $deleteHtml = '<p class="notice">' . e(t('adm.purge_on', ['date' => $purge])) . '</p>';
    }

    page($group['name'], '
<section class="card">
  <h1><span class="badge">' . e($code) . '</span> ' . e($group['name']) . '</h1>
  <dl class="facts">
    <dt>' . e(t('adm.address')) . '</dt><dd><code>' . e(site_url(group_url($group))) . '</code></dd>
    <dt>' . e(t('adm.status')) . '</dt><dd>' . e(t('status.' . $group['status'])) . '</dd>
    <dt>' . e(t('adm.profile')) . '</dt><dd>' . e(profile_meta((string) $group['profile'])['label']) . ' · ' . e(t('lang.' . $group['lang'])) . '</dd>
    <dt>' . e(t('adm.members')) . '</dt><dd>' . $members . '</dd>
  </dl>
  <div class="row">' . $statusActions . '</div>
</section>
<section class="card">
  <h2>' . e(t('role.chair')) . '</h2>
  <ul class="list">' . ($chairHtml ?: '<li class="muted">' . e(t('adm.no_chair')) . '</li>') . '</ul>
  <p class="muted small">' . e(t('adm.chair_help')) . '</p>
  ' . post_button($back, t('adm.new_chair_btn', ['role' => t('role.chair')]), ['do' => 'new_chair'], t('adm.new_chair_confirm', ['role' => t('role.chair')]), 'btn btn-ghost') . '
</section>
<section class="card">
  <h2>' . e(t('adm.change_slug')) . '</h2>
  <form method="post" action="' . e($back) . '" class="stack">' . csrf_field() . '
    <input type="hidden" name="do" value="slug">
    <input name="slug" value="' . e($group['slug']) . '" maxlength="190" required>
    <small class="hint">' . e(t('adm.slug_change_hint')) . '</small>
    <button class="btn btn-ghost" type="submit">' . e(t('adm.save')) . '</button>
  </form>
</section>' . $deleteHtml . '
<p><a href="' . e(admin_url()) . '">← ' . e(t('adm.back')) . '</a></p>', 'groups');
}

/* --------------------------- group list --------------------------- */

if (maybe(10)) {
    groups_housekeeping();
}

$groups = db_all(db(), 'SELECT g.code, g.name, g.slug, g.status, g.created_at,
    (SELECT COUNT(*) FROM grp_memberships m WHERE m.group_code = g.code AND m.status = ?) AS members
    FROM grp_groups g ORDER BY FIELD(g.status, ?, ?, ?), g.code', ['active', 'active', 'suspended', 'deleted']);

$rows = '';
foreach ($groups as $g) {
    $rows .= '<tr class="status-' . e($g['status']) . '"><td><a href="' . e(admin_url('group', ['code' => $g['code']])) . '"><span class="badge">' . e($g['code']) . '</span></a></td>'
        . '<td><a href="' . e(admin_url('group', ['code' => $g['code']])) . '">' . e($g['name']) . '</a><br><span class="muted small">/' . e($g['slug']) . '/</span></td>'
        . '<td>' . (int) $g['members'] . '</td><td>' . e(t('status.' . $g['status'])) . '</td></tr>';
}

page(t('adm.nav.groups'), '
<section class="card">
  <div class="row between"><h1>' . e(t('adm.nav.groups')) . '</h1><a class="btn" href="' . e(admin_url('create')) . '">' . e(t('adm.nav.create')) . '</a></div>
  ' . ($rows === '' ? '<p class="muted">' . e(t('adm.no_groups')) . '</p>' : '<table class="table"><thead><tr><th>' . e(t('adm.code_short')) . '</th><th>' . e(t('adm.name')) . '</th><th>' . e(t('adm.members')) . '</th><th>' . e(t('adm.status')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>') . '
  <p class="muted small">' . e(t('adm.privacy_note')) . '</p>
</section>', 'groups');
