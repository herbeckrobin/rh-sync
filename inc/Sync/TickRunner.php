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
            return;
        }

        if ($job->isFinished()) {
            return;
        }

        // Heartbeat + Frontend-Projektion, dann nächsten Tick anstoßen.
        $job->touch();
        $this->scheduler->spawnLoopback($job);
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
