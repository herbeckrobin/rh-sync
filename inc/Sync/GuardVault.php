<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Ablage für den Options-Snapshot, ausserhalb der Datenbank.
 *
 * Der Snapshot lag bisher im Job-Cursor, und der Job-Cursor ist eine Option. Die
 * Rettungsleine lag damit in dem Boot, das gerade sinkt: als der Import am 2026-08-02 die
 * Options-Tabelle ersetzte, war der Snapshot mit weg, und mit ihm die einzige Kopie von
 * Adresse, aktiven Plugins und Rollen der Zielseite.
 *
 * Hier liegt er als Datei neben dem Sicherheits-Backup. Er überlebt jeden Abbruch, den die
 * Datenbank nicht überlebt, und ist auch dann noch lesbar, wenn WordPress selbst nicht mehr
 * startet.
 */
final class GuardVault
{
    private const PREFIX = 'guard-';

    /**
     * Legt den Snapshot ab und gibt den Pfad zurück.
     *
     * @param array<int, array{option_name: string, option_value: string, autoload: string}> $snapshot
     * @throws \RuntimeException wenn nicht geschrieben werden kann. Ein Import ohne Rettungsleine
     *                           soll gar nicht erst anfangen.
     */
    public function store(string $jobId, array $snapshot): string
    {
        $storage = rh_db_engine()->storage();
        $storage->ensureReady();

        $path = $this->path($jobId);
        $json = wp_json_encode([
            'job_id' => $jobId,
            'created_at' => gmdate('c'),
            'site_url' => get_site_url(),
            'table_prefix' => $GLOBALS['wpdb']->prefix,
            'options' => $snapshot,
        ]);

        if (!is_string($json)) {
            throw new \RuntimeException('Options-Snapshot konnte nicht als JSON abgelegt werden.');
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- eigene Datei in der abgeschirmten Ablage, muss auch ohne WP_Filesystem-Zugang funktionieren.
        if (@file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
            throw new \RuntimeException('Options-Snapshot konnte nicht geschrieben werden: ' . $path);
        }

        $storage->protectFile($path);

        return $path;
    }

    /**
     * @return array<int, array{option_name: string, option_value: string, autoload: string}>|null
     */
    public function load(string $jobId): ?array
    {
        $path = $this->path($jobId);
        if (!is_readable($path)) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- eigene Datei in der abgeschirmten Ablage.
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['options'] ?? null)) {
            return null;
        }

        $out = [];
        foreach ($data['options'] as $row) {
            if (is_array($row) && isset($row['option_name'], $row['option_value'])) {
                $out[] = [
                    'option_name' => (string) $row['option_name'],
                    'option_value' => (string) $row['option_value'],
                    'autoload' => (string) ($row['autoload'] ?? 'no'),
                ];
            }
        }

        return $out;
    }

    public function clear(string $jobId): void
    {
        $path = $this->path($jobId);
        if (is_file($path)) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Aufräumen der eigenen Datei, unkritisch.
            @unlink($path);
        }
    }

    public function path(string $jobId): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', $jobId) ?? '';

        return trailingslashit(rh_db_engine()->storage()->jobsPath()) . self::PREFIX . $safe . '.json';
    }
}
