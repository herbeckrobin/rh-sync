<?php

declare(strict_types=1);

namespace RhSync\Cli;

use RhSync\Sync\JobState;
use RhSync\Sync\JobTrace;
use RhSync\Sync\PeerRegistry;
use RhSync\Sync\Preflight;
use RhSync\Sync\ScheduleRebuilder;
use RhSync\Sync\ScheduleReport;
use RhSync\Sync\SyncLog;
use RhSync\Sync\TickRunner;

/**
 * Werkzeuge für rh-sync auf der Kommandozeile.
 *
 * Zwei Gruppen, absichtlich getrennt:
 *
 *   Nachsehen (ändert nichts, überall gefahrlos):
 *     wp rh sync status      Überblick über Peers, Läufe und Termine
 *     wp rh sync peers       Die gekoppelten Websites
 *     wp rh sync jobs        Laufende und letzte Läufe
 *     wp rh sync job <id>    Ein Lauf im Detail, inklusive Termin-Bericht
 *     wp rh sync schedule    Zustand der Termine geplanter Beiträge
 *     wp rh sync trace       Die letzten Zeilen aus dem Verlaufsprotokoll
 *     wp rh sync doctor      Umgebung, Grenzen und was davon knapp wird
 *
 *   Eingreifen:
 *     wp rh sync schedule-repair   Fehlende Termine wiederherstellen (auch auf Produktion)
 *     wp rh sync fixture <was>     Testdaten anlegen, kaputtmachen, wegräumen
 *     wp rh sync selftest          Kompletter Durchlauf gegen die eigene Website
 *
 * Die letzten beiden legen Daten an und verweigern deshalb auf einer Produktionsseite den
 * Dienst. Das Reparieren nicht: es stellt nur wieder her, was ohnehin dastehen sollte.
 *
 * Peer-Zugangsdaten werden nirgends ausgegeben, auch nicht gekürzt.
 */
final class SyncCommand
{
    public function __construct(
        private readonly PeerRegistry $peers,
        private readonly SyncLog $log,
        private readonly TickRunner $ticker,
    ) {
    }

    /**
     * Überblick: Umgebung, gekoppelte Websites, laufende Läufe, Zustand der Termine.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Ausgabeform.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rh sync status
     *     wp rh sync status --format=json
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $inspector = new ScheduleInspector();
        $posts = $inspector->posts();
        $strays = $inspector->strays();
        $summary = $inspector->summary($posts, $strays);

        $running = 0;
        foreach (JobState::index() as $jobId => $peerId) {
            $job = JobState::load((string) $jobId);
            if ($job !== null && !$job->isFinished()) {
                $running++;
            }
        }

        $history = $this->log->all();
        $last = $history[0] ?? null;

        $rows = [
            ['posten' => 'Umgebung', 'wert' => Guard::environment()],
            ['posten' => 'Plugin-Version', 'wert' => defined('RHSYNC_VERSION') ? RHSYNC_VERSION : '?'],
            ['posten' => 'Gekoppelte Websites', 'wert' => (string) count($this->peers->all())],
            ['posten' => 'Läufe gerade aktiv', 'wert' => (string) $running],
            ['posten' => 'Läufe im Verlauf', 'wert' => (string) count($history)],
            ['posten' => 'Letzter Lauf', 'wert' => $last === null
                ? '(keiner)'
                : sprintf(
                    '%s, %s, %s',
                    (string) ($last['direction'] ?? '?'),
                    (string) ($last['status'] ?? '?'),
                    wp_date('Y-m-d H:i', (int) ($last['timestamp'] ?? 0)) ?: '?'
                )],
            ['posten' => 'Geplante Beiträge', 'wert' => (string) count($posts)],
            ['posten' => 'Termine in Ordnung', 'wert' => (string) ($summary[ScheduleInspector::STATE_OK] ?? 0)],
            ['posten' => 'Termine fehlen', 'wert' => (string) ($summary[ScheduleInspector::STATE_MISSING] ?? 0)],
            ['posten' => 'Überfällig', 'wert' => (string) ($summary[ScheduleInspector::STATE_OVERDUE] ?? 0)],
            ['posten' => 'Termine weichen ab', 'wert' => (string) ($summary[ScheduleInspector::STATE_DRIFT] ?? 0)],
            ['posten' => 'Verwaiste Termine', 'wert' => (string) ($summary['verwaist'] ?? 0)],
        ];

        \WP_CLI\Utils\format_items($this->format($assoc), $rows, ['posten', 'wert']);

        if ($inspector->needsRepair($summary)) {
            \WP_CLI::warning('Es fehlen Termine. "wp rh sync schedule" zeigt welche, "wp rh sync schedule-repair" stellt sie her.');
        }

        // Ein hart abgeschossener Selbsttest hinterlässt seine Kopplung, weil dann auch kein
        // Aufräumen mehr läuft. Sie hat volle Rechte und zeigt auf diese Website selbst, also
        // soll sie nicht unbemerkt liegenbleiben.
        if ($this->peers->getByName(Selftest::PEER_NAME) !== null) {
            \WP_CLI::warning(sprintf(
                'Es steht noch eine Kopplung "%s" aus einem Selbsttest. Entweder ein Lauf wurde abgebrochen oder er lief mit --keep. Entfernen: wp rh sync selftest --insecure --yes (räumt sie mit weg) oder von Hand im Sync-Tab.',
                Selftest::PEER_NAME
            ));
        }
    }

    /**
     * Die gekoppelten Websites. Zugangsdaten werden nicht ausgegeben.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Ausgabeform.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rh sync peers
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function peers(array $args, array $assoc): void
    {
        $rows = [];

        foreach ($this->peers->all() as $peer) {
            $rows[] = [
                'id' => $peer->id,
                'name' => $peer->name,
                'url' => $peer->url,
                'profil' => $peer->profile->isFullSync()
                    ? 'voll'
                    : sprintf('%d von 8', $peer->profile->activeCount()),
                'holen' => $peer->permissions->allowPullFrom ? 'ja' : 'nein',
                'schieben' => $peer->permissions->allowPushTo ? 'ja' : 'nein',
                'annehmen' => $peer->permissions->allowInboundImport ? 'ja' : 'nein',
                'letzter_sync' => is_array($peer->lastSync) && isset($peer->lastSync['timestamp'])
                    ? (string) (wp_date('Y-m-d H:i', (int) $peer->lastSync['timestamp']) ?: '?')
                    : '(nie)',
            ];
        }

        if ($rows === []) {
            \WP_CLI::log('Keine gekoppelte Website eingetragen.');

            return;
        }

        \WP_CLI\Utils\format_items(
            $this->format($assoc),
            $rows,
            ['id', 'name', 'url', 'profil', 'holen', 'schieben', 'annehmen', 'letzter_sync']
        );
    }

    /**
     * Laufende und bekannte Läufe.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Auch abgeschlossene Läufe aus dem Verlauf zeigen.
     *
     * [--format=<format>]
     * : Ausgabeform.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rh sync jobs
     *     wp rh sync jobs --all
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function jobs(array $args, array $assoc): void
    {
        $rows = [];

        foreach (JobState::index() as $jobId => $peerId) {
            $job = JobState::load((string) $jobId);
            if ($job === null) {
                $rows[] = [
                    'id' => (string) $jobId,
                    'richtung' => '?',
                    'phase' => '(Zustand verloren)',
                    'schritt' => '',
                    'still_seit' => '',
                ];
                continue;
            }

            $rows[] = [
                'id' => $job->jobId,
                'richtung' => $job->direction,
                'phase' => $job->stage,
                'schritt' => (string) ($job->cursor['ij_phase'] ?? ''),
                'still_seit' => $job->lastUpdateAt > 0
                    ? (string) max(0, time() - $job->lastUpdateAt) . ' s'
                    : '?',
            ];
        }

        if (isset($assoc['all'])) {
            foreach ($this->log->all() as $entry) {
                $rows[] = [
                    'id' => '(Verlauf)',
                    'richtung' => (string) ($entry['direction'] ?? '?'),
                    'phase' => (string) ($entry['status'] ?? '?'),
                    'schritt' => (string) ($entry['peer_name'] ?? ''),
                    'still_seit' => (string) (wp_date('Y-m-d H:i', (int) ($entry['timestamp'] ?? 0)) ?: '?'),
                ];
            }
        }

        if ($rows === []) {
            \WP_CLI::log('Kein Lauf aktiv.');

            return;
        }

        \WP_CLI\Utils\format_items($this->format($assoc), $rows, ['id', 'richtung', 'phase', 'schritt', 'still_seit']);
    }

    /**
     * Ein Lauf im Detail, inklusive dem Bericht über die wiederhergestellten Termine.
     *
     * ## OPTIONS
     *
     * <id>
     * : Die Kennung des Laufs, wie sie "wp rh sync jobs" zeigt.
     *
     * ## EXAMPLES
     *
     *     wp rh sync job 9b2b63f987b2922ccdf4a66946571859
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function job(array $args, array $assoc): void
    {
        $id = $this->jobId($args[0] ?? '');
        $job = JobState::load($id);

        if ($job === null) {
            \WP_CLI::error(sprintf('Zu dieser Kennung gibt es keinen Lauf: %s', $id));
        }

        \WP_CLI::log(sprintf('Richtung:        %s', $job->direction));
        \WP_CLI::log(sprintf('Phase:           %s', $job->stage));
        \WP_CLI::log(sprintf('Schritt intern:  %s', (string) ($job->cursor['ij_phase'] ?? '-')));
        \WP_CLI::log(sprintf('Daten stehen:    %s', $job->importCommitted ? 'ja' : 'nein'));
        \WP_CLI::log(sprintf('Meldung:         %s', $job->message !== '' ? $job->message : '-'));

        if (is_array($job->error) && $job->error !== []) {
            \WP_CLI::log(sprintf('Fehler:          %s', (string) ($job->error['message'] ?? '?')));
        }

        $report = is_array($job->summary['schedule_rebuild'] ?? null)
            ? ScheduleReport::fromArray($job->summary['schedule_rebuild'])
            : null;

        \WP_CLI::log('');

        if ($report === null) {
            \WP_CLI::log('Termine: nicht geprüft.');

            return;
        }

        \WP_CLI::log('Termine: ' . $report->headline());
        foreach ($report->lines() as $line) {
            \WP_CLI::log('  - ' . $line);
        }
    }

    /**
     * Zustand der Termine geplanter Beiträge.
     *
     * Zeigt für jeden geplanten Beitrag, ob sein Termin steht, fehlt, abweicht oder ob der
     * Zeitpunkt schon vorbei ist. Genau der Befund, der nach einem Sync interessant ist: die
     * Beiträge kommen mit, ihre Termine nicht.
     *
     * ## OPTIONS
     *
     * [--only=<zustand>]
     * : Nur einen Zustand zeigen.
     * ---
     * options:
     *   - ok
     *   - fehlt
     *   - überfällig
     *   - abweichend
     *   - unlesbar
     * ---
     *
     * [--limit=<anzahl>]
     * : Höchstens so viele Beiträge ansehen.
     * ---
     * default: 500
     * ---
     *
     * [--format=<format>]
     * : Ausgabeform.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp rh sync schedule
     *     wp rh sync schedule --only=fehlt
     *     wp rh sync schedule --format=json
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function schedule(array $args, array $assoc): void
    {
        $inspector = new ScheduleInspector();
        $limit = max(1, (int) ($assoc['limit'] ?? 500));

        $posts = $inspector->posts($limit);
        $strays = $inspector->strays();
        $summary = $inspector->summary($posts, $strays);

        $only = isset($assoc['only']) ? (string) $assoc['only'] : '';
        $shown = $only === ''
            ? $posts
            : array_values(array_filter($posts, static fn (array $p): bool => $p['zustand'] === $only));

        if ($shown === []) {
            \WP_CLI::log($only === '' ? 'Kein geplanter Beitrag vorhanden.' : sprintf('Kein Beitrag im Zustand "%s".', $only));
        } else {
            \WP_CLI\Utils\format_items(
                $this->format($assoc),
                $shown,
                ['id', 'typ', 'titel', 'geplant_fuer', 'termin', 'zustand']
            );
        }

        if ($strays !== []) {
            \WP_CLI::log('');
            \WP_CLI::log('Termine ohne gültigen Beitrag:');
            \WP_CLI\Utils\format_items($this->format($assoc), $strays, ['hook', 'id', 'grund', 'termin']);
        }

        \WP_CLI::log('');
        \WP_CLI::log(sprintf(
            'In Ordnung %d, fehlt %d, überfällig %d, abweichend %d, unlesbar %d, verwaist %d.',
            $summary[ScheduleInspector::STATE_OK] ?? 0,
            $summary[ScheduleInspector::STATE_MISSING] ?? 0,
            $summary[ScheduleInspector::STATE_OVERDUE] ?? 0,
            $summary[ScheduleInspector::STATE_DRIFT] ?? 0,
            $summary[ScheduleInspector::STATE_UNREADABLE] ?? 0,
            $summary['verwaist'] ?? 0
        ));

        if (($summary[ScheduleInspector::STATE_OVERDUE] ?? 0) > 0) {
            \WP_CLI::log('Überfällige werden nicht automatisch veröffentlicht. Das bleibt eine Entscheidung von Hand.');
        }
    }

    /**
     * Stellt fehlende Termine wieder her.
     *
     * Läuft dieselbe Maschine, die nach jedem Import läuft. Für den Fall, dass eine Website
     * ihre Termine verloren hat und man nicht auf den nächsten Sync warten will.
     *
     * Überfällige Beiträge werden dabei NICHT veröffentlicht, sondern nur gemeldet.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Nur zeigen, was fehlt. Ändert nichts.
     *
     * [--yes]
     * : Ohne Rückfrage ausführen.
     *
     * ## EXAMPLES
     *
     *     wp rh sync schedule-repair --dry-run
     *     wp rh sync schedule-repair --yes
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     * @subcommand schedule-repair
     */
    public function schedule_repair(array $args, array $assoc): void
    {
        $inspector = new ScheduleInspector();
        $summary = $inspector->summary($inspector->posts(), $inspector->strays());

        if (!$inspector->needsRepair($summary)) {
            \WP_CLI::success('Alle Termine stehen. Nichts zu tun.');

            return;
        }

        \WP_CLI::log(sprintf(
            'Zu reparieren: %d fehlende, %d abweichende, %d verwaiste Termine.',
            $summary[ScheduleInspector::STATE_MISSING] ?? 0,
            $summary[ScheduleInspector::STATE_DRIFT] ?? 0,
            $summary['verwaist'] ?? 0
        ));

        if (isset($assoc['dry-run'])) {
            \WP_CLI::log('Probelauf, es wurde nichts geändert.');

            return;
        }

        Guard::confirm('Termine jetzt wiederherstellen?', $assoc);

        // Ob die Einstellungen zur Website gehören, weiss der Aufruf von Hand nicht. Wir gehen
        // vom gutmütigen Fall aus: die Zeitzone passt zu den Beitragsdaten.
        $report = (new ScheduleRebuilder())->runToCompletion(true);

        \WP_CLI::log($report->headline());
        foreach ($report->lines() as $line) {
            \WP_CLI::log('  - ' . $line);
        }

        \WP_CLI::success('Fertig.');
    }

    /**
     * Die letzten Zeilen aus dem Verlaufsprotokoll.
     *
     * Das Protokoll überlebt einen Absturz und beantwortet die Frage, an welcher Stelle ein
     * Lauf stehengeblieben ist. Genau dafür steht dort auch die Adresse der Notluke, und die
     * trägt ihren Zugangsschlüssel im Klartext: ohne ihn wäre sie nach einem Absturz nutzlos.
     * Die Ausgabe gehört deshalb nicht in ein Ticket oder einen Chat, solange ein Lauf noch
     * offen ist.
     *
     * ## OPTIONS
     *
     * [--lines=<anzahl>]
     * : Wie viele Zeilen.
     * ---
     * default: 40
     * ---
     *
     * [--job=<id>]
     * : Nur Zeilen zu einem Lauf.
     *
     * ## EXAMPLES
     *
     *     wp rh sync trace
     *     wp rh sync trace --lines=200
     *     wp rh sync trace --job=9b2b63f987b2922ccdf4a66946571859
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function trace(array $args, array $assoc): void
    {
        $path = (string) JobTrace::path();

        if ($path === '' || !is_readable($path)) {
            \WP_CLI::log('Noch kein Verlaufsprotokoll vorhanden.');

            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            \WP_CLI::log('Das Verlaufsprotokoll ist leer.');

            return;
        }

        $job = isset($assoc['job']) ? $this->jobId((string) $assoc['job']) : '';
        if ($job !== '') {
            $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, $job)));
        }

        $count = max(1, (int) ($assoc['lines'] ?? 40));
        foreach (array_slice($lines, -$count) as $line) {
            \WP_CLI::log($line);
        }
    }

    /**
     * Umgebung, Grenzen und was davon für einen grossen Sync knapp wird.
     *
     * ## EXAMPLES
     *
     *     wp rh sync doctor
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function doctor(array $args, array $assoc): void
    {
        $rows = [
            ['posten' => 'Umgebung', 'wert' => Guard::environment()],
            ['posten' => 'PHP', 'wert' => PHP_VERSION],
            ['posten' => 'WordPress', 'wert' => (string) get_bloginfo('version')],
            ['posten' => 'Speichergrenze', 'wert' => (string) ini_get('memory_limit')],
            ['posten' => 'Zeitgrenze', 'wert' => (string) ini_get('max_execution_time')],
            ['posten' => 'Zeitzone', 'wert' => (string) wp_timezone_string()],
            ['posten' => 'db-engine geladen', 'wert' => function_exists('rh_db_engine') ? 'ja' : 'NEIN'],
        ];

        // Funktionen, die Hoster gern sperren. Fehlt eine, merkt man das sonst erst im Lauf.
        foreach (['disk_free_space', 'set_time_limit', 'proc_open'] as $fn) {
            $rows[] = [
                'posten' => 'Funktion ' . $fn,
                'wert' => function_exists($fn) ? 'verfügbar' : 'gesperrt',
            ];
        }

        try {
            foreach (Preflight::localLimits() as $key => $value) {
                if (is_scalar($value)) {
                    $rows[] = ['posten' => 'Grenze ' . (string) $key, 'wert' => (string) $value];
                }
            }
        } catch (\Throwable $e) {
            $rows[] = ['posten' => 'Grenzen', 'wert' => 'nicht ermittelbar: ' . $e->getMessage()];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['posten', 'wert']);
    }

    /**
     * Testdaten für die Termin-Prüfung.
     *
     * Legt geplante Beiträge an, nimmt ihnen die Termine (der Zustand nach einem Import) oder
     * räumt alles wieder weg. Angefasst wird ausschliesslich, was dieser Befehl selbst angelegt
     * hat.
     *
     * Läuft nicht auf einer Produktionsseite.
     *
     * ## OPTIONS
     *
     * <aktion>
     * : Was getan werden soll.
     * ---
     * options:
     *   - create
     *   - damage
     *   - reset
     * ---
     *
     * [--upcoming=<anzahl>]
     * : Wie viele Beiträge mit Termin in der Zukunft.
     * ---
     * default: 12
     * ---
     *
     * [--overdue=<anzahl>]
     * : Wie viele mit einem Termin, der schon vorbei ist.
     * ---
     * default: 2
     * ---
     *
     * [--valid-gmt]
     * : post_date_gmt korrekt setzen. Ohne diese Angabe steht dort ein falsches Datum, wie auf einer echten importierten Website.
     *
     * [--yes]
     * : Ohne Rückfrage ausführen.
     *
     * ## EXAMPLES
     *
     *     wp rh sync fixture create
     *     wp rh sync fixture create --upcoming=50 --overdue=5
     *     wp rh sync fixture damage
     *     wp rh sync fixture reset --yes
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function fixture(array $args, array $assoc): void
    {
        Guard::requireNonProduction('"wp rh sync fixture"');

        $action = (string) ($args[0] ?? '');
        $fixtures = new Fixtures();

        switch ($action) {
            case 'create':
                Guard::confirm('Testbeiträge anlegen?', $assoc);
                $result = $fixtures->create(
                    max(0, (int) ($assoc['upcoming'] ?? 12)),
                    max(0, (int) ($assoc['overdue'] ?? 2)),
                    isset($assoc['valid-gmt'])
                );
                \WP_CLI::success(sprintf(
                    '%d Beiträge angelegt, davon %d überfällig.',
                    $result['angelegt'],
                    $result['ueberfaellig']
                ));
                break;

            case 'damage':
                Guard::confirm('Allen geplanten Beiträgen die Termine nehmen?', $assoc);
                $result = $fixtures->damage();
                \WP_CLI::success(sprintf(
                    '%d Termine entfernt, dazu ein verwaister und einer mit der ID als Text.',
                    $result['entfernt']
                ));
                break;

            case 'reset':
                Guard::confirm('Alle Testbeiträge und ihre Termine löschen?', $assoc);
                $result = $fixtures->reset();
                \WP_CLI::success(sprintf(
                    '%d Beiträge und %d verwaiste Termine entfernt.',
                    $result['beitraege'],
                    $result['termine']
                ));
                break;

            default:
                \WP_CLI::error(sprintf('Unbekannte Aktion: %s. Möglich sind create, damage, reset.', $action));
        }
    }

    /**
     * Kompletter Durchlauf gegen die eigene Website.
     *
     * Koppelt die Website mit sich selbst, legt geplante Beiträge an, nimmt ihnen die Termine,
     * fährt einen echten Sync und prüft danach, ob die Termine wieder stehen. Räumt am Ende
     * hinter sich auf.
     *
     * Gesynct werden nur die Inhalte, nicht die Einstellungen und nicht die Mediathek. Der Lauf
     * bleibt damit kurz und fasst nichts an, was er nicht braucht.
     *
     * Läuft nicht auf einer Produktionsseite.
     *
     * ## OPTIONS
     *
     * [--upcoming=<anzahl>]
     * : Wie viele Beiträge mit Termin in der Zukunft.
     * ---
     * default: 12
     * ---
     *
     * [--overdue=<anzahl>]
     * : Wie viele überfällige.
     * ---
     * default: 2
     * ---
     *
     * [--insecure]
     * : Zertifikatsprüfung für die eigenen Aufrufe abschalten, für lokale Umgebungen mit selbstsigniertem Zertifikat (DDEV).
     *
     * [--keep]
     * : Testdaten und Kopplung nach dem Lauf stehen lassen, zum Nachsehen.
     *
     * [--yes]
     * : Ohne Rückfrage ausführen.
     *
     * ## EXAMPLES
     *
     *     wp rh sync selftest --insecure --yes
     *     wp rh sync selftest --upcoming=40 --overdue=3 --insecure --yes
     *     wp rh sync selftest --insecure --keep --yes
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    public function selftest(array $args, array $assoc): void
    {
        Guard::requireNonProduction('"wp rh sync selftest"');
        Guard::confirm('Der Selbsttest legt Testdaten an und fährt einen echten Sync gegen diese Website. Weiter?', $assoc);

        (new Selftest($this->peers, $this->log, $this->ticker))->run([
            'upcoming' => max(1, (int) ($assoc['upcoming'] ?? 12)),
            'overdue' => max(0, (int) ($assoc['overdue'] ?? 2)),
            'insecure' => isset($assoc['insecure']),
            'keep' => isset($assoc['keep']),
        ]);
    }

    /**
     * @param array<string, mixed> $assoc
     */
    private function format(array $assoc): string
    {
        $format = (string) ($assoc['format'] ?? 'table');

        return in_array($format, ['table', 'json', 'yaml', 'csv', 'count', 'ids'], true) ? $format : 'table';
    }

    /**
     * Job-Kennungen sind Hex-Zeichenketten. Alles andere fliegt raus, bevor es in eine
     * Dateisuche oder einen Options-Namen wandert.
     */
    private function jobId(string $raw): string
    {
        $clean = preg_replace('/[^a-f0-9]/', '', strtolower(trim($raw)));

        if (!is_string($clean) || $clean === '') {
            \WP_CLI::error('Das ist keine gültige Kennung eines Laufs.');
        }

        return $clean;
    }
}
