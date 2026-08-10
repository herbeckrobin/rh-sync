<?php

/**
 * Standalone-Test zum Befund vom 2026-08-10.
 *   php tests/option-guard-test.php
 *
 * Auf einer Kundeninstallation hat der Schutz der site-eigenen Options dreimal Erfolg
 * gemeldet und trotzdem die Peer-Liste, die aktiven Plugins und die Rollen der Quelle
 * stehen lassen. Der Auslöser liess sich nicht nachstellen, die Lücke schon: weder der
 * Rückgabewert der Abfragen noch das Ergebnis wurden geprüft.
 *
 * Geprüft wird hier:
 *   A) Der Schutz merkt es, wenn ein Schreibvorgang scheitert.
 *   B) Der Schutz merkt es, wenn geschrieben wurde und trotzdem der falsche Wert dasteht.
 *   C) Was die Sync-Engine ausmacht, wird beim Export ausgelassen, alles andere nicht.
 *   D) Eine Kopplung mit der eigenen Website wird abgelehnt.
 */

declare(strict_types=1);

namespace {
    // --- WordPress-Ersatz ------------------------------------------------

    define('ARRAY_A', 'ARRAY_A');

    function __(string $t, string $d = ''): string
    {
        return $t;
    }
    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }
    function home_url(string $path = ''): string
    {
        return 'https://www.kunde.de' . $path;
    }
    function site_url(string $path = ''): string
    {
        return 'https://www.kunde.de' . $path;
    }
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }

    /**
     * Datenbank-Attrappe, die eine Options-Tabelle wirklich führt.
     *
     * Sie kann drei Rollen spielen: alles klappt, das Einfügen scheitert, oder die
     * Abfragen melden Erfolg und die Tabelle bleibt trotzdem, wie sie war. Die dritte
     * ist der Fall aus der Praxis.
     */
    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $last_error = '';

        /** @var array<string, array{option_value: string, autoload: string}> */
        public array $rows = [];

        public function __construct(
            private readonly string $modus = 'normal'
        ) {
        }

        public function query(string $sql)
        {
            if (stripos($sql, 'DELETE FROM') === 0) {
                if ($this->modus === 'stumm') {
                    return 1; // meldet Erfolg, tut nichts
                }
                if ($this->modus === 'delete_fehler') {
                    $this->last_error = 'Table does not exist';
                    return false;
                }

                foreach (array_keys($this->rows) as $name) {
                    if ($this->betroffen($name)) {
                        unset($this->rows[$name]);
                    }
                }
                return 1;
            }

            return 0;
        }

        public function insert(string $table, array $data, array $format = [])
        {
            if ($this->modus === 'insert_fehler') {
                $this->last_error = 'Unknown column';
                return false;
            }
            if ($this->modus === 'stumm') {
                return 1; // meldet Erfolg, schreibt nichts
            }

            $this->rows[(string) $data['option_name']] = [
                'option_value' => (string) $data['option_value'],
                'autoload' => (string) $data['autoload'],
            ];
            return 1;
        }

        public function get_results(string $sql, $output = null): array
        {
            $out = [];
            foreach ($this->rows as $name => $row) {
                if ($this->betroffen($name)) {
                    $out[] = ['option_name' => $name, 'option_value' => $row['option_value']];
                }
            }
            return $out;
        }

        /** Grobe Nachbildung der WHERE-Klausel: reicht für die Namen in diesem Test. */
        private function betroffen(string $name): bool
        {
            $exakt = ['rhbp_peers', 'siteurl', 'home', 'active_plugins', 'wp_user_roles', 'upload_path'];
            if (in_array($name, $exakt, true)) {
                return true;
            }
            return str_starts_with($name, 'rhbp_sync_');
        }
    }

    require_once dirname(__DIR__) . '/inc/Sync/PeerRegistry.php';
    require_once dirname(__DIR__) . '/inc/Sync/LocalOptionGuard.php';
    require_once dirname(__DIR__) . '/inc/Sync/PeerUrl.php';

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (!$ok) {
            $failures++;
            if ($detail !== '') {
                echo '        ' . $detail . "\n";
            }
        }
    }

    /** @return array<int, array{option_name: string, option_value: string, autoload: string}> */
    function eigenerStand(): array
    {
        return [
            ['option_name' => 'rhbp_peers', 'option_value' => 'a:1:{ziel}', 'autoload' => 'auto'],
            ['option_name' => 'active_plugins', 'option_value' => 'a:1:{eigenes}', 'autoload' => 'on'],
            ['option_name' => 'siteurl', 'option_value' => 'https://stage.kunde.de', 'autoload' => 'on'],
        ];
    }

    /** @return array<string, array{option_value: string, autoload: string}> */
    function fremderStand(): array
    {
        return [
            'rhbp_peers' => ['option_value' => 'a:1:{quelle}', 'autoload' => 'auto'],
            'active_plugins' => ['option_value' => 'a:23:{fremde}', 'autoload' => 'on'],
            'siteurl' => ['option_value' => 'https://www.kunde.de', 'autoload' => 'on'],
            'blogname' => ['option_value' => 'Kunde', 'autoload' => 'on'],
        ];
    }

    function guardLauf(string $modus): array
    {
        $GLOBALS['wpdb'] = new FakeWpdb($modus);
        $GLOBALS['wpdb']->rows = fremderStand();

        $guard = new RhSync\Sync\LocalOptionGuard();

        try {
            $guard->applyTo('rhstg_options', eigenerStand());
            return ['ok' => true, 'message' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ====================================================================
    echo "\nA. Ein gescheiterter Schreibvorgang wird bemerkt\n";
    // ====================================================================

    $normal = guardLauf('normal');
    check('Der gute Fall läuft durch', $normal['ok'], $normal['message']);
    check(
        'Und der eigene Stand steht danach da',
        ($GLOBALS['wpdb']->rows['rhbp_peers']['option_value'] ?? '') === 'a:1:{ziel}',
        (string) ($GLOBALS['wpdb']->rows['rhbp_peers']['option_value'] ?? '(fehlt)')
    );
    check(
        'Ungeschützte Options bleiben unangetastet',
        ($GLOBALS['wpdb']->rows['blogname']['option_value'] ?? '') === 'Kunde'
    );

    $insertFehler = guardLauf('insert_fehler');
    check('Ein fehlgeschlagenes Einfügen bricht ab', !$insertFehler['ok']);
    check(
        'Und nennt die Option und den Datenbankfehler',
        str_contains($insertFehler['message'], 'rhbp_peers') && str_contains($insertFehler['message'], 'Unknown column'),
        $insertFehler['message']
    );

    $deleteFehler = guardLauf('delete_fehler');
    check('Ein fehlgeschlagenes Löschen bricht ab', !$deleteFehler['ok']);
    check(
        'Und nennt die betroffene Tabelle',
        str_contains($deleteFehler['message'], 'rhstg_options'),
        $deleteFehler['message']
    );

    // ====================================================================
    echo "\nB. Erfolg gemeldet, nichts passiert: genau der Fall vom 2026-08-10\n";
    // ====================================================================

    $stumm = guardLauf('stumm');
    check('Der Lauf bricht ab, obwohl jede Abfrage Erfolg gemeldet hat', !$stumm['ok']);
    check(
        'Und sagt, welche Werte fremd geblieben sind',
        str_contains($stumm['message'], 'nicht gegriffen') && str_contains($stumm['message'], 'rhbp_peers'),
        $stumm['message']
    );
    check(
        'Die fremden Werte stehen unverändert da (nichts halb Geschriebenes)',
        ($GLOBALS['wpdb']->rows['active_plugins']['option_value'] ?? '') === 'a:23:{fremde}'
    );

    // ====================================================================
    echo "\nC. Was die Sync-Engine ausmacht, reist nicht mit\n";
    // ====================================================================

    $GLOBALS['wpdb'] = new FakeWpdb();
    $muster = RhSync\Sync\LocalOptionGuard::engineOptions();

    require_once dirname(dirname(__DIR__)) . '/rh-db-engine/src/ExportCursor.php';
    $ausgeschlossen = static fn (string $name): bool => RhDbEngine\ExportCursor::optionExcluded($name, $muster);

    check('Die Peer-Liste bleibt zuhause', $ausgeschlossen('rhbp_peers'));
    check('Der Verlauf bleibt zuhause', $ausgeschlossen('rhbp_sync_log'));
    check('Die Job-Liste bleibt zuhause', $ausgeschlossen('rhbp_sync_jobs_index'));
    check('Ein einzelner Job-Stand bleibt zuhause', $ausgeschlossen('rhbp_sync_job_1fd73d717dbaf4bf'));
    check('Die Transients der Engine bleiben zuhause', $ausgeschlossen('_transient_rhbp_sync_status_abc'));

    check('Modul-Einstellungen wandern weiter mit', !$ausgeschlossen('rhbp_settings_seo'));
    check('Die Einstellungen des Sync-Moduls auch', !$ausgeschlossen('rhbp_settings_sync'));
    check('WordPress-Options sowieso', !$ausgeschlossen('blogname'));
    check('Und der Unterstrich ist kein Platzhalter', !$ausgeschlossen('rhbpXpeers'));

    // ====================================================================
    echo "\nD. Eine Kopplung mit der eigenen Website wird abgelehnt\n";
    // ====================================================================

    check('Die eigene Adresse', RhSync\Sync\PeerUrl::validate('https://www.kunde.de') === 'peer_is_self');
    check('Auch ohne www', RhSync\Sync\PeerUrl::validate('https://kunde.de') === 'peer_is_self');
    check('Auch mit anderem Schema', RhSync\Sync\PeerUrl::validate('http://www.kunde.de', true) === 'peer_is_self');
    check('Auch mit Schrägstrich am Ende', RhSync\Sync\PeerUrl::validate('https://www.kunde.de/') === 'peer_is_self');
    check('Eine andere Website geht durch', RhSync\Sync\PeerUrl::validate('https://stage.kunde.de') === null);
    check('Ein anderer Pfad ist eine andere Installation', RhSync\Sync\PeerUrl::validate('https://www.kunde.de/shop') === null);

    // ====================================================================
    echo "\n";
    if ($failures > 0) {
        echo "  {$failures} Prüfung(en) fehlgeschlagen.\n\n";
        exit(1);
    }
    echo "  Alle Prüfungen bestanden.\n\n";
    exit(0);
}
