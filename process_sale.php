<?php
global $conn;
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

include 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['cart'])) {
    echo json_encode(['success' => false, 'error' => 'Cart is empty']);
    exit();
}

$client_id = !empty($data['client_id']) ? intval($data['client_id']) : null;
$cart = $data['cart'];

// Start database transaction
$conn->begin_transaction();

try {
    $total_amount = 0;
    foreach ($cart as $item) {
        $total_amount += floatval($item['price']) * intval($item['qty']);
    }

    // Insert into sales table using 'total_amount'
    if ($client_id) {
        $stmt = $conn->prepare("INSERT INTO sales (client_id, total_amount, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("id", $client_id, $total_amount);
    } else {
        $stmt = $conn->prepare("INSERT INTO sales (total_amount, created_at) VALUES (?, NOW())");
        $stmt->bind_param("d", $total_amount);
    }
    $stmt->execute();
    $sale_id = $conn->insert_id;
    $stmt->close();

    // Insert sale items without the non-existent 'total' column reference
    foreach ($cart as $item) {
        $product_id = intval($item['id']);
        $qty = intval($item['qty']);
        $price = floatval($item['price']);

        $stmt_item = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price) VALUES (?, ?, ?, ?)");
        $stmt_item->bind_param("iiid", $sale_id, $product_id, $qty, $price);
        $stmt_item->execute();
        $stmt_item->close();

        $stmt_stock = $conn->prepare("UPDATE stock SET qty = qty - ? WHERE id = ?");
        $stmt_stock->bind_param("ii", $qty, $product_id);
        $stmt_stock->execute();
        $stmt_stock->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'sale_id' => $sale_id]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
