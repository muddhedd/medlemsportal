<?php
declare(strict_types=1);

/*
 * Group app pages for formal meetings, the suggestion box and templates.
 * Included by group-app/index.php; runs in its scope.
 */

ensure_formal_defaults($code);
formal_run_schedules($code);

/** POST field as key => string map (e.g. minutes[12]=...). */
function post_map(string $key): array
{
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $k => $val) {
        if (is_string($val)) {
            $out[(string) $k] = $val;
        }
    }
    return $out;
}

function formal_date_label(array $m): string
{
    return nice_date((string) $m['meeting_date'], false) . ' ' . substr((string) $m['meeting_date'], 0, 4)
        . ($m['start_time'] !== null ? ' ' . substr((string) $m['start_time'], 0, 5) : '');
}

function status_tag(string $status): string
{
    return '<span class="tag tag-' . e($status) . '">' . e(t('formal.status.' . $status)) . '</span>';
}

function formal_row(array $m): string
{
    return '<li><a href="' . e(u('fm', ['id' => $m['id']])) . '"><strong>' . e($m['type_name']) . '</strong></a> ' . status_tag((string) $m['status'])
        . '<div class="muted small">' . e(formal_date_label($m)) . ($m['place'] ? ' · ' . e($m['place']) : '') . '</div></li>';
}

function item_kind_tag(array $it): string
{
    return match ($it['kind']) {
        'suggestion' => ' <span class="tag tag-changed">' . e(t('formal.from_box')) . '</span>',
        'carried'    => ' <span class="tag tag-changed">' . e(t('decision.' . $it['carried_decision'])) . '</span>',
        default      => '',
    };
}

/** Read-only rendering of agenda or minutes. */
function render_items(array $tree, bool $minutes): string
{
    $h = '<ol class="agenda">';
    foreach ($tree as $t) {
        foreach (array_merge([$t], $t['children']) as $it) {
            $child = $it['parent_id'] !== null;
            $h .= '<li class="agenda-item' . ($child ? ' child' : '') . '"><div class="agenda-head"><span class="no">§ ' . e($it['no']) . '</span> <strong>' . e($it['heading']) . '</strong>' . item_kind_tag($it)
                . ((int) $it['is_decision'] === 1 && !$minutes ? ' <span class="tag">' . e(t('formal.decision_point')) . '</span>' : '') . '</div>';
            $agendaText = trim((string) ($it['agenda_text'] ?? ''));
            if ($agendaText !== '' && (!$minutes || (int) $it['include_agenda'] === 1)) {
                $h .= '<div class="pre agenda-text">' . e($agendaText) . '</div>';
            }
            if ($minutes) {
                $txt = trim((string) ($it['minutes_text'] ?? ''));
                if ($txt !== '') {
                    $h .= '<div class="pre minutes-text">' . e($txt) . '</div>';
                }
                if ($it['decision'] !== null) {
                    $h .= '<div class="decision">' . e(t('decision.' . $it['decision'])) . '</div>';
                }
            }
            $h .= '</li>';
        }
    }
    return $h . '</ol>';
}

function addenda_html(int $meetingId): string
{
    $rows = db_all(db(), 'SELECT a.*, u.username, u.display_name FROM grp_formal_addenda a LEFT JOIN grp_users u ON u.id = a.user_id WHERE a.meeting_id = ? ORDER BY a.id', [$meetingId]);
    if (!$rows) {
        return '';
    }
    $h = '<h2>' . e(t('formal.addenda')) . '</h2><ul class="addenda">';
    foreach ($rows as $r) {
        $who = $r['username'] !== null ? user_label($r) : '–';
        $h .= '<li><div class="muted small">' . e(substr((string) $r['created_at'], 0, 16)) . ' · ' . e($who) . '</div><div class="pre">' . e($r['text']) . '</div></li>';
    }
    return $h . '</ul>';
}

/* ============================= suggestion box ============================= */

if ($p === 'suggest') {
    $types = formal_types($code);
    $typeIds = array_map(fn ($tp) => (int) $tp['id'], $types);

    if ($isPost) {
        $do = post_str('do');
        if ($do === 'remove') {
            can('suggestions.moderate') || deny();
            $s = db_one(db(), 'SELECT s.*, i.meeting_id, m.status AS meeting_status FROM grp_suggestions s
                LEFT JOIN grp_formal_items i ON i.id = s.item_id LEFT JOIN grp_formal_meetings m ON m.id = i.meeting_id
                WHERE s.id = ? AND s.group_code = ?', [(int) post_str('id'), $code]);
            if ($s === null) {
                redirect(u('suggest'));
            }
            if ($s['item_id'] !== null && $s['meeting_status'] !== 'planned') {
                flash('error', t('box.already_in_meeting'));
                redirect(u('suggest'));
            }
            if ($s['item_id'] !== null) {
                db_exec(db(), 'DELETE FROM grp_formal_items WHERE id = ?', [(int) $s['item_id']]);
            }
            db_exec(db(), 'UPDATE grp_suggestions SET removed_at = ?, item_id = NULL WHERE id = ?', [now_db(), (int) $s['id']]);
            group_log($code, $myId, 'box.removed', mb_substr((string) $s['text'], 0, 60));
            flash('ok', t('box.removed'));
            redirect(u('suggest'));
        }

        $typeId = (int) post_str('type_id');
        $text = clean_text(post_str('text'), 2000);
        if (!in_array($typeId, $typeIds, true) || $text === '') {
            flash('error', t('box.err'));
            redirect(u('suggest'));
        }
        // Anonymous: no user id is stored with the suggestion, and the log does not say who.
        db_exec(db(), 'INSERT INTO grp_suggestions (group_code, type_id, text, created_at) VALUES (?, ?, ?, ?)', [$code, $typeId, $text, date('Y-m-d') . ' 00:00:00']);
        group_log($code, null, 'box.added');
        formal_route_pending($code, $typeId);
        flash('ok', t('box.thanks'));
        redirect(u('suggest'));
    }

    $pending = db_all(db(), 'SELECT s.*, t.name AS type_name, m.id AS meeting_id, m.meeting_date, m.status AS meeting_status
        FROM grp_suggestions s JOIN grp_formal_types t ON t.id = s.type_id
        LEFT JOIN grp_formal_items i ON i.id = s.item_id LEFT JOIN grp_formal_meetings m ON m.id = i.meeting_id
        WHERE s.group_code = ? AND s.removed_at IS NULL AND (s.item_id IS NULL OR m.status = ?) ORDER BY s.id DESC', [$code, 'planned']);
    $opts = '';
    foreach ($types as $tp) {
        $opts .= '<option value="' . (int) $tp['id'] . '">' . e($tp['name']) . '</option>';
    }
    $list = '';
    foreach ($pending as $s) {
        $where = $s['meeting_id'] !== null
            ? t('box.goes_to', ['meeting' => $s['type_name'], 'date' => nice_date((string) $s['meeting_date'], false)])
            : t('box.waiting', ['meeting' => $s['type_name']]);
        $list .= '<li class="suggestion"><div class="pre">' . e($s['text']) . '</div><div class="muted small">' . e($where) . '</div>'
            . (can('suggestions.moderate') ? post_button(u('suggest'), t('box.remove'), ['do' => 'remove', 'id' => $s['id']], t('box.remove_confirm'), 'btn btn-ghost btn-small') : '')
            . '</li>';
    }
    page(t('box.title'), '<section class="card">
  <h1>' . e(t('box.title')) . '</h1>
  <p class="muted">' . e(t('box.intro')) . '</p>
  ' . ($types ? '<form method="post" action="' . e(u('suggest')) . '" class="stack">' . csrf_field() . '
    <label for="type_id">' . e(t('box.to_meeting')) . '</label>
    <select id="type_id" name="type_id">' . $opts . '</select>
    <label for="text">' . e(t('box.text')) . '</label>
    <textarea id="text" name="text" rows="5" maxlength="2000" required></textarea>
    <p class="muted small">' . e(t('box.anonymous')) . '</p>
    <button class="btn" type="submit">' . e(t('box.send')) . '</button>
  </form>' : '<p class="muted">' . e(t('formal.no_types')) . '</p>') . '
</section>
<section class="card">
  <h2>' . e(t('box.pending')) . '</h2>
  ' . ($list !== '' ? '<ul class="list">' . $list . '</ul>' : '<p class="muted">' . e(t('box.none')) . '</p>') . '
</section>', 'formal');
}

/* ========================= meeting types & templates ========================= */

if ($p === 'ftypes') {
    $editTypes = can('formaltypes.edit');
    $editItems = can('templates.edit');

    if ($isPost) {
        $do = post_str('do');
        $tid = (int) post_str('type_id');
        $type = $tid > 0 ? formal_type($code, $tid) : null;
        $back = u('ftypes', $type !== null ? ['open' => $tid] : []);

        if ($do === 'type_create') {
            $editTypes || deny();
            $name = clean_line(post_str('name'), 80);
            if ($name !== '') {
                $pos = (int) db_val(db(), 'SELECT COALESCE(MAX(position), 0) FROM grp_formal_types WHERE group_code = ?', [$code]) + 10;
                db_exec(db(), 'INSERT INTO grp_formal_types (group_code, name, position, created_at) VALUES (?, ?, ?, ?)', [$code, $name, $pos, now_db()]);
                $newId = (int) db()->lastInsertId();
                db_exec(db(), 'INSERT INTO grp_formal_template_items (group_code, type_id, position, heading, default_text, is_decision, kind) VALUES (?, ?, 10, ?, NULL, 0, ?)', [$code, $newId, t('formal.other_business'), 'other']);
                group_log($code, $myId, 'formal.type_created', $name);
                redirect(u('ftypes', ['open' => $newId]));
            }
            redirect(u('ftypes'));
        }
        if ($type === null) {
            redirect(u('ftypes'));
        }
        if ($do === 'type_rename') {
            $editTypes || deny();
            $name = clean_line(post_str('name'), 80);
            if ($name !== '') {
                db_exec(db(), 'UPDATE grp_formal_types SET name = ? WHERE id = ?', [$name, $tid]);
                group_log($code, $myId, 'formal.type_changed', $name);
            }
            redirect($back);
        }
        if ($do === 'type_delete') {
            $editTypes || deny();
            if (db_val(db(), 'SELECT 1 FROM grp_formal_meetings WHERE type_id = ? LIMIT 1', [$tid]) !== null) {
                flash('error', t('ftypes.in_use'));
                redirect($back);
            }
            db_exec(db(), 'DELETE FROM grp_formal_types WHERE id = ?', [$tid]);
            group_log($code, $myId, 'formal.type_deleted', (string) $type['name']);
            flash('ok', t('ftypes.deleted'));
            redirect(u('ftypes'));
        }

        if ($do === 'schedule') {
            can('formal.edit') || deny();
            $recur = post_str('recur');
            if ($recur === 'off') {
                db_exec(db(), 'DELETE FROM grp_formal_schedules WHERE type_id = ? AND group_code = ?', [$tid, $code]);
                group_log($code, $myId, 'formal.schedule_off', (string) $type['name']);
                flash('ok', t('fsched.off_saved'));
                redirect($back);
            }
            $f = [
                'start_date' => post_str('start_date'),
                'start_time' => post_str('start_time'),
                'place'      => ($pl = clean_line(post_str('place'), 200)) !== '' ? $pl : null,
                'recur'      => in_array($recur, RECUR_TYPES, true) && $recur !== 'none' ? $recur : 'monthly_nth',
                'interval_n' => max(1, min(12, (int) post_str('interval_n'))),
                'nth'        => in_array((int) post_str('nth'), [1, 2, 3, 4, -1], true) ? (int) post_str('nth') : 1,
                'until_date' => post_str('until_date'),
            ];
            if (!valid_date($f['start_date']) || ($f['start_time'] !== '' && !valid_time($f['start_time'])) || ($f['until_date'] !== '' && (!valid_date($f['until_date']) || $f['until_date'] < $f['start_date']))) {
                flash('error', t('formal.err_new'));
                redirect($back);
            }
            $f['start_time'] = $f['start_time'] !== '' ? $f['start_time'] : null;
            $f['until_date'] = $f['until_date'] !== '' ? $f['until_date'] : null;
            $f['nth'] = $f['recur'] === 'monthly_nth' ? $f['nth'] : null;
            $f['generated_until'] = null; // regenerate from today with the new rule
            if (formal_schedule($code, $tid) === null) {
                $f += ['group_code' => $code, 'type_id' => $tid, 'created_at' => now_db()];
                db_exec(db(), 'INSERT INTO grp_formal_schedules (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_fill(0, count($f), '?')) . ')', array_values($f));
            } else {
                db_exec(db(), 'UPDATE grp_formal_schedules SET ' . implode(', ', array_map(fn ($k) => $k . ' = ?', array_keys($f))) . ' WHERE type_id = ? AND group_code = ?', array_merge(array_values($f), [$tid, $code]));
            }
            formal_run_schedules($code);
            group_log($code, $myId, 'formal.schedule_set', (string) $type['name']);
            flash('ok', t('fsched.saved'));
            redirect($back);
        }

        $editItems || deny();
        $iid = (int) post_str('item_id');
        $item = $iid > 0 ? db_one(db(), 'SELECT * FROM grp_formal_template_items WHERE id = ? AND type_id = ?', [$iid, $tid]) : null;

        if ($do === 'item_add') {
            $heading = clean_line(post_str('heading'), 150);
            if ($heading !== '') {
                // New items go just before "other business" when it exists.
                $other = db_one(db(), 'SELECT position FROM grp_formal_template_items WHERE type_id = ? AND kind = ? ORDER BY position LIMIT 1', [$tid, 'other']);
                if ($other !== null) {
                    db_exec(db(), 'UPDATE grp_formal_template_items SET position = position + 10 WHERE type_id = ? AND position >= ?', [$tid, (int) $other['position']]);
                    $pos = (int) $other['position'];
                } else {
                    $pos = (int) db_val(db(), 'SELECT COALESCE(MAX(position), 0) FROM grp_formal_template_items WHERE type_id = ?', [$tid]) + 10;
                }
                db_exec(db(), 'INSERT INTO grp_formal_template_items (group_code, type_id, position, heading, default_text, is_decision, kind) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$code, $tid, $pos, $heading, ($txt = clean_text(post_str('default_text'), 2000)) !== '' ? $txt : null, post_str('is_decision') === '1' ? 1 : 0, 'normal']);
            }
        } elseif ($item !== null && $do === 'item_save') {
            $heading = clean_line(post_str('heading'), 150);
            $isOther = post_str('is_other') === '1';
            if ($isOther) {
                db_exec(db(), 'UPDATE grp_formal_template_items SET kind = ? WHERE type_id = ?', ['normal', $tid]);
            }
            db_exec(db(), 'UPDATE grp_formal_template_items SET heading = ?, default_text = ?, is_decision = ?, kind = ? WHERE id = ?', [
                $heading !== '' ? $heading : $item['heading'],
                ($txt = clean_text(post_str('default_text'), 2000)) !== '' ? $txt : null,
                post_str('is_decision') === '1' ? 1 : 0,
                $isOther ? 'other' : 'normal',
                $iid,
            ]);
        } elseif ($item !== null && $do === 'item_delete') {
            db_exec(db(), 'DELETE FROM grp_formal_template_items WHERE id = ?', [$iid]);
        } elseif ($item !== null && ($do === 'item_up' || $do === 'item_down')) {
            $neighbour = db_one(db(), 'SELECT * FROM grp_formal_template_items WHERE type_id = ? AND position ' . ($do === 'item_up' ? '<' : '>') . ' ? ORDER BY position ' . ($do === 'item_up' ? 'DESC' : 'ASC') . ' LIMIT 1', [$tid, (int) $item['position']]);
            if ($neighbour !== null) {
                db_exec(db(), 'UPDATE grp_formal_template_items SET position = ? WHERE id = ?', [(int) $neighbour['position'], $iid]);
                db_exec(db(), 'UPDATE grp_formal_template_items SET position = ? WHERE id = ?', [(int) $item['position'], (int) $neighbour['id']]);
            }
        }
        group_log($code, $myId, 'formal.template_changed', (string) $type['name']);
        redirect($back);
    }

    $open = (int) get_str('open');
    $html = '<section class="card"><p class="muted small"><a href="' . e(u('formal')) . '">← ' . e(t('nav.formal')) . '</a></p><h1>' . e(t('ftypes.title')) . '</h1><p class="muted small">' . e(t('ftypes.intro')) . '</p></section>';
    foreach (formal_types($code) as $tp) {
        $tid = (int) $tp['id'];
        $items = db_all(db(), 'SELECT * FROM grp_formal_template_items WHERE type_id = ? ORDER BY position, id', [$tid]);
        $rows = '';
        foreach ($items as $n => $it) {
            $iid = (int) $it['id'];
            $flags = ((int) $it['is_decision'] === 1 ? ' <span class="tag">' . e(t('formal.decision_point')) . '</span>' : '')
                . ($it['kind'] === 'other' ? ' <span class="tag tag-open">' . e(t('ftypes.other_tag')) . '</span>' : '');
            if ($editItems) {
                $base = ['type_id' => $tid, 'item_id' => $iid];
                $rows .= '<li class="tpl-item"><details><summary><span class="no">§ ' . ($n + 1) . '</span> ' . e($it['heading']) . $flags . '</summary>
  <form method="post" action="' . e(u('ftypes')) . '" class="stack">' . csrf_field() . '
    <input type="hidden" name="do" value="item_save"><input type="hidden" name="type_id" value="' . $tid . '"><input type="hidden" name="item_id" value="' . $iid . '">
    <label>' . e(t('ftypes.heading')) . '</label><input name="heading" value="' . e($it['heading']) . '" maxlength="150" required>
    <label>' . e(t('ftypes.default_text')) . '</label><textarea name="default_text" rows="2" maxlength="2000">' . e($it['default_text']) . '</textarea>
    <label class="checkbox-row"><input type="checkbox" name="is_decision" value="1"' . ((int) $it['is_decision'] === 1 ? ' checked' : '') . '> ' . e(t('ftypes.is_decision')) . '</label>
    <label class="checkbox-row"><input type="checkbox" name="is_other" value="1"' . ($it['kind'] === 'other' ? ' checked' : '') . '> ' . e(t('ftypes.is_other')) . '</label>
    <button class="btn btn-small" type="submit">' . e(t('ftypes.save')) . '</button>
  </form>
  <div class="row">' . post_button(u('ftypes'), '↑', ['do' => 'item_up'] + $base, '', 'btn btn-ghost btn-small')
                    . post_button(u('ftypes'), '↓', ['do' => 'item_down'] + $base, '', 'btn btn-ghost btn-small')
                    . post_button(u('ftypes'), t('ftypes.delete_item'), ['do' => 'item_delete'] + $base, t('ftypes.delete_item_confirm'), 'btn btn-ghost btn-small') . '</div>
</details></li>';
            } else {
                $rows .= '<li class="tpl-item"><span class="no">§ ' . ($n + 1) . '</span> ' . e($it['heading']) . $flags . ($it['default_text'] ? '<div class="muted small pre">' . e($it['default_text']) . '</div>' : '') . '</li>';
            }
        }
        $add = $editItems ? '<details class="add-item"><summary>+ ' . e(t('ftypes.add_item')) . '</summary><form method="post" action="' . e(u('ftypes')) . '" class="stack">' . csrf_field() . '
    <input type="hidden" name="do" value="item_add"><input type="hidden" name="type_id" value="' . $tid . '">
    <label>' . e(t('ftypes.heading')) . '</label><input name="heading" maxlength="150" required>
    <label>' . e(t('ftypes.default_text')) . '</label><textarea name="default_text" rows="2" maxlength="2000"></textarea>
    <label class="checkbox-row"><input type="checkbox" name="is_decision" value="1"> ' . e(t('ftypes.is_decision')) . '</label>
    <button class="btn btn-small" type="submit">' . e(t('ftypes.add_item')) . '</button></form></details>' : '';
        $sched = formal_schedule($code, $tid);
        $schedText = $sched !== null ? series_summary($sched + ['end_time' => null, 'start_time' => $sched['start_time'] ?? '']) . ($sched['place'] ? ' · ' . $sched['place'] : '') : t('fsched.none');
        $schedHtml = '<div class="sched"><strong>' . e(t('fsched.title')) . ':</strong> ' . e($schedText) . '</div>';
        if (can('formal.edit')) {
            $sf = $sched ?? ['start_date' => date('Y-m-d'), 'start_time' => '18:00', 'place' => '', 'recur' => 'monthly_nth', 'interval_n' => 1, 'nth' => 1, 'until_date' => ''];
            $rOpts = '<option value="off"' . ($sched === null ? ' selected' : '') . '>' . e(t('fsched.recur_off')) . '</option>';
            foreach (RECUR_TYPES as $r) {
                if ($r !== 'none') {
                    $rOpts .= '<option value="' . $r . '"' . ($sched !== null && $sf['recur'] === $r ? ' selected' : '') . '>' . e(t('recur.' . $r)) . '</option>';
                }
            }
            $nOpts = '';
            foreach ([1, 2, 3, 4, -1] as $n) {
                $nOpts .= '<option value="' . $n . '"' . ((int) ($sf['nth'] ?? 1) === $n ? ' selected' : '') . '>' . e(t('nth.' . $n)) . '</option>';
            }
            $schedHtml .= '<details><summary>' . e(t('fsched.edit')) . '</summary><form method="post" action="' . e(u('ftypes')) . '" class="stack" data-recur-form>' . csrf_field() . '
    <input type="hidden" name="do" value="schedule"><input type="hidden" name="type_id" value="' . $tid . '">
    <label>' . e(t('series.recur')) . '</label><select name="recur" data-recur>' . $rOpts . '</select>
    <div class="stack" data-recur-show="daily weekly monthly_date monthly_nth">
      <label>' . e(t('fsched.first_date')) . '</label><input name="start_date" type="date" value="' . e((string) $sf['start_date']) . '">
      <small class="hint">' . e(t('fsched.first_date_hint')) . '</small>
      <div class="stack" data-recur-show="monthly_nth"><label>' . e(t('series.nth')) . '</label><select name="nth">' . $nOpts . '</select></div>
      <label>' . e(t('series.interval')) . '</label><input name="interval_n" type="number" min="1" max="12" value="' . (int) $sf['interval_n'] . '">
      <div class="grid2"><div class="stack"><label>' . e(t('formal.time')) . '</label><input name="start_time" type="time" value="' . e($sf['start_time'] !== null ? substr((string) $sf['start_time'], 0, 5) : '') . '"></div>
      <div class="stack"><label>' . e(t('series.until')) . '</label><input name="until_date" type="date" value="' . e((string) ($sf['until_date'] ?? '')) . '"></div></div>
      <label>' . e(t('formal.place')) . '</label><input name="place" value="' . e((string) ($sf['place'] ?? '')) . '" maxlength="200">
    </div>
    <p class="muted small">' . e(t('fsched.hint', ['days' => FORMAL_SCHEDULE_DAYS])) . '</p>
    <button class="btn btn-small" type="submit">' . e(t('ftypes.save')) . '</button></form></details>';
        }
        $typeAdmin = $editTypes ? '<details><summary>' . e(t('ftypes.rename_delete')) . '</summary><form method="post" action="' . e(u('ftypes')) . '" class="row">' . csrf_field() . '
    <input type="hidden" name="do" value="type_rename"><input type="hidden" name="type_id" value="' . $tid . '"><input name="name" value="' . e($tp['name']) . '" maxlength="80" required>
    <button class="btn btn-small" type="submit">' . e(t('ftypes.save')) . '</button></form>'
            . post_button(u('ftypes'), t('ftypes.delete_type'), ['do' => 'type_delete', 'type_id' => $tid], t('ftypes.delete_type_confirm'), 'btn btn-ghost btn-small') . '</details>' : '';
        $html .= '<section class="card"><details' . ($open === $tid ? ' open' : '') . '><summary class="type-summary"><strong>' . e($tp['name']) . '</strong> <span class="muted small">(' . count($items) . ')</span></summary>'
            . $schedHtml . '<ol class="tpl-items">' . $rows . '</ol>' . $add . $typeAdmin . '</details></section>';
    }
    if ($editTypes) {
        $html .= '<section class="card"><h2>' . e(t('ftypes.new')) . '</h2><form method="post" action="' . e(u('ftypes')) . '" class="row">' . csrf_field()
            . '<input type="hidden" name="do" value="type_create"><input name="name" maxlength="80" required placeholder="' . e(t('ftypes.name')) . '" aria-label="' . e(t('ftypes.name')) . '">'
            . '<button class="btn" type="submit">' . e(t('ftypes.create')) . '</button></form></section>';
    }
    page(t('ftypes.title'), $html, 'formal');
}

/* ============================== list ============================== */

if ($p === 'formal') {
    if ($isPost) {
        can('formal.edit') || deny();
        $tid = (int) post_str('type_id');
        $date = post_str('date');
        $time = post_str('time');
        if (formal_type($code, $tid) === null || !valid_date($date) || ($time !== '' && !valid_time($time))) {
            flash('error', t('formal.err_new'));
            redirect(u('formal'));
        }
        $mid = create_formal_meeting($code, $tid, $date, $time !== '' ? $time : null, ($pl = clean_line(post_str('place'), 200)) !== '' ? $pl : null, $myId);
        $m = formal_meeting($code, $mid);
        group_log($code, $myId, 'formal.created', $m['type_name'] . ' ' . $date);
        flash('ok', t('formal.created'));
        redirect(u('fm', ['id' => $mid]));
    }

    $upcoming = db_all(db(), 'SELECT m.*, t.name AS type_name FROM grp_formal_meetings m JOIN grp_formal_types t ON t.id = m.type_id
        WHERE m.group_code = ? AND m.status <> ? ORDER BY m.meeting_date, m.start_time', [$code, 'saved']);
    $past = db_all(db(), 'SELECT m.*, t.name AS type_name FROM grp_formal_meetings m JOIN grp_formal_types t ON t.id = m.type_id
        WHERE m.group_code = ? AND m.status = ? ORDER BY m.meeting_date DESC, m.id DESC LIMIT 100', [$code, 'saved']);
    $types = formal_types($code);

    $new = '';
    if (can('formal.edit') && $types) {
        $opts = '';
        foreach ($types as $tp) {
            $opts .= '<option value="' . (int) $tp['id'] . '">' . e($tp['name']) . '</option>';
        }
        $new = '<section class="card"><details><summary class="btn btn-small">+ ' . e(t('formal.new')) . '</summary>
  <form method="post" action="' . e(u('formal')) . '" class="stack">' . csrf_field() . '
    <label for="type_id">' . e(t('formal.type')) . '</label><select id="type_id" name="type_id">' . $opts . '</select>
    <div class="grid2"><div class="stack"><label for="date">' . e(t('formal.date')) . '</label><input id="date" name="date" type="date" required value="' . e(date('Y-m-d')) . '"></div>
    <div class="stack"><label for="time">' . e(t('formal.time')) . '</label><input id="time" name="time" type="time"></div></div>
    <label for="place">' . e(t('formal.place')) . '</label><input id="place" name="place" maxlength="200">
    <p class="muted small">' . e(t('formal.new_hint')) . '</p>
    <button class="btn" type="submit">' . e(t('formal.create')) . '</button>
  </form></details></section>';
    }

    page(t('nav.formal'), '<section class="card">
  <div class="row between"><h1>' . e(t('nav.formal')) . '</h1>
    <div class="row"><a class="btn btn-small" href="' . e(u('suggest')) . '">' . e(t('box.title')) . '</a><a class="btn btn-ghost btn-small" href="' . e(u('ftypes')) . '">' . e(t('ftypes.title_short')) . '</a></div></div>
  <h2>' . e(t('formal.upcoming')) . '</h2>
  ' . ($upcoming ? '<ul class="list">' . implode('', array_map('formal_row', $upcoming)) . '</ul>' : '<p class="muted">' . e(t('formal.none_upcoming')) . '</p>') . '
</section>' . $new . '
<section class="card"><h2>' . e(t('formal.past')) . '</h2>
  ' . ($past ? '<ul class="list">' . implode('', array_map('formal_row', $past)) . '</ul>' : '<p class="muted">' . e(t('formal.none_past')) . '</p>') . '
</section>', 'formal');
}

/* ============================ print / PDF ============================ */

if ($p === 'fprint') {
    $m = formal_meeting($code, (int) get_str('id'));
    if ($m === null || $m['status'] === 'planned') {
        redirect(u('formal'));
    }
    $draft = $m['status'] !== 'saved' ? '<p class="draft-mark">' . e(t('formal.draft')) . '</p>' : '';
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="' . e(current_lang()) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>' . e($m['type_name'] . ' ' . $m['meeting_date']) . '</title><link rel="stylesheet" href="' . e(asset('app.css')) . '"></head><body class="print-page"><main class="wrap">'
        . '<div class="no-print row"><button type="button" class="btn" data-print>' . e(t('formal.print_btn')) . '</button><a class="btn btn-ghost" href="' . e(u('fm', ['id' => $m['id']])) . '">' . e(t('adm.back')) . '</a></div>'
        . '<article class="protocol">' . $draft . '<header class="protocol-head"><div class="protocol-group">' . e($group['name']) . '</div>'
        . '<h1>' . e(t('formal.minutes_of', ['type' => $m['type_name']])) . '</h1>'
        . '<dl class="facts"><dt>' . e(t('formal.date')) . '</dt><dd>' . e(formal_date_label($m)) . '</dd>'
        . ($m['place'] ? '<dt>' . e(t('formal.place')) . '</dt><dd>' . e($m['place']) . '</dd>' : '')
        . '<dt>' . e(t('formal.attendees')) . '</dt><dd>' . ($m['attendees'] !== null ? (int) $m['attendees'] : '–') . '</dd></dl></header>'
        . render_items(formal_items_tree((int) $m['id']), true) . addenda_html((int) $m['id'])
        . '<footer class="muted small">' . e(t('formal.printed', ['date' => date('Y-m-d H:i')])) . '</footer></article>'
        . '</main><script src="' . e(asset('app.js')) . '" defer></script></body></html>';
    exit;
}

/* ============================ one meeting ============================ */

if ($p === 'fm') {
    $mid = (int) (get_str('id') ?: post_str('id'));
    $m = formal_meeting($code, $mid);
    if ($m === null) {
        flash('error', t('meet.not_found'));
        redirect(u('formal'));
    }
    $self = u('fm', ['id' => $mid]);
    $isWriter = $m['status'] === 'started' && (int) $m['writer_user_id'] === $myId;

    if ($isPost) {
        $do = post_str('do');
        $ajax = post_str('ajax') === '1';

        if ($do === 'agenda' && $m['status'] === 'planned') {
            can('formal.edit') || deny();
            foreach (post_map('agenda') as $iid => $txt) {
                $txt = clean_text($txt, 4000);
                db_exec(db(), 'UPDATE grp_formal_items SET agenda_text = ? WHERE id = ? AND meeting_id = ?', [$txt !== '' ? $txt : null, (int) $iid, $mid]);
            }
            $time = post_str('time');
            if (valid_date(post_str('date')) && ($time === '' || valid_time($time))) {
                db_exec(db(), 'UPDATE grp_formal_meetings SET meeting_date = ?, start_time = ?, place = ? WHERE id = ?',
                    [post_str('date'), $time !== '' ? $time : null, ($pl = clean_line(post_str('place'), 200)) !== '' ? $pl : null, $mid]);
            }
            group_log($code, $myId, 'formal.agenda_changed', $m['type_name'] . ' ' . $m['meeting_date']);
            flash('ok', t('formal.agenda_saved'));
            redirect($self);
        }

        if ($do === 'delete' && $m['status'] === 'planned') {
            can('formal.edit') || deny();
            db_exec(db(), 'DELETE FROM grp_formal_meetings WHERE id = ?', [$mid]);
            group_log($code, $myId, 'formal.deleted', $m['type_name'] . ' ' . $m['meeting_date']);
            formal_route_pending($code, (int) $m['type_id']);
            flash('ok', t('formal.deleted'));
            redirect(u('formal'));
        }

        if ($do === 'start' && $m['status'] === 'planned') {
            (can('formal.edit') || can('minutes.edit')) || deny();
            db_exec(db(), 'UPDATE grp_formal_meetings SET status = ?, started_at = ?, writer_user_id = ? WHERE id = ? AND status = ?', ['started', now_db(), $myId, $mid, 'planned']);
            group_log($code, $myId, 'formal.started', $m['type_name'] . ' ' . $m['meeting_date']);
            redirect($self);
        }

        if ($do === 'takeover' && $m['status'] === 'started') {
            can('minutes.edit') || deny();
            db_exec(db(), 'UPDATE grp_formal_meetings SET writer_user_id = ? WHERE id = ?', [$myId, $mid]);
            group_log($code, $myId, 'formal.takeover', $m['type_name'] . ' ' . $m['meeting_date']);
            redirect($self);
        }

        if (($do === 'draft' || $do === 'final') && $m['status'] === 'started') {
            if (!$isWriter || !(can('minutes.edit') || can('formal.edit'))) {
                if ($ajax) {
                    header('Content-Type: application/json');
                    exit(json_encode(['ok' => false, 'msg' => t('formal.not_writer')]));
                }
                flash('error', t('formal.not_writer'));
                redirect($self);
            }
            $minutes = post_map('minutes');
            $include = post_map('include');
            $decision = post_map('decision');
            foreach (db_all(db(), 'SELECT id, is_decision FROM grp_formal_items WHERE meeting_id = ?', [$mid]) as $it) {
                $iid = (string) $it['id'];
                if (!array_key_exists($iid, $minutes)) {
                    continue;
                }
                $dec = (int) $it['is_decision'] === 1 && in_array($decision[$iid] ?? '', DECISIONS, true) ? $decision[$iid] : null;
                db_exec(db(), 'UPDATE grp_formal_items SET minutes_text = ?, include_agenda = ?, decision = ? WHERE id = ?', [
                    ($txt = clean_text($minutes[$iid], 8000)) !== '' ? $txt : null,
                    ($include[$iid] ?? '') === '1' ? 1 : 0,
                    $dec,
                    (int) $iid,
                ]);
            }
            $att = post_str('attendees');
            db_exec(db(), 'UPDATE grp_formal_meetings SET attendees = ? WHERE id = ?', [preg_match('/^\d{1,4}$/', $att) ? min(9999, (int) $att) : null, $mid]);

            if ($ajax) {
                header('Content-Type: application/json');
                exit(json_encode(['ok' => true, 'time' => date('H:i:s')]));
            }
            if ($do === 'final') {
                $missing = decision_items_missing($mid);
                if ($missing) {
                    flash('error', t('formal.missing_decisions', ['items' => implode(', ', array_map(fn ($r) => $r['heading'], $missing))]));
                    redirect($self);
                }
                db_exec(db(), 'UPDATE grp_formal_meetings SET status = ?, saved_at = ?, saved_by = ?, writer_user_id = NULL WHERE id = ?', ['saved', now_db(), $myId, $mid]);
                db_exec(db(), 'UPDATE grp_suggestions SET used_at = ? WHERE item_id IN (SELECT id FROM grp_formal_items WHERE meeting_id = ?)', [now_db(), $mid]);
                group_log($code, $myId, 'formal.saved', $m['type_name'] . ' ' . $m['meeting_date']);
                formal_route_pending($code, (int) $m['type_id']);
                flash('ok', t('formal.saved_final'));
                redirect($self);
            }
            flash('ok', t('formal.draft_saved'));
            redirect($self);
        }

        if ($do === 'addendum' && $m['status'] === 'saved') {
            can('minutes.edit') || deny();
            $txt = clean_text(post_str('text'), 4000);
            if ($txt !== '') {
                db_exec(db(), 'INSERT INTO grp_formal_addenda (group_code, meeting_id, user_id, text, created_at) VALUES (?, ?, ?, ?, ?)', [$code, $mid, $myId, $txt, now_db()]);
                group_log($code, $myId, 'formal.addendum', $m['type_name'] . ' ' . $m['meeting_date']);
                flash('ok', t('formal.addendum_saved'));
            }
            redirect($self);
        }
        redirect($self);
    }

    $tree = formal_items_tree($mid);
    $head = '<section class="card">
  <p class="muted small"><a href="' . e(u('formal')) . '">← ' . e(t('nav.formal')) . '</a></p>
  <h1>' . e($m['type_name']) . ' ' . status_tag((string) $m['status']) . '</h1>
  <p><strong>' . e(formal_date_label($m)) . '</strong>' . ($m['place'] ? ' · ' . e($m['place']) : '') . '</p>';

    /* ----- planned: agenda ----- */
    if ($m['status'] === 'planned') {
        $startBtn = (can('formal.edit') || can('minutes.edit'))
            ? post_button($self, t('formal.start'), ['do' => 'start'], t('formal.start_confirm'), 'btn') . '<p class="muted small">' . e(t('formal.start_hint')) . '</p>' : '';
        $body = $head . $startBtn . '</section><section class="card"><h2>' . e(t('formal.agenda')) . '</h2>';
        if (can('formal.edit')) {
            $fields = '';
            foreach ($tree as $t) {
                foreach (array_merge([$t], $t['children']) as $it) {
                    $fields .= '<div class="agenda-edit' . ($it['parent_id'] !== null ? ' child' : '') . '"><label for="ag' . (int) $it['id'] . '"><span class="no">§ ' . e($it['no']) . '</span> ' . e($it['heading']) . item_kind_tag($it)
                        . ((int) $it['is_decision'] === 1 ? ' <span class="tag">' . e(t('formal.decision_point')) . '</span>' : '') . '</label>'
                        . '<textarea id="ag' . (int) $it['id'] . '" name="agenda[' . (int) $it['id'] . ']" rows="2" maxlength="4000">' . e($it['agenda_text']) . '</textarea></div>';
                }
            }
            $body .= '<form method="post" action="' . e($self) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="agenda">
  <div class="grid2"><div class="stack"><label for="date">' . e(t('formal.date')) . '</label><input id="date" name="date" type="date" value="' . e($m['meeting_date']) . '" required></div>
  <div class="stack"><label for="time">' . e(t('formal.time')) . '</label><input id="time" name="time" type="time" value="' . e($m['start_time'] !== null ? substr((string) $m['start_time'], 0, 5) : '') . '"></div></div>
  <label for="place">' . e(t('formal.place')) . '</label><input id="place" name="place" value="' . e($m['place']) . '" maxlength="200">
  ' . $fields . '<button class="btn" type="submit">' . e(t('formal.save_agenda')) . '</button></form>';
        } else {
            $body .= render_items($tree, false);
        }
        $body .= '<p class="muted small">' . e(t('formal.agenda_box_hint')) . ' <a href="' . e(u('suggest')) . '">' . e(t('box.title')) . ' →</a></p></section>';
        if (can('formal.edit')) {
            $body .= '<section class="card danger"><h2>' . e(t('formal.delete_title')) . '</h2><p class="muted small">' . e(t('formal.delete_intro')) . '</p>'
                . post_button($self, t('formal.delete'), ['do' => 'delete'], t('formal.delete_confirm'), 'btn btn-danger btn-small') . '</section>';
        }
        page($m['type_name'], $body, 'formal');
    }

    /* ----- started: minutes ----- */
    if ($m['status'] === 'started') {
        $writer = $m['writer_user_id'] !== null ? db_one(db(), 'SELECT username, display_name FROM grp_users WHERE id = ?', [(int) $m['writer_user_id']]) : null;
        if (!$isWriter) {
            $take = can('minutes.edit') ? post_button($self, t('formal.takeover'), ['do' => 'takeover'], t('formal.takeover_confirm'), 'btn btn-ghost btn-small') : '';
            page($m['type_name'], $head . '<p class="notice">' . e(t('formal.writing_now', ['name' => $writer !== null ? user_label($writer) : '–'])) . '</p>
  <div class="row"><a class="btn btn-ghost btn-small" href="' . e($self) . '">' . e(t('formal.refresh')) . '</a>' . $take . '</div></section>
<section class="card"><h2>' . e(t('formal.minutes')) . ' <span class="muted small">(' . e(t('formal.draft')) . ')</span></h2>' . render_items($tree, true) . '</section>', 'formal');
        }

        $fields = '';
        foreach ($tree as $t) {
            foreach (array_merge([$t], $t['children']) as $it) {
                $iid = (int) $it['id'];
                $agendaText = trim((string) ($it['agenda_text'] ?? ''));
                $dec = '';
                if ((int) $it['is_decision'] === 1) {
                    $opts = '<option value="">' . e(t('formal.choose_decision')) . '</option>';
                    foreach (DECISIONS as $d) {
                        $opts .= '<option value="' . $d . '"' . ($it['decision'] === $d ? ' selected' : '') . '>' . e(t('decision.' . $d)) . '</option>';
                    }
                    $dec = '<label for="dec' . $iid . '">' . e(t('formal.decision')) . '</label><select id="dec' . $iid . '" name="decision[' . $iid . ']">' . $opts . '</select>';
                }
                $fields .= '<div class="minutes-item' . ($it['parent_id'] !== null ? ' child' : '') . '">
  <div class="agenda-head"><span class="no">§ ' . e($it['no']) . '</span> <strong>' . e($it['heading']) . '</strong>' . item_kind_tag($it) . '</div>
  ' . ($agendaText !== '' ? '<div class="pre agenda-text">' . e($agendaText) . '</div><label class="checkbox-row small"><input type="checkbox" name="include[' . $iid . ']" value="1"' . ((int) $it['include_agenda'] === 1 ? ' checked' : '') . '> ' . e(t('formal.include_agenda')) . '</label>' : '<input type="hidden" name="include[' . $iid . ']" value="1">') . '
  <textarea name="minutes[' . $iid . ']" rows="3" maxlength="8000" aria-label="' . e($it['heading']) . '">' . e($it['minutes_text']) . '</textarea>
  ' . $dec . '
</div>';
            }
        }
        page($m['type_name'], $head . '<p class="muted small">' . e(t('formal.you_write')) . '</p>
  <div class="row"><a class="btn btn-ghost btn-small" href="' . e(u('fprint', ['id' => $mid])) . '">' . e(t('formal.print')) . '</a></div></section>
<section class="card">
  <form method="post" action="' . e($self) . '" class="stack" data-autosave>' . csrf_field() . '
    <input type="hidden" name="do" value="draft">
    <label for="attendees">' . e(t('formal.attendees')) . '</label>
    <input id="attendees" name="attendees" type="number" min="0" max="9999" value="' . e($m['attendees'] !== null ? (string) $m['attendees'] : '') . '" class="short">
    <h2>' . e(t('formal.minutes')) . '</h2>
    ' . $fields . '
    <p class="autosave-status muted small" data-autosave-status></p>
    <div class="row sticky-actions">
      <button class="btn btn-ghost" type="submit">' . e(t('formal.save_draft')) . '</button>
      <button class="btn" type="submit" name="do" value="final" data-confirm-click="' . e(t('formal.final_confirm')) . '">' . e(t('formal.save_final')) . '</button>
    </div>
  </form>
</section>', 'formal');
    }

    /* ----- saved: read-only + addenda ----- */
    $addForm = can('minutes.edit') ? '<form method="post" action="' . e($self) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="addendum">
  <label for="text">' . e(t('formal.add_addendum')) . '</label><textarea id="text" name="text" rows="3" maxlength="4000" required></textarea>
  <button class="btn btn-small" type="submit">' . e(t('formal.save_addendum')) . '</button></form>' : '';
    page($m['type_name'], $head . '<dl class="facts"><dt>' . e(t('formal.attendees')) . '</dt><dd>' . ($m['attendees'] !== null ? (int) $m['attendees'] : '–') . '</dd></dl>
  <div class="row"><a class="btn btn-small" href="' . e(u('fprint', ['id' => $mid])) . '">' . e(t('formal.print')) . '</a></div></section>
<section class="card"><h2>' . e(t('formal.minutes')) . '</h2>' . render_items($tree, true) . '</section>
<section class="card">' . addenda_html($mid) . $addForm . '<p class="muted small">' . e(t('formal.locked_hint')) . '</p></section>', 'formal');
}
