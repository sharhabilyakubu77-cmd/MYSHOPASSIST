<?php
global $conn;
session_start();
ini_set('display_errors', 0);

// Max discount (as % of subtotal) a Cashier may give. Managers/Administrators: up to 100%.
const CASHIER_MAX_DISCOUNT_PERCENT = 10.0;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['cart'])) {
    echo json_encode(['success' => false, 'error' => 'Cart is empty']);
    exit();
}

$client_id      = !empty($data['client_id']) ? intval($data['client_id']) : null;
$payment_method = $data['payment_method'] ?? 'cash';
$cashier        = $_SESSION['username'];
$cart           = $data['cart'];

// Discount input (never trust a client-calculated amount; only type + value are used)
$discount_type  = in_array(($data['discount_type'] ?? 'none'), ['percent', 'fixed'], true) ? $data['discount_type'] : 'none';
$discount_value = $discount_type === 'none' ? 0.0 : 0
        |> floatval(...)
        |> (fn($x) => round($x, 2))
        |> (fn($x) => max(0.0, $x));

// Look up the cashier's role
$role = 'Cashier';
$r = $conn->prepare('SELECT role FROM users WHERE username = ?');
if ($r) {
    $r->bind_param('s', $cashier);
    $r->execute();
    $r->bind_result($fetched_role);
    if ($r->fetch() && $fetched_role !== null) {
        $role = (string)$fetched_role;
    }
    $r->close();
}
$is_privileged = in_array(strtolower($role), ['administrator', 'manager'], true);

$conn->begin_transaction();

try {
    // 1. Gross line totals and subtotal
    $lines = [];
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $qty   = intval($item['qty']);
        $price = floatval($item['price']);
        if ($qty < 1 || $price < 0) {
            throw new RuntimeException('Invalid item in cart.');
        }
        $gross = round($qty * $price, 2);
        $lines[] = ['id' => intval($item['id']), 'qty' => $qty, 'price' => $price, 'gross' => $gross];
        $subtotal += $gross;
    }
    $subtotal = round($subtotal, 2);

    // 2. Discount amount (server-side)
    if ($discount_type === 'percent') {
        if ($discount_value > 100) {
            throw new RuntimeException('Discount cannot exceed 100%.');
        }
        $discount_amount = round($subtotal * $discount_value / 100, 2);
    } elseif ($discount_type === 'fixed') {
        $discount_amount = $discount_value;
    } else {
        $discount_amount = 0.0;
    }

    if ($discount_amount > $subtotal) {
        throw new RuntimeException('Discount cannot be more than the sale total.');
    }
    if ($discount_amount > 0 && !$is_privileged && $subtotal > 0) {
        $pct = $discount_amount / $subtotal * 100;
        if ($pct > CASHIER_MAX_DISCOUNT_PERCENT + 0.0001) {
            throw new RuntimeException('Cashiers can give at most ' . CASHIER_MAX_DISCOUNT_PERCENT . '% discount. Ask a manager.');
        }
    }

    // 3. Prorate discount across lines (last line absorbs rounding)
    $allocated = 0.0;
    $last = count($lines) - 1;
    foreach ($lines as $i => &$ln) {
        if ($discount_amount <= 0 || $subtotal <= 0) {
            $share = 0.0;
        } elseif ($i === $last) {
            $share = round($discount_amount - $allocated, 2);
        } else {
            $share = round($discount_amount * $ln['gross'] / $subtotal, 2);
            $allocated += $share;
        }
        $ln['discount'] = $share;
        $ln['net'] = round($ln['gross'] - $share, 2);
    }
    unset($ln);
    $net_total = round($subtotal - $discount_amount, 2);

    // 4. Insert sale
    $stmt = $conn->prepare(
        "INSERT INTO sales (client_id, cashier, payment_method, discount_type, discount_value, discount_amount, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param("isssdd", $client_id, $cashier, $payment_method, $discount_type, $discount_value, $discount_amount);
    $stmt->execute();
    $sale_id = $conn->insert_id;

    // If sales has a total/total_amount column, store the NET (after-discount) figure there too
    foreach (['total_amount', 'total', 'grand_total', 'amount'] as $tc) {
        $chk = $conn->query("SHOW COLUMNS FROM `sales` LIKE '$tc'");
        if ($chk && $chk->num_rows > 0) {
            $upd = $conn->prepare("UPDATE sales SET `$tc` = ? WHERE id = ?");
            $upd->bind_param("di", $net_total, $sale_id);
            $upd->execute();
            $upd->close();
            break;
        }
    }
    $stmt->close();

    // 5. Insert items (total = NET after discount) and deduct stock
    $stmt_item  = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price, discount, total) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_stock = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE id = ?");
    foreach ($lines as $ln) {
        $stmt_item->bind_param("iiiddd", $sale_id, $ln['id'], $ln['qty'], $ln['price'], $ln['discount'], $ln['net']);
        $stmt_item->execute();

        $stmt_stock->bind_param("ii", $ln['qty'], $ln['id']);
        $stmt_stock->execute();
    }
    $stmt_item->close();
    $stmt_stock->close();

    $conn->commit();
    echo json_encode([
        'success'         => true,
        'sale_id'         => $sale_id,
        'subtotal'        => $subtotal,
        'discount_amount' => $discount_amount,
        'total'           => $net_total,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Could not complete the sale.';
    error_log('Checkout failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $msg]);
}
exit();