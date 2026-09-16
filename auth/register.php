<?php
/**
 * register.php — governed self-registration.
 *
 * A visitor may request an account. The account is created with the LEAST
 * privileged role (Viewer) and a 'pending' status, and is INACTIVE until an
 * administrator approves it from Dashboard → Users. The role is never taken
 * from the form (no privilege escalation), the password is hashed with
 * password_hash(), and the request is CSRF-protected and audited.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$errors  = [];
$success = '';
$old     = ['full_name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session mismatch. Please refresh and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');
        $old['full_name'] = $fullName;
        $old['email']     = $email;

        // Server-side validation (authoritative).
        if ($fullName === '' || mb_strlen($fullName) < 2)          $errors[] = 'Please enter your full name.';
        if (preg_match('/\d/u', $fullName))                        $errors[] = 'Name cannot contain numbers.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))            $errors[] = 'Please enter a valid email address.';
        if (mb_strlen($email) > 150)                               $errors[] = 'Email address is too long.';
        if (strlen($pass) < 8)                                     $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $confirm)                                    $errors[] = 'Passwords do not match.';

        if (!$errors) {
            try {
                // Duplicate email → generic message (limits account enumeration).
                $chk = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
                $chk->execute([$email]);
                if ($chk->fetchColumn()) {
                    // Don't reveal whether it's active/pending — keep it generic.
                    $success = 'Thanks. If this email can be registered, an administrator will review your request.';
                } else {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    // Role is FORCED to the least-privileged role; status pending; inactive.
                    $conn->prepare(
                        "INSERT INTO users (full_name, email, password, role, is_active, status)
                         VALUES (?, ?, ?, 'Viewer', 0, 'pending')"
                    )->execute([$fullName, $email, $hash]);

                    $newId = (int) $conn->lastInsertId();
                    require_once __DIR__ . '/../includes/audit.php';
                    // Audit WITHOUT any secret; performed_by is the new user themselves.
                    audit_log($conn, 'INSERT', 'users', (string) $newId, null,
                        ['full_name' => $fullName, 'email' => $email, 'role' => 'Viewer', 'status' => 'pending']);

                    $success = 'Registration received. An administrator will review your request, '
                             . 'and you can sign in once your account is approved.';
                    $old = ['full_name' => '', 'email' => '']; // clear the form on success
                }
            } catch (Throwable $e) {
                error_log('register error: ' . $e->getMessage());
                $errors[] = 'A server error occurred. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Request an account | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-logo">Birthday Communications</div>
    <p class="subtitle"><?= htmlspecialchars(COMPANY_NAME) ?> — HR Portal</p>

    <?php if ($success): ?>
      <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div>
      <p class="auth-hint" style="text-align:center;margin-top:1rem">
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>/auth/login.php">&larr; Back to sign in</a>
      </p>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert alert-error" style="margin-bottom:1rem">
          <ul style="margin:0 0 0 1.1rem;padding:0;">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <p class="auth-hint" style="margin-bottom:1rem;color:#475569;font-size:.9rem;">
        Request access to the HR birthday portal. New accounts are reviewed by an
        administrator before they can sign in.
      </p>

      <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>">
        <?= csrf_field() ?>
        <label for="reg-name">Full name</label>
        <input type="text" id="reg-name" name="full_name" required autofocus
               value="<?= htmlspecialchars($old['full_name'], ENT_QUOTES) ?>" placeholder="Jane Doe">

        <label for="reg-email">Email address</label>
        <input type="email" id="reg-email" name="email" required
               value="<?= htmlspecialchars($old['email'], ENT_QUOTES) ?>" placeholder="you@company.com">

        <label for="reg-pass">Password</label>
        <input type="password" id="reg-pass" name="password" required minlength="8" placeholder="At least 8 characters">

        <label for="reg-confirm">Confirm password</label>
        <input type="password" id="reg-confirm" name="confirm_password" required minlength="8" placeholder="Re-enter your password">

        <button type="submit" class="btn-primary" style="margin-top:1rem;width:100%">Request account &rarr;</button>
      </form>

      <p class="auth-hint" style="margin-top:1.5rem;text-align:center;">
        Already have an account?
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>/auth/login.php">Sign in</a>
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
