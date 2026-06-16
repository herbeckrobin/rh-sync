<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Vorab-Prüfung vor einem Sync: erhebt die relevanten Server-Limits und den freien
 * Plattenplatz und bewertet, ob ein Transfer der erwarteten Größe durchlaufen kann.
 *
 * Liefert dem Frontend eine Datengrundlage, um VOR dem Klick zu warnen ("dieser Sync
 * überträgt X, dein Server hat Y frei") statt mitten im Lauf an einer vollen Platte zu
 * scheitern. Bei klar zu wenig Plattenplatz wird `blocking` gesetzt.
 */
final class Preflight
{
    /** Sicherheitsfaktor: Download/Export + Safety-Backup + Extraktion liegen temporär parallel. */
    private const DISK_SAFETY_FACTOR = 3.0;

    /**
     * Lokale Server-Limits dieser Instanz.
     *
     * @return array<string, mixed>
     */
    public static function localLimits(): array
    {
        $contentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH;
        $diskFree = @disk_free_space($contentDir);

        return [
            'memory_limit' => self::bytesFromIni((string) ini_get('memory_limit')),
            'post_max_size' => self::bytesFromIni((string) ini_get('post_max_size')),
            'upload_max_filesize' => self::bytesFromIni((string) ini_get('upload_max_filesize')),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'disk_free_bytes' => $diskFree === false ? null : (int) $diskFree,
        ];
    }

    /**
     * Bewertet einen geplanten Sync anhand der erwarteten Datenmenge und des lokalen freien Platzes.
     *
     * @param int $expectedBytes Erwartete Nutzdatenmenge (DB + ggf. Uploads).
     * @return array{warnings: array<int, string>, blocking: bool, needed_bytes: int, disk_free_bytes: ?int}
     */
    public static function assessLocalDisk(int $expectedBytes): array
    {
        $limits = self::localLimits();
        $diskFree = $limits['disk_free_bytes'] ?? null;
        $needed = (int) ($expectedBytes * self::DISK_SAFETY_FACTOR);

        $warnings = [];
        $blocking = false;

        if (is_int($diskFree)) {
            if ($diskFree < $needed) {
                $blocking = true;
                $warnings[] = sprintf(
                    /* translators: %1$s = benötigt, %2$s = verfügbar */
                    __('Zu wenig Plattenplatz: für diesen Sync werden ca. %1$s benötigt, frei sind nur %2$s.', 'rh-sync'),
                    size_format($needed),
                    size_format($diskFree)
                );
            } elseif ($diskFree < $needed * 1.5) {
                $warnings[] = sprintf(
                    /* translators: %s = verfügbar */
                    __('Plattenplatz wird knapp (nur noch %s frei). Der Sync sollte durchlaufen, aber mit wenig Reserve.', 'rh-sync'),
                    size_format($diskFree)
                );
            }
        }

        return [
            'warnings' => $warnings,
            'blocking' => $blocking,
            'needed_bytes' => $needed,
            'disk_free_bytes' => is_int($diskFree) ? $diskFree : null,
        ];
    }

    /**
     * Schätzt grob die Sync-Dauer (für eine UI-Anzeige), sehr konservativ.
     */
    public static function estimateSeconds(int $bytes): int
    {
        // Sehr grobe Annahme: ~5 MB/s effektiv (Export + Transfer + Import zusammen).
        $perSecond = 5 * 1024 * 1024;
        return (int) max(1, ceil($bytes / $perSecond));
    }

    /**
     * Wandelt einen ini-Größenwert ("256M", "1G", "512K") in Bytes. -1 (unbegrenzt) => PHP_INT_MAX.
     */
    public static function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
