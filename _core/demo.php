<?php
declare(strict_types=1);

/*
 * Demo mode (config: define('DEMO_MODE', true)).
 *
 * - Everything is reset to example data once a night (the first visit after
 *   03:00 triggers it – no cron job needed on shared hosting).
 * - Ready-made accounts are shown on the login pages.
 * - The example accounts are protected: their passwords can't be changed and
 *   they can't be removed, so visitors can't lock each other out.
 * - The admin portal skips two-step login for the demo admin.
 * Off by default. Leave it off in a real installation.
 */

const DEMO_RESET_HOUR = 3;
const DEMO_GROUP_CODE = 'EXF';
const DEMO_GROUP_SLUG = 'exempelforeningen';
const DEMO_ADMIN_USER = 'demo';
const DEMO_ADMIN_PASS = 'demo-admin';

/** Example accounts: username => [password, display name, role key or null, full name, phone, e-mail] */
const DEMO_USERS = [
    'EXF-ORDF' => ['demo-ordforande', 'Anna', 'chair', 'Anna Andersson', '070-000 00 01', 'anna@example.com'],
    'EXF-KASS' => ['demo-kassor', 'Bertil', 'treasurer', 'Bertil Berg', '070-000 00 02', 'bertil@example.com'],
    'EXF-NYCK' => ['demo-nyckel', 'Cecilia', 'keyholder', '', '', ''],
    'EXF-MEDL' => ['demo-medlem', 'David', null, '', '', ''],
];

function is_demo(): bool
{
    return defined('DEMO_MODE') && DEMO_MODE === true;
}

/** Example accounts can't have their password changed, be removed or leave. */
function demo_protected(string $username): bool
{
    return is_demo() && (isset(DEMO_USERS[strtoupper($username)]) || strtolower($username) === DEMO_ADMIN_USER);
}

function demo_threshold(): string
{
    $t = strtotime(date('Y-m-d') . sprintf(' %02d:00:00', DEMO_RESET_HOUR));
    if (time() < $t) {
        $t -= 86400;
    }
    return date('Y-m-d H:i:s', $t);
}

/** Reset if the last reset was before last night's reset time. Only one request wins. */
function demo_maybe_reset(bool $withAdmin): void
{
    if (!is_demo()) {
        return;
    }
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS grp_demo_meta (id TINYINT NOT NULL PRIMARY KEY, last_reset DATETIME NULL)');
    db_exec($pdo, 'INSERT IGNORE INTO grp_demo_meta (id, last_reset) VALUES (1, NULL)');
    $won = db_exec($pdo, 'UPDATE grp_demo_meta SET last_reset = ? WHERE id = 1 AND (last_reset IS NULL OR last_reset < ?)', [now_db(), demo_threshold()]) === 1;
    if ($won) {
        demo_reset_groups();
    }
    if ($withAdmin) {
        $a = db_admin();
        if ($won || db_val($a, 'SELECT 1 FROM adm_users WHERE username = ?', [DEMO_ADMIN_USER]) === null) {
            demo_reset_admin();
        }
    }
}

function demo_reset_admin(): void
{
    $a = db_admin();
    db_exec($a, 'DELETE FROM adm_sessions');
    db_exec($a, 'DELETE FROM adm_log');
    db_exec($a, 'DELETE FROM adm_users');
    db_exec($a, 'INSERT INTO adm_users (username, password_hash, must_change_pw, failed_logins, created_at) VALUES (?, ?, 0, 0, ?)', [DEMO_ADMIN_USER, pw_hash(DEMO_ADMIN_PASS), now_db()]);
}

/** Wipe all groups and accounts and build the example association again. */
function demo_reset_groups(): void
{
    $pdo = db();
    db_exec($pdo, 'DELETE FROM grp_groups');      // cascades to everything a group owns
    db_exec($pdo, 'DELETE FROM grp_users');
    db_exec($pdo, 'DELETE FROM grp_sessions');

    $saved = $GLOBALS['gp_i18n'] ?? null;
    i18n_init('sv', 'assoc');
    $code = DEMO_GROUP_CODE;
    $day = fn (int $offset): string => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    $weekday = function (int $iso, int $weeksAhead = 0): string {
        $d = new DateTimeImmutable('today');
        while ((int) $d->format('N') !== $iso) {
            $d = $d->modify('+1 day');
        }
        return $d->modify('+' . (7 * $weeksAhead) . ' days')->format('Y-m-d');
    };

    db_exec($pdo, 'INSERT INTO grp_groups (code, slug, country, city, name, profile, lang, status, address, landlord, landlord_contact, district, other_info, contact_fields, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $code, DEMO_GROUP_SLUG, '', '', 'Exempelföreningen', 'assoc', 'sv', 'active',
        "Exempelgatan 1\n123 45 Exempelstad", 'Exempel Fastigheter AB', "Fastighetsskötare: 010-000 00 00\nfelanmalan@example.com",
        'Exempelförbundet, region Syd', "Detta är en demo med påhittade uppgifter.\nKaffet står i skåpet till vänster.", 1, now_db(),
    ]);
    $roles = create_fixed_roles($code);
    $ids = [];
    foreach (DEMO_USERS as $username => [$pw, $display, $role, $full, $phone, $email]) {
        db_exec($pdo, 'INSERT INTO grp_users (username, home_group, password_hash, must_change_pw, display_name, full_name, phone, email, created_at) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)',
            [$username, $code, pw_hash($pw), $display, $full ?: null, $phone ?: null, $email ?: null, now_db()]);
        $uid = (int) $pdo->lastInsertId();
        $ids[$username] = $uid;
        db_exec($pdo, 'INSERT INTO grp_memberships (user_id, group_code, status, created_at) VALUES (?, ?, ?, ?)', [$uid, $code, 'active', now_db()]);
        if ($role !== null) {
            assign_role($code, $uid, $roles[$role]);
        }
    }
    $chair = $ids['EXF-ORDF'];

    // Calendar
    foreach ([
        ['Onsdagsträffen', 'Öppen träff med fika. Alla välkomna!', $weekday(3), '18:00', '20:00', 1, 'weekly', null, $ids['EXF-ORDF']],
        ['Lördagsfika', 'Vi turas om att ta med fikabröd.', $weekday(6), '10:00', '11:30', 1, 'weekly', null, null],
        ['Medlemskväll', 'Sista torsdagen varje månad. Endast medlemmar.', $weekday(4), '19:00', '21:00', 0, 'monthly_nth', -1, $ids['EXF-KASS']],
    ] as [$title, $desc, $start, $from, $to, $open, $recur, $nth, $default]) {
        db_exec($pdo, 'INSERT INTO grp_meeting_series (group_code, title, description, start_date, start_time, end_time, is_open, recur, interval_n, nth, default_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)', [$code, $title, $desc, $start, $from, $to, $open, $recur, $nth, $default, now_db(), now_db()]);
    }
    $wed = (int) db_val($pdo, 'SELECT id FROM grp_meeting_series WHERE group_code = ? AND title = ?', [$code, 'Onsdagsträffen']);
    $sat = (int) db_val($pdo, 'SELECT id FROM grp_meeting_series WHERE group_code = ? AND title = ?', [$code, 'Lördagsfika']);
    occ_save($code, $sat, $weekday(6), ['resp_mode' => 'user', 'resp_user_id' => $ids['EXF-MEDL'], 'unavailable' => 0], $chair);
    occ_save($code, $wed, $weekday(3, 1), ['resp_mode' => 'default', 'unavailable' => 1, 'resp_user_id' => null], $chair);
    occ_save($code, $wed, $weekday(3, 2), ['note' => 'Vi är i lilla salen den här gången.', 'resp_mode' => 'default', 'resp_user_id' => null, 'unavailable' => 0], $chair);
    db_exec($pdo, 'UPDATE grp_meeting_occ SET resp_mode = ?, resp_user_id = ? WHERE series_id = ? AND occ_date = ?', ['user', $chair, $wed, $weekday(3, 1)]);

    // Formal meetings: default types, a schedule, one finished meeting with minutes, suggestions
    ensure_formal_defaults($code);
    $board = (int) db_val($pdo, 'SELECT id FROM grp_formal_types WHERE group_code = ? AND name = ?', [$code, 'Styrelsemöte']);
    $past = create_formal_meeting($code, $board, $day(-20), '18:30', 'Föreningslokalen', $chair);
    $items = db_all($pdo, 'SELECT id, heading, is_decision FROM grp_formal_items WHERE meeting_id = ? ORDER BY position', [$past]);
    foreach ($items as $i => $it) {
        $text = ['Ordföranden öppnade mötet.', 'Bertil valdes att justera protokollet.', 'Dagordningen godkändes.', 'Föregående protokoll lades till handlingarna.',
            'Kassören redovisade ekonomin. Kassan är god.', 'Beslut att köpa en ny kaffebryggare för högst 800 kr.', '', 'Nästa möte första tisdagen nästa månad.', 'Ordföranden avslutade mötet.'][$i] ?? '';
        db_exec($pdo, 'UPDATE grp_formal_items SET minutes_text = ?, decision = ? WHERE id = ?', [$text !== '' ? $text : null, (int) $it['is_decision'] === 1 ? 'decided' : null, (int) $it['id']]);
    }
    db_exec($pdo, 'UPDATE grp_formal_meetings SET status = ?, attendees = 7, started_at = ?, saved_at = ?, saved_by = ? WHERE id = ?', ['saved', now_db(), now_db(), $chair, $past]);
    db_exec($pdo, 'INSERT INTO grp_formal_schedules (group_code, type_id, start_date, start_time, place, recur, interval_n, nth, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)',
        [$code, $board, $weekday(2), '18:30', 'Föreningslokalen', 'monthly_nth', now_db()]);
    db_exec($pdo, 'INSERT INTO grp_suggestions (group_code, type_id, text, created_at) VALUES (?, ?, ?, ?)', [$code, $board, 'Kan vi ordna en utflykt i vår?', date('Y-m-d') . ' 00:00:00']);
    formal_run_schedules($code);

    // Economy: default accounts with a few transactions
    ensure_accounts_default($code);
    $accounts = accounts($code);
    db_exec($pdo, 'UPDATE grp_periods SET start_date = ?, opening_ore = ? WHERE group_code = ?', [$day(-40), 50000, $code]);
    if (count($accounts) >= 2) {
        [$cash, $bank] = [(int) $accounts[0]['id'], (int) $accounts[1]['id']];
        $kass = $ids['EXF-KASS'];
        book_tx($code, $cash, 'deposit', 35000, $day(-30), 'Medlemsavgifter vid träffen', $kass);
        book_tx($code, $cash, 'withdrawal', 12950, $day(-25), 'Kaffe, mjölk och fikabröd', $kass);
        book_tx($code, $cash, 'deposit', 15000, $day(-10), 'Insamling lördagsfika', $kass);
        book_transfer($code, $cash, $bank, 30000, $day(-5), 'Insättning på banken', $kass);
        $wrong = book_tx($code, $cash, 'withdrawal', 4500, $day(-3), 'Servetter', $kass);
        correct_tx($code, $wrong, 'Fel belopp, skulle vara 45,00', $kass);
        book_tx($code, $cash, 'withdrawal', 4500, $day(-3), 'Servetter (rätt belopp)', $kass);
        db_exec($pdo, 'UPDATE grp_accounts SET close_date = ? WHERE id = ?', [date('Y') . '-12-31', $cash]);
    }

    // Keys
    foreach ([['N1', 'key', 'Huvudentré', 'lent', $ids['EXF-ORDF']], ['N2', 'key', 'Huvudentré', 'available', null], ['T0457', 'tag', 'Larmtagg', 'lent', $ids['EXF-MEDL']], ['N3', 'key', 'Förrådet', 'lost', null]] as [$label, $kind, $desc, $status, $holder]) {
        db_exec($pdo, 'INSERT INTO grp_keys (group_code, label, kind, description, status, holder_user_id, lent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$code, $label, $kind, $desc, $status, $holder, $holder !== null ? now_db() : null, now_db()]);
        $kid = (int) $pdo->lastInsertId();
        db_exec($pdo, 'INSERT INTO grp_key_log (group_code, key_id, action, holder_user_id, by_user_id, created_at) VALUES (?, ?, ?, NULL, ?, ?)', [$code, $kid, 'registered', $ids['EXF-NYCK'], now_db()]);
        if ($holder !== null) {
            db_exec($pdo, 'INSERT INTO grp_key_log (group_code, key_id, action, holder_user_id, by_user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$code, $kid, 'lent', $holder, $ids['EXF-NYCK'], 'Kvittens nr 1', now_db()]);
        }
    }

    group_log($code, null, 'group.created', 'demo');
    if ($saved !== null) {
        $GLOBALS['gp_i18n'] = $saved;
    }
}

/** Box with the demo accounts, shown on the login pages. */
function demo_login_help(bool $admin): string
{
    if (!is_demo()) {
        return '';
    }
    $rows = '';
    if ($admin) {
        $rows = '<tr><td><code data-copy>' . e(DEMO_ADMIN_USER) . '</code></td><td><code data-copy>' . e(DEMO_ADMIN_PASS) . '</code></td><td>' . e(t('demo.admin')) . '</td></tr>';
    } else {
        foreach (DEMO_USERS as $u => [$pw, $display, $role]) {
            $rows .= '<tr><td><code data-copy>' . e($u) . '</code></td><td><code data-copy>' . e($pw) . '</code></td><td>' . e($display . ' – ' . ($role !== null ? t('role.' . $role) : t('role.member'))) . '</td></tr>';
        }
    }
    return '<section class="card narrow demo-help"><h2>' . e(t('demo.accounts')) . '</h2><table class="table"><tbody>' . $rows . '</tbody></table>'
        . ($admin ? '' : '<p class="muted small">' . e(t('demo.address', ['url' => path('/' . DEMO_GROUP_SLUG . '/')])) . '</p>') . '</section>';
}
