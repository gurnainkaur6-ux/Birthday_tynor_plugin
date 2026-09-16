<?php
/**
 * config.php — Core application bootstrap.
 * Loads .env if present, starts session, defines URL constants.
 */

// HIGH FIX #22: Include security headers configuration FIRST
// This must be included before any output to set HTTP security headers
require_once __DIR__ . '/security_headers.php';

// ── Load .env if it exists ────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key); $val = trim($val, " \"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// ── Session (secure cookie flags before start) ────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // HTTPS either directly or via a trusted reverse proxy.
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (getenv('TRUST_PROXY') === '1' && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
    ini_set('session.use_strict_mode', '1');
    $cp = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cp['path'] ?: '/',
        'domain'   => $cp['domain'] ?? '',
        'httponly' => true,
        'secure'   => $secureCookie,   // only sent over HTTPS in production
        'samesite' => 'Lax',           // mitigates CSRF; use 'None';Secure if iframe-embedded
    ]);
    session_start();
}

// ── Error reporting (never leak errors to the browser in production) ──
$__debug = in_array(strtolower((string) getenv('APP_DEBUG')), ['1', 'true', 'on', 'yes'], true);
$__prod  = strtolower((string) getenv('APP_ENV')) === 'production';
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', ($__debug && !$__prod) ? '1' : '0');

// ── Base URL (explicit override preferred; else auto-detect) ──────
// Deployment may be at web root or any subfolder (/tynor, /erp/plugins/…),
// and possibly behind a reverse proxy that terminates HTTPS. Set
// PLUGIN_BASE_URL (or legacy BASE_URL) in .env for CLI/cron and to remove all
// ambiguity in production; auto-detection is only a development convenience.
if (!defined('BASE_URL')) {
    $base = getenv('PLUGIN_BASE_URL') ?: (getenv('BASE_URL') ?: '');
    if (!$base) {
        // Scheme: honour a trusted proxy's X-Forwarded-Proto only when opted in.
        $trustProxy = getenv('TRUST_PROXY') === '1';
        $fwdProto   = $trustProxy ? strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0])) : '';
        if ($fwdProto === 'https' || $fwdProto === 'http') {
            $scheme = $fwdProto;
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }

        // Host: prevent host-header injection — accept only sane characters,
        // else fall back to localhost. (X-Forwarded-Host only when trusted.)
        $rawHost = $trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])
            ? explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', trim($rawHost)) ? trim($rawHost) : 'localhost';

        $parts = explode('/', trim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'));
        $subs  = ['auth', 'dashboard', 'setup', 'cron', 'api', 'public'];
        if (in_array(end($parts), $subs, true)) array_pop($parts);
        $base = $scheme . '://' . $host . '/' . implode('/', array_filter($parts));
    }
    define('BASE_URL', rtrim($base, '/'));
}

if (!defined('ASSETS_URL')) {
    // Defensive: no matter what BASE_URL resolves to on a given server
    // (auto-detected, from .env, behind a proxy, etc.), never let this end
    // up double-appending "/assets" — strip any trailing /assets first,
    // then add exactly one.
    $__baseForAssets = preg_replace('#/assets/?$#i', '', BASE_URL);
    define('ASSETS_URL', rtrim($__baseForAssets, '/') . '/assets');
}
if (!defined('APP_ROOT'))   define('APP_ROOT',   dirname(__DIR__));
if (!defined('COMPANY_NAME')) define('COMPANY_NAME', getenv('COMPANY_NAME') ?: 'Tynor Orthotics');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.3.0');   // single source of truth

/**
 * photo_url() — turn a stored photo path into a browser-usable URL.
 * Stored paths are kept RELATIVE (e.g. "img/emp1.jpg", "uploads/x.jpg") so the
 * app is portable across hosts/domains. Absolute URLs are returned unchanged.
 */
if (!function_exists('photo_url')) {
    function photo_url(?string $path): string {
        $path = trim((string) $path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;   // already absolute
        return BASE_URL . '/' . ltrim($path, '/');
    }
}

/**
 * is_individual_photo() — true only for a genuinely uploaded, per-employee
 * photo (stored under uploads/) or an explicit remote per-person URL. The
 * shipped stock placeholders (img/empN.jpg) are shared across many demo
 * employees, so they are NOT treated as individual photos — those fall back to
 * a consistent initials avatar so the same face is never shown for two people.
 */
if (!function_exists('is_individual_photo')) {
    function is_individual_photo(?string $path): bool {
        $path = trim((string) $path);
        if ($path === '') return false;
        if (preg_match('#^https?://#i', $path)) return true;      // explicit remote URL
        return stripos(ltrim($path, '/'), 'uploads/') === 0;      // genuine upload
    }
}

/**
 * employee_photo_url() — browser URL for a genuine individual photo WITH a
 * cache-busting token (?v=mtime) so a replaced photo shows immediately and an
 * old browser-cached image is never displayed. Returns '' when the employee has
 * no individual photo (caller should render the initials avatar instead).
 */
if (!function_exists('employee_photo_url')) {
    function employee_photo_url(?string $path): string {
        if (!is_individual_photo($path)) return '';
        $url = photo_url($path);
        $rel = ltrim((string) $path, '/');
        $abs = defined('APP_ROOT') ? APP_ROOT . '/' . $rel : '';
        if ($abs !== '' && is_file($abs)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($abs);
        }
        return $url;
    }
}

/** employee_initials() — 1–2 letter initials for the avatar fallback. */
if (!function_exists('employee_initials')) {
    function employee_initials(?string $name): string {
        $name = trim((string) $name);
        if ($name === '') return '?';
        $p = preg_split('/\s+/u', $name);
        $a = mb_substr($p[0] ?? '', 0, 1, 'UTF-8');
        $b = count($p) > 1 ? mb_substr(end($p), 0, 1, 'UTF-8') : '';
        $out = mb_strtoupper($a . $b, 'UTF-8');
        return $out !== '' ? $out : '?';
    }
}

/**
 * employee_avatar_html() — round avatar for admin/list views: the employee's
 * own uploaded photo when present (cache-busted), otherwise a consistent
 * initials circle whose colour is derived deterministically from the name (so
 * each person keeps a stable badge). Never renders a shared stock image, which
 * is what previously caused the same face to appear for many employees.
 */
if (!function_exists('employee_avatar_html')) {
    function employee_avatar_html(?string $name, ?string $photoPath, int $size = 32): string {
        $src = employee_photo_url($photoPath);
        if ($src !== '') {
            return '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" width="' . $size . '" height="' . $size . '" '
                 . 'loading="lazy" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;'
                 . 'border:1px solid #e2e8f0;background:#f1f5f9;vertical-align:middle;">';
        }
        $init = employee_initials($name);
        $hue = 0; $s = (string) $name;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) { $hue = ($hue * 31 + ord($s[$i])) % 360; }
        $fs = (int) round($size * 0.42);
        return '<span aria-hidden="true" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;'
             . 'display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;vertical-align:middle;'
             . 'background:hsl(' . $hue . ',55%,45%);color:#fff;font-weight:700;font-size:' . $fs . 'px;'
             . 'font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars($init, ENT_QUOTES) . '</span>';
    }
}

/**
 * Session idle-timeout policy (single source of truth for server + client).
 *   SESSION_IDLE_TIMEOUT — seconds of inactivity before the session is expired.
 *   SESSION_IDLE_WARNING — seconds before expiry that the client warns the user.
 * Enforced server-side in includes/auth_check.php and dashboard/ping.php; the
 * client-side countdown in includes/topbar.php mirrors these values.
 */
if (!defined('SESSION_IDLE_TIMEOUT')) define('SESSION_IDLE_TIMEOUT', 300); // 5 minutes
if (!defined('SESSION_IDLE_WARNING')) define('SESSION_IDLE_WARNING', 60);  // warn 60s before
