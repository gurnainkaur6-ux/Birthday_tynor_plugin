<?php
/**
 * database.php — MySQL PDO connection.
 * All credentials come from environment variables (.env file or system env).
 */
require_once __DIR__ . '/config.php';

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'birthday_system';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    // Connect to MySQL server (without specifying database yet)
    $conn = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Automatically create the database if it doesn't exist
    $conn->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->exec("USE `{$dbName}`");
} catch (PDOException $e) {
    // Log full error server-side only - NEVER expose details to users
    error_log('CRITICAL: Database Connection Failed');
    error_log('Error Code: ' . $e->getCode());
    error_log('Error Message: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    
    // Generate error ID for support reference
    $errorId = strtoupper(bin2hex(random_bytes(6)));
    error_log("Error ID: {$errorId}");
    
    http_response_code(503);
    // Generic error message - NO implementation details, NO file paths, NO credentials hints
    die('<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Service Unavailable</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 500px;
            text-align: center;
        }
        h1 {
            color: #d32f2f;
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        p {
            color: #555;
            line-height: 1.6;
            margin: 15px 0;
        }
        .error-ref {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            margin-top: 20px;
            font-family: monospace;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ Service Unavailable</h1>
        <p>We are experiencing technical difficulties at the moment.</p>
        <p style="font-size: 14px; color: #888;">Please try again later or contact support if the problem persists.</p>
        <div class="error-ref">Support Reference: ' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</div>
    </div>
</body>
</html>');
}