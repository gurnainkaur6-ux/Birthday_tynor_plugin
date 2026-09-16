<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/dashboard/index.php'); exit;
}

$error   = '';
$success = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

// Friendly notice after an idle-timeout logout.
if (isset($_GET['expired'])) {
    $error = 'Your session expired due to inactivity. Please sign in again.';
}

// Brute-force throttle: block after too many recent failures (EMAIL-level and IP-level)
const LOGIN_MAX_FAILS_EMAIL = 5;
const LOGIN_MAX_FAILS_IP = 20;
const LOGIN_WINDOW_MINS = 15;

function record_login_attempt(PDO $conn, string $email, string $ip, bool $ok): void {
    try {
        $conn->prepare("INSERT INTO login_attempts (email, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())")
             ->execute([mb_substr($email, 0, 150), mb_substr($ip, 0, 45), $ok ? 1 : 0]);
    } catch (Throwable $e) {
        // Auditing must never block login, but log the error
        error_log('Failed to record login attempt: ' . $e->getMessage());
    }
}

/**
 * Validate that session role matches database role (prevent privilege escalation via session tampering)
 */
function validate_user_db_state(PDO $conn, int $userId, string $sessionRole, string $sessionName): bool {
    try {
        $stmt = $conn->prepare(
            "SELECT role, is_active, status FROM users WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            error_log("Session validation failed: User ID $userId not found in database");
            return false;
        }
        
        // Verify role matches and user is still active
        if ($user['role'] !== $sessionRole) {
            error_log("Session role mismatch: User $userId session role '$sessionRole' != DB role '{$user['role']}'");
            return false;
        }
        
        if (!$user['is_active']) {
            error_log("Session validation failed: User $userId is inactive");
            return false;
        }
        
        if ($user['status'] !== 'approved') {
            error_log("Session validation failed: User $userId status is '{$user['status']}'");
            return false;
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('Session validation DB error: ' . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        error_log("Login CSRF token verification failed from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $error = 'Session mismatch. Please refresh and try again.';
    } else {
        $email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $rawEmail = trim((string)($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!$email || $password === '') {
            $error = 'Please enter a valid email and password.';
        } else {
            // Check BOTH email-level and IP-level rate limiting
            $emailBlocked = false;
            $ipBlocked = false;
            
            try {
                $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINS * 60);
                
                // Email-level throttle
                $st = $conn->prepare(
                    "SELECT COUNT(*) FROM login_attempts
                     WHERE email = ? AND success = 0 AND attempted_at > ?"
                );
                $st->execute([$email, $since]);
                $emailBlocked = ((int)$st->fetchColumn() >= LOGIN_MAX_FAILS_EMAIL);
                
                // IP-level throttle (prevent distributed attacks)
                $st = $conn->prepare(
                    "SELECT COUNT(*) FROM login_attempts
                     WHERE ip_address = ? AND success = 0 AND attempted_at > ?"
                );
                $st->execute([$ip, $since]);
                $ipBlocked = ((int)$st->fetchColumn() >= LOGIN_MAX_FAILS_IP);
                
                if ($emailBlocked || $ipBlocked) {
                    error_log("Login throttle triggered: Email=$emailBlocked, IP=$ipBlocked for email=$email, IP=$ip");
                }
            } catch (Throwable $e) {
                error_log('Login throttle check error: ' . $e->getMessage());
                $emailBlocked = false;
                $ipBlocked = false;
            }

            if ($emailBlocked || $ipBlocked) {
                $error = 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINS . ' minutes and try again.';
                record_login_attempt($conn, $email, $ip, false); // Still record this attempt
            } else {
                try {
                    $stmt = $conn->prepare(
                        "SELECT user_id AS id, full_name, password, role, is_active, status
                         FROM users WHERE email = ? AND is_active = 1 LIMIT 1"
                    );
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password'])) {
                        $status = $user['status'] ?? 'approved';
                        
                        if ($status === 'approved') {
                            // SUCCESS: Create new session
                            record_login_attempt($conn, $email, $ip, true);
                            session_regenerate_id(true);
                            
                            $_SESSION['admin_id']      = (int)$user['id'];
                            $_SESSION['admin_name']    = $user['full_name'];
                            $_SESSION['admin_role']    = $user['role'];
                            $_SESSION['last_activity'] = time();
                            $_SESSION['created_at']    = time();
                            $_SESSION['session_hash']  = hash('sha256', session_id() . $_SERVER['HTTP_USER_AGENT'] ?? '');
                            
                            require_once __DIR__ . '/../includes/audit.php';
                            audit_log($conn, 'LOGIN', 'users', (string)$user['id']);
                            
                            header('Location: ' . BASE_URL . '/dashboard/index.php');
                            exit;
                        }

                        // Credentials are valid but the account state does not permit login
                        // This is NOT a failed authentication, so don't increment throttle
                        if ($status === 'pending') {
                            $error = 'Your account is awaiting administrator approval.';
                        } elseif ($status === 'rejected') {
                            $error = 'Your account request was not approved. Please contact your administrator.';
                        } else {
                            $error = 'Your account is disabled. Please contact your administrator.';
                        }
                    } else {
                        // Failed authentication — record and show generic message (prevent user enumeration)
                        record_login_attempt($conn, $email, $ip, false);
                        $error = 'Invalid email or password.';
                    }
                } catch (PDOException $e) {
                    error_log('Login query error: ' . $e->getMessage());
                    record_login_attempt($conn, $email, $ip, false);
                    $error = 'A server error occurred. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Sign in | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES, 'UTF-8') ?>/css/styles.css">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-logo">Birthday Communications</div>
    <p class="subtitle"><?= htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') ?> — HR Portal</p>

    <?php if ($error): ?>
      <div class="alert alert-error" role="alert" style="margin-bottom:1rem"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success" role="alert" style="margin-bottom:1rem"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
      <?= csrf_field() ?>
      
      <label for="login-email">Email address</label>
      <input type="email" id="login-email" name="email" required autofocus autocomplete="email"
             placeholder="you@company.com"
             value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
             maxlength="255">

      <label for="login-password">Password</label>
      <input type="password" id="login-password" name="password" required autocomplete="current-password"
             placeholder="••••••••" maxlength="128">

      <button type="submit" class="btn-primary" style="margin-top:1rem;width:100%">Sign in &rarr;</button>
    </form>

    <p class="auth-hint" style="margin-top:1.5rem">
      Need an account? <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/auth/register.php">Request one</a> — an administrator reviews every request before access is granted.
    </p>
  </div>
</body>
</html>
