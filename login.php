<?php
global $conn;
session_start();
include 'db.php';

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Verify password (supports hashed or plain text depending on your setup, using password_verify or direct check)
            if (password_verify($password, $row['password']) || $password === $row['password']) {
                $_SESSION['username'] = $row['username'];
                $_SESSION['user_id'] = $row['id'];
                header("Location: dashboard.php");
                exit();
            } else {
                $error_message = "Invalid username or password.";
            }
        } else {
            $error_message = "Invalid username or password.";
        }
        $stmt->close();
    } else {
        $error_message = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="icon.png">
    <title>Alkhair Ghana Limited  - LoginPage


    </title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-color: #f1f5f9;
            --surface: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .login-card h2 {
            font-size: 22px;
            color: var(--primary);
            margin-bottom: 6px;
            text-align: center;
        }

        .login-card p {
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: var(--primary);
            color: white;
            transition: background 0.2s;
            margin-top: 10px;
        }

        .btn:hover {
            background: var(--primary-hover);
        }

        .alert {
            padding: 10px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 16px;
            background: #fee2e2;
            color: #991b1b;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="login-card">
    <h2>Alkhairi Ghana SALES SERVICE </h2>
    <p>Sign in to your account</p>

    <?php if (!empty($error_message)): ?>
        <div class="alert"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label>Username</label>
            <label>
                <input type="text" name="username" class="form-control" required autofocus>
            </label>
        </div>

        <div class="form-group">
            <label>Password</label>
            <label>
                <input type="password" name="password" class="form-control" required>
            </label>
        </div>

        <button type="submit" class="btn">Login</button>
    </form>
</div>

</body>
</html>
