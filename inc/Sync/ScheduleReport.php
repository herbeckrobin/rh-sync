<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Was der Wiederaufbau der Termine nach einem Import getan und gefunden hat.
 *
 * Reines Datenobjekt, serialisierbar, weil es sowohl in den Job-Cursor als auch in den
 * Sync-Verlauf wandert. Beim Push entsteht es auf der Zielseite und reist als Teil der
 * Status-Antwort zurück zum Auslöser.
 *
 * Wichtig für die Anzeige: ein leerer Bericht und gar kein Bericht sind zwei verschiedene
 * Aussagen. `isEmpty()` heißt "geprüft, nichts zu tun". Steht statt eines Berichts `null`,
 * heißt das "konnte nicht geprüft werden" und muss auch so dastehen, sonst ist beides nicht
 * mehr auseinanderzuhalten.
 */
final class ScheduleReport
{
    /** Mehr Titel als diese passen nicht sinnvoll in eine Verlaufszeile. */
    public const MAX_LISTED = 25;

    /**
     * @param array<int, array{id: int, title: string, type: string, date: string}> $overdue
     * @param array<string, int> $unregisteredTypes
     */
    public function __construct(
        public readonly int $scheduled = 0,
        public readonly array $overdue = [],
        public readonly int $overdueTotal = 0,
        public readonly int $corrected = 0,
        public readonly int $orphansRemoved = 0,
        public readonly int $staleRemoved = 0,
        public readonly int $pings = 0,
        public readonly int $importerCleanups = 0,
        public readonly int $importerOverdue = 0,
        public readonly int $failed = 0,
        public readonly array $unregisteredTypes = [],
        public readonly string $timezone = '',
        public readonly int $scanned = 0,
        public readonly bool $truncated = false,
        public readonly bool $ownTimezone = false,
    ) {
    }

    /**
     * Hat der Lauf etwas gefunden, das der Rede wert ist?
     *
     * Bewusst nicht an `scanned` gebunden: eine Website ohne geplante Beiträge hat nichts
     * zu berichten, auch wenn geschaut wurde.
     */
    public function isEmpty(): bool
    {
        return $this->scheduled === 0
            && $this->overdueTotal === 0
            && $this->corrected === 0
            && $this->orphansRemoved === 0
            && $this->staleRemoved === 0
            && $this->pings === 0
            && $this->importerCleanups === 0
            && $this->importerOverdue === 0
            && $this->failed === 0
            && !$this->truncated;
    }

    /**
     * Eine Zeile für die Übersicht, in Endkundensprache.
     */
    public function headline(): string
    {
        if ($this->isEmpty()) {
            return __('Keine Termine zu korrigieren.', 'rh-sync');
        }

        $parts = [];

        if ($this->scheduled > 0) {
            $parts[] = sprintf(
                /* translators: %d = Anzahl wiederhergestellter Termine */
                _n('%d Termin wiederhergestellt', '%d Termine wiederhergestellt', $this->scheduled, 'rh-sync'),
                $this->scheduled
            );
        }

        if ($this->corrected > 0) {
            $parts[] = sprintf(
                /* translators: %d = Anzahl korrigierter Termine */
                _n('%d Termin korrigiert', '%d Termine korrigiert', $this->corrected, 'rh-sync'),
                $this->corrected
            );
        }

        if ($this->overdueTotal > 0) {
            $parts[] = sprintf(
                /* translators: %d = Anzahl überfälliger Beiträge */
                _n('%d überfällig', '%d überfällig', $this->overdueTotal, 'rh-sync'),
                $this->overdueTotal
            );
        }

        $removed = $this->orphansRemoved + $this->staleRemoved;
        if ($removed > 0) {
            $parts[] = sprintf(
                /* translators: %d = Anzahl entfernter Termine ohne Beitrag */
                _n('%d verwaister Termin entfernt', '%d verwaiste Termine entfernt', $removed, 'rh-sync'),
                $removed
            );
        }

        if ($this->failed > 0) {
            $parts[] = sprintf(
                /* translators: %d = Anzahl nicht setzbarer Termine */
                _n('%d Termin ließ sich nicht setzen', '%d Termine ließen sich nicht setzen', $this->failed, 'rh-sync'),
                $this->failed
            );
        }

        if ($parts === []) {
            return __('Termine geprüft.', 'rh-sync');
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * Das Kurz-Etikett für die Verlaufszeile. Höchstens 14 Zeichen, sonst bricht die Tabelle.
     *
     * @return array{text: string, tone: string}|null
     */
    public function pill(): ?array
    {
        if ($this->overdueTotal > 0) {
            return [
                'text' => sprintf(
                    /* translators: %d = Anzahl überfälliger Beiträge */
                    __('%d überfällig', 'rh-sync'),
                    $this->overdueTotal
                ),
                'tone' => 'err',
            ];
        }

        if ($this->failed > 0 || $this->truncated) {
            return ['text' => __('unvollständig', 'rh-sync'), 'tone' => 'err'];
        }

        $touched = $this->scheduled + $this->corrected;
        if ($touched > 0) {
            return [
                'text' => sprintf(
                    /* translators: %d = Anzahl gesetzter Termine */
                    _n('%d Termin', '%d Termine', $touched, 'rh-sync'),
                    $touched
                ),
                'tone' => 'ok',
            ];
        }

        return null;
    }

    /**
     * Die Einzelheiten, eine Zeile pro Fund.
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->overdue as $post) {
            $lines[] = sprintf(
                /* translators: 1: Beitrags-ID, 2: Titel, 3: geplanter Termin */
                __('#%1$d "%2$s", war fällig am %3$s', 'rh-sync'),
                $post['id'],
                $post['title'],
                $post['date']
            );
        }

        $rest = $this->overdueTotal - count($this->overdue);
        if ($rest > 0) {
            $lines[] = sprintf(
                /* translators: %d = Anzahl weiterer überfälliger Beiträge */
                _n('... und %d weiterer', '... und %d weitere', $rest, 'rh-sync'),
                $rest
            );
        }

        foreach ($this->unregisteredTypes as $type => $count) {
            $lines[] = sprintf(
                /* translators: 1: Anzahl Beiträge, 2: Name der Inhaltsart */
                _n(
                    '%1$d Beitrag der Art "%2$s": auf dieser Website ist dafür kein Plugin aktiv.',
                    '%1$d Beiträge der Art "%2$s": auf dieser Website ist dafür kein Plugin aktiv.',
                    $count,
                    'rh-sync'
                ),
                $count,
                $type
            );
        }

        if ($this->importerOverdue > 0) {
            $lines[] = sprintf(
                /* translators: %d = Anzahl liegengebliebener Import-Dateien */
                _n(
                    '%d liegengebliebene Import-Datei, das Aufräumen war schon fällig.',
                    '%d liegengebliebene Import-Dateien, das Aufräumen war schon fällig.',
                    $this->importerOverdue,
                    'rh-sync'
                ),
                $this->importerOverdue
            );
        }

        if ($this->ownTimezone) {
            $lines[] = sprintf(
                /* translators: %s = Zeitzone der Zielseite */
                __('Gerechnet mit der Zeitzone dieser Website (%s). Die Einstellungen wurden nicht mitgesynct, weichen die Zeitzonen voneinander ab, verschieben sich alle Termine entsprechend.', 'rh-sync'),
                $this->timezone
            );
        }

        if ($this->truncated) {
            $lines[] = __('Es waren mehr Termine, als in einem Lauf zu schaffen sind. Der nächste Sync macht weiter.', 'rh-sync');
        }

        return $lines;
    }

    /**
     * Die Anzeige-Struktur für die Abschlussmeldung.
     *
     * Bewusst allgemein gehalten (Titel, Ton, Kennzahlen, Zeilen): der Core rendert das,
     * ohne von Terminen oder Cron zu wissen, und der nächste Nachlauf kann dieselbe
     * Schiene benutzen.
     *
     * @return array{title: string, tone: string, stats: array<int, array{label: string, value: string}>, items: array<int, string>}|null
     */
    public function note(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $stats = [];

        if ($this->scheduled > 0) {
            $stats[] = ['label' => __('Wiederhergestellt', 'rh-sync'), 'value' => (string) $this->scheduled];
        }
        if ($this->corrected > 0) {
            $stats[] = ['label' => __('Korrigiert', 'rh-sync'), 'value' => (string) $this->corrected];
        }
        if ($this->overdueTotal > 0) {
            $stats[] = ['label' => __('Überfällig', 'rh-sync'), 'value' => (string) $this->overdueTotal];
        }
        $removed = $this->orphansRemoved + $this->staleRemoved;
        if ($removed > 0) {
            $stats[] = ['label' => __('Verwaist entfernt', 'rh-sync'), 'value' => (string) $removed];
        }
        if ($this->failed > 0) {
            $stats[] = ['label' => __('Nicht setzbar', 'rh-sync'), 'value' => (string) $this->failed];
        }

        return [
            'title' => __('Geplante Beiträge', 'rh-sync'),
            'tone' => ($this->overdueTotal > 0 || $this->failed > 0 || $this->truncated) ? 'warn' : 'info',
            'stats' => $stats,
            'items' => $this->lines(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scheduled' => $this->scheduled,
            'overdue' => $this->overdue,
            'overdue_total' => $this->overdueTotal,
            'corrected' => $this->corrected,
            'orphans_removed' => $this->orphansRemoved,
            'stale_removed' => $this->staleRemoved,
            'pings' => $this->pings,
            'importer_cleanups' => $this->importerCleanups,
            'importer_overdue' => $this->importerOverdue,
            'failed' => $this->failed,
            'unregistered_types' => $this->unregisteredTypes,
            'timezone' => $this->timezone,
            'scanned' => $this->scanned,
            'truncated' => $this->truncated,
            'own_timezone' => $this->ownTimezone,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array{id: int, title: string, type: string, date: string}> $overdue */
        $overdue = [];
        foreach ((array) ($data['overdue'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $overdue[] = [
                'id' => (int) ($entry['id'] ?? 0),
                'title' => (string) ($entry['title'] ?? ''),
                'type' => (string) ($entry['type'] ?? ''),
                'date' => (string) ($entry['date'] ?? ''),
            ];
        }

        /** @var array<string, int> $types */
        $types = [];
        foreach ((array) ($data['unregistered_types'] ?? []) as $type => $count) {
            $types[(string) $type] = (int) $count;
        }

        return new self(
            scheduled: (int) ($data['scheduled'] ?? 0),
            overdue: $overdue,
            overdueTotal: (int) ($data['overdue_total'] ?? count($overdue)),
            corrected: (int) ($data['corrected'] ?? 0),
            orphansRemoved: (int) ($data['orphans_removed'] ?? 0),
            staleRemoved: (int) ($data['stale_removed'] ?? 0),
            pings: (int) ($data['pings'] ?? 0),
            importerCleanups: (int) ($data['importer_cleanups'] ?? 0),
            importerOverdue: (int) ($data['importer_overdue'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
            unregisteredTypes: $types,
            timezone: (string) ($data['timezone'] ?? ''),
            scanned: (int) ($data['scanned'] ?? 0),
            truncated: (bool) ($data['truncated'] ?? false),
            ownTimezone: (bool) ($data['own_timezone'] ?? false),
        );
    }
}
