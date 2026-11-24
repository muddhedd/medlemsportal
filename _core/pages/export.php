<?php
declare(strict_types=1);

/*
 * Export of everything a group owns (Chair only, permission group.edit).
 * - JSON: complete, machine-readable, for archiving or moving elsewhere.
 * - CSV:  all transactions, opens directly in Excel (semicolon, UTF-8 BOM).
 * Never exported: password hashes, sessions, other groups' data.
 */

/** Which tables to export and which columns to leave out. */
const EXPORT_TABLES = [
    'grp_memberships'           => [],
    'grp_roles'                 => [],
    'grp_member_roles'          => [],
    'grp_log'                   => [],
    'grp_meeting_series'        => [],
    'grp_meeting_occ'           => [],
    'grp_formal_types'          => [],
    'grp_formal_template_items' => [],
    'grp_formal_schedules'      => [],
    'grp_formal_meetings'       => [],
    'grp_formal_items'          => [],
    'grp_formal_addenda'        => [],
    'grp_suggestions'           => [],
    'grp_accounts'              => [],
    'grp_periods'               => [],
    'grp_transactions'          => [],
    'grp_keys'                  => [],
    'grp_key_log'               => [],
];

function export_group_data(array $group): array
{
    $code = (string) $group['code'];
    $data = [
        'export' => [
            'format'     => 'groupportal-export',
            'version'    => 1,
            'created_at' => date('c'),
            'note'       => 'Amounts (*_ore) are in 1/100 of the currency. User ids refer to the "users" list.',
        ],
        'group' => $group,
        // Only members of this group, and never password data.
        'users' => array_map(
            fn ($u) => array_diff_key($u, array_flip(['password_hash', 'must_change_pw', 'otp_expires_at', 'failed_logins', 'locked_until'])),
            db_all(db(), 'SELECT u.* FROM grp_users u JOIN grp_memberships m ON m.user_id = u.id WHERE m.group_code = ? ORDER BY u.username', [$code])
        ),
        'grp_role_perms' => db_all(db(), 'SELECT p.* FROM grp_role_perms p JOIN grp_roles r ON r.id = p.role_id WHERE r.group_code = ?', [$code]),
    ];
    foreach (EXPORT_TABLES as $table => $skip) {
        $rows = db_all(db(), 'SELECT * FROM ' . $table . ' WHERE group_code = ? ORDER BY 1', [$code]);
        if ($skip) {
            $rows = array_map(fn ($r) => array_diff_key($r, array_flip($skip)), $rows);
        }
        $data[$table] = $rows;
    }
    return $data;
}

function export_filename(array $group, string $ext): string
{
    return strtolower((string) $group['code']) . '-export-' . date('Y-m-d') . '.' . $ext;
}

if ($p === 'export') {
    can('group.edit') || deny();
    $do = get_str('do');

    if ($do === 'json') {
        group_log($code, $myId, 'export.json');
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . export_filename($group, 'json') . '"');
        echo json_encode(export_group_data(group_by_code($code)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($do === 'csv') {
        group_log($code, $myId, 'export.csv');
        $rows = db_all(db(), 'SELECT a.name AS account, p.start_date, p.end_date, t.ver_no, t.tx_date, t.type, t.amount_ore, t.comment, t.correction_of, t.transfer_id, u.username, t.created_at
            FROM grp_transactions t JOIN grp_accounts a ON a.id = t.account_id JOIN grp_periods p ON p.id = t.period_id LEFT JOIN grp_users u ON u.id = t.created_by
            WHERE t.group_code = ? ORDER BY a.position, p.id, t.ver_no', [$code]);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . export_filename($group, 'csv') . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads å, ä, ö correctly
        $sep = current_lang() === 'sv' ? ';' : ',';
        fputcsv($out, [t('eco.account_name'), t('eco.period'), t('eco.ver'), t('eco.date'), t('export.col_type'), t('eco.amount'), t('eco.comment'), t('export.col_corrects'), t('export.col_transfer'), t('export.col_by'), t('export.col_stamp')], $sep, '"', '');
        foreach ($rows as $r) {
            $amount = (int) $r['amount_ore'] * ($r['type'] === 'deposit' ? 1 : -1);
            $amountText = number_format($amount / 100, 2, current_lang() === 'sv' ? ',' : '.', '');
            // Neutralise spreadsheet formulas in free text (CSV injection).
            $comment = preg_match('/^[=+\-@\t\r]/', (string) $r['comment']) ? "'" . $r['comment'] : (string) $r['comment'];
            fputcsv($out, [
                $r['account'], $r['start_date'] . '–' . ($r['end_date'] ?? ''), $r['ver_no'], $r['tx_date'],
                t('eco.' . $r['type']), $amountText, $comment, $r['correction_of'] !== null ? 'x' : '', $r['transfer_id'] !== null ? 'x' : '',
                $r['username'] ?? '', $r['created_at'],
            ], $sep, '"', '');
        }
        fclose($out);
        exit;
    }

    page(t('export.title'), '<section class="card">
  <p class="muted small"><a href="' . e(u('info')) . '">← ' . e(t('nav.info')) . '</a></p>
  <h1>' . e(t('export.title')) . '</h1>
  <p>' . e(t('export.intro')) . '</p>
  <div class="stack">
    <a class="btn" href="' . e(u('export', ['do' => 'json'])) . '">' . e(t('export.json')) . '</a>
    <p class="muted small">' . e(t('export.json_hint')) . '</p>
    <a class="btn btn-ghost" href="' . e(u('export', ['do' => 'csv'])) . '">' . e(t('export.csv')) . '</a>
    <p class="muted small">' . e(t('export.csv_hint')) . '</p>
  </div>
  <p class="notice small">' . e(t('export.careful')) . '</p>
</section>', 'info');
}
