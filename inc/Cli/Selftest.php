<?php

declare(strict_types=1);

namespace RhSync\Cli;

use RhSync\Sync\JobState;
use RhSync\Sync\Peer;
use RhSync\Sync\PeerRegistry;
use RhSync\Sync\ScheduleRebuilder;
use RhSync\Sync\ScheduleReport;
use RhSync\Sync\SyncLog;
use RhSync\Sync\SyncPermissions;
use RhSync\Sync\SyncProfile;
use RhSync\Sync\SyncStatus;
use RhSync\Sync\TickRunner;

/**
 * Der komplette Durchlauf gegen die eigene Website.
 *
 * Koppelt die Website mit sich selbst, stellt den Zustand nach einem Import nach (Beiträge da,
 * Termine weg) und fährt einen echten Sync. Danach wird geprüft, ob wieder stimmt, was stimmen
 * soll. Das ersetzt die zwölf Wegwerf-Skripte, mit denen das vorher von Hand ging.
 *
 * Drei Entscheidungen, die den Lauf schnell und harmlos halten:
 *
 *   - Gesynct werden nur die Inhalte. Ohne Einstellungen und ohne Mediathek bleibt der Lauf
 *     kurz, und die eigene Kopplung wird nicht überschrieben.
 *   - Getickt wird im eigenen Prozess statt über den Loopback. Damit ist die Reihenfolge
 *     bestimmt, es gibt kein Warten auf einen zweiten Prozess, und der Filter für das
 *     Zertifikat greift auch für die Aufrufe an die eigene Website.
 *   - Aufgeräumt wird in jedem Fall, auch wenn der Lauf scheitert.
 */
final class Selftest
{
    /**
     * Name, unter dem die Kopplung angelegt wird. Daran wird sie auch wieder erkannt.
     *
     * Öffentlich, weil `wp rh sync status` darauf hinweist, falls so eine Kopplung
     * stehengeblieben ist. Das kann passieren, wenn der Lauf hart abgeschossen wird: dann
     * greift auch kein `finally` mehr.
     */
    public const PEER_NAME = 'Selbsttest (automatisch)';

    /** Reissleine, damit ein hängender Lauf nicht ewig tickt. */
    private const MAX_TICKS = 400;

    private int $failures = 0;

    public function __construct(
        private readonly PeerRegistry $peers,
        private readonly SyncLog $log,
        private readonly TickRunner $ticker,
    ) {
    }

    /**
     * @param array{upcoming: int, overdue: int, insecure: bool, keep: bool} $options
     */
    public function run(array $options): void
    {
        $fixtures = new Fixtures();
        $peer = null;

        // Der Loopback würde jeden Tick ein zweites Mal anstossen. Hier tickt der Befehl selbst.
        add_filter('rh-blueprint/sync/suppress_loopback', '__return_true');

        if ($options['insecure']) {
            add_filter('rh-blueprint/sync/sslverify', '__return_false');
            add_filter('rh-blueprint/sync/loopback_sslverify', '__return_false');
            \WP_CLI::log('Zertifikatsprüfung ist für diesen Lauf abgeschaltet.');
        }

        try {
            $peer = $this->preparePeer();
            $this->prepareData($fixtures, $options);
            $before = $this->measure();

            $this->step('Ausgangslage', sprintf(
                '%d geplante Beiträge, %d Termine (davon %d unbrauchbar)',
                $before['posts'],
                $before['events'],
                $before['strays']
            ));

            $this->expect(
                'Der Zustand nach einem Import ist hergestellt',
                $before['posts'] >= $options['upcoming'] + $options['overdue'] && $before['events'] === $before['strays'],
                sprintf('%d Beiträge, %d Termine, %d davon unbrauchbar', $before['posts'], $before['events'], $before['strays'])
            );

            $job = $this->runSync($peer);
            $after = $this->measure();
            $report = $this->reportOf($job);

            $this->verify($job, $report, $after, $options);
        } catch (\Throwable $e) {
            $this->failures++;
            \WP_CLI::warning('Der Lauf ist gescheitert: ' . $e->getMessage());
        } finally {
            if (!$options['keep']) {
                $this->cleanUp($fixtures, $peer);
            } else {
                \WP_CLI::log('Testdaten und Kopplung bleiben stehen (--keep).');
            }
        }

        \WP_CLI::log('');

        if ($this->failures > 0) {
            \WP_CLI::error(sprintf('%d Prüfung(en) fehlgeschlagen.', $this->failures));
        }

        \WP_CLI::success('Alle Prüfungen bestanden.');
    }

    // ------------------------------------------------------------ Vorbereiten

    private function preparePeer(): Peer
    {
        $existing = $this->peers->getByName(self::PEER_NAME);
        if ($existing !== null) {
            $this->peers->remove($existing->id);
        }

        $base = Peer::create(self::PEER_NAME, home_url());

        // Nur die Inhalte, sonst würde der Lauf die eigene Kopplung und die Mediathek anfassen.
        $profile = new SyncProfile(
            content: true,
            taxonomies: false,
            comments: false,
            users: false,
            options: false,
            links: false,
            customTables: false,
            uploads: false,
        );

        $peer = new Peer(
            id: $base->id,
            name: $base->name,
            url: $base->url,
            token: $base->token,
            lastSync: null,
            createdAt: time(),
            profile: $profile,
            // Eine Kopplung mit sich selbst muss sich selbst alles erlauben, sonst blockt der
            // eigene Endpunkt den eigenen Aufruf.
            permissions: new SyncPermissions(true, true, true, true),
        );

        $this->peers->add($peer);
        $this->step('Kopplung', 'mit sich selbst angelegt, nur Inhalte');

        return $peer;
    }

    /**
     * @param array{upcoming: int, overdue: int, insecure: bool, keep: bool} $options
     */
    private function prepareData(Fixtures $fixtures, array $options): void
    {
        $fixtures->reset();

        $created = $fixtures->create($options['upcoming'], $options['overdue']);
        $this->step('Testdaten', sprintf(
            '%d Beiträge, davon %d überfällig',
            $created['angelegt'],
            $created['ueberfaellig']
        ));

        $damage = $fixtures->damage();
        $this->step('Schadensbild', sprintf('%d Termine entfernt, zwei kaputte gesetzt', $damage['entfernt']));
    }

    // ------------------------------------------------------------ Durchführen

    private function runSync(Peer $peer): JobState
    {
        $job = JobState::create($peer->id, SyncStatus::DIRECTION_PULL, $peer->profile);
        $jobId = $job->jobId;

        \WP_CLI::log('');
        \WP_CLI::log('Lauf ' . $jobId);

        $ticks = 0;
        $lastStage = '';

        while ($ticks < self::MAX_TICKS) {
            $ticks++;
            $this->ticker->runTick($jobId, $job->spawnToken);

            $reloaded = JobState::load($jobId);
            if ($reloaded === null) {
                throw new \RuntimeException('Der Zustand des Laufs ist verschwunden.');
            }

            $job = $reloaded;

            $stage = $job->stage . '/' . (string) ($job->cursor['ij_phase'] ?? '-');
            if ($stage !== $lastStage) {
                \WP_CLI::log('  ' . $stage);
                $lastStage = $stage;
            }

            if ($job->isFinished()) {
                break;
            }
        }

        $this->step('Ticks', (string) $ticks);

        if (!$job->isFinished()) {
            throw new \RuntimeException(sprintf('Der Lauf war nach %d Durchgängen noch nicht fertig.', $ticks));
        }

        return $job;
    }

    // ------------------------------------------------------------ Prüfen

    /**
     * @param array{posts: int, events: int, strays: int, missing: int, drift: int, textIds: int} $after
     * @param array{upcoming: int, overdue: int, insecure: bool, keep: bool} $options
     */
    private function verify(JobState $job, ?ScheduleReport $report, array $after, array $options): void
    {
        \WP_CLI::log('');

        $this->expect(
            'Der Lauf ist erfolgreich beendet',
            $job->stage === SyncStatus::PHASE_DONE,
            'Phase ' . $job->stage . (is_array($job->error) ? ': ' . (string) ($job->error['message'] ?? '') : '')
        );

        $this->expect('Es gibt einen Bericht über die Termine', $report !== null);

        if ($report === null) {
            return;
        }

        $this->expect(
            sprintf('%d Termine wiederhergestellt', $options['upcoming']),
            $report->scheduled === $options['upcoming'],
            'gemeldet: ' . $report->scheduled
        );

        $this->expect(
            sprintf('%d überfällige gemeldet statt geplant', $options['overdue']),
            $report->overdueTotal === $options['overdue'],
            'gemeldet: ' . $report->overdueTotal
        );

        $this->expect(
            'Die überfälligen haben keinen Termin bekommen',
            $after['events'] === $options['upcoming'],
            sprintf('%d Termine stehen, erwartet %d', $after['events'], $options['upcoming'])
        );

        $this->expect('Kein verwaister Termin mehr da', $after['strays'] === 0, (string) $after['strays']);
        $this->expect('Keine Beitrags-ID steht als Text', $after['textIds'] === 0, (string) $after['textIds']);
        $this->expect('Kein Termin fehlt mehr', $after['missing'] === 0, (string) $after['missing']);
        $this->expect('Kein Termin weicht ab', $after['drift'] === 0, (string) $after['drift']);
        $this->expect('Nichts ist fehlgeschlagen', $report->failed === 0, (string) $report->failed);
        $this->expect('Der Lauf war vollständig', !$report->truncated);

        // Ohne mitgesyncte Einstellungen gehört die verwendete Zeitzone in den Bericht.
        $this->expect(
            'Der Bericht nennt die verwendete Zeitzone',
            $report->ownTimezone && $report->timezone !== '',
            $report->timezone
        );

        $entry = $this->log->all()[0] ?? null;
        $this->expect(
            'Der Lauf steht im Verlauf',
            is_array($entry) && ($entry['status'] ?? '') === 'success',
            is_array($entry) ? (string) ($entry['status'] ?? '?') : '(kein Eintrag)'
        );
        $this->expect(
            'Und der Verlauf trägt den Termin-Bericht',
            is_array($entry) && is_array($entry['schedule'] ?? null)
        );

        // Ein zweiter Durchgang darf nichts mehr tun. Das ist der Beweis, dass der Wiederaufbau
        // sich nicht bei jedem Sync selbst in die Quere kommt.
        $again = (new ScheduleRebuilder())->runToCompletion(true);
        $this->expect(
            'Ein zweiter Durchgang setzt keinen Termin erneut',
            $again->scheduled === 0 && $again->corrected === 0,
            sprintf('gesetzt %d, korrigiert %d', $again->scheduled, $again->corrected)
        );

        \WP_CLI::log('');
        \WP_CLI::log('Bericht: ' . $report->headline());
        foreach ($report->lines() as $line) {
            \WP_CLI::log('  - ' . $line);
        }
    }

    /**
     * Zählt den Ist-Zustand, ohne etwas zu ändern.
     *
     * @return array{posts: int, events: int, strays: int, missing: int, drift: int, textIds: int}
     */
    private function measure(): array
    {
        $inspector = new ScheduleInspector();
        $posts = $inspector->posts();
        $strays = $inspector->strays();
        $summary = $inspector->summary($posts, $strays);

        $events = 0;
        $textIds = 0;

        foreach ((array) _get_cron_array() as $hooks) {
            foreach ((array) ($hooks[ScheduleRebuilder::HOOK_PUBLISH] ?? []) as $event) {
                $events++;
                $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                if (isset($args[0]) && !is_int($args[0])) {
                    $textIds++;
                }
            }
        }

        return [
            'posts' => count($posts),
            'events' => $events,
            'strays' => count($strays),
            'missing' => (int) ($summary[ScheduleInspector::STATE_MISSING] ?? 0),
            'drift' => (int) ($summary[ScheduleInspector::STATE_DRIFT] ?? 0),
            'textIds' => $textIds,
        ];
    }

    private function reportOf(JobState $job): ?ScheduleReport
    {
        return is_array($job->summary['schedule_rebuild'] ?? null)
            ? ScheduleReport::fromArray($job->summary['schedule_rebuild'])
            : null;
    }

    // ------------------------------------------------------------ Aufräumen

    private function cleanUp(Fixtures $fixtures, ?Peer $peer): void
    {
        $removed = $fixtures->reset();

        if ($peer !== null) {
            $this->peers->remove($peer->id);
        }

        // Der Verlaufseintrag des Testlaufs soll den echten Verlauf nicht zumüllen.
        $this->log->forget(static function (array $entry): bool {
            return (string) ($entry['peer_name'] ?? '') === self::PEER_NAME;
        });

        \WP_CLI::log(sprintf(
            'Aufgeräumt: %d Beiträge, %d Termine, Kopplung entfernt.',
            $removed['beitraege'],
            $removed['termine']
        ));
    }

    // ------------------------------------------------------------ Ausgabe

    private function step(string $label, string $detail): void
    {
        \WP_CLI::log(sprintf('  %-16s %s', $label . ':', $detail));
    }

    private function expect(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            \WP_CLI::log('  [ok]   ' . $label);

            return;
        }

        $this->failures++;
        \WP_CLI::log('  [FEHL] ' . $label . ($detail !== '' ? ' (' . $detail . ')' : ''));
    }
}
