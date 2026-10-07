<?php
global $conn;
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

$success_message = "";
$error_message = "";


if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_stock'])) {
    $id = $_POST['item_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $qty = $_POST['qty'] ?? 0;
    $unit_ret_px = $_POST['unit_ret_px'] ?? 0;
    $unit_cost_px = $_POST['unit_cost_px'] ?? 0;
    $classification = trim($_POST['classification'] ?? '');
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;

    if (!empty($name)) {
        if (!empty($id)) {
            // Update existing stock item
            $stmt = $conn->prepare("UPDATE stock SET name=?, code=?, qty=?, unit_ret_px=?, unit_cost_px=?, classification=?, expiry_date=? WHERE id=?");
            $stmt->bind_param("ssiddssi", $name, $code, $qty, $unit_ret_px, $unit_cost_px, $classification, $expiry_date, $id);
            if ($stmt->execute()) {
                $success_message = "Stock item updated successfully!";
            } else {
                $error_message = "Error updating stock: " . $conn->error;
            }
        } else {
            // Insert new stock item
            $stmt = $conn->prepare("INSERT INTO stock (name, code, qty, unit_ret_px, unit_cost_px, classification, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiddss", $name, $code, $qty, $unit_ret_px, $unit_cost_px, $classification, $expiry_date);
            if ($stmt->execute()) {
                $success_message = "New stock item added successfully!";
            } else {
                $error_message = "Error adding stock: " . $conn->error;
            }
        }
        $stmt->close();
    } else {
        $error_message = "Item name is required.";
    }
}

// Handle Delete request
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    $del_stmt = $conn->prepare("DELETE FROM stock WHERE id = ?");
    $del_stmt->bind_param("i", $del_id);
    $del_stmt->execute();
    $del_stmt->close();
    header("Location: stock.php");
    exit();
}


$search = trim($_GET['search'] ?? '');
$filter_class = trim($_GET['classification'] ?? '');

$query = "SELECT * FROM stock WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR code LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

if (!empty($filter_class) && $filter_class !== 'All') {
    $query .= " AND classification = ?";
    $params[] = $filter_class;
    $types .= "s";
}

$query .= " ORDER BY id DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();


$total_qty_sum = 0;
$total_ret_val_sum = 0;
$total_cost_val_sum = 0;
$stock_rows = [];

while ($row = $result->fetch_assoc()) {
    $tot_ret = $row['qty'] * $row['unit_ret_px'];
    $tot_cost = $row['qty'] * $row['unit_cost_px'];

    $total_qty_sum += $row['qty'];
    $total_ret_val_sum += $tot_ret;
    $total_cost_val_sum += $tot_cost;

    $row['tot_ret_px'] = $tot_ret;
    $row['tot_cost_px'] = $tot_cost;
    $stock_rows[] = $row;
}
$stmt->close();


$class_result = $conn->query("SELECT DISTINCT classification FROM stock WHERE classification IS NOT NULL AND classification != ''");

// Display only: expiry status for colouring the Expiry Date column
$today_ts = strtotime(date('Y-m-d'));
function expiry_status($date, $today_ts): string
{
    if (empty($date)) {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    if ($ts < $today_ts) {
        return 'expired';
    }
    if ($ts <= $today_ts + 30 * 86400) {
        return 'soon';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alkhair Ghana Limited - Modern Stock Control</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #0f4c3a;
            --forest-deep: #0a3328;
            --forest-soft: #e3efe9;
            --gold: #e2a72e;
            --gold-deep: #c58d17;
            --gold-soft: #fbf1d8;
            --red: #c8372d;
            --bg-color: #eef2ef;
            --surface: #ffffff;
            --text-main: #14231c;
            --text-muted: #66756d;
            --border: #dbe3de;
            --success: #0f7a4f;
            --danger: #c8372d;
            --radius: 16px;
            --shadow: 0 1px 2px rgba(10,51,40,.05), 0 6px 20px rgba(10,51,40,.05);
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
            -webkit-font-smoothing: antialiased;
        }

        :focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }

        /* ---------- Header with woven strip ---------- */
        header {
            position: relative;
            background: var(--forest-deep);
            color: #fff;
            height: 64px;
            padding: 0 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        header::after {
            content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 5px;
            background: repeating-linear-gradient(90deg,
            var(--gold) 0 28px, var(--forest) 28px 40px, var(--red) 40px 52px, var(--forest) 52px 64px);
        }
        .header-title { font-size: 17px; font-weight: 800; letter-spacing: -0.02em; display: flex; align-items: center; gap: 12px; }
        .header-title::before {
            content: '📦'; width: 36px; height: 36px; border-radius: 11px; background: var(--gold);
            display: grid; place-items: center; font-size: 17px; box-shadow: 0 2px 0 var(--gold-deep);
        }
        .header-link {
            color: #fff; text-decoration: none; font-weight: 700; font-size: 13px;
            padding: 8px 16px; border-radius: 999px; border: 1.5px solid rgba(255,255,255,.28); transition: background .15s;
        }
        .header-link:hover { background: rgba(255,255,255,.12); }

        /* ---------- Workspace ---------- */
        .workspace {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 20px;
            padding: 20px 24px 24px;
            flex-grow: 1;
            overflow: hidden;
            min-height: 0;
        }

        /* ---------- Form sidebar ---------- */
        .form-sidebar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .form-sidebar h3 { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; }
        .form-sidebar .sub { font-size: 13px; color: var(--text-muted); margin-top: -8px; font-weight: 500; }
        .form-sidebar.editing { box-shadow: var(--shadow), inset 0 5px 0 var(--gold); }

        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-muted); }
        .form-control {
            padding: 11px 12px; border: 1.5px solid var(--border); border-radius: 10px;
            font-size: 14px; background: #fff; color: var(--text-main); width: 100%; min-width: 0;
            transition: border-color .15s, box-shadow .15s;
        }
        .form-control:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-actions { display: flex; gap: 8px; margin-top: 4px; }

        .btn {
            padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer;
            border: 1.5px solid transparent; text-align: center; text-decoration: none; transition: background .15s, transform .08s;
        }
        .btn:active { transform: scale(.98); }
        .btn-primary { background: var(--gold); color: var(--forest-deep); }
        .btn-primary:hover { background: var(--gold-deep); }
        .btn-secondary { background: var(--surface); color: var(--text-main); border-color: var(--border); }
        .btn-secondary:hover { background: var(--bg-color); }
        .btn-danger { background: transparent; color: var(--danger); border-color: #efc4c0; padding: 5px 11px; font-size: 12px; border-radius: 9px; }
        .btn-danger:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        .alert { padding: 12px 14px; border-radius: 12px; font-size: 13px; font-weight: 600; border-left: 5px solid; }
        .alert-success { background: var(--forest-soft); color: var(--forest-deep); border-color: var(--forest); }
        .alert-error { background: #fdeceb; color: #8f241c; border-color: var(--danger); }

        /* ---------- Main panel ---------- */
        .main-panel {
            display: flex; flex-direction: column; overflow: hidden; min-height: 0;
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
        }
        .toolbar {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .search-filter-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-filter-form .form-control { width: 240px; padding: 10px 12px; }
        .search-filter-form select.form-control { width: 200px; }
        .search-filter-form .btn { padding: 10px 16px; }
        .count { font-size: 13px; color: var(--text-muted); font-weight: 600; }
        .count strong { color: var(--forest-deep); }

        .table-container { flex-grow: 1; overflow: auto; position: relative; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; font-size: 13.5px; white-space: nowrap; }
        th {
            background: var(--bg-color); color: var(--text-muted); font-weight: 700; font-size: 12px;
            padding: 12px 16px; position: sticky; top: 0; z-index: 10;
        }
        td { padding: 12px 16px; border-bottom: 1px solid var(--border); color: var(--text-main); }
        th.num, td.num { text-align: right; }
        tbody tr { cursor: pointer; transition: background .12s; }
        tbody tr:hover td { background: #f7faf8; }
        tbody tr.selected td { background: var(--gold-soft); }
        td.name { font-weight: 700; }
        td.total { color: var(--forest); font-weight: 800; }

        .qty-pill {
            display: inline-block; min-width: 38px; text-align: center; padding: 3px 10px; border-radius: 999px;
            font-weight: 800; font-size: 12.5px; background: var(--forest-soft); color: var(--forest-deep);
        }
        .qty-pill.low { background: #fdeceb; color: var(--danger); }
        .class-tag { display: inline-block; padding: 3px 10px; border-radius: 999px; background: var(--bg-color); font-size: 12px; font-weight: 700; color: var(--text-muted); }
        .exp.expired { color: var(--danger); font-weight: 800; }
        .exp.soon { color: #9a6a0a; font-weight: 800; }
        .empty-cell { text-align: center; color: var(--text-muted); padding: 48px 16px; }

        /* ---------- Summary footer ---------- */
        .status-footer {
            background: var(--forest-deep); color: #a9c7bb;
            padding: 16px 24px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
            font-size: 12.5px; font-weight: 600; box-shadow: inset 0 4px 0 var(--gold);
        }
        .status-footer strong { display: block; color: #fff; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin-top: 2px; }
        .status-footer div:nth-child(2) strong { color: var(--gold); }

        /* ---------- Responsive ---------- */
        @media (max-width: 1000px) {
            body { height: auto; overflow: auto; }
            .workspace { grid-template-columns: 1fr; overflow: visible; padding: 16px; }
            .main-panel { overflow: visible; }
            .table-container { overflow-x: auto; max-height: 70vh; }
            header { padding: 0 16px; }
            .header-title { font-size: 15px; }
            .search-filter-form .form-control, .search-filter-form select.form-control { width: 100%; }
            .search-filter-form { width: 100%; }
            .status-footer { grid-template-columns: 1fr; gap: 10px; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

<header>
    <div class="header-title">AlKhair Ghana Limited - Stock &amp; Inventory Control</div>
    <a href="dashboard.php" class="header-link">Back to Dashboard</a>
</header>

<div class="workspace">
    <!-- Sidebar Control Form (Mirrors Update/Add Stock Functionality) -->
    <div class="form-sidebar" id="formSidebar">
        <h3 id="formTitle">Add stock item</h3>
        <p class="sub" id="formSub">Fill in the details below, or click a row to edit it.</p>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="item_id" id="item_id">

            <div class="form-group">
                <label for="form_name">Item name</label>
                <input type="text" name="name" id="form_name" class="form-control" required placeholder="e.g. Perfume">
            </div>

            <div class="form-group">
                <label for="form_code">Product code / barcode</label>
                <input type="text" name="code" id="form_code" class="form-control" placeholder="e.g. 1001">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="form_qty">Quantity</label>
                    <input type="number" name="qty" id="form_qty" class="form-control" required value="0">
                </div>
                <div class="form-group">
                    <label for="form_class">Classification</label>
                    <input type="text" name="classification" id="form_class" class="form-control" placeholder="e.g. Cosmetics">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="form_ret_px">Retail price (₵)</label>
                    <input type="number" step="0.01" name="unit_ret_px" id="form_ret_px" class="form-control" required value="0.00">
                </div>
                <div class="form-group">
                    <label for="form_cost_px">Cost price (₵)</label>
                    <input type="number" step="0.01" name="unit_cost_px" id="form_cost_px" class="form-control" required value="0.00">
                </div>
            </div>

            <div class="form-group">
                <label for="form_expiry">Expiry date</label>
                <input type="date" name="expiry_date" id="form_expiry" class="form-control">
            </div>

            <div class="form-actions">
                <button type="submit" name="save_stock" class="btn btn-primary" style="flex-grow: 1;">Save stock item</button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">Clear</button>
            </div>
        </form>
    </div>

    <!-- Right Grid & Management Panel -->
    <div class="main-panel">
        <div class="toolbar">
            <form method="GET" action="" class="search-filter-form">
                <label>
                    <input type="text" name="search" class="form-control" placeholder="Search name or code..." aria-label="Search name or code" value="<?php echo htmlspecialchars($search); ?>">
                </label>

                <label>
                    <select name="classification" class="form-control" aria-label="Classification" onchange="this.form.submit()">
                        <option value="All">All Classifications</option>
                        <?php while($c = $class_result->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($c['classification']); ?>" <?php echo ($filter_class === $c['classification']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['classification']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </label>

                <button type="submit" class="btn btn-secondary">Filter</button>
            </form>

            <div class="count">
                Showing <strong><?php echo count($stock_rows); ?></strong> items
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit Ret. Px</th>
                    <th class="num">Unit Cost Px</th>
                    <th class="num">Tot. Cost Px</th>
                    <th class="num">Tot. Ret Px</th>
                    <th>Classification</th>
                    <th>Expiry Date</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($stock_rows)): ?>
                    <?php foreach ($stock_rows as $row): ?>
                        <tr onclick="populateForm(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES); ?>, this)" title="Click row to edit">
                            <td class="name"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['code'] ?? 'N/A'); ?></td>
                            <td class="num">
                                <span class="qty-pill<?php echo ($row['qty'] <= 5) ? ' low' : ''; ?>"><?php echo htmlspecialchars($row['qty']); ?></span>
                            </td>
                            <td class="num">₵<?php echo number_format($row['unit_ret_px'], 2); ?></td>
                            <td class="num">₵<?php echo number_format($row['unit_cost_px'], 2); ?></td>
                            <td class="num">₵<?php echo number_format($row['tot_cost_px'], 2); ?></td>
                            <td class="num total">₵<?php echo number_format($row['tot_ret_px'], 2); ?></td>
                            <td>
                                <?php if (!empty($row['classification'])): ?>
                                    <span class="class-tag"><?php echo htmlspecialchars($row['classification']); ?></span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><span class="exp <?php echo expiry_status($row['expiry_date'] ?? null, $today_ts); ?>"><?php echo htmlspecialchars($row['expiry_date'] ?? 'N/A'); ?></span></td>
                            <td>
                                <a href="stock.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="event.stopPropagation(); return confirm('Are you sure you want to delete this item?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr style="cursor: default;">
                        <td colspan="10" class="empty-cell">
                            No items found in stock inventory.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Modern Equivalent of Bottom Summary Bar -->
        <div class="status-footer">
            <div>Total cost value<strong>₵<?php echo number_format($total_cost_val_sum, 2); ?></strong></div>
            <div>Total retail value<strong>₵<?php echo number_format($total_ret_val_sum, 2); ?></strong></div>
            <div>Total item quantity<strong><?php echo number_format($total_qty_sum); ?></strong></div>
        </div>
    </div>
</div>

<script>
    let selectedRow = null;

    function populateForm(item, rowEl) {
        document.getElementById('item_id').value = item.id;
        document.getElementById('form_name').value = item.name;
        document.getElementById('form_code').value = item.code || '';
        document.getElementById('form_qty').value = item.qty;
        document.getElementById('form_ret_px').value = item.unit_ret_px;
        document.getElementById('form_cost_px').value = item.unit_cost_px;
        document.getElementById('form_class').value = item.classification || '';
        document.getElementById('form_expiry').value = item.expiry_date || '';

        document.getElementById('formTitle').textContent = 'Edit stock item';
        document.getElementById('formSub').textContent = 'Editing "' + item.name + '". Save to apply your changes.';
        document.getElementById('formSidebar').classList.add('editing');
        if (selectedRow) { selectedRow.classList.remove('selected'); }
        if (rowEl) { rowEl.classList.add('selected'); selectedRow = rowEl; }
    }

    function resetForm() {
        document.getElementById('item_id').value = '';
        document.querySelector('form').reset();
        document.getElementById('formTitle').textContent = 'Add stock item';
        document.getElementById('formSub').textContent = 'Fill in the details below, or click a row to edit it.';
        document.getElementById('formSidebar').classList.remove('editing');
        if (selectedRow) { selectedRow.classList.remove('selected'); selectedRow = null; }
    }
</script>
</body>
</html>