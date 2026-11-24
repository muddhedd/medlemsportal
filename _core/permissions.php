<?php
declare(strict_types=1);

/*
 * Every role is a set of permissions. Fixed roles are presets that exist in
 * every group and cannot be deleted; a few of their permissions can be
 * switched on/off per group ("toggles"). Custom roles may get any permission
 * except the EXCLUSIVE ones. Members without a role can read everything
 * (transparency) and use the everyday functions (take a meeting, report
 * that they can't, drop a suggestion in the box).
 */

const PERMISSIONS = [
    'users.manage',         // add / remove users, new one-time passwords
    'roles.manage',         // create custom roles, assign roles, toggles
    'group.edit',           // group information (address, landlord)
    'keys.register',        // register keys and tags
    'keys.lend',            // register key loans and returns
    'economy.edit',         // deposits, withdrawals, accounts
    'economy.close',        // close a financial period
    'templates.edit',       // meeting templates (agenda)
    'meetings.edit',        // the regular meetings in the calendar
    'formal.edit',          // business / board meetings
    'formaltypes.edit',     // types of business meetings
    'minutes.edit',         // minutes
    'suggestions.moderate', // remove inappropriate suggestions
    'log.view',             // see the group's activity log
];

/** Only fixed roles can ever hold these. */
const EXCLUSIVE_PERMISSIONS = ['group.edit', 'economy.close', 'keys.register'];

const EDIT_ALL = [
    'keys.lend', 'economy.edit', 'templates.edit', 'meetings.edit',
    'formal.edit', 'formaltypes.edit', 'minutes.edit',
];

const ADMIN_BASE = ['roles.manage', 'suggestions.moderate', 'log.view'];

/** role_key => [always-on permissions, permissions that can be toggled per group] */
const FIXED_ROLES = [
    'chair'       => ['base' => [...EDIT_ALL, ...ADMIN_BASE, 'users.manage', 'group.edit'], 'toggles' => []],
    'treasurer' => ['base' => [...EDIT_ALL, ...ADMIN_BASE, 'economy.close'],             'toggles' => ['users.manage', 'keys.register']],
    'keyholder' => ['base' => [...EDIT_ALL, ...ADMIN_BASE, 'keys.register'],             'toggles' => ['users.manage']],
    'admin'     => ['base' => [...EDIT_ALL, ...ADMIN_BASE],                              'toggles' => ['users.manage']],
];

/** Permissions a custom role may be given. */
function custom_role_permissions(): array
{
    return array_values(array_diff(PERMISSIONS, EXCLUSIVE_PERMISSIONS));
}

/**
 * Effective permissions for a user in a group: union of all their roles.
 * Fixed roles = base + enabled toggles (stored in grp_role_perms);
 * custom roles = whatever is stored in grp_role_perms.
 */
function user_permissions(string $groupCode, int $userId): array
{
    $rows = db_all(
        db(),
        'SELECT r.id, r.role_key, p.perm
           FROM grp_member_roles mr
           JOIN grp_roles r ON r.id = mr.role_id
           LEFT JOIN grp_role_perms p ON p.role_id = r.id
          WHERE mr.group_code = ? AND mr.user_id = ?',
        [$groupCode, $userId]
    );
    $perms = [];
    foreach ($rows as $row) {
        $key = $row['role_key'];
        if ($key !== null && isset(FIXED_ROLES[$key])) {
            foreach (FIXED_ROLES[$key]['base'] as $p) {
                $perms[$p] = true;
            }
            if ($row['perm'] !== null && in_array($row['perm'], FIXED_ROLES[$key]['toggles'], true)) {
                $perms[$row['perm']] = true;
            }
        } elseif ($row['perm'] !== null && in_array($row['perm'], custom_role_permissions(), true)) {
            $perms[$row['perm']] = true;
        }
    }
    return array_keys($perms);
}

/** Does the user hold any role that lets them administer the group at all? */
function is_group_administrator(array $perms): bool
{
    return in_array('roles.manage', $perms, true) || in_array('users.manage', $perms, true);
}
