<?php

/**
 * Standalone-Test für die Fehler-Detail-Anreicherung im SyncClient.
 *   php tests/download-error-detail-test.php
 *
 * describeTransportError() und describeErrorBody() sind reine statische Methoden ohne
 * WP-Abhängigkeit (string-/Datei-IO), darum per Reflection direkt aufrufbar. Sie sorgen
 * dafür, dass ein fehlgeschlagener Download den echten Grund ins Log bringt (Peer-Fatal
 * bzw. token_expired vs. file_missing) statt nur "HTTP-Status 404".
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/Sync/SyncClient.php';

use RhSync\Sync\SyncClient;

/** @return mixed */
function invoke(string $method, mixed ...$args)
{
    // setAccessible ist seit PHP 8.1 überflüssig (private Methoden sind per Reflection
    // aufrufbar) und in 8.5 deprecated, darum weggelassen.
    $m = new ReflectionMethod(SyncClient::class, $method);

    return $m->invoke(null, ...$args);
}

$fails = 0;
function check(string $name, bool $cond): void
{
    global $fails;
    if ($cond) {
        echo "  ok:   $name\n";
    } else {
        echo "  FAIL: $name\n";
        $fails++;
    }
}

// --- describeTransportError: Empty-reply-Signatur eines Peer-Fatals ---
$r = invoke('describeTransportError', 'cURL error 52: Empty reply from server');
check('Empty reply bekommt Fatal-Deutungshinweis', str_contains($r, 'Fatal auf der Gegenseite'));

$r = invoke('describeTransportError', 'cURL error 18: transfer closed with outstanding read data remaining');
check('anderer Transportfehler bleibt unveraendert', $r === 'cURL error 18: transfer closed with outstanding read data remaining');

// --- describeErrorBody: JSON-Fehlerkoerper aus der gestreamten Datei ---
$tmp = (string) tempnam(sys_get_temp_dir(), 'dl');

file_put_contents($tmp, '{"code":"rhbp_token_expired","message":"Token ungueltig oder abgelaufen.","data":{"status":404}}');
$r = invoke('describeErrorBody', $tmp);
check('JSON-Fehler wird als (code: message) gezeigt', str_contains($r, 'rhbp_token_expired') && str_contains($r, 'Token ungueltig'));

file_put_contents($tmp, '{"code":"rhbp_file_missing","message":"Backup-Datei gerade nicht lesbar."}');
$r = invoke('describeErrorBody', $tmp);
check('file_missing wird vom token_expired unterscheidbar', str_contains($r, 'rhbp_file_missing'));

file_put_contents($tmp, 'irgendein Nicht-JSON-Fehlertext vom Proxy');
$r = invoke('describeErrorBody', $tmp);
check('Nicht-JSON wird durchgereicht', str_contains($r, 'Nicht-JSON-Fehlertext'));

file_put_contents($tmp, '');
$r = invoke('describeErrorBody', $tmp);
check('leere Datei -> leerer Detail', $r === '');

$r = invoke('describeErrorBody', '/nonexistent/does-not-exist.bin');
check('fehlende Datei -> leerer Detail', $r === '');

unlink($tmp);

echo $fails === 0 ? "\nALLE TESTS GRUEN\n" : "\n$fails FEHLER\n";
exit($fails === 0 ? 0 : 1);
