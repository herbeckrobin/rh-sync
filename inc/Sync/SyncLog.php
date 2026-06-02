<?php

declare(strict_types=1);

namespace RhSync\Sync;

final class SyncLog
{
    public const OPTION_NAME = 'rhbp_sync_log';
    public const MAX_ENTRIES = 50;

    /**
     * @param array<string, mixed>|null $manifest Quellen-Manifest (nur bei Pull)
     */
    public function record(
        Peer $peer,
        string $direction,
        string $status,
        int $bytes = 0,
        int $durationMs = 0,
        ?string $error = null,
        ?SyncProfile $profile = null,
        ?array $manifest = null,
        ?string $safetyBackup = null
    ): void {
        /** @var array<int, array<string, mixed>> $entries */
        $entries = (array) get_option(self::OPTION_NAME, []);

        array_unshift($entries, [
            'peer_id' => $peer->id,
            'peer_name' => $peer->name,
            'peer_url' => $peer->url,
            'direction' => $direction,
            'status' => $status,
            'bytes' => $bytes,
            'duration_ms' => $durationMs,
            'error' => $error,
            'timestamp' => time(),
            'profile' => $profile?->toArray(),
            'manifest' => $manifest,
            'safety_backup' => $safetyBackup,
        ]);

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, 0, self::MAX_ENTRIES);
        }

        update_option(self::OPTION_NAME, $entries, false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<int, array<string, mixed>> $entries */
        $entries = (array) get_option(self::OPTION_NAME, []);

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forPeer(string $peerId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $entry): bool => ($entry['peer_id'] ?? '') === $peerId
        ));
    }

    public function clear(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
