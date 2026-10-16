<?php
declare(strict_types=1);

use Random\RandomException;

session_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
/** @var mysqli $conn */
global $conn;

/* -------------------------------------------------------------
 * Payment methods shown in the bottom bar.
 * 'aliases' = the values that may be stored in sales.payment_method (lower-case).
 * 'img'     = optional logo file. Drop a PNG/SVG/WebP at that path and it is used
 *             instead of the built-in badge.
 * ----------------------------------------------------------- */
$methods = [
    'momo'  => ['label' => 'MoMo',   'img' => 'icons/momo.png',
        'aliases' => ['momo', 'mtn momo', 'mtn_momo', 'mtn-momo', 'mobile money', 'mobile_money', 'mobilemoney', 'mtn']],
    'cash'  => ['label' => 'Cash',   'img' => 'icons/cash.png',
        'aliases' => ['cash']],
    'tcash' => ['label' => 'T-Cash', 'img' => 'icons/tcash.png',
        'aliases' => ['tcash', 't-cash', 't cash', 't_cash', 'airteltigo', 'airteltigo money']],
    'visa'  => ['label' => 'Visa',   'img' => 'icons/visa.png',
        'aliases' => ['visa', 'card']],
];

/* -------------------------------------------------------------
 * Helpers
 * ----------------------------------------------------------- */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return 'GH₵ ' . number_format($amount, 2);
}

function valid_date(?string $value, string $fallback): string
{
    if ($value === null) {
        return $fallback;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : $fallback;
}

function table_columns(mysqli $conn, string $table): array
{
    $cols = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM `$table`");
    } catch (mysqli_sql_exception) {
        return []; // table does not exist
    }
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cols[strtolower($r['Field'])] = $r['Field'];
        }
    }
    return $cols;
}

function first_match(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (isset($cols[$c])) {
            return $cols[$c];
        }
    }
    return null;
}

/** Works out where revenue lives: a column on sales, or line items in sale_items. */
function detect_revenue_source(mysqli $conn): ?array
{
    $sales = table_columns($conn, 'sales');
    // Discounts: the net amount lives in sale_items (after discount), so prefer the line items
    // over any sales.total_amount column, which may hold the pre-discount figure.
    $direct = isset($sales['discount_amount'])
        ? null
        : first_match($sales, ['total_amount', 'total', 'amount', 'grand_total']);
    if ($direct !== null) {
        return ['join' => '', 'expr' => "s.`$direct`"];
    }

    $items = table_columns($conn, 'sale_items');
    $fk = first_match($items, ['sale_id', 'sales_id']);
    if ($fk === null) {
        return null;
    }
    $line_total = first_match($items, ['line_total', 'total', 'total_amount', 'subtotal', 'amount']);
    $price = first_match($items, ['unit_price', 'price', 'selling_price', 'sale_price']);
    $qty = first_match($items, ['quantity', 'qty']);

    if ($line_total !== null) {
        $expr = "si.`$line_total`";
    } elseif ($price !== null) {
        $expr = $qty !== null ? "si.`$qty` * si.`$price`" : "si.`$price`";
    } else {
        return null;
    }
    return ['join' => "LEFT JOIN sale_items si ON si.`$fk` = s.id", 'expr' => $expr];
}

/** WHERE clause for a half-open date range [from, to) and an optional payment-method filter. */
function sales_where(string $from, string $to, array $aliases): array
{
    $sql = 's.created_at >= ? AND s.created_at < ?';
    $types = 'ss';
    $params = [$from, $to];
    if ($aliases) {
        $sql .= ' AND LOWER(TRIM(s.payment_method)) IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
        $types .= str_repeat('s', count($aliases));
        array_push($params, ...$aliases);
    }
    return [$sql, $types, $params];
}

function fetch_totals(mysqli $conn, ?array $src, string $from, string $to, array $aliases = []): array
{
    $count = 0;
    $sum = 0.0;
    if ($src === null) {
        return ['count' => 0, 'sum' => 0.0];
    }
    [$where, $types, $params] = sales_where($from, $to, $aliases);
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT s.id), COALESCE(SUM({$src['expr']}), 0)
         FROM sales s {$src['join']} WHERE $where"
    );
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($count, $sum);
        $stmt->fetch();
        $stmt->close();
    }
    return ['count' => (int)$count, 'sum' => (float)$sum];
}

function fetch_daily(mysqli $conn, ?array $src, string $from, string $to, array $aliases = []): array
{
    $rows = [];
    if ($src === null) {
        return $rows;
    }
    [$where, $types, $params] = sales_where($from, $to, $aliases);
    $stmt = $conn->prepare(
        "SELECT DATE(s.created_at) AS d, COUNT(DISTINCT s.id), COALESCE(SUM({$src['expr']}), 0)
         FROM sales s {$src['join']} WHERE $where
         GROUP BY d ORDER BY d DESC"
    );
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($day, $count, $sum);
        while ($stmt->fetch()) {
            $rows[] = ['day' => $day, 'count' => (int)$count, 'sum' => (float)$sum];
        }
        $stmt->close();
    }
    return $rows;
}

/** Totals per payment method for the range (ignores the method filter so every button shows its own figure). */
function fetch_by_method(mysqli $conn, ?array $src, string $from, string $to, array $methods): array
{
    $out = ['all' => ['count' => 0, 'sum' => 0.0]];
    foreach ($methods as $key => $_) {
        $out[$key] = ['count' => 0, 'sum' => 0.0];
    }
    if ($src === null) {
        return $out;
    }
    $stmt = $conn->prepare(
        "SELECT LOWER(TRIM(s.payment_method)), COUNT(DISTINCT s.id), COALESCE(SUM({$src['expr']}), 0)
         FROM sales s {$src['join']}
         WHERE s.created_at >= ? AND s.created_at < ?
         GROUP BY 1"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $stmt->bind_result($pm, $count, $sum);
        while ($stmt->fetch()) {
            $out['all']['count'] += (int)$count;
            $out['all']['sum'] += (float)$sum;
            foreach ($methods as $key => $m) {
                if (in_array((string)$pm, $m['aliases'], true)) {
                    $out[$key]['count'] += (int)$count;
                    $out[$key]['sum'] += (float)$sum;
                    break;
                }
            }
        }
        $stmt->close();
    }
    return $out;
}

/**
 * Works out how to list items sold: which sale_items columns hold the item, quantity and amount,
 * and which table holds the item names. Returns null if it can't tell.
 */
function detect_item_source(mysqli $conn): ?array
{
    $items = table_columns($conn, 'sale_items');
    $sale_fk = first_match($items, ['sale_id', 'sales_id']);
    if ($sale_fk === null) {
        return null;
    }
    $item_fk = first_match($items, ['product_id', 'item_id', 'productid', 'itemid', 'inventory_id', 'stock_id']);
    $line_total = first_match($items, ['line_total', 'total', 'total_amount', 'subtotal', 'amount']);
    $price = first_match($items, ['unit_price', 'price', 'selling_price', 'sale_price']);
    $qty = first_match($items, ['quantity', 'qty']);

    if ($line_total !== null) {
        $amount = "si.`$line_total`";
    } elseif ($price !== null) {
        $amount = $qty !== null ? "si.`$qty` * si.`$price`" : "si.`$price`";
    } else {
        return null;
    }
    $qty_expr = $qty !== null ? "si.`$qty`" : '1';
    $join = "JOIN sale_items si ON si.`$sale_fk` = s.id";
    $label = null;
    $group = null;

    $name_in_items = first_match($items, ['product_name', 'item_name', 'name']);
    if ($name_in_items !== null) {
        $label = "si.`$name_in_items`";
        $group = $label;
    } elseif ($item_fk !== null) {
        foreach (['products', 'product', 'items', 'item', 'inventory', 'stock'] as $table) {
            $cols = table_columns($conn, $table);
            if (!$cols) {
                continue;
            }
            $pid = first_match($cols, ['id', 'product_id', 'item_id']);
            $pname = first_match($cols, ['name', 'product_name', 'item_name', 'title', 'description']);
            if ($pid !== null && $pname !== null) {
                $join .= " LEFT JOIN `$table` p ON p.`$pid` = si.`$item_fk`";
                $label = "COALESCE(p.`$pname`, CONCAT('Item #', si.`$item_fk`))";
                $group = "si.`$item_fk`, p.`$pname`";
                break;
            }
        }
        if ($label === null) {
            $label = "CONCAT('Item #', si.`$item_fk`)";
            $group = "si.`$item_fk`";
        }
    } else {
        return null;
    }
    return ['join' => $join, 'label' => $label, 'group' => $group, 'qty' => $qty_expr, 'amount' => $amount];
}

/** Items sold in the range (respecting the payment-method filter), best sellers first. */
function fetch_items(mysqli $conn, ?array $src, string $from, string $to, array $aliases = []): array
{
    $rows = [];
    if ($src === null) {
        return $rows;
    }
    [$where, $types, $params] = sales_where($from, $to, $aliases);
    $stmt = $conn->prepare(
        "SELECT {$src['label']} AS item_name, COALESCE(SUM({$src['qty']}), 0), COALESCE(SUM({$src['amount']}), 0)
         FROM sales s {$src['join']}
         WHERE $where
         GROUP BY {$src['group']}
         ORDER BY 3 DESC, 1 ASC
         LIMIT 500"
    );
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($name, $qty, $total);
        while ($stmt->fetch()) {
            $rows[] = ['name' => (string)$name, 'qty' => (float)$qty, 'total' => (float)$total];
        }
        $stmt->close();
    }
    return $rows;
}

/** Individual sales in the range (respecting the payment-method filter), newest first. */
function fetch_sales_list(mysqli $conn, ?array $src, string $from, string $to, array $aliases = []): array
{
    $rows = [];
    if ($src === null) {
        return $rows;
    }
    [$where, $types, $params] = sales_where($from, $to, $aliases);
    $has_d = has_discount_col($conn);
    $stmt = $conn->prepare(
        "SELECT s.id, s.created_at, s.payment_method, " . ($has_d ? 's.discount_amount' : '0') . ", COALESCE(SUM({$src['expr']}), 0)
         FROM sales s {$src['join']}
         WHERE $where
         GROUP BY s.id, s.created_at, s.payment_method" . ($has_d ? ', s.discount_amount' : '') . "
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT 200"
    );
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($id, $created, $pm, $disc, $sum);
        while ($stmt->fetch()) {
            $rows[] = ['id' => (int)$id, 'created' => (string)$created, 'method' => (string)$pm, 'discount' => (float)$disc, 'sum' => (float)$sum];
        }
        $stmt->close();
    }
    return $rows;
}

function has_discount_col(mysqli $conn): bool
{
    static $has = null;
    if ($has === null) {
        $has = isset(table_columns($conn, 'sales')['discount_amount']);
    }
    return $has;
}

/** Total discount given on sales in the range (respecting the payment-method filter). */
function fetch_discounts(mysqli $conn, string $from, string $to, array $aliases = []): float
{
    if (!has_discount_col($conn)) {
        return 0.0;
    }
    [$where, $types, $params] = sales_where($from, $to, $aliases);
    $total = 0.0;
    $stmt = $conn->prepare("SELECT COALESCE(SUM(s.discount_amount), 0) FROM sales s WHERE $where");
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($total);
        $stmt->fetch();
        $stmt->close();
    }
    return (float)$total;
}

/** Discounts per day (from sales alone, so line-item joins can never double count). */
function fetch_daily_discounts(mysqli $conn, string $from, string $to, array $aliases = []): array
{
    $out = [];
    if (!has_discount_col($conn)) {
        return $out;
    }
    [$where, $types, $params] = sales_where($from, $to, $aliases);
    $stmt = $conn->prepare("SELECT DATE(s.created_at), COALESCE(SUM(s.discount_amount), 0) FROM sales s WHERE $where GROUP BY DATE(s.created_at)");
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->bind_result($day, $amt);
        while ($stmt->fetch()) {
            $out[(string)$day] = (float)$amt;
        }
        $stmt->close();
    }
    return $out;
}

function fmt_qty(float $q): string
{
    return floor($q) == $q ? number_format($q) : rtrim(rtrim(number_format($q, 2), '0'), '.');
}

/** Logo for a payment method: your own image if the file exists, otherwise a simple built-in badge. */
function method_icon(string $key, array $m): string
{
    // Looks in the icons/ folder (then assets/logos/) for a file named after the method,
    // e.g. momo.png, MoMo.PNG, t-cash.svg, tcash.webp. Case and dashes are ignored.
    $wanted = [$key, preg_replace('/[^a-z0-9]/', '', strtolower($m['label']))];
    foreach (['icons', 'assets/logos'] as $dir) {
        $abs_dir = __DIR__ . '/' . $dir;
        if (!is_dir($abs_dir)) {
            continue;
        }
        foreach (scandir($abs_dir) ?: [] as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'svg', 'webp', 'jpg', 'jpeg', 'gif'], true)) {
                continue;
            }
            $base = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($file, PATHINFO_FILENAME)));
            if (in_array($base, $wanted, true)) {
                // ?v= makes the browser pick up a replaced logo straight away
                $version = filemtime($abs_dir . '/' . $file);
                return '<img src="' . e($dir . '/' . rawurlencode($file)) . '?v=' . $version . '" alt="" width="32" height="32" style="object-fit:contain;border-radius:6px">';
            }
        }
    }
    $svg = [
        'momo' => '<rect width="40" height="40" rx="9" fill="#FFCC00"/><text x="20" y="25" text-anchor="middle" font-family="Arial,sans-serif" font-weight="800" font-size="13" fill="#003366">MoMo</text>',
        'cash' => '<rect width="40" height="40" rx="9" fill="#047857"/><rect x="7" y="12" width="26" height="16" rx="3" fill="none" stroke="#fff" stroke-width="2"/><circle cx="20" cy="20" r="4" fill="none" stroke="#fff" stroke-width="2"/><circle cx="11.5" cy="20" r="1.3" fill="#fff"/><circle cx="28.5" cy="20" r="1.3" fill="#fff"/>',
        'tcash' => '<rect width="40" height="40" rx="9" fill="#1e3a8a"/><text x="20" y="24" text-anchor="middle" font-family="Arial,sans-serif" font-weight="800" font-size="10" fill="#fff">T-Cash</text><rect x="9" y="29" width="22" height="3" rx="1.5" fill="#ef4444"/>',
        'visa' => '<rect x="1" y="1" width="38" height="38" rx="9" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><text x="20" y="25" text-anchor="middle" font-family="Arial,sans-serif" font-weight="800" font-style="italic" font-size="13" fill="#1a1f71">VISA</text>',
    ];
    if (!isset($svg[$key])) {
        return '';
    }
    return '<svg viewBox="0 0 40 40" width="32" height="32" aria-hidden="true" focusable="false">' . $svg[$key] . '</svg>';
}

function change_badge(float $now, float $before): string
{
    if ($before <= 0) {
        return $now > 0
            ? '<span class="delta up">New activity</span>'
            : '<span class="delta flat">No change</span>';
    }
    $pct = (($now - $before) / $before) * 100;
    if (abs($pct) < 0.05) {
        return '<span class="delta flat">No change</span>';
    }
    $class = $pct > 0 ? 'up' : 'down';
    $sign = $pct > 0 ? '+' : '−';
    return '<span class="delta ' . $class . '">' . $sign . number_format(abs($pct), 1) . '% vs previous period</span>';
}

/* -------------------------------------------------------------
 * Authorization
 * ----------------------------------------------------------- */

$logged_in_user = (string)$_SESSION['username'];
$current_user_role = 'Cashier';

$role_stmt = $conn->prepare('SELECT role FROM users WHERE username = ?');
if ($role_stmt) {
    $role_stmt->bind_param('s', $logged_in_user);
    $role_stmt->execute();
    $role_stmt->bind_result($fetched_role);
    if ($role_stmt->fetch() && $fetched_role !== null) {
        $current_user_role = (string)$fetched_role;
    }
    $role_stmt->close();
}

if (!in_array(strtolower($current_user_role), ['administrator', 'manager'], true)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access denied</title>
        <style>
            body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2ef;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:16px}
            .card{background:#fff;padding:32px;border-radius:16px;border:1px solid #dbe3de;text-align:center;max-width:400px}
            h2{color:#c8372d;margin-bottom:12px;font-size:20px}
            p{color:#66756d;font-size:14px;margin-bottom:20px}
            .btn{background:#0f4c3a;color:#fff;padding:10px 20px;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px}
        </style>
    </head>
    <body>
    <div class="card">
        <h2>Access denied</h2>
        <p>Only managers and administrators can view sales reports.</p>
        <a href="dashboard.php" class="btn">Back to dashboard</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* -------------------------------------------------------------
 * Reverse sale (POST only, Administrator / Manager only)
 * ----------------------------------------------------------- */

$can_reverse = in_array(strtolower($current_user_role), ['administrator', 'manager'], true);

if (empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (RandomException $e) {

    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse_sale') {
    // Return to the same filters afterwards (whitelisted keys only)
    $keep = [];
    foreach (['start_date', 'end_date', 'method', 'cal'] as $k) {
        if (!empty($_POST[$k]) && is_string($_POST[$k])) {
            $keep[$k] = $_POST[$k];
        }
    }
    $back = 'reports.php' . ($keep ? '?' . http_build_query($keep) : '');

    $token_ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    $sale_id = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$can_reverse) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'You do not have permission to reverse sales.'];
    } elseif (!$token_ok || !$sale_id || $sale_id < 1) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'Invalid request. Please try again.'];
    } else {
        try {
            $conn->begin_transaction();

            // Lock the sale so it cannot be reversed twice at the same time
            $lock = $conn->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $sale_id);
            $lock->execute();
            $lock->store_result();
            if ($lock->num_rows === 0) {
                $lock->close();
                throw new RuntimeException('Sale not found. It may already have been reversed.');
            }
            $lock->close();

            // 1. Items on this sale
            $stmt = $conn->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!$items) {
                throw new RuntimeException('This sale has no items to reverse.');
            }

            // 2. Return quantities to stock
            $upd = $conn->prepare('UPDATE stock SET qty = qty + ? WHERE id = ?');
            foreach ($items as $item) {
                $q = (int)$item['qty'];
                $pid = (int)$item['product_id'];
                $upd->bind_param('ii', $q, $pid);
                $upd->execute();
            }
            $upd->close();

            // 3. Delete items, then the sale
            $del_items = $conn->prepare('DELETE FROM sale_items WHERE sale_id = ?');
            $del_items->bind_param('i', $sale_id);
            $del_items->execute();
            $del_items->close();

            $del_sale = $conn->prepare('DELETE FROM sales WHERE id = ?');
            $del_sale->bind_param('i', $sale_id);
            $del_sale->execute();
            $del_sale->close();

            $conn->commit();
            error_log("Sale #$sale_id reversed by $logged_in_user ($current_user_role)");
            $_SESSION['flash'] = ['type' => 'ok', 'text' => "Sale #$sale_id was reversed and its stock returned."];
        } catch (Throwable $ex) {
            $conn->rollback();
            error_log('Reverse sale failed: ' . $ex->getMessage());
            $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not reverse the sale. Nothing was changed.';
            $_SESSION['flash'] = ['type' => 'error', 'text' => $msg];
        }
    }

    header('Location: ' . $back);
    exit;
}

/* -------------------------------------------------------------
 * Inputs
 * ----------------------------------------------------------- */

$today = date('Y-m-d');
$start_date = valid_date($_GET['start_date'] ?? null, $today);
$end_date = valid_date($_GET['end_date'] ?? null, $today);

$notice = '';
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
    $notice = 'The start date was after the end date, so the two were swapped.';
}

try {
    $start = new DateTimeImmutable($start_date);
} catch (Exception $e) {

}
try {
    $end = new DateTimeImmutable($end_date);
} catch (Exception $e) {

}
$days = (int)$start->diff($end)->days + 1;

$max_days = 366;
if ($days > $max_days) {
    $start = $end->modify('-' . ($max_days - 1) . ' days');
    $start_date = $start->format('Y-m-d');
    $days = $max_days;
    $notice = "Ranges are limited to $max_days days. Showing the most recent $max_days days up to $end_date.";
}

$method_key = (string)($_GET['method'] ?? '');
if (!isset($methods[$method_key])) {
    $method_key = 'all';
}
$aliases = $method_key === 'all' ? [] : $methods[$method_key]['aliases'];
$method_label = $method_key === 'all' ? 'All methods' : $methods[$method_key]['label'];

// Month shown in the calendar (defaults to the month of the end date).
$cal_month = (string)($_GET['cal'] ?? '');
$cal_check = DateTimeImmutable::createFromFormat('Y-m-d', $cal_month . '-01');
if (!$cal_check || $cal_check->format('Y-m') !== $cal_month) {
    $cal_month = $end->format('Y-m');
}
try {
    $cal_first = new DateTimeImmutable($cal_month . '-01');
} catch (Exception $e) {

}

$source = detect_revenue_source($conn);
if ($source === null) {
    $notice = 'Revenue could not be calculated: no amount column was found in the sales or sale_items tables.';
}

$from = $start->format('Y-m-d') . ' 00:00:00';
$to = $end->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$prev_end = $start->modify('-1 day');
$prev_start = $prev_end->modify('-' . ($days - 1) . ' days');
$prev_from = $prev_start->format('Y-m-d') . ' 00:00:00';
$prev_to = $start->format('Y-m-d') . ' 00:00:00';

$current = fetch_totals($conn, $source, $from, $to, $aliases);
$previous = fetch_totals($conn, $source, $prev_from, $prev_to, $aliases);
$daily = fetch_daily($conn, $source, $from, $to, $aliases);
$by_method = fetch_by_method($conn, $source, $from, $to, $methods);
$sales_list = fetch_sales_list($conn, $source, $from, $to, $aliases);

$item_source = detect_item_source($conn);
$items_sold = fetch_items($conn, $item_source, $from, $to, $aliases);
$items_qty_total = 0.0;
$items_amount_total = 0.0;
foreach ($items_sold as $it) {
    $items_qty_total += $it['qty'];
    $items_amount_total += $it['total'];
}

$cal_rows = fetch_daily(
    $conn, $source,
    $cal_first->format('Y-m-d') . ' 00:00:00',
    $cal_first->modify('first day of next month')->format('Y-m-d') . ' 00:00:00',
    $aliases
);
$cal_data = [];
foreach ($cal_rows as $r) {
    $cal_data[$r['day']] = $r;
}

$average_sale = $current['count'] > 0 ? $current['sum'] / $current['count'] : 0.0;
$discounts_given = fetch_discounts($conn, $from, $to, $aliases);
$previous_discounts = fetch_discounts($conn, $prev_from, $prev_to, $aliases);
$daily_discounts = fetch_daily_discounts($conn, $from, $to, $aliases);

/* -------------------------------------------------------------
 * URL builder (keeps the current filters unless overridden)
 * ----------------------------------------------------------- */

$base = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'method' => $method_key === 'all' ? '' : $method_key,
    'cal' => $cal_month,
];
$url = static function (array $override = []) use ($base): string {
    $q = array_filter(array_merge($base, $override), static fn($v) => $v !== '' && $v !== null);
    return 'reports.php?' . http_build_query($q);
};

/* -------------------------------------------------------------
 * CSV export (before any HTML output)
 * ----------------------------------------------------------- */

if (($_GET['export'] ?? '') === 'csv') {
    $suffix = $method_key === 'all' ? '' : '_' . $method_key;
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sales_' . $start_date . '_to_' . $end_date . $suffix . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Payment method', 'Transactions', 'Discounts (GHS)', 'Net revenue (GHS)']);
    foreach (array_reverse($daily) as $row) {
        fputcsv($out, [$row['day'], $method_label, $row['count'], number_format($daily_discounts[$row['day']] ?? 0, 2, '.', ''), number_format($row['sum'], 2, '.', '')]);
    }
    fputcsv($out, ['Total', $method_label, $current['count'], number_format($discounts_given, 2, '.', ''), number_format($current['sum'], 2, '.', '')]);
    fclose($out);
    exit;
}

/* -------------------------------------------------------------
 * View data
 * ----------------------------------------------------------- */

$yesterday = date('Y-m-d', strtotime('-1 day'));
$presets = [
    ['Today', $today, $today],
    ['Yesterday', $yesterday, $yesterday],
    ['Last 7 days', date('Y-m-d', strtotime('-6 days')), $today],
    ['This month', date('Y-m-01'), $today],
    ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];

$max_daily = 0.0;
foreach ($daily as $row) {
    $max_daily = max($max_daily, $row['sum']);
}

$range_label = $start_date === $end_date ? $start_date : "$start_date to $end_date";
$lead_blanks = (int)$cal_first->format('N') - 1; // week starts Monday
$days_in_month = (int)$cal_first->format('t');
$prev_month = $cal_first->modify('-1 month')->format('Y-m');
$next_month = $cal_first->modify('+1 month')->format('Y-m');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales report · AlKhair Ghana Limited</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* AlKhair Ghana Limited - Reports theme v2 (matches the POS terminal) */
        :root {
            --forest: #0f4c3a;
            --forest-deep: #0a3328;
            --forest-soft: #e3efe9;
            --gold: #e2a72e;
            --gold-deep: #c58d17;
            --gold-soft: #fbf1d8;
            --red: #c8372d;
            --primary: var(--forest);
            --primary-soft: var(--forest-soft);
            --bg: #eef2ef;
            --surface: #ffffff;
            --text: #14231c;
            --muted: #66756d;
            --border: #dbe3de;
            --success: #0f7a4f;
            --danger: #c8372d;
            --radius: 16px;
            --shadow: 0 1px 2px rgba(10,51,40,.05), 0 6px 20px rgba(10,51,40,.05);
            --font: 'Plus Jakarta Sans', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0;
            font-family: var(--font), serif; }
        html { scroll-behavior: smooth; }
        body { background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; font-variant-numeric: tabular-nums; -webkit-font-smoothing: antialiased; }
        :focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }

        /* ---------- Header: woven strip along the bottom edge ---------- */
        header {
            position: sticky; top: 0; z-index: 20;
            background: var(--forest-deep); color: #fff; height: 64px; padding: 0 28px;
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
        }
        header::after {
            content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 5px;
            background: repeating-linear-gradient(90deg,
            var(--gold) 0 28px, var(--forest) 28px 40px, var(--red) 40px 52px, var(--forest) 52px 64px);
        }
        .header-title { font-size: 17px; font-weight: 800; letter-spacing: -0.02em; color: #fff; display: flex; align-items: center; gap: 12px; }
        .header-title::before {
            content: '📊'; width: 36px; height: 36px; border-radius: 11px; background: var(--gold);
            display: grid; place-items: center; font-size: 17px; box-shadow: 0 2px 0 var(--gold-deep);
        }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .who { font-size: 13px; color: #a9c7bb; font-weight: 600; }
        header .btn.secondary {
            background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,.28); border-radius: 999px; padding: 8px 16px; font-size: 13px;
        }
        header .btn.secondary:hover { background: rgba(255,255,255,.12); }

        /* ---------- Layout ---------- */
        .workspace { display: flex; flex: 1; min-height: 0; }

        .sidebar {
            width: 216px; flex-shrink: 0; background: transparent; border-right: 0;
            padding: 24px 12px 24px 20px; display: flex; flex-direction: column; gap: 4px;
            position: sticky; top: 64px; align-self: flex-start;
        }
        .menu-btn {
            padding: 11px 14px; border-radius: 12px; font-size: 14px; font-weight: 600; color: var(--muted);
            text-decoration: none; border: 0; background: transparent; transition: background .15s, color .15s;
        }
        .menu-btn:hover { background: rgba(15,76,58,.07); color: var(--text); }
        .menu-btn.active { background: var(--surface); color: var(--forest-deep); box-shadow: var(--shadow), inset 4px 0 0 var(--gold); font-weight: 800; }

        main { flex: 1; min-width: 0; padding: 24px 28px 130px 12px; }

        /* ---------- Buttons ---------- */
        .btn {
            padding: 10px 16px; border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer;
            border: 1.5px solid var(--forest); background: var(--forest); color: #fff; text-decoration: none;
            display: inline-block; text-align: center; transition: background .15s, transform .08s;
        }
        .btn:hover { background: var(--forest-deep); }
        .btn:active { transform: scale(.98); }
        .btn.secondary { background: var(--surface); color: var(--text); border-color: var(--border); }
        .btn.secondary:hover { background: var(--bg); border-color: #c4d0c9; }
        .btn.danger { background: transparent; color: var(--danger); border-color: #efc4c0; }
        .btn.danger:hover { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn.small { padding: 6px 12px; font-size: 12px; border-radius: 9px; }

        /* ---------- Notices ---------- */
        .notice {
            background: var(--gold-soft); border: 1px solid #efd58c; border-left: 5px solid var(--gold); color: #6b4a07;
            border-radius: 12px; padding: 12px 16px; font-size: 14px; font-weight: 600; margin-bottom: 18px;
        }
        .notice.ok { background: var(--forest-soft); border-color: #b5d3c5; border-left-color: var(--forest); color: var(--forest-deep); }
        .notice.error { background: #fdeceb; border-color: #f2bcb8; border-left-color: var(--danger); color: #8f241c; }

        .layout { display: grid; grid-template-columns: 292px minmax(0, 1fr); gap: 22px; align-items: start; }

        /* ---------- Calendar ---------- */
        .cal-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 18px; width: 100%; max-width: 292px; box-shadow: var(--shadow);
            position: sticky; top: 88px;
        }
        .cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .cal-head h2 { font-size: 16px; font-weight: 800; letter-spacing: -0.02em; }
        .cal-nav {
            width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
            border: 1.5px solid var(--border); border-radius: 10px; color: var(--forest); text-decoration: none; font-size: 18px; font-weight: 700;
        }
        .cal-nav:hover { background: var(--forest-soft); border-color: var(--forest); }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px 0; }
        .cal-dow { font-size: 11px; font-weight: 700; color: var(--muted); text-align: center; padding: 4px 0 8px; }
        .cal-day {
            position: relative; height: 34px; border: 0; background: transparent; border-radius: 10px;
            font-size: 13px; font-weight: 600; cursor: pointer; color: var(--text); transition: background .12s;
        }
        .cal-day:hover:not(:disabled) { background: var(--bg); }
        .cal-day:disabled { color: #b4bfb8; cursor: not-allowed; font-weight: 500; }
        .cal-day.in-range { background: var(--forest-soft); border-radius: 0; }
        .cal-day.edge { background: var(--forest); color: #fff; border-radius: 10px; font-weight: 800; }
        .cal-day.today { box-shadow: inset 0 0 0 2px var(--gold); }
        .cal-day.pending { background: var(--gold); color: var(--forest-deep); }
        .cal-day.has-sales::after {
            content: ''; position: absolute; bottom: 4px; left: 50%; width: 5px; height: 5px;
            margin-left: -2.5px; border-radius: 50%; background: var(--gold-deep);
        }
        .cal-day.edge.has-sales::after { background: var(--gold); }
        .cal-hint { font-size: 11.5px; line-height: 1.55; color: var(--muted); margin: 12px 0 16px; padding-top: 12px; border-top: 1px dashed var(--border); }
        .cal-hint .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--gold-deep); margin-right: 5px; }

        .filter-form { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--muted); }
        .form-control {
            padding: 9px 10px; min-width: 0; border: 1.5px solid var(--border); border-radius: 10px;
            font-size: 13px; background: #fff; width: 100%; color: var(--text);
        }
        .form-control:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }
        .filter-form .btn { grid-column: 1 / -1; }

        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
        .chip {
            padding: 6px 12px; border: 1.5px solid var(--border); border-radius: 999px; background: var(--surface);
            color: var(--text); font-size: 12px; font-weight: 700; text-decoration: none; transition: background .15s;
        }
        .chip:hover { background: var(--bg); }
        .chip.active { background: var(--forest); border-color: var(--forest); color: #fff; }
        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .cal-card .btn { padding: 9px 12px; font-size: 13px; }

        /* ---------- Metrics ---------- */
        .metrics { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr; gap: 14px; margin-bottom: 20px; }
        .metric {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 20px 22px; box-shadow: var(--shadow); display: flex; flex-direction: column; justify-content: space-between; min-height: 128px;
        }
        .metric h3 { font-size: 13px; font-weight: 700; color: var(--muted); margin-bottom: 10px; }
        .metric .value { font-size: 28px; font-weight: 800; letter-spacing: -0.035em; line-height: 1.1; }
        .metric .value.money { color: var(--forest); }

        /* Revenue is the headline figure */
        .metric:first-child {
            background: var(--forest-deep); border-color: var(--forest-deep);
            background-image: radial-gradient(circle at 100% 0, rgba(226,167,46,.22), transparent 55%);
            box-shadow: var(--shadow), inset 0 -5px 0 var(--gold);
        }
        .metric:first-child h3 { color: #a9c7bb; }
        .metric:first-child .value.money { color: #fff; font-size: 38px; }
        .metric:first-child .delta.up { color: #7fe0b0; }
        .metric:first-child .delta.down { color: #ff9b92; }
        .metric:first-child .delta.flat { color: #a9c7bb; }

        .delta { display: inline-block; margin-top: 10px; font-size: 12px; font-weight: 700; }
        .delta.up { color: var(--success); }
        .delta.down { color: var(--danger); }
        .delta.flat { color: var(--muted); }

        /* ---------- Panels & tables ---------- */
        .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; overflow-x: auto; box-shadow: var(--shadow); }
        .panel h2 { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
        .panel .sub { font-size: 13px; color: var(--muted); margin-bottom: 18px; font-weight: 500; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; }
        th, td { padding: 13px 14px; text-align: left; border-bottom: 1px solid var(--border); }
        th { font-size: 12px; font-weight: 700; color: var(--muted); background: var(--bg); border-bottom: 0; }
        th:first-child { border-radius: 10px 0 0 10px; }
        th:last-child { border-radius: 0 10px 10px 0; }
        tbody td:first-child { font-weight: 600; }
        tbody tr { transition: background .12s; }
        tbody tr:hover td { background: #f7faf8; }
        tbody tr:last-child td { border-bottom: 0; }
        td.num, th.num { text-align: right; }
        td form { display: inline; }
        tfoot td { font-weight: 800; border-bottom: 0; border-top: 2px solid var(--forest); background: transparent; padding-top: 14px; }
        .bar-cell { width: 28%; min-width: 110px; }
        .bar { height: 10px; background: var(--forest-soft); border-radius: 999px; overflow: hidden; }
        .bar > span { display: block; height: 100%; background: linear-gradient(90deg, var(--forest), #1c7a5e); border-radius: 999px; min-width: 4px; }
        tbody tr:first-child .bar > span { background: linear-gradient(90deg, var(--gold-deep), var(--gold)); }

        /* Payment label in the transactions table (optional <span class="pill">) */
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; background: var(--forest-soft); color: var(--forest-deep); font-size: 12px; font-weight: 700; text-transform: capitalize; }

        .empty {
            text-align: center; padding: 40px 16px; color: var(--muted); font-size: 14px;
            border: 2px dashed var(--border); border-radius: 12px; background: #fafcfb;
        }
        .empty strong { display: block; color: var(--text); margin-bottom: 4px; font-size: 15px; }
        .sr-only { position: absolute; left: -9999px; }

        /* ---------- Payment method dock ---------- */
        .method-bar {
            position: fixed; left: 50%; transform: translateX(-50%); bottom: 14px; z-index: 15;
            max-width: calc(100% - 24px); background: rgba(255,255,255,.94); backdrop-filter: blur(8px);
            border: 1px solid var(--border); border-radius: 20px;
            box-shadow: 0 10px 34px rgba(10,51,40,.18);
            display: flex; gap: 8px; padding: 8px; overflow-x: auto; scroll-snap-type: x proximity;
        }
        .mbtn {
            display: flex; align-items: center; gap: 10px; min-width: 156px; padding: 8px 14px;
            border: 2px solid transparent; border-radius: 14px; background: transparent; color: var(--text);
            text-decoration: none; flex-shrink: 0; scroll-snap-align: center; transition: background .15s, border-color .15s;
        }
        .mbtn:hover { background: var(--bg); }
        .mbtn.active { border-color: var(--forest); background: var(--forest-soft); }
        .mbtn .all-icon {
            width: 32px; height: 32px; border-radius: 9px; background: var(--forest-deep); color: var(--gold);
            display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800;
        }
        .mbtn .name { display: block; font-size: 14px; font-weight: 800; line-height: 1.2; }
        .mbtn .meta { display: block; font-size: 12px; color: var(--muted); font-weight: 500; }
        .mbtn.active .name { color: var(--forest-deep); }

        /* ---------- Responsive ---------- */
        @media (max-width: 1100px) { .metrics { grid-template-columns: 1fr 1fr; } .metric:first-child { grid-column: 1 / -1; } }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } .cal-card { max-width: none; position: static; } }
        @media (max-width: 800px) {
            .workspace { flex-direction: column; }
            .sidebar { width: 100%; flex-direction: row; position: static; padding: 12px 16px 0; overflow-x: auto; }
            .menu-btn { white-space: nowrap; }
            main { padding: 16px 16px 130px; }
            header { padding: 0 16px; }
            .who { display: none; }
            .metrics { grid-template-columns: 1fr; }
            .method-bar { left: 12px; right: 12px; transform: none; max-width: none; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; scroll-behavior: auto !important; } }

        /* ---------- Print ---------- */
        @media print {
            header, .sidebar, .cal-card, .method-bar { display: none; }
            td form, th.action, td.action { display: none; }
            .layout { display: block; }
            body { background: #fff; }
            main { padding: 0 0 16px; }
            .panel, .metric { box-shadow: none; }
            .metric:first-child { background: #fff; box-shadow: none; border-color: var(--border); }
            .metric:first-child h3 { color: var(--muted); }
            .metric:first-child .value.money { color: var(--forest); }
        }
    </style>
</head>
<body>

<header>
    <div class="header-title">AlKhair Ghana Limited · Reports</div>
    <div class="header-right">
        <span class="who"><?= e($logged_in_user) ?> · <?= e($current_user_role) ?></span>
        <a href="dashboard.php" class="btn secondary">Back to dashboard</a>
    </div>
</header>

<div class="workspace">
    <nav class="sidebar" aria-label="Reports">
        <a href="reports.php" class="menu-btn active" aria-current="page">Sales summary</a>
        <a href="dashboard.php" class="menu-btn">Main dashboard</a>
    </nav>

    <main>
        <?php if ($notice !== ''): ?>
            <div class="notice" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="notice <?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
        <?php endif; ?>

        <div class="layout">
            <!-- Calendar + date controls -->
            <aside class="cal-card" aria-label="Date filter">
                <div class="cal-head">
                    <a class="cal-nav" href="<?= e($url(['cal' => $prev_month])) ?>" aria-label="Previous month">‹</a>
                    <h2><?= e($cal_first->format('F Y')) ?></h2>
                    <a class="cal-nav" href="<?= e($url(['cal' => $next_month])) ?>" aria-label="Next month">›</a>
                </div>

                <div class="cal-grid" id="calendar">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow): ?>
                        <div class="cal-dow"><?= $dow ?></div>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $lead_blanks; $i++): ?>
                        <div></div>
                    <?php endfor; ?>

                    <?php for ($d = 1; $d <= $days_in_month; $d++):
                        $date = $cal_first->format('Y-m-') . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                        $classes = ['cal-day'];
                        if ($date >= $start_date && $date <= $end_date) { $classes[] = 'in-range'; }
                        if ($date === $start_date || $date === $end_date) { $classes[] = 'edge'; }
                        if ($date === $today) { $classes[] = 'today'; }
                        $title = $date;
                        if (isset($cal_data[$date])) {
                            $classes[] = 'has-sales';
                            $title .= ' · ' . $cal_data[$date]['count'] . ' sale' . ($cal_data[$date]['count'] === 1 ? '' : 's') . ' · ' . money($cal_data[$date]['sum']);
                        }
                        ?>
                        <button type="button" class="<?= e(implode(' ', $classes)) ?>" data-date="<?= e($date) ?>"
                                title="<?= e($title) ?>" <?= $date > $today ? 'disabled' : '' ?>><?= $d ?></button>
                    <?php endfor; ?>
                </div>

                <p class="cal-hint" id="cal-hint" role="status">
                    <span class="dot"></span>Days with sales. Click a day, then another day to choose a range. Click the same day twice for a single day.
                </p>

                <form method="GET" action="reports.php" class="filter-form">
                    <?php if ($method_key !== 'all'): ?>
                        <input type="hidden" name="method" value="<?= e($method_key) ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="start_date">Start date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" max="<?= e($today) ?>" value="<?= e($start_date) ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date">End date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" max="<?= e($today) ?>" value="<?= e($end_date) ?>">
                    </div>
                    <button type="submit" class="btn">Apply dates</button>
                </form>

                <div class="chips" role="group" aria-label="Quick date ranges">
                    <?php foreach ($presets as [$label, $from_d, $to_d]):
                        $active = ($from_d === $start_date && $to_d === $end_date) ? ' active' : ''; ?>
                        <a class="chip<?= $active ?>" href="<?= e($url(['start_date' => $from_d, 'end_date' => $to_d, 'cal' => substr($to_d, 0, 7)])) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="actions">
                    <a href="<?= e($url(['export' => 'csv'])) ?>" class="btn secondary">Download CSV</a>
                    <button type="button" class="btn secondary" onclick="window.print()">Print</button>
                </div>
            </aside>

            <!-- Results -->
            <section>
                <div class="metrics">
                    <div class="metric">
                        <h3>Net revenue (after discounts)</h3>
                        <div class="value money"><?= e(money($current['sum'])) ?></div>
                        <?= change_badge($current['sum'], $previous['sum']) ?>
                    </div>
                    <div class="metric">
                        <h3>Transactions</h3>
                        <div class="value"><?= number_format($current['count']) ?></div>
                        <?= change_badge((float)$current['count'], (float)$previous['count']) ?>
                    </div>
                    <div class="metric">
                        <h3>Average sale</h3>
                        <div class="value"><?= e(money($average_sale)) ?></div>
                    </div>
                    <div class="metric">
                        <h3>Discounts given</h3>
                        <div class="value" style="color: var(--danger)"><?= e(money($discounts_given)) ?></div>
                        <?= change_badge($discounts_given, $previous_discounts) ?>
                    </div>
                </div>

                <div class="panel">
                    <h2>Daily breakdown</h2>
                    <p class="sub">
                        <?= e($range_label) ?> (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>) · <?= e($method_label) ?> · Viewing as <?= e($current_user_role) ?>
                    </p>

                    <?php if (!$daily): ?>
                        <div class="empty">
                            <strong>No sales found</strong>
                            Try a wider date range<?= $method_key !== 'all' ? ' or choose All methods below' : '' ?>.
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col" class="num">Transactions</th>
                                <th scope="col" class="num">Discounts</th>
                                <th scope="col" class="num">Net revenue</th>
                                <th scope="col" class="bar-cell"><span class="sr-only">Share of busiest day</span></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($daily as $row):
                                $pct = $max_daily > 0 ? round(($row['sum'] / $max_daily) * 100) : 0; ?>
                                <tr>
                                    <td><?= e(date('D, j M Y', strtotime($row['day']))) ?></td>
                                    <td class="num"><?= number_format($row['count']) ?></td>
                                    <?php $dd = $daily_discounts[$row['day']] ?? 0.0; ?>
                                    <td class="num" style="color: var(--danger)"><?= $dd > 0 ? e('−' . money($dd)) : '—' ?></td>
                                    <td class="num"><?= e(money($row['sum'])) ?></td>
                                    <td class="bar-cell"><div class="bar" aria-hidden="true"><span style="width: <?= (int)$pct ?>%"></span></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="num"><?= number_format($current['count']) ?></td>
                                <td class="num" style="color: var(--danger)"><?= $discounts_given > 0 ? e('−' . money($discounts_given)) : '—' ?></td>
                                <td class="num"><?= e(money($current['sum'])) ?></td>
                                <td></td>
                            </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <h2>Items sold</h2>
                    <p class="sub"><?= e($range_label) ?> · <?= e($method_label) ?></p>

                    <?php if ($item_source === null): ?>
                        <div class="empty">
                            <strong>Item details unavailable</strong>
                            Could not find the item and amount columns in the sale_items table.
                        </div>
                    <?php elseif (!$items_sold): ?>
                        <div class="empty">
                            <strong>No items sold</strong>
                            Try a wider date range<?= $method_key !== 'all' ? ' or choose All methods below' : '' ?>.
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col" class="num">Qty sold</th>
                                <th scope="col" class="num">Total amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items_sold as $it): ?>
                                <tr>
                                    <td><?= e($it['name']) ?></td>
                                    <td class="num"><?= e(fmt_qty($it['qty'])) ?></td>
                                    <td class="num"><?= e(money($it['total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="num"><?= e(fmt_qty($items_qty_total)) ?></td>
                                <td class="num"><?= e(money($items_amount_total)) ?></td>
                            </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>

                <?php if ($can_reverse): ?>
                    <div class="panel" style="margin-top: 20px;">
                        <h2>Transactions</h2>
                        <p class="sub"><?= e($range_label) ?> · <?= e($method_label) ?> · Showing up to 200 most recent</p>

                        <?php if (!$sales_list): ?>
                            <div class="empty">
                                <strong>No transactions found</strong>
                                Try a wider date range<?= $method_key !== 'all' ? ' or choose All methods below' : '' ?>.
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                <tr>
                                    <th scope="col">Sale #</th>
                                    <th scope="col">Date &amp; time</th>
                                    <th scope="col">Payment</th>
                                    <th scope="col" class="num">Discount</th>
                                    <th scope="col" class="num">Amount</th>
                                    <th scope="col" class="num action">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($sales_list as $s): ?>
                                    <tr>
                                        <td>#<?= (int)$s['id'] ?></td>
                                        <td><?= e(date('j M Y, H:i', strtotime($s['created']))) ?></td>
                                        <td><span class="pill"><?= e($s['method']) ?></span></td>
                                        <td class="num"><?= $s['discount'] > 0 ? e('−' . money($s['discount'])) : '—' ?></td>
                                        <td class="num"><?= e(money($s['sum'])) ?></td>
                                        <td class="num action">
                                            <form method="POST" action="reports.php"
                                                  onsubmit="return confirm('Reverse sale #<?= (int)$s['id'] ?> (<?= e(money($s['sum'])) ?>)?\n\nThe items will be returned to stock and the sale permanently deleted.');">
                                                <input type="hidden" name="action" value="reverse_sale">
                                                <input type="hidden" name="sale_id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="start_date" value="<?= e($start_date) ?>">
                                                <input type="hidden" name="end_date" value="<?= e($end_date) ?>">
                                                <input type="hidden" name="method" value="<?= e($method_key === 'all' ? '' : $method_key) ?>">
                                                <input type="hidden" name="cal" value="<?= e($cal_month) ?>">
                                                <button type="submit" class="btn danger small">Reverse</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<!-- Payment method filter bar -->
<nav class="method-bar" aria-label="Filter by payment method">
    <a class="mbtn<?= $method_key === 'all' ? ' active' : '' ?>" href="<?= e($url(['method' => ''])) ?>"
        <?= $method_key === 'all' ? 'aria-current="true"' : '' ?>>
        <span class="all-icon" aria-hidden="true">All</span>
        <span>
            <span class="name">All methods</span>
            <span class="meta"><?= e(money($by_method['all']['sum'])) ?> · <?= number_format($by_method['all']['count']) ?></span>
        </span>
    </a>
    <?php foreach ($methods as $key => $m): ?>
        <a class="mbtn<?= $method_key === $key ? ' active' : '' ?>" href="<?= e($url(['method' => $key])) ?>"
            <?= $method_key === $key ? 'aria-current="true"' : '' ?>>
            <?= method_icon($key, $m) ?>
            <span>
                <span class="name"><?= e($m['label']) ?></span>
                <span class="meta"><?= e(money($by_method[$key]['sum'])) ?> · <?= number_format($by_method[$key]['count']) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</nav>

<script>
    (function () {
        const grid = document.getElementById('calendar');
        const hint = document.getElementById('cal-hint');
        let first = null;

        grid.addEventListener('click', function (ev) {
            const btn = ev.target.closest('.cal-day');
            if (!btn || btn.disabled) { return; }
            const date = btn.getAttribute('data-date');

            if (first === null) {
                first = date;
                btn.classList.add('pending');
                hint.textContent = 'Start: ' + date + '. Now click the end date (or the same day again for one day).';
                return;
            }

            const a = first < date ? first : date;
            const b = first < date ? date : first;
            const params = new URLSearchParams(window.location.search);
            params.set('start_date', a);
            params.set('end_date', b);
            params.set('cal', b.slice(0, 7));
            params.delete('export');
            window.location.search = params.toString();
        });
    })();
</script>

</body>
</html>