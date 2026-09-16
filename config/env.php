<?php
/**
 * Minimal .env loader.
 * Avoids committing secrets into version-controlled PHP files.
 */

function loadEnv(string $path): void
{
    // Resolve the absolute path to eliminate any weird "/../" issues in Windows
    $realPath = realpath($path);

    if (!$realPath || !file_exists($realPath)) {
        // Fail loudly in logs, not to the browser.
        error_log("Missing .env file at target path: " . ($realPath ?: $path));
        return;
    }

    $lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Strip inline comments when they are not inside quotes.
        if (preg_match('/^("[^"]*"|\'[^\']*\')$/', $value) !== 1) {
            $value = preg_replace('/\s+#.*$/', '', $value);
            $value = preg_replace('/\s+;.*$/', '', $value);
        }

        $value = trim($value);

        // Strip surrounding quotes if present.
        $value = trim($value, "\"'");

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Dynamically target the .env file one level above the config directory
loadEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');