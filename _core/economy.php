<?php
declare(strict_types=1);

/*
 * Economy: accounts (cash boxes), periods and transactions.
 *
 * - Amounts are stored in öre (integer) – never rounding errors.
 * - The balance can ONLY change through deposits and withdrawals.
 * - Transactions are never edited or deleted. A mistake is reversed with
 *   a correction entry that points to the original.
 * - Each account has periods. Closing the books ("bokslut") locks the
 *   period, stamps the closing balance and opens a new period with that
 *   balance as its opening balance.
 * - Voucher numbers (ver.nr) run per account and period: 1, 2, 3 …
 */

const TX_TYPES = ['deposit', 'withdrawal'];

final class EconomyError extends RuntimeException
{
}

/** "1 234,50" / "1234.5" / "250" -> öre, or null. */
function parse_money(string $s): ?int
{
    $s = str_replace([' ', "\u{00A0}", "\u{202F}", 'kr', 'SEK', ':-'], '', trim($s));
    if (!preg_match('/^(\d{1,9})(?:[.,](\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $ore = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    return $ore > 0 ? $ore : null;
}

function fmt_money(int $ore, bool $sign = false): string
{
    $neg = $ore < 0;
    $abs = abs($ore);
    $s = number_format(intdiv($abs, 100), 0, '', t('money.thou')) . t('money.dec') . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    return ($neg ? '−' : ($sign && $ore > 0 ? '+' : '')) . $s . "\u{00A0}" . t('money.currency');
}

/** First visit: create the profile's default accounts (once per group). */
function ensure_accounts_default(string $groupCode): void
{
    if (db_val(db(), 'SELECT 1 FROM grp_economy_setup WHERE group_code = ?', [$groupCode]) !== null) {
        return;
    }
    $names = $GLOBALS['gp_i18n']['text']['_accounts_default'] ?? [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec($pdo, 'INSERT INTO grp_economy_setup (group_code, created_at) VALUES (?, ?)', [$groupCode, now_db()]);
        foreach (is_array($names) ? $names : [] as $name) {
            create_account($groupCode, (string) $name);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_account(string $groupCode, string $name): int
{
    $pos = (int) db_val(db(), 'SELECT COALESCE(MAX(position), 0) FROM grp_accounts WHERE group_code = ?', [$groupCode]) + 10;
    db_exec(db(), 'INSERT INTO grp_accounts (group_code, name, position, created_at) VALUES (?, ?, ?, ?)', [$groupCode, $name, $pos, now_db()]);
    $id = (int) db()->lastInsertId();
    db_exec(db(), 'INSERT INTO grp_periods (group_code, account_id, start_date, opening_ore, opened_at) VALUES (?, ?, ?, 0, ?)', [$groupCode, $id, date('Y-m-d'), now_db()]);
    return $id;
}

function accounts(string $groupCode): array
{
    return db_all(db(), 'SELECT * FROM grp_accounts WHERE group_code = ? ORDER BY position, id', [$groupCode]);
}

function account(string $groupCode, int $id): ?array
{
    return db_one(db(), 'SELECT * FROM grp_accounts WHERE id = ? AND group_code = ?', [$id, $groupCode]);
}

function open_period(int $accountId): array
{
    $p = db_one(db(), 'SELECT * FROM grp_periods WHERE account_id = ? AND closed_at IS NULL ORDER BY id DESC LIMIT 1', [$accountId]);
    if ($p === null) {
        throw new EconomyError('no open period');
    }
    return $p;
}

function period_by_id(string $groupCode, int $id): ?array
{
    return db_one(db(), 'SELECT * FROM grp_periods WHERE id = ? AND group_code = ?', [$id, $groupCode]);
}

/** Opening balance + all deposits − all withdrawals in the period (optionally up to a date). */
function period_balance(array $period, ?string $upTo = null): int
{
    $sql = 'SELECT COALESCE(SUM(CASE WHEN type = ? THEN amount_ore ELSE -amount_ore END), 0) FROM grp_transactions WHERE period_id = ?';
    $params = ['deposit', (int) $period['id']];
    if ($upTo !== null) {
        $sql .= ' AND tx_date <= ?';
        $params[] = $upTo;
    }
    return (int) $period['opening_ore'] + (int) db_val(db(), $sql, $params);
}

function account_balance(int $accountId): int
{
    return period_balance(open_period($accountId));
}

function period_transactions(int $periodId): array
{
    return db_all(db(), 'SELECT t.*, u.username, u.display_name,
        (SELECT c.ver_no FROM grp_transactions c WHERE c.correction_of = t.id LIMIT 1) AS corrected_by_ver,
        (SELECT o.ver_no FROM grp_transactions o WHERE o.id = t.correction_of) AS corrects_ver,
        (SELECT a.name FROM grp_transactions x JOIN grp_accounts a ON a.id = x.account_id WHERE x.transfer_id = t.transfer_id AND x.id <> t.id LIMIT 1) AS transfer_other
        FROM grp_transactions t LEFT JOIN grp_users u ON u.id = t.created_by
        WHERE t.period_id = ? ORDER BY t.ver_no', [$periodId]);
}

/**
 * Book one transaction in the account's open period. Returns the new id.
 * Throws EconomyError with an i18n key on rule violations.
 */
function book_tx(string $groupCode, int $accountId, string $type, int $amount, string $date, string $comment, int $by, ?int $correctionOf = null, ?int $transferId = null, bool $checkBalance = true): int
{
    if (!in_array($type, TX_TYPES, true) || $amount <= 0 || $comment === '') {
        throw new EconomyError('eco.err_input');
    }
    $period = open_period($accountId);
    if (!valid_date($date) || $date < $period['start_date'] || $date > date('Y-m-d')) {
        throw new EconomyError('eco.err_date');
    }
    if ($type === 'withdrawal' && $checkBalance && period_balance($period) < $amount) {
        throw new EconomyError('eco.err_balance');
    }
    $ver = (int) db_val(db(), 'SELECT COALESCE(MAX(ver_no), 0) FROM grp_transactions WHERE period_id = ?', [(int) $period['id']]) + 1;
    db_exec(db(), 'INSERT INTO grp_transactions (group_code, account_id, period_id, ver_no, tx_date, type, amount_ore, comment, correction_of, transfer_id, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$groupCode, $accountId, (int) $period['id'], $ver, $date, $type, $amount, $comment, $correctionOf, $transferId, $by, now_db()]);
    return (int) db()->lastInsertId();
}

/** Move money between two accounts: a withdrawal and a deposit linked by transfer_id. */
function book_transfer(string $groupCode, int $fromId, int $toId, int $amount, string $date, string $comment, int $by): void
{
    if ($fromId === $toId) {
        throw new EconomyError('eco.err_same_account');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = book_tx($groupCode, $fromId, 'withdrawal', $amount, $date, $comment, $by);
        db_exec($pdo, 'UPDATE grp_transactions SET transfer_id = ? WHERE id = ?', [$out, $out]);
        book_tx($groupCode, $toId, 'deposit', $amount, $date, $comment, $by, null, $out);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reverse a transaction with a correction entry (both legs of a transfer).
 * The original stays visible and is marked as corrected.
 */
function correct_tx(string $groupCode, int $txId, string $reason, int $by): void
{
    $tx = db_one(db(), 'SELECT * FROM grp_transactions WHERE id = ? AND group_code = ?', [$txId, $groupCode]);
    if ($tx === null || $tx['correction_of'] !== null) {
        throw new EconomyError('eco.err_cannot_correct');
    }
    $legs = $tx['transfer_id'] !== null
        ? db_all(db(), 'SELECT * FROM grp_transactions WHERE transfer_id = ? AND group_code = ?', [(int) $tx['transfer_id'], $groupCode])
        : [$tx];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($legs as $leg) {
            if (db_val($pdo, 'SELECT 1 FROM grp_transactions WHERE correction_of = ?', [(int) $leg['id']]) !== null) {
                throw new EconomyError('eco.err_already_corrected');
            }
            $period = open_period((int) $leg['account_id']);
            if ((int) $leg['period_id'] !== (int) $period['id']) {
                throw new EconomyError('eco.err_closed_period');
            }
            book_tx($groupCode, (int) $leg['account_id'], $leg['type'] === 'deposit' ? 'withdrawal' : 'deposit', (int) $leg['amount_ore'], date('Y-m-d'),
                t('eco.correction_of', ['ver' => $leg['ver_no']]) . ': ' . $reason, $by, (int) $leg['id'], null, false);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Close the books on the account's closing date: lock the period, stamp the
 * closing balance and open a new period. Transactions already entered with a
 * date after the closing date move to the new period (renumbered 1, 2, 3 …).
 */
function close_books(string $groupCode, int $accountId, int $by): void
{
    $acc = account($groupCode, $accountId);
    if ($acc === null || $acc['close_date'] === null || (string) $acc['close_date'] >= date('Y-m-d')) {
        throw new EconomyError('eco.err_close_date');
    }
    $closeDate = (string) $acc['close_date'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $period = open_period($accountId);
        if ($closeDate < $period['start_date']) {
            throw new EconomyError('eco.err_close_date');
        }
        $closing = period_balance($period, $closeDate);
        $later = db_all($pdo, 'SELECT id FROM grp_transactions WHERE period_id = ? AND tx_date > ? ORDER BY tx_date, id', [(int) $period['id'], $closeDate]);

        db_exec($pdo, 'UPDATE grp_periods SET end_date = ?, closing_ore = ?, closed_at = ?, closed_by = ? WHERE id = ?', [$closeDate, $closing, now_db(), $by, (int) $period['id']]);
        db_exec($pdo, 'INSERT INTO grp_periods (group_code, account_id, start_date, opening_ore, opened_at) VALUES (?, ?, ?, ?, ?)',
            [$groupCode, $accountId, date('Y-m-d', strtotime($closeDate . ' +1 day')), $closing, now_db()]);
        $newPeriod = (int) $pdo->lastInsertId();
        foreach ($later as $i => $row) {
            db_exec($pdo, 'UPDATE grp_transactions SET period_id = ?, ver_no = ? WHERE id = ?', [$newPeriod, $i + 1, (int) $row['id']]);
        }
        db_exec($pdo, 'UPDATE grp_accounts SET close_date = NULL WHERE id = ?', [$accountId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
