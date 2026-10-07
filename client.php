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

// Handle adding or updating client
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_client'])) {
    $id = $_POST['client_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $balance = $_POST['balance'] ?? 0.00;

    if (!empty($name)) {
        if (!empty($id)) {
            // Update client
            $stmt = $conn->prepare("UPDATE clients SET name = ?, phone = ?, address = ?, balance = ? WHERE id = ?");
            $stmt->bind_param("sssdi", $name, $phone, $address, $balance, $id);
            if ($stmt->execute()) {
                $success_message = "Client updated successfully!";
            } else {
                $error_message = "Error updating client: " . $conn->error;
            }
        } else {
            // Insert new client
            $stmt = $conn->prepare("INSERT INTO clients (name, phone, address, balance) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssd", $name, $phone, $address, $balance);
            if ($stmt->execute()) {
                $success_message = "New client added successfully!";
            } else {
                $error_message = "Error adding client: " . $conn->error;
            }
        }
        $stmt->close();
    } else {
        $error_message = "Client name is required.";
    }
}

// Handle Delete client
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    $del_stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
    $del_stmt->bind_param("i", $del_id);
    $del_stmt->execute();
    $del_stmt->close();
    header("Location: client.php");
    exit();
}

// Search functionality
$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $search_param = "%$search%";
    $stmt = $conn->prepare("SELECT * FROM clients WHERE name LIKE ? OR phone LIKE ? ORDER BY id DESC");
    $stmt->bind_param("ss", $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM clients ORDER BY id DESC");
}

$clients_rows = [];
$total_balance = 0;
while ($row = $result->fetch_assoc()) {
    $total_balance += $row['balance'];
    $clients_rows[] = $row;
}

// Display only: initials for the avatar and a readable registered date
function client_initials($name): string
{
    $parts = preg_split('/\s+/', trim((string)$name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $second = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $second);
}

function pretty_date($value): string
{
    if (empty($value)) {
        return 'N/A';
    }
    $ts = strtotime((string)$value);
    return $ts === false ? (string)$value : date('j M Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ShopAssist - Client & Credit Management</title>
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
            content: '👥'; width: 36px; height: 36px; border-radius: 11px; background: var(--gold);
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
        textarea.form-control { resize: vertical; min-height: 64px; }
        .form-control:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }
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
        .search-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-form .form-control { width: 260px; padding: 10px 12px; }
        .search-form .btn { padding: 10px 16px; }
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

        .client-cell { display: flex; align-items: center; gap: 12px; font-weight: 700; }
        .avatar {
            width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
            background: var(--forest-soft); color: var(--forest-deep);
            display: grid; place-items: center; font-size: 12px; font-weight: 800;
        }
        .muted { color: var(--text-muted); }
        td.address { max-width: 260px; overflow: hidden; text-overflow: ellipsis; }

        .balance-pill {
            display: inline-block; padding: 4px 12px; border-radius: 999px; font-weight: 800; font-size: 12.5px;
            background: #fdeceb; color: var(--danger);
        }
        .balance-pill.clear { background: var(--forest-soft); color: var(--forest-deep); }
        .empty-cell { text-align: center; color: var(--text-muted); padding: 48px 16px; }

        /* ---------- Summary footer ---------- */
        .status-footer {
            background: var(--forest-deep); color: #a9c7bb;
            padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px;
            font-size: 12.5px; font-weight: 600; box-shadow: inset 0 4px 0 var(--gold);
        }
        .status-footer strong { display: block; color: #fff; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin-top: 2px; }
        .status-footer div:first-child strong { color: var(--gold); }
        .status-footer div:last-child { text-align: right; }
        .status-footer div:last-child strong { font-size: 15px; display: inline-flex; align-items: center; gap: 8px; }
        .status-footer div:last-child strong::before { content: ''; width: 9px; height: 9px; border-radius: 50%; background: #5fd6a0; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1000px) {
            body { height: auto; overflow: auto; }
            .workspace { grid-template-columns: 1fr; overflow: visible; padding: 16px; }
            .main-panel { overflow: visible; }
            .table-container { overflow-x: auto; max-height: 70vh; }
            header { padding: 0 16px; }
            .header-title { font-size: 15px; }
            .search-form, .search-form .form-control { width: 100%; }
            .search-form label { flex: 1; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

<header>
    <div class="header-title">AlKhair Ghana Limited - Credit &amp; Wholesale Clients</div>
    <a href="dashboard.php" class="header-link">Back to Dashboard</a>
</header>

<div class="workspace">
    <div class="form-sidebar" id="formSidebar">
        <h3 id="formTitle">Add client</h3>
        <p class="sub" id="formSub">Fill in the details below, or click a row to edit it.</p>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="client_id" id="client_id">

            <div class="form-group">
                <label for="form_name">Client name</label>
                <input type="text" name="name" id="form_name" class="form-control" required placeholder="Full name or company">
            </div>

            <div class="form-group">
                <label for="form_phone">Phone number</label>
                <input type="text" name="phone" id="form_phone" class="form-control" placeholder="e.g. 0240000000">
            </div>

            <div class="form-group">
                <label for="form_address">Address</label>
                <textarea name="address" id="form_address" class="form-control" rows="2" placeholder="Location/Address"></textarea>
            </div>

            <div class="form-group">
                <label for="form_balance">Outstanding balance / credit (₵)</label>
                <input type="number" step="0.01" name="balance" id="form_balance" class="form-control" value="0.00">
            </div>

            <div class="form-actions">
                <button type="submit" name="save_client" class="btn btn-primary" style="flex-grow: 1;">Save client</button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">Clear</button>
            </div>
        </form>
    </div>

    <div class="main-panel">
        <div class="toolbar">
            <form method="GET" action="" class="search-form">
                <label>
                    <input type="text" name="search" class="form-control" placeholder="Search client name or phone..." aria-label="Search client name or phone" value="<?php echo htmlspecialchars($search); ?>">
                </label>
                <button type="submit" class="btn btn-secondary">Search</button>
            </form>

            <div class="count">
                Total clients: <strong><?php echo count($clients_rows); ?></strong>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                <tr>
                    <th>Client Name</th>
                    <th>Phone Number</th>
                    <th>Address</th>
                    <th class="num">Outstanding Balance</th>
                    <th>Registered Date</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($clients_rows)): ?>
                    <?php foreach ($clients_rows as $row): ?>
                        <tr onclick="populateForm(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES); ?>, this)" title="Click row to edit">
                            <td>
                                <div class="client-cell">
                                    <span class="avatar" aria-hidden="true"><?php echo htmlspecialchars(client_initials($row['name'])); ?></span>
                                    <span><?php echo htmlspecialchars($row['name']); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                            <td class="address muted"><?php echo htmlspecialchars($row['address'] ?? 'N/A'); ?></td>
                            <td class="num">
                                <span class="balance-pill<?php echo ((float)$row['balance'] <= 0) ? ' clear' : ''; ?>">₵<?php echo number_format($row['balance'], 2); ?></span>
                            </td>
                            <td class="muted"><?php echo htmlspecialchars(pretty_date($row['created_at'] ?? null)); ?></td>
                            <td>
                                <a href="client.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="event.stopPropagation(); return confirm('Are you sure you want to delete this client?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr style="cursor: default;">
                        <td colspan="6" class="empty-cell">
                            No clients found.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="status-footer">
            <div>Total outstanding credit balances<strong>₵<?php echo number_format($total_balance, 2); ?></strong></div>
            <div>System mode<strong>Active</strong></div>
        </div>
    </div>
</div>

<script>
    let selectedRow = null;

    function populateForm(client, rowEl) {
        document.getElementById('client_id').value = client.id;
        document.getElementById('form_name').value = client.name;
        document.getElementById('form_phone').value = client.phone || '';
        document.getElementById('form_address').value = client.address || '';
        document.getElementById('form_balance').value = client.balance || 0.00;

        document.getElementById('formTitle').textContent = 'Edit client';
        document.getElementById('formSub').textContent = 'Editing "' + client.name + '". Save to apply your changes.';
        document.getElementById('formSidebar').classList.add('editing');
        if (selectedRow) { selectedRow.classList.remove('selected'); }
        if (rowEl) { rowEl.classList.add('selected'); selectedRow = rowEl; }
    }

    function resetForm() {
        document.getElementById('client_id').value = '';
        document.querySelector('form').reset();
        document.getElementById('formTitle').textContent = 'Add client';
        document.getElementById('formSub').textContent = 'Fill in the details below, or click a row to edit it.';
        document.getElementById('formSidebar').classList.remove('editing');
        if (selectedRow) { selectedRow.classList.remove('selected'); selectedRow = null; }
    }
</script>
</body>
</html>
