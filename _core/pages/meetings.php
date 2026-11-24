<?php
declare(strict_types=1);

/*
 * Group app pages for the regular meetings (calendar).
 * Included by group-app/index.php, runs in its scope:
 * $p, $isPost, $group, $code, $me, $myId and the helpers u(), page(), can(), deny().
 */

/** Where to return after a quick action (take / can't come). */
function back_url(): string
{
    $b = post_str('back');
    if ($b === 'calendar') {
        return u('calendar', array_filter(['weeks' => (int) post_str('weeks') ?: null]));
    }
    if ($b === 'occ') {
        return u('occ', ['s' => (int) post_str('s'), 'd' => post_str('d')]);
    }
    return u();
}

function occ_card(array $o, int $myId, string $back, array $extra = []): string
{
    $state = $o['state'];
    $time = e($o['start']) . ($o['end'] !== '' ? '–' . e($o['end']) : '');
    $tags = '<span class="tag ' . ($o['is_open'] ? 'tag-open' : 'tag-closed') . '">' . e($o['is_open'] ? t('meet.open') : t('meet.closed')) . '</span>';
    if ($o['cancelled']) {
        $tags .= ' <span class="tag tag-cancelled">' . e(t('meet.cancelled')) . '</span>';
    } elseif ($o['time_changed'] || $o['open_changed']) {
        $tags .= ' <span class="tag tag-changed">' . e(t('meet.changed')) . '</span>';
    }

    $resp = match ($state) {
        'cancelled'   => '',
        'empty'       => '<div class="occ-resp alert">' . e(t('meet.no_resp')) . '</div>',
        'unavailable' => '<div class="occ-resp alert">' . e(t('meet.resp_unavailable', ['name' => $o['resp_label']])) . '</div>',
        default       => '<div class="occ-resp">' . e(t('meet.resp')) . ': <strong>' . e($o['resp_label']) . '</strong>' . ($o['resp_id'] === $myId ? ' <span class="muted small">(' . e(t('meet.you')) . ')</span>' : '') . '</div>',
    };

    $hidden = ['s' => $o['series_id'], 'd' => $o['date'], 'back' => $back] + $extra;
    $actions = '';
    if (!$o['cancelled'] && $o['date'] >= date('Y-m-d')) {
        if (($state === 'empty' || $state === 'unavailable') && $o['resp_id'] !== $myId) {
            $actions .= post_button(u('take'), t('meet.take'), $hidden, '', 'btn btn-small');
        }
        if ($o['resp_id'] === $myId && !$o['unavailable']) {
            $actions .= post_button(u('unavailable'), t('meet.cant'), $hidden, t('meet.cant_confirm'), 'btn btn-ghost btn-small');
        }
        if ($o['resp_id'] === $myId && $o['unavailable']) {
            $actions .= post_button(u('unavailable'), t('meet.can_after_all'), $hidden + ['undo' => '1'], '', 'btn btn-ghost btn-small');
        }
    }

    return '<li class="occ state-' . e($state) . '">'
        . '<div class="occ-time">' . $time . '</div>'
        . '<div class="occ-main"><a class="occ-title" href="' . e(u('occ', ['s' => $o['series_id'], 'd' => $o['date']])) . '">' . e($o['title']) . '</a> ' . $tags
        . $resp
        . ($o['note'] !== '' ? '<div class="occ-note">' . e($o['note']) . '</div>' : '')
        . '</div>'
        . ($actions !== '' ? '<div class="occ-actions">' . $actions . '</div>' : '')
        . '</li>';
}

/** List grouped by date. */
function occ_list(array $occs, int $myId, string $back, array $extra = []): string
{
    if (!$occs) {
        return '<p class="muted">' . e(t('meet.none')) . '</p>';
    }
    $html = '';
    $current = '';
    foreach ($occs as $o) {
        if ($o['date'] !== $current) {
            $html .= ($current !== '' ? '</ul>' : '') . '<h3 class="day">' . e(nice_date($o['date'])) . ($o['date'] <= date('Y-m-d', strtotime('+1 day')) ? ' <span class="muted small">' . e(nice_date($o['date'], false)) . '</span>' : '') . '</h3><ul class="occs">';
            $current = $o['date'];
        }
        $html .= occ_card($o, $myId, $back, $extra);
    }
    return $html . '</ul>';
}

function member_options(array $labels, ?int $selected, string $firstLabel = '', string $firstValue = ''): string
{
    $h = $firstLabel !== '' ? '<option value="' . e($firstValue) . '">' . e($firstLabel) . '</option>' : '';
    foreach ($labels as $id => $label) {
        $h .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
}

/* ---------------------------- quick actions ---------------------------- */

if ($isPost && ($p === 'take' || $p === 'unavailable')) {
    $sid = (int) post_str('s');
    $date = post_str('d');
    $s = series_by_id($code, $sid);
    if ($s === null || !series_has_date($s, $date) || $date < date('Y-m-d')) {
        flash('error', t('err.generic'));
        redirect(back_url());
    }
    $o = meeting_occurrences($code, $date, $date, null, $sid)[0] ?? null;
    if ($o === null || $o['cancelled']) {
        flash('error', t('err.generic'));
        redirect(back_url());
    }

    if ($p === 'take') {
        if (($o['state'] !== 'empty' && $o['state'] !== 'unavailable') || $o['resp_id'] === $myId) {
            flash('error', t('meet.already_taken'));
            redirect(back_url());
        }
        occ_save($code, $sid, $date, ['resp_mode' => 'user', 'resp_user_id' => $myId, 'unavailable' => 0], $myId);
        group_log($code, $myId, 'meeting.taken', $o['title'] . ' ' . $date);
        flash('ok', t('meet.taken_ok'));
    } else {
        if ($o['resp_id'] !== $myId) {
            deny();
        }
        $undo = post_str('undo') === '1';
        occ_save($code, $sid, $date, ['resp_mode' => 'user', 'resp_user_id' => $myId, 'unavailable' => $undo ? 0 : 1], $myId);
        group_log($code, $myId, $undo ? 'meeting.available' : 'meeting.unavailable', $o['title'] . ' ' . $date);
        flash('ok', $undo ? t('meet.available_ok') : t('meet.unavailable_ok'));
    }
    redirect(back_url());
}

/* ------------------------------ calendar ------------------------------ */

if ($p === 'calendar') {
    $weeks = max(1, min(CALENDAR_MAX_WEEKS, (int) (get_str('weeks') ?: CALENDAR_DEFAULT_WEEKS)));
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+' . ($weeks * 7) . ' days'));
    $occs = meeting_occurrences($code, $from, $to);
    $more = $weeks < CALENDAR_MAX_WEEKS ? '<p><a class="btn btn-ghost" href="' . e(u('calendar', ['weeks' => min(CALENDAR_MAX_WEEKS, $weeks + CALENDAR_DEFAULT_WEEKS)])) . '">' . e(t('meet.show_more')) . '</a></p>' : '';
    $admin = can('meetings.edit') ? '<a class="btn btn-small" href="' . e(u('series')) . '">' . e(t('meet.manage_series')) . '</a>' : '<a class="btn btn-ghost btn-small" href="' . e(u('series')) . '">' . e(t('meet.all_series')) . '</a>';
    page(t('nav.meetings'), '<section class="card">
  <div class="row between"><h1>' . e(t('nav.meetings')) . '</h1>' . $admin . '</div>
  <p class="muted small">' . e(t('meet.range', ['weeks' => $weeks])) . '</p>
  ' . occ_list($occs, $myId, 'calendar', $weeks !== CALENDAR_DEFAULT_WEEKS ? ['weeks' => $weeks] : []) . '
</section>' . $more, 'calendar');
}

/* ---------------------------- one occurrence ---------------------------- */

if ($p === 'occ') {
    $sid = (int) (get_str('s') ?: post_str('s'));
    $date = get_str('d') ?: post_str('d');
    $s = series_by_id($code, $sid);
    if ($s === null || !series_has_date($s, $date)) {
        flash('error', t('meet.not_found'));
        redirect(u('calendar'));
    }
    $o = meeting_occurrences($code, $date, $date, null, $sid)[0];
    $labels = member_labels($code);
    $self = u('occ', ['s' => $sid, 'd' => $date]);

    if ($isPost) {
        can('meetings.edit') || deny();
        $start = post_str('start_time');
        $end = post_str('end_time');
        $openSel = post_str('is_open');
        $respSel = post_str('resp');
        if (($start !== '' && !valid_time($start)) || ($end !== '' && !valid_time($end))) {
            flash('error', t('meet.err_time'));
            redirect($self);
        }
        $seriesStart = substr((string) $s['start_time'], 0, 5);
        $seriesEnd = $s['end_time'] !== null ? substr((string) $s['end_time'], 0, 5) : '';

        if ($respSel === 'default') {
            [$mode, $uid] = ['default', null];
        } elseif ($respSel === 'none') {
            [$mode, $uid] = ['none', null];
        } elseif (isset($labels[(int) $respSel])) {
            [$mode, $uid] = ['user', (int) $respSel];
        } else {
            [$mode, $uid] = [$o['resp_mode'], $o['resp_id']];
        }
        $newResp = $mode === 'user' ? $uid : ($mode === 'default' ? $o['default_id'] : null);

        occ_save($code, $sid, $date, [
            'cancelled'    => post_str('cancelled') === '1' ? 1 : 0,
            'start_time'   => $start !== '' && $start !== $seriesStart ? $start : null,
            'end_time'     => $end !== '' && $end !== $seriesEnd ? $end : null,
            'is_open'      => $openSel === '1' || $openSel === '0' ? ((int) $openSel === (int) $s['is_open'] ? null : (int) $openSel) : null,
            'note'         => ($note = clean_text(post_str('note'), 500)) !== '' ? $note : null,
            'resp_mode'    => $mode,
            'resp_user_id' => $uid,
            'unavailable'  => $newResp === $o['resp_id'] && $o['unavailable'] ? 1 : 0,
        ], $myId);
        group_log($code, $myId, post_str('cancelled') === '1' ? 'meeting.cancelled' : 'meeting.changed', $s['title'] . ' ' . $date);
        flash('ok', t('meet.saved'));
        redirect($self);
    }

    $info = '<section class="card">
  <p class="muted small"><a href="' . e(u('calendar')) . '">← ' . e(t('nav.meetings')) . '</a></p>
  <h1>' . e($o['title']) . '</h1>
  <p><strong>' . e(nice_date($date, false)) . ' ' . e(substr($date, 0, 4)) . '</strong> · ' . e($o['start']) . ($o['end'] !== '' ? '–' . e($o['end']) : '') . '</p>
  <ul class="occs">' . occ_card($o, $myId, 'occ') . '</ul>
  ' . ($o['description'] !== '' ? '<div class="pre description">' . e($o['description']) . '</div>' : '') . '
  <p class="muted small">' . e(series_summary($s)) . '</p>
</section>';

    $edit = '';
    if (can('meetings.edit')) {
        $respOptions = '<option value="default"' . ($o['resp_mode'] === 'default' ? ' selected' : '') . '>' . e(t('meet.resp_default', ['name' => $o['default_id'] !== null ? $labels[$o['default_id']] : t('meet.nobody')])) . '</option>'
            . '<option value="none"' . ($o['resp_mode'] === 'none' ? ' selected' : '') . '>' . e(t('meet.nobody')) . '</option>'
            . member_options($labels, $o['resp_mode'] === 'user' ? $o['resp_id'] : null);
        $openNow = $o['is_open'] ? '1' : '0';
        $edit = '<section class="card">
  <h2>' . e(t('meet.change_this')) . '</h2>
  <p class="muted small">' . e(t('meet.change_this_intro')) . '</p>
  <form method="post" action="' . e($self) . '" class="stack">' . csrf_field() . '
    <label for="resp">' . e(t('meet.resp')) . '</label>
    <select id="resp" name="resp">' . $respOptions . '</select>
    <div class="grid2">
      <div class="stack"><label for="start_time">' . e(t('meet.start')) . '</label><input id="start_time" name="start_time" type="time" value="' . e($o['start']) . '"></div>
      <div class="stack"><label for="end_time">' . e(t('meet.end')) . '</label><input id="end_time" name="end_time" type="time" value="' . e($o['end']) . '"></div>
    </div>
    <label for="is_open">' . e(t('meet.type')) . '</label>
    <select id="is_open" name="is_open"><option value="1"' . ($openNow === '1' ? ' selected' : '') . '>' . e(t('meet.open')) . '</option><option value="0"' . ($openNow === '0' ? ' selected' : '') . '>' . e(t('meet.closed')) . '</option></select>
    <label for="note">' . e(t('meet.note')) . '</label>
    <textarea id="note" name="note" rows="2" maxlength="500" placeholder="' . e(t('meet.note_ph')) . '">' . e($o['note']) . '</textarea>
    <label class="checkbox-row"><input type="checkbox" name="cancelled" value="1"' . ($o['cancelled'] ? ' checked' : '') . '> ' . e(t('meet.cancel_this')) . '</label>
    <button class="btn" type="submit">' . e(t('meet.save')) . '</button>
  </form>
</section>';
    }
    page($o['title'], $info . $edit, 'calendar');
}

/* ------------------------------- series ------------------------------- */

if ($p === 'series') {
    $labels = member_labels($code);
    $id = (int) (get_str('id') ?: post_str('id'));
    $s = $id > 0 ? series_by_id($code, $id) : null;

    if ($isPost) {
        can('meetings.edit') || deny();
        $do = post_str('do');

        if ($do === 'delete' && $s !== null) {
            db_exec(db(), 'DELETE FROM grp_meeting_series WHERE id = ? AND group_code = ?', [$id, $code]);
            group_log($code, $myId, 'meeting.series_deleted', (string) $s['title']);
            flash('ok', t('series.deleted'));
            redirect(u('series'));
        }

        $f = [
            'title'           => clean_line(post_str('title'), 100),
            'description'     => clean_text(post_str('description'), 2000),
            'start_date'      => post_str('start_date'),
            'start_time'      => post_str('start_time'),
            'end_time'        => post_str('end_time'),
            'is_open'         => post_str('is_open') === '0' ? 0 : 1,
            'recur'           => in_array(post_str('recur'), RECUR_TYPES, true) ? post_str('recur') : 'none',
            'interval_n'      => max(1, min(12, (int) post_str('interval_n'))),
            'nth'             => in_array((int) post_str('nth'), [1, 2, 3, 4, -1], true) ? (int) post_str('nth') : 1,
            'until_date'      => post_str('until_date'),
            'default_user_id' => isset($labels[(int) post_str('default_user_id')]) ? (int) post_str('default_user_id') : null,
        ];
        $errors = [];
        if ($f['title'] === '') {
            $errors[] = t('series.err_title');
        }
        if (!valid_date($f['start_date'])) {
            $errors[] = t('series.err_date');
        }
        if (!valid_time($f['start_time']) || ($f['end_time'] !== '' && !valid_time($f['end_time']))) {
            $errors[] = t('meet.err_time');
        }
        if ($f['until_date'] !== '' && (!valid_date($f['until_date']) || $f['until_date'] < $f['start_date'])) {
            $errors[] = t('series.err_until');
        }
        if ($errors) {
            foreach ($errors as $err) {
                flash('error', $err);
            }
            $_SESSION['series_form'] = $f;
            redirect(u('series', $s !== null ? ['id' => $id] : ['new' => 1]));
        }
        $f['end_time'] = $f['end_time'] !== '' ? $f['end_time'] : null;
        $f['until_date'] = $f['recur'] !== 'none' && $f['until_date'] !== '' ? $f['until_date'] : null;
        $f['nth'] = $f['recur'] === 'monthly_nth' ? $f['nth'] : null;
        $f['description'] = $f['description'] !== '' ? $f['description'] : null;
        $f['updated_at'] = now_db();

        if ($s === null) {
            $f['group_code'] = $code;
            $f['created_at'] = now_db();
            db_exec(db(), 'INSERT INTO grp_meeting_series (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_fill(0, count($f), '?')) . ')', array_values($f));
            group_log($code, $myId, 'meeting.series_created', $f['title']);
        } else {
            $set = implode(', ', array_map(fn ($k) => $k . ' = ?', array_keys($f)));
            db_exec(db(), 'UPDATE grp_meeting_series SET ' . $set . ' WHERE id = ? AND group_code = ?', array_merge(array_values($f), [$id, $code]));
            group_log($code, $myId, 'meeting.series_changed', $f['title']);
        }
        flash('ok', t('series.saved'));
        redirect(u('series'));
    }

    /* ----- form (new / edit) ----- */
    if (can('meetings.edit') && ($s !== null || get_str('new') === '1')) {
        $f = $_SESSION['series_form'] ?? null;
        unset($_SESSION['series_form']);
        $f = is_array($f) ? $f : ($s ?? [
            'title' => '', 'description' => '', 'start_date' => date('Y-m-d'), 'start_time' => '19:00', 'end_time' => '',
            'is_open' => 1, 'recur' => 'weekly', 'interval_n' => 1, 'nth' => 1, 'until_date' => '', 'default_user_id' => null,
        ]);
        $recurOpts = '';
        foreach (RECUR_TYPES as $r) {
            $recurOpts .= '<option value="' . $r . '"' . ($f['recur'] === $r ? ' selected' : '') . '>' . e(t('recur.' . $r)) . '</option>';
        }
        $nthOpts = '';
        foreach ([1, 2, 3, 4, -1] as $n) {
            $nthOpts .= '<option value="' . $n . '"' . ((int) ($f['nth'] ?? 1) === $n ? ' selected' : '') . '>' . e(t('nth.' . $n)) . '</option>';
        }
        $isOpen = (int) $f['is_open'] === 1;
        $form = '<section class="card">
  <p class="muted small"><a href="' . e(u('series')) . '">← ' . e(t('series.title')) . '</a></p>
  <h1>' . e($s !== null ? t('series.edit') : t('series.new')) . '</h1>
  <form method="post" action="' . e(u('series')) . '" class="stack" data-recur-form>' . csrf_field() . '
    <input type="hidden" name="id" value="' . ($s !== null ? $id : 0) . '">
    <label for="title">' . e(t('series.name')) . '</label>
    <input id="title" name="title" value="' . e((string) $f['title']) . '" maxlength="100" required placeholder="' . e(t('series.name_ph')) . '">
    <label for="description">' . e(t('series.description')) . '</label>
    <textarea id="description" name="description" rows="3" maxlength="2000" placeholder="' . e(t('series.description_ph')) . '">' . e((string) ($f['description'] ?? '')) . '</textarea>
    <label for="start_date">' . e(t('series.start_date')) . '</label>
    <input id="start_date" name="start_date" type="date" value="' . e((string) $f['start_date']) . '" required>
    <div class="grid2">
      <div class="stack"><label for="start_time">' . e(t('meet.start')) . '</label><input id="start_time" name="start_time" type="time" value="' . e(substr((string) $f['start_time'], 0, 5)) . '" required></div>
      <div class="stack"><label for="end_time">' . e(t('meet.end')) . '</label><input id="end_time" name="end_time" type="time" value="' . e(substr((string) ($f['end_time'] ?? ''), 0, 5)) . '"></div>
    </div>
    <label>' . e(t('meet.type')) . '</label>
    <div class="row">
      <label class="checkbox-row"><input type="radio" name="is_open" value="1"' . ($isOpen ? ' checked' : '') . '> ' . e(t('meet.open')) . '</label>
      <label class="checkbox-row"><input type="radio" name="is_open" value="0"' . (!$isOpen ? ' checked' : '') . '> ' . e(t('meet.closed')) . '</label>
    </div>
    <label for="recur">' . e(t('series.recur')) . '</label>
    <select id="recur" name="recur" data-recur>' . $recurOpts . '</select>
    <div class="stack" data-recur-show="daily weekly monthly_date monthly_nth">
      <label for="interval_n">' . e(t('series.interval')) . '</label>
      <input id="interval_n" name="interval_n" type="number" min="1" max="12" value="' . (int) $f['interval_n'] . '">
      <small class="hint">' . e(t('series.interval_hint')) . '</small>
    </div>
    <div class="stack" data-recur-show="monthly_nth">
      <label for="nth">' . e(t('series.nth')) . '</label>
      <select id="nth" name="nth">' . $nthOpts . '</select>
      <small class="hint">' . e(t('series.nth_hint')) . '</small>
    </div>
    <div class="stack" data-recur-show="daily weekly monthly_date monthly_nth">
      <label for="until_date">' . e(t('series.until')) . '</label>
      <input id="until_date" name="until_date" type="date" value="' . e((string) ($f['until_date'] ?? '')) . '">
      <small class="hint">' . e(t('series.until_hint')) . '</small>
    </div>
    <label for="default_user_id">' . e(t('series.default_resp')) . '</label>
    <select id="default_user_id" name="default_user_id">' . member_options($labels, $f['default_user_id'] !== null ? (int) $f['default_user_id'] : null, t('meet.nobody'), '') . '</select>
    <small class="hint">' . e(t('series.default_resp_hint')) . '</small>
    <button class="btn" type="submit">' . e(t('series.save')) . '</button>
  </form>
</section>';
        if ($s !== null) {
            $form .= '<section class="card danger"><h2>' . e(t('series.delete_title')) . '</h2><p class="muted small">' . e(t('series.delete_intro')) . '</p>'
                . post_button(u('series'), t('series.delete'), ['do' => 'delete', 'id' => $id], t('series.delete_confirm'), 'btn btn-danger btn-small') . '</section>';
        }
        page($s !== null ? t('series.edit') : t('series.new'), $form, 'calendar');
    }

    /* ----- list ----- */
    $all = db_all(db(), 'SELECT * FROM grp_meeting_series WHERE group_code = ? ORDER BY (until_date IS NOT NULL AND until_date < ?), start_time, title', [$code, date('Y-m-d')]);
    $list = '';
    foreach ($all as $row) {
        $ended = $row['until_date'] !== null && $row['until_date'] < date('Y-m-d') || ($row['recur'] === 'none' && $row['start_date'] < date('Y-m-d'));
        $def = $row['default_user_id'] !== null && isset($labels[(int) $row['default_user_id']]) ? $labels[(int) $row['default_user_id']] : '';
        $list .= '<li class="series-item' . ($ended ? ' ended' : '') . '"><div><strong>' . e($row['title']) . '</strong> <span class="tag ' . ((int) $row['is_open'] === 1 ? 'tag-open' : 'tag-closed') . '">' . e((int) $row['is_open'] === 1 ? t('meet.open') : t('meet.closed')) . '</span>'
            . ($ended ? ' <span class="tag">' . e(t('series.ended')) . '</span>' : '')
            . '<div class="muted small">' . e(series_summary($row)) . '</div>'
            . ($def !== '' ? '<div class="small">' . e(t('series.default_resp')) . ': ' . e($def) . '</div>' : '') . '</div>'
            . (can('meetings.edit') ? '<a class="btn btn-ghost btn-small" href="' . e(u('series', ['id' => $row['id']])) . '">' . e(t('series.edit_btn')) . '</a>' : '')
            . '</li>';
    }
    page(t('series.title'), '<section class="card">
  <p class="muted small"><a href="' . e(u('calendar')) . '">← ' . e(t('nav.meetings')) . '</a></p>
  <div class="row between"><h1>' . e(t('series.title')) . '</h1>' . (can('meetings.edit') ? '<a class="btn btn-small" href="' . e(u('series', ['new' => 1])) . '">' . e(t('series.new')) . '</a>' : '') . '</div>
  ' . ($list !== '' ? '<ul class="list series-list">' . $list . '</ul>' : '<p class="muted">' . e(t('series.none')) . '</p>') . '
</section>', 'calendar');
}
