<?php

/**
 * Standalone-Test für SyncController::parseRangeHeader().
 *   php tests/range-parse-test.php
 *
 * parseRangeHeader ist pure (keine WP-Aufrufe). Wir laden nur die Klassendefinition
 * mit ein paar leeren Stubs für die referenzierten Typ-Hints (lazy, werden beim Laden
 * nicht aufgelöst) und rufen die statische Methode direkt auf.
 */

declare(strict_types=1);

// Stubs für die im use-Block referenzierten Klassen (nur zum Laden der Datei).
namespace {
    class WP_Error
    {
    }
    class WP_REST_Request
    {
    }
    class WP_REST_Response
    {
    }
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
    }
}

namespace RhDbEngine {
    class Exporter
    {
    }
    class Storage
    {
    }
}

namespace {
    require_once dirname(__DIR__) . '/inc/Sync/SyncController.php';

    use RhSync\Sync\SyncController;

    $failures = 0;
    function check(string $label, bool $ok): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (! $ok) {
            $failures++;
        }
    }

    $fs = 1000;

    // Kein Range -> null (volle Datei).
    check('null-Header -> null', SyncController::parseRangeHeader(null, $fs) === null);
    check('leer -> null', SyncController::parseRangeHeader('', $fs) === null);
    check('ohne bytes= -> null', SyncController::parseRangeHeader('items=0-10', $fs) === null);

    // Geschlossener Range.
    $r = SyncController::parseRangeHeader('bytes=0-99', $fs);
    check('0-99 start', is_array($r) && $r['start'] === 0);
    check('0-99 end', is_array($r) && $r['end'] === 99);
    check('0-99 length', is_array($r) && $r['length'] === 100);

    // Mittiger Chunk.
    $r = SyncController::parseRangeHeader('bytes=500-599', $fs);
    check('500-599 length', is_array($r) && $r['length'] === 100 && $r['start'] === 500 && $r['end'] === 599);

    // Offener Range (bytes=start-) -> bis fs-1.
    $r = SyncController::parseRangeHeader('bytes=900-', $fs);
    check('900- end=fs-1', is_array($r) && $r['end'] === 999 && $r['length'] === 100);

    // end über Dateiende -> auf fs-1 klemmen.
    $r = SyncController::parseRangeHeader('bytes=900-5000', $fs);
    check('900-5000 geklemmt', is_array($r) && $r['end'] === 999 && $r['length'] === 100);

    // Ganze Datei.
    $r = SyncController::parseRangeHeader('bytes=0-', $fs);
    check('0- volle Datei', is_array($r) && $r['start'] === 0 && $r['end'] === 999 && $r['length'] === 1000);

    // Letztes Byte.
    $r = SyncController::parseRangeHeader('bytes=999-999', $fs);
    check('999-999 letztes Byte', is_array($r) && $r['start'] === 999 && $r['length'] === 1);

    // start jenseits Dateiende -> unsatisfiable (416).
    check('1000- unsatisfiable', SyncController::parseRangeHeader('bytes=1000-', $fs) === 'unsatisfiable');
    check('5000-6000 unsatisfiable', SyncController::parseRangeHeader('bytes=5000-6000', $fs) === 'unsatisfiable');

    // end < start -> unsatisfiable.
    check('500-100 unsatisfiable', SyncController::parseRangeHeader('bytes=500-100', $fs) === 'unsatisfiable');

    // Suffix-Range (bytes=-N) nicht unterstützt -> null (volle Datei).
    check('-500 Suffix -> null', SyncController::parseRangeHeader('bytes=-500', $fs) === null);

    // Multi-Range nicht unterstützt -> null.
    check('multi -> null', SyncController::parseRangeHeader('bytes=0-99,200-299', $fs) === null);

    // Whitespace-Toleranz.
    $r = SyncController::parseRangeHeader('bytes= 0-99 ', $fs);
    check('whitespace tolerant', is_array($r) && $r['length'] === 100);

    // Dateigröße 0 -> null (Full-Fallback greift beim Aufrufer).
    check('fs=0 -> null', SyncController::parseRangeHeader('bytes=0-10', 0) === null);

    echo "\n" . ($failures === 0 ? "ALLE TESTS GRÜN" : "$failures FEHLER") . "\n";
    exit($failures === 0 ? 0 : 1);
}
