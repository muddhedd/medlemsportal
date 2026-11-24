<?php
declare(strict_types=1);

/*
 * Regular meetings (calendar).
 *
 * A meeting is a SERIES (one-off or recurring). Single dates are calculated,
 * not stored. A row in grp_meeting_occ is only created when one date differs
 * from the series: cancelled, other time, note, other responsible person,
 * or someone has reported that they can't come ("förhinder").
 */

const RECUR_TYPES = ['none', 'daily', 'weekly', 'monthly_date', 'monthly_nth'];
const CALENDAR_DEFAULT_WEEKS = 8;
const CALENDAR_MAX_WEEKS = 52;

function valid_date(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

function valid_time(string $t): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

/** The n:th given weekday (ISO 1–7) of a month; $nth = -1 means the last one. */
function nth_weekday(int $year, int $month, int $weekday, int $nth): ?DateTimeImmutable
{
    if ($nth === -1) {
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $d = $d->modify('last day of this month');
        while ((int) $d->format('N') !== $weekday) {
            $d = $d->modify('-1 day');
        }
        return $d;
    }
    $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    while ((int) $d->format('N') !== $weekday) {
        $d = $d->modify('+1 day');
    }
    $d = $d->modify('+' . (($nth - 1) * 7) . ' days');
    return (int) $d->format('n') === $month ? $d : null;
}

/** All dates (Y-m-d) of a series between $from and $to, inclusive. */
function series_dates(array $s, string $from, string $to): array
{
    $start = new DateTimeImmutable((string) $s['start_date']);
    $fromD = new DateTimeImmutable($from);
    $toD = new DateTimeImmutable($to);
    if (!empty($s['until_date']) && $s['until_date'] < $to) {
        $toD = new DateTimeImmutable((string) $s['until_date']);
    }
    if ($fromD < $start) {
        $fromD = $start;
    }
    if ($toD < $fromD) {
        return [];
    }
    $n = max(1, (int) $s['interval_n']);
    $out = [];

    switch ($s['recur']) {
        case 'none':
            if ($start >= $fromD && $start <= $toD) {
                $out[] = $start->format('Y-m-d');
            }
            break;

        case 'daily':
        case 'weekly':
            $step = $s['recur'] === 'daily' ? $n : 7 * $n;
            $diff = (int) $start->diff($fromD)->days;
            $d = $start->modify('+' . (intdiv($diff + $step - 1, $step) * $step) . ' days');
            for ($i = 0; $d <= $toD && $i < 400; $i++, $d = $d->modify('+' . $step . ' days')) {
                $out[] = $d->format('Y-m-d');
            }
            break;

        case 'monthly_date':
        case 'monthly_nth':
            $day = (int) $start->format('j');
            $weekday = (int) $start->format('N');
            $nth = (int) ($s['nth'] ?? 1);
            $y = (int) $start->format('Y');
            $m = (int) $start->format('n');
            for ($i = 0; $i < 600; $i++) {
                if ($s['recur'] === 'monthly_date') {
                    $d = checkdate($m, $day, $y) ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day)) : null;
                } else {
                    $d = nth_weekday($y, $m, $weekday, $nth);
                }
                if ($d !== null) {
                    if ($d > $toD) {
                        break;
                    }
                    if ($d >= $fromD) {
                        $out[] = $d->format('Y-m-d');
                    }
                } elseif (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)) > $toD) {
                    break;
                }
                $m += $n;
                while ($m > 12) {
                    $m -= 12;
                    $y++;
                }
            }
            break;
    }
    return $out;
}

function series_has_date(array $s, string $date): bool
{
    return valid_date($date) && series_dates($s, $date, $date) === [$date];
}

function series_by_id(string $groupCode, int $id): ?array
{
    return db_one(db(), 'SELECT * FROM grp_meeting_series WHERE id = ? AND group_code = ?', [$id, $groupCode]);
}

/** user id => label for active members of a group. */
function member_labels(string $groupCode): array
{
    $out = [];
    foreach (db_all(db(), 'SELECT u.id, u.username, u.display_name FROM grp_memberships m JOIN grp_users u ON u.id = m.user_id
        WHERE m.group_code = ? AND m.status = ? ORDER BY COALESCE(NULLIF(u.display_name, \'\'), u.username)', [$groupCode, 'active']) as $u) {
        $d = trim((string) $u['display_name']);
        $out[(int) $u['id']] = $d !== '' ? $d : (string) $u['username'];
    }
    return $out;
}

/**
 * Upcoming dates of all series in [from, to], merged with their changes.
 * Each item: series_id, date, title, description, start, end, is_open,
 * cancelled, note, resp_id, resp_label, unavailable, state
 * (state: ok | empty | unavailable | cancelled).
 */
function meeting_occurrences(string $groupCode, string $from, string $to, ?int $limit = null, ?int $onlySeries = null): array
{
    $params = [$groupCode, $to, $from];
    $sql = 'SELECT * FROM grp_meeting_series WHERE group_code = ? AND start_date <= ? AND (until_date IS NULL OR until_date >= ?)';
    if ($onlySeries !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlySeries;
    }
    $series = db_all(db(), $sql, $params);
    if (!$series) {
        return [];
    }

    $changes = [];
    foreach (db_all(db(), 'SELECT * FROM grp_meeting_occ WHERE group_code = ? AND occ_date BETWEEN ? AND ?', [$groupCode, $from, $to]) as $c) {
        $changes[(int) $c['series_id']][(string) $c['occ_date']] = $c;
    }
    $labels = member_labels($groupCode);

    $out = [];
    foreach ($series as $s) {
        $sid = (int) $s['id'];
        foreach (series_dates($s, $from, $to) as $date) {
            $c = $changes[$sid][$date] ?? null;
            $mode = $c['resp_mode'] ?? 'default';
            $resp = match ($mode) {
                'user' => $c['resp_user_id'] !== null ? (int) $c['resp_user_id'] : null,
                'none' => null,
                default => $s['default_user_id'] !== null ? (int) $s['default_user_id'] : null,
            };
            if ($resp !== null && !isset($labels[$resp])) {
                $resp = null; // no longer a member
            }
            $cancelled = $c !== null && (int) $c['cancelled'] === 1;
            $unavailable = $resp !== null && $c !== null && (int) $c['unavailable'] === 1;
            $out[] = [
                'series_id'   => $sid,
                'date'        => $date,
                'title'       => (string) $s['title'],
                'description' => (string) ($s['description'] ?? ''),
                'start'       => substr((string) ($c['start_time'] ?? $s['start_time']), 0, 5),
                'end'         => ($c['end_time'] ?? $s['end_time']) !== null ? substr((string) ($c['end_time'] ?? $s['end_time']), 0, 5) : '',
                'is_open'     => (int) ($c['is_open'] ?? $s['is_open']) === 1,
                'open_changed'=> $c !== null && $c['is_open'] !== null && (int) $c['is_open'] !== (int) $s['is_open'],
                'time_changed'=> $c !== null && ($c['start_time'] !== null || $c['end_time'] !== null),
                'cancelled'   => $cancelled,
                'note'        => (string) ($c['note'] ?? ''),
                'resp_mode'   => $mode,
                'resp_id'     => $resp,
                'resp_label'  => $resp !== null ? $labels[$resp] : '',
                'default_id'  => $s['default_user_id'] !== null && isset($labels[(int) $s['default_user_id']]) ? (int) $s['default_user_id'] : null,
                'unavailable' => $unavailable,
                'state'       => $cancelled ? 'cancelled' : ($resp === null ? 'empty' : ($unavailable ? 'unavailable' : 'ok')),
            ];
        }
    }
    usort($out, fn ($a, $b) => [$a['date'], $a['start'], $a['title']] <=> [$b['date'], $b['start'], $b['title']]);
    return $limit !== null ? array_slice($out, 0, $limit) : $out;
}

/** Create or update the change-row for one date of a series. */
function occ_save(string $groupCode, int $seriesId, string $date, array $fields, int $by): void
{
    $fields['updated_at'] = now_db();
    $fields['updated_by'] = $by;
    $id = db_val(db(), 'SELECT id FROM grp_meeting_occ WHERE series_id = ? AND occ_date = ?', [$seriesId, $date]);
    if ($id === null) {
        $cols = array_merge(['group_code', 'series_id', 'occ_date'], array_keys($fields));
        db_exec(db(), 'INSERT INTO grp_meeting_occ (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_merge([$groupCode, $seriesId, $date], array_values($fields)));
    } else {
        $set = implode(', ', array_map(fn ($k) => $k . ' = ?', array_keys($fields)));
        db_exec(db(), 'UPDATE grp_meeting_occ SET ' . $set . ' WHERE id = ?', array_merge(array_values($fields), [(int) $id]));
    }
}

/** "ons 3 dec" / "idag" / "imorgon". */
function nice_date(string $date, bool $relative = true): string
{
    $d = new DateTimeImmutable($date);
    if ($relative) {
        $today = date('Y-m-d');
        if ($date === $today) {
            return t('date.today');
        }
        if ($date === date('Y-m-d', strtotime('+1 day'))) {
            return t('date.tomorrow');
        }
    }
    return t('wd.short.' . $d->format('N')) . ' ' . $d->format('j') . ' ' . t('mon.short.' . $d->format('n'));
}

/** Human description of a series' schedule, e.g. "Varje onsdag 19:00–20:30". */
function series_summary(array $s): string
{
    $start = new DateTimeImmutable((string) $s['start_date']);
    $n = max(1, (int) $s['interval_n']);
    $wd = t('wd.long.' . $start->format('N'));
    $text = match ($s['recur']) {
        'none'         => nice_date((string) $s['start_date'], false) . ' ' . $start->format('Y'),
        'daily'        => $n === 1 ? t('recur.sum.daily') : t('recur.sum.daily_n', ['n' => $n]),
        'weekly'       => $n === 1 ? t('recur.sum.weekly', ['wd' => $wd]) : t('recur.sum.weekly_n', ['n' => $n, 'wd' => $wd]),
        'monthly_date' => t('recur.sum.monthly_date', ['day' => $start->format('j')]) . ($n > 1 ? ' ' . t('recur.sum.every_n_months', ['n' => $n]) : ''),
        'monthly_nth'  => t('recur.sum.monthly_nth', ['nth' => t('nth.' . (int) $s['nth']), 'wd' => $wd]) . ($n > 1 ? ' ' . t('recur.sum.every_n_months', ['n' => $n]) : ''),
        default        => '',
    };
    $time = substr((string) $s['start_time'], 0, 5) . ($s['end_time'] !== null ? '–' . substr((string) $s['end_time'], 0, 5) : '');
    return trim($text . ' ' . $time);
}
