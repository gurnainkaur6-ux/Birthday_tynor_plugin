<?php
/**
 * Create or update a portal user from the command line.
 *
 *   php scripts/create_Admin.php "Jane Doe" jane@company.com "StrongPassword123!" [role]
 *
 * role is optional and one of: SuperAdmin (default) | Admin | Viewer
 * Writes to the `users` table (the table the login actually reads).
 */
require_once __DIR__ . '/../config/database.php';   // provides $conn on DB_NAME

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from the command line.\n");
}

[$scriptName, $fullName, $email, $plainPassword, $role] = array_pad($argv, 5, null);

if (!$fullName || !$email || !$plainPassword) {
    die("Usage: php scripts/create_Admin.php \"Full Name\" name@company.com \"Password\" [SuperAdmin|Admin|Viewer]\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid email address.\n");
}
if (strlen($plainPassword) < 8) {
    die("Password must be at least 8 characters.\n");
}

$role = in_array($role, ['SuperAdmin', 'Admin', 'Viewer'], true) ? $role : 'SuperAdmin';
$hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

try {
    $stmt = $conn->prepare(
        'INSERT INTO users (full_name, email, password, role, is_active)
         VALUES (:full_name, :email, :password, :role, 1)
         ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name), password = VALUES(password),
            role = VALUES(role), is_active = 1'
    );
    $stmt->execute([
        'full_name' => $fullName,
        'email'     => $email,
        'password'  => $hashedPassword,
        'role'      => $role,
    ]);
    echo "User '{$email}' created/updated with role {$role}.\n";
} catch (PDOException $e) {
    die('Failed to create user: ' . $e->getMessage() . "\n");
}
