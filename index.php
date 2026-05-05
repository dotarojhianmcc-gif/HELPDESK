<?php
// index.php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: user.php');
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $isModalLogin = isset($_POST['modal_login']) && $_POST['modal_login'] === '1';

    $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Support legacy user2 login by mapping it to the first viewer account.
    if (!$user && $username === 'user2' && $password === 'user2') {
        $viewerStmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE role = 'viewer' ORDER BY id ASC LIMIT 1");
        $viewerStmt->execute();
        $viewerUser = $viewerStmt->fetch();
        if ($viewerUser) {
            $user = [
                'id' => $viewerUser['id'],
                'username' => 'user2',
                'password' => null,
                'role' => 'viewer'
            ];
        }
    }

    if ($user && ($user['password'] === null || password_verify($password, $user['password']))) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        $logStmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
        $logStmt->execute([$user['id'], 'login', 'User logged in']);

        if ($user['role'] == 'admin') {
            header('Location: admin.php');
        } else {
            header('Location: user.php');
        }
        exit;
    } else {
        if ($isModalLogin) {
            $query = http_build_query([
                'login_error' => 1,
                'login_username' => $username,
            ]);
            header('Location: user.php?' . $query);
            exit;
        }
        $error = '❌ Invalid username or password!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Support HelpDesk</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg?v=20260408e">
    <link rel="shortcut icon" href="logo.svg?v=20260408e">
    <link rel="stylesheet" href="auth.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <img src="logo.svg?v=20260408e" alt="HelpDesk logo">
                </div>

                <div class="login-header">
                    <h1>Support HelpDesk</h1>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" class="login-form" id="loginForm">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Enter username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter password" required>
                    </div>

                    <button type="submit" class="btn-login">Login</button>
                </form>
                <div style="text-align:center; margin-top:16px;">
                    <a href="user.php" style="color:#2563eb; font-size:0.9rem; text-decoration:none;">Browse Help Center as Viewer</a>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const loginForm = document.getElementById('loginForm');
            if (!loginForm) {
                return;
            }

            loginForm.addEventListener('submit', function () {
                sessionStorage.removeItem('helpdeskActivePage');
                sessionStorage.removeItem('helpdeskNextPage');
                sessionStorage.removeItem('helpdeskSelectedTopic');
            });
        })();
    </script>
</body>
</html>