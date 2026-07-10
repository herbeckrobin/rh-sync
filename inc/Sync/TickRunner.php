<?php

declare(strict_types=1);

namespace RhSync\Sync;

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

        $job->markStarted();

        try {
            ($this->advancerResolver)($job)->advance($job);
        } catch (\Throwable $e) {
            $job->finishFailure($e->getMessage(), $job->stage);
            $this->logCompletion($job);
            return;
        }

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

        $this->log->record(
            $peer,
            $job->direction,
            $status,
            $bytes,
            (int) $durationMs,
            $error,
            SyncProfile::fromArray($job->profile),
            $manifest,
            $safety
        );

        $job->logged = true;
        $job->save();
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
