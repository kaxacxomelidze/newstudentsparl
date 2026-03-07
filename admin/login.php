<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/_ui.php';

/* ---------------- SESSION ---------------- */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* ---------------- SAFE LOG ---------------- */

function safe_record_admin_login_log(string $username, ?int $adminId, string $status, string $message): void
{
    try {
        if (function_exists('record_admin_login_log')) {
            record_admin_login_log($username, $adminId, $status, $message);
        }
    } catch (Throwable $e) {
        error_log('Login log failed: ' . $e->getMessage());
    }
}

/* ---------------- SIMPLE CAPTCHA (ADDITION) ---------------- */

function set_captcha(): void
{
    $a = random_int(1, 20);
    $b = random_int(1, 20);

    $_SESSION['captcha_q'] = "{$a} + {$b}";
    $_SESSION['captcha_expected'] = (string)($a + $b);
}

/* ---------------- LOGIN STATE ---------------- */

function init_login_state(): void
{
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
    }
    if (!isset($_SESSION['login_locked_until'])) {
        $_SESSION['login_locked_until'] = 0;
    }
}

init_login_state();

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod !== 'POST') {
    set_captcha();
} elseif (empty($_SESSION['captcha_q']) || empty($_SESSION['captcha_expected'])) {
    set_captcha();
}

/* ---------------- PREVENT LOOP ---------------- */

if (!empty($_SESSION['admin_id']) || !empty($_SESSION['admin_logged_in'])) {
    header('Location: /admin/news/index.php');
    exit;
}

/* ---------------- LOGIN ---------------- */

$error = '';
$maxAttempts = 5;
$lockTime = 300;

if ($requestMethod === 'POST') {

    try {

        csrf_verify();
        $now = time();

        if ((int)$_SESSION['login_locked_until'] > $now) {
            $remaining = $_SESSION['login_locked_until'] - $now;
            $error = "Too many attempts. Try again in {$remaining} seconds.";
        } else {

            $captcha  = trim((string)($_POST['captcha'] ?? ''));
            $expected = (string)($_SESSION['captcha_expected'] ?? '');

            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($username === '' || $password === '') {
                $error = 'Username and password required.';
            } elseif ($captcha === '' || !hash_equals($expected, $captcha)) {
                $error = 'Incorrect captcha.';
            } else {

                $pdo = db();
                $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($admin && password_verify($password, $admin['password_hash'])) {

                    session_regenerate_id(true);

                    $_SESSION['admin_id'] = (int)$admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['role'] = 'admin';

                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['login_locked_until'] = 0;

                    safe_record_admin_login_log($username, (int)$admin['id'], 'success', 'login success');

                    header('Location: /admin/news/index.php');
                    exit;
                }

                $error = 'Wrong username or password.';
            }
        }

    } catch (Throwable $e) {
        $error = 'Server error.';
    }

    if ($error !== '') {
        $_SESSION['login_attempts']++;
        if ($_SESSION['login_attempts'] >= $maxAttempts) {
            $_SESSION['login_locked_until'] = time() + $lockTime;
        }
        set_captcha();
    }
}

$captchaQ = $_SESSION['captcha_q'] ?? '';
$prefillUser = $_POST['username'] ?? '';
?>
<!doctype html>
<html lang="en">
<?php admin_head('Admin Login'); ?>
<body class="admin-body admin-login-page">

<form method="post" style="max-width:420px;margin:100px auto;background:#111;padding:35px;border-radius:14px;color:white;">
<h2>Admin Login</h2>

<?php if ($error !== ''): ?>
<div style="background:#300;padding:10px;margin-bottom:10px;">
<?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

<div>
<label>Username</label>
<input name="username" value="<?= htmlspecialchars($prefillUser) ?>" required>
</div>

<div>
<label>Password</label>
<input type="password" name="password" required>
</div>

<div style="margin-top:15px;">
<label>Solve: <strong><?= htmlspecialchars($captchaQ) ?></strong></label>
<input name="captcha" required>
</div>

<button type="submit" style="margin-top:20px;">Login</button>

</form>

</body>
</html>
