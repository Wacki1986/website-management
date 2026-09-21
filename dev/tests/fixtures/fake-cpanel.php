<?php

declare(strict_types=1);

/**
 * Falešný cPanel pro testy průvodce.
 *
 * Router pro `php -S`: odpovídá na `/execute/<Module>/<function>` ve tvaru
 * UAPI (`{status: 1|0, data, errors}`). Řídí se proměnnými prostředí:
 *
 * - FAKE_CPANEL_TOKEN — vyžadovaná hlavička `Authorization: cpanel user:TOKEN`
 * - FAKE_CPANEL_FAIL — „Module/function", které má selhat (status 0)
 * - FAKE_CPANEL_LOG — soubor, kam se zapisuje každé volání (na assertování)
 * - FAKE_CPANEL_SUBDOMAIN_EXISTS — addsubdomain hlásí „already exists"
 * - FAKE_CPANEL_DOCROOT — documentroot, který vrací DomainInfo::single_domain_data
 */

header('Content-Type: application/json; charset=UTF-8');

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// UAPI (/execute/Module/fn) i API2 (/json-api/cpanel?cpanel_jsonapi_module=…)
// — crontab žije jen v API2, přesně jako na skutečném cPanelu.
$api2 = str_ends_with($path, '/json-api/cpanel');

if ($api2) {
    $module = (string) ($_GET['cpanel_jsonapi_module'] ?? '');
    $function = (string) ($_GET['cpanel_jsonapi_func'] ?? '');
} elseif (preg_match('#/execute/([A-Za-z]+)/([a-z_]+)$#', $path, $matches) === 1) {
    [, $module, $function] = $matches;

    // Věrnost skutečnému cPanelu: Cron v UAPI neexistuje.
    if ($module === 'Cron') {
        echo json_encode(['status' => 0, 'errors' => [
            'Failed to load module "Cron": Can\'t locate Cpanel/API/Cron.pm in @INC',
        ]]);
        exit;
    }
} else {
    http_response_code(404);
    echo json_encode(['status' => 0, 'errors' => ['Neznámá cesta.']]);
    exit;
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$expected = getenv('FAKE_CPANEL_TOKEN') ?: '';

if ($expected !== '' && !str_ends_with($auth, ':' . $expected)) {
    http_response_code(401);
    echo json_encode(['status' => 0, 'errors' => ['Access denied.']]);
    exit;
}

$log = getenv('FAKE_CPANEL_LOG') ?: '';

if ($log !== '') {
    @file_put_contents(
        $log,
        $module . '/' . $function . ' ' . json_encode($_GET, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX,
    );
}

$failed = (getenv('FAKE_CPANEL_FAIL') ?: '') === $module . '/' . $function;

if ($api2) {
    echo $failed
        ? json_encode(['cpanelresult' => ['event' => ['result' => 1],
            'error' => 'Simulované selhání ' . $module . '::' . $function,
            'data' => [['status' => 0, 'statusmsg' => 'Simulované selhání']]]])
        : json_encode(['cpanelresult' => ['event' => ['result' => 1],
            'data' => [['status' => 1, 'statusmsg' => 'ok']]]]);
    exit;
}

if ($failed) {
    echo json_encode(['status' => 0, 'errors' => ['(XID abc123) Simulované selhání ' . $module . '::' . $function]]);
    exit;
}

// Věrnost skutečnému cPanelu: subdoména, která na účtu už žije.
if ($module === 'SubDomain' && $function === 'addsubdomain' && (getenv('FAKE_CPANEL_SUBDOMAIN_EXISTS') ?: '') !== '') {
    echo json_encode(['status' => 0, 'errors' => [
        'The subdomain “' . (string) ($_GET['domain'] ?? '') . '” already exists.',
    ]]);
    exit;
}

if ($module === 'DomainInfo' && $function === 'single_domain_data') {
    echo json_encode(['status' => 1, 'data' => [
        'domain' => (string) ($_GET['domain'] ?? ''),
        'documentroot' => getenv('FAKE_CPANEL_DOCROOT') ?: '',
    ], 'errors' => null]);
    exit;
}

echo json_encode(['status' => 1, 'data' => ['ok' => true], 'errors' => null]);
