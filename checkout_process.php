<?php
global $conn;
session_start();
ini_set('display_errors', 0);

if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['cart'])) {
        echo json_encode(['success' => false, 'error' => 'Cart is empty']);
        exit();
    }

    $client_id = !empty($data['client_id']) ? intval($data['client_id']) : null;
    $payment_method = $data['payment_method'] ?? 'cash';
    $cashier = $_SESSION['username'];
    $cart = $data['cart'];

    $conn->begin_transaction();

    try {
        $total_amount = 0;
        foreach ($cart as $item) {
            $total_amount += floatval($item['price']) * intval($item['qty']);
        }

        // Insert into sales including cashier and payment_method
        if ($client_id) {
            $stmt = $conn->prepare("INSERT INTO sales (client_id, cashier, payment_method, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iss", $client_id, $cashier, $payment_method);
        } else {
            $stmt = $conn->prepare("INSERT INTO sales (cashier, payment_method, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param("ss", $cashier, $payment_method);
        }
        $stmt->execute();
        $sale_id = $conn->insert_id;
        $stmt->close();

        // Insert sale items (including calculated total) and deduct stock
        foreach ($cart as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            $price = floatval($item['price']);
            $item_total = $qty * $price; // Calculate line total

            $stmt_item = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price, total) VALUES (?, ?, ?, ?, ?)");
            $stmt_item->bind_param("iiidd", $sale_id, $product_id, $qty, $price, $item_total);
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
    exit();
}
