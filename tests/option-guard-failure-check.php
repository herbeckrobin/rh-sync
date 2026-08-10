<?php
/**
 * Was passiert, wenn der Schutz nicht greift?
 *
 * Der Schadensfall wird erzwungen: kurz bevor der Schutz in die Schattentabelle schreibt,
 * wird sie weggezogen. Erwartet wird, dass der Lauf abbricht, nicht umschaltet und die
 * Website unverändert weiterläuft. Vorher wäre er stillschweigend durchgelaufen und hätte
 * die Adresse, die Plugin-Liste und die Kopplung der Quelle stehen lassen.
 *
 *   ddev wp eval-file rh-sync/tests/option-guard-failure-check.php
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

global $wpdb;

const PROBE_PEER = 'Sonde Schadensfall';

add_filter('rh-blueprint/sync/suppress_loopback', '__return_true');
add_filter('rh-blueprint/sync/sslverify', '__return_false');
add_filter('rh-blueprint/sync/loopback_sslverify', '__return_false');

// Der Schadensfall: die Schattentabelle verschwindet, kurz bevor der Schutz sie füllt.
add_action('rh-db-engine/before_table_swap', static function (string $stagePrefix): void {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS `{$stagePrefix}options`");
    echo "  >> Schattentabelle {$stagePrefix}options weggezogen\n";
}, 9, 1);

$engine = rh_db_engine();
$peers = new PeerRegistry();
$log = new SyncLog();
$client = new SyncClient(new HmacAuth($peers));
$pull = new PullOperation($client, $engine->exporter(), $engine->importer(), $engine->storage(), $log, $peers);
$push = new PushOperation($client, $engine->exporter(), $log, $engine->storage(), $peers);
$importAdvancer = new ImportJobAdvancer($engine->importer(), $engine->exporter(), $engine->storage());
$resolver = static function (JobState $job) use ($pull, $push, $importAdvancer): StageAdvancer {
    return match ($job->direction) {
        SyncStatus::DIRECTION_PUSH => $push,
        SyncStatus::DIRECTION_IMPORT => $importAdvancer,
        default => $pull,
    };
};
$ticker = new TickRunner($resolver, new JobScheduler(), $log, $peers);

$vorher = [
    'upload_path' => (string) get_option('upload_path', ''),
    'blogdescription' => (string) get_option('blogdescription', ''),
];
update_option('upload_path', '/QUELLE');
update_option('blogdescription', 'QUELLE');

$alt = $peers->getByName(PROBE_PEER);
if ($alt !== null) {
    $peers->remove($alt->id);
}
$basis = Peer::create(PROBE_PEER, home_url());
$peer = new Peer(
    id: $basis->id,
    name: $basis->name,
    url: $basis->url,
    token: $basis->token,
    lastSync: null,
    createdAt: time(),
    profile: new SyncProfile(false, false, false, false, true, false, false, false),
    permissions: new SyncPermissions(true, true, true, true),
);
$peers->add($peer);

$job = JobState::create($peer->id, SyncStatus::DIRECTION_PULL, $peer->profile);
$jobId = $job->jobId;
echo "Lauf {$jobId}\n";

$gesetzt = false;
for ($i = 0; $i < 60; $i++) {
    $ticker->runTick($jobId, $job->spawnToken);
    $job = JobState::load($jobId);
    if ($job === null) {
        break;
    }

    if (!$gesetzt && !in_array($job->stage, [SyncStatus::PHASE_MANIFEST, SyncStatus::PHASE_EXPORT, SyncStatus::PHASE_DOWNLOAD], true)) {
        update_option('upload_path', '/ZIEL');
        update_option('blogdescription', 'ZIEL');
        $gesetzt = true;
    }

    if ($job->isFinished()) {
        break;
    }
}

wp_cache_flush();
$uploadPath = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'upload_path'");
$blog = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogdescription'");
$meldung = is_array($job?->error) ? (string) ($job->error['message'] ?? '') : '';

echo "\nErgebnis:\n";
echo "  Endstand: " . ($job?->stage ?? '?') . "\n";
echo "  Meldung:  " . $meldung . "\n";
echo "  upload_path:     '{$uploadPath}'\n";
echo "  blogdescription: '{$blog}'\n\n";

$abgebrochen = ($job?->stage ?? '') === SyncStatus::PHASE_FAILED;
$unberuehrt = $uploadPath === '/ZIEL' && $blog === 'ZIEL';
$erklaert = str_contains($meldung, 'nicht gegriffen') || str_contains($meldung, 'nicht ersetzen');

echo ($abgebrochen ? "[ok]   " : "[FEHL] ") . "Der Lauf ist als Fehlschlag geendet.\n";
echo ($unberuehrt ? "[ok]   " : "[FEHL] ") . "Die Website ist unverändert (auch die ungeschützte Option).\n";
echo ($erklaert ? "[ok]   " : "[FEHL] ") . "Die Meldung nennt den Grund.\n";

update_option('upload_path', $vorher['upload_path']);
update_option('blogdescription', $vorher['blogdescription']);
$peers->remove($peer->id);
$log->forget(static fn (array $e): bool => (string) ($e['peer_name'] ?? '') === PROBE_PEER);
(new RhDbEngine\TableSwap())->dropLeftovers();
echo "\nAufgeräumt.\n";
