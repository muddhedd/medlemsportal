<?php
declare(strict_types=1);

/*
 * Group & user operations shared by the admin portal and the group app.
 * Every group-owned row carries the group's three-letter code (group_code),
 * so a human can filter any table by eye, e.g. WHERE group_code = 'SRG'.
 */

const GROUP_PURGE_DAYS = 30;
const LOG_KEEP_DAYS    = 90;

function group_by_code(string $code): ?array
{
    return db_one(db(), 'SELECT * FROM grp_groups WHERE code = ?', [$code]);
}

function group_by_slug(string $slug): ?array
{
    return db_one(db(), 'SELECT * FROM grp_groups WHERE slug = ?', [$slug]);
}

function group_url(array $group, string $page = '', array $query = []): string
{
    $url = path('/' . $group['slug'] . '/');
    if ($page !== '') {
        $query = ['p' => $page] + $query;
    }
    return $query ? $url . '?' . http_build_query($query) : $url;
}

function valid_group_code(string $code): bool
{
    return (bool) preg_match('/^[A-Z]{3}$/', $code);
}

function group_log(string $groupCode, ?int $userId, string $action, string $detail = ''): void
{
    db_exec(
        db(),
        'INSERT INTO grp_log (group_code, user_id, action, detail, created_at) VALUES (?, ?, ?, ?, ?)',
        [$groupCode, $userId, $action, mb_substr($detail, 0, 255), now_db()]
    );
}

/** Create the fixed roles for a new group. Returns role_key => role id. */
function create_fixed_roles(string $groupCode): array
{
    $ids = [];
    foreach (array_keys(FIXED_ROLES) as $key) {
        db_exec(db(), 'INSERT INTO grp_roles (group_code, role_key, name, created_at) VALUES (?, ?, NULL, ?)', [$groupCode, $key, now_db()]);
        $ids[$key] = (int) db()->lastInsertId();
    }
    return $ids;
}

function role_id(string $groupCode, string $roleKey): ?int
{
    $v = db_val(db(), 'SELECT id FROM grp_roles WHERE group_code = ? AND role_key = ?', [$groupCode, $roleKey]);
    return $v === null ? null : (int) $v;
}

/** Unique username, e.g. SRG-K7Q2M. */
function new_username(string $groupCode): string
{
    for ($i = 0; $i < 20; $i++) {
        $u = $groupCode . '-' . random_str(5);
        if (db_val(db(), 'SELECT 1 FROM grp_users WHERE username = ?', [$u]) === null) {
            return $u;
        }
    }
    throw new RuntimeException('could not generate a unique username');
}

/**
 * Create a user with a one-time password (must be changed at first login)
 * and make them an active member of the group. Returns [id, username, otp].
 */
function create_member(string $groupCode, ?int $createdBy): array
{
    $username = new_username($groupCode);
    $otp = gen_one_time_password();
    db_exec(
        db(),
        'INSERT INTO grp_users (username, home_group, password_hash, must_change_pw, otp_expires_at, created_at)
         VALUES (?, ?, ?, 1, ?, ?)',
        [$username, $groupCode, pw_hash($otp), now_db(OTP_TTL_SECONDS), now_db()]
    );
    $id = (int) db()->lastInsertId();
    db_exec(
        db(),
        'INSERT INTO grp_memberships (user_id, group_code, status, invited_by, created_at) VALUES (?, ?, \'active\', ?, ?)',
        [$id, $groupCode, $createdBy, now_db()]
    );
    return [$id, $username, $otp];
}

/** New one-time password; ends all the user's sessions. Returns the password. */
function reset_one_time_password(int $userId): string
{
    $otp = gen_one_time_password();
    db_exec(
        db(),
        'UPDATE grp_users SET password_hash = ?, must_change_pw = 1, otp_expires_at = ?, failed_logins = 0, locked_until = NULL WHERE id = ?',
        [pw_hash($otp), now_db(OTP_TTL_SECONDS), $userId]
    );
    gp_kill_user_sessions(db(), 'grp_sessions', $userId);
    return $otp;
}

function assign_role(string $groupCode, int $userId, int $roleId): void
{
    db_exec(db(), 'INSERT IGNORE INTO grp_member_roles (user_id, group_code, role_id) VALUES (?, ?, ?)', [$userId, $groupCode, $roleId]);
}

function chair_count(string $groupCode): int
{
    return (int) db_val(
        db(),
        'SELECT COUNT(*) FROM grp_member_roles mr
           JOIN grp_roles r ON r.id = mr.role_id
           JOIN grp_memberships m ON m.user_id = mr.user_id AND m.group_code = mr.group_code AND m.status = \'active\'
          WHERE mr.group_code = ? AND r.role_key = \'chair\'',
        [$groupCode]
    );
}

function user_is_chair(string $groupCode, int $userId): bool
{
    return db_val(
        db(),
        'SELECT 1 FROM grp_member_roles mr JOIN grp_roles r ON r.id = mr.role_id
          WHERE mr.group_code = ? AND mr.user_id = ? AND r.role_key = \'chair\'',
        [$groupCode, $userId]
    ) !== null;
}

/** Groups where this user is the only Chair (blocks leaving / deleting the account). */
function groups_where_last_chair(int $userId): array
{
    $codes = db_all(
        db(),
        'SELECT mr.group_code FROM grp_member_roles mr JOIN grp_roles r ON r.id = mr.role_id
          WHERE mr.user_id = ? AND r.role_key = \'chair\'',
        [$userId]
    );
    $out = [];
    foreach ($codes as $row) {
        if (chair_count($row['group_code']) <= 1) {
            $out[] = $row['group_code'];
        }
    }
    return $out;
}

/**
 * Remove a user from a group. If it was their last group the whole account
 * goes; if it was their home group, home moves to another of their groups.
 */
function remove_member(string $groupCode, int $userId, bool $endSessions = true): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec($pdo, 'DELETE FROM grp_member_roles WHERE group_code = ? AND user_id = ?', [$groupCode, $userId]);
        db_exec($pdo, 'DELETE FROM grp_memberships WHERE group_code = ? AND user_id = ?', [$groupCode, $userId]);
        $other = db_val($pdo, 'SELECT group_code FROM grp_memberships WHERE user_id = ? AND status = \'active\' ORDER BY created_at LIMIT 1', [$userId]);
        if ($other === null) {
            db_exec($pdo, 'DELETE FROM grp_users WHERE id = ?', [$userId]);
        } else {
            db_exec($pdo, 'UPDATE grp_users SET home_group = ? WHERE id = ? AND home_group = ?', [$other, $userId, $groupCode]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($endSessions) {
        gp_kill_user_sessions($pdo, 'grp_sessions', $userId);
    }
}

/** Housekeeping: drop groups deleted > 30 days ago, accounts left without groups, old logs. */
function groups_housekeeping(): void
{
    $pdo = db();
    $codes = db_all($pdo, 'SELECT code FROM grp_groups WHERE status = \'deleted\' AND deleted_at < ?', [now_db(-GROUP_PURGE_DAYS * 86400)]);
    foreach ($codes as $row) {
        // Foreign keys (ON DELETE CASCADE) remove everything that belongs to the group.
        db_exec($pdo, 'DELETE FROM grp_groups WHERE code = ?', [$row['code']]);
    }
    if ($codes) {
        // Move "home" for users who still belong elsewhere, then drop accounts with no group left.
        db_exec($pdo, 'UPDATE grp_users u SET u.home_group = (SELECT m.group_code FROM grp_memberships m WHERE m.user_id = u.id AND m.status = \'active\' ORDER BY m.created_at LIMIT 1)
                        WHERE NOT EXISTS (SELECT 1 FROM grp_groups g WHERE g.code = u.home_group)');
        db_exec($pdo, 'DELETE FROM grp_users WHERE NOT EXISTS (SELECT 1 FROM grp_memberships m WHERE m.user_id = grp_users.id AND m.status = \'active\')');
    }
    db_exec($pdo, 'DELETE FROM grp_log WHERE created_at < ?', [now_db(-LOG_KEEP_DAYS * 86400)]);
    // Unused one-time passwords that expired: the account stays, the password just no longer works.
}
