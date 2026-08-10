# rh-sync testen

Zwei Ebenen, weil sie verschiedene Fragen beantworten.

**Ohne WordPress** prüft die Logik: Rechnungen, Zustandsübergänge, Grenzfälle. Läuft in
Sekunden, braucht keine Datenbank, und genau deshalb kann man es nach jeder Änderung laufen
lassen.

**Mit WordPress** prüft den Weg durch das echte System: HTTP, Datenbank, Tick-Kette, Anzeige.
Das findet die Dinge, die in Attrappen nie auffallen.

---

## Ohne WordPress

```bash
bin/test.sh                 # alle
bin/test.sh schedule        # nur passende Namen
```

Oder vom Projekt-Root aus über alle Module hinweg:

```bash
bun run test                # alle Module
bun run test rh-sync        # nur eins
```

Jede Datei unter `tests/` ist ein eigenständiges Skript mit eigenen Attrappen. Kein PHPUnit,
keine Testumgebung, die erst hochfahren müsste.

**Namenskonvention, an die sich der Runner hält:**

| Endung | Bedeutung |
|---|---|
| `*-test.php` | Läuft allein, ohne WordPress. Fährt der Runner. |
| `*-check.php` | Misst gegen eine echte Datenbank. Der Runner listet sie nur auf. |

Die `-check`-Dateien laufen so:

```bash
ddev wp eval-file rh-db-engine/tests/db-keyset-check.php
```

### Die Checks rund um die site-eigenen Einstellungen

Sie gehören zusammen und beantworten die Frage, an der am 2026-08-10 eine Kundeninstallation
hängengeblieben ist: überlebt das, was diese Website ausmacht, einen Import?

```bash
ddev wp eval-file rh-sync/tests/option-guard-sync-check.php     # der gute Fall
ddev wp eval-file rh-sync/tests/option-guard-failure-check.php  # der Schadensfall
ddev wp eval-file rh-sync/tests/export-exclusion-check.php      # was im Archiv steht
```

Der erste koppelt die Website mit sich selbst und setzt zwischen Download und Import
Marker. Ohne diesen Kniff sind Quelle und Ziel identisch, und ein grüner Lauf beweist
nichts. Der zweite zieht dem Schutz die Schattentabelle unter den Füssen weg und prüft,
dass der Lauf abbricht, ohne die Website anzufassen. Der dritte packt zwei Archive aus:
Transportware ohne Kopplung und Verlauf, Sicherungskopie vollständig.

Sie räumen hinter sich auf. Trotzdem gehören sie in eine Entwicklungsumgebung, nicht auf
eine Kundenseite: sie fahren einen echten Import.

---

## Mit WordPress

Alles über `wp rh sync`. Die eingebaute Hilfe zeigt zu jedem Befehl die Optionen:

```bash
wp help rh sync
wp help rh sync selftest
```

### Nachsehen, ändert nichts

```bash
wp rh sync status           # Überblick: Kopplungen, Läufe, Zustand der Termine
wp rh sync peers            # Die gekoppelten Websites (ohne Zugangsdaten)
wp rh sync jobs --all       # Laufende und vergangene Läufe
wp rh sync job <id>         # Ein Lauf im Detail, mit Termin-Bericht
wp rh sync schedule         # Welche Termine stehen, fehlen oder weichen ab
wp rh sync trace --lines=80 # Die letzten Zeilen aus dem Verlaufsprotokoll
wp rh sync doctor           # Umgebung, Grenzen, gesperrte Funktionen
```

`wp rh sync schedule` ist der Befehl, der den Befund von Entrümpel-König in einem Aufruf
sichtbar macht: geplante Beiträge ohne Termin.

### Eingreifen

```bash
wp rh sync schedule-repair --dry-run    # zeigt, was fehlt
wp rh sync schedule-repair --yes        # stellt es her
```

Das Reparieren läuft auch auf einer Produktionsseite, es stellt nur wieder her, was ohnehin
dastehen sollte. Überfällige Beiträge werden dabei nicht veröffentlicht, sondern gemeldet.

### Der komplette Durchlauf

```bash
wp rh sync selftest --insecure --yes
```

Koppelt die Website mit sich selbst, legt geplante Beiträge an, nimmt ihnen die Termine, fährt
einen echten Sync und prüft danach fünfzehn Dinge. Räumt am Ende hinter sich auf.

| Option | Wofür |
|---|---|
| `--insecure` | Selbstsigniertes Zertifikat akzeptieren (DDEV) |
| `--upcoming=<n>` | Wie viele Beiträge mit Termin in der Zukunft |
| `--overdue=<n>` | Wie viele überfällige |
| `--keep` | Testdaten stehen lassen, zum Nachsehen |

Gesynct werden nur die Inhalte, nicht die Einstellungen und nicht die Mediathek. Der Lauf
bleibt damit kurz und fasst nichts an, was er nicht braucht.

Wer die Bausteine einzeln braucht:

```bash
wp rh sync fixture create --upcoming=12 --overdue=2
wp rh sync fixture damage        # nimmt allen geplanten Beiträgen die Termine
wp rh sync schedule              # ansehen
wp rh sync fixture reset --yes   # wegräumen
```

---

## Die Schranke

`fixture` und `selftest` legen Daten an und verweigern deshalb auf einer Produktionsseite den
Dienst. Maßgeblich ist, was WordPress selbst über die Installation denkt:

```bash
wp eval 'echo wp_get_environment_type();'
```

Ohne Angabe ist das **production**, auch in einer lokalen Umgebung. Für DDEV gehört deshalb in
`.ddev/config.yaml`:

```yaml
web_environment:
    - WP_ENVIRONMENT_TYPE=development
```

Für einen einzelnen Aufruf reicht auch:

```bash
ddev exec bash -c "cd /var/www/html/wordpress && WP_ENVIRONMENT_TYPE=development wp rh sync selftest --insecure --yes"
```

---

## Was die Ebenen jeweils finden

Ein Beispiel aus der Praxis, weil es den Unterschied gut zeigt: der Selbsttest hat beim ersten
Lauf hunderte Zeilen `Table 'rhstg_options' doesn't exist` produziert. Der Schutz der
site-eigenen Einstellungen lief auch dann, wenn das Profil gar keine Einstellungen überträgt,
und schrieb ins Leere. Kein Test ohne WordPress hätte das je gesehen, weil dort keine echte
Tabelle fehlt.

Umgekehrt fängt die Ebene ohne WordPress die Rechenfehler: dass der Termin aus `post_date`
kommen muss und nicht aus `post_date_gmt`, dass eine Beitrags-ID als Zahl ins Argument-Array
gehört, dass ein zweiter Durchgang nichts doppelt anlegt. Solche Fälle im echten System
herzustellen wäre umständlich und langsam.
