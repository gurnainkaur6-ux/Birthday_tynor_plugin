<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $conn->prepare('SELECT user_id, email, full_name, is_active FROM users LIMIT 5');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo "No users found or query returned empty.\n";
    } else {
        echo "Users sample:\n";
        foreach ($rows as $r) {
            echo sprintf("- id=%s email=%s name=%s active=%s\n", $r['user_id'], $r['email'], $r['full_name'], $r['is_active']);
        }
    }
} catch (PDOException $e) {
    echo "DB error: " . $e->getMessage() . "\n";
}