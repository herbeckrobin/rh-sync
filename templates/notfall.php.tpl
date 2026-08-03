<?php
/**
 * Notfall-Wiederanlauf fuer rh-sync.
 *
 * Diese Datei wird von rh-sync erzeugt, kurz bevor ein Import ohne atomares Umschalten
 * beginnt, und nach dem Import wieder entfernt. Sie kommt ohne WordPress aus: sie liest nur
 * die Zugangsdaten aus der wp-config.php und spricht die Datenbank direkt an. Genau darum
 * geht es, denn wenn sie gebraucht wird, startet WordPress nicht mehr.
 *
 * Aufruf: diese Adresse im Browser oeffnen, das Kennwort steht in der Sync-Oberflaeche und
 * im Verlaufsprotokoll des Jobs.
 *
 * Kein Umlaut-Zeichen in dieser Datei: sie laeuft ohne WordPress und ohne gesetzte
 * Zeichensatz-Umgebung, und ein falsch dekodiertes Wort im Notfall hilft niemandem.
 */

$rhsTokenHash = '{{TOKEN_HASH}}';
$rhsSnapshot  = '{{SNAPSHOT_PATH}}';
$rhsWpConfig  = '{{WP_CONFIG}}';
$rhsExpires   = (int) '{{EXPIRES}}';
$rhsJobId     = '{{JOB_ID}}';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

/** Beendet die Ausgabe, optional mit Selbstloeschung. */
function rhs_stop(string $title, string $body, int $status = 200, bool $removeSelf = false): void
{
    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><title>rh-sync Notfall</title>';
    echo '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:44rem;margin:3rem auto;padding:0 1rem}'
        . 'h1{font-size:1.4rem}code,pre{background:#f4f4f5;padding:.15rem .35rem;border-radius:.25rem}'
        . 'pre{padding:1rem;overflow:auto}</style>';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $body;

    if ($removeSelf) {
        @unlink(__FILE__);
    }

    exit;
}

if (time() > $rhsExpires) {
    rhs_stop(
        'Abgelaufen',
        '<p>Dieser Wiederanlauf ist abgelaufen und hat sich soeben entfernt.</p>',
        410,
        true
    );
}

$rhsGiven = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($rhsGiven === '' || !hash_equals($rhsTokenHash, hash('sha256', $rhsGiven))) {
    rhs_stop('Kein Zugriff', '<p>Kennwort fehlt oder stimmt nicht.</p>', 403);
}

/**
 * Liest die per define() gesetzten Konstanten und den Tabellen-Prefix aus der wp-config.php.
 *
 * Ueber den PHP-Tokenizer statt per Regex: die Zugangsdaten stehen je nach Hoster in
 * einfachen oder doppelten Anfuehrungszeichen, mit oder ohne Leerzeichen, teils mit
 * maskierten Zeichen im Kennwort.
 *
 * @return array{consts: array<string, string>, prefix: string}
 */
function rhs_read_config(string $file): array
{
    $src = @file_get_contents($file);
    if (!is_string($src) || $src === '') {
        rhs_stop('wp-config.php nicht lesbar', '<p>Erwartet unter <code>'
            . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</code>.</p>', 500);
    }

    $unquote = static function (string $literal): string {
        $quote = $literal[0] ?? "'";
        $inner = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : stripcslashes($inner);
    };

    $tokens = token_get_all($src);
    $count = count($tokens);
    $consts = [];
    $prefix = '';

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'define') {
            $values = [];
            for ($j = $i + 1; $j < $count && count($values) < 2; $j++) {
                if ($tokens[$j] === ')' || $tokens[$j] === ';') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $values[] = $unquote($tokens[$j][1]);
                }
            }
            if (count($values) === 2) {
                $consts[$values[0]] = $values[1];
            }
            continue;
        }

        if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$table_prefix') {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $prefix = $unquote($tokens[$j][1]);
                    break;
                }
            }
        }
    }

    return ['consts' => $consts, 'prefix' => $prefix];
}

$rhsConfig = rhs_read_config($rhsWpConfig);
$rhsConsts = $rhsConfig['consts'];

foreach (['DB_NAME', 'DB_USER', 'DB_HOST'] as $needed) {
    if (!isset($rhsConsts[$needed])) {
        rhs_stop('Zugangsdaten unvollstaendig', '<p>In der wp-config.php fehlt <code>'
            . htmlspecialchars($needed, ENT_QUOTES, 'UTF-8') . '</code>.</p>', 500);
    }
}

$rhsHost = $rhsConsts['DB_HOST'];
$rhsPort = null;
$rhsSock = null;
if (strpos($rhsHost, ':') !== false) {
    list($rhsHost, $rhsTail) = explode(':', $rhsHost, 2);
    if (ctype_digit($rhsTail)) {
        $rhsPort = (int) $rhsTail;
    } else {
        $rhsSock = $rhsTail;
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$rhsDb = @new mysqli(
    $rhsHost,
    $rhsConsts['DB_USER'],
    isset($rhsConsts['DB_PASSWORD']) ? $rhsConsts['DB_PASSWORD'] : '',
    $rhsConsts['DB_NAME'],
    $rhsPort,
    $rhsSock
);

if ($rhsDb->connect_errno) {
    rhs_stop('Keine Datenbank-Verbindung', '<p>' . htmlspecialchars($rhsDb->connect_error ?? '', ENT_QUOTES, 'UTF-8')
        . '</p><p>Dann liegt es wirklich an der Datenbank und nicht am Import.</p>', 500);
}

$rhsDb->set_charset(isset($rhsConsts['DB_CHARSET']) && $rhsConsts['DB_CHARSET'] !== ''
    ? $rhsConsts['DB_CHARSET']
    : 'utf8mb4');

$rhsPrefix = $rhsConfig['prefix'] !== '' ? $rhsConfig['prefix'] : 'wp_';

/** Vorhandene Tabellen der Site einsammeln. */
$rhsTables = [];
if ($rhsRes = $rhsDb->query('SHOW TABLES')) {
    while ($row = $rhsRes->fetch_array(MYSQLI_NUM)) {
        $rhsTables[$row[0]] = true;
    }
    $rhsRes->close();
}

$rhsCore = ['options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships'];
$rhsMissing = [];
foreach ($rhsCore as $rhsBase) {
    if (!isset($rhsTables[$rhsPrefix . $rhsBase])) {
        $rhsMissing[] = $rhsPrefix . $rhsBase;
    }
}

$rhsReport = '';

// Fall A: es fehlen ganze Tabellen. Das laesst sich hier nicht ehrlich reparieren, dafuer
// braucht es das Sicherheits-Backup. Diese Datei sagt genau das, statt so zu tun als ob.
if ($rhsMissing !== []) {
    $rhsReport .= '<p><strong>Es fehlen Tabellen.</strong> Der Import ist mitten in der '
        . 'Uebertragung gestorben. Das Zurueckschreiben der Optionen allein hilft hier nicht.</p>'
        . '<pre>' . htmlspecialchars(implode("\n", $rhsMissing), ENT_QUOTES, 'UTF-8') . '</pre>'
        . '<p>Der Weg zurueck fuehrt ueber das Sicherheits-Backup, das der Import vor dem '
        . 'Schreiben angelegt hat. Es liegt unter <code>wp-content/rh-blueprint-data/backups/presync/</code> '
        . 'und traegt den juengsten Zeitstempel.</p>';
}

// Fall B: die Tabellen sind da, aber die Site kennt sich selbst nicht mehr. Genau das
// laesst sich hier vollstaendig geradebiegen.
$rhsSnap = @file_get_contents($rhsSnapshot);
$rhsData = is_string($rhsSnap) ? json_decode($rhsSnap, true) : null;

if (!is_array($rhsData) || !isset($rhsData['options']) || !is_array($rhsData['options'])) {
    $rhsReport .= '<p>Kein brauchbarer Options-Snapshot unter <code>'
        . htmlspecialchars($rhsSnapshot, ENT_QUOTES, 'UTF-8') . '</code>.</p>';
    rhs_stop('Nichts zurueckzuschreiben', $rhsReport, 500);
}

if (!isset($rhsTables[$rhsPrefix . 'options'])) {
    rhs_stop('Options-Tabelle fehlt', $rhsReport, 500);
}

$rhsWrote = 0;
$rhsOptions = $rhsPrefix . 'options';

$rhsDel = $rhsDb->prepare("DELETE FROM `{$rhsOptions}` WHERE option_name = ?");
$rhsIns = $rhsDb->prepare("INSERT INTO `{$rhsOptions}` (option_name, option_value, autoload) VALUES (?, ?, ?)");

if ($rhsDel === false || $rhsIns === false) {
    rhs_stop('Schreiben nicht moeglich', $rhsReport . '<p>'
        . htmlspecialchars($rhsDb->error, ENT_QUOTES, 'UTF-8') . '</p>', 500);
}

foreach ($rhsData['options'] as $rhsRow) {
    if (!is_array($rhsRow) || !isset($rhsRow['option_name'], $rhsRow['option_value'])) {
        continue;
    }

    $rhsName = (string) $rhsRow['option_name'];
    $rhsValue = (string) $rhsRow['option_value'];
    $rhsAuto = isset($rhsRow['autoload']) ? (string) $rhsRow['autoload'] : 'no';

    $rhsDel->bind_param('s', $rhsName);
    $rhsDel->execute();

    $rhsIns->bind_param('sss', $rhsName, $rhsValue, $rhsAuto);
    if ($rhsIns->execute()) {
        $rhsWrote++;
    }
}

$rhsDel->close();
$rhsIns->close();
$rhsDb->close();

$rhsReport .= '<p><strong>' . $rhsWrote . ' Optionen zurueckgeschrieben</strong> (Adresse, aktive '
    . 'Plugins, Rollen, Peer-Liste). Job <code>' . htmlspecialchars($rhsJobId, ENT_QUOTES, 'UTF-8')
    . '</code>.</p><p>Jetzt die Startseite aufrufen. Kommt sie hoch, war es das. Falls ein '
    . 'Objekt-Cache laeuft, einmal leeren.</p>';

if ($rhsMissing === []) {
    $rhsReport .= '<p>Diese Datei hat sich soeben selbst entfernt.</p>';
}

rhs_stop('Erledigt', $rhsReport, 200, $rhsMissing === []);
