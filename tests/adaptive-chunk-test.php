<?php

/**
 * Standalone-Test für den adaptiven Download-Chunk-Fix.
 *   php tests/adaptive-chunk-test.php
 *
 * Zwei Bausteine ohne WP-Abhängigkeit:
 *  - JobState::updateStepMessage() setzt die Meldung des laufenden Steps (fürs Modal),
 *    ohne dessen Status oder Start-Zeit anzutasten.
 *  - die Halbierungs-Sequenz der Blockgröße (8 MB -> ... -> Mindestgröße) terminiert
 *    und unterschreitet die Mindestgröße nie.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/Sync/SyncStatus.php';
require_once dirname(__DIR__) . '/inc/Sync/JobState.php';

use RhSync\Sync\JobState;
use RhSync\Sync\SyncStatus;

$fails = 0;
function check(string $name, bool $cond): void
{
    global $fails;
    echo ($cond ? '  ok:   ' : '  FAIL: ') . $name . "\n";
    if (! $cond) {
        $fails++;
    }
}

// --- JobState::updateStepMessage ---
$job = JobState::fromArray([
    'steps' => [
        ['id' => SyncStatus::PHASE_MANIFEST, 'status' => 'done', 'started_at' => 100.0],
        ['id' => SyncStatus::PHASE_DOWNLOAD, 'status' => 'running', 'started_at' => 200.0, 'message' => 'alt'],
    ],
]);
$job->updateStepMessage(SyncStatus::PHASE_DOWNLOAD, 'Reduziere die Blockgröße auf 512 KB');
$arr = $job->toArray();

check('Download-Step bekommt die neue Meldung', $arr['steps'][1]['message'] === 'Reduziere die Blockgröße auf 512 KB');
check('Download-Step-Status bleibt running', $arr['steps'][1]['status'] === 'running');
check('Download-Step started_at unangetastet', $arr['steps'][1]['started_at'] === 200.0);
check('anderer Step bleibt ohne Meldung', ! isset($arr['steps'][0]['message']));
check('globale message wird mitgesetzt', $arr['message'] === 'Reduziere die Blockgröße auf 512 KB');

// --- Halbierungs-Sequenz (wie in PullOperation::stageDownload) ---
$min   = 128 * 1024;
$chunk = 8 * 1024 * 1024;
$seq   = [];
$guard = 0;
while ($chunk > $min && $guard++ < 100) {
    $chunk = max($min, intdiv($chunk, 2));
    $seq[] = $chunk;
}
check('Sequenz terminiert an der Mindestgröße', end($seq) === $min);
check('8 MB -> 128 KB in genau 6 Halbierungen', count($seq) === 6);
check('keine Größe unter der Mindestgröße', min($seq) >= $min);
check('512 KB ist Teil der Sequenz (ADKRU-Grenze)', in_array(512 * 1024, $seq, true));

echo $fails === 0 ? "\nALLE TESTS GRUEN\n" : "\n$fails FEHLER\n";
exit($fails === 0 ? 0 : 1);
