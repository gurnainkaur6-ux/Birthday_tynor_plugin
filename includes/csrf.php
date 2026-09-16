<?php
/**
 * csrf.php — Lightweight CSRF token helpers.
 *
 * Usage in a form:
 *   echo csrf_field();
 *
 * Usage to verify on POST:
 *   if (!csrf_verify()) { die('CSRF mismatch'); }
 */

// Make sure the session is already open (config.php starts it)
require_once __DIR__ . '/../config/config.php';

/**
 * Generate (or reuse) a CSRF token for the current session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Return a hidden <input> carrying the current token.
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify that the POST token matches the session token.
 */
function csrf_verify(): bool
{
    $posted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (empty($posted) || empty($stored)) {
        return false;
    }

    return hash_equals($stored, $posted);
}