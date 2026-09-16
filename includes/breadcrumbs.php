<?php
/**
 * breadcrumbs.php — render an accessible breadcrumb trail.
 *
 * Usage (before including topbar.php):
 *   $BREADCRUMBS = [
 *       ['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'],
 *       ['label' => 'Employees', 'url' => BASE_URL . '/dashboard/employees.php'],
 *       ['label' => 'Edit employee'],   // last item = current page (no link)
 *   ];
 *
 * Breadcrumbs supplement — never replace — the global "Back to ERP" control.
 */
require_once __DIR__ . '/../config/config.php';

if (!function_exists('render_breadcrumbs')) {
    function render_breadcrumbs(array $trail): string
    {
        if (empty($trail)) {
            return '';
        }
        $last  = count($trail) - 1;
        $items = [];
        foreach (array_values($trail) as $i => $crumb) {
            $label = htmlspecialchars((string) ($crumb['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url   = (string) ($crumb['url'] ?? '');
            if ($i === $last || $url === '') {
                $items[] = '<span class="crumb crumb-current" aria-current="page">' . $label . '</span>';
            } else {
                $items[] = '<a class="crumb" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
            }
        }
        return '<nav class="breadcrumbs" aria-label="Breadcrumb">'
             . implode('<span class="crumb-sep" aria-hidden="true">&rsaquo;</span>', $items)
             . '</nav>';
    }
}
