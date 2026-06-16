<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Live-Progress-Tracking für Pull/Push-Operationen.
 *
 * Pull/Push laufen synchron im Backend (kein Action-Scheduler, Phase 5c).
 * Damit das Frontend trotzdem Live-Updates zeigen kann, schreibt der
 * Operations-Code in jeder Phase den aktuellen Status in einen Transient,
 * den das Frontend per AJAX-Polling abfragt.
 *
 * Status-Struktur (im Transient):
 * {
 *   job_id, peer_id, direction, started_at, ended_at?,
 *   phase, message, bytes_now, bytes_total,
 *   profile: { content: true, ... },
 *   steps: [
 *     { id: 'manifest', label: '...', status: 'done', duration_ms: 240, message: '...' },
 *     ...
 *   ],
 *   summary?: { bytes, duration_ms, safety_backup_path, source_manifest, phase_timings },
 *   error?: { message, phase, safety_backup_path }
 * }
 *
 * Zusätzlich wird pro Peer ein Lock-Transient `rhbp_sync_lock_{peer_id}` mit der
 * aktiven `job_id` gesetzt, damit das UI parallele Syncs verhindern kann.
 */
final class SyncStatus
{
    public const TRANSIENT_PREFIX = 'rhbp_sync_status_';
    public const LOCK_PREFIX = 'rhbp_sync_lock_';
    public const TTL = 3600; // 1 Stunde

    public const DIRECTION_PULL = 'pull';
    public const DIRECTION_PUSH = 'push';
    // Reiner Inbound-Import auf der Ziel-Seite eines Push (eigener Tick-Job auf dem Peer).
    public const DIRECTION_IMPORT = 'import';

    public const PHASE_PREFLIGHT = 'preflight';
    public const PHASE_MANIFEST = 'manifest';
    public const PHASE_EXPORT = 'export';
    public const PHASE_UPLOAD = 'upload';
    public const PHASE_DOWNLOAD = 'download';
    public const PHASE_SAFETY = 'safety';
    public const PHASE_IMPORT = 'import';
    public const PHASE_DONE = 'done';
    public const PHASE_FAILED = 'failed';

    /**
     * Schritte für einen Pull-Vorgang (in der Reihenfolge).
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function pullSteps(): array
    {
        return [
            ['id' => self::PHASE_MANIFEST, 'label' => __('Verbindung prüfen', 'rh-sync')],
            ['id' => self::PHASE_EXPORT, 'label' => __('Snapshot auf Quelle erstellen', 'rh-sync')],
            ['id' => self::PHASE_DOWNLOAD, 'label' => __('Daten herunterladen', 'rh-sync')],
            ['id' => self::PHASE_SAFETY, 'label' => __('Lokales Sicherheits-Backup', 'rh-sync')],
            ['id' => self::PHASE_IMPORT, 'label' => __('Daten einspielen', 'rh-sync')],
        ];
    }

    /**
     * Schritte für einen Push-Vorgang.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function pushSteps(): array
    {
        return [
            ['id' => self::PHASE_EXPORT, 'label' => __('Lokalen Snapshot erstellen', 'rh-sync')],
            ['id' => self::PHASE_UPLOAD, 'label' => __('Daten hochladen', 'rh-sync')],
            ['id' => self::PHASE_IMPORT, 'label' => __('Ziel-Site spielt Daten ein', 'rh-sync')],
        ];
    }

    /**
     * Schritte für einen reinen Inbound-Import (Ziel-Seite eines Push).
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function importSteps(): array
    {
        return [
            ['id' => self::PHASE_SAFETY, 'label' => __('Sicherheits-Backup', 'rh-sync')],
            ['id' => self::PHASE_IMPORT, 'label' => __('Daten einspielen', 'rh-sync')],
        ];
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    public static function stepsForDirection(string $direction): array
    {
        return match ($direction) {
            self::DIRECTION_PUSH => self::pushSteps(),
            self::DIRECTION_IMPORT => self::importSteps(),
            default => self::pullSteps(),
        };
    }

    /**
     * Startet einen neuen Job, liefert die job_id.
     */
    public static function start(string $peerId, string $direction, SyncProfile $profile): string
    {
        $jobId = bin2hex(random_bytes(16));
        $steps = $direction === self::DIRECTION_PULL ? self::pullSteps() : self::pushSteps();
        $stepsState = [];
        foreach ($steps as $step) {
            $stepsState[] = [
                'id' => $step['id'],
                'label' => $step['label'],
                'status' => 'pending',
                'duration_ms' => null,
                'message' => null,
                'started_at' => null,
                'ended_at' => null,
            ];
        }

        $status = [
            'job_id' => $jobId,
            'peer_id' => $peerId,
            'direction' => $direction,
            'started_at' => time(),
            'ended_at' => null,
            'phase' => $stepsState[0]['id'] ?? self::PHASE_MANIFEST,
            'message' => '',
            'bytes_now' => 0,
            'bytes_total' => 0,
            'profile' => $profile->toArray(),
            'steps' => $stepsState,
            'summary' => null,
            'error' => null,
        ];

        set_transient(self::TRANSIENT_PREFIX . $jobId, $status, self::TTL);
        set_transient(self::LOCK_PREFIX . $peerId, $jobId, self::TTL);

        return $jobId;
    }

    /**
     * Markiert einen Step als laufend.
     */
    public static function beginStep(string $jobId, string $phase, string $message = ''): void
    {
        $status = self::get($jobId);
        if ($status === null) {
            return;
        }

        $status['phase'] = $phase;
        $status['message'] = $message;

        foreach ($status['steps'] as $i => $step) {
            if ($step['id'] === $phase) {
                $status['steps'][$i]['status'] = 'running';
                $status['steps'][$i]['started_at'] = microtime(true);
                $status['steps'][$i]['message'] = $message;
            }
        }

        set_transient(self::TRANSIENT_PREFIX . $jobId, $status, self::TTL);
    }

    /**
     * Aktualisiert den Byte-Counter während Download/Upload.
     */
    public static function progress(string $jobId, int $bytesNow, ?int $bytesTotal = null): void
    {
        $status = self::get($jobId);
        if ($status === null) {
            return;
        }

        $status['bytes_now'] = $bytesNow;
        if ($bytesTotal !== null) {
            $status['bytes_total'] = $bytesTotal;
        }

        set_transient(self::TRANSIENT_PREFIX . $jobId, $status, self::TTL);
    }

    /**
     * Markiert einen Step als erfolgreich abgeschlossen.
     */
    public static function completeStep(string $jobId, string $phase, string $message = ''): void
    {
        $status = self::get($jobId);
        if ($status === null) {
            return;
        }

        foreach ($status['steps'] as $i => $step) {
            if ($step['id'] === $phase) {
                $startedAt = $step['started_at'];
                $endedAt = microtime(true);
                $status['steps'][$i]['status'] = 'done';
                $status['steps'][$i]['ended_at'] = $endedAt;
                $status['steps'][$i]['duration_ms'] = is_numeric($startedAt)
                    ? (int) (($endedAt - $startedAt) * 1000)
                    : null;
                if ($message !== '') {
                    $status['steps'][$i]['message'] = $message;
                }
            }
        }

        set_transient(self::TRANSIENT_PREFIX . $jobId, $status, self::TTL);
    }

    /**
     * Schliesst den Job mit Erfolg ab.
     *
     * @param array<string, mixed> $summary
     */
    public static function done(string $jobId, array $summary): void
    {
        $status = self::get($jobId);
        if ($status === null) {
            return;
        }

        $status['phase'] = self::PHASE_DONE;
        $status['ended_at'] = time();
        $status['summary'] = $summary;
        $status['message'] = '';

        set_transient(self::TRANSIENT_PREFIX . $jobId, $status, self::TTL);

        $peerId = (string) ($status['peer_id'] ?? '');
        if ($peerId !== '') {
            delete_transient(self::LOCK_PREFIX . $peerId);
        }
    }

    /**
     * Schliesst den Job mit Fehler ab.
     */
    public static function failed(string $jobId, string $error, ?string $phase = null, ?string $safetyBackup = null): void
    {
        $status = self::get($jobId);
        if ($status === null) {
            return;
        }

        $effectivePhase = $phase ?? $status['phase'] ?? self::PHASE_FAILED;

        // Markiere den aktuellen Step als failed
        foreach ($status['steps'] as $i => $step) {
            if ($step['id'] === $effectivePhase && $step['status'] === 'running') {
                $startedAt = $step['started_at'];
                $endedAt = microtime(true);
                $status['steps'][$i]['status'] = 'failed';
                $status['steps'][$i]['ended_at'] = $endedAt;
                $status['steps'][$i]['duration_ms'] = is_numeric($startedAt)
                    ? (int) (($endedAt - $startedAt) * 1000)
                    : null;
                $status['steps'][$i]['message'] = $error;
            }
        }

        $status['phase'] = self::PHASE_FAILED;
        $status['ended_at'] = time();
        $status['error'] = [
            'message' => $error,
            'phase' => $effectivePhase,
            'safety_backup_path' => $safetyBackup,
        ];

        set_transient(self::TRANSIENT_PREFIX . $jobId, $status, self::TTL);

        $peerId = (string) ($status['peer_id'] ?? '');
        if ($peerId !== '') {
            delete_transient(self::LOCK_PREFIX . $peerId);
        }
    }

    /**
     * Projiziert den schlanken Frontend-Status aus einem {@see JobState} in den Polling-Transient.
     *
     * Der JobState (Option) ist die Wahrheit, der Transient nur die flüchtige Sicht fürs Frontend.
     * Das Format entspricht 1:1 dem bisherigen Status, erweitert um `last_update_at` und `stale`
     * (Stillstand-Erkennung), sodass das bestehende Polling-JS unverändert weiterläuft.
     */
    public static function project(JobState $job): void
    {
        $status = [
            'job_id' => $job->jobId,
            'peer_id' => $job->peerId,
            'direction' => $job->direction,
            'started_at' => $job->startedAt,
            'ended_at' => $job->endedAt,
            'last_update_at' => $job->lastUpdateAt,
            'phase' => $job->stage,
            'message' => $job->message,
            'bytes_now' => $job->bytesNow,
            'bytes_total' => $job->bytesTotal,
            'profile' => $job->profile,
            'steps' => $job->steps,
            'summary' => $job->summary,
            'error' => $job->error,
            'stale' => $job->isStale(),
        ];

        set_transient(self::TRANSIENT_PREFIX . $job->jobId, $status, self::TTL);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $jobId): ?array
    {
        $value = get_transient(self::TRANSIENT_PREFIX . $jobId);
        if (!is_array($value)) {
            return null;
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Liefert den aktuellen Job für einen Peer (wenn ein Sync läuft).
     *
     * @return array<string, mixed>|null
     */
    public static function forPeer(string $peerId): ?array
    {
        $jobId = get_transient(self::LOCK_PREFIX . $peerId);
        if (!is_string($jobId) || $jobId === '') {
            return null;
        }
        return self::get($jobId);
    }

    /**
     * Löscht einen Job-Status (nach UI-Acknowledge).
     */
    public static function clear(string $jobId): void
    {
        $status = self::get($jobId);
        if ($status !== null) {
            $peerId = (string) ($status['peer_id'] ?? '');
            if ($peerId !== '') {
                delete_transient(self::LOCK_PREFIX . $peerId);
            }
        }
        delete_transient(self::TRANSIENT_PREFIX . $jobId);
    }
}
