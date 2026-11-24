<?php
declare(strict_types=1);

/*
 * Group app pages for the economy. Included by group-app/index.php.
 * Everyone can read (transparency). economy.edit books; economy.close closes the books.
 */

ensure_accounts_default($code);

function tx_rows(array $txs, int $opening, bool $canCorrect, int $accountId): string
{
    $h = '';
    $bal = $opening;
    foreach ($txs as $tx) {
        $amt = (int) $tx['amount_ore'] * ($tx['type'] === 'deposit' ? 1 : -1);
        $bal += $amt;
        $tags = '';
        if ($tx['corrects_ver'] !== null) {
            $tags .= ' <span class="tag tag-changed">' . e(t('eco.tag_correction', ['ver' => $tx['corrects_ver']])) . '</span>';
        }
        if ($tx['corrected_by_ver'] !== null) {
            $tags .= ' <span class="tag tag-cancelled">' . e(t('eco.tag_corrected', ['ver' => $tx['corrected_by_ver']])) . '</span>';
        }
        if ($tx['transfer_other'] !== null) {
            $tags .= ' <span class="tag">' . e(t($tx['type'] === 'deposit' ? 'eco.tag_from' : 'eco.tag_to', ['account' => $tx['transfer_other']])) . '</span>';
        }
        $who = $tx['username'] !== null ? user_label($tx) : '–';
        $action = '';
        if ($canCorrect && $tx['correction_of'] === null && $tx['corrected_by_ver'] === null) {
            $action = '<details class="inline-details"><summary>' . e(t('eco.correct')) . '</summary><form method="post" action="' . e(u('account', ['id' => $accountId])) . '" class="stack" data-confirm="' . e(t('eco.correct_confirm')) . '">' . csrf_field()
                . '<input type="hidden" name="do" value="correct"><input type="hidden" name="tx_id" value="' . (int) $tx['id'] . '">'
                . '<input name="reason" maxlength="200" required placeholder="' . e(t('eco.correct_reason')) . '" aria-label="' . e(t('eco.correct_reason')) . '">'
                . '<button class="btn btn-small" type="submit">' . e(t('eco.correct_btn')) . '</button></form></details>';
        }
        $h .= '<tr' . ($tx['corrected_by_ver'] !== null ? ' class="corrected"' : '') . '>'
            . '<td class="nowrap">' . (int) $tx['ver_no'] . '</td>'
            . '<td class="nowrap">' . e((string) $tx['tx_date']) . '</td>'
            . '<td>' . e($tx['comment']) . $tags . '<div class="muted small">' . e($who) . ' · ' . e(substr((string) $tx['created_at'], 0, 16)) . '</div>' . $action . '</td>'
            . '<td class="num ' . ($amt < 0 ? 'neg' : 'pos') . '">' . e(fmt_money($amt, true)) . '</td>'
            . '<td class="num">' . e(fmt_money($bal)) . '</td></tr>';
    }
    return $h;
}

function tx_table(string $rows, int $opening): string
{
    return '<div class="table-wrap"><table class="table ledger"><thead><tr><th>' . e(t('eco.ver')) . '</th><th>' . e(t('eco.date')) . '</th><th>' . e(t('eco.comment')) . '</th><th class="num">' . e(t('eco.amount')) . '</th><th class="num">' . e(t('eco.balance')) . '</th></tr></thead><tbody>'
        . '<tr class="opening"><td></td><td></td><td>' . e(t('eco.opening')) . '</td><td></td><td class="num">' . e(fmt_money($opening)) . '</td></tr>'
        . $rows . '</tbody></table></div>';
}

/* ============================== overview ============================== */

if ($p === 'economy') {
    if ($isPost) {
        can('economy.edit') || deny();
        $do = post_str('do');
        if ($do === 'create') {
            $name = clean_line(post_str('name'), 60);
            if ($name !== '') {
                create_account($code, $name);
                group_log($code, $myId, 'eco.account_created', $name);
                flash('ok', t('eco.account_created'));
            }
        } elseif ($do === 'rename' && ($acc = account($code, (int) post_str('id'))) !== null) {
            $name = clean_line(post_str('name'), 60);
            if ($name !== '') {
                db_exec(db(), 'UPDATE grp_accounts SET name = ? WHERE id = ?', [$name, (int) $acc['id']]);
                group_log($code, $myId, 'eco.account_renamed', $acc['name'] . ' → ' . $name);
            }
        } elseif ($do === 'delete' && ($acc = account($code, (int) post_str('id'))) !== null) {
            if (db_val(db(), 'SELECT 1 FROM grp_transactions WHERE account_id = ? LIMIT 1', [(int) $acc['id']]) !== null) {
                flash('error', t('eco.err_has_tx'));
            } else {
                db_exec(db(), 'DELETE FROM grp_accounts WHERE id = ?', [(int) $acc['id']]);
                group_log($code, $myId, 'eco.account_deleted', (string) $acc['name']);
                flash('ok', t('eco.account_deleted'));
            }
        }
        redirect(u('economy'));
    }

    $cards = '';
    $total = 0;
    foreach (accounts($code) as $acc) {
        $bal = account_balance((int) $acc['id']);
        $total += $bal;
        $close = $acc['close_date'] !== null
            ? ((string) $acc['close_date'] < date('Y-m-d') ? '<span class="tag tag-changed">' . e(t('eco.close_due')) . '</span>' : '<span class="muted small">' . e(t('eco.close_on', ['date' => $acc['close_date']])) . '</span>')
            : '';
        $cards .= '<a class="account-card" href="' . e(u('account', ['id' => $acc['id']])) . '"><span class="account-name">' . e($acc['name']) . '</span><span class="account-balance">' . e(fmt_money($bal)) . '</span>' . $close . '</a>';
    }

    $manage = '';
    if (can('economy.edit')) {
        $rows = '';
        foreach (accounts($code) as $acc) {
            $rows .= '<li><form method="post" action="' . e(u('economy')) . '" class="row">' . csrf_field() . '<input type="hidden" name="do" value="rename"><input type="hidden" name="id" value="' . (int) $acc['id'] . '">'
                . '<input name="name" value="' . e($acc['name']) . '" maxlength="60" required aria-label="' . e(t('eco.account_name')) . '"><button class="btn btn-ghost btn-small" type="submit">' . e(t('eco.rename')) . '</button></form>'
                . post_button(u('economy'), t('eco.delete_account'), ['do' => 'delete', 'id' => $acc['id']], t('eco.delete_account_confirm'), 'btn btn-ghost btn-small') . '</li>';
        }
        $manage = '<section class="card"><details><summary>' . e(t('eco.manage_accounts')) . '</summary><ul class="list">' . $rows . '</ul>
  <form method="post" action="' . e(u('economy')) . '" class="row">' . csrf_field() . '<input type="hidden" name="do" value="create">
    <input name="name" maxlength="60" required placeholder="' . e(t('eco.new_account')) . '" aria-label="' . e(t('eco.new_account')) . '"><button class="btn btn-small" type="submit">' . e(t('eco.create_account')) . '</button></form>
  <p class="muted small">' . e(t('eco.delete_hint')) . '</p></details></section>';
    }

    page(t('nav.economy'), '<section class="card">
  <h1>' . e(t('nav.economy')) . '</h1>
  <div class="accounts">' . ($cards ?: '<p class="muted">' . e(t('eco.no_accounts')) . '</p>') . '</div>
  <p class="total">' . e(t('eco.total')) . ': <strong>' . e(fmt_money($total)) . '</strong></p>
  <p class="muted small">' . e(t('eco.intro')) . '</p>
</section>' . $manage, 'economy');
}

/* ============================== one account ============================== */

if ($p === 'account') {
    $acc = account($code, (int) (get_str('id') ?: post_str('id')));
    if ($acc === null) {
        redirect(u('economy'));
    }
    $aid = (int) $acc['id'];
    $self = u('account', ['id' => $aid]);

    if ($isPost) {
        $do = post_str('do');
        try {
            if ($do === 'tx' || $do === 'transfer') {
                can('economy.edit') || deny();
                $amount = parse_money(post_str('amount'));
                $comment = clean_line(post_str('comment'), 300);
                $date = post_str('date') ?: date('Y-m-d');
                if ($amount === null) {
                    throw new EconomyError('eco.err_amount');
                }
                if ($comment === '') {
                    throw new EconomyError('eco.err_comment');
                }
                if ($do === 'tx') {
                    $type = post_str('type') === 'withdrawal' ? 'withdrawal' : 'deposit';
                    book_tx($code, $aid, $type, $amount, $date, $comment, $myId);
                    group_log($code, $myId, 'eco.' . $type, $acc['name'] . ' ' . fmt_money($amount));
                    flash('ok', t('eco.booked'));
                } else {
                    $to = account($code, (int) post_str('to'));
                    if ($to === null) {
                        throw new EconomyError('eco.err_input');
                    }
                    book_transfer($code, $aid, (int) $to['id'], $amount, $date, $comment, $myId);
                    group_log($code, $myId, 'eco.transfer', $acc['name'] . ' → ' . $to['name'] . ' ' . fmt_money($amount));
                    flash('ok', t('eco.transferred'));
                }
            } elseif ($do === 'correct') {
                can('economy.edit') || deny();
                $reason = clean_line(post_str('reason'), 200);
                if ($reason === '') {
                    throw new EconomyError('eco.err_comment');
                }
                correct_tx($code, (int) post_str('tx_id'), $reason, $myId);
                group_log($code, $myId, 'eco.correction', $acc['name']);
                flash('ok', t('eco.corrected'));
            } elseif ($do === 'close_date') {
                can('economy.close') || deny();
                $d = post_str('close_date');
                $period = open_period($aid);
                if ($d !== '' && (!valid_date($d) || $d < $period['start_date'])) {
                    throw new EconomyError('eco.err_close_date');
                }
                db_exec(db(), 'UPDATE grp_accounts SET close_date = ? WHERE id = ?', [$d !== '' ? $d : null, $aid]);
                group_log($code, $myId, 'eco.close_date', $acc['name'] . ' ' . ($d ?: '–'));
                flash('ok', t('eco.close_date_saved'));
            } elseif ($do === 'close') {
                can('economy.close') || deny();
                close_books($code, $aid, $myId);
                group_log($code, $myId, 'eco.closed', $acc['name'] . ' ' . $acc['close_date']);
                flash('ok', t('eco.closed'));
            }
        } catch (EconomyError $e) {
            flash('error', t($e->getMessage()));
        }
        redirect($self);
    }

    $period = open_period($aid);
    $txs = period_transactions((int) $period['id']);
    $balance = period_balance($period);
    $others = array_filter(accounts($code), fn ($a) => (int) $a['id'] !== $aid);
    $today = date('Y-m-d');

    $forms = '';
    if (can('economy.edit')) {
        $forms .= '<section class="card"><h2>' . e(t('eco.new_tx')) . '</h2>
  <form method="post" action="' . e($self) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="tx">
    <div class="row">
      <label class="checkbox-row"><input type="radio" name="type" value="deposit" checked> ' . e(t('eco.deposit')) . '</label>
      <label class="checkbox-row"><input type="radio" name="type" value="withdrawal"> ' . e(t('eco.withdrawal')) . '</label>
    </div>
    <div class="grid2"><div class="stack"><label for="amount">' . e(t('eco.amount')) . '</label><input id="amount" name="amount" inputmode="decimal" required placeholder="0,00" autocomplete="off"></div>
    <div class="stack"><label for="date">' . e(t('eco.date')) . '</label><input id="date" name="date" type="date" value="' . e($today) . '" min="' . e((string) $period['start_date']) . '" max="' . e($today) . '"></div></div>
    <label for="comment">' . e(t('eco.comment')) . '</label><input id="comment" name="comment" maxlength="300" required placeholder="' . e(t('eco.comment_ph')) . '">
    <p class="muted small">' . e(t('eco.ver_hint', ['ver' => (int) db_val(db(), 'SELECT COALESCE(MAX(ver_no), 0) FROM grp_transactions WHERE period_id = ?', [(int) $period['id']]) + 1])) . '</p>
    <button class="btn" type="submit">' . e(t('eco.book')) . '</button>
  </form></section>';
        if ($others) {
            $opts = '';
            foreach ($others as $o) {
                $opts .= '<option value="' . (int) $o['id'] . '">' . e($o['name']) . '</option>';
            }
            $forms .= '<section class="card"><details><summary>' . e(t('eco.transfer')) . '</summary>
  <form method="post" action="' . e($self) . '" class="stack">' . csrf_field() . '<input type="hidden" name="do" value="transfer">
    <label for="to">' . e(t('eco.transfer_to', ['from' => $acc['name']])) . '</label><select id="to" name="to">' . $opts . '</select>
    <div class="grid2"><div class="stack"><label for="tamount">' . e(t('eco.amount')) . '</label><input id="tamount" name="amount" inputmode="decimal" required placeholder="0,00" autocomplete="off"></div>
    <div class="stack"><label for="tdate">' . e(t('eco.date')) . '</label><input id="tdate" name="date" type="date" value="' . e($today) . '" max="' . e($today) . '"></div></div>
    <label for="tcomment">' . e(t('eco.comment')) . '</label><input id="tcomment" name="comment" maxlength="300" required>
    <button class="btn" type="submit">' . e(t('eco.transfer_btn')) . '</button>
  </form></details></section>';
        }
    }

    $closeHtml = '<p>' . ($acc['close_date'] !== null ? e(t('eco.close_on', ['date' => $acc['close_date']])) : e(t('eco.no_close_date'))) . '</p>';
    if (can('economy.close')) {
        $closeHtml .= '<form method="post" action="' . e($self) . '" class="row">' . csrf_field() . '<input type="hidden" name="do" value="close_date">
    <input name="close_date" type="date" value="' . e((string) ($acc['close_date'] ?? '')) . '" min="' . e((string) $period['start_date']) . '" aria-label="' . e(t('eco.close_date')) . '">
    <button class="btn btn-ghost btn-small" type="submit">' . e(t('eco.set_close_date')) . '</button></form>';
        if ($acc['close_date'] !== null && (string) $acc['close_date'] < $today) {
            $closeHtml .= '<div class="notice">' . e(t('eco.close_ready', ['date' => $acc['close_date'], 'balance' => fmt_money(period_balance($period, (string) $acc['close_date']))])) . '</div>'
                . post_button($self, t('eco.close_btn'), ['do' => 'close'], t('eco.close_confirm'), 'btn');
        }
    } elseif ($acc['close_date'] !== null && (string) $acc['close_date'] < $today) {
        $closeHtml .= '<p class="muted small">' . e(t('eco.close_waiting')) . '</p>';
    }

    $periods = db_all(db(), 'SELECT * FROM grp_periods WHERE account_id = ? AND closed_at IS NOT NULL ORDER BY id DESC', [$aid]);
    $plist = '';
    foreach ($periods as $pp) {
        $plist .= '<li><a href="' . e(u('period', ['id' => $pp['id']])) . '">' . e($pp['start_date'] . ' – ' . $pp['end_date']) . '</a> <span class="muted small">' . e(fmt_money((int) $pp['opening_ore'])) . ' → <strong>' . e(fmt_money((int) $pp['closing_ore'])) . '</strong></span></li>';
    }

    page($acc['name'], '<section class="card">
  <p class="muted small"><a href="' . e(u('economy')) . '">← ' . e(t('nav.economy')) . '</a></p>
  <div class="row between"><h1>' . e($acc['name']) . '</h1><span class="big-balance">' . e(fmt_money($balance)) . '</span></div>
  <p class="muted small">' . e(t('eco.period_since', ['date' => $period['start_date']])) . ' · <a href="' . e(u('period', ['id' => $period['id']])) . '">' . e(t('eco.print')) . '</a></p>
</section>' . $forms . '
<section class="card"><h2>' . e(t('eco.transactions')) . '</h2>' . ($txs ? tx_table(tx_rows($txs, (int) $period['opening_ore'], can('economy.edit'), $aid), (int) $period['opening_ore']) : '<p class="muted">' . e(t('eco.no_tx')) . '</p>') . '</section>
<section class="card"><h2>' . e(t('eco.closing_title')) . '</h2>' . $closeHtml . '</section>
<section class="card"><h2>' . e(t('eco.periods')) . '</h2>' . ($plist !== '' ? '<ul class="list">' . $plist . '</ul>' : '<p class="muted">' . e(t('eco.no_periods')) . '</p>') . '</section>', 'economy');
}

/* ======================= one period (print / PDF) ======================= */

if ($p === 'period') {
    $period = period_by_id($code, (int) get_str('id'));
    $acc = $period !== null ? account($code, (int) $period['account_id']) : null;
    if ($period === null || $acc === null) {
        redirect(u('economy'));
    }
    $txs = period_transactions((int) $period['id']);
    $closed = $period['closed_at'] !== null;
    $deposits = 0;
    $withdrawals = 0;
    foreach ($txs as $tx) {
        if ($closed && (string) $tx['tx_date'] > (string) $period['end_date']) {
            continue;
        }
        $tx['type'] === 'deposit' ? $deposits += (int) $tx['amount_ore'] : $withdrawals += (int) $tx['amount_ore'];
    }
    $end = $closed ? (int) $period['closing_ore'] : period_balance($period);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="' . e(current_lang()) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>' . e($acc['name'] . ' ' . $period['start_date']) . '</title><link rel="stylesheet" href="' . e(asset('app.css')) . '"></head><body class="print-page"><main class="wrap">'
        . '<div class="no-print row"><button type="button" class="btn" data-print>' . e(t('formal.print_btn')) . '</button><a class="btn btn-ghost" href="' . e(u('account', ['id' => $acc['id']])) . '">' . e(t('adm.back')) . '</a></div>'
        . '<article class="protocol">' . (!$closed ? '<p class="draft-mark">' . e(t('eco.open_period')) . '</p>' : '')
        . '<header class="protocol-head"><div class="protocol-group">' . e($group['name']) . '</div><h1>' . e(t('eco.report_title', ['account' => $acc['name']])) . '</h1>'
        . '<dl class="facts"><dt>' . e(t('eco.period')) . '</dt><dd>' . e($period['start_date'] . ' – ' . ($period['end_date'] ?? '')) . '</dd>'
        . '<dt>' . e(t('eco.opening')) . '</dt><dd>' . e(fmt_money((int) $period['opening_ore'])) . '</dd>'
        . '<dt>' . e(t('eco.deposits')) . '</dt><dd>' . e(fmt_money($deposits)) . '</dd>'
        . '<dt>' . e(t('eco.withdrawals')) . '</dt><dd>' . e(fmt_money(-$withdrawals)) . '</dd>'
        . '<dt>' . e($closed ? t('eco.closing') : t('eco.balance_now')) . '</dt><dd><strong>' . e(fmt_money($end)) . '</strong></dd>'
        . ($closed ? '<dt>' . e(t('eco.closed_at')) . '</dt><dd>' . e(substr((string) $period['closed_at'], 0, 16)) . '</dd>' : '') . '</dl></header>'
        . ($txs ? tx_table(tx_rows($txs, (int) $period['opening_ore'], false, (int) $acc['id']), (int) $period['opening_ore']) : '<p class="muted">' . e(t('eco.no_tx')) . '</p>')
        . '<footer class="muted small">' . e(t('formal.printed', ['date' => date('Y-m-d H:i')])) . '</footer></article></main><script src="' . e(asset('app.js')) . '" defer></script></body></html>';
    exit;
}
