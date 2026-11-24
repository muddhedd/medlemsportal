<?php
declare(strict_types=1);

/*
 * Formal meetings (business / board meetings): types with agenda templates,
 * anonymous suggestion box, agenda -> minutes, tabled items carried over.
 *
 * Agenda items are a two-level list. Top level comes from the template.
 * Suggestions become children of the "other business" item; tabled items
 * from earlier meetings become children of a "tabled items" item that is
 * placed just before "other business".
 */

const DECISIONS = ['no_action', 'tabled_decision', 'tabled_discussion', 'decided'];
const TABLED = ['tabled_decision', 'tabled_discussion'];

/** Profile defaults (types + template items) from the language/terms layer. */
function formal_defaults(): array
{
    $d = $GLOBALS['gp_i18n']['text']['_formal_defaults'] ?? [];
    return is_array($d) ? $d : [];
}

/** First visit: create the profile's default meeting types and templates. */
function ensure_formal_defaults(string $groupCode): void
{
    if (db_val(db(), 'SELECT 1 FROM grp_formal_types WHERE group_code = ? LIMIT 1', [$groupCode]) !== null) {
        return;
    }
    if (db_val(db(), 'SELECT 1 FROM grp_formal_setup WHERE group_code = ?', [$groupCode]) !== null) {
        return; // defaults were created once; the group may have removed them on purpose
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec($pdo, 'INSERT INTO grp_formal_setup (group_code, created_at) VALUES (?, ?)', [$groupCode, now_db()]);
        foreach (formal_defaults() as $i => $type) {
            db_exec($pdo, 'INSERT INTO grp_formal_types (group_code, name, position, created_at) VALUES (?, ?, ?, ?)', [$groupCode, $type['name'], ($i + 1) * 10, now_db()]);
            $tid = (int) $pdo->lastInsertId();
            foreach ($type['items'] as $j => [$heading, $text, $isDecision, $kind]) {
                db_exec($pdo, 'INSERT INTO grp_formal_template_items (group_code, type_id, position, heading, default_text, is_decision, kind) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$groupCode, $tid, ($j + 1) * 10, $heading, $text !== '' ? $text : null, $isDecision ? 1 : 0, $kind]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function formal_types(string $groupCode): array
{
    return db_all(db(), 'SELECT * FROM grp_formal_types WHERE group_code = ? ORDER BY position, id', [$groupCode]);
}

function formal_type(string $groupCode, int $id): ?array
{
    return db_one(db(), 'SELECT * FROM grp_formal_types WHERE id = ? AND group_code = ?', [$id, $groupCode]);
}

function formal_meeting(string $groupCode, int $id): ?array
{
    return db_one(db(), 'SELECT m.*, t.name AS type_name FROM grp_formal_meetings m JOIN grp_formal_types t ON t.id = m.type_id WHERE m.id = ? AND m.group_code = ?', [$id, $groupCode]);
}

/** Items of a meeting as a tree: list of top items, each with 'children' and a display number. */
function formal_items_tree(int $meetingId): array
{
    $rows = db_all(db(), 'SELECT * FROM grp_formal_items WHERE meeting_id = ? ORDER BY position, id', [$meetingId]);
    $top = [];
    $children = [];
    foreach ($rows as $r) {
        if ($r['parent_id'] === null) {
            $top[] = $r + ['children' => []];
        } else {
            $children[(int) $r['parent_id']][] = $r;
        }
    }
    foreach ($top as $i => &$t) {
        $t['no'] = (string) ($i + 1);
        foreach ($children[(int) $t['id']] ?? [] as $j => $c) {
            $t['children'][] = $c + ['no' => ($i + 1) . '.' . ($j + 1), 'children' => []];
        }
    }
    unset($t);
    return $top;
}

/** Flat list (top and children, in display order). */
function formal_items_flat(int $meetingId): array
{
    $out = [];
    foreach (formal_items_tree($meetingId) as $t) {
        $kids = $t['children'];
        $out[] = $t;
        foreach ($kids as $c) {
            $out[] = $c;
        }
    }
    return $out;
}

/** The next planned meeting of a type (today or later). */
function next_planned_meeting(string $groupCode, int $typeId): ?array
{
    return db_one(db(), 'SELECT * FROM grp_formal_meetings WHERE group_code = ? AND type_id = ? AND status = ? AND meeting_date >= ? ORDER BY meeting_date, start_time, id LIMIT 1',
        [$groupCode, $typeId, 'planned', date('Y-m-d')]);
}

/** Create a planned meeting with its agenda copied from the template. Returns the id. */
function create_formal_meeting(string $groupCode, int $typeId, string $date, ?string $time, ?string $place, ?int $by): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec($pdo, 'INSERT INTO grp_formal_meetings (group_code, type_id, meeting_date, start_time, place, status, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$groupCode, $typeId, $date, $time, $place, 'planned', now_db(), $by]);
        $mid = (int) $pdo->lastInsertId();
        foreach (db_all($pdo, 'SELECT * FROM grp_formal_template_items WHERE type_id = ? ORDER BY position, id', [$typeId]) as $i => $ti) {
            db_exec($pdo, 'INSERT INTO grp_formal_items (group_code, meeting_id, parent_id, position, heading, agenda_text, is_decision, kind) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)',
                [$groupCode, $mid, ($i + 1) * 10, $ti['heading'], $ti['default_text'], (int) $ti['is_decision'], $ti['kind'] === 'other' ? 'other' : 'normal']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    formal_attach_pending($groupCode, $mid);
    return $mid;
}

/**
 * Pull unclaimed suggestions and tabled items of the meeting's type into
 * a planned meeting. Safe to call repeatedly.
 */
function formal_attach_pending(string $groupCode, int $meetingId): void
{
    $m = db_one(db(), 'SELECT * FROM grp_formal_meetings WHERE id = ? AND group_code = ?', [$meetingId, $groupCode]);
    if ($m === null || $m['status'] !== 'planned') {
        return;
    }
    $typeId = (int) $m['type_id'];

    $suggestions = db_all(db(), 'SELECT * FROM grp_suggestions WHERE group_code = ? AND type_id = ? AND item_id IS NULL AND removed_at IS NULL ORDER BY id', [$groupCode, $typeId]);
    $tabled = db_all(db(), 'SELECT i.* FROM grp_formal_items i JOIN grp_formal_meetings m ON m.id = i.meeting_id
        WHERE m.group_code = ? AND m.type_id = ? AND m.status = ? AND i.decision IN (?, ?) AND i.carried_to_item_id IS NULL AND i.meeting_id <> ?
        ORDER BY m.meeting_date, i.position, i.id', [$groupCode, $typeId, 'saved', TABLED[0], TABLED[1], $meetingId]);
    if (!$suggestions && !$tabled) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $other = db_one($pdo, 'SELECT * FROM grp_formal_items WHERE meeting_id = ? AND parent_id IS NULL AND kind = ? ORDER BY position LIMIT 1', [$meetingId, 'other']);
        if ($other === null) {
            $max = (int) db_val($pdo, 'SELECT COALESCE(MAX(position), 0) FROM grp_formal_items WHERE meeting_id = ? AND parent_id IS NULL', [$meetingId]);
            db_exec($pdo, 'INSERT INTO grp_formal_items (group_code, meeting_id, parent_id, position, heading, is_decision, kind) VALUES (?, ?, NULL, ?, ?, 0, ?)',
                [$groupCode, $meetingId, $max + 10, t('formal.other_business'), 'other']);
            $other = db_one($pdo, 'SELECT * FROM grp_formal_items WHERE id = ?', [(int) $pdo->lastInsertId()]);
        }

        if ($tabled) {
            $parent = db_one($pdo, 'SELECT * FROM grp_formal_items WHERE meeting_id = ? AND parent_id IS NULL AND kind = ? LIMIT 1', [$meetingId, 'tabled']);
            if ($parent === null) {
                db_exec($pdo, 'INSERT INTO grp_formal_items (group_code, meeting_id, parent_id, position, heading, is_decision, kind) VALUES (?, ?, NULL, ?, ?, 0, ?)',
                    [$groupCode, $meetingId, (int) $other['position'] - 5, t('formal.tabled_items'), 'tabled']);
                $parentId = (int) $pdo->lastInsertId();
            } else {
                $parentId = (int) $parent['id'];
            }
            $pos = (int) db_val($pdo, 'SELECT COALESCE(MAX(position), 0) FROM grp_formal_items WHERE parent_id = ?', [$parentId]);
            foreach ($tabled as $ti) {
                $pos += 10;
                $context = trim((string) ($ti['agenda_text'] ?? ''));
                $said = trim((string) ($ti['minutes_text'] ?? ''));
                $text = trim($context . ($said !== '' ? "\n\n" . t('formal.previously') . ': ' . $said : ''));
                db_exec($pdo, 'INSERT INTO grp_formal_items (group_code, meeting_id, parent_id, position, heading, agenda_text, is_decision, kind, carried_decision) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [$groupCode, $meetingId, $parentId, $pos, $ti['heading'], $text !== '' ? $text : null, 'carried', $ti['decision']]);
                db_exec($pdo, 'UPDATE grp_formal_items SET carried_to_item_id = ? WHERE id = ?', [(int) $pdo->lastInsertId(), (int) $ti['id']]);
            }
        }

        $pos = (int) db_val($pdo, 'SELECT COALESCE(MAX(position), 0) FROM grp_formal_items WHERE parent_id = ?', [(int) $other['id']]);
        foreach ($suggestions as $s) {
            $pos += 10;
            db_exec($pdo, 'INSERT INTO grp_formal_items (group_code, meeting_id, parent_id, position, heading, agenda_text, is_decision, kind) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
                [$groupCode, $meetingId, (int) $other['id'], $pos, t('formal.suggestion'), $s['text'], 'suggestion']);
            db_exec($pdo, 'UPDATE grp_suggestions SET item_id = ? WHERE id = ?', [(int) $pdo->lastInsertId(), (int) $s['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** After a change that may have left items unclaimed: give them to the next planned meeting. */
function formal_route_pending(string $groupCode, int $typeId): void
{
    $next = next_planned_meeting($groupCode, $typeId);
    if ($next !== null) {
        formal_attach_pending($groupCode, (int) $next['id']);
    }
}

function decision_items_missing(int $meetingId): array
{
    return db_all(db(), 'SELECT heading FROM grp_formal_items WHERE meeting_id = ? AND is_decision = 1 AND decision IS NULL', [$meetingId]);
}

/* ------------------------- automatic scheduling ------------------------- */

const FORMAL_SCHEDULE_DAYS = 60; // planned meetings are created this far ahead

function formal_schedule(string $groupCode, int $typeId): ?array
{
    return db_one(db(), 'SELECT * FROM grp_formal_schedules WHERE group_code = ? AND type_id = ?', [$groupCode, $typeId]);
}

/**
 * Create planned meetings from each type's schedule, up to 60 days ahead.
 * Each date is only generated once: a generated meeting that is removed
 * does not come back (unless the schedule itself is changed).
 */
function formal_run_schedules(string $groupCode): void
{
    $horizon = date('Y-m-d', strtotime('+' . FORMAL_SCHEDULE_DAYS . ' days'));
    $today = date('Y-m-d');
    foreach (db_all(db(), 'SELECT * FROM grp_formal_schedules WHERE group_code = ? AND (generated_until IS NULL OR generated_until < ?)', [$groupCode, $horizon]) as $s) {
        $from = $s['generated_until'] !== null ? date('Y-m-d', strtotime((string) $s['generated_until'] . ' +1 day')) : $today;
        if ($from < $today) {
            $from = $today;
        }
        foreach (series_dates($s, $from, $horizon) as $date) {
            $exists = db_val(db(), 'SELECT 1 FROM grp_formal_meetings WHERE group_code = ? AND type_id = ? AND meeting_date = ?', [$groupCode, (int) $s['type_id'], $date]);
            if ($exists === null) {
                create_formal_meeting($groupCode, (int) $s['type_id'], $date, $s['start_time'] !== null ? substr((string) $s['start_time'], 0, 5) : null, $s['place'], null);
            }
        }
        db_exec(db(), 'UPDATE grp_formal_schedules SET generated_until = ? WHERE id = ?', [$horizon, (int) $s['id']]);
    }
}
