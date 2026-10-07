<?php
global $conn;
session_start();
ini_set('display_errors', 0);

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

$success_message = "";
$error_message = "";
$active_tab = $_GET['tab'] ?? 'dashboard';

// Ensure roles and permissions tables/columns exist or handle role assignments
// (Note: Make sure your `users` table has a `role` column, e.g., ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'Cashier';)

// Handle Add New User with Role
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_user'])) {
    $new_user = trim($_POST['new_username'] ?? '');
    $new_pass = $_POST['new_password'] ?? '';
    $new_role = trim($_POST['new_role'] ?? 'Cashier');

    if (!empty($new_user) && !empty($new_pass)) {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $new_user);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error_message = "Username already exists.";
        } else {
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            // Insert with role (Ensure your users table has a 'role' column)
            $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sss", $new_user, $hashed_pass, $new_role);
                if ($stmt->execute()) {
                    $success_message = "New user created successfully with role: $new_role!";
                } else {
                    $error_message = "Error creating user: " . $conn->error;
                }
                $stmt->close();
            } else {
                // Fallback if 'role' column hasn't been manually added to DB table yet
                $stmt_fallback = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt_fallback->bind_param("ss", $new_user, $hashed_pass);
                $stmt_fallback->execute();
                $stmt_fallback->close();
                $success_message = "New user created successfully! (Tip: Add a 'role' column to your users table to store roles permanently)";
            }
        }
        $check->close();
    } else {
        $error_message = "Username and password are required.";
    }
    $active_tab = 'new_user';
}

// Handle Update User Role
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_role'])) {
    $target_user_id = $_POST['user_id'] ?? 0;
    $updated_role = $_POST['role'] ?? 'Cashier';

    $update_stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    if ($update_stmt) {
        $update_stmt->bind_param("si", $updated_role, $target_user_id);
        if ($update_stmt->execute()) {
            $success_message = "User role updated successfully!";
        } else {
            $error_message = "Failed to update role.";
        }
        $update_stmt->close();
    } else {
        $error_message = "Database schema missing 'role' column in users table.";
    }
    $active_tab = 'permissions';
}

// Handle Change Password
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['change_password'])) {
    $username = $_SESSION['username'];
    $old_pass = $_POST['old_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (!empty($old_pass) && !empty($new_pass) && !empty($confirm_pass)) {
        if ($new_pass !== $confirm_pass) {
            $error_message = "New passwords do not match.";
        } else {
            $stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($hashed_password);
            if ($stmt->fetch()) {
                $stmt->close();
                if (password_verify($old_pass, $hashed_password) || $old_pass === $hashed_password) {
                    $new_hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $update->bind_param("ss", $new_hashed, $username);
                    if ($update->execute()) {
                        $success_message = "Password changed successfully!";
                    } else {
                        $error_message = "Error updating password.";
                    }
                    $update->close();
                } else {
                    $error_message = "Incorrect old password.";
                }
            } else {
                $error_message = "User not found.";
            }
        }
    } else {
        $error_message = "All password fields are required.";
    }
    $active_tab = 'change_password';
}

// Handle Delete User
if (isset($_GET['delete_user'])) {
    $del_id = $_GET['delete_user'];
    $del_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $del_stmt->bind_param("i", $del_id);
    $del_stmt->execute();
    $del_stmt->close();
    header("Location: account.php?tab=permissions");
    exit();
}

// Fetch Users for lists/permissions (safely checking if role column exists)
$users_result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
$has_role_column = ($users_result && $users_result->num_rows > 0);

if ($has_role_column) {
    $users_query = $conn->query("SELECT * FROM users ORDER BY username ASC");
} else {
    $users_query = $conn->query("SELECT id, username, password, 'Cashier' as role FROM users ORDER BY username ASC");
}

$users_list = [];
while($u = $users_query->fetch_assoc()) {
    $users_list[] = $u;
}

// Get selected user for permissions view
$selected_user_id = $_GET['user_id'] ?? ($users_list[0]['id'] ?? 0);
$current_selected_user = null;
foreach ($users_list as $u) {
    if ($u['id'] == $selected_user_id) {
        $current_selected_user = $u;
        break;
    }
}
if (!$current_selected_user && !empty($users_list)) {
    $current_selected_user = $users_list[0];
}

// Display only: role badge colour and avatar initial
function role_class($role): string
{
    $r = strtolower((string)$role);
    if ($r === 'administrator') {
        return 'role-admin';
    }
    if ($r === 'manager') {
        return 'role-manager';
    }
    return 'role-cashier';
}

function user_initial($name): string
{
    return mb_strtoupper(mb_substr(trim((string)$name), 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AlKhair Ghana Limited - User Account & Permissions Management</title>
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
            content: '⚙️'; width: 36px; height: 36px; border-radius: 11px; background: var(--gold);
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

        /* ---------- Cards ---------- */
        .card {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 28px; max-width: 600px; margin: 0 auto; box-shadow: var(--shadow);
        }
        .card h2 { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
        .card .lead { font-size: 13.5px; color: var(--text-muted); margin-bottom: 22px; font-weight: 500; line-height: 1.5; }

        .welcome { text-align: center; padding: 44px 32px; box-shadow: var(--shadow), inset 0 5px 0 var(--gold); }
        .welcome .lead { margin: 8px auto 26px; max-width: 420px; }
        .welcome-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
        .form-control {
            width: 100%; padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 10px;
            font-size: 14px; background: #fff; color: var(--text-main); transition: border-color .15s, box-shadow .15s;
        }
        .form-control:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 4px var(--forest-soft); }
        .check-row { margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
        .check-row input { accent-color: var(--forest); width: 16px; height: 16px; }
        .check-row label { font-size: 13px; font-weight: 600; color: var(--text-main); cursor: pointer; }

        .btn {
            padding: 12px 20px; border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer;
            border: 1.5px solid transparent; background: var(--gold); color: var(--forest-deep);
            transition: background .15s, transform .08s; text-decoration: none; display: inline-block; text-align: center;
        }
        .btn:hover { background: var(--gold-deep); }
        .btn:active { transform: scale(.98); }
        .btn-green { background: var(--forest); color: #fff; }
        .btn-green:hover { background: var(--forest-deep); }
        .btn-ghost { background: var(--surface); color: var(--text-main); border-color: var(--border); }
        .btn-ghost:hover { background: var(--bg-color); }
        .btn-danger { background: transparent; color: var(--danger); border-color: #efc4c0; }
        .btn-danger:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        .alert {
            max-width: 600px; margin: 0 auto 20px auto; padding: 13px 16px; border-radius: 12px;
            font-size: 13.5px; font-weight: 600; border-left: 5px solid;
        }
        .alert-success { background: var(--forest-soft); color: var(--forest-deep); border-color: var(--forest); }
        .alert-error { background: #fdeceb; color: #8f241c; border-color: var(--danger); }

        /* ---------- Roles & permissions ---------- */
        .permissions-container {
            display: grid; grid-template-columns: 260px 1fr; gap: 24px; max-width: 1100px; margin: 0 auto;
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 22px; box-shadow: var(--shadow);
        }
        .user-select-list { border-right: 1px solid var(--border); padding-right: 20px; display: flex; flex-direction: column; gap: 6px; }
        .user-select-list h3 { font-size: 13px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
        .user-item-btn {
            padding: 10px 12px; border: 1.5px solid transparent; border-radius: 12px; text-decoration: none;
            color: var(--text-main); display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 14px;
            transition: background .15s;
        }
        .user-item-btn:hover { background: var(--bg-color); }
        .user-item-btn.selected { background: var(--forest-soft); border-color: var(--forest); }
        .avatar {
            width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; background: var(--forest); color: #fff;
            display: grid; place-items: center; font-size: 14px; font-weight: 800;
        }
        .user-item-btn.selected .avatar { background: var(--gold); color: var(--forest-deep); }
        .user-meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .user-meta span.name { overflow: hidden; text-overflow: ellipsis; }

        .role-badge { display: inline-block; font-size: 11px; font-weight: 800; padding: 2px 9px; border-radius: 999px; width: fit-content; }
        .role-admin { background: var(--gold-soft); color: #8a5d05; }
        .role-manager { background: var(--forest-soft); color: var(--forest-deep); }
        .role-cashier { background: var(--bg-color); color: var(--text-muted); }

        .delete-wrap { margin-top: auto; padding-top: 16px; border-top: 1px dashed var(--border); }
        .delete-wrap .btn { display: block; font-size: 13px; padding: 10px; }

        .perm-title { font-size: 17px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 16px; }
        .perm-title span { color: var(--forest); }
        .role-form {
            margin-bottom: 24px; background: var(--bg-color); padding: 16px; border-radius: 14px;
            display: flex; gap: 12px; align-items: flex-end;
        }
        .role-form .grow { flex-grow: 1; }
        .section-label { font-size: 13px; font-weight: 700; color: var(--text-muted); margin-bottom: 12px; }

        .permissions-grid-cols { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; font-size: 13px; }
        .permission-checkbox-group {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            background: var(--forest-soft); border: 1px solid #c5ddd0; border-radius: 10px;
            font-weight: 600; color: var(--forest-deep);
        }
        .permission-checkbox-group input { accent-color: var(--forest); flex-shrink: 0; }
        .permission-checkbox-group.denied { background: #fafbfa; border-color: var(--border); color: #98a59d; }
        .permission-checkbox-group.denied span { text-decoration: line-through; }

        /* ---------- Logs table ---------- */
        .log-card { max-width: 900px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13.5px; text-align: left; margin-top: 16px; }
        th { background: var(--bg-color); color: var(--text-muted); font-weight: 700; font-size: 12px; padding: 12px 14px; }
        th:first-child { border-radius: 10px 0 0 10px; }
        th:last-child { border-radius: 0 10px 10px 0; }
        td { padding: 13px 14px; border-bottom: 1px solid var(--border); font-weight: 500; }
        td.when { color: var(--text-muted); font-variant-numeric: tabular-nums; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1000px) {
            .permissions-grid-cols { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 800px) {
            body { height: auto; overflow: auto; }
            .workspace { flex-direction: column; overflow: visible; }
            .account-sidebar { width: 100%; flex-direction: row; padding: 12px 16px 0; overflow-x: auto; }
            .menu-btn { white-space: nowrap; }
            .menu-btn.active { box-shadow: var(--shadow); }
            .account-content { padding: 16px; overflow: visible; }
            .permissions-container { grid-template-columns: 1fr; }
            .user-select-list { border-right: 0; padding-right: 0; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
            .permissions-grid-cols { grid-template-columns: 1fr; }
            .role-form { flex-direction: column; align-items: stretch; }
            header { padding: 0 16px; }
            .header-title { font-size: 15px; }
            .log-card { overflow-x: auto; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

<header>
    <div class="header-title">ShopAssist - Account &amp; User Management</div>
    <a href="dashboard.php" class="header-link">Back to Dashboard</a>
</header>

<div class="workspace">
    <!-- Sidebar Dashboard Menu Buttons -->
    <nav class="account-sidebar" aria-label="Account settings">
        <a href="account.php?tab=dashboard" class="menu-btn <?php echo ($active_tab === 'dashboard') ? 'active' : ''; ?>">🏠 Settings Overview</a>
        <a href="account.php?tab=new_user" class="menu-btn <?php echo ($active_tab === 'new_user') ? 'active' : ''; ?>">➕ New User</a>
        <a href="account.php?tab=permissions" class="menu-btn <?php echo ($active_tab === 'permissions') ? 'active' : ''; ?>">🛡️ User Roles &amp; Permissions</a>
        <a href="account.php?tab=change_password" class="menu-btn <?php echo ($active_tab === 'change_password') ? 'active' : ''; ?>">🔑 Change User Password</a>
        <a href="account.php?tab=logs" class="menu-btn <?php echo ($active_tab === 'logs') ? 'active' : ''; ?>">📋 User Logs</a>
    </nav>

    <!-- Dynamic Content Area -->
    <div class="account-content">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($active_tab === 'dashboard' || $active_tab === ''): ?>
            <div class="card welcome">
                <h2>Welcome to Account Settings</h2>
                <p class="lead">Select an option from the left sidebar to manage system users, configure user roles, or update credentials.</p>
                <div class="welcome-actions">
                    <a href="account.php?tab=new_user" class="btn">Create New User</a>
                    <a href="account.php?tab=permissions" class="btn btn-green">Manage Roles &amp; Permissions</a>
                </div>
            </div>

        <?php elseif ($active_tab === 'new_user'): ?>
            <div class="card">
                <h2>Add New System User</h2>
                <p class="lead">Create a login and choose what this person can do.</p>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="new_username">Username</label>
                        <input type="text" id="new_username" name="new_username" class="form-control" required placeholder="Enter username">
                    </div>
                    <div class="form-group">
                        <label for="new_password">Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required placeholder="Enter temporary password">
                    </div>
                    <div class="form-group">
                        <label for="new_role">User role</label>
                        <select id="new_role" name="new_role" class="form-control">
                            <option value="Administrator">Administrator (Full Access)</option>
                            <option value="Manager">Manager (Reports & Inventory)</option>
                            <option value="Cashier" selected>Cashier (POS & Sales)</option>
                        </select>
                    </div>
                    <button type="submit" name="add_user" class="btn">Create User Account</button>
                </form>
            </div>

        <?php elseif ($active_tab === 'change_password'): ?>
            <div class="card">
                <h2>Change Password for <?php echo htmlspecialchars($_SESSION['username']); ?></h2>
                <p class="lead">Enter your current password, then choose a new one.</p>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="old_password">Old password</label>
                        <input type="password" id="old_password" name="old_password" class="form-control pw-field" required placeholder="Enter old password">
                    </div>
                    <div class="form-group">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control pw-field" required placeholder="Enter new password">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control pw-field" required placeholder="Confirm new password">
                    </div>
                    <div class="check-row">
                        <input type="checkbox" id="mask" checked onchange="toggleMask()">
                        <label for="mask">Mask Entries</label>
                    </div>
                    <button type="submit" name="change_password" class="btn">Update Password</button>
                </form>
            </div>

        <?php elseif ($active_tab === 'permissions'): ?>
            <div class="permissions-container">
                <div class="user-select-list">
                    <h3>Select user</h3>
                    <?php if (!empty($users_list)): ?>
                        <?php foreach ($users_list as $u): ?>
                            <a href="account.php?tab=permissions&user_id=<?php echo $u['id']; ?>" class="user-item-btn <?php echo ($current_selected_user && $current_selected_user['id'] == $u['id']) ? 'selected' : ''; ?>">
                                <span class="avatar" aria-hidden="true"><?php echo htmlspecialchars(user_initial($u['username'])); ?></span>
                                <span class="user-meta">
                                    <span class="name"><?php echo htmlspecialchars($u['username']); ?></span>
                                    <span class="role-badge <?php echo role_class($u['role'] ?? 'Cashier'); ?>"><?php echo htmlspecialchars($u['role'] ?? 'Cashier'); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size: 13px; color: var(--text-muted);">No users found.</p>
                    <?php endif; ?>

                    <div class="delete-wrap">
                        <?php if ($current_selected_user): ?>
                            <a href="account.php?delete_user=<?php echo $current_selected_user['id']; ?>" class="btn btn-danger" onclick="return confirm('Delete this user?');">Delete Selected User</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h3 class="perm-title">
                        Assign role &amp; permissions for: <span><?php echo htmlspecialchars($current_selected_user['username'] ?? ''); ?></span>
                    </h3>

                    <!-- Role Selector Form -->
                    <?php if ($current_selected_user): ?>
                        <form method="POST" action="account.php?tab=permissions&user_id=<?php echo $current_selected_user['id']; ?>" class="role-form">
                            <input type="hidden" name="user_id" value="<?php echo $current_selected_user['id']; ?>">
                            <div class="grow">
                                <label for="role_select" style="font-size: 12px; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">User role group</label>
                                <select name="role" id="role_select" class="form-control">
                                    <option value="Administrator" <?php if(($current_selected_user['role'] ?? '') === 'Administrator') echo 'selected'; ?>>Administrator (Full System Access)</option>
                                    <option value="Manager" <?php if(($current_selected_user['role'] ?? '') === 'Manager') echo 'selected'; ?>>Manager (Inventory, Reports & Sales)</option>
                                    <option value="Cashier" <?php if(($current_selected_user['role'] ?? '') === 'Cashier' || empty($current_selected_user['role'])) echo 'selected'; ?>>Cashier (POS & Sales Only)</option>
                                </select>
                            </div>
                            <button type="submit" name="update_role" class="btn">Save Role</button>
                        </form>
                    <?php endif; ?>

                    <h4 class="section-label">Active role permissions breakdown</h4>
                    <div class="permissions-grid-cols">
                        <?php
                        $user_role = $current_selected_user['role'] ?? 'Cashier';

                        // Define preset role permissions
                        $permissions_list = [
                                'View POS (Sell Out)' => true,
                                'View Setup' => ($user_role === 'Administrator'),
                                'Alter Approved Invoice' => ($user_role === 'Administrator'),
                                'Initiate Sale' => true,
                                'View Settings' => ($user_role === 'Administrator'),
                                'Reverse Uploaded Invoice' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Cancel Initiated Sale' => true,
                                'View Users' => ($user_role === 'Administrator'),
                                'Make Supplier Payments' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Process Initiated Sale' => true,
                                'Add Users' => ($user_role === 'Administrator'),
                                'Delete Supplier Payments' => ($user_role === 'Administrator'),
                                'View Processed Sales' => true,
                                'View / Edit User Permissions' => ($user_role === 'Administrator'),
                                'View (Credit/WS) Clients' => true,
                                'Reverse Processed Sales' => ($user_role === 'Administrator'),
                                'View User Logs' => ($user_role === 'Administrator'),
                                'Add (Credit/WS) Clients' => true,
                                'View Reports' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Delete User Logs' => ($user_role === 'Administrator'),
                                'Delete (Credit/WS) Clients' => ($user_role === 'Administrator'),
                                'View Stock Page' => true,
                                'Change Own Password' => true,
                                'Sell To (Credit/WS) Clients' => true,
                                'Create New Stock' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'View Suppliers' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'View Accounts' => ($user_role === 'Administrator'),
                                'Delete Stock' => ($user_role === 'Administrator'),
                                'Create Suppliers' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Edit Accounts' => ($user_role === 'Administrator'),
                                'Add Stock Quantity' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Create Invoice' => true,
                                'Edit Company Settings' => ($user_role === 'Administrator'),
                                'Subtract Stock Quantity' => true,
                                'Approve Invoice' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Reverse Dates' => ($user_role === 'Administrator'),
                                'Edit Stock Name' => ($user_role === 'Administrator'),
                                'Upload Invoice' => true,
                                'Stock Special Actions' => ($user_role === 'Administrator'),
                                'Edit Stock Prices' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Edit Unapproved Invoice' => true,
                                '' => false,
                                'Other Stock Updates' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                'Delete Unapproved Invoice' => ($user_role === 'Administrator' || $user_role === 'Manager'),
                                '' => false
                        ];

                        foreach ($permissions_list as $perm => $is_granted):
                            if (empty($perm)) {
                                echo '<div></div>';
                                continue;
                            }
                            ?>
                            <label class="permission-checkbox-group<?php echo $is_granted ? '' : ' denied'; ?>">
                                <input type="checkbox" <?php echo $is_granted ? 'checked' : ''; ?> disabled>
                                <span><?php echo htmlspecialchars($perm); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($active_tab === 'logs'): ?>
            <div class="card log-card">
                <h2>System User Activity Logs</h2>
                <table>
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Action Performed</th>
                        <th>Timestamp</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><?php echo htmlspecialchars($_SESSION['username']); ?></td>
                        <td>Viewed Account &amp; Permissions Management</td>
                        <td class="when"><?php echo date('Y-m-d H:i:s'); ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleMask() {
        const passFields = document.querySelectorAll('.pw-field');
        const maskCheckbox = document.getElementById('mask');
        passFields.forEach(field => {
            field.type = maskCheckbox.checked ? 'password' : 'text';
        });
    }
</script>
</body>
</html>