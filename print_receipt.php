<?php
global $conn;
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

$sale_id = $_GET['id'] ?? 0;

// Fetch sale and client details
$sale_query = "SELECT s.*, c.name as client_name, c.phone as client_phone 
               FROM sales s 
               LEFT JOIN clients c ON s.client_id = c.id 
               WHERE s.id = ?";
$stmt = $conn->prepare($sale_query);
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    die("Sale not found.");
}

// Fetch items for this sale safely without relying on a missing products table
$items_query = "SELECT si.* FROM sale_items si WHERE si.sale_id = ?";
$stmt = $conn->prepare($items_query);
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$items_result = $stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?php echo $sale_id; ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
            color: #000;
            background: #fff;
            max-width: 350px;
            margin: 20px auto;
            padding: 10px;
        }
        .center { text-align: center; }
        .receipt-header { margin-bottom: 15px; border-bottom: 1px dashed #000; padding-bottom: 10px; }
        .receipt-info { margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { padding: 6px 0; text-align: left; font-size: 12px; }
        th { border-bottom: 1px solid #000; }
        .totals { border-top: 1px dashed #000; padding-top: 8px; }
        .total-row { display: flex; justify-content: space-between; font-weight: bold; font-size: 14px; margin-top: 5px; }
        .footer { text-align: center; margin-top: 20px; font-size: 11px; border-top: 1px dashed #000; padding-top: 10px; }
        @media print {
            body { margin: 0; padding: 0; width: 100%; max-width: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="receipt-header center">
    <h2 style="margin: 0; font-size: 18px;">ALKHAIRI GHANA LIMITED</h2>
    <p style="margin: 4px 0;">Accra, Ghana</p>
    <p style="margin: 4px 0;">Tel: +233 XX XXX XXXX</p>
</div>

<div class="receipt-info">
    <div><strong>Receipt #:</strong> <?php echo $sale['id']; ?></div>
    <div><strong>Date:</strong> <?php echo date('d M Y, h:i A', strtotime($sale['created_at'])); ?></div>
    <div><strong>Client:</strong> <?php echo htmlspecialchars($sale['client_name'] ?? 'Walk-in Customer'); ?></div>
</div>

<table>
    <thead>
    <tr>
        <th>Item / Product ID</th>
        <th style="text-align: center;">Qty</th>
        <th style="text-align: right;">Price</th>
        <th style="text-align: right;">Total</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td>Item #<?php echo htmlspecialchars($item['product_id'] ?? 'N/A'); ?></td>
            <td style="text-align: center;"><?php echo $item['qty']; ?></td>
            <td style="text-align: right;">₵<?php echo number_format($item['price'], 2); ?></td>
            <td style="text-align: right;">₵<?php echo number_format((float)$item['price'] * (int)$item['qty'], 2); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php
// Line totals above are gross (qty x price). sale_items.total holds the NET amount after discount.
$subtotal_calc = 0.0;
foreach ($items as $it) {
    $subtotal_calc += (float)$it['price'] * (int)$it['qty'];
}
$net_total = array_sum(array_map(static fn($it) => (float)$it['total'], $items));
$discount_amt = (float)($sale['discount_amount'] ?? 0);
if ($discount_amt <= 0 && $subtotal_calc - $net_total > 0.004) {
    $discount_amt = round($subtotal_calc - $net_total, 2); // fallback if only line items carry the discount
}
$disc_label = (($sale['discount_type'] ?? 'none') === 'percent' && (float)($sale['discount_value'] ?? 0) > 0)
    ? ' (' . rtrim(rtrim(number_format((float)$sale['discount_value'], 2), '0'), '.') . '%)' : '';
?>
<div class="totals">
    <?php if ($discount_amt > 0): ?>
        <div class="total-row" style="font-weight: normal; font-size: 13px;">
            <span>Subtotal:</span>
            <span>₵<?php echo number_format($subtotal_calc, 2); ?></span>
        </div>
        <div class="total-row" style="font-weight: normal; font-size: 13px;">
            <span>Discount<?php echo $disc_label; ?>:</span>
            <span>-₵<?php echo number_format($discount_amt, 2); ?></span>
        </div>
    <?php endif; ?>
    <div class="total-row">
        <span>TOTAL:</span>
        <span>₵<?php echo number_format($net_total, 2); ?></span>
    </div>
</div>

<div class="footer">
    <p>Thank you for your business!</p>
    <p>Please come again.</p>
</div>

<div class="no-print center" style="margin-top: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #2563eb; color: #fff; border: none; border-radius: 6px;">Print Again</button>
    <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer; background: #64748b; color: #fff; border: none; border-radius: 6px; margin-left: 10px;">Close</button>
</div>

</body>
</html>