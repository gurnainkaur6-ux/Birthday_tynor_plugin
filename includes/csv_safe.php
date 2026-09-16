<?php
/**
 * csv_safe.php — neutralise CSV formula injection.
 *
 * Spreadsheet apps execute a cell that begins with = + - @ (or tab/CR) as a
 * formula. A malicious employee name like  =HYPERLINK(...)  could run when an
 * admin opens an export. Prefix such cells with a single quote so they are
 * always treated as text. Applied to every exported cell.
 */
if (!function_exists('csv_safe_cell')) {
    function csv_safe_cell($value): string
    {
        $s = (string) $value;
        if ($s === '') {
            return $s;
        }
        // Leading dangerous character → prefix with a single quote.
        if (preg_match('/^[=\-+@\t\r]/', $s)) {
            return "'" . $s;
        }
        return $s;
    }
}