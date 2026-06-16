<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Treibt die Tick-Kette eines Sync-Jobs an: Loopback-Self-Spawn als Primärtakt,
 * WP-Cron-Watchdog als Sicherheitsnetz.
 *
 * Primär: Nach jedem Tick feuert {@see spawnLoopback()} einen nicht-blockierenden
 * Request an admin-ajax.php, der den nächsten Tick in einem frischen, kurzen Request
 * ausführt. So kann kein einzelner Request je in ein FPM-Timeout laufen.
 *
 * Fallback: Ist der Loopback blockiert (Basic-Auth, Firewall, selbstsigniertes Cert),
 * bleibt der Job stehen. Der Cron-Watchdog (siehe {@see TickRunner::runWatchdog()})
 * erkennt das über den fehlenden Heartbeat und übernimmt.
 */
final class JobScheduler
{
    public const TICK_ACTION = 'rhbp_sync_tick';
    public const CRON_HOOK = 'rhbp_sync_watchdog';
    public const CRON_INTERVAL = 'rhbp_minute';

    /**
     * Registriert das 1-Minuten-Cron-Intervall (WP kennt nativ nur hourly/twicedaily/daily).
     * Früh aufrufen (vor ensureCronScheduled), damit das Intervall beim Scheduling existiert.
     */
    public function bootSchedules(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules[self::CRON_INTERVAL])) {
                $schedules[self::CRON_INTERVAL] = [
                    'interval' => 60,
                    'display' => __('Jede Minute (RH Sync Watchdog)', 'rh-sync'),
                ];
            }
            return $schedules;
        });
    }

    public function ensureCronScheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public function clearCron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Feuert einen nicht-blockierenden Loopback-Request für den nächsten Tick.
     *
     * Authentifiziert wird userlos über den job-eigenen spawn_token (NICHT über eine
     * User-Nonce, da der Tick keinen eingeloggten User hat). Per Filter abschaltbar
     * (z.B. für Tests oder Umgebungen, in denen ausschließlich der Cron-Watchdog tickt).
     */
    public function spawnLoopback(JobState $job): void
    {
        if (apply_filters('rh-blueprint/sync/suppress_loopback', false, $job)) {
            return;
        }

        wp_remote_post(admin_url('admin-ajax.php'), [
            'blocking' => false,
            'timeout' => 0.01,
            // Loopback an die eigene Site: lokales/selbstsigniertes Cert nicht erzwingen.
            'sslverify' => (bool) apply_filters('rh-blueprint/sync/loopback_sslverify', false),
            'cookies' => [],
            'body' => [
                'action' => self::TICK_ACTION,
                'job_id' => $job->jobId,
                'token' => $job->spawnToken,
            ],
        ]);
    }
}
