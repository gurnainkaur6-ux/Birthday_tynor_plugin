<?php
/**
 * test_safe_redirect.php — open-redirect protection for ERP navigation.
 */
require_once __DIR__ . '/../config/erp.php';

function run_safe_redirect_tests(): void
{
    t_section('ERP safe-URL validation (open-redirect protection)');

    $cases = [
        // [url, allowedHosts, requireHttps, expected]
        ['https://company.com',      [],               true,  true],
        ['http://company.com',       [],               true,  false], // http rejected when https required
        ['javascript:alert(1)',      [],               false, false], // dangerous scheme
        ['//evil.com',               [],               false, false], // protocol-relative, no scheme
        ['https://evil.com',         ['company.com'],  true,  false], // not allowlisted
        ['https://app.company.com',  ['.company.com'], true,  true],  // subdomain via leading dot
        ['https://localhost',        [],               true,  false], // localhost blocked in prod
        ["https://c.com/\r\nSet",    [],               true,  false], // CRLF header injection
        ['',                         [],               false, false], // empty
        ['http://internal-erp',      [],               false, true],  // http ok when https not required (dev)
    ];
    foreach ($cases as $i => $c) {
        t_eq($c[3], erp_is_safe_url($c[0], $c[1], $c[2]), "safe-url case #{$i}: " . ($c[0] === '' ? '(empty)' : $c[0]));
    }

    // erp_safe_return_url must never hand back an untrusted host.
    putenv('ERP_ALLOWED_HOSTS=company.com');
    $ret = erp_safe_return_url('https://evil.com/steal');
    t_ok($ret !== 'https://evil.com/steal', 'untrusted return URL is rejected (falls back to a safe target)');
    putenv('ERP_ALLOWED_HOSTS');
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/lib.php';
    run_safe_redirect_tests();
    exit(t_summary());
}
