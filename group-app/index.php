<?php
declare(strict_types=1);

/* =============================================================
   Group app: one program serves every group.
   /country/city/group/ (or /association/) is routed here by the
   root .htaccess; the group is looked up from the address.
   Nothing is shown to anyone who is not logged in — not even
   whether a group exists at an address.
   ============================================================= */

require dirname(__DIR__) . '/_core/bootstrap.php';
gp_load_config('groupportal-group-config.php');
gp_security_headers();

const IDLE_SECONDS     = 2 * 3600;   // without "remember me"
const REMEMBER_SECONDS = 30 * 86400; // with "remember me"

/* ------------------------- resolve the group ------------------------- */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (base_path() !== '' && str_starts_with($path, base_path() . '/')) {
    $path = substr($path, strlen(base_path()));
}
$slug = trim(strtolower(rawurldecode($path)), '/');
if (!preg_match('~^[a-z0-9-]+(/[a-z0-9-]+){0,2}$~', $slug)) {
    http_response_code(404);
    exit('Not found');
}
if (!str_ends_with($path, '/')) {
    redirect(path('/' . $slug . '/') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

$group = group_by_slug($slug);
if ($group === null) {
    $moved = db_val(db(), 'SELECT g.slug FROM grp_slug_history h JOIN grp_groups g ON g.code = h.group_code WHERE h.old_slug = ?', [$slug]);
    if (is_string($moved)) {
        header('Location: ' . path('/' . $moved . '/') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
        exit;
    }
}

i18n_init($group['lang'] ?? (defined('DEFAULT_LANG') ? DEFAULT_LANG : 'sv'), $group['profile'] ?? (defined('DEFAULT_PROFILE') ? DEFAULT_PROFILE : 'default'));

$here = path('/' . $slug . '/');
$p = get_str('p');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* The manifest is fetched without cookies, so it must not need a login and reveals nothing. */
if ($p === 'manifest') {
    header('Content-Type: application/manifest+json; charset=UTF-8');
    echo json_encode([
        'name' => t('app.name'),
        'short_name' => t('app.name'),
        'start_url' => $here,
        'scope' => $here,
        'display' => 'standalone',
        'background_color' => '#F6F7F5',
        'theme_color' => '#3F6E63',
        'icons' => [
            ['src' => asset('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => asset('icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

gp_session_start(db(), 'grp_sessions', 'gpgrp', path('/'), REMEMBER_SECONDS);
demo_maybe_reset(false);

if (maybe(50)) {
    groups_housekeeping();
}

if ($isPost && !csrf_check()) {
    flash('error', t('err.form_expired'));
    redirect($here);
}

/* ------------------------------ helpers ------------------------------ */

function u(string $page = '', array $q = []): string
{
    global $here;
    if ($page !== '') {
        $q = ['p' => $page] + $q;
    }
    return $here . ($q ? '?' . http_build_query($q) : '');
}

function site_url(string $path): string
{
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
    return $base . $path;
}

function user_label(array $u): string
{
    $d = trim((string) ($u['display_name'] ?? ''));
    return $d !== '' ? $d : (string) $u['username'];
}

/** Optional contact fields (association profile only, switched on per group). */
function contact_enabled(?array $group): bool
{
    return $group !== null && profile_meta((string) $group['profile'])['contact'] && (int) ($group['contact_fields'] ?? 0) === 1;
}

/** Active groups this user is an active member of. */
function my_groups(int $uid): array
{
    return db_all(db(), 'SELECT g.code, g.name, g.slug FROM grp_memberships m JOIN grp_groups g ON g.code = m.group_code
        WHERE m.user_id = ? AND m.status = ? AND g.status = ? ORDER BY g.name', [$uid, 'active', 'active']);
}

function login_page(): never
{
    render_page(t('app.name'), '
<section class="card narrow">
  <h1>' . e(t('app.name')) . '</h1>
  <form method="post" action="' . e(u('login')) . '" class="stack">' . csrf_field() . '
    <label for="username">' . e(t('field.username')) . '</label>
    <input id="username" name="username" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus>
    <label for="password">' . e(t('field.password')) . '</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <label class="checkbox-row"><input type="checkbox" name="remember" value="1"> ' . e(t('auth.remember', ['days' => REMEMBER_SECONDS / 86400])) . '</label>
    <button class="btn" type="submit">' . e(t('auth.login')) . '</button>
  </form>
  <p class="muted small">' . e(t('auth.forgot')) . '</p>
</section>' . demo_login_help(false), [], '', ['manifest' => u('manifest')]);
}

/* ------------------------------ login ------------------------------ */

if ($isPost && $p === 'login') {
    $username = strtoupper(clean_line(post_str('username'), 20));
    $password = post_str('password');
    $user = db_one(db(), 'SELECT * FROM grp_users WHERE username = ?', [$username]);
    if ($user === null) {
        pw_dummy_verify($password);
        flash('error', t('auth.failed'));
        redirect($here);
    }
    if ($user['locked_until'] !== null && $user['locked_until'] > now_db()) {
        flash('error', t('auth.locked'));
        redirect($here);
    }
    if (!password_verify($password, (string) $user['password_hash'])) {
        $fails = (int) $user['failed_logins'] + 1;
        $lock = lock_seconds($fails);
        db_exec(db(), 'UPDATE grp_users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails, $lock > 0 ? now_db($lock) : null, $user['id']]);
        flash('error', t('auth.failed'));
        redirect($here);
    }
    if ((int) $user['must_change_pw'] === 1 && $user['otp_expires_at'] !== null && $user['otp_expires_at'] < now_db()) {
        flash('error', t('auth.otp_expired'));
        redirect($here);
    }
    $groups = my_groups((int) $user['id']);
    if (!$groups) {
        flash('error', t('auth.no_active_group'));
        redirect($here);
    }
    db_exec(db(), 'UPDATE grp_users SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [now_db(), $user['id']]);
    if (password_needs_rehash((string) $user['password_hash'], pw_algo())) {
        db_exec(db(), 'UPDATE grp_users SET password_hash = ? WHERE id = ?', [pw_hash($password), $user['id']]);
    }
    $remember = post_str('remember') === '1';
    gp_session_login($remember ? REMEMBER_SECONDS : 0);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['remember'] = $remember;
    $_SESSION['login_at'] = time();
    $_SESSION['seen_at'] = time();

    $target = null;
    foreach ($groups as $g) {
        if ($group !== null && $g['code'] === $group['code']) {
            $target = $g;
        }
    }
    $target ??= $groups[0];
    redirect(path('/' . $target['slug'] . '/') . ((int) $user['must_change_pw'] === 1 ? '?p=password' : ''));
}

/* --------------------------- session state --------------------------- */

if (!isset($_SESSION['uid'])) {
    login_page();
}

$idleLimit = !empty($_SESSION['remember']) ? REMEMBER_SECONDS : IDLE_SECONDS;
if (time() - (int) ($_SESSION['seen_at'] ?? 0) > $idleLimit) {
    gp_session_logout();
    login_page();
}
$_SESSION['seen_at'] = time();

$me = db_one(db(), 'SELECT * FROM grp_users WHERE id = ?', [$_SESSION['uid']]);
if ($me === null) {
    gp_session_logout();
    login_page();
}
$myId = (int) $me['id'];

// Personal language choice overrides the group's default language.
if (!empty($me['lang']) && in_array($me['lang'], LANGS, true) && $me['lang'] !== current_lang()) {
    i18n_init((string) $me['lang'], $group['profile'] ?? (defined('DEFAULT_PROFILE') ? DEFAULT_PROFILE : 'default'));
}

if ($isPost && $p === 'logout') {
    gp_session_logout();
    redirect($here);
}

$myGroups = my_groups($myId);

/* Forced password change comes before everything else (works on any group address). */
$mustChange = (int) $me['must_change_pw'] === 1;

/* Accept / decline invitations (available on every page of every group). */
if ($isPost && $p === 'invite_answer') {
    $code = strtoupper(post_str('code'));
    if (valid_group_code($code)) {
        if (post_str('answer') === 'yes') {
            db_exec(db(), 'UPDATE grp_memberships SET status = ? WHERE user_id = ? AND group_code = ? AND status = ?', ['active', $myId, $code, 'invited']);
            group_log($code, $myId, 'user.joined', (string) $me['username']);
            flash('ok', t('invite.accepted'));
        } else {
            db_exec(db(), 'DELETE FROM grp_memberships WHERE user_id = ? AND group_code = ? AND status = ?', [$myId, $code, 'invited']);
            flash('ok', t('invite.declined'));
        }
    }
    redirect($here);
}

$isMember = false;
if ($group !== null) {
    foreach ($myGroups as $g) {
        if ($g['code'] === $group['code']) {
            $isMember = true;
        }
    }
}

/* Not a member here (or unknown / paused address): show only the user's own groups. */
if (!$isMember && $p !== 'password') {
    $list = '';
    foreach ($myGroups as $g) {
        $list .= '<li><a href="' . e(path('/' . $g['slug'] . '/')) . '">' . e($g['name']) . '</a></li>';
    }
    render_page(t('app.name'), '
<section class="card narrow">
  <h1>' . e(t('app.name')) . '</h1>
  <p>' . e(t('auth.not_here')) . '</p>
  <ul class="list">' . ($list ?: '<li class="muted">' . e(t('auth.no_active_group')) . '</li>') . '</ul>
  ' . post_button(u('logout'), t('nav.logout'), [], '', 'btn btn-ghost') . '
</section>');
}

$perms = $isMember ? user_permissions($group['code'], $myId) : [];
function can(string $perm): bool
{
    global $perms;
    return in_array($perm, $perms, true);
}

function nav(string $active): array
{
    $n = [
        [u(), t('nav.home'), $active === 'home'],
        [u('calendar'), t('nav.meetings'), $active === 'calendar'],
        [u('formal'), t('nav.formal'), $active === 'formal'],
        [u('economy'), t('nav.economy'), $active === 'economy'],
        [u('keys'), t('nav.keys'), $active === 'keys'],
        [u('users'), t('nav.users'), $active === 'users'],
        [u('roles'), t('nav.roles'), $active === 'roles'],
        [u('info'), t('nav.info'), $active === 'info'],
    ];
    if (can('log.view')) {
        $n[] = [u('log'), t('nav.log'), $active === 'log'];
    }
    $n[] = [u('me'), t('nav.me'), $active === 'me'];
    return $n;
}

function page(string $title, string $body, string $active = ''): never
{
    global $group, $mustChange;
    $header = post_button(u('logout'), t('nav.logout'), [], '', 'btn btn-ghost btn-small');
    render_page($title . ' · ' . $group['name'], $body, $mustChange ? [] : nav($active), $header, [
        'brand' => $group['name'],
        'manifest' => u('manifest'),
    ]);
}

function deny(): never
{
    global $here;
    flash('error', t('err.no_permission'));
    redirect($here);
}

/* -------------------------- change password -------------------------- */

if ($p === 'password' || $mustChange) {
    if ($group === null || !$isMember) {
        // Forced change before membership is resolved: use the first group's address.
        if ($myGroups) {
            redirect(path('/' . $myGroups[0]['slug'] . '/') . '?p=password');
        }
        gp_session_logout();
        login_page();
    }
    if ($isPost && $p === 'password') {
        if (demo_protected((string) $me['username'])) {
            flash('error', t('demo.protected'));
            redirect(u('password'));
        }
        $current = post_str('current');
        $new = post_str('new');
        if (!password_verify($current, (string) $me['password_hash'])) {
            flash('error', t('pw.current_wrong'));
            redirect(u('password'));
        }
        if ($new !== post_str('new2')) {
            flash('error', t('pw.mismatch'));
            redirect(u('password'));
        }
        if (($err = pw_policy_error($new, (string) $me['username'])) !== null || hash_equals($current, $new)) {
            flash('error', t($err ?? 'pw.same_as_old', ['min' => PW_MIN_LEN]));
            redirect(u('password'));
        }
        db_exec(db(), 'UPDATE grp_users SET password_hash = ?, must_change_pw = 0, otp_expires_at = NULL WHERE id = ?', [pw_hash($new), $myId]);
        db_exec(db(), 'DELETE FROM grp_sessions WHERE user_id = ? AND id <> ?', [$myId, hash('sha256', session_id())]);
        group_log($group['code'], $myId, 'user.password_changed', (string) $me['username']);
        flash('ok', t('pw.changed'));
        redirect($here);
    }
    $forced = $mustChange ? '<p class="notice">' . e(t('pw.must_change')) . '</p>' : '';
    page(t('pw.title'), '
<section class="card narrow">
  <h1>' . e(t('pw.title')) . '</h1>' . $forced . '
  <form method="post" action="' . e(u('password')) . '" class="stack">' . csrf_field() . '
    <input type="text" name="username" value="' . e($me['username']) . '" autocomplete="username" hidden>
    <label for="current">' . e($mustChange ? t('pw.current_otp') : t('pw.current')) . '</label>
    <input id="current" name="current" type="password" autocomplete="current-password" required>
    <label for="new">' . e(t('pw.new')) . '</label>
    <input id="new" name="new" type="password" autocomplete="new-password" minlength="' . PW_MIN_LEN . '" required>
    <small class="hint">' . e(t('pw.rules', ['min' => PW_MIN_LEN])) . '</small>
    <label for="new2">' . e(t('pw.repeat')) . '</label>
    <input id="new2" name="new2" type="password" autocomplete="new-password" required>
    <button class="btn" type="submit">' . e(t('pw.save')) . '</button>
  </form>
</section>', 'me');
}

$code = $group['code'];

/* ------------------------------ my account ------------------------------ */

if ($p === 'me') {
    if ($isPost) {
        $do = post_str('do');
        if ($do === 'lang') {
            $lang = post_str('lang');
            db_exec(db(), 'UPDATE grp_users SET lang = ? WHERE id = ?', [in_array($lang, LANGS, true) ? $lang : null, $myId]);
            redirect(u('me'));
        }
        if ($do === 'contact' && contact_enabled($group)) {
            db_exec(db(), 'UPDATE grp_users SET full_name = ?, phone = ?, email = ? WHERE id = ?', [
                ($v = clean_line(post_str('full_name'), 100)) !== '' ? $v : null,
                ($v = clean_line(post_str('phone'), 40)) !== '' ? $v : null,
                ($v = clean_line(post_str('email'), 150)) !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null,
                $myId,
            ]);
            flash('ok', t('me.saved'));
            redirect(u('me'));
        }
        if ($do === 'display_name') {
            $name = clean_line(post_str('display_name'), 60);
            db_exec(db(), 'UPDATE grp_users SET display_name = ? WHERE id = ?', [$name === '' ? null : $name, $myId]);
            flash('ok', t('me.saved'));
        } elseif (($do === 'leave' || $do === 'delete') && demo_protected((string) $me['username'])) {
            flash('error', t('demo.protected'));
        } elseif ($do === 'leave') {
            if (count($myGroups) < 2) {
                flash('error', t('me.leave_last'));
            } elseif (user_is_chair($code, $myId) && chair_count($code) <= 1) {
                flash('error', t('me.last_chair', ['role' => t('role.chair')]));
            } else {
                group_log($code, $myId, 'user.left', (string) $me['username']);
                remove_member($code, $myId, false);
                foreach ($myGroups as $g) {
                    if ($g['code'] !== $code) {
                        redirect(path('/' . $g['slug'] . '/'));
                    }
                }
                redirect(path('/'));
            }
        } elseif ($do === 'delete') {
            if (!password_verify(post_str('password'), (string) $me['password_hash'])) {
                flash('error', t('pw.current_wrong'));
            } elseif (($blocked = groups_where_last_chair($myId)) !== []) {
                flash('error', t('me.last_chair', ['role' => t('role.chair')]) . ' (' . implode(', ', $blocked) . ')');
            } else {
                foreach ($myGroups as $g) {
                    group_log($g['code'], null, 'user.deleted_self', (string) $me['username']);
                }
                db_exec(db(), 'DELETE FROM grp_users WHERE id = ?', [$myId]);
                gp_kill_user_sessions(db(), 'grp_sessions', $myId);
                gp_session_logout();
                redirect(path('/'));
            }
        }
        redirect(u('me'));
    }

    $groupsHtml = '';
    foreach ($myGroups as $g) {
        $groupsHtml .= '<li><a href="' . e(path('/' . $g['slug'] . '/')) . '">' . e($g['name']) . '</a>' . ($g['code'] === $code ? ' <span class="muted small">(' . e(t('me.this_group')) . ')</span>' : '') . '</li>';
    }
    page(t('nav.me'), '
<section class="card">
  <h1>' . e(t('nav.me')) . '</h1>
  <dl class="facts"><dt>' . e(t('field.username')) . '</dt><dd><code>' . e($me['username']) . '</code></dd></dl>
  <form method="post" action="' . e(u('me')) . '" class="stack">' . csrf_field() . '
    <input type="hidden" name="do" value="display_name">
    <label for="display_name">' . e(t('me.display_name')) . '</label>
    <input id="display_name" name="display_name" value="' . e($me['display_name']) . '" maxlength="60" autocomplete="off">
    <p class="warning small">' . e(t('me.display_warning')) . '</p>
    <button class="btn" type="submit">' . e(t('me.save')) . '</button>
  </form>
  <p><a href="' . e(u('password')) . '">' . e(t('pw.title')) . ' →</a></p>
</section>
' . (contact_enabled($group) ? '<section class="card">
  <h2>' . e(t('me.contact')) . '</h2>
  <form method="post" action="' . e(u('me')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="contact">
    <label for="full_name">' . e(t('me.full_name')) . '</label><input id="full_name" name="full_name" value="' . e($me['full_name'] ?? '') . '" maxlength="100" autocomplete="name">
    <label for="phone">' . e(t('me.phone')) . '</label><input id="phone" name="phone" value="' . e($me['phone'] ?? '') . '" maxlength="40" inputmode="tel" autocomplete="tel">
    <label for="email">' . e(t('me.email')) . '</label><input id="email" name="email" value="' . e($me['email'] ?? '') . '" maxlength="150" inputmode="email" autocomplete="email">
    <p class="warning small">' . e(t('me.contact_warning')) . '</p>
    <button class="btn" type="submit">' . e(t('me.save')) . '</button>
  </form>
</section>' : '') . '
<section class="card">
  <h2>' . e(t('me.language')) . '</h2>
  <form method="post" action="' . e(u('me')) . '" class="row">' . csrf_field() . '<input type="hidden" name="do" value="lang">
    <select name="lang" aria-label="' . e(t('me.language')) . '">
      <option value="">' . e(t('me.lang_group', ['lang' => t('lang.' . $group['lang'])])) . '</option>'
    . implode('', array_map(fn ($l) => '<option value="' . $l . '"' . ($me['lang'] === $l ? ' selected' : '') . '>' . e(t('lang.native.' . $l)) . '</option>', LANGS)) . '
    </select>
    <button class="btn btn-small" type="submit">' . e(t('me.save')) . '</button>
  </form>
</section>
' . (($myKeys = db_all(db(), 'SELECT label, kind, lent_at FROM grp_keys WHERE group_code = ? AND holder_user_id = ? AND status = ? ORDER BY label', [$code, $myId, 'lent'])) ? '<section class="card"><h2>' . e(t('me.my_keys')) . '</h2><ul class="list">' . implode('', array_map(fn ($k) => '<li><strong>' . e($k['label']) . '</strong> <span class="muted small">' . e(t('keys.kind.' . $k['kind'])) . ' · ' . e(substr((string) $k['lent_at'], 0, 10)) . '</span></li>', $myKeys)) . '</ul></section>' : '') . '
<section class="card">
  <h2>' . e(t('me.groups')) . '</h2>
  <ul class="list">' . $groupsHtml . '</ul>
  ' . (count($myGroups) > 1 ? post_button(u('me'), t('me.leave', ['group' => $group['name']]), ['do' => 'leave'], t('me.leave_confirm'), 'btn btn-ghost btn-small') : '') . '
</section>
<section class="card danger">
  <h2>' . e(t('me.delete_title')) . '</h2>
  <p class="muted">' . e(t('me.delete_intro')) . '</p>
  <form method="post" action="' . e(u('me')) . '" class="stack" data-confirm="' . e(t('me.delete_confirm')) . '">' . csrf_field() . '
    <input type="hidden" name="do" value="delete">
    <label for="delpw">' . e(t('pw.current')) . '</label>
    <input id="delpw" name="password" type="password" autocomplete="current-password" required>
    <button class="btn btn-danger" type="submit">' . e(t('me.delete_btn')) . '</button>
  </form>
</section>', 'me');
}

/* ------------------------------- users ------------------------------- */

if ($p === 'users') {
    $roles = db_all(db(), 'SELECT * FROM grp_roles WHERE group_code = ? ORDER BY role_key IS NULL, id', [$code]);
    $roleIds = array_map(fn ($r) => (int) $r['id'], $roles);

    if ($isPost) {
        $do = post_str('do');
        $uid = (int) post_str('user_id');
        $target = $uid > 0 ? db_one(db(), 'SELECT u.* FROM grp_users u JOIN grp_memberships m ON m.user_id = u.id WHERE u.id = ? AND m.group_code = ?', [$uid, $code]) : null;

        if ($do === 'add') {
            can('users.manage') || deny();
            $pdo = db();
            $pdo->beginTransaction();
            try {
                [$newId, $username, $otp] = create_member($code, $myId);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('groupportal: add user failed: ' . $e->getMessage());
                flash('error', t('err.generic'));
                redirect(u('users'));
            }
            group_log($code, $myId, 'user.created', $username);
            page(t('users.added_title'), credentials_box(site_url($here), $username, $otp)
                . '<p class="muted">' . e(t('users.added_next')) . '</p><p><a class="btn btn-ghost" href="' . e(u('users')) . '">' . e(t('users.back')) . '</a></p>', 'users');
        }

        if ($do === 'invite') {
            can('users.manage') || deny();
            $username = strtoupper(clean_line(post_str('username'), 20));
            $invitee = db_one(db(), 'SELECT id FROM grp_users WHERE username = ?', [$username]);
            if ($invitee !== null) {
                db_exec(db(), 'INSERT IGNORE INTO grp_memberships (user_id, group_code, status, invited_by, created_at) VALUES (?, ?, ?, ?, ?)', [(int) $invitee['id'], $code, 'invited', $myId, now_db()]);
                group_log($code, $myId, 'user.invited', $username);
            }
            flash('ok', t('users.invite_sent'));
            redirect(u('users'));
        }

        if ($target === null) {
            flash('error', t('err.generic'));
            redirect(u('users'));
        }

        if (($do === 'remove' || $do === 'reset') && demo_protected((string) $target['username'])) {
            flash('error', t('demo.protected'));
            redirect(u('users'));
        }

        if ($do === 'remove') {
            can('users.manage') || deny();
            if ($uid === $myId) {
                flash('error', t('users.no_self_remove'));
            } elseif (user_is_chair($code, $uid) && chair_count($code) <= 1) {
                flash('error', t('me.last_chair', ['role' => t('role.chair')]));
            } else {
                remove_member($code, $uid);
                group_log($code, $myId, 'user.removed', (string) $target['username']);
                flash('ok', t('users.removed'));
            }
            redirect(u('users'));
        }

        if ($do === 'reset') {
            can('users.manage') || deny();
            // Only the user's home group may reset the password: otherwise an admin in one
            // group could take over an account and read another group's pages.
            if ($target['home_group'] !== $code) {
                flash('error', t('users.reset_home_only', ['code' => (string) $target['home_group']]));
                redirect(u('users'));
            }
            $otp = reset_one_time_password($uid);
            group_log($code, $myId, 'user.password_reset', (string) $target['username']);
            page(t('users.reset_title'), credentials_box(site_url($here), (string) $target['username'], $otp)
                . '<p><a class="btn btn-ghost" href="' . e(u('users')) . '">' . e(t('users.back')) . '</a></p>', 'users');
        }

        if ($do === 'roles') {
            can('roles.manage') || deny();
            $wanted = array_values(array_intersect(array_map('intval', post_list('roles')), $roleIds));
            $chairId = role_id($code, 'chair');
            if (user_is_chair($code, $uid) && !in_array($chairId, $wanted, true) && chair_count($code) <= 1) {
                flash('error', t('me.last_chair', ['role' => t('role.chair')]));
                redirect(u('users'));
            }
            db_exec(db(), 'DELETE FROM grp_member_roles WHERE group_code = ? AND user_id = ?', [$code, $uid]);
            foreach ($wanted as $rid) {
                assign_role($code, $uid, $rid);
            }
            $names = [];
            foreach ($roles as $r) {
                if (in_array((int) $r['id'], $wanted, true)) {
                    $names[] = role_name($r);
                }
            }
            group_log($code, $myId, 'user.roles', $target['username'] . ': ' . ($names ? implode(', ', $names) : '–'));
            flash('ok', t('users.roles_saved'));
            redirect(u('users'));
        }
        redirect(u('users'));
    }

    $members = db_all(db(), 'SELECT u.id, u.username, u.display_name, u.home_group, u.must_change_pw, u.last_login_at, m.status
        FROM grp_memberships m JOIN grp_users u ON u.id = m.user_id WHERE m.group_code = ? ORDER BY m.status, u.username', [$code]);
    $contacts = [];
    if (contact_enabled($group)) {
        foreach (db_all(db(), 'SELECT u.id, u.full_name, u.phone, u.email FROM grp_users u JOIN grp_memberships m ON m.user_id = u.id WHERE m.group_code = ?', [$code]) as $c) {
            $contacts[(int) $c['id']] = array_filter([$c['full_name'], $c['phone'], $c['email']]);
        }
    }
    $keysHeld = [];
    foreach (db_all(db(), 'SELECT holder_user_id, COUNT(*) AS n FROM grp_keys WHERE group_code = ? AND status = ? AND holder_user_id IS NOT NULL GROUP BY holder_user_id', [$code, 'lent']) as $r) {
        $keysHeld[(int) $r['holder_user_id']] = (int) $r['n'];
    }
    $memberRoles = [];
    foreach (db_all(db(), 'SELECT user_id, role_id FROM grp_member_roles WHERE group_code = ?', [$code]) as $r) {
        $memberRoles[(int) $r['user_id']][] = (int) $r['role_id'];
    }

    $list = '';
    foreach ($members as $m) {
        $mid = (int) $m['id'];
        $mine = $memberRoles[$mid] ?? [];
        $roleNames = [];
        $checks = '';
        foreach ($roles as $r) {
            $has = in_array((int) $r['id'], $mine, true);
            if ($has) {
                $roleNames[] = role_name($r);
            }
            $checks .= '<label class="checkbox-row"><input type="checkbox" name="roles[]" value="' . (int) $r['id'] . '"' . ($has ? ' checked' : '') . '> ' . e(role_name($r)) . '</label>';
        }
        $status = $m['status'] === 'invited' ? ' <span class="pill">' . e(t('users.invited')) . '</span>'
            : ((int) $m['must_change_pw'] === 1 ? ' <span class="pill">' . e(t('users.not_activated')) . '</span>' : '');

        $actions = '';
        if ($m['status'] === 'active' && can('roles.manage')) {
            $actions .= '<details class="inline-details"><summary>' . e(t('users.edit_roles')) . '</summary><form method="post" action="' . e(u('users')) . '" class="stack">' . csrf_field()
                . '<input type="hidden" name="do" value="roles"><input type="hidden" name="user_id" value="' . $mid . '">' . $checks
                . '<button class="btn btn-small" type="submit">' . e(t('users.save_roles')) . '</button></form></details>';
        }
        if (can('users.manage') && $mid !== $myId) {
            if ($m['home_group'] === $code && $m['status'] === 'active') {
                $actions .= post_button(u('users'), t('users.reset'), ['do' => 'reset', 'user_id' => $mid], t('users.reset_confirm', ['user' => $m['username']]), 'btn btn-ghost btn-small');
            }
            $removeConfirm = t('users.remove_confirm', ['user' => $m['username']]) . (isset($keysHeld[$mid]) ? ' ' . t('users.remove_keys_warning', ['n' => $keysHeld[$mid]]) : '');
            $actions .= post_button(u('users'), $m['status'] === 'invited' ? t('users.cancel_invite') : t('users.remove'), ['do' => 'remove', 'user_id' => $mid], $removeConfirm, 'btn btn-ghost btn-small');
        }

        $list .= '<li class="member"><div><strong>' . e(user_label($m)) . '</strong>'
            . ($m['display_name'] ? ' <code class="muted small">' . e($m['username']) . '</code>' : '') . $status
            . (!empty($contacts[$mid]) ? '<div class="small contact">' . e(implode(' · ', $contacts[$mid])) . '</div>' : '')
            . '<div class="muted small">' . e($roleNames ? implode(', ', $roleNames) : t('role.member')) . (isset($keysHeld[$mid]) ? ' · ' . e(t('users.has_keys', ['n' => $keysHeld[$mid]])) : '') . '</div></div>'
            . ($actions !== '' ? '<div class="actions">' . $actions . '</div>' : '') . '</li>';
    }

    $manage = '';
    if (can('users.manage')) {
        $manage = '
<section class="card">
  <h2>' . e(t('users.add_title')) . '</h2>
  <p class="muted small">' . e(t('users.add_intro')) . '</p>
  ' . post_button(u('users'), t('users.add_btn'), ['do' => 'add'], '', 'btn') . '
  <hr>
  <h2>' . e(t('users.invite_title')) . '</h2>
  <p class="muted small">' . e(t('users.invite_intro')) . '</p>
  <form method="post" action="' . e(u('users')) . '" class="row">' . csrf_field() . '
    <input type="hidden" name="do" value="invite">
    <input name="username" placeholder="ABC-XXXXX" maxlength="20" autocapitalize="characters" required aria-label="' . e(t('field.username')) . '">
    <button class="btn btn-ghost" type="submit">' . e(t('users.invite_btn')) . '</button>
  </form>
</section>';
    }

    page(t('nav.users'), '
<section class="card">
  <h1>' . e(t('nav.users')) . ' <span class="muted small">(' . count($members) . ')</span></h1>
  <ul class="members">' . $list . '</ul>
</section>' . $manage, 'users');
}

/* ------------------------------- roles ------------------------------- */

if ($p === 'roles') {
    if ($isPost) {
        can('roles.manage') || deny();
        $do = post_str('do');
        $rid = (int) post_str('role_id');
        $role = $rid > 0 ? db_one(db(), 'SELECT * FROM grp_roles WHERE id = ? AND group_code = ?', [$rid, $code]) : null;

        if ($do === 'create') {
            $name = clean_line(post_str('name'), 60);
            if ($name !== '') {
                db_exec(db(), 'INSERT INTO grp_roles (group_code, role_key, name, created_at) VALUES (?, NULL, ?, ?)', [$code, $name, now_db()]);
                group_log($code, $myId, 'role.created', $name);
                flash('ok', t('roles.created'));
            }
            redirect(u('roles'));
        }
        if ($role === null) {
            redirect(u('roles'));
        }
        if ($do === 'perms') {
            $allowed = $role['role_key'] !== null ? (FIXED_ROLES[$role['role_key']]['toggles'] ?? []) : custom_role_permissions();
            $wanted = array_values(array_intersect(post_list('perms'), $allowed));
            db_exec(db(), 'DELETE FROM grp_role_perms WHERE role_id = ?', [$rid]);
            foreach ($wanted as $perm) {
                db_exec(db(), 'INSERT INTO grp_role_perms (role_id, perm) VALUES (?, ?)', [$rid, $perm]);
            }
            if ($role['role_key'] === null) {
                $name = clean_line(post_str('name'), 60);
                if ($name !== '') {
                    db_exec(db(), 'UPDATE grp_roles SET name = ? WHERE id = ?', [$name, $rid]);
                }
            }
            group_log($code, $myId, 'role.changed', role_name($role) . ': ' . ($wanted ? implode(', ', array_map(fn ($pm) => t('perm.' . $pm), $wanted)) : '–'));
            flash('ok', t('roles.saved'));
        } elseif ($do === 'delete' && $role['role_key'] === null) {
            db_exec(db(), 'DELETE FROM grp_roles WHERE id = ?', [$rid]);
            group_log($code, $myId, 'role.deleted', role_name($role));
            flash('ok', t('roles.deleted'));
        }
        redirect(u('roles'));
    }

    $roles = db_all(db(), 'SELECT r.*, (SELECT COUNT(*) FROM grp_member_roles mr WHERE mr.role_id = r.id) AS holders FROM grp_roles r WHERE r.group_code = ? ORDER BY r.role_key IS NULL, r.id', [$code]);
    $stored = [];
    foreach (db_all(db(), 'SELECT p.role_id, p.perm FROM grp_role_perms p JOIN grp_roles r ON r.id = p.role_id WHERE r.group_code = ?', [$code]) as $row) {
        $stored[(int) $row['role_id']][] = $row['perm'];
    }
    $edit = can('roles.manage');

    $html = '<section class="card"><h1>' . e(t('nav.roles')) . '</h1><p class="muted small">' . e(t('roles.intro')) . '</p></section>';
    foreach ($roles as $r) {
        $rid = (int) $r['id'];
        $fixed = $r['role_key'] !== null;
        $on = $stored[$rid] ?? [];
        $base = $fixed ? FIXED_ROLES[$r['role_key']]['base'] : [];
        $toggles = $fixed ? FIXED_ROLES[$r['role_key']]['toggles'] : custom_role_permissions();

        $baseList = '';
        foreach ($base as $perm) {
            $baseList .= '<li>' . e(t('perm.' . $perm)) . '</li>';
        }
        $toggleHtml = '';
        foreach ($toggles as $perm) {
            $checked = in_array($perm, $on, true);
            $toggleHtml .= $edit
                ? '<label class="checkbox-row"><input type="checkbox" name="perms[]" value="' . e($perm) . '"' . ($checked ? ' checked' : '') . '> ' . e(t('perm.' . $perm)) . '</label>'
                : ($checked ? '<li>' . e(t('perm.' . $perm)) . '</li>' : '');
        }

        $html .= '<section class="card"><h2>' . e(role_name($r)) . ' <span class="muted small">' . e(t('roles.holders', ['n' => (int) $r['holders']])) . '</span></h2>';
        if ($baseList !== '') {
            $html .= '<ul class="perm-list">' . $baseList . '</ul>';
        }
        if ($edit && $toggles) {
            $html .= '<form method="post" action="' . e(u('roles')) . '" class="stack">' . csrf_field()
                . '<input type="hidden" name="do" value="perms"><input type="hidden" name="role_id" value="' . $rid . '">'
                . (!$fixed ? '<label>' . e(t('roles.name')) . '</label><input name="name" value="' . e($r['name']) . '" maxlength="60">' : '<p class="muted small">' . e(t('roles.optional')) . '</p>')
                . $toggleHtml . '<button class="btn btn-small" type="submit">' . e(t('roles.save')) . '</button></form>';
            if (!$fixed) {
                $html .= post_button(u('roles'), t('roles.delete'), ['do' => 'delete', 'role_id' => $rid], t('roles.delete_confirm'), 'btn btn-ghost btn-small');
            }
        } elseif ($toggleHtml !== '') {
            $html .= '<ul class="perm-list">' . $toggleHtml . '</ul>';
        }
        $html .= '</section>';
    }
    $html .= '<section class="card"><h2>' . e(t('role.member')) . '</h2><p class="muted small">' . e(t('roles.member_info')) . '</p></section>';

    if ($edit) {
        $html .= '<section class="card"><h2>' . e(t('roles.new')) . '</h2><form method="post" action="' . e(u('roles')) . '" class="row">' . csrf_field()
            . '<input type="hidden" name="do" value="create"><input name="name" maxlength="60" required placeholder="' . e(t('roles.name')) . '" aria-label="' . e(t('roles.name')) . '">'
            . '<button class="btn" type="submit">' . e(t('roles.create')) . '</button></form></section>';
    }
    page(t('nav.roles'), $html, 'roles');
}

/* ---------------------------- group info ---------------------------- */

if ($p === 'info') {
    if ($isPost) {
        can('group.edit') || deny();
        db_exec(db(), 'UPDATE grp_groups SET address = ?, landlord = ?, landlord_contact = ?, district = ?, other_info = ?, lang = ? WHERE code = ?', [
            clean_text(post_str('address'), 500),
            clean_line(post_str('landlord'), 200),
            clean_text(post_str('landlord_contact'), 500),
            ($dist = clean_line(post_str('district'), 200)) !== '' ? $dist : null,
            ($oth = clean_text(post_str('other_info'), 4000)) !== '' ? $oth : null,
            in_array(post_str('lang'), LANGS, true) ? post_str('lang') : $group['lang'],
            $code,
        ]);
        if (profile_meta((string) $group['profile'])['contact']) {
            db_exec(db(), 'UPDATE grp_groups SET contact_fields = ? WHERE code = ?', [post_str('contact_fields') === '1' ? 1 : 0, $code]);
        }
        group_log($code, $myId, 'group.info_changed');
        flash('ok', t('info.saved'));
        redirect(u('info'));
    }
    $g = group_by_code($code);
    if (can('group.edit')) {
        $body = '<form method="post" action="' . e(u('info')) . '" class="stack">' . csrf_field() . '
    <label for="address">' . e(t('info.address')) . '</label>
    <textarea id="address" name="address" rows="3" maxlength="500">' . e($g['address']) . '</textarea>
    <label for="landlord">' . e(t('info.landlord')) . '</label>
    <input id="landlord" name="landlord" value="' . e($g['landlord']) . '" maxlength="200">
    <label for="landlord_contact">' . e(t('info.landlord_contact')) . '</label>
    <textarea id="landlord_contact" name="landlord_contact" rows="3" maxlength="500">' . e($g['landlord_contact']) . '</textarea>
    <label for="district">' . e(t('info.district')) . '</label>
    <input id="district" name="district" value="' . e($g['district']) . '" maxlength="200">
    <label for="other_info">' . e(t('info.other')) . '</label>
    <textarea id="other_info" name="other_info" rows="5" maxlength="4000">' . e($g['other_info']) . '</textarea>
    ' . (profile_meta((string) $g['profile'])['contact'] ? '<label class="checkbox-row"><input type="checkbox" name="contact_fields" value="1"' . ((int) ($g['contact_fields'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('info.contact_fields')) . '</label><small class="hint">' . e(t('info.contact_fields_hint')) . '</small>' : '') . '
    <label for="lang">' . e(t('info.lang')) . '</label>
    <select id="lang" name="lang">' . implode('', array_map(fn ($l) => '<option value="' . $l . '"' . ($g['lang'] === $l ? ' selected' : '') . '>' . e(t('lang.native.' . $l)) . '</option>', LANGS)) . '</select>
    <small class="hint">' . e(t('info.lang_hint')) . '</small>
    <button class="btn" type="submit">' . e(t('info.save')) . '</button>
  </form>';
    } else {
        $body = '<dl class="facts">
    <dt>' . e(t('info.address')) . '</dt><dd class="pre">' . e($g['address'] ?: '–') . '</dd>
    <dt>' . e(t('info.landlord')) . '</dt><dd>' . e($g['landlord'] ?: '–') . '</dd>
    <dt>' . e(t('info.landlord_contact')) . '</dt><dd class="pre">' . e($g['landlord_contact'] ?: '–') . '</dd>
    <dt>' . e(t('info.district')) . '</dt><dd>' . e($g['district'] ?: '–') . '</dd>
    <dt>' . e(t('info.other')) . '</dt><dd class="pre">' . e($g['other_info'] ?: '–') . '</dd>
  </dl>';
    }
    page(t('nav.info'), '<section class="card"><h1>' . e(t('nav.info')) . ' <span class="badge">' . e($code) . '</span></h1>' . $body . '</section>
<section class="card"><h2>' . e(t('qr.title')) . '</h2><p class="muted small">' . e(t('qr.intro')) . '</p><a class="btn btn-small" href="' . e(u('qr')) . '">' . e(t('qr.show')) . '</a></section>'
    . (can('group.edit') ? '<section class="card"><h2>' . e(t('export.title')) . '</h2><p class="muted small">' . e(t('export.short')) . '</p><a class="btn btn-small btn-ghost" href="' . e(u('export')) . '">' . e(t('export.open')) . '</a></section>' : ''), 'info');
}

/* -------------------------------- log -------------------------------- */

if ($p === 'log') {
    can('log.view') || deny();
    $rows = db_all(db(), 'SELECT l.*, u.username, u.display_name FROM grp_log l LEFT JOIN grp_users u ON u.id = l.user_id WHERE l.group_code = ? ORDER BY l.id DESC LIMIT 300', [$code]);
    $html = '';
    foreach ($rows as $r) {
        $who = $r['username'] !== null ? user_label($r) : '–';
        $html .= '<tr><td class="nowrap">' . e(substr((string) $r['created_at'], 0, 16)) . '</td><td>' . e($who) . '</td><td>' . e(t('log.' . $r['action'])) . ($r['detail'] !== '' ? ' <span class="muted">' . e($r['detail']) . '</span>' : '') . '</td></tr>';
    }
    page(t('nav.log'), '<section class="card"><h1>' . e(t('nav.log')) . '</h1><p class="muted small">' . e(t('log.kept', ['days' => LOG_KEEP_DAYS])) . '</p>
<table class="table"><thead><tr><th>' . e(t('log.when')) . '</th><th>' . e(t('log.who')) . '</th><th>' . e(t('log.what')) . '</th></tr></thead><tbody>' . $html . '</tbody></table></section>', 'log');
}

/* ------------------------------ meetings ------------------------------ */

require GP_CORE . '/pages/meetings.php';
require GP_CORE . '/pages/formal.php';
require GP_CORE . '/pages/economy.php';
require GP_CORE . '/pages/keys.php';
require GP_CORE . '/pages/export.php';

/* ------------------------------- home ------------------------------- */

$invites = db_all(db(), 'SELECT g.code, g.name FROM grp_memberships m JOIN grp_groups g ON g.code = m.group_code WHERE m.user_id = ? AND m.status = ? AND g.status = ?', [$myId, 'invited', 'active']);
$inviteHtml = '';
foreach ($invites as $inv) {
    $inviteHtml .= '<section class="card highlight"><p>' . e(t('invite.text', ['group' => $inv['name']])) . '</p><div class="row">'
        . post_button(u('invite_answer'), t('invite.accept'), ['code' => $inv['code'], 'answer' => 'yes'], '', 'btn btn-small')
        . post_button(u('invite_answer'), t('invite.decline'), ['code' => $inv['code'], 'answer' => 'no'], '', 'btn btn-ghost btn-small')
        . '</div></section>';
}

$switch = '';
if (count($myGroups) > 1) {
    foreach ($myGroups as $g) {
        if ($g['code'] !== $code) {
            $switch .= '<li><a href="' . e(path('/' . $g['slug'] . '/')) . '">' . e($g['name']) . ' →</a></li>';
        }
    }
    $switch = '<section class="card"><h2>' . e(t('home.other_groups')) . '</h2><ul class="list">' . $switch . '</ul></section>';
}

$today = date('Y-m-d');
$upcoming = meeting_occurrences($code, $today, date('Y-m-d', strtotime('+365 days')), 10);
$needs = array_filter(
    meeting_occurrences($code, $today, date('Y-m-d', strtotime('+28 days'))),
    fn ($o) => $o['state'] === 'empty' || $o['state'] === 'unavailable'
);
$needsHtml = $needs ? '<section class="card highlight"><p><strong>' . e(t('home.needs', ['n' => count($needs)])) . '</strong></p>'
    . '<ul class="occs">' . implode('', array_map(fn ($o) => occ_card($o, $myId, 'home'), array_slice(array_values($needs), 0, 3))) . '</ul>'
    . (count($needs) > 3 ? '<p><a href="' . e(u('calendar')) . '">' . e(t('home.see_all')) . ' →</a></p>' : '') . '</section>' : '';

$formalNext = db_all(db(), 'SELECT m.*, t.name AS type_name FROM grp_formal_meetings m JOIN grp_formal_types t ON t.id = m.type_id
    WHERE m.group_code = ? AND (m.status = ? OR (m.status = ? AND m.meeting_date >= ?)) ORDER BY m.meeting_date, m.start_time LIMIT 5', [$code, 'started', 'planned', $today]);

page(t('nav.home'), $inviteHtml . '
<section class="card">
  <h1>' . e(t('home.hello', ['name' => user_label($me)])) . '</h1>
</section>' . $needsHtml . '
<section class="card">
  <div class="row between"><h2>' . e(t('home.next_meetings')) . '</h2><a href="' . e(u('calendar')) . '">' . e(t('home.see_all')) . ' →</a></div>
  ' . occ_list($upcoming, $myId, 'home') . '
</section>
<section class="card">
  <div class="row between"><h2>' . e(t('home.next_formal')) . '</h2><a href="' . e(u('formal')) . '">' . e(t('home.see_all')) . ' →</a></div>
  ' . ($formalNext ? '<ul class="list">' . implode('', array_map('formal_row', $formalNext)) . '</ul>' : '<p class="muted">' . e(t('formal.none_upcoming')) . '</p>') . '
  <p class="small"><a href="' . e(u('suggest')) . '">' . e(t('home.box_link')) . ' →</a></p>
</section>' . $switch, 'home');
