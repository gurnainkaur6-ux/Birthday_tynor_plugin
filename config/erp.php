<?php
/**
 * erp.php — Host-ERP integration configuration & safe navigation helpers.
 *
 * The plugin runs inside / alongside a company ERP. This file centralises:
 *   • where "Back to ERP" points   (ERP_BASE_URL)
 *   • the plugin's own base URL     (PLUGIN_BASE_URL, falls back to BASE_URL)
 *   • an allowlist of trusted hosts (ERP_ALLOWED_HOSTS) to prevent open
 *     redirects — an arbitrary URL is NEVER used as a return target.
 *
 * Security rules enforced here:
 *   • Only http/https absolute URLs are accepted.
 *   • In production (APP_ENV=production) the ERP URL must be https and must
 *     not point at localhost.
 *   • Any configured return target must match the host allowlist (when set).
 *   • Values are validated once and cached; callers get a safe string or ''.
 *
 * See docs/ERP_INTEGRATION.md for the full integration contract.
 */

require_once __DIR__ . '/config.php';

if (!defined('ERP_HELPERS_LOADED')) {
    define('ERP_HELPERS_LOADED', true);

    /** Current environment: local | testing | staging | production. */
    function app_env(): string
    {
        $env = strtolower(trim((string) getenv('APP_ENV')));
        $valid = ['local', 'testing', 'staging', 'production'];
        return in_array($env, $valid, true) ? $env : 'production'; // fail safe
    }

    /** Raw ERP integration config from the environment. */
    function erp_config(): array
    {
        $hosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) getenv('ERP_ALLOWED_HOSTS'))
        ), fn($h) => $h !== ''));

        return [
            'erp_url'       => trim((string) getenv('ERP_BASE_URL')),
            'plugin_url'    => trim((string) getenv('PLUGIN_BASE_URL'))
                                ?: (defined('BASE_URL') ? BASE_URL : ''),
            'allowed_hosts' => $hosts,
            'env'           => app_env(),
        ];
    }

    /**
     * Is $url a safe, absolute http(s) URL?
     *  - rejects empty, control chars (header injection), non-http schemes
     *  - optionally requires https
     *  - optionally requires the host to be in $allowedHosts (exact, or a
     *    leading-dot entry like ".company.com" to allow subdomains)
     */
    function erp_is_safe_url(string $url, array $allowedHosts = [], bool $requireHttps = false): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // No CR/LF/other control characters (defends header/redirect injection).
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        // Reject protocol-relative ("//evil.com") and scheme-relative tricks.
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if ($requireHttps && $scheme !== 'https') {
            return false;
        }
        $host = strtolower($parts['host']);
        // In production, never allow localhost/loopback as an ERP target.
        if ($requireHttps && (in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            return false;
        }
        if (!empty($allowedHosts)) {
            $ok = false;
            foreach ($allowedHosts as $h) {
                $h = strtolower(trim($h));
                if ($h === '') {
                    continue;
                }
                if ($h[0] === '.') {                       // ".company.com" → subdomains
                    if ($host === substr($h, 1) || str_ends_with($host, $h)) {
                        $ok = true;
                        break;
                    }
                } elseif ($host === $h) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * The validated "Back to ERP" URL, or '' when not configured / unsafe.
     * When '' the UI hides the control and System Health shows a warning.
     */
    function erp_back_url(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $c   = erp_config();
        $url = $c['erp_url'];
        if ($url === '') {
            return $cached = '';
        }
        $prod = ($c['env'] === 'production');
        if (erp_is_safe_url($url, $c['allowed_hosts'], $prod)) {
            return $cached = $url;
        }
        // Non-production may use plain http (e.g. an internal test ERP).
        if (!$prod && erp_is_safe_url($url, $c['allowed_hosts'], false)) {
            return $cached = $url;
        }
        error_log('ERP_BASE_URL is set but failed safe-URL validation: ' . $url);
        return $cached = '';
    }

    /** True when a usable ERP link is configured. */
    function erp_is_configured(): bool
    {
        return erp_back_url() !== '';
    }

    /** The plugin's own dashboard URL (safe fallback destination). */
    function plugin_dashboard_url(): string
    {
        $c = erp_config();
        $base = $c['plugin_url'] ?: (defined('BASE_URL') ? BASE_URL : '');
        return rtrim($base, '/') . '/dashboard/index.php';
    }

    /**
     * Validate a candidate return URL supplied by an integration (e.g. the ERP
     * passes ?return=...). Only accepted if it is safe AND host-allowlisted;
     * otherwise we fall back to the plugin dashboard. This prevents open
     * redirects from untrusted query parameters.
     */
    function erp_safe_return_url(?string $candidate): string
    {
        $candidate = trim((string) $candidate);
        $c = erp_config();
        if ($candidate !== '' && !empty($c['allowed_hosts'])
            && erp_is_safe_url($candidate, $c['allowed_hosts'], $c['env'] === 'production')) {
            return $candidate;
        }
        // Anything untrusted → the configured ERP home, else the plugin dashboard.
        return erp_back_url() ?: plugin_dashboard_url();
    }
}
