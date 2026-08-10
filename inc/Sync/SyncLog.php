<?php

declare(strict_types=1);

namespace RhSync\Sync;

final class SyncLog
{
    public const OPTION_NAME = 'rhbp_sync_log';
    public const MAX_ENTRIES = 50;

    /**
     * @param array<string, mixed>|null $manifest Quellen-Manifest (nur bei Pull)
     * @param array<string, mixed>|null $schedule Bericht des Termin-Nachlaufs. `null` heißt
     *                                            "nicht geprüft", ein leerer Bericht heißt
     *                                            "geprüft, nichts zu tun".
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
        ?string $safetyBackup = null,
        ?array $schedule = null
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
            'schedule' => $schedule,
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

    /**
     * Entfernt gezielt Einträge, die auf eine Bedingung passen.
     *
     * Gebraucht vom Selbsttest: sein eigener Lauf soll den Verlauf nicht zumüllen. `clear()`
     * wäre dafür zu grob, das würde auch die echten Läufe wegwerfen.
     *
     * @param callable(array<string, mixed>): bool $matcher
     * @return int Anzahl der entfernten Einträge.
     */
    public function forget(callable $matcher): int
    {
        $entries = $this->all();
        $kept = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => !$matcher($entry)
        ));

        $removed = count($entries) - count($kept);

        if ($removed > 0) {
            update_option(self::OPTION_NAME, $kept, false);
        }

        return $removed;
    }

    public function clear(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
