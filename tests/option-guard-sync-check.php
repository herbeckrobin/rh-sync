<?php
/**
 * Der gute Fall, gegen eine echte Datenbank: überlebt eine geschützte Option den Import?
 *
 *   ddev wp eval-file rh-sync/tests/option-guard-sync-check.php
 *
 * Aufbau: die Website koppelt sich mit sich selbst und zieht nur die Einstellungen.
 * Zwischen dem Download und dem Import werden Marker gesetzt. Das Archiv trägt dann
 * den alten Wert (die Rolle der Quelle), die Datenbank den neuen (die Rolle des Ziels).
 * Genau diese Abweichung fehlt einem Sync mit sich selbst sonst, und ohne sie beweist
 * ein grüner Lauf gar nichts.
 *
 *   upload_path      geschützt   muss nach dem Import ZIEL sein
 *   blogdescription  ungeschützt muss nach dem Import QUELLE sein (Kontrolle)
 *   die Kopplung     steht nicht mehr im Archiv und muss trotzdem überleben
 *   eine ausgelassene Tabelle, die die Quelle benutzt, muss gemeldet werden
 *
 * Räumt hinterher auf: Marker zurück, Kopplung weg, Verlaufseintrag weg.
 */

use RhSync\Sync\ImportJobAdvancer;
use RhSync\Sync\JobScheduler;
use RhSync\Sync\JobState;
use RhSync\Sync\Peer;
use RhSync\Sync\PeerRegistry;
use RhSync\Sync\PullOperation;
use RhSync\Sync\PushOperation;
use RhSync\Sync\StageAdvancer;
use RhSync\Sync\SyncClient;
use RhSync\Sync\SyncLog;
use RhSync\Sync\SyncPermissions;
use RhSync\Sync\SyncProfile;
use RhSync\Sync\SyncStatus;
use RhSync\Sync\HmacAuth;
use RhSync\Sync\TickRunner;

if (!class_exists(PeerRegistry::class)) {
    echo "rh-sync ist nicht geladen.\n";
    return;
}

const PROBE_PEER = 'Sonde Options-Guard';

add_filter('rh-blueprint/sync/suppress_loopback', '__return_true');
add_filter('rh-blueprint/sync/sslverify', '__return_false');
add_filter('rh-blueprint/sync/loopback_sslverify', '__return_false');

$engine = rh_db_engine();
$peers = new PeerRegistry();
$log = new SyncLog();
$client = new SyncClient(new HmacAuth($peers));
$pull = new PullOperation($client, $engine->exporter(), $engine->importer(), $engine->storage(), $log, $peers);
$push = new PushOperation($client, $engine->exporter(), $log, $engine->storage(), $peers);
$importer = new ImportJobAdvancer($engine->importer(), $engine->exporter(), $engine->storage());
$resolver = static function (JobState $job) use ($pull, $push, $importer): StageAdvancer {
    return match ($job->direction) {
        SyncStatus::DIRECTION_PUSH => $push,
        SyncStatus::DIRECTION_IMPORT => $importer,
        default => $pull,
    };
};
$ticker = new TickRunner($resolver, new JobScheduler(), $log, $peers);

// --- Ausgangslage ------------------------------------------------------------
$vorher = [
    'upload_path' => (string) get_option('upload_path', ''),
    'blogdescription' => (string) get_option('blogdescription', ''),
];

update_option('upload_path', '/QUELLE');
update_option('blogdescription', 'QUELLE');
echo "Ausgangslage gesetzt: upload_path=/QUELLE, blogdescription=QUELLE\n";

// Eine der ausgelassenen Tabellen, die es hier gibt. Sie verschwindet gleich mitten im
// Lauf, damit die Lage der Zielseite entsteht: die Quelle benutzt sie, hier fehlt sie.
global $wpdb;
$warteschlange = $wpdb->prefix . 'actionscheduler_actions';
$wpdb->query("CREATE TABLE IF NOT EXISTS `{$warteschlange}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)");
echo "Warteschlangen-Tabelle {$warteschlange} angelegt\n";

// --- Kopplung mit sich selbst ------------------------------------------------
$alt = $peers->getByName(PROBE_PEER);
if ($alt !== null) {
    $peers->remove($alt->id);
}

$basis = Peer::create(PROBE_PEER, home_url());
$profil = new SyncProfile(
    content: false,
    taxonomies: false,
    comments: false,
    users: false,
    options: true,
    links: false,
    customTables: false,
    uploads: false,
);
$peer = new Peer(
    id: $basis->id,
    name: $basis->name,
    url: $basis->url,
    token: $basis->token,
    lastSync: null,
    createdAt: time(),
    profile: $profil,
    permissions: new SyncPermissions(true, true, true, true),
);
$peers->add($peer);
echo "Kopplung angelegt, Profil: nur Einstellungen\n";

// --- Lauf --------------------------------------------------------------------
$job = JobState::create($peer->id, SyncStatus::DIRECTION_PULL, $peer->profile);
$jobId = $job->jobId;

// Winziges Zeitbudget: der Import zerfällt in viele Ticks, das Umschalten und der
// Abschluss landen in verschiedenen Durchgängen. Genau so lief es bei ADKRU, und nur
// dann greift zusätzlich der Weg über die Live-Tabelle.
$job->tickBudget = 0.05;
$job->save();
echo "Lauf {$jobId} (Zeitbudget {$job->tickBudget}s)\n";

$gesetzt = false;
$letzte = '';
$ticks = 0;

while ($ticks < 200) {
    $ticks++;
    $ticker->runTick($jobId, $job->spawnToken);

    $job = JobState::load($jobId);
    if ($job === null) {
        echo "Der Zustand des Laufs ist verschwunden.\n";
        break;
    }

    $stufe = $job->stage . '/' . (string) ($job->cursor['ij_phase'] ?? '-');
    if ($stufe !== $letzte) {
        echo "  {$stufe}\n";
        $letzte = $stufe;
    }

    // Sobald das Archiv da ist: Ziel-Marker setzen. Ab hier weicht die Datenbank
    // vom Archiv ab, genau wie bei zwei echten Websites.
    if (!$gesetzt && !in_array($job->stage, [SyncStatus::PHASE_MANIFEST, SyncStatus::PHASE_EXPORT, SyncStatus::PHASE_DOWNLOAD], true)) {
        update_option('upload_path', '/ZIEL');
        update_option('blogdescription', 'ZIEL');
        $wpdb->query("DROP TABLE IF EXISTS `{$warteschlange}`");
        $gesetzt = true;
        echo "  >> Ziel-Marker gesetzt, Warteschlangen-Tabelle entfernt\n";
    }

    if ($job->isFinished()) {
        break;
    }
}

echo "Ticks: {$ticks}, Endstand: {$job->stage}\n";
if (is_array($job->error)) {
    echo "Fehler: " . (string) ($job->error['message'] ?? '') . "\n";
}

// --- Auswertung --------------------------------------------------------------
wp_cache_flush();
global $wpdb;
$uploadPath = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'upload_path'");
$blogBeschreibung = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogdescription'");

echo "\nErgebnis:\n";
echo "  upload_path      (geschützt)   = '{$uploadPath}'  erwartet '/ZIEL'\n";
echo "  blogdescription  (ungeschützt) = '{$blogBeschreibung}'  erwartet 'QUELLE'\n";

$guardOk = $uploadPath === '/ZIEL';
$importOk = $blogBeschreibung === 'QUELLE';

// Die Kopplung selbst ist der eigentliche Fall: sie steht nicht mehr im Archiv und muss
// den Import trotzdem überstehen, sonst ist die Website danach von nichts mehr erreichbar.
$peerDa = $peers->get($peer->id) !== null;

echo "\n";
echo $importOk ? "[ok]   Der Import hat die Options-Tabelle wirklich ersetzt.\n" : "[FEHL] Der Import hat die Options-Tabelle gar nicht angefasst, die Sonde beweist nichts.\n";
echo $guardOk ? "[ok]   Die geschützte Option hat überlebt.\n" : "[FEHL] Die geschützte Option wurde überschrieben, der ADKRU-Fall ist reproduziert.\n";
echo $peerDa ? "[ok]   Die Kopplung steht noch.\n" : "[FEHL] Die Kopplung ist weg.\n";

$hinweise = is_array($job->summary['notes'] ?? null) ? $job->summary['notes'] : [];
$tabellenHinweis = null;
foreach ($hinweise as $hinweis) {
    if (($hinweis['title'] ?? '') === 'Fehlende Tabellen') {
        $tabellenHinweis = $hinweis;
    }
}
$gemeldet = $tabellenHinweis !== null && in_array($warteschlange, (array) ($tabellenHinweis['items'] ?? []), true);
echo $gemeldet
    ? "[ok]   Die fehlende Warteschlangen-Tabelle steht im Bericht.\n"
    : "[FEHL] Die fehlende Warteschlangen-Tabelle wurde nicht gemeldet.\n";

// --- Aufräumen ---------------------------------------------------------------
update_option('upload_path', $vorher['upload_path']);
update_option('blogdescription', $vorher['blogdescription']);
$peers->remove($peer->id);
$log->forget(static function (array $eintrag): bool {
    return (string) ($eintrag['peer_name'] ?? '') === PROBE_PEER;
});
echo "\nAufgeräumt.\n";
