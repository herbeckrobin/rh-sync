<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Persistenter Zustand eines Sync-Jobs.
 *
 * Anders als der frühere reine SyncStatus-Transient lebt der Job-State in einer
 * autoload=no Option (`rhbp_sync_job_{jobId}`): ein über viele Hintergrund-Ticks laufender
 * 10-GB-Import darf seinen Resume-Cursor nicht durch einen Object-Cache-Flush verlieren.
 *
 * Daneben:
 *   - `rhbp_sync_lock_{peerId}`  Lock-Option (job_id + expires_at + last_update_at), Stale-erkennbar.
 *   - `rhbp_sync_jobs_index`     Map jobId => peerId aktiver Jobs (für Watchdog + GC).
 *
 * Der schlanke Frontend-Status (Polling) wird via {@see SyncStatus::project()} aus diesem
 * State in den bekannten Transient projiziert, der Frontend-Contract bleibt unverändert.
 */
final class JobState
{
    public const OPTION_PREFIX = 'rhbp_sync_job_';
    public const LOCK_PREFIX = 'rhbp_sync_lock_';
    public const INDEX_OPTION = 'rhbp_sync_jobs_index';

    public const TYPE_DB_SYNC = 'db_sync';
    public const TYPE_FS_SYNC = 'fs_sync';

    /** Stale-Schwelle: kein Heartbeat seit so vielen Sekunden => Job gilt als hängend. */
    public const STALE_AFTER = 90;

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $cursor
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed>|null $preflight
     * @param array<string, mixed>|null $summary
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        public string $jobId,
        public string $peerId,
        public string $direction,
        public string $type,
        public array $profile,
        public string $spawnToken,
        public int $createdAt,
        public ?int $startedAt = null,
        public ?int $endedAt = null,
        public int $lastUpdateAt = 0,
        public float $tickBudget = 20.0,
        public string $stage = SyncStatus::PHASE_PREFLIGHT,
        public array $cursor = [],
        public ?array $preflight = null,
        public string $message = '',
        public int $bytesNow = 0,
        public int $bytesTotal = 0,
        public array $steps = [],
        public ?array $summary = null,
        public ?array $error = null,
        public int $retries = 0,
        public bool $importCommitted = false,
    ) {
    }

    /**
     * Legt einen neuen Job an, persistiert ihn, setzt Lock + Index und projiziert den Status.
     */
    public static function create(string $peerId, string $direction, SyncProfile $profile, string $type = self::TYPE_DB_SYNC): self
    {
        $steps = SyncStatus::stepsForDirection($direction);
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

        $job = new self(
            jobId: bin2hex(random_bytes(16)),
            peerId: $peerId,
            direction: $direction,
            type: $type,
            profile: $profile->toArray(),
            spawnToken: bin2hex(random_bytes(16)),
            createdAt: time(),
            lastUpdateAt: time(),
            stage: $stepsState[0]['id'] ?? SyncStatus::PHASE_MANIFEST,
            steps: $stepsState,
        );

        $job->save();
        $job->acquireLock();
        $job->addToIndex();
        $job->project();

        return $job;
    }

    public static function load(string $jobId): ?self
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return null;
        }

        $data = get_option(self::OPTION_PREFIX . $jobId);
        if (!is_array($data)) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * Aktiver Job eines Peers (über das Lock), oder null.
     */
    public static function forPeer(string $peerId): ?self
    {
        $lock = get_option(self::LOCK_PREFIX . $peerId);
        if (!is_array($lock) || empty($lock['job_id']) || !is_string($lock['job_id'])) {
            return null;
        }

        return self::load($lock['job_id']);
    }

    /**
     * @return array<string, string> Map jobId => peerId aller indexierten Jobs.
     */
    public static function index(): array
    {
        $index = get_option(self::INDEX_OPTION);
        if (!is_array($index)) {
            return [];
        }

        $out = [];
        foreach ($index as $jobId => $peerId) {
            if (is_string($jobId) && is_string($peerId)) {
                $out[$jobId] = $peerId;
            }
        }

        return $out;
    }

    // ============================================================
    // Persistenz
    // ============================================================

    public function save(): void
    {
        $this->lastUpdateAt = time();
        update_option(self::OPTION_PREFIX . $this->jobId, $this->toArray(), false);
    }

    /**
     * Heartbeat: aktualisiert last_update_at + projiziert den Frontend-Status, ohne Stage-Wechsel.
     */
    public function touch(): void
    {
        $this->save();
        $this->refreshLock();
        $this->project();
    }

    /**
     * Projiziert den schlanken Frontend-Status in den Polling-Transient.
     */
    public function project(): void
    {
        SyncStatus::project($this);
    }

    public function markStarted(): void
    {
        if ($this->startedAt === null) {
            $this->startedAt = time();
        }
    }

    public function isStale(): bool
    {
        return $this->endedAt === null
            && (time() - $this->lastUpdateAt) > self::STALE_AFTER;
    }

    public function isFinished(): bool
    {
        return $this->stage === SyncStatus::PHASE_DONE || $this->stage === SyncStatus::PHASE_FAILED;
    }

    // ============================================================
    // Lifecycle: Lock + Index
    // ============================================================

    public function acquireLock(): void
    {
        update_option(self::LOCK_PREFIX . $this->peerId, [
            'job_id' => $this->jobId,
            'expires_at' => time() + 3600,
            'last_update_at' => time(),
        ], false);
    }

    public function refreshLock(): void
    {
        update_option(self::LOCK_PREFIX . $this->peerId, [
            'job_id' => $this->jobId,
            'expires_at' => time() + 3600,
            'last_update_at' => time(),
        ], false);
    }

    public function releaseLock(): void
    {
        $lock = get_option(self::LOCK_PREFIX . $this->peerId);
        // Nur freigeben, wenn der Lock wirklich diesem Job gehört (kein fremder Job-Lock).
        if (is_array($lock) && ($lock['job_id'] ?? null) === $this->jobId) {
            delete_option(self::LOCK_PREFIX . $this->peerId);
        }
    }

    private function addToIndex(): void
    {
        $index = self::index();
        $index[$this->jobId] = $this->peerId;
        update_option(self::INDEX_OPTION, $index, false);
    }

    private function removeFromIndex(): void
    {
        $index = self::index();
        unset($index[$this->jobId]);
        update_option(self::INDEX_OPTION, $index, false);
    }

    /**
     * Schließt den Job ab (Erfolg), gibt Lock frei, projiziert.
     *
     * @param array<string, mixed> $summary
     */
    public function finishSuccess(array $summary): void
    {
        $this->stage = SyncStatus::PHASE_DONE;
        $this->endedAt = time();
        $this->summary = $summary;
        $this->message = '';
        $this->save();
        $this->releaseLock();
        $this->removeFromIndex();
        $this->project();
    }

    /**
     * Schließt den Job ab (Fehler), gibt Lock frei, projiziert.
     */
    public function finishFailure(string $error, ?string $phase = null, ?string $safetyBackup = null): void
    {
        $effectivePhase = $phase ?? $this->stage;

        foreach ($this->steps as $i => $step) {
            if (($step['id'] ?? null) === $effectivePhase && ($step['status'] ?? null) === 'running') {
                $this->steps[$i]['status'] = 'failed';
                $this->steps[$i]['message'] = $error;
            }
        }

        $this->stage = SyncStatus::PHASE_FAILED;
        $this->endedAt = time();
        $this->error = [
            'message' => $error,
            'phase' => $effectivePhase,
            'safety_backup_path' => $safetyBackup,
        ];
        $this->save();
        $this->releaseLock();
        $this->removeFromIndex();
        $this->project();
    }

    /**
     * Räumt den Job vollständig ab (Option + Lock + Index). Für UI-Acknowledge oder GC.
     */
    public function purge(): void
    {
        $this->releaseLock();
        $this->removeFromIndex();
        delete_option(self::OPTION_PREFIX . $this->jobId);
        delete_transient(SyncStatus::TRANSIENT_PREFIX . $this->jobId);
    }

    // ============================================================
    // Step-Lifecycle (mutiert State, ruft KEIN save() automatisch)
    // ============================================================

    public function beginStep(string $phase, string $message = ''): void
    {
        $this->stage = $phase;
        $this->message = $message;
        foreach ($this->steps as $i => $step) {
            if (($step['id'] ?? null) === $phase) {
                $this->steps[$i]['status'] = 'running';
                $this->steps[$i]['started_at'] = microtime(true);
                $this->steps[$i]['message'] = $message;
            }
        }
    }

    public function completeStep(string $phase, string $message = ''): void
    {
        foreach ($this->steps as $i => $step) {
            if (($step['id'] ?? null) === $phase) {
                $startedAt = $step['started_at'] ?? null;
                $endedAt = microtime(true);
                $this->steps[$i]['status'] = 'done';
                $this->steps[$i]['ended_at'] = $endedAt;
                $this->steps[$i]['duration_ms'] = is_numeric($startedAt) ? (int) (($endedAt - $startedAt) * 1000) : null;
                if ($message !== '') {
                    $this->steps[$i]['message'] = $message;
                }
            }
        }
    }

    public function setProgress(int $bytesNow, ?int $bytesTotal = null): void
    {
        $this->bytesNow = $bytesNow;
        if ($bytesTotal !== null) {
            $this->bytesTotal = $bytesTotal;
        }
    }

    // ============================================================
    // Serialisierung
    // ============================================================

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'peer_id' => $this->peerId,
            'direction' => $this->direction,
            'type' => $this->type,
            'profile' => $this->profile,
            'spawn_token' => $this->spawnToken,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'last_update_at' => $this->lastUpdateAt,
            'tick_budget' => $this->tickBudget,
            'stage' => $this->stage,
            'cursor' => $this->cursor,
            'preflight' => $this->preflight,
            'message' => $this->message,
            'bytes_now' => $this->bytesNow,
            'bytes_total' => $this->bytesTotal,
            'steps' => $this->steps,
            'summary' => $this->summary,
            'error' => $this->error,
            'retries' => $this->retries,
            'import_committed' => $this->importCommitted,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['job_id'] ?? ''),
            peerId: (string) ($data['peer_id'] ?? ''),
            direction: (string) ($data['direction'] ?? SyncStatus::DIRECTION_PULL),
            type: (string) ($data['type'] ?? self::TYPE_DB_SYNC),
            profile: is_array($data['profile'] ?? null) ? $data['profile'] : [],
            spawnToken: (string) ($data['spawn_token'] ?? ''),
            createdAt: (int) ($data['created_at'] ?? time()),
            startedAt: isset($data['started_at']) ? (int) $data['started_at'] : null,
            endedAt: isset($data['ended_at']) ? (int) $data['ended_at'] : null,
            lastUpdateAt: (int) ($data['last_update_at'] ?? 0),
            tickBudget: (float) ($data['tick_budget'] ?? 20.0),
            stage: (string) ($data['stage'] ?? SyncStatus::PHASE_PREFLIGHT),
            cursor: is_array($data['cursor'] ?? null) ? $data['cursor'] : [],
            preflight: is_array($data['preflight'] ?? null) ? $data['preflight'] : null,
            message: (string) ($data['message'] ?? ''),
            bytesNow: (int) ($data['bytes_now'] ?? 0),
            bytesTotal: (int) ($data['bytes_total'] ?? 0),
            steps: is_array($data['steps'] ?? null) ? $data['steps'] : [],
            summary: is_array($data['summary'] ?? null) ? $data['summary'] : null,
            error: is_array($data['error'] ?? null) ? $data['error'] : null,
            retries: (int) ($data['retries'] ?? 0),
            importCommitted: (bool) ($data['import_committed'] ?? false),
        );
    }
}
