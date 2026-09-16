<?php
// csp-report.php
// Get the JSON data from the browser
$data = file_get_contents('php://input');

if ($data) {
    // Decode and log the violation to a file so you can see what is breaking
    $message = date('Y-m-d H:i:s') . " - Violation: " . $data . PHP_EOL;
    file_put_contents('csp_violations.log', $message, FILE_APPEND);
}

// Respond with 204 No Content (Standard for success)
http_response_code(204);
?>
<?php
// Add this at the very top of index.php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; report-uri /csp-report.php;");
?>