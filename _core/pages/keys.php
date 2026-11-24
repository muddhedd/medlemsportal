<?php
declare(strict_types=1);

/*
 * Keys and tags + the group's QR code. Included by group-app/index.php.
 * Everyone can see who holds which key (transparency). Receipts are kept
 * on paper outside the system, so no personal data is stored here.
 * keys.register: register / change / retire keys, mark lost and found.
 * keys.lend:     lend and take back.
 */

const KEY_KINDS = ['key', 'tag'];

function key_row(string $groupCode, int $id): ?array
{
    return db_one(db(), 'SELECT * FROM grp_keys WHERE id = ? AND group_code = ?', [$id, $groupCode]);
}

function key_log(string $groupCode, int $keyId, string $action, ?int $holder, int $by, string $note = ''): void
{
    db_exec(db(), 'INSERT INTO grp_key_log (group_code, key_id, action, holder_user_id, by_user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$groupCode, $keyId, $action, $holder, $by, $note !== '' ? $note : null, now_db()]);
}

/** Label for a holder: member label, else former member's username, else "removed account". */
function holder_label(?int $uid, array $labels): string
{
    if ($uid === null) {
        return t('keys.removed_account');
    }
    if (isset($labels[$uid])) {
        return $labels[$uid];
    }
    $u = db_val(db(), 'SELECT username FROM grp_users WHERE id = ?', [$uid]);
    return $u !== null ? t('keys.former_member', ['name' => (string) $u]) : t('keys.removed_account');
}

/** Keys a user holds in this group (used on My account and before removing a user). */
function keys_held(string $groupCode, int $userId): array
{
    return db_all(db(), 'SELECT * FROM grp_keys WHERE group_code = ? AND holder_user_id = ? AND status = ? ORDER BY label', [$groupCode, $userId, 'lent']);
}

/* ================================ keys ================================ */

if ($p === 'keys') {
    $labels = member_labels($code);

    if ($isPost) {
        $do = post_str('do');
        $kid = (int) post_str('id');
        $key = $kid > 0 ? key_row($code, $kid) : null;

        if ($do === 'add') {
            can('keys.register') || deny();
            $label = clean_line(post_str('label'), 40);
            $kind = in_array(post_str('kind'), KEY_KINDS, true) ? post_str('kind') : 'key';
            if ($label === '') {
                flash('error', t('keys.err_label'));
            } elseif (db_val(db(), 'SELECT 1 FROM grp_keys WHERE group_code = ? AND label = ? AND status <> ?', [$code, $label, 'retired']) !== null) {
                flash('error', t('keys.err_label_taken'));
            } else {
                db_exec(db(), 'INSERT INTO grp_keys (group_code, label, kind, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$code, $label, $kind, ($d = clean_line(post_str('description'), 200)) !== '' ? $d : null, 'available', now_db()]);
                $newId = (int) db()->lastInsertId();
                key_log($code, $newId, 'registered', null, $myId);
                group_log($code, $myId, 'keys.registered', $label);
                flash('ok', t('keys.added'));
            }
            redirect(u('keys'));
        }
        if ($key === null) {
            redirect(u('keys'));
        }
        $back = u('keys', ['id' => $kid]);

        if ($do === 'lend') {
            can('keys.lend') || deny();
            $to = (int) post_str('user_id');
            if ($key['status'] !== 'available' || !isset($labels[$to])) {
                flash('error', t('keys.err_lend'));
                redirect($back);
            }
            db_exec(db(), 'UPDATE grp_keys SET status = ?, holder_user_id = ?, lent_at = ? WHERE id = ? AND status = ?', ['lent', $to, now_db(), $kid, 'available']);
            key_log($code, $kid, 'lent', $to, $myId, clean_line(post_str('note'), 200));
            group_log($code, $myId, 'keys.lent', $key['label'] . ' → ' . $labels[$to]);
            flash('ok', t('keys.lent_ok', ['key' => $key['label'], 'name' => $labels[$to]]));
        } elseif ($do === 'return') {
            can('keys.lend') || deny();
            if ($key['status'] === 'lent') {
                db_exec(db(), 'UPDATE grp_keys SET status = ?, holder_user_id = NULL, lent_at = NULL WHERE id = ?', ['available', $kid]);
                key_log($code, $kid, 'returned', $key['holder_user_id'] !== null ? (int) $key['holder_user_id'] : null, $myId);
                group_log($code, $myId, 'keys.returned', (string) $key['label']);
                flash('ok', t('keys.returned_ok'));
            }
        } elseif ($do === 'lost') {
            can('keys.register') || deny();
            if ($key['status'] !== 'retired') {
                db_exec(db(), 'UPDATE grp_keys SET status = ?, holder_user_id = NULL, lent_at = NULL WHERE id = ?', ['lost', $kid]);
                key_log($code, $kid, 'lost', $key['holder_user_id'] !== null ? (int) $key['holder_user_id'] : null, $myId, clean_line(post_str('note'), 200));
                group_log($code, $myId, 'keys.lost', (string) $key['label']);
                flash('ok', t('keys.lost_ok'));
            }
        } elseif ($do === 'found') {
            can('keys.register') || deny();
            if ($key['status'] === 'lost') {
                db_exec(db(), 'UPDATE grp_keys SET status = ? WHERE id = ?', ['available', $kid]);
                key_log($code, $kid, 'found', null, $myId);
                group_log($code, $myId, 'keys.found', (string) $key['label']);
                flash('ok', t('keys.found_ok'));
            }
        } elseif ($do === 'edit') {
            can('keys.register') || deny();
            $label = clean_line(post_str('label'), 40);
            if ($label !== '' && db_val(db(), 'SELECT 1 FROM grp_keys WHERE group_code = ? AND label = ? AND id <> ? AND status <> ?', [$code, $label, $kid, 'retired']) === null) {
                db_exec(db(), 'UPDATE grp_keys SET label = ?, kind = ?, description = ? WHERE id = ?', [
                    $label, in_array(post_str('kind'), KEY_KINDS, true) ? post_str('kind') : $key['kind'],
                    ($d = clean_line(post_str('description'), 200)) !== '' ? $d : null, $kid,
                ]);
                key_log($code, $kid, 'changed', null, $myId);
                flash('ok', t('keys.saved'));
            } else {
                flash('error', t('keys.err_label_taken'));
            }
        } elseif ($do === 'retire') {
            can('keys.register') || deny();
            if ($key['status'] === 'lent') {
                flash('error', t('keys.err_retire_lent'));
            } else {
                db_exec(db(), 'UPDATE grp_keys SET status = ? WHERE id = ?', ['retired', $kid]);
                key_log($code, $kid, 'retired', null, $myId, clean_line(post_str('note'), 200));
                group_log($code, $myId, 'keys.retired', (string) $key['label']);
                flash('ok', t('keys.retired_ok'));
                redirect(u('keys'));
            }
        }
        redirect($back);
    }

    /* ----- one key: details + history ----- */
    $kid = (int) get_str('id');
    if ($kid > 0 && ($key = key_row($code, $kid)) !== null) {
        $history = db_all(db(), 'SELECT l.*, b.username AS by_username, b.display_name AS by_display FROM grp_key_log l LEFT JOIN grp_users b ON b.id = l.by_user_id WHERE l.key_id = ? ORDER BY l.id DESC', [$kid]);
        $hist = '';
        foreach ($history as $h) {
            $by = $h['by_username'] !== null ? user_label(['username' => $h['by_username'], 'display_name' => $h['by_display']]) : '–';
            $hist .= '<tr><td class="nowrap">' . e(substr((string) $h['created_at'], 0, 16)) . '</td><td>' . e(t('keys.act.' . $h['action']))
                . ($h['action'] === 'lent' || $h['action'] === 'returned' ? ' – ' . e(holder_label($h['holder_user_id'] !== null ? (int) $h['holder_user_id'] : null, $labels)) : '')
                . ($h['note'] ? ' <span class="muted">' . e($h['note']) . '</span>' : '') . '</td><td class="muted small">' . e($by) . '</td></tr>';
        }
        $actions = '';
        if ($key['status'] === 'available' && can('keys.lend')) {
            $actions .= '<form method="post" action="' . e(u('keys')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="lend"><input type="hidden" name="id" value="' . $kid . '">
    <label for="user_id">' . e(t('keys.lend_to')) . '</label><select id="user_id" name="user_id" required>' . member_options($labels, null, t('keys.choose'), '') . '</select>
    <label for="note">' . e(t('keys.note')) . '</label><input id="note" name="note" maxlength="200" placeholder="' . e(t('keys.note_ph')) . '">
    <button class="btn" type="submit">' . e(t('keys.lend_btn')) . '</button></form>';
        }
        if ($key['status'] === 'lent' && can('keys.lend')) {
            $actions .= post_button(u('keys'), t('keys.return_btn'), ['do' => 'return', 'id' => $kid], t('keys.return_confirm'), 'btn');
        }
        if (can('keys.register')) {
            if ($key['status'] === 'lost') {
                $actions .= post_button(u('keys'), t('keys.found_btn'), ['do' => 'found', 'id' => $kid], '', 'btn btn-ghost');
            } elseif ($key['status'] !== 'retired') {
                $actions .= post_button(u('keys'), t('keys.lost_btn'), ['do' => 'lost', 'id' => $kid], t('keys.lost_confirm'), 'btn btn-ghost');
            }
        }
        $edit = '';
        if (can('keys.register') && $key['status'] !== 'retired') {
            $kOpts = '';
            foreach (KEY_KINDS as $k) {
                $kOpts .= '<option value="' . $k . '"' . ($key['kind'] === $k ? ' selected' : '') . '>' . e(t('keys.kind.' . $k)) . '</option>';
            }
            $edit = '<section class="card"><details><summary>' . e(t('keys.edit')) . '</summary><form method="post" action="' . e(u('keys')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="' . $kid . '">
    <label>' . e(t('keys.label')) . '</label><input name="label" value="' . e($key['label']) . '" maxlength="40" required>
    <label>' . e(t('keys.kind')) . '</label><select name="kind">' . $kOpts . '</select>
    <label>' . e(t('keys.description')) . '</label><input name="description" value="' . e($key['description']) . '" maxlength="200">
    <button class="btn btn-small" type="submit">' . e(t('keys.save')) . '</button></form>'
                . ($key['status'] !== 'lent' ? '<hr><p class="muted small">' . e(t('keys.retire_hint')) . '</p>' . post_button(u('keys'), t('keys.retire_btn'), ['do' => 'retire', 'id' => $kid], t('keys.retire_confirm'), 'btn btn-ghost btn-small') : '')
                . '</details></section>';
        }
        $status = match ($key['status']) {
            'lent'    => t('keys.st.lent_to', ['name' => holder_label($key['holder_user_id'] !== null ? (int) $key['holder_user_id'] : null, $labels), 'date' => substr((string) $key['lent_at'], 0, 10)]),
            default   => t('keys.st.' . $key['status']),
        };
        page($key['label'], '<section class="card">
  <p class="muted small"><a href="' . e(u('keys')) . '">← ' . e(t('nav.keys')) . '</a></p>
  <h1>' . e($key['label']) . ' <span class="tag">' . e(t('keys.kind.' . $key['kind'])) . '</span></h1>
  ' . ($key['description'] ? '<p>' . e($key['description']) . '</p>' : '') . '
  <p class="key-status st-' . e($key['status']) . '">' . e($status) . '</p>
  <div class="stack">' . $actions . '</div>
</section>' . $edit . '
<section class="card"><h2>' . e(t('keys.history')) . '</h2><div class="table-wrap"><table class="table"><tbody>' . ($hist ?: '<tr><td class="muted">–</td></tr>') . '</tbody></table></div></section>', 'keys');
    }

    /* ----- list ----- */
    $keys = db_all(db(), 'SELECT * FROM grp_keys WHERE group_code = ? AND status <> ? ORDER BY label', [$code, 'retired']);
    $retired = (int) db_val(db(), 'SELECT COUNT(*) FROM grp_keys WHERE group_code = ? AND status = ?', [$code, 'retired']);
    $rows = '';
    $counts = ['available' => 0, 'lent' => 0, 'lost' => 0];
    foreach ($keys as $k) {
        $counts[$k['status']]++;
        $holder = $k['status'] === 'lent' ? holder_label($k['holder_user_id'] !== null ? (int) $k['holder_user_id'] : null, $labels) : '';
        $mine = $k['status'] === 'lent' && (int) $k['holder_user_id'] === $myId;
        $rows .= '<li class="key-item st-' . e($k['status']) . ($mine ? ' mine' : '') . '"><a href="' . e(u('keys', ['id' => $k['id']])) . '"><strong>' . e($k['label']) . '</strong></a> <span class="tag">' . e(t('keys.kind.' . $k['kind'])) . '</span>'
            . ($k['description'] ? '<div class="muted small">' . e($k['description']) . '</div>' : '')
            . '<div class="small">' . e($k['status'] === 'lent' ? t('keys.st.lent_to', ['name' => $holder, 'date' => substr((string) $k['lent_at'], 0, 10)]) : t('keys.st.' . $k['status'])) . ($mine ? ' <span class="muted">(' . e(t('meet.you')) . ')</span>' : '') . '</div></li>';
    }
    $add = '';
    if (can('keys.register')) {
        $kOpts = '';
        foreach (KEY_KINDS as $k) {
            $kOpts .= '<option value="' . $k . '">' . e(t('keys.kind.' . $k)) . '</option>';
        }
        $add = '<section class="card"><details><summary class="btn btn-small">+ ' . e(t('keys.add')) . '</summary><form method="post" action="' . e(u('keys')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="add">
    <div class="grid2"><div class="stack"><label for="label">' . e(t('keys.label')) . '</label><input id="label" name="label" maxlength="40" required placeholder="' . e(t('keys.label_ph')) . '"></div>
    <div class="stack"><label for="kind">' . e(t('keys.kind')) . '</label><select id="kind" name="kind">' . $kOpts . '</select></div></div>
    <label for="description">' . e(t('keys.description')) . '</label><input id="description" name="description" maxlength="200" placeholder="' . e(t('keys.description_ph')) . '">
    <button class="btn" type="submit">' . e(t('keys.add_btn')) . '</button></form></details></section>';
    }
    page(t('nav.keys'), '<section class="card">
  <h1>' . e(t('nav.keys')) . '</h1>
  <p class="muted small">' . e(t('keys.summary', ['available' => $counts['available'], 'lent' => $counts['lent'], 'lost' => $counts['lost']])) . '</p>
  ' . ($rows !== '' ? '<ul class="list keys-list">' . $rows . '</ul>' : '<p class="muted">' . e(t('keys.none')) . '</p>') . '
  ' . ($retired > 0 ? '<p class="muted small">' . e(t('keys.retired_count', ['n' => $retired])) . '</p>' : '') . '
  <p class="muted small">' . e(t('keys.paper_note')) . '</p>
</section>' . $add, 'keys');
}

/* ================================ QR code ================================ */

if ($p === 'qr') {
    $url = site_url($here);
    $svg = QrCode::svg($url);
    if (get_str('print') === '1') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="' . e(current_lang()) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>QR</title><link rel="stylesheet" href="' . e(asset('app.css')) . '"></head><body class="print-page"><main class="wrap">'
            . '<div class="no-print row"><button type="button" class="btn" data-print>' . e(t('formal.print_btn')) . '</button><a class="btn btn-ghost" href="' . e(u('qr')) . '">' . e(t('adm.back')) . '</a></div>'
            . '<article class="qr-poster"><h1>' . e(t('app.name')) . '</h1><div class="qr-big">' . $svg . '</div><p class="qr-url">' . e($url) . '</p><p class="muted">' . e(t('qr.poster_text')) . '</p></article>'
            . '</main><script src="' . e(asset('app.js')) . '" defer></script></body></html>';
        exit;
    }
    page(t('qr.title'), '<section class="card">
  <p class="muted small"><a href="' . e(u('info')) . '">← ' . e(t('nav.info')) . '</a></p>
  <h1>' . e(t('qr.title')) . '</h1>
  <div class="qr-box">' . $svg . '</div>
  <p class="qr-url"><code data-copy>' . e($url) . '</code></p>
  <p class="muted small">' . e(t('qr.intro')) . '</p>
  <a class="btn" href="' . e(u('qr', ['print' => 1])) . '">' . e(t('qr.print')) . '</a>
</section>', 'info');
}
