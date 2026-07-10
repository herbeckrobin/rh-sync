<?php

/**
 * Standalone-Test für die Resume-Schreib-Mechanik von PullOperation::writeChunkAt().
 *   php tests/download-resume-test.php
 *
 * writeChunkAt() ist reine Datei-IO (fopen/fseek/stream_copy/ftruncate), kein WP nötig.
 * Wir laden die echte Methode per Reflection und beweisen:
 *   - chunkweises Schreiben an aufsteigenden Offsets ergibt byte-für-byte das Original,
 *   - der erste Chunk (offset 0) überschreibt vorhandenen Müll (wb-Modus),
 *   - dasselbe Teilstück doppelt geschrieben (Revive-Tick) bleibt idempotent,
 *   - der letzte, kleinere Teilchunk wird korrekt gesetzt.
 */

declare(strict_types=1);

namespace RhDbEngine {
    class Exporter
    {
    }
    class Importer
    {
    }
    class Storage
    {
    }
}

namespace {
    require_once dirname(__DIR__) . '/inc/Sync/StageAdvancer.php';
    require_once dirname(__DIR__) . '/inc/Sync/PullOperation.php';

    $failures = 0;
    function check(string $label, bool $ok): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (! $ok) {
            $failures++;
        }
    }

    $write = new \ReflectionMethod(\RhSync\Sync\PullOperation::class, 'writeChunkAt');
    $writeChunk = static function (string $target, int $offset, string $data) use ($write): bool {
        $part = $target . '.part';
        file_put_contents($part, $data);
        $ok = (bool) $write->invoke(null, $target, $offset, $part);
        @unlink($part);
        return $ok;
    };

    $dir = sys_get_temp_dir() . '/rhsync-resume-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $target = $dir . '/out.zip';

    // Original: 250003 Bytes (nicht durch die Chunkgröße teilbar -> letzter Teilchunk).
    $original = random_bytes(250003);
    $chunk = 64 * 1024;

    // Zieldatei vorab mit Müll füllen -> der offset-0-Chunk (wb) muss ihn wegräumen.
    file_put_contents($target, str_repeat("\xFF", 999999));

    // Chunkweise schreiben, mit simuliertem Abbruch: Chunk 2 wird doppelt geschrieben
    // (Revive-Tick schreibt denselben Offset erneut -> muss byte-identisch bleiben).
    $offset = 0;
    $index = 0;
    while ($offset < strlen($original)) {
        $len = (int) min($chunk, strlen($original) - $offset);
        $data = substr($original, $offset, $len);

        $ok = $writeChunk($target, $offset, $data);
        check("Chunk $index (offset $offset, len $len) geschrieben", $ok);

        // Idempotenz: den zweiten Chunk gleich nochmal an denselben Offset schreiben.
        if ($index === 1) {
            $ok2 = $writeChunk($target, $offset, $data);
            check('Revive-Tick: Chunk 1 erneut geschrieben (idempotent)', $ok2);
        }

        $offset += $len;
        $index++;
    }

    $result = (string) file_get_contents($target);
    check('finale Größe == Original', strlen($result) === strlen($original));
    check('finaler Inhalt hash-identisch zum Original', hash('sha256', $result) === hash('sha256', $original));

    // Aufräumen.
    @unlink($target);
    @rmdir($dir);

    echo "\n" . ($failures === 0 ? "ALLE TESTS GRÜN" : "$failures FEHLER") . "\n";
    exit($failures === 0 ? 0 : 1);
}
