<?php
/**
 * Gegenprobe am echten Archiv: was steht im Dump?
 *
 *   Transportware  (Export für einen Peer)  darf die Sync-Eigenwerte NICHT enthalten
 *   Sicherungskopie (presync, Rollback)     muss vollständig sein
 *
 *   ddev wp eval-file rh-sync/tests/export-exclusion-check.php
 */

use RhSync\Sync\LocalOptionGuard;
use RhSync\Sync\SyncDefaults;

global $wpdb;

$engine = rh_db_engine();
$exporter = $engine->exporter();
$storage = $engine->storage();

// Es muss etwas zu finden geben, sonst beweist ein leeres Ergebnis nichts.
update_option('rhbp_peers', [['id' => 'probe', 'url' => 'https://gegenseite.example', 'token' => 'geheim']]);
update_option('rhbp_sync_log', [['peer_name' => 'Probe', 'direction' => 'pull']]);

function dumpAus(string $zip): string
{
    $ziel = sys_get_temp_dir() . '/probe-export-' . wp_generate_password(8, false, false);
    mkdir($ziel, 0700, true);
    $archiv = new ZipArchive();
    $archiv->open($zip);
    $archiv->extractTo($ziel);
    $archiv->close();
    $sql = (string) file_get_contents($ziel . '/db.sql');
    $manifest = (string) file_get_contents($ziel . '/manifest.json');
    array_map('unlink', (array) glob($ziel . '/*'));
    rmdir($ziel);

    return $sql . "\n" . $manifest;
}

function enthaelt(string $dump, string $name): bool
{
    return str_contains($dump, "'" . $name . "'");
}

function pruefe(string $label, bool $ok): int
{
    static $fehler = 0;
    if ($label !== '') {
        echo ($ok ? '  [ok]   ' : '  [FEHL] ') . $label . "\n";
        if (!$ok) {
            $fehler++;
        }
    }

    return $fehler;
}

// --- 1. Transportware, wie sie der Peer abholt -------------------------------
$transport = $exporter->createBackup(
    false,
    SyncDefaults::excludedTables(),
    $storage->jobWorkdir('probe-transport'),
    LocalOptionGuard::engineOptions()
);
$dumpTransport = dumpAus($transport);

echo "Transportware (" . size_format((int) filesize($transport)) . "):\n";
pruefe('Die Peer-Liste fehlt', !enthaelt($dumpTransport, 'rhbp_peers'));
pruefe('Der Verlauf fehlt', !enthaelt($dumpTransport, 'rhbp_sync_log'));
pruefe('Das Token taucht nirgends auf', !str_contains($dumpTransport, 'gegenseite.example'));
pruefe('Die Inhalte sind trotzdem da', enthaelt($dumpTransport, 'blogname'));
pruefe('Modul-Einstellungen sind da', str_contains($dumpTransport, 'rhbp_settings_'));
pruefe('Das Manifest sagt, was fehlt', str_contains($dumpTransport, '"excluded_options"') && str_contains($dumpTransport, 'rhbp_sync_*'));

// --- 2. Sicherungskopie, wie sie der Rollback braucht ------------------------
$sicherung = $exporter->createBackup(
    false,
    SyncDefaults::excludedTables(),
    $storage->jobWorkdir('probe-sicherung')
);
$dumpSicherung = dumpAus($sicherung);

echo "\nSicherungskopie (" . size_format((int) filesize($sicherung)) . "):\n";
pruefe('Die Peer-Liste ist drin', enthaelt($dumpSicherung, 'rhbp_peers'));
pruefe('Der Verlauf ist drin', enthaelt($dumpSicherung, 'rhbp_sync_log'));
pruefe('Das Token ist drin', str_contains($dumpSicherung, 'gegenseite.example'));

// --- Aufräumen ---------------------------------------------------------------
foreach ([$transport, $sicherung] as $datei) {
    if (is_file($datei)) {
        unlink($datei);
    }
}
delete_option('rhbp_peers');
delete_option('rhbp_sync_log');

$fehler = pruefe('', true);
echo "\n" . ($fehler === 0 ? "Alles wie erwartet.\n" : "{$fehler} Abweichung(en).\n");
