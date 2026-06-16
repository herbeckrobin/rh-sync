# ADR 0001: Hintergrund-Sync über eine Tick-Engine (10-GB-fähig, resume-bar)

Status: akzeptiert
Datum: 2026-06-16

## Kontext

Der ursprüngliche Sync lief synchron in einem einzigen HTTP-Request, der per
`fastcgi_finish_request()` vom Browser getrennt wurde. Das hatte für große Datenmengen
(Ziel: 10 GB+, perspektivisch kompletter Filesystem-Sync) harte Grenzen:

- Export und Import liefen in einem Request. PHP-FPM (`request_terminate_timeout`) killt lange
  Läufe, `set_time_limit(0)` hilft dagegen nicht.
- Kein Heartbeat: ein gestorbener Job zeigte bis zu einer Stunde "läuft", ohne dass jemand den
  Stillstand erkannte.
- Keine Vorab-Prüfung von Plattenplatz/Server-Limits, kein Aufräumen verwaister Temp-Dateien.
- Ein users-Pull loggte den auslösenden Admin aus (Session-Token weg).

## Entscheidung

Sync läuft über eine eigene, leichtgewichtige **Tick-Engine**:

- **Resume-fähige db-engine:** `Importer::importStep()` / `Exporter::exportStep()` arbeiten ein
  Zeitbudget ab und geben einen serialisierbaren Cursor zurück. `importFromFile()` /
  `createBackup()` bleiben als dünne Wrapper (unbegrenztes Budget) erhalten, der rh-backup-Pfad
  ist unverändert.
- **Persistenter JobState** in einer autoload=no Option (nicht Transient), damit ein 10-GB-Cursor
  keinen Object-Cache-Flush-Verlust erleidet. Der schlanke Frontend-Status wird daraus in einen
  Polling-Transient projiziert (Frontend-Contract unverändert).
- **Tick-Antrieb:** Loopback-Self-Spawn (nicht-blockierender `wp_remote_post` an einen userlosen,
  per spawn_token authentifizierten ajax-Endpoint) als Primärtakt, WP-Cron-Watchdog (1 min) als
  Sicherheitsnetz für hängende Jobs + GC verwaister Temp-Dateien.
- **Geteilte Import-Maschine** (`ImportJobAdvancer`): Safety-Backup → Import → bei Fehler Rollback,
  alles resume-bar. Genutzt von Pull (lokaler Import) und Push (Ziel-Import als eigener Tick-Job).
- **Zwei Guards um den Import:** `LocalOptionGuard` (schützt Site-Identität: Peers, aktive Plugins,
  Login-URL) und `SessionGuard` (rettet die Admin-Session über einen users-Pull, kein Logout).
- **Preflight:** erhebt Server-Limits + freien Plattenplatz, warnt vor dem Start und blockt bei
  klar zu wenig Platz.

Bewusst NICHT gewählt: Action Scheduler als Dependency (eigene Tabellen, Versionskonflikt mit
WooCommerce-AS auf der Zielsite, widerspricht "geteilter Code wird in jedes Plugin gebundelt").

## Konsequenzen

- Auch sehr große Syncs laufen durch: kein einzelner Request muss Export, Transfer oder Import in
  einem Stück schaffen.
- Der Sync ist jederzeit beobachtbar (Heartbeat, Stale-Erkennung, Stillstand-Hinweis im UI).
- Null externe Dependencies, nur WP-Bordmittel (Transients/Options, WP-Cron, `wp_remote_post`).
- Loopback kann in abgeschotteten Umgebungen (Basic-Auth, Firewall, selbstsigniertes Cert)
  scheitern; dann trägt der Cron-Watchdog. Für solche Umgebungen System-Cron auf `wp-cron.php`
  empfehlen.
- **In-place-Import** (kein Zero-Downtime): die Zielseite ist während eines großen Imports kurz im
  Mischzustand. Shadow-Tabellen + atomarer `RENAME`-Swap sind eine spätere Phase 2.
- Rollout-Hinweis: die neuen beidseitigen Routen (Remote-Import-Job, Status-Poll) existieren erst
  nach einem Release auf beiden Peers. Während eines gemischten Rollouts ist das zu beachten.
