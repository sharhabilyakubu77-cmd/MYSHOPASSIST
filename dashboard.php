<?php
declare(strict_types=1);

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
 * Helpers
 * ----------------------------------------------------------- */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Runs a query that returns a single value; returns $default on any failure. */
function scalar(mysqli $conn, string $sql, float|int $default = 0): float|int
{
    try {
        $res = $conn->query($sql);
        if ($res && ($row = $res->fetch_row()) && $row[0] !== null) {
            return $row[0] + 0;
        }
    } catch (Throwable $ex) {
        error_log('Dashboard query failed: ' . $ex->getMessage());
    }
    return $default;
}

/* -------------------------------------------------------------
 * Data
 * ----------------------------------------------------------- */

$username = (string)$_SESSION['username'];

$total_stock = (int)scalar($conn, 'SELECT COUNT(*) FROM stock');
$total_clients = (int)scalar($conn, 'SELECT COUNT(*) FROM clients');
$today_revenue = (float)scalar(
    $conn,
    'SELECT COALESCE(SUM(si.total), 0)
     FROM sales s JOIN sale_items si ON si.sale_id = s.id
     WHERE s.created_at >= CURDATE() AND s.created_at < CURDATE() + INTERVAL 1 DAY',
    0.0
);

// Discounts given today (today's revenue above is already net of discounts)
$today_discounts = (float)scalar(
    $conn,
    'SELECT COALESCE(SUM(discount_amount), 0) FROM sales
     WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY',
    0.0
);

// Last 6 months that have sales, shown oldest -> newest.
$months = [];
$revenues = [];
try {
    $chart_result = $conn->query(
        "SELECT month_label, monthly_revenue FROM (
             SELECT DATE_FORMAT(MIN(s.created_at), '%b %Y') AS month_label,
                    DATE_FORMAT(s.created_at, '%Y-%m')      AS ym,
                    SUM(si.total)                           AS monthly_revenue
             FROM sales s
             JOIN sale_items si ON si.sale_id = s.id
             GROUP BY ym
             ORDER BY ym DESC
             LIMIT 6
         ) t ORDER BY ym ASC"
    );
    if ($chart_result) {
        while ($row = $chart_result->fetch_assoc()) {
            $months[] = $row['month_label'];
            $revenues[] = round((float)$row['monthly_revenue'], 2);
        }
    }
} catch (Throwable $ex) {
    error_log('Dashboard chart query failed: ' . $ex->getMessage());
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$session_start = $_SESSION['login_time'] ?? time();
$session_start = is_int($session_start) ? $session_start : time();

/* Inline SVG icons (stroke style) so the page doesn't depend on image files. */
$icons = [
    'pos'      => '<path d="M6 6h15l-1.5 9h-12z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/>',
    'reports'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'stock'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    'clients'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.3c2.2.7 3.5 2.7 3.5 5.7"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    'box'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    'cash'     => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.8"/><path d="M6 12h.01M18 12h.01"/>',
    'shield'   => '<path d="M12 3 4 6v6c0 4.5 3.2 7.8 8 9 4.8-1.2 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
];
$icon = static fn(string $name, int $size = 22): string =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$name] . '</svg>';

$modules = [
    ['pos.php',      'pos',      'Point of Sale', 'Ring up sales',        'blue'],
    ['reports.php',  'reports',  'Reports',       'Sales & reversals',    'violet'],
    ['stock.php',    'stock',    'Stock',         'Inventory levels',     'amber'],
    ['client.php',   'clients',  'Clients',       'Customer records',     'teal'],
    ['settings.php', 'settings', 'Settings',      'System & users',       'slate'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard · Alkhairi Ghana Limited</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --bg: #f4f6fb;
            --surface: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e5e9f2;
            --success: #16a34a;
            --radius: 16px;
            --shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 24px rgba(15, 23, 42, .05);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
        }

        :focus-visible { outline: 3px solid var(--primary); outline-offset: 2px; }

        /* ---------- Sidebar ---------- */
        aside {
            width: 264px;
            flex-shrink: 0;
            position: sticky; top: 0; height: 100vh;
            background: linear-gradient(170deg, #0f172a 0%, #1e293b 55%, #1e3a8a 140%);
            color: #e2e8f0;
            padding: 28px 22px;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; }
        .brand-mark {
            width: 42px; height: 42px; border-radius: 12px;
            background: linear-gradient(135deg, #60a5fa, #2563eb);
            display: grid; place-items: center;
            font-weight: 800; font-size: 18px; color: #fff;
            box-shadow: 0 6px 16px rgba(37, 99, 235, .45);
        }
        .brand-name { font-weight: 700; font-size: 15px; line-height: 1.25; color: #fff; }
        .brand-sub { font-size: 11px; color: #94a3b8; letter-spacing: .4px; }

        .user-card {
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 14px;
            padding: 16px;
        }
        .user-card .label { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #94a3b8; }
        .avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            display: grid; place-items: center; font-weight: 700; font-size: 18px; color: #fff;
            margin: 12px 0 10px;
        }
        .user-card .name { font-size: 15px; font-weight: 700; color: #fff; word-break: break-word; }
        .user-card .meta { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }

        .side-bottom { display: flex; flex-direction: column; gap: 14px; }
        .side-date { font-size: 12px; color: #94a3b8; }
        .logout-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 14px; border-radius: 12px;
            background: rgba(239, 68, 68, .12); border: 1px solid rgba(239, 68, 68, .35);
            color: #fecaca; font-weight: 600; font-size: 14px; text-decoration: none;
            transition: background .2s, color .2s;
        }
        .logout-btn:hover { background: #ef4444; color: #fff; }

        /* ---------- Main ---------- */
        main { flex: 1; min-width: 0; padding: 32px 36px 40px; display: flex; flex-direction: column; gap: 26px; }

        .hero {
            display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { font-size: 26px; font-weight: 800; letter-spacing: -.3px; }
        .hero p { color: var(--muted); font-size: 14px; margin-top: 4px; }
        .pill {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--surface); border: 1px solid var(--border); border-radius: 999px;
            padding: 8px 14px; font-size: 12px; font-weight: 600; color: var(--muted);
            box-shadow: var(--shadow);
        }
        .pill .live { width: 8px; height: 8px; border-radius: 50%; background: var(--success); box-shadow: 0 0 0 4px rgba(22, 163, 74, .15); }

        /* ---------- Stats ---------- */
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; }
        .stat {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 20px; box-shadow: var(--shadow);
            display: flex; align-items: center; gap: 16px;
        }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
            display: grid; place-items: center;
        }
        .stat-label { font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--muted); }
        .stat-value { font-size: 24px; font-weight: 800; margin-top: 2px; font-variant-numeric: tabular-nums; letter-spacing: -.3px; }
        .stat-sub { font-size: 11px; font-weight: 600; color: #c8372d; margin-top: 3px; }
        .stat-value.small { font-size: 16px; color: var(--success); margin-top: 6px; }

        /* colour themes */
        .c-blue   { background: #dbeafe; color: #1d4ed8; }
        .c-violet { background: #ede9fe; color: #6d28d9; }
        .c-amber  { background: #fef3c7; color: #b45309; }
        .c-teal   { background: #ccfbf1; color: #0f766e; }
        .c-slate  { background: #e2e8f0; color: #334155; }
        .c-green  { background: #dcfce7; color: #15803d; }

        /* ---------- Modules ---------- */
        .section-title { font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); }
        .modules { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 18px; }
        .tile {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 22px 18px; text-decoration: none; color: var(--text);
            box-shadow: var(--shadow);
            display: flex; flex-direction: column; gap: 14px;
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
        }
        .tile:hover { transform: translateY(-4px); border-color: #bfdbfe; box-shadow: 0 14px 30px rgba(37, 99, 235, .12); }
        .tile-icon { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; }
        .tile-name { font-size: 15px; font-weight: 700; }
        .tile-desc { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .tile-arrow { margin-top: auto; font-size: 12px; font-weight: 700; color: var(--primary); opacity: 0; transform: translateX(-4px); transition: all .2s; }
        .tile:hover .tile-arrow { opacity: 1; transform: none; }

        /* ---------- Chart ---------- */
        .chart-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); }
        .chart-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 18px; gap: 12px; flex-wrap: wrap; }
        .chart-head h2 { font-size: 16px; font-weight: 700; }
        .chart-head span { font-size: 12px; color: var(--muted); }
        .chart-wrap { position: relative; height: 300px; }
        .chart-empty { height: 300px; display: grid; place-items: center; text-align: center; color: var(--muted); font-size: 14px; }
        .chart-empty strong { display: block; color: var(--text); margin-bottom: 4px; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1250px) {
            .modules { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 860px) {
            body { flex-direction: column; }
            aside {
                width: 100%; height: auto; position: static; padding: 18px 20px;
                flex-direction: row; align-items: center; flex-wrap: wrap; gap: 14px;
            }
            .brand { margin-bottom: 0; }
            .user-card { display: none; }
            .side-bottom { flex-direction: row; align-items: center; margin-left: auto; }
            .side-date { display: none; }
            main { padding: 20px 16px 32px; }
            .modules { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 520px) {
            .stats { grid-template-columns: 1fr; }
            .hero h1 { font-size: 22px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .tile, .tile-arrow, .logout-btn { transition: none; }
            .tile:hover { transform: none; }
        }
    </style>
</head>
<body>

<aside>
    <div>
        <div class="brand">
            <div class="brand-mark">A</div>
            <div>
                <div class="brand-name">Alkhairi Ghana Ltd</div>
                <div class="brand-sub">RETAIL POS SYSTEM</div>
            </div>
        </div>

        <div class="user-card">
            <div class="label">Signed in as</div>
            <div class="avatar"><?= substr($username, 0, 1)
                    |> strtoupper(...)
                    |> e(...) ?></div>
            <div class="name"><?= e($username) ?></div>
            <div class="meta">
                Session started<br>
                <?= e(date('d M Y, h:i A', $session_start)) ?>
            </div>
        </div>
    </div>

    <div class="side-bottom">
        <div class="side-date"><?= e(date('l, d F Y')) ?></div>
        <a href="logout.php" class="logout-btn"><?= $icon('logout', 18) ?> Log out</a>
    </div>
</aside>

<main>
    <div class="hero">
        <div>
            <h1><?= e($greeting) ?>, <?= e(ucfirst($username)) ?> 👋</h1>
            <p>Here's what's happening at the shop today.</p>
        </div>
        <div class="pill"><span class="live"></span> System active &amp; secure</div>
    </div>

    <section class="stats" aria-label="Key figures">
        <div class="stat">
            <div class="stat-icon c-blue"><?= $icon('box') ?></div>
            <div>
                <div class="stat-label">Stock items</div>
                <div class="stat-value"><?= number_format($total_stock) ?></div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon c-teal"><?= $icon('clients') ?></div>
            <div>
                <div class="stat-label">Registered clients</div>
                <div class="stat-value"><?= number_format($total_clients) ?></div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon c-green"><?= $icon('cash') ?></div>
            <div>
                <div class="stat-label">Today's sales</div>
                <div class="stat-value">GH₵ <?= number_format($today_revenue, 2) ?></div>
                <?php if ($today_discounts > 0): ?>
                    <div class="stat-sub">after GH₵ <?= number_format($today_discounts, 2) ?> in discounts</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon c-violet"><?= $icon('shield') ?></div>
            <div>
                <div class="stat-label">System status</div>
                <div class="stat-value small">● Active &amp; secure</div>
            </div>
        </div>
    </section>

    <div class="section-title">Management modules</div>
    <nav class="modules" aria-label="Modules">
        <?php foreach ($modules as [$href, $ico, $name, $desc, $color]): ?>
            <a href="<?= e($href) ?>" class="tile">
                <div class="tile-icon c-<?= e($color) ?>"><?= $icon($ico) ?></div>
                <div>
                    <div class="tile-name"><?= e($name) ?></div>
                    <div class="tile-desc"><?= e($desc) ?></div>
                </div>
                <div class="tile-arrow">Open →</div>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="chart-card" aria-label="Revenue trend">
        <div class="chart-head">
            <h2>Revenue trend</h2>
            <span>Last 6 months with sales · GH₵</span>
        </div>
        <?php if ($months): ?>
            <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
        <?php else: ?>
            <div class="chart-empty"><div><strong>No sales data yet</strong>Revenue will appear here once sales are recorded.</div></div>
        <?php endif; ?>
    </section>
</main>

<?php if ($months): ?>
    <script>
        (function () {
            const canvas = document.getElementById('salesChart');
            const ctx = canvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.28)');
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0.01)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($months, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    datasets: [{
                        label: 'Revenue (GH₵)',
                        data: <?= json_encode($revenues) ?>,
                        borderColor: '#2563eb',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.38,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#2563eb',
                        pointBorderWidth: 2.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 12,
                            cornerRadius: 10,
                            displayColors: false,
                            callbacks: {
                                label: function (c) {
                                    return 'GH₵ ' + Number(c.parsed.y).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }

                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#eef2f7' },
                            border: { display: false },
                            ticks: {
                                color: '#64748b',
                                callback: function (v) { return 'GH₵ ' + Number(v).toLocaleString(); }
                            }
                        },
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { color: '#64748b' }
                        }
                    }
                }
            });
        })();
    </script>
<?php endif; ?>

</body>
</html>