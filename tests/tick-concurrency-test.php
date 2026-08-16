<?php

/**
 * Standalone-Test der Tick-Sperre.
 *   php tests/tick-concurrency-test.php
 *
 * Der Vorfall vom 2026-08-02: Selbstantrieb und Cron-Watchdog trafen für
 * DENSELBEN Lauf zusammen und arbeiteten beide am selben Cursor. Die
 * Stillstands-Prüfung des Watchdogs schützt davor nicht, ein Schritt, der
 * länger als die Schwelle braucht (grosse Tabelle, langer Abschnitt), sieht
 * von aussen genauso aus wie ein hängender. Ergebnis war eine anderthalb
 * Stunden unbrauchbare Kundenseite.
 *
 * Die Sperre kam danach dazu, blieb aber ungetestet. Geprüft wird deshalb
 * beides: dass sie greift, und dass sie den normalen Betrieb nicht anhält.
 * Ohne die zweite Hälfte wäre eine Sperre, die einfach immer blockiert, grün.
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
    define('DAY_IN_SECONDS', 86400);

    // --- WordPress-Ersatz ------------------------------------------------

    $GLOBALS['rh_options'] = [];

    function __(string $t, string $d = ''): string
    {
        return $t;
    }
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        // Die Taktbremse ausschalten, sonst wartet der Test in Echtzeit.
        if ($hook === 'rh-blueprint/sync/min_tick_gap') {
            return 0.0;
        }

        // Kein Selbstantrieb: der Test taktet selbst. Dafür gibt es den
        // Filter schon, ein eigener Ersatz-Scheduler wäre nur Attrappe.
        if ($hook === 'rh-blueprint/sync/suppress_loopback') {
            return true;
        }

        return $value;
    }
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['rh_options'][$key] ?? $default;
    }
    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['rh_options'][$key] = $value;

        return true;
    }
    function delete_option(string $key): bool
    {
        unset($GLOBALS['rh_options'][$key]);

        return true;
    }
    function add_option(string $key, mixed $value, string $deprecated = '', mixed $autoload = null): bool
    {
        // Genau hier hängt die Sperre: add_option scheitert, wenn der Name
        // schon vergeben ist. Ein Stub, der stattdessen überschreibt, würde
        // den ganzen Test wertlos machen.
        if (array_key_exists($key, $GLOBALS['rh_options'])) {
            return false;
        }

        $GLOBALS['rh_options'][$key] = $value;

        return true;
    }
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        return true;
    }
    function wp_cache_get(string $key, string $group = ''): mixed
    {
        return false;
    }
    function current_time(string $format, int $gmt = 0): int
    {
        return time();
    }
    function wp_json_encode(mixed $data, int $flags = 0): string
    {
        return (string) json_encode($data, $flags);
    }
    function sanitize_key(string $k): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($k));
    }
    function esc_html(string $t): string
    {
        return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    }
    function set_transient(string $k, mixed $v, int $ttl = 0): bool
    {
        $GLOBALS['rh_options']['_t_' . $k] = $v;

        return true;
    }
    function get_transient(string $k): mixed
    {
        return $GLOBALS['rh_options']['_t_' . $k] ?? false;
    }
    function delete_transient(string $k): bool
    {
        unset($GLOBALS['rh_options']['_t_' . $k]);

        return true;
    }
    function wp_generate_password(int $len = 12, bool $special = true): string
    {
        return substr(bin2hex(random_bytes(32)), 0, $len);
    }
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/') . '/';
    }
    function wp_mkdir_p(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }

    require_once dirname(__DIR__) . '/vendor/rh/tick-engine/autoload-src.php';
    require_once dirname(__DIR__) . '/inc/Sync/LocalOptionGuard.php';
    require_once dirname(__DIR__) . '/inc/Sync/SyncStatus.php';
    require_once dirname(__DIR__) . '/inc/Sync/SyncProfile.php';
    require_once dirname(__DIR__) . '/inc/Sync/JobState.php';
    require_once dirname(__DIR__) . '/inc/Sync/JobTrace.php';
    require_once dirname(__DIR__) . '/inc/Sync/StageAdvancer.php';
    require_once dirname(__DIR__) . '/inc/Sync/JobScheduler.php';
    require_once dirname(__DIR__) . '/inc/Sync/SyncLog.php';
    require_once dirname(__DIR__) . '/inc/Sync/Peer.php';
    require_once dirname(__DIR__) . '/inc/Sync/PeerUrl.php';
    require_once dirname(__DIR__) . '/inc/Sync/PeerRegistry.php';
    require_once dirname(__DIR__) . '/inc/Sync/TickRunner.php';

    use RhSync\Sync\JobScheduler;
    use RhSync\Sync\JobState;
    use RhSync\Sync\PeerRegistry;
    use RhSync\Sync\SyncLog;
    use RhSync\Sync\TickRunner;

    /** Baut einen Runner mit echtem Umfeld, nur ohne Selbstantrieb. */
    function baueRunner(callable $resolver): TickRunner
    {
        return new TickRunner($resolver, new JobScheduler(), new SyncLog(), new PeerRegistry());
    }

    $fehler = 0;

    function pruefe(bool $ok, string $name, string $detail = ''): void
    {
        global $fehler;

        if ($ok) {
            echo "  ok   $name\n";

            return;
        }

        echo "  FEHL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
        $fehler++;
    }

    // --- Aufbau -----------------------------------------------------------

    /**
     * Ein Fortschritt, der mitzählt und auf Wunsch mitten im Schritt einen
     * zweiten Tick auslöst. Genau so trafen sich Selbstantrieb und Watchdog.
     */
    final class Mitzaehler
    {
        public int $aufrufe = 0;

        /** @var null|callable(): void */
        public $waehrenddessen = null;

        public function advance(JobState $job): void
        {
            $this->aufrufe++;

            if ($this->waehrenddessen !== null) {
                $einmal = $this->waehrenddessen;
                $this->waehrenddessen = null;
                $einmal();
            }

            $stand = (int) ($job->cursor['n'] ?? 0);
            $job->cursor['n'] = $stand + 1;
            $job->save();
        }
    }

    function neuerLauf(): JobState
    {
        $job = new JobState(
            jobId: bin2hex(random_bytes(16)),
            peerId: 'peer-1',
            direction: RhSync\Sync\SyncStatus::DIRECTION_PULL,
            type: JobState::TYPE_DB_SYNC,
            profile: [],
            spawnToken: bin2hex(random_bytes(8)),
            createdAt: time(),
        );
        $job->stage = 'start';
        $job->save();

        return $job;
    }

    // --- Der Vorfall: zwei Ticks für denselben Lauf ------------------------

    $zaehler = new Mitzaehler();
    $runner = baueRunner(static fn (JobState $j): Mitzaehler => $zaehler);

    $job = neuerLauf();

    // Mitten im Schritt kommt der Watchdog dazwischen, mit derselben Kennung
    // und demselben Token. Genau das war der 2026-08-02.
    $zaehler->waehrenddessen = static function () use ($runner, $job): void {
        $runner->runTick($job->jobId, $job->spawnToken);
    };

    $runner->runTick($job->jobId, $job->spawnToken);

    pruefe(
        $zaehler->aufrufe === 1,
        'zwei gleichzeitige Schritte für denselben Lauf, nur einer arbeitet',
        sprintf('%d Aufrufe', $zaehler->aufrufe)
    );

    $nachher = JobState::load($job->jobId);

    pruefe(
        $nachher !== null && (int) ($nachher->cursor['n'] ?? 0) === 1,
        'der Cursor ist einmal weitergerückt, nicht zweimal',
        (string) ($nachher->cursor['n'] ?? 'weg')
    );

    // --- Und die andere Hälfte: der Betrieb läuft weiter -------------------
    //
    // Ohne diese Prüfung wäre eine Sperre grün, die einfach immer blockiert.

    $zaehler2 = new Mitzaehler();
    $runner2 = baueRunner(static fn (JobState $j): Mitzaehler => $zaehler2);

    $job2 = neuerLauf();

    $runner2->runTick($job2->jobId, $job2->spawnToken);
    $runner2->runTick($job2->jobId, $job2->spawnToken);
    $runner2->runTick($job2->jobId, $job2->spawnToken);

    pruefe(
        $zaehler2->aufrufe === 3,
        'nacheinander laufen drei Schritte durch, die Sperre hält nichts fest',
        sprintf('%d Aufrufe', $zaehler2->aufrufe)
    );

    // Zwei verschiedene Läufe dürfen sich nicht gegenseitig blockieren: die
    // Sperre hängt an der Kennung des Laufs, nicht an "es läuft irgendwas".
    $zaehler3 = new Mitzaehler();
    $runner3 = baueRunner(static fn (JobState $j): Mitzaehler => $zaehler3);

    $a = neuerLauf();
    $b = neuerLauf();

    $zaehler3->waehrenddessen = static function () use ($runner3, $b): void {
        $runner3->runTick($b->jobId, $b->spawnToken);
    };

    $runner3->runTick($a->jobId, $a->spawnToken);

    pruefe(
        $zaehler3->aufrufe === 2,
        'zwei verschiedene Läufe blockieren sich nicht gegenseitig',
        sprintf('%d Aufrufe', $zaehler3->aufrufe)
    );

    // --- Ein falscher Token kommt gar nicht erst durch ---------------------

    $zaehler4 = new Mitzaehler();
    $runner4 = baueRunner(static fn (JobState $j): Mitzaehler => $zaehler4);

    $job4 = neuerLauf();
    $runner4->runTick($job4->jobId, 'falsch');

    pruefe($zaehler4->aufrufe === 0, 'ein falscher Token treibt nichts an');

    // --- Und die Sperre bleibt nicht liegen --------------------------------
    //
    // Der gefährlichere Fehler wäre eine Sperre, die nach einem Fehlschlag
    // stehen bleibt: dann käme der Lauf nie wieder in Gang, bis sie von
    // selbst verfällt.

    $kaputt = new class {
        public int $aufrufe = 0;

        public function advance(JobState $job): void
        {
            $this->aufrufe++;

            throw new RuntimeException('Absicht');
        }
    };

    $runner5 = baueRunner(static fn (JobState $j) => $kaputt);
    $job5 = neuerLauf();

    $runner5->runTick($job5->jobId, $job5->spawnToken);

    $offen = array_filter(
        array_keys($GLOBALS['rh_options']),
        static fn (string $k): bool => str_contains($k, 'sync_tick_' . $job5->jobId)
    );

    pruefe(
        $offen === [],
        'nach einem Fehler bleibt keine Sperre liegen',
        implode(', ', $offen)
    );

    echo "\n";

    if ($fehler > 0) {
        echo "$fehler Fehler.\n";
        exit(1);
    }

    echo "Alles gruen.\n";
}
