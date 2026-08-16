<?php

/**
 * Standalone-Test für zwei Befunde aus dem Rückblick auf den 2026-08-02.
 *   php tests/guard-and-heartbeat-test.php
 *
 * A) Der Schutz der site-eigenen Options kannte `{prefix}_user_roles` nicht. Ohne diese
 *    Option hat kein Benutzer mehr Rechte, das Backend antwortet nur noch mit "Du bist
 *    leider nicht berechtigt". Der Name trägt den Tabellen-Prefix der Site, darf also
 *    nicht fest verdrahtet sein.
 *
 * B) Die Quellseite meldete 45 Minuten "läuft", obwohl die Zielseite seit 17 Sekunden tot
 *    war: der Zeitstempel kam vom eigenen Watchdog, nicht von einer Rückmeldung des Ziels.
 *    Jetzt zählt nur noch das echte Lebenszeichen der Gegenseite.
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
    class ExportCursor
    {
    }
}

namespace {
    define('HOUR_IN_SECONDS', 3600);
    define('MINUTE_IN_SECONDS', 60);

    // --- WordPress-Ersatz ------------------------------------------------

    $GLOBALS['rh_options'] = [];

    function __(string $t, string $d = ''): string
    {
        return $t;
    }
    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['rh_options'][$key] ?? $default;
    }
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['rh_options'][$key] = $value;
        return true;
    }
    function delete_option(string $key): bool
    {
        unset($GLOBALS['rh_options'][$key]);
        return true;
    }
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        $GLOBALS['rh_options']['_t_' . $key] = $value;
        return true;
    }
    function get_transient(string $key)
    {
        return $GLOBALS['rh_options']['_t_' . $key] ?? false;
    }
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['rh_options']['_t_' . $key]);
        return true;
    }

    final class MiniWpdb
    {
        public string $prefix = 'vlsoa_';
        public string $options = 'vlsoa_options';
    }
    $GLOBALS['wpdb'] = new MiniWpdb();

    // Die Tick-Engine liefert das Interface, das JobState erfuellt.
    require_once dirname(__DIR__) . '/vendor/rh/tick-engine/autoload-src.php';
    require_once dirname(__DIR__) . '/inc/Sync/LocalOptionGuard.php';
    require_once dirname(__DIR__) . '/inc/Sync/SyncStatus.php';
    require_once dirname(__DIR__) . '/inc/Sync/SyncProfile.php';
    require_once dirname(__DIR__) . '/inc/Sync/JobState.php';
    require_once dirname(__DIR__) . '/inc/Sync/JobTrace.php';
    require_once dirname(__DIR__) . '/inc/Sync/StageAdvancer.php';
    require_once dirname(__DIR__) . '/inc/Sync/PushOperation.php';

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (!$ok) {
            $failures++;
            if ($detail !== '') {
                echo '        ' . $detail . "\n";
            }
        }
    }

    // ====================================================================
    echo "\nA. Die Rollendefinition ist geschützt\n";
    // ====================================================================

    $guard = new RhSync\Sync\LocalOptionGuard();
    $names = $guard->protectedNames();

    check(
        'vlsoa_user_roles steht auf der Liste',
        in_array('vlsoa_user_roles', $names, true),
        implode(', ', $names)
    );
    check('Der Prefix ist nicht fest verdrahtet', !in_array('wp_user_roles', $names, true));

    $GLOBALS['wpdb']->prefix = 'wp_';
    check(
        'Bei anderem Prefix wandert der Name mit',
        in_array('wp_user_roles', (new RhSync\Sync\LocalOptionGuard())->protectedNames(), true)
    );
    $GLOBALS['wpdb']->prefix = 'vlsoa_';

    $where = new ReflectionMethod(RhSync\Sync\LocalOptionGuard::class, 'buildWhereClause');
    $clause = (string) $where->invoke(new RhSync\Sync\LocalOptionGuard());
    check('Und landet in der Abfrage', str_contains($clause, "'vlsoa_user_roles'"), $clause);

    // Das Theme soll weiterhin mitwandern, sonst bringt ein Sync die Gestaltung nicht mit.
    check('Das aktive Theme bleibt bewusst ungeschützt', !str_contains($clause, "'stylesheet'"));

    // ====================================================================
    echo "\nB. Ein hängender Import wird als hängend gemeldet\n";
    // ====================================================================

    /** Baut einen Push-Job, der auf die Rückmeldung der Zielseite wartet. */
    function makeJob(): RhSync\Sync\JobState
    {
        $job = new RhSync\Sync\JobState(
            jobId: str_repeat('a', 32),
            peerId: 'peer1',
            direction: RhSync\Sync\SyncStatus::DIRECTION_PUSH,
            type: RhSync\Sync\JobState::TYPE_DB_SYNC,
            profile: [],
            spawnToken: str_repeat('b', 32),
            createdAt: time(),
            lastUpdateAt: time(),
            stage: RhSync\Sync\SyncStatus::PHASE_IMPORT,
            steps: [[
                'id' => RhSync\Sync\SyncStatus::PHASE_IMPORT,
                'label' => 'Import',
                'status' => 'running',
                'duration_ms' => null,
                'message' => null,
                'started_at' => null,
                'ended_at' => null,
            ]],
        );
        $job->cursor['remote_job_id'] = str_repeat('c', 32);

        return $job;
    }

    $track = new ReflectionMethod(RhSync\Sync\PushOperation::class, 'trackRemoteHeartbeat');
    $push = (new ReflectionClass(RhSync\Sync\PushOperation::class))->newInstanceWithoutConstructor();

    $beat = static function (RhSync\Sync\JobState $job, array $status) use ($track, $push): void {
        $track->invoke($push, $job, $status);
    };

    // 1. Die Gegenseite arbeitet: der Lauf bleibt am Leben.
    $job = makeJob();
    $beat($job, ['last_update_at' => time() - 5, 'phase' => 'import']);
    $beat($job, ['last_update_at' => time() - 1, 'phase' => 'import']);
    check('Fortschritt auf der Gegenseite hält den Lauf am Leben', !$job->isFinished());

    // 2. Die Gegenseite ist tot: derselbe Zeitstempel, immer wieder.
    $job = makeJob();
    $frozen = time() - 5;
    $beat($job, ['last_update_at' => $frozen, 'phase' => 'import']);

    // Stillstand zurückdatieren, statt im Test fünf Minuten zu warten.
    $job->cursor['remote_progress_at'] = time() - 100;
    $beat($job, ['last_update_at' => $frozen, 'phase' => 'import']);
    check('Nach 100 Sekunden Stille steht der Hinweis im Fenster', str_contains($job->message, 'Keine Rückmeldung'), $job->message);
    check('Aber der Lauf gilt noch nicht als gescheitert', !$job->isFinished());

    $job->cursor['remote_progress_at'] = time() - 400;
    $beat($job, ['last_update_at' => $frozen, 'phase' => 'import']);
    check('Nach 400 Sekunden Stille ist der Lauf gescheitert', $job->isFinished());
    check(
        'Und sagt auch warum',
        is_array($job->error) && str_contains((string) $job->error['message'], 'meldet sich seit'),
        is_array($job->error) ? (string) $job->error['message'] : 'kein Fehler gesetzt'
    );

    // 3. Die Gegenseite antwortet gar nicht mehr: gleiche Behandlung, keine Ausrede.
    $job = makeJob();
    $beat($job, []);
    $job->cursor['remote_progress_at'] = time() - 400;
    $beat($job, []);
    check('Eine stumme Gegenseite endet genauso', $job->isFinished());

    // 4. Der alte Fehler: das blosse Weiterlaufen der eigenen Uhr darf nichts beweisen.
    $job = makeJob();
    $beat($job, ['last_update_at' => $frozen, 'phase' => 'import']); // erster Poll setzt den Bezugspunkt
    $job->cursor['remote_progress_at'] = time() - 400;
    $job->touch(); // eigener Heartbeat, wie ihn der alte Pfad bei jedem Tick setzte
    check('Der eigene Heartbeat allein sieht frisch aus', !$job->isStale());
    $beat($job, ['last_update_at' => $frozen, 'phase' => 'import']);
    check('Zählt aber nicht mehr als Lebenszeichen', $job->isFinished());

    echo "\n" . ($failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n");
    exit($failures === 0 ? 0 : 1);
}
