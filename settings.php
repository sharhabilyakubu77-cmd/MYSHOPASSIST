<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

$success_message = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_settings'])) {
    $company_name = trim($_POST['company_name'] ?? '');
    $currency = trim($_POST['currency'] ?? '₵');
    $tax_rate = floatval($_POST['tax_rate'] ?? 0);
    $receipt_footer = trim($_POST['receipt_footer'] ?? '');

    $success_message = "System settings updated successfully!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title> Alkhairi Ghana Limited  - System Settings</title>
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
            content: '🛠️'; width: 36px; height: 36px; border-radius: 11px; background: var(--gold);
            display: grid; place-items: center; font-size: 17px; box-shadow: 0 2px 0 var(--gold-deep);
        }
        .header-link {
            color: #fff; text-decoration: none; font-weight: 700; font-size: 13px;
            padding: 8px 16px; border-radius: 999px; border: 1.5px solid rgba(255,255,255,.28); transition: background .15s;
        }
        .header-link:hover { background: rgba(255,255,255,.12); }

        /* ---------- Layout ---------- */
        .workspace { display: flex; flex-grow: 1; overflow: hidden; min-height: 0; }

        .account-sidebar {
            width: 260px; flex-shrink: 0; padding: 24px 12px 24px 20px;
            display: flex; flex-direction: column; gap: 4px; overflow-y: auto;
        }
        .menu-btn {
            padding: 12px 14px; border-radius: 12px; font-size: 14px; font-weight: 600; color: var(--text-muted);
            text-decoration: none; display: block; transition: background .15s, color .15s;
        }
        .menu-btn:hover { background: rgba(15,76,58,.07); color: var(--text-main); }
        .menu-btn.active {
            background: var(--surface); color: var(--forest-deep); font-weight: 800;
            box-shadow: var(--shadow), inset 4px 0 0 var(--gold);
        }

        .account-content { flex-grow: 1; padding: 24px 28px 32px 12px; overflow-y: auto; }

        .settings-grid {
            display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 22px;
            max-width: 1000px; margin: 0 auto; align-items: start;
        }

        /* ---------- Card & form ---------- */
        .card {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 28px; box-shadow: var(--shadow);
        }
        .card h2 { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
        .card .lead { font-size: 13.5px; color: var(--text-muted); margin-bottom: 22px; font-weight: 500; line-height: 1.5; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
        .form-control {
            width: 100%; padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 10px;
            font-size: 14px; background: #fff; color: var(--text-main); transition: border-color .15s, box-shadow .15s;
        }
        textarea.form-control { resize: vertical; min-height: 84px; line-height: 1.5; }
        .form-control:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }

        .btn {
            padding: 12px 22px; border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer;
            border: 1.5px solid transparent; background: var(--gold); color: var(--forest-deep);
            transition: background .15s, transform .08s;
        }
        .btn:hover { background: var(--gold-deep); }
        .btn:active { transform: scale(.98); }

        .alert {
            max-width: 1000px; margin: 0 auto 20px auto; padding: 13px 16px; border-radius: 12px;
            font-size: 13.5px; font-weight: 600; border-left: 5px solid;
        }
        .alert-success { background: var(--forest-soft); color: var(--forest-deep); border-color: var(--forest); }

        /* ---------- Receipt preview ---------- */
        .preview-wrap { position: sticky; top: 0; }
        .preview-label { font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 10px; }
        .receipt {
            position: relative; background: #fff; padding: 24px 22px 28px; text-align: center;
            border-radius: 6px 6px 0 0; box-shadow: var(--shadow); border: 1px solid var(--border); border-bottom: 0;
        }
        .receipt::after {
            content: ''; position: absolute; left: 0; right: 0; bottom: -10px; height: 10px;
            background:
                    linear-gradient(135deg, #fff 25%, transparent 25%) -7px 0 / 14px 14px,
                    linear-gradient(225deg, #fff 25%, transparent 25%) -7px 0 / 14px 14px;
            filter: drop-shadow(0 2px 1px rgba(10,51,40,.08));
        }
        .receipt .shop { font-size: 17px; font-weight: 800; letter-spacing: -0.02em; color: var(--forest); word-break: break-word; }
        .receipt .tag { font-size: 12px; color: var(--text-muted); margin: 2px 0 14px; }
        .receipt .lines {
            text-align: left; font-size: 12.5px; line-height: 1.8; padding: 12px 0;
            border-top: 2px dashed var(--border); border-bottom: 2px dashed var(--border);
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
        }
        .receipt .lines div { display: flex; justify-content: space-between; gap: 10px; font-family: inherit; }
        .receipt .lines div * { font-family: inherit; }
        .receipt .lines .tot { margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--border); font-weight: 700; }
        .receipt .foot { margin-top: 14px; font-size: 12px; color: var(--text-muted); line-height: 1.5; white-space: pre-wrap; word-break: break-word; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1100px) {
            .settings-grid { grid-template-columns: 1fr; }
            .preview-wrap { position: static; max-width: 360px; }
        }
        @media (max-width: 800px) {
            body { height: auto; overflow: auto; }
            .workspace { flex-direction: column; overflow: visible; }
            .account-sidebar { width: 100%; flex-direction: row; padding: 12px 16px 0; overflow-x: auto; }
            .menu-btn { white-space: nowrap; }
            .menu-btn.active { box-shadow: var(--shadow); }
            .account-content { padding: 16px; overflow: visible; }
            .form-row { grid-template-columns: 1fr; }
            header { padding: 0 16px; }
            .header-title { font-size: 15px; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

<header>
    <div class="header-title">AlKhair Ghana Limited - System Settings</div>
    <a href="dashboard.php" class="header-link">Back to Dashboard</a>
</header>

<div class="workspace">
    <nav class="account-sidebar" aria-label="Settings">
        <a href="settings.php" class="menu-btn active" aria-current="page">⚙️ Company Settings</a>
        <a href="account.php?tab=permissions" class="menu-btn">🛡️ User Permissions</a>
        <a href="account.php?tab=logs" class="menu-btn">📋 User Logs</a>
        <a href="account.php?tab=change_password" class="menu-btn">🔑 Change Password</a>
    </nav>

    <div class="account-content">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php ?>

        <div class="settings-grid">
            <div class="card">
                <h2>General System Configuration</h2>
                <p class="lead">Your shop details and what appears on customer receipts.</p>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="company_name">Company / shop name</label>
                        <input type="text" id="company_name" name="company_name" class="form-control" value="ShopAssist Retail" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="currency">Currency symbol</label>
                            <input type="text" id="currency" name="currency" class="form-control" value="₵" required>
                        </div>
                        <div class="form-group">
                            <label for="tax_rate">Default tax rate (%)</label>
                            <input type="number" id="tax_rate" step="0.01" name="tax_rate" class="form-control" value="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="receipt_footer">Receipt footer message</label>
                        <textarea id="receipt_footer" name="receipt_footer" class="form-control" rows="3">Thank you for shopping with us! Goods sold are not returnable.</textarea>
                    </div>
                    <button type="submit" name="update_settings" class="btn">Save Configuration</button>
                </form>
            </div>

            <!-- Live preview of the receipt (updates as you type, display only) -->
            <aside class="preview-wrap" aria-label="Receipt preview">
                <div class="preview-label">Receipt preview</div>
                <div class="receipt">
                    <div class="shop" id="pvShop"></div>
                    <div class="tag">Official Sales Receipt</div>
                    <div class="lines">
                        <div><span>Perfume × 2</span><span><b class="pvCur"></b>50.00</span></div>
                        <div><span>Body lotion × 1</span><span><b class="pvCur"></b>20.00</span></div>
                        <div class="tot"><span>Total paid</span><span><b class="pvCur"></b>70.00</span></div>
                    </div>
                    <div class="foot" id="pvFoot"></div>
                </div>
            </aside>
        </div>
    </div>
</div>

<script>
    (function () {
        const name = document.getElementById('company_name');
        const cur = document.getElementById('currency');
        const foot = document.getElementById('receipt_footer');

        function refresh() {
            document.getElementById('pvShop').textContent = name.value || 'Your shop name';
            document.querySelectorAll('.pvCur').forEach(function (el) { el.textContent = cur.value || ''; });
            document.getElementById('pvFoot').textContent = foot.value;
        }
        [name, cur, foot].forEach(function (el) { el.addEventListener('input', refresh); });
        refresh();
    })();
</script>
</body>
</html>