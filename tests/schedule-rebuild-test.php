<?php

/**
 * Standalone-Test für den Wiederaufbau der Termine nach einem Import.
 *   php tests/schedule-rebuild-test.php
 *
 * Hintergrund: der Schutz der Option `cron` hält beim Import die Sync-Zustände der Zielseite
 * fest. Die Beiträge wandern mit, ihre Termine nicht, und WordPress trägt sie nie von selbst
 * nach. Auf einer echten Kundenseite standen so 14 geplante Beiträge ohne einen einzigen
 * Termin, zwei davon längst überfällig.
 *
 * Zwei Dinge prüft dieser Test besonders scharf, weil sie beim Reparieren von Hand fast
 * schiefgegangen wären:
 *   - Der Termin muss aus `post_date` gerechnet werden. Das Feld `post_date_gmt` trägt auf
 *     importierten Beiträgen Unsinn, hier absichtlich ein Datum von 2019. Taucht es in einem
 *     gesetzten Termin auf, ist die Rechnung falsch.
 *   - Die Beitrags-ID muss als Zahl ins Argument-Array. WordPress erkennt Dubletten über
 *     md5(serialize($args)), und "42" ist damit ein anderer Termin als 42.
 *
 * Der Cron-Ersatz unten bildet genau diese Schlüssel-Semantik nach, damit der Test das
 * Verhalten von WordPress prüft und nicht nur unsere eigene Buchführung.
 */

declare(strict_types=1);

define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

// --- Zeitzone und Datums-Umrechnung ------------------------------------------

$GLOBALS['rh_tz_offset'] = 7200;      // Sekunden östlich von GMT (Europe/Berlin im Sommer)
$GLOBALS['rh_tz_label'] = 'Europe/Berlin';

/** Lokale Wandzeit zu einem GMT-Zeitpunkt, so wie sie in `post_date` stünde. */
function rh_local_date(int $timestamp): string
{
    return gmdate('Y-m-d H:i:s', $timestamp + $GLOBALS['rh_tz_offset']);
}

// --- WordPress-Ersatz --------------------------------------------------------

$GLOBALS['rh_options'] = [];
$GLOBALS['rh_cron'] = [];
$GLOBALS['rh_schedule_calls'] = [];
$GLOBALS['rh_schedule_fails'] = false;
$GLOBALS['rh_known_types'] = ['post', 'page', 'attachment'];

function __(string $t, string $d = ''): string
{
    return $t;
}
function _n(string $single, string $plural, int $n, string $d = ''): string
{
    return $n === 1 ? $single : $plural;
}
function apply_filters(string $hook, $value, ...$args)
{
    return $value;
}
function get_option(string $key, $default = false)
{
    return $GLOBALS['rh_options'][$key] ?? $default;
}
function wp_timezone_string(): string
{
    return $GLOBALS['rh_tz_label'];
}
function wp_strip_all_tags(string $text): string
{
    return strip_tags($text);
}
function wp_html_excerpt(string $text, int $length, string $more = ''): string
{
    return strlen($text) <= $length ? $text : substr($text, 0, $length) . $more;
}
function mysql2date(string $format, string $date, bool $translate = true): string
{
    $ts = strtotime($date . ' UTC');
    return $ts === false ? $date : gmdate($format, $ts);
}
function post_type_exists(string $type): bool
{
    return in_array($type, $GLOBALS['rh_known_types'], true);
}

/**
 * Die Umrechnung, um die es geht: lokale Wandzeit zu GMT, mit der Zeitzone der Website.
 */
function get_gmt_from_date(string $localDate, string $format = 'Y-m-d H:i:s'): string
{
    $ts = strtotime($localDate . ' UTC');
    if ($ts === false) {
        return '';
    }
    return gmdate($format, $ts - $GLOBALS['rh_tz_offset']);
}

// --- Cron-Ersatz mit der Schlüssel-Semantik von WordPress --------------------

function rh_cron_key(array $args): string
{
    return md5(serialize($args));
}

function _get_cron_array()
{
    $crons = $GLOBALS['rh_cron'];
    ksort($crons);
    return $crons;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wpError = false)
{
    $GLOBALS['rh_schedule_calls'][] = ['ts' => $timestamp, 'hook' => $hook, 'args' => $args];

    if ($GLOBALS['rh_schedule_fails']) {
        return false;
    }

    $key = rh_cron_key($args);

    // Die Dublettenerkennung von WordPress: gleicher Hook, gleicher Argument-Schlüssel.
    foreach ($GLOBALS['rh_cron'] as $ts => $hooks) {
        if (isset($hooks[$hook][$key])) {
            return false;
        }
    }

    $GLOBALS['rh_cron'][$timestamp][$hook][$key] = ['schedule' => false, 'args' => $args];

    return true;
}

function wp_next_scheduled(string $hook, array $args = [])
{
    $key = rh_cron_key($args);
    $found = false;

    foreach ($GLOBALS['rh_cron'] as $ts => $hooks) {
        if (isset($hooks[$hook][$key]) && ($found === false || $ts < $found)) {
            $found = $ts;
        }
    }

    return $found;
}

function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
{
    $key = rh_cron_key($args);

    if (!isset($GLOBALS['rh_cron'][$timestamp][$hook][$key])) {
        return false;
    }

    unset($GLOBALS['rh_cron'][$timestamp][$hook][$key]);

    if ($GLOBALS['rh_cron'][$timestamp][$hook] === []) {
        unset($GLOBALS['rh_cron'][$timestamp][$hook]);
    }
    if ($GLOBALS['rh_cron'][$timestamp] === []) {
        unset($GLOBALS['rh_cron'][$timestamp]);
    }

    return true;
}

/** Alle Termine eines Hooks als [id => timestamp]. */
function rh_scheduled_ids(string $hook): array
{
    $out = [];
    foreach ($GLOBALS['rh_cron'] as $ts => $hooks) {
        foreach ($hooks[$hook] ?? [] as $event) {
            $out[] = ['ts' => (int) $ts, 'args' => $event['args']];
        }
    }
    return $out;
}

// --- Datenbank-Ersatz, der die drei Abfragen des Rebuilders beantwortet ------

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';

    /** @var array<int, array<string, mixed>> */
    public array $inventory = [];

    public bool $pendingPings = false;

    /** @var array<int, string> */
    public array $queries = [];

    public function prepare(string $sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $out = '';
        $i = 0;
        $len = strlen($sql);

        for ($pos = 0; $pos < $len; $pos++) {
            if ($sql[$pos] === '%' && $pos + 1 < $len) {
                $type = $sql[$pos + 1];
                if ($type === 's') {
                    $out .= "'" . str_replace("'", "\\'", (string) ($args[$i++] ?? '')) . "'";
                    $pos++;
                    continue;
                }
                if ($type === 'd') {
                    $out .= (string) (int) ($args[$i++] ?? 0);
                    $pos++;
                    continue;
                }
            }
            $out .= $sql[$pos];
        }

        return $out;
    }

    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;

        if (str_contains($sql, 'DISTINCT post_type')) {
            $types = [];
            foreach ($this->inventory as $row) {
                $types[(string) $row['post_type']] = true;
            }
            return array_keys($types);
        }

        return [];
    }

    public function get_var(string $sql)
    {
        $this->queries[] = $sql;

        if (str_contains($sql, '_pingme')) {
            return $this->pendingPings ? '1' : null;
        }

        return null;
    }

    public function get_results(string $sql, $output = null): array
    {
        $this->queries[] = $sql;

        if (str_contains($sql, "post_mime_type = 'import'")) {
            $rows = array_values(array_filter(
                $this->inventory,
                static fn (array $r): bool => ($r['post_type'] ?? '') === 'attachment'
                    && ($r['post_mime_type'] ?? '') === 'import'
            ));
            usort($rows, static fn (array $a, array $b): int => $a['ID'] <=> $b['ID']);

            return array_map(
                static fn (array $r): array => ['ID' => (string) $r['ID'], 'post_date' => (string) $r['post_date']],
                $rows
            );
        }

        if (str_contains($sql, 'WHERE ID IN')) {
            preg_match('/IN \(([^)]*)\)/', $sql, $m);
            $ids = array_map('intval', array_map('trim', explode(',', $m[1] ?? '')));

            $rows = [];
            foreach ($this->inventory as $row) {
                if (in_array((int) $row['ID'], $ids, true)) {
                    $rows[] = [
                        'ID' => (string) $row['ID'],
                        'post_status' => (string) $row['post_status'],
                        'post_type' => (string) $row['post_type'],
                    ];
                }
            }

            return $rows;
        }

        if (str_contains($sql, "post_status = 'future'")) {
            preg_match("/post_type = '([^']*)'/", $sql, $mType);
            $type = $mType[1] ?? '';

            preg_match("/post_date > '([^']*)'/", $sql, $mDate);
            $afterDate = $mDate[1] ?? '';

            preg_match('/ID > (\d+)/', $sql, $mId);
            $afterId = (int) ($mId[1] ?? 0);

            preg_match('/LIMIT (\d+)/', $sql, $mLimit);
            $limit = (int) ($mLimit[1] ?? 200);

            $rows = array_values(array_filter(
                $this->inventory,
                static fn (array $r): bool => ($r['post_type'] ?? '') === $type
                    && ($r['post_status'] ?? '') === 'future'
            ));

            usort($rows, static function (array $a, array $b): int {
                return [$a['post_date'], $a['ID']] <=> [$b['post_date'], $b['ID']];
            });

            if ($afterDate !== '') {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r): bool => $r['post_date'] > $afterDate
                        || ($r['post_date'] === $afterDate && (int) $r['ID'] > $afterId)
                ));
            }

            $rows = array_slice($rows, 0, $limit);

            return array_map(static fn (array $r): array => [
                'ID' => (string) $r['ID'],
                'post_title' => (string) $r['post_title'],
                'post_type' => (string) $r['post_type'],
                'post_date' => (string) $r['post_date'],
            ], $rows);
        }

        return [];
    }
}

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

// Die Tick-Engine liefert das Interface, das JobState und UploadJob erfuellen.
require_once dirname(__DIR__) . '/vendor/rh/tick-engine/autoload-src.php';

require_once dirname(__DIR__) . '/inc/Sync/ScheduleReport.php';
require_once dirname(__DIR__) . '/inc/Sync/ScheduleRebuilder.php';
require_once dirname(__DIR__) . '/inc/Sync/Schedule.php';

use RhSync\Sync\ScheduleRebuilder;
use RhSync\Sync\ScheduleReport;

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

/**
 * Setzt Datenbank und Cron zurück und legt ein Inventar an.
 *
 * @param array<int, array<string, mixed>> $inventory
 */
function rh_reset(array $inventory, array $cron = [], bool $pendingPings = false): void
{
    global $wpdb;

    $wpdb->inventory = $inventory;
    $wpdb->pendingPings = $pendingPings;
    $wpdb->queries = [];

    $GLOBALS['rh_cron'] = $cron;
    $GLOBALS['rh_schedule_calls'] = [];
    $GLOBALS['rh_schedule_fails'] = false;
}

/**
 * Ein geplanter Beitrag, dessen Termin um $offset Sekunden von jetzt entfernt liegt.
 * `post_date_gmt` bekommt bewusst Unsinn aus dem Jahr 2019.
 */
function rh_future_post(int $id, int $offset, string $type = 'post', string $title = ''): array
{
    return [
        'ID' => $id,
        'post_title' => $title !== '' ? $title : ('Beitrag ' . $id),
        'post_type' => $type,
        'post_status' => 'future',
        'post_date' => rh_local_date(time() + $offset),
        'post_date_gmt' => '2019-03-05 08:00:00',
        'post_mime_type' => '',
    ];
}

function rh_run(bool $optionsSynced = true): ScheduleReport
{
    return (new ScheduleRebuilder())->runToCompletion($optionsSynced);
}

// ============================================================================
echo "\nA1/A2. Vierzehn geplante Beiträge, kein einziger Termin\n";
// ============================================================================

$inventory = [];
for ($i = 1; $i <= 12; $i++) {
    $inventory[] = rh_future_post(100 + $i, ($i + 1) * HOUR_IN_SECONDS);
}
$inventory[] = rh_future_post(200, -3 * DAY_IN_SECONDS, 'post', 'Längst fällig');
$inventory[] = rh_future_post(201, -21 * DAY_IN_SECONDS, 'post', 'Seit drei Wochen');

rh_reset($inventory);
$report = rh_run();

check('12 Termine gesetzt, die zwei überfälligen nicht', $report->scheduled === 12, 'scheduled=' . $report->scheduled);
check('14 Beiträge angesehen', $report->scanned === 14, 'scanned=' . $report->scanned);

$allInt = true;
$argCount = true;
foreach (rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH) as $event) {
    if (count($event['args']) !== 1) {
        $argCount = false;
    }
    if (!is_int($event['args'][0] ?? null)) {
        $allInt = false;
    }
}
check('Argument-Array trägt genau einen Wert', $argCount);
check('Die Beitrags-ID ist eine Zahl, kein Text', $allInt);

$expected = strtotime(get_gmt_from_date($inventory[0]['post_date']) . ' GMT');
$actual = wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [101]);
check('Termin stimmt mit der Rechnung aus post_date überein', $actual === $expected, 'erwartet ' . $expected . ', gesetzt ' . var_export($actual, true));

$bogus = strtotime('2019-03-05 08:00:00 GMT');
$usedBogus = false;
foreach (rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH) as $event) {
    if (abs($event['ts'] - $bogus) < DAY_IN_SECONDS) {
        $usedBogus = true;
    }
}
check('Das falsche post_date_gmt taucht in keinem Termin auf', !$usedBogus);

// ============================================================================
echo "\nA3. Überfällige werden gemeldet, nicht geplant\n";
// ============================================================================

check('Zwei überfällige gezählt', $report->overdueTotal === 2, 'overdue_total=' . $report->overdueTotal);
check('Beide namentlich aufgeführt', count($report->overdue) === 2);
check('Kein Termin für den überfälligen Beitrag', wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [200]) === false);

$titles = array_column($report->overdue, 'title');
check('Titel steht im Bericht', in_array('Längst fällig', $titles, true), implode(' | ', $titles));
check('Termin steht im Bericht', ($report->overdue[0]['date'] ?? '') !== '');
check('Die Meldung nennt beide Zahlen', str_contains($report->headline(), '12') && str_contains($report->headline(), '2'), $report->headline());

// ============================================================================
echo "\nA4. Ein zweiter Durchlauf ändert nichts\n";
// ============================================================================

$GLOBALS['rh_schedule_calls'] = [];
$second = rh_run();

check('Kein einziger Termin wird noch einmal gesetzt', $GLOBALS['rh_schedule_calls'] === [], count($GLOBALS['rh_schedule_calls']) . ' Aufrufe');
check('Zweiter Lauf meldet keine neuen Termine', $second->scheduled === 0);
check('Die Überfälligen werden weiterhin gemeldet', $second->overdueTotal === 2);

// ============================================================================
echo "\nA5. Verwaiste Termine verschwinden, fremde bleiben\n";
// ============================================================================

$live = rh_future_post(300, 5 * HOUR_IN_SECONDS);
$cron = [
    time() + 3600 => [
        ScheduleRebuilder::HOOK_PUBLISH => [
            rh_cron_key([999999]) => ['schedule' => false, 'args' => [999999]],
        ],
        'woocommerce_scheduled_sales' => [
            rh_cron_key([]) => ['schedule' => false, 'args' => []],
        ],
    ],
];
rh_reset([$live], $cron);
$report = rh_run();

check('Der Termin ohne Beitrag ist weg', wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [999999]) === false);
check('Als verwaist gezählt', $report->orphansRemoved === 1, 'orphans=' . $report->orphansRemoved);
check('Der fremde Hook bleibt unangetastet', wp_next_scheduled('woocommerce_scheduled_sales') !== false);
check('Der gültige Beitrag hat seinen Termin', wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [300]) !== false);

$stalePost = rh_future_post(400, 4 * HOUR_IN_SECONDS);
$stalePost['post_status'] = 'publish';
$cron = [
    time() + 7200 => [
        ScheduleRebuilder::HOOK_PUBLISH => [
            rh_cron_key([400]) => ['schedule' => false, 'args' => [400]],
        ],
    ],
];
rh_reset([$stalePost], $cron);
$report = rh_run();

check('Termin zu einem längst veröffentlichten Beitrag verschwindet', wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [400]) === false);
check('Als überholt gezählt, nicht als verwaist', $report->staleRemoved === 1 && $report->orphansRemoved === 0);

// ============================================================================
echo "\nA6. Eine ID als Text wird durch die Zahl ersetzt\n";
// ============================================================================

$post42 = rh_future_post(42, 6 * HOUR_IN_SECONDS);
$cron = [
    time() + 3600 => [
        ScheduleRebuilder::HOOK_PUBLISH => [
            rh_cron_key(['42']) => ['schedule' => false, 'args' => ['42']],
        ],
    ],
];
rh_reset([$post42], $cron);
$report = rh_run();

$events = rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH);
check('Am Ende gibt es genau einen Termin für 42', count($events) === 1, count($events) . ' Termine');
check('Und der trägt die Zahl, nicht den Text', is_int($events[0]['args'][0] ?? null));
check('Der Text-Eintrag wurde als überholt entfernt', $report->staleRemoved === 1);

// ============================================================================
echo "\nA7. Ein falscher Termin wird geradegezogen\n";
// ============================================================================

$post = rh_future_post(500, 8 * HOUR_IN_SECONDS);
$wrong = time() + 99 * HOUR_IN_SECONDS;
$cron = [
    $wrong => [
        ScheduleRebuilder::HOOK_PUBLISH => [
            rh_cron_key([500]) => ['schedule' => false, 'args' => [500]],
        ],
    ],
];
rh_reset([$post], $cron);
$report = rh_run();

$correct = strtotime(get_gmt_from_date($post['post_date']) . ' GMT');
check('Der Termin steht jetzt richtig', wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [500]) === $correct);
check('Als korrigiert gezählt', $report->corrected === 1, 'corrected=' . $report->corrected);
check('Nicht verdoppelt', count(rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH)) === 1);

// ============================================================================
echo "\nA8. Wenn das Setzen fehlschlägt, läuft der Lauf weiter\n";
// ============================================================================

rh_reset([rh_future_post(600, HOUR_IN_SECONDS), rh_future_post(601, 2 * HOUR_IN_SECONDS)]);
$GLOBALS['rh_schedule_fails'] = true;

$threw = false;
try {
    $report = rh_run();
} catch (\Throwable $e) {
    $threw = true;
}

check('Der Lauf wirft nicht', !$threw);
check('Beide Fehlschläge sind gezählt', !$threw && $report->failed === 2, $threw ? 'Ausnahme' : 'failed=' . $report->failed);
check('Und keiner gilt als gesetzt', !$threw && $report->scheduled === 0);

// ============================================================================
echo "\nA9. Liegengebliebene Verweise bekommen ihren Termin\n";
// ============================================================================

rh_reset([], [], true);
$report = rh_run();
check('Ein Termin für die Verweise', $report->pings === 1);
$pings = rh_scheduled_ids(ScheduleRebuilder::HOOK_PINGS);
check('Ohne Argumente, wie es WordPress selbst tut', count($pings) === 1 && $pings[0]['args'] === []);

rh_reset([], [], false);
$report = rh_run();
check('Ohne liegengebliebene Verweise kein Termin', $report->pings === 0);

rh_reset([], [
    time() + 60 => [ScheduleRebuilder::HOOK_PINGS => [rh_cron_key([]) => ['schedule' => false, 'args' => []]]],
], true);
$report = rh_run();
check('Ein vorhandener Termin wird nicht verdoppelt', $report->pings === 0 && count(rh_scheduled_ids(ScheduleRebuilder::HOOK_PINGS)) === 1);

// ============================================================================
echo "\nA10. Aufräumen nach einem WordPress-Import\n";
// ============================================================================

$attachment = [
    'ID' => 700,
    'post_title' => 'import.xml',
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'post_date' => rh_local_date(time() - HOUR_IN_SECONDS),
    'post_date_gmt' => '2019-03-05 08:00:00',
    'post_mime_type' => 'import',
];
rh_reset([$attachment]);
$report = rh_run();

$expected = strtotime(get_gmt_from_date($attachment['post_date']) . ' GMT') + DAY_IN_SECONDS;
check('Termin liegt einen Tag nach dem Hochladen', wp_next_scheduled(ScheduleRebuilder::HOOK_IMPORTER, [700]) === $expected);
check('Als Aufräum-Termin gezählt', $report->importerCleanups === 1);

$old = $attachment;
$old['ID'] = 701;
$old['post_date'] = rh_local_date(time() - 3 * DAY_IN_SECONDS);
rh_reset([$old]);
$report = rh_run();

check('Eine längst fällige Import-Datei wird nur gemeldet', $report->importerOverdue === 1 && $report->importerCleanups === 0);
check('Und bekommt keinen Termin in der Vergangenheit', wp_next_scheduled(ScheduleRebuilder::HOOK_IMPORTER, [701]) === false);

// ============================================================================
echo "\nA11. Der Lauf lässt sich unterbrechen und fortsetzen\n";
// ============================================================================

$inventory = [];
for ($i = 1; $i <= 14; $i++) {
    $inventory[] = rh_future_post(800 + $i, $i * HOUR_IN_SECONDS);
}
rh_reset($inventory);

$state = ScheduleRebuilder::start();
$rebuilder = new ScheduleRebuilder();

// Deadline schon abgelaufen: jeder Durchgang macht genau ein Häppchen und hört auf.
// Der erste sammelt die Inhaltsarten, erst der zweite kommt an die Beiträge.
$state = $rebuilder->step($state, microtime(true) - 1.0);
check('Der erste Durchgang ist nicht fertig', !ScheduleRebuilder::isDone($state));
check('Er hat die Inhaltsarten gesammelt', ($state['stage'] ?? '') === ScheduleRebuilder::STAGE_POSTS, (string) ($state['stage'] ?? ''));

$state = $rebuilder->step($state, microtime(true) - 1.0);
$partial = count(rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH));
check('Der zweite hat angefangen, aber nicht alles geschafft', $partial > 0 && $partial < 14, $partial . ' von 14');
check('Der Zustand merkt sich die Stelle', (string) ($state['after_date'] ?? '') !== '');

$resumeFrom = (string) $state['after_date'];

$guard = 0;
while (!ScheduleRebuilder::isDone($state) && $guard++ < 30) {
    $state = $rebuilder->step($state, microtime(true) + 5.0);
}
$report = ScheduleRebuilder::report($state);

check('Am Ende stehen alle 14 Termine', count(rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH)) === 14, (string) count(rh_scheduled_ids(ScheduleRebuilder::HOOK_PUBLISH)));
check('Keiner doppelt', $report->scheduled === 14, 'scheduled=' . $report->scheduled);
check('Die Fortsetzung hat da weitergemacht, wo abgebrochen wurde', $resumeFrom !== '' && $report->scanned === 14, 'scanned=' . $report->scanned);

// ============================================================================
echo "\nA12. Eine unbekannte Inhaltsart wird gemeldet, nicht übersprungen\n";
// ============================================================================

rh_reset([
    rh_future_post(900, HOUR_IN_SECONDS, 'produkt'),
    rh_future_post(901, 2 * HOUR_IN_SECONDS, 'produkt'),
    rh_future_post(902, 3 * HOUR_IN_SECONDS, 'post'),
]);
$report = rh_run();

check('Der Termin wird trotzdem gesetzt', wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [900]) !== false);
check('Die Inhaltsart steht mit ihrer Anzahl im Bericht', ($report->unregisteredTypes['produkt'] ?? 0) === 2, var_export($report->unregisteredTypes, true));
check('Bekannte Arten tauchen dort nicht auf', !isset($report->unregisteredTypes['post']));
check('Und der Hinweis steht in den Zeilen', (bool) array_filter($report->lines(), static fn (string $l): bool => str_contains($l, 'produkt')));

// ============================================================================
echo "\nA13. Die Zeitzone geht in die Rechnung ein\n";
// ============================================================================

$offsets = [
    ['label' => 'Europe/Berlin', 'offset' => 7200],
    ['label' => 'Australia/Sydney', 'offset' => 36000],
    ['label' => 'America/New_York', 'offset' => -14400],
];

foreach ($offsets as $tz) {
    $GLOBALS['rh_tz_offset'] = $tz['offset'];
    $GLOBALS['rh_tz_label'] = $tz['label'];

    $post = rh_future_post(1000, 10 * HOUR_IN_SECONDS);
    rh_reset([$post]);
    rh_run();

    $wall = strtotime($post['post_date'] . ' UTC');
    $expected = $wall - $tz['offset'];

    check(
        'Termin folgt der Zeitzone ' . $tz['label'],
        wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [1000]) === $expected,
        'erwartet ' . $expected . ', gesetzt ' . var_export(wp_next_scheduled(ScheduleRebuilder::HOOK_PUBLISH, [1000]), true)
    );
}

$GLOBALS['rh_tz_offset'] = 7200;
$GLOBALS['rh_tz_label'] = 'Europe/Berlin';

rh_reset([rh_future_post(1100, HOUR_IN_SECONDS)]);
$report = rh_run(false);
check('Ohne mitgesyncte Einstellungen steht die Zeitzone im Bericht', $report->ownTimezone && $report->timezone === 'Europe/Berlin', $report->timezone);
check('Und der Hinweis erscheint als Zeile', (bool) array_filter($report->lines(), static fn (string $l): bool => str_contains($l, 'Zeitzone')));

rh_reset([rh_future_post(1101, HOUR_IN_SECONDS)]);
$report = rh_run(true);
check('Mit mitgesyncten Einstellungen kein solcher Hinweis', !$report->ownTimezone);

// ============================================================================
echo "\nA14. Sehr viele Beiträge sprengen den Lauf nicht\n";
// ============================================================================

$inventory = [];
for ($i = 0; $i < 6000; $i++) {
    $inventory[] = rh_future_post(10000 + $i, ($i + 1) * 60);
}
rh_reset($inventory);

$start = microtime(true);
$report = rh_run();
$elapsed = microtime(true) - $start;

check('Der Lauf endet', $elapsed < 60.0, sprintf('%.1f s', $elapsed));
check('Und sagt, dass er nicht fertig wurde', $report->truncated);
check('Er hat trotzdem gearbeitet', $report->scheduled > 0, 'scheduled=' . $report->scheduled);
check('Die Meldung nennt die Einschränkung', (bool) array_filter($report->lines(), static fn (string $l): bool => str_contains($l, 'nächste Sync')));

// ============================================================================
echo "\nB1. Ein hängender Nachlauf ist kein fehlgeschlagener Import\n";
// ============================================================================

require_once dirname(__DIR__) . '/inc/Sync/SyncStatus.php';
require_once dirname(__DIR__) . '/inc/Sync/JobState.php';
require_once dirname(__DIR__) . '/inc/Sync/StageAdvancer.php';
require_once dirname(__DIR__) . '/inc/Sync/JobScheduler.php';
require_once dirname(__DIR__) . '/inc/Sync/SyncLog.php';
require_once dirname(__DIR__) . '/inc/Sync/PeerRegistry.php';
require_once dirname(__DIR__) . '/inc/Sync/TickRunner.php';

$stuck = new ReflectionMethod(RhSync\Sync\TickRunner::class, 'stuckInAftercare');
$runner = (new ReflectionClass(RhSync\Sync\TickRunner::class))->newInstanceWithoutConstructor();

/** Ein Job in der angegebenen Phase, Daten je nach Flag schon live. */
function rh_job(string $phase, bool $committed): RhSync\Sync\JobState
{
    $job = new RhSync\Sync\JobState(
        jobId: str_repeat('a', 32),
        peerId: 'peer1',
        direction: RhSync\Sync\SyncStatus::DIRECTION_PULL,
        type: RhSync\Sync\JobState::TYPE_DB_SYNC,
        profile: [],
        spawnToken: str_repeat('b', 32),
        createdAt: time(),
        lastUpdateAt: time(),
        stage: RhSync\Sync\SyncStatus::PHASE_IMPORT,
    );
    $job->cursor['ij_phase'] = $phase;
    $job->importCommitted = $committed;

    return $job;
}

check(
    'Steckengebliebener Nachlauf zählt nicht als Fehlschlag',
    $stuck->invoke($runner, rh_job('aftercare', true)) === true
);
check(
    'Ein Abbruch mitten im Einspielen dagegen schon',
    $stuck->invoke($runner, rh_job('import', true)) === false
);
check(
    'Und einer, bevor die Daten live standen, erst recht',
    $stuck->invoke($runner, rh_job('aftercare', false)) === false
);

// ============================================================================
echo "\nB2. Der Bericht übersteht Speichern und Laden\n";
// ============================================================================

$overdue = [];
for ($i = 0; $i < 40; $i++) {
    $overdue[] = ['id' => $i, 'title' => 'Titel ' . $i, 'type' => 'post', 'date' => '01.08.2026 09:00'];
}

$report = new ScheduleReport(
    scheduled: 12,
    overdue: array_slice($overdue, 0, ScheduleReport::MAX_LISTED),
    overdueTotal: 40,
    corrected: 3,
    orphansRemoved: 1,
    staleRemoved: 2,
    pings: 1,
    importerCleanups: 0,
    importerOverdue: 0,
    failed: 0,
    unregisteredTypes: ['produkt' => 2],
    timezone: 'Europe/Berlin',
    scanned: 52,
    truncated: false,
    ownTimezone: true,
);

$round = ScheduleReport::fromArray($report->toArray());

check('Alle Werte kommen unverändert zurück', $round->toArray() === $report->toArray());
check('Die Liste bleibt gedeckelt', count($round->overdue) === ScheduleReport::MAX_LISTED);
check('Die echte Zahl bleibt erhalten', $round->overdueTotal === 40);
check('Die Zeilen weisen auf den Rest hin', (bool) array_filter($round->lines(), static fn (string $l): bool => str_contains($l, '15 weitere')));

$empty = new ScheduleReport();
check('Ein leerer Bericht weiß, dass er leer ist', $empty->isEmpty());
check('Und zeigt nichts an', $empty->note() === null);
check('Ein gefüllter schon', $report->note() !== null && $report->note()['tone'] === 'warn');
check('Das Etikett bleibt kurz', strlen((string) ($report->pill()['text'] ?? '')) <= 20, (string) ($report->pill()['text'] ?? ''));

// ============================================================================
echo "\n";
if ($failures === 0) {
    echo "Alle Prüfungen bestanden.\n\n";
    exit(0);
}

echo $failures . " Prüfung(en) fehlgeschlagen.\n\n";
exit(1);
