<?php

/**
 * Standalone-Integrationstest für die History-Schreibung (TickRunner::logCompletion) und
 * den State-GC (TickRunner::gcFinishedStates), mit einem In-Memory-WP-Options-Mock.
 *   php tests/history-gc-test.php
 *
 * Beweist:
 *   - ein abgeschlossener Pull-Job (done) schreibt genau EINEN success-Eintrag in den Verlauf,
 *   - ein zweiter logCompletion-Aufruf erzeugt KEINEN Doppel-Eintrag (logged-Flag),
 *   - ein fehlgeschlagener Job wird als failed geloggt,
 *   - ein Inbound-Import-Job wird NICHT geloggt (nur logged markiert),
 *   - gcFinishedStates purged nur geloggte, mehr als 1h alte States; frische/laufende bleiben.
 */

declare(strict_types=1);

namespace RhBlueprint\Core {
    class Environment
    {
        public static function isProduction(): bool
        {
            return false;
        }
    }
}

namespace {
    define('HOUR_IN_SECONDS', 3600);

    $GLOBALS['__opts'] = [];

    // Minimaler $wpdb-Mock: allStateJobIds() scannt die Options nach rhbp_sync_job_%.
    // Der Mock ignoriert das SQL und filtert direkt aus dem In-Memory-Store (der echte
    // Query wird live auf hosting-01 verifiziert).
    class FakeWpdb
    {
        public string $options = 'wp_options';
        public function esc_like(string $s): string
        {
            return addcslashes($s, '_%\\');
        }
        public function prepare(string $q, ...$args): string
        {
            return $q;
        }
        /** @return array<int, string> */
        public function get_col(string $q): array
        {
            $out = [];
            foreach (array_keys($GLOBALS['__opts']) as $name) {
                if (str_starts_with((string) $name, \RhSync\Sync\JobState::OPTION_PREFIX)) {
                    $out[] = $name;
                }
            }
            return $out;
        }
    }
    $GLOBALS['wpdb'] = new FakeWpdb();

    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__opts'][$name] ?? $default;
    }
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['__opts'][$name] = $value;
        return true;
    }
    function delete_option(string $name): bool
    {
        unset($GLOBALS['__opts'][$name]);
        return true;
    }
    function get_transient(string $k)
    {
        return false;
    }
    function set_transient(string $k, $v, $t = 0): bool
    {
        return true;
    }
    function delete_transient(string $k): bool
    {
        return true;
    }
    function wp_cache_flush(): bool
    {
        return true;
    }
    function __(string $t, string $d = 'default'): string
    {
        return $t;
    }
    function wp_generate_password(int $len = 12, bool $special = true, bool $extra = false): string
    {
        return substr(str_repeat('abcdef0123456789', 8), 0, $len);
    }
    function wp_generate_uuid4(): string
    {
        return sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    }
    function sanitize_key(string $k): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $k));
    }
    function esc_url_raw(string $u): string
    {
        return $u;
    }
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/') . '/';
    }
    function untrailingslashit(string $s): string
    {
        return rtrim($s, '/');
    }
    function apply_filters(string $h, $v, ...$a)
    {
        return $v;
    }

    $base = dirname(__DIR__) . '/inc/Sync/';
    foreach (['SyncStatus', 'SyncProfile', 'SyncPermissions', 'Peer', 'PeerRegistry', 'SyncLog', 'JobState', 'JobScheduler', 'TickRunner'] as $cls) {
        require_once $base . $cls . '.php';
    }

    use RhSync\Sync\JobState;
    use RhSync\Sync\JobScheduler;
    use RhSync\Sync\Peer;
    use RhSync\Sync\PeerRegistry;
    use RhSync\Sync\SyncLog;
    use RhSync\Sync\SyncProfile;
    use RhSync\Sync\SyncStatus;
    use RhSync\Sync\TickRunner;

    $failures = 0;
    function check(string $label, bool $ok): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (! $ok) {
            $failures++;
        }
    }

    // Peer anlegen und in die rhbp_peers-Option schreiben.
    $peer = Peer::create('Testquelle', 'https://quelle.example');
    update_option(PeerRegistry::OPTION_NAME, [$peer->toArray()]);

    $peers = new PeerRegistry();
    $log = new SyncLog();
    $runner = new TickRunner(static fn ($j) => null, new JobScheduler(), $log, $peers);

    $logCompletion = new \ReflectionMethod(TickRunner::class, 'logCompletion');
    $gc = new \ReflectionMethod(TickRunner::class, 'gcFinishedStates');

    $profile = new SyncProfile(true, true, true, true, true, true, true, true);

    // Helper: einen finalen JobState direkt bauen (ohne den ganzen Tick-Lauf).
    $makeJob = static function (string $direction, string $stage, int $endedOffset = 0) use ($peer, $profile): JobState {
        $job = new JobState(
            jobId: bin2hex(random_bytes(16)),
            peerId: $peer->id,
            direction: $direction,
            type: JobState::TYPE_DB_SYNC,
            profile: $profile->toArray(),
            spawnToken: 'x',
            createdAt: time() - 100,
            startedAt: time() - 100,
            endedAt: time() + $endedOffset,
            stage: $stage,
            bytesTotal: 12345,
        );
        $job->save();
        return $job;
    };

    // 1. Erfolgreicher Pull -> ein success-Eintrag.
    $job = $makeJob(SyncStatus::DIRECTION_PULL, SyncStatus::PHASE_DONE);
    $logCompletion->invoke($runner, $job);
    $entries = $log->all();
    check('Pull done -> 1 History-Eintrag', count($entries) === 1);
    check('Eintrag status = success', ($entries[0]['status'] ?? '') === 'success');
    check('Eintrag direction = pull', ($entries[0]['direction'] ?? '') === 'pull');
    check('logged-Flag gesetzt', JobState::load($job->jobId)->logged === true);

    // 2. Idempotenz: nochmal loggen -> weiterhin 1 Eintrag.
    $logCompletion->invoke($runner, JobState::load($job->jobId));
    check('idempotent: weiterhin 1 Eintrag', count($log->all()) === 1);

    // 3. Fehlgeschlagener Push -> failed-Eintrag.
    $job2 = $makeJob(SyncStatus::DIRECTION_PUSH, SyncStatus::PHASE_FAILED);
    $logCompletion->invoke($runner, $job2);
    $all = $log->all();
    check('Push failed -> 2 Einträge gesamt', count($all) === 2);
    check('neuester status = failed', ($all[0]['status'] ?? '') === 'failed');
    check('neuester direction = push', ($all[0]['direction'] ?? '') === 'push');

    // 4. Inbound-Import -> NICHT geloggt, aber logged markiert.
    $job3 = $makeJob(SyncStatus::DIRECTION_IMPORT, SyncStatus::PHASE_DONE);
    $logCompletion->invoke($runner, $job3);
    check('Import -> kein zusätzlicher History-Eintrag', count($log->all()) === 2);
    check('Import-Job trotzdem logged', JobState::load($job3->jobId)->logged === true);

    // 5. GC: alter (2h) geloggter Job wird gepurged, frischer bleibt.
    $oldJob = $makeJob(SyncStatus::DIRECTION_PULL, SyncStatus::PHASE_DONE, -7200); // endedAt 2h in der Vergangenheit
    $logCompletion->invoke($runner, $oldJob);
    $freshJob = JobState::load($job->jobId); // endedAt ~jetzt, schon geloggt
    $gc->invoke($runner);
    check('GC: alter geloggter State gepurged', JobState::load($oldJob->jobId) === null);
    check('GC: frischer State bleibt', JobState::load($freshJob->jobId) !== null);

    echo "\n" . ($failures === 0 ? "ALLE TESTS GRÜN" : "$failures FEHLER") . "\n";
    exit($failures === 0 ? 0 : 1);
}
