<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhTickEngine\TickLock;

/**
 * Führt einen einzelnen Sync-Tick aus und hält die Tick-Kette am Laufen.
 *
 * Jeder Tick: Job laden, spawn_token prüfen, einen Häppchen der aktuellen Stage abarbeiten
 * (delegiert an den {@see StageAdvancer} für Pull bzw. Push), Heartbeat setzen, nächsten Tick
 * per Loopback anstoßen. Der Cron-Watchdog erkennt hängende Jobs (fehlender Heartbeat) und
 * übernimmt oder bricht sie sauber ab (Lock-Freigabe).
 */
final class TickRunner
{
    /** Wie oft ein hängender Job vom Watchdog wiederbelebt wird, bevor er als gescheitert gilt. */
    public const MAX_RETRIES = 3;

    /**
     * Wie lange die Sperre eines Schrittes haelt. Grosszuegiger als das
     * Zeitbudget: ein vom Webserver abgeschossener Schritt gibt seine Sperre
     * nicht zurueck, sie muss von selbst verfallen.
     */
    private const TICK_LOCK_TTL = 240;

    /** @var callable(JobState): StageAdvancer */
    private $advancerResolver;

    /**
     * @param callable(JobState): StageAdvancer $advancerResolver Wählt anhand der Richtung den passenden Advancer.
     */
    public function __construct(
        callable $advancerResolver,
        private readonly JobScheduler $scheduler,
        private readonly SyncLog $log,
        private readonly PeerRegistry $peers,
    ) {
        $this->advancerResolver = $advancerResolver;
    }

    public function boot(): void
    {
        $this->scheduler->bootSchedules();
        add_action(JobScheduler::CRON_HOOK, [$this, 'runWatchdog']);
        add_action('init', [$this->scheduler, 'ensureCronScheduled']);

        // Loopback-Tick-Endpoint. Userlos (nopriv), da der Loopback-Request keine Cookies
        // mitschickt. Authentifiziert wird NICHT per Nonce/Cap, sondern über den job-eigenen
        // spawn_token, den runTick() konstantzeit gegen den JobState prüft.
        add_action('wp_ajax_' . JobScheduler::TICK_ACTION, [$this, 'handleTickRequest']);
        add_action('wp_ajax_nopriv_' . JobScheduler::TICK_ACTION, [$this, 'handleTickRequest']);
    }

    /**
     * AJAX-Handler für den Loopback-Tick. Keine Nonce/Cap-Prüfung (userloser Kontext);
     * die Autorisierung erfolgt ausschließlich über den spawn_token in runTick().
     */
    public function handleTickRequest(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Userloser Loopback-Endpoint, Auth über spawn_token (hash_equals) in runTick(), nicht über Nonce.
        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $this->runTick($jobId, $token);

        wp_send_json_success();
    }

    /**
     * Führt einen Tick aus. Userlos aufrufbar (Loopback/Cron), daher Token-Prüfung statt Nonce.
     */
    public function runTick(string $jobId, string $token): void
    {
        $job = JobState::load($jobId);
        if ($job === null) {
            return;
        }

        // Konstantzeit-Vergleich gegen Timing-Angriffe auf den spawn_token.
        if ($job->spawnToken === '' || !hash_equals($job->spawnToken, $token)) {
            return;
        }

        if ($job->isFinished()) {
            return;
        }

        // Der Vorfall vom 2026-08-02: Selbstantrieb und Cron-Watchdog treffen
        // fuer denselben Lauf zusammen und arbeiten beide am selben Cursor.
        // Die Stillstands-Pruefung des Watchdogs schuetzt davor nicht: ein
        // Schritt, der laenger als die Schwelle braucht (grosse Tabelle, langer
        // Chunk), sieht von aussen genauso aus wie ein haengender. rh-backup
        // hatte diese Sperre laengst, hier fehlte sie.
        $riegel = 'sync_tick_' . $job->jobId;

        if (! TickLock::acquire($riegel, self::TICK_LOCK_TTL)) {
            return;
        }

        try {
            $this->tickInner($job);
        } finally {
            TickLock::release($riegel);
        }
    }

    /**
     * Der eigentliche Schritt. Getrennt, damit die Sperre in jedem Fall wieder
     * freigegeben wird, auch wenn hier etwas durchschlaegt.
     */
    private function tickInner(JobState $job): void
    {
        $job->markStarted();

        // Ab hier führt jeder Schritt Protokoll in einer Datei. Stirbt der Prozess mitten
        // im Tick (Speicher voll, Zeitlimit, Abschuss durch den Webserver), steht hinterher
        // trotzdem da, wo er war. Der Job-Zustand in der Datenbank kann das nicht leisten:
        // er liegt in genau der Tabelle, die ein Import gerade ersetzt.
        JobTrace::arm($job->jobId, ['stage' => $job->stage, 'direction' => $job->direction]);
        JobTrace::write($job->jobId, 'tick_start', ['stage' => $job->stage]);

        try {
            ($this->advancerResolver)($job)->advance($job);
        } catch (\Throwable $e) {
            JobTrace::write($job->jobId, 'tick_exception', [
                'stage' => $job->stage,
                'message' => $e->getMessage(),
            ]);
            $job->finishFailure($e->getMessage(), $job->stage);
            $this->logCompletion($job);
            return;
        }

        JobTrace::write($job->jobId, 'tick_end', ['stage' => $job->stage]);

        if ($job->isFinished()) {
            $this->logCompletion($job);
            return;
        }

        // Heartbeat + Frontend-Projektion, dann nächsten Tick anstoßen.
        $job->touch();
        $this->scheduler->spawnLoopback($job);
    }

    /**
     * Schreibt einen History-Eintrag, sobald ein Job final ist (done/failed), genau einmal.
     *
     * Der Tick-Pfad schließt Jobs über {@see JobState::finishSuccess()}/finishFailure() ab, die
     * selbst NICHT loggen. Ohne diesen zentralen Punkt bliebe der Verlauf leer. Idempotent über
     * das persistierte `logged`-Flag: mehrfacher Aufruf (verschiedene Abschlusswege, GC-Nachhol)
     * erzeugt keinen Doppel-Eintrag. Push (Upload) und Pull (Download) werden gleichermaßen
     * geloggt; der reine Inbound-Import auf der Ziel-Seite eines Push wird beim Initiator geloggt.
     */
    private function logCompletion(JobState $job): void
    {
        if (!$job->isFinished() || $job->logged) {
            return;
        }

        // Reiner Inbound-Import (Gegenseite eines Push): der Initiator loggt, nicht das Ziel.
        if ($job->direction !== SyncStatus::DIRECTION_PULL && $job->direction !== SyncStatus::DIRECTION_PUSH) {
            $job->logged = true;
            $job->save();
            return;
        }

        $peer = $this->peers->get($job->peerId);
        if ($peer === null) {
            $job->logged = true;
            $job->save();
            return;
        }

        $status = $job->stage === SyncStatus::PHASE_DONE ? 'success' : 'failed';

        $bytes = (int) ($job->summary['bytes'] ?? ($job->bytesTotal > 0 ? $job->bytesTotal : $job->bytesNow));

        $durationMs = 0;
        if ($job->startedAt !== null && $job->endedAt !== null) {
            $durationMs = max(0, ($job->endedAt - $job->startedAt) * 1000);
        }

        $error = is_array($job->error) && isset($job->error['message']) ? (string) $job->error['message'] : null;

        $manifest = is_array($job->cursor['source_manifest'] ?? null) ? $job->cursor['source_manifest'] : null;

        $safety = null;
        if (is_array($job->error) && !empty($job->error['safety_backup_path'])) {
            $safety = (string) $job->error['safety_backup_path'];
        } elseif (is_array($job->summary) && !empty($job->summary['safety_backup_path'])) {
            $safety = (string) $job->summary['safety_backup_path'];
        }

        $schedule = is_array($job->summary['schedule_rebuild'] ?? null)
            ? $job->summary['schedule_rebuild']
            : null;

        $this->log->record(
            $peer,
            $job->direction,
            $status,
            $bytes,
            (int) $durationMs,
            $error,
            SyncProfile::fromArray($job->profile),
            $manifest,
            $safety,
            $schedule
        );

        $job->logged = true;
        $job->save();
    }

    /**
     * Bleibt ein Job hängen, nachdem die Daten schon live stehen?
     *
     * Dann steckt nur der Nachlauf fest, der die Termine wiederherstellt. Das als
     * "fehlgeschlagen" zu melden wäre schlicht falsch: der Import hat funktioniert, und wer
     * die Meldung liest, würde nach einem Schaden suchen, den es nicht gibt. Gemeldet wird
     * stattdessen ein Erfolg ohne Termin-Bericht, und das heißt "nicht geprüft".
     */
    private function stuckInAftercare(JobState $job): bool
    {
        return $job->importCommitted
            && (string) ($job->cursor['ij_phase'] ?? '') === 'aftercare';
    }

    /**
     * Cron-Sicherheitsnetz: findet hängende Jobs (kein Heartbeat) und belebt sie wieder
     * bzw. bricht sie nach MAX_RETRIES sauber ab. Räumt verwaiste Index-Einträge auf.
     */
    public function runWatchdog(): void
    {
        foreach (JobState::index() as $jobId => $peerId) {
            $job = JobState::load($jobId);

            if ($job === null) {
                $this->forgetOrphan($jobId, $peerId);
                continue;
            }

            if ($job->isFinished() || !$job->isStale()) {
                continue;
            }

            if ($job->retries >= self::MAX_RETRIES) {
                if ($this->stuckInAftercare($job)) {
                    $job->finishSuccess([
                        'safety_backup_path' => $job->cursor['ij_safety_path'] ?? null,
                        'profile' => $job->profile,
                        'schedule_rebuild' => null,
                    ]);
                    $this->logCompletion($job);
                    continue;
                }

                $job->finishFailure(
                    __('Der Sync blieb stehen (kein Fortschritt mehr). Bitte neu starten.', 'rh-sync'),
                    $job->stage
                );
                $this->logCompletion($job);
                continue;
            }

            $job->retries++;
            $job->save();

            // Loopback war offenbar tot, darum hier direkt im Cron-Request weiterticken.
            $this->runTick($job->jobId, $job->spawnToken);
        }

        // Garbage Collection: verwaiste Temp-Dateien (abgebrochene Sessions/Workdirs), deren
        // Job nie sauber abschloss, nach 2 Stunden entfernen. Verhindert eine volllaufende
        // Platte bei großen Transfers.
        if (function_exists('rh_db_engine')) {
            rh_db_engine()->storage()->gcStaleJobs(2 * HOUR_IN_SECONDS);
        }

        $this->gcFinishedStates();

        // Eine vergessene Notluke ist genau die Altlast, die man Monate später auf einer
        // Kundensite findet. Nach Ablauf der Frist fliegt sie raus.
        (new RecoveryHatch())->gc();

        $this->gcSwapLeftovers();
    }

    /**
     * Entfernt Schattentabellen, die ein abgeschossener Lauf hinterlassen hat.
     *
     * Der Import räumt beim Start und bei jedem geordneten Ende selbst auf. Wird der
     * Prozess hart abgeschossen, kommt er zu keinem von beidem, und die Tabellen liegen bis
     * zum nächsten Lauf herum. Bei einer grossen Datenbank ist das der doppelte Platz.
     *
     * Nur wenn gerade kein Job läuft: sonst würde dieser Aufräumer einem laufenden Import
     * die Tabellen unter den Füssen wegziehen.
     */
    private function gcSwapLeftovers(): void
    {
        if (!class_exists(\RhDbEngine\TableSwap::class)) {
            return;
        }

        foreach (JobState::index() as $jobId => $peerId) {
            $job = JobState::load($jobId);
            if ($job !== null && !$job->isFinished()) {
                return;
            }
        }

        $dropped = (new \RhDbEngine\TableSwap())->dropLeftovers();
        if ($dropped > 0) {
            JobTrace::write('watchdog', 'leftovers_dropped', ['tables' => $dropped]);
        }
    }

    /**
     * Räumt abgeschlossene, bereits geloggte Job-State-Options auf, die eine Stunde nach dem
     * Ende noch herumliegen. `finishSuccess()`/finishFailure() entfernen den Job nur aus dem
     * Index, löschen die State-Option aber nicht (die UI zeigt den Abschluss noch an). Ohne
     * diesen GC blieben abgeschlossene States dauerhaft als verwaiste Options liegen.
     *
     * Zusätzlich Sicherheitsnetz: ein finaler, aber noch ungeloggter State (der finishende Tick
     * starb vor {@see logCompletion()}) wird hier nachgeloggt, bevor er später gepurged wird.
     */
    private function gcFinishedStates(): void
    {
        foreach (JobState::allStateJobIds() as $jobId) {
            $job = JobState::load($jobId);
            if ($job === null || !$job->isFinished()) {
                continue;
            }

            // Ungeloggte finale States nachloggen (garantiert die History, auch bei Tick-Tod).
            if (!$job->logged) {
                $this->logCompletion($job);
            }

            // Erst purgen, wenn geloggt UND das 1h-Grace-Fenster (UI-Anzeige) abgelaufen ist.
            if ($job->logged && $job->endedAt !== null && (time() - $job->endedAt) > HOUR_IN_SECONDS) {
                $job->purge();
            }
        }
    }

    /**
     * Index-Eintrag eines Jobs, dessen State-Option nicht mehr existiert, entfernen
     * und einen evtl. verwaisten Lock des Peers freigeben.
     */
    private function forgetOrphan(string $jobId, string $peerId): void
    {
        $index = JobState::index();
        unset($index[$jobId]);
        update_option(JobState::INDEX_OPTION, $index, false);

        $lock = get_option(JobState::LOCK_PREFIX . $peerId);
        if (is_array($lock) && ($lock['job_id'] ?? null) === $jobId) {
            delete_option(JobState::LOCK_PREFIX . $peerId);
        }
    }
}
