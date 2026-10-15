<?php
global $conn;
session_start();
ini_set('display_errors', 0);

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

/* -------------------------------------------------------------
 * Built-in SQL (discount feature)
 * Adds the discount columns the first time this page loads.
 * Safe to run repeatedly: it only alters a table when a column is missing.
 * ----------------------------------------------------------- */
function ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    try {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $ex) {
        error_log("POS discount migration ($table.$column) failed: " . $ex->getMessage());
    }
}

ensure_column($conn, 'sales', 'discount_type', "VARCHAR(10) NOT NULL DEFAULT 'none'");
ensure_column($conn, 'sales', 'discount_value', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
ensure_column($conn, 'sales', 'discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
ensure_column($conn, 'sale_items', 'discount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');

// Who is selling? (managers/administrators get no discount limit)
$pos_role = 'Cashier';
$role_stmt = $conn->prepare('SELECT role FROM users WHERE username = ?');
if ($role_stmt) {
    $uname = (string)$_SESSION['username'];
    $role_stmt->bind_param('s', $uname);
    $role_stmt->execute();
    $role_stmt->bind_result($fetched_role);
    if ($role_stmt->fetch() && $fetched_role !== null) {
        $pos_role = (string)$fetched_role;
    }
    $role_stmt->close();
}
$is_privileged = in_array(strtolower($pos_role), ['administrator', 'manager'], true);
$cashier_max_pct = 10; // display/UX only; the real limit is enforced in checkout_process.php

// Fetch stock items for page render
$stock_result = $conn->query("SELECT * FROM stock WHERE qty > 0 ORDER BY name ASC");
$stock_items = [];
while ($item = $stock_result->fetch_assoc()) {
    $stock_items[] = $item;
}

// Fetch clients for credit sales
$client_result = $conn->query("SELECT * FROM clients ORDER BY name ASC");
$clients = [];
while ($client = $client_result->fetch_assoc()) {
    $clients[] = $client;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ShopAssist - POS Terminal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #0f4c3a;
            --forest-deep: #0a3328;
            --forest-soft: #e3efe9;
            --gold: #e2a72e;
            --gold-soft: #fbf1d8;
            --bg-color: #eef2ef;
            --surface: #ffffff;
            --text-main: #14231c;
            --text-muted: #66756d;
            --border: #dbe3de;
            --danger: #c8372d;
            --warn: #b7791f;
            --radius: 14px;
            --font: 'Plus Jakarta Sans', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0;
            font-family: var(--font), serif; }

        body {
            background: var(--bg-color);
            color: var(--text-main);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-variant-numeric: tabular-nums;
        }

        :focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }

        /* ---------- Header ---------- */
        header {
            background: var(--forest-deep);
            color: #fff;
            padding: 0 24px;
            height: 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid var(--gold);
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-mark {
            width: 34px; height: 34px; border-radius: 10px;
            background: var(--gold); color: var(--forest-deep);
            display: grid; place-items: center; font-size: 18px;
        }
        .brand-name { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; }
        .brand-sub { font-size: 12px; color: #a9c7bb; font-weight: 500; }
        .header-actions { display: flex; align-items: center; gap: 14px; font-size: 13px; color: #a9c7bb; }
        .header-link {
            color: #fff; text-decoration: none; font-weight: 600; font-size: 13px;
            padding: 8px 16px; border-radius: 999px; border: 1px solid rgba(255,255,255,.25);
            transition: background .15s;
        }
        .header-link:hover { background: rgba(255,255,255,.12); }

        /* ---------- Layout ---------- */
        .pos-workspace {
            display: grid;
            grid-template-columns: 1fr 430px;
            flex-grow: 1;
            overflow: hidden;
        }

        /* ---------- Catalog ---------- */
        .catalog-section {
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            overflow-y: auto;
        }
        .search-wrap { position: relative; }
        .search-wrap svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-bar {
            width: 100%;
            padding: 15px 18px 15px 46px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            font-size: 15px;
            background: var(--surface);
            transition: border-color .15s, box-shadow .15s;
        }
        .search-bar:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }

        .catalog-meta { font-size: 13px; color: var(--text-muted); font-weight: 500; }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
            gap: 12px;
        }
        .product-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 14px 14px 12px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 14px;
            min-height: 112px;
            text-align: left;
            transition: border-color .15s, background .15s, transform .08s;
            position: relative;
            overflow: hidden;
        }
        .product-card::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
            background: var(--forest); opacity: 0; transition: opacity .15s;
        }
        .product-card:hover { border-color: var(--forest); background: #fbfdfc; }
        .product-card:hover::before { opacity: 1; }
        .product-card:active { transform: scale(.975); }

        .product-name { font-weight: 600; font-size: 14px; line-height: 1.3; color: var(--text-main); }
        .product-foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 8px; }
        .product-price { color: var(--forest); font-weight: 800; font-size: 17px; letter-spacing: -0.02em; }
        .product-stock {
            font-size: 11.5px; font-weight: 600; color: var(--text-muted);
            background: var(--bg-color); padding: 3px 8px; border-radius: 999px; white-space: nowrap;
        }
        .product-stock.low { background: var(--gold-soft); color: var(--warn); }

        .no-results { grid-column: 1 / -1; text-align: center; color: var(--text-muted); padding: 48px 0; font-size: 14px; display: none; }

        /* ---------- Cart (receipt strip) ---------- */
        .cart-section {
            background: var(--surface);
            border-left: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .cart-header {
            padding: 18px 22px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cart-title { font-weight: 800; font-size: 18px; letter-spacing: -0.02em; display: flex; align-items: center; gap: 10px; }
        .cart-count {
            background: var(--forest); color: #fff; font-size: 12px; font-weight: 700;
            min-width: 24px; height: 24px; border-radius: 999px; display: grid; place-items: center; padding: 0 7px;
        }
        .link-btn {
            background: none; border: none; color: var(--danger); cursor: pointer;
            font-size: 13px; font-weight: 600; padding: 6px 8px; border-radius: 6px;
        }
        .link-btn:hover { background: #fbeceb; }

        .cart-items {
            flex-grow: 1;
            overflow-y: auto;
            padding: 4px 22px 12px;
            display: flex;
            flex-direction: column;
        }
        .empty-cart {
            margin: auto; text-align: center; color: var(--text-muted); font-size: 14px; line-height: 1.5;
        }
        .empty-cart strong { display: block; color: var(--text-main); font-size: 15px; margin-bottom: 2px; }

        .cart-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 4px 12px;
            padding: 14px 0;
            border-bottom: 1px dashed var(--border);
            font-size: 14px;
        }
        .cart-item-name { font-weight: 600; line-height: 1.3; }
        .cart-item-total { font-weight: 700; text-align: right; }
        .cart-item-unit { font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }
        .qty {
            display: inline-flex; align-items: center; border: 1.5px solid var(--border); border-radius: 8px; overflow: hidden;
        }
        .qty button {
            width: 28px; height: 28px; border: none; background: var(--bg-color); cursor: pointer;
            font-size: 16px; font-weight: 700; color: var(--forest);
        }
        .qty button:hover { background: var(--forest-soft); }
        .qty input {
            width: 38px; height: 28px; border: none; text-align: center; font-size: 13px; font-weight: 700; background: #fff;
            -moz-appearance: textfield;
        }
        .qty input::-webkit-outer-spin-button, .qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .qty input:focus { outline: none; background: var(--forest-soft); }
        .remove-btn { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 12px; font-weight: 600; text-align: right; }
        .remove-btn:hover { text-decoration: underline; }

        .cart-summary {
            padding: 18px 22px 20px;
            background: var(--bg-color);
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
        }
        /* torn receipt edge */
        .cart-summary::before {
            content: ''; position: absolute; left: 0; right: 0; top: -8px; height: 8px;
            background:
                    linear-gradient(135deg, var(--bg-color) 25%, transparent 25%) -6px 0 / 12px 12px,
                    linear-gradient(225deg, var(--bg-color) 25%, transparent 25%) -6px 0 / 12px 12px;
        }

        .field-label { font-size: 12px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px; }
        .form-control {
            width: 100%; padding: 11px 12px; border: 1.5px solid var(--border); border-radius: 10px;
            font-size: 14px; background: #fff; color: var(--text-main);
        }
        .form-control:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }
        .form-control:disabled { background: #f3f6f4; color: #98a59d; }

        .pay-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .pay-option {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
            padding: 12px 4px 10px; border: 2px solid var(--border); border-radius: 12px;
            cursor: pointer; background: #fff; font-size: 12.5px; font-weight: 700; position: relative;
            transition: border-color .15s, background .15s;
        }
        .pay-option img { width: 30px; height: 30px; object-fit: contain; }
        .pay-option input { position: absolute; opacity: 0; pointer-events: none; }
        .pay-option:hover { border-color: #b9cbc2; }
        .pay-option:has(input:checked) { border-color: var(--forest); background: var(--forest-soft); color: var(--forest-deep); }
        .pay-option:has(input:checked)::after {
            content: '✓'; position: absolute; top: -8px; right: -6px; width: 20px; height: 20px; border-radius: 50%;
            background: var(--forest); color: #fff; font-size: 11px; display: grid; place-items: center;
        }
        .pay-option:has(input:focus-visible) { outline: 3px solid var(--gold); outline-offset: 2px; }

        /* ---------- Discount ---------- */
        .discount-box {
            background: var(--gold-soft); border: 1px solid #efd58c; border-radius: 12px;
            padding: 12px; display: flex; flex-direction: column; gap: 10px;
        }
        .discount-row { display: flex; align-items: center; gap: 8px; }
        .discount-label { font-size: 12px; font-weight: 700; color: #6b4a07; flex-shrink: 0; }
        .disc-seg { display: inline-flex; background: #fff; border: 1.5px solid #efd58c; border-radius: 10px; overflow: hidden; flex-shrink: 0; }
        .disc-seg button {
            border: none; background: transparent; padding: 8px 11px; font-size: 12.5px; font-weight: 800;
            cursor: pointer; color: #6b4a07;
        }
        .disc-seg button.on { background: var(--gold); color: var(--forest-deep); }
        .discount-box .form-control { padding: 8px 10px; }
        .disc-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .disc-chips[hidden] { display: none; }
        .disc-chip {
            padding: 4px 11px; border-radius: 999px; border: 1.5px solid #efd58c; background: #fff;
            font-size: 12px; font-weight: 800; color: #6b4a07; cursor: pointer;
        }
        .disc-chip:hover { background: var(--gold); color: var(--forest-deep); }
        .disc-note { font-size: 11.5px; font-weight: 600; color: #6b4a07; }
        .disc-note.err { color: var(--danger); }

        .summary-row { display: flex; justify-content: space-between; font-size: 14px; color: var(--text-muted); font-weight: 500; }
        .summary-row.discount { color: var(--danger); font-weight: 700; }
        .summary-row.discount[hidden] { display: none; }
        .summary-row.total {
            color: var(--text-main); font-size: 26px; font-weight: 800; letter-spacing: -0.03em;
            align-items: baseline; padding-top: 4px;
        }
        .summary-row.total span:first-child { font-size: 14px; font-weight: 700; letter-spacing: 0; }

        .btn {
            padding: 16px; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer;
            border: none; text-align: center; width: 100%; transition: background .15s, transform .08s;
        }
        .btn:active { transform: scale(.98); }
        .btn-primary { background: var(--forest); color: #fff; }
        .btn-primary:hover { background: var(--forest-deep); }
        .btn-success { background: var(--gold); color: var(--forest-deep); }
        .btn-success:hover { background: #d19820; }
        .btn-success:disabled { background: #e6dfcc; color: #9a9684; cursor: not-allowed; }
        .btn-ghost { background: transparent; color: var(--text-main); border: 1.5px solid var(--border); }
        .btn-ghost:hover { background: var(--bg-color); }

        /* ---------- Receipt modal ---------- */
        .modal {
            display: none; position: fixed; inset: 0;
            background: rgba(10, 51, 40, .55); backdrop-filter: blur(3px);
            justify-content: center; align-items: center; z-index: 1000; padding: 20px;
        }
        .modal-content {
            background: #fff; padding: 28px 26px 22px; border-radius: 6px; width: 360px; max-height: 92vh; overflow-y: auto;
            text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.35);
            animation: rise .22s ease-out;
        }
        @keyframes rise { from { transform: translateY(16px); opacity: 0; } to { transform: none; opacity: 1; } }
        .receipt-brand { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; color: var(--forest); }
        .receipt-sub { font-size: 12px; color: var(--text-muted); margin: 2px 0 16px; }
        #receiptDetails {
            text-align: left; font-size: 13px; line-height: 1.7; margin-bottom: 18px;
            border-top: 2px dashed var(--border); border-bottom: 2px dashed var(--border); padding: 14px 0;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
        }
        #receiptDetails * { font-family: inherit; }
        .r-line { display: flex; justify-content: space-between; gap: 12px; }
        .r-meta { color: var(--text-muted); }
        .r-total { display: flex; justify-content: space-between; font-size: 16px; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); }
        .modal-actions { display: flex; flex-direction: column; gap: 8px; }
        .modal-actions .btn { padding: 13px; font-size: 14px; }

        /* ---------- Responsive ---------- */
        @media (max-width: 900px) {
            body { height: auto; overflow: auto; }
            .pos-workspace { grid-template-columns: 1fr; overflow: visible; }
            .cart-section { border-left: none; border-top: 1px solid var(--border); }
            .brand-sub { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }

        /* ---------- Print ---------- */
        @media print {
            body * { visibility: hidden; }
            #receipt-modal, #receipt-modal * { visibility: visible; }
            #receipt-modal { position: absolute; left: 0; top: 0; width: 100%; background: white; padding: 20px; box-shadow: none; animation: none; }
            .modal-actions { display: none !important; }
        }
    </style>
</head>
<body>

<header>
    <div class="brand">
        <div class="brand-mark">🛒</div>
        <div>
            <div class="brand-name">AlKhair Ghana Limited</div>
            <div class="brand-sub">Point of Sale</div>
        </div>
    </div>
    <div class="header-actions">
        <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
        <a href="dashboard.php" class="header-link">Dashboard</a>
    </div>
</header>

<div class="pos-workspace">
    <div class="catalog-section">
        <div class="search-wrap">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <label for="productSearch" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Search products</label>
            <input type="text" id="productSearch" class="search-bar" placeholder="Search products by name or code..." onkeyup="filterProducts()" autocomplete="off">
        </div>
        <div class="catalog-meta" id="catalogMeta"><?php echo count($stock_items); ?> products in stock</div>

        <div class="products-grid" id="productsGrid">
            <?php foreach ($stock_items as $item): ?>
                <div class="product-card" role="button" tabindex="0"
                     onclick="addToCart(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES); ?>)"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="product-foot">
                        <div class="product-price">₵<?php echo number_format($item['unit_ret_px'], 2); ?></div>
                        <div class="product-stock<?php echo $item['qty'] <= 5 ? ' low' : ''; ?>"><?php echo $item['qty']; ?> left</div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="no-results" id="noResults">No products match your search.</div>
        </div>
    </div>

    <div class="cart-section">
        <div class="cart-header">
            <div class="cart-title">Current sale <span class="cart-count" id="cartCount">0</span></div>
            <button type="button" class="link-btn" onclick="clearCart()">Clear cart</button>
        </div>

        <div class="cart-items" id="cartItemsList">
            <div class="empty-cart" id="emptyCartText"><strong>Cart is empty</strong>Tap a product to add it.</div>
        </div>

        <div class="cart-summary">
            <div>
                <label class="field-label" for="saleClient">Client (optional)</label>
                <select id="saleClient" class="form-control">
                    <option value="">-- Walk-in Customer --</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <span class="field-label">Payment method</span>
                <div class="pay-grid">
                    <label class="pay-option">
                        <input type="radio" name="payment_method" value="cash" checked>
                        <img src="icons/cash.png" alt="">
                        <span>Cash</span>
                    </label>
                    <label class="pay-option">
                        <input type="radio" name="payment_method" value="momo">
                        <img src="icons/momo.png" alt="">
                        <span>MoMo</span>
                    </label>
                    <label class="pay-option">
                        <input type="radio" name="payment_method" value="tcash">
                        <img src="icons/tcash.png" alt="">
                        <span>T-Cash</span>
                    </label>
                    <label class="pay-option">
                        <input type="radio" name="payment_method" value="visa">
                        <img src="icons/visa.png" alt="">
                        <span>Visa</span>
                    </label>
                </div>
            </div>

            <!-- Discount -->
            <div class="discount-box">
                <div class="discount-row">
                    <span class="discount-label">Discount</span>
                    <div class="disc-seg" role="group" aria-label="Discount type">
                        <button type="button" class="on" data-type="none">None</button>
                        <button type="button" data-type="percent">%</button>
                        <button type="button" data-type="fixed">₵</button>
                    </div>
                    <input type="number" id="discValue" class="form-control" min="0" step="0.01" value="0" disabled aria-label="Discount value">
                </div>
                <div class="disc-chips" id="discChips" hidden>
                    <button type="button" class="disc-chip" data-pct="5">5%</button>
                    <button type="button" class="disc-chip" data-pct="10">10%</button>
                    <?php if ($is_privileged): ?>
                        <button type="button" class="disc-chip" data-pct="15">15%</button>
                        <button type="button" class="disc-chip" data-pct="20">20%</button>
                    <?php endif; ?>
                </div>
                <div class="disc-note" id="discNote"></div>
            </div>

            <div class="summary-row">
                <span>Subtotal</span>
                <span id="subtotalAmount">₵0.00</span>
            </div>
            <div class="summary-row discount" id="discountRow" hidden>
                <span id="discountLabel">Discount</span>
                <span id="discountAmountText">−₵0.00</span>
            </div>
            <div class="summary-row total">
                <span>Total due</span>
                <span id="totalAmount">₵0.00</span>
            </div>
            <button type="button" class="btn btn-success" id="completeBtn" onclick="completeSale()" disabled>Complete sale</button>
        </div>
    </div>
</div>

<div class="modal" id="receiptModal">
    <div class="modal-content" id="receipt-modal">
        <div class="receipt-brand">Alkhairi Ghana Retail</div>
        <p class="receipt-sub">Official Sales Receipt</p>
        <div id="receiptDetails"></div>

        <div class="modal-actions">
            <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Print receipt</button>
            <button type="button" class="btn btn-ghost" onclick="closeModal()">Done / New sale</button>
        </div>
    </div>
</div>

<script>
    let cart = [];

    // Discount state
    const IS_PRIVILEGED = <?php echo $is_privileged ? 'true' : 'false'; ?>;
    const CASHIER_MAX_PCT = <?php echo (int)$cashier_max_pct; ?>;
    let discType = 'none';

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    const money = n => '₵' + (Math.round(n * 100) / 100).toFixed(2);

    /* ---------- Discount helpers ---------- */
    function cartSubtotal() {
        return Math.round(cart.reduce((s, i) => s + Math.round(i.price * i.qty * 100) / 100, 0) * 100) / 100;
    }

    function discValueNum() {
        return Math.max(0, parseFloat(document.getElementById('discValue').value) || 0);
    }

    function calcDiscount(sub) {
        const v = discValueNum();
        if (discType === 'percent') return Math.round(sub * Math.min(v, 100)) / 100;
        if (discType === 'fixed') return Math.min(Math.round(v * 100) / 100, sub);
        return 0;
    }

    function discountProblem(sub, amt) {
        if (discType === 'none' || amt <= 0) return '';
        if (!IS_PRIVILEGED && sub > 0 && (amt / sub * 100) > CASHIER_MAX_PCT + 0.0001) {
            return 'Cashiers can give at most ' + CASHIER_MAX_PCT + '% off. Ask a manager.';
        }
        return '';
    }

    function resetDiscount() {
        discType = 'none';
        document.getElementById('discValue').value = 0;
        document.getElementById('discValue').disabled = true;
        document.getElementById('discChips').hidden = true;
        document.querySelectorAll('.disc-seg button').forEach(b => b.classList.toggle('on', b.dataset.type === 'none'));
    }

    function updateTotals() {
        const sub = cartSubtotal();
        const amt = calcDiscount(sub);
        const problem = discountProblem(sub, amt);
        const total = Math.round((sub - amt) * 100) / 100;

        document.getElementById('subtotalAmount').innerText = money(sub);
        document.getElementById('totalAmount').innerText = money(total);

        const row = document.getElementById('discountRow');
        row.hidden = amt <= 0;
        if (amt > 0) {
            document.getElementById('discountLabel').innerText =
                discType === 'percent' ? 'Discount (' + discValueNum() + '%)' : 'Discount';
            document.getElementById('discountAmountText').innerText = '−' + money(amt);
        }

        const note = document.getElementById('discNote');
        if (problem) {
            note.innerText = problem;
            note.classList.add('err');
        } else {
            note.innerText = IS_PRIVILEGED
                ? 'Manager access: no discount limit.'
                : 'Cashiers can give up to ' + CASHIER_MAX_PCT + '% off.';
            note.classList.remove('err');
        }

        document.getElementById('completeBtn').disabled = cart.length === 0 || !!problem;
    }

    document.querySelectorAll('.disc-seg button').forEach(b => {
        b.addEventListener('click', () => {
            discType = b.dataset.type;
            document.querySelectorAll('.disc-seg button').forEach(x => x.classList.toggle('on', x === b));
            const input = document.getElementById('discValue');
            input.disabled = discType === 'none';
            document.getElementById('discChips').hidden = discType !== 'percent';
            if (discType === 'none') input.value = 0; else input.focus();
            updateTotals();
        });
    });
    document.getElementById('discValue').addEventListener('input', updateTotals);
    document.querySelectorAll('.disc-chip').forEach(c => {
        c.addEventListener('click', () => {
            document.getElementById('discValue').value = c.dataset.pct;
            updateTotals();
        });
    });

    /* ---------- Cart ---------- */
    function addToCart(item) {
        const existingIndex = cart.findIndex(ci => ci.id === item.id);
        if (existingIndex > -1) {
            if (cart[existingIndex].qty < item.qty) {
                cart[existingIndex].qty += 1;
            } else {
                alert('Reached maximum available stock quantity.');
            }
        } else {
            cart.push({
                id: item.id,
                name: item.name,
                price: parseFloat(item.unit_ret_px),
                qty: 1,
                max_qty: item.qty
            });
        }
        renderCart();
    }

    function renderCart() {
        const list = document.getElementById('cartItemsList');
        document.getElementById('cartCount').innerText = cart.reduce((n, i) => n + i.qty, 0);

        if (cart.length === 0) {
            list.innerHTML = '<div class="empty-cart" id="emptyCartText"><strong>Cart is empty</strong>Tap a product to add it.</div>';
            updateTotals();
            return;
        }

        let html = '';

        cart.forEach((item, index) => {
            const itemTotal = item.price * item.qty;

            html += `
                <div class="cart-item">
                    <div class="cart-item-name">${esc(item.name)}</div>
                    <div class="cart-item-total">₵${itemTotal.toFixed(2)}</div>
                    <div class="cart-item-unit">
                        <span class="qty">
                            <button type="button" aria-label="Decrease quantity" onclick="updateQty(${index}, ${item.qty - 1})">−</button>
                            <input type="number" value="${item.qty}" min="1" max="${item.max_qty}" aria-label="Quantity" onchange="updateQty(${index}, this.value)">
                            <button type="button" aria-label="Increase quantity" onclick="updateQty(${index}, ${item.qty + 1})">+</button>
                        </span>
                        <span>× ₵${item.price.toFixed(2)}</span>
                    </div>
                    <button type="button" class="remove-btn" onclick="removeItem(${index})">Remove</button>
                </div>
            `;
        });

        list.innerHTML = html;
        updateTotals();
    }

    function updateQty(index, val) {
        let q = parseInt(val);
        if (q === 0) {
            removeItem(index);
            return;
        }
        if (q > 0 && q <= cart[index].max_qty) {
            cart[index].qty = q;
        } else {
            alert('Invalid quantity specified.');
            cart[index].qty = 1;
        }
        renderCart();
    }

    function removeItem(index) {
        cart.splice(index, 1);
        renderCart();
    }

    function clearCart() {
        cart = [];
        resetDiscount();
        renderCart();
    }

    function filterProducts() {
        const query = document.getElementById('productSearch').value.toLowerCase();
        const cards = document.querySelectorAll('.products-grid .product-card');
        let visible = 0;
        cards.forEach(card => {
            const name = card.querySelector('.product-name').innerText.toLowerCase();
            const show = name.includes(query);
            card.style.display = show ? 'flex' : 'none';
            if (show) visible++;
        });
        document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
        document.getElementById('catalogMeta').innerText = visible + (visible === 1 ? ' product' : ' products') + (query ? ' found' : ' in stock');
    }

    function completeSale() {
        if (cart.length === 0) {
            alert('Cart is empty.');
            return;
        }

        const clientId = document.getElementById('saleClient').value;
        const selectedPaymentEl = document.querySelector('input[name="payment_method"]:checked');
        const paymentMethod = selectedPaymentEl ? selectedPaymentEl.value : 'cash';
        const soldCart = cart.map(i => ({...i}));

        fetch('checkout_process.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                cart: cart,
                client_id: clientId ? parseInt(clientId) : null,
                payment_method: paymentMethod,
                discount_type: discType,
                discount_value: discType === 'none' ? 0 : discValueNum()
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let receiptHtml = `
                        <div class="r-meta">Receipt #${esc(String(data.sale_id))}</div>
                        <div class="r-meta">${new Date().toLocaleString()}</div>
                        <div class="r-meta" style="margin-bottom:10px;">Payment: ${paymentMethod.toUpperCase()}</div>`;
                    soldCart.forEach(i => {
                        const t = i.price * i.qty;
                        receiptHtml += `<div class="r-line"><span>${esc(i.name)} × ${i.qty}</span><span>₵${t.toFixed(2)}</span></div>`;
                    });

                    // Totals come from the server so the receipt matches what was saved
                    const sub = Number(data.subtotal);
                    const disc = Number(data.discount_amount) || 0;
                    const tot = Number(data.total);
                    if (disc > 0) {
                        receiptHtml += `<div class="r-line r-meta" style="margin-top:10px;"><span>Subtotal</span><span>${money(sub)}</span></div>`;
                        receiptHtml += `<div class="r-line"><span>Discount</span><span>−${money(disc)}</span></div>`;
                    }
                    receiptHtml += `<div class="r-total"><strong>Total paid</strong><strong>${money(tot)}</strong></div>`;

                    document.getElementById('receiptDetails').innerHTML = receiptHtml;
                    document.getElementById('receiptModal').style.display = 'flex';
                } else {
                    alert('Checkout Error: ' + (data.error || data.message));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while completing the sale.');
            });
    }

    function closeModal() {
        document.getElementById('receiptModal').style.display = 'none';
        window.location.reload();
    }

    // Initial render
    resetDiscount();
    renderCart();
</script>
</body>
</html>