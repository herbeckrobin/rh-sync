# 0002, Import schaltet atomar um statt live zu überschreiben

Status: angenommen, 2026-08-03
Betrifft: rh-db-engine (Importer, TableSwap), rh-sync (ImportJobAdvancer, LocalOptionGuard)

## Kontext

Am 2026-08-02 hat ein Push von lokal nach Produktion die Zielseite anderthalb Stunden
unbrauchbar gemacht. Sie war nicht selbst wiederherstellbar, die Reparatur ging nur von
Hand über ein PHP-Script per FTP.

Der Import lief 17 Sekunden und starb. Was er hinterliess: die Options-Tabelle mit dem
Stand der Quelle statt dem der Zielseite, ohne `siteurl`, ohne `active_plugins`, ohne
`{prefix}_user_roles`. WordPress lieferte auf allen URLs HTTP 500, obwohl die Datenbank
einwandfrei erreichbar war.

Vier Folgeprobleme hingen alle am selben Punkt:

1. Der Snapshot der site-eigenen Options lag im Job-Cursor, und der Job-Cursor ist eine
   Option. Die Rettungsleine lag in dem Boot, das gerade sinkt.
2. `{prefix}_user_roles` stand nicht auf der Schutzliste.
3. Beide Antriebe der Tick-Kette (WP-Cron und der Loopback auf admin-ajax.php) brauchen
   ein startfähiges WordPress. Macht der Import die Site kaputt, kann er sich weder
   fortsetzen noch aufräumen.
4. Die Quellseite meldete 45 Minuten "läuft", weil ihr eigener Watchdog den Zeitstempel
   weiterdrehte, nicht die Gegenseite.

Diese vier sind Symptome. Die Ursache ist, dass der Import nicht atomar war: er leerte die
Zieltabellen und befüllte sie neu, und zwischen diesen beiden Schritten war die Site kaputt.

## Entscheidung

Der Import schreibt nicht mehr in die Live-Tabellen, sondern legt seine Tabellen unter
einem eigenen Prefix an (`rhstg_`), arbeitet dort fertig und schaltet am Ende mit einem
einzigen `RENAME TABLE` um. Das ist in MySQL atomar.

Reihenfolge der Phasen: entpacken, übertragen, Schlüssel umschreiben, URLs umschreiben,
**umschalten**, Medien. Alles vor dem Umschalten passiert in Schattentabellen. Ein Abbruch
davor hinterlässt ein paar Tabellen unter `rhstg_`, die der nächste Lauf wegräumt, und
sonst nichts.

Die site-eigenen Options wandern per `rh-db-engine/before_table_swap` in die Schatten-
Options-Tabelle, **bevor** sie live geht. Damit fällt das nachträgliche Zurückschreiben weg
und mit ihm das Fenster, in dem die Zielseite die Adresse der Quelle trägt.

## Warum nicht anders

**Transaktion statt Tabellentausch.** Ein Import besteht aus DDL (`DROP`, `CREATE`), und
DDL committet in MySQL implizit. Eine umschliessende Transaktion gibt es hier nicht.

**Sicherungskopie plus Rollback als einziges Netz.** Genau das war der Stand, und es hat
nicht getragen: der Rollback greift nur bei einer gefangenen Ausnahme, nicht bei
Speicher-Abbruch, Zeitlimit oder hartem Abschuss durch den Webserver. Die Sicherungskopie
bleibt als zweites Netz, sie ist aber nicht mehr die erste Verteidigungslinie.

**Nur die Symptome fixen.** Snapshot in eine Datei, `user_roles` nachtragen, Notfall-Script
bauen: alles nötig, aber alles Pflaster auf einem Verfahren, das die Site planmässig durch
einen kaputten Zwischenzustand führt.

## Preis und Grenzen

- **Doppelter Tabellenplatz** während des Imports. Bei einer 35-MB-Datenbank belanglos, bei
  einer sehr grossen ein echter Punkt. Der Preflight kann den Platz auf dem MySQL-Server
  nicht messen, ein Scheitern daran bleibt aber folgenlos: die Live-Tabellen sind
  unangetastet.
- **Rechte.** `RENAME TABLE` braucht ALTER und DROP auf der alten, CREATE und INSERT auf der
  neuen Tabelle. Das wird nicht angenommen, sondern gemessen (Wegwerf-Tabelle anlegen,
  umbenennen, entfernen). Fällt die Probe durch, läuft der Import direkt wie bisher.
- **Tabellennamen.** Der Stage-Prefix ersetzt den Site-Prefix. Ist der Site-Prefix kürzer,
  wachsen die Namen und können die 64 Zeichen sprengen. Das steht erst in den
  CREATE-Anweisungen, nicht im Manifest, wird also mitten in der Übertragung erkannt: dann
  verwirft der Import die Schattentabellen und beginnt die Übertragung direkt von vorn.
- **Medien sind nicht atomar** und können es nicht sein. Sie laufen nach dem Umschalten.
  Ein Abbruch dort lässt eine funktionierende Site mit noch fehlenden Bildern zurück, nicht
  eine kaputte.
- **Views** über Tabellengrenzen hinweg sind bei WordPress unüblich und nicht abgedeckt.

## Nachtrag 2026-08-03, Fremdschlüssel

Der erste Produktivlauf blieb genau daran hängen:

```
Can't create table `…`.`rhstg_defender_quarantine` (errno: 121 "Duplicate key on write or update")
```

InnoDB verlangt Constraint-Namen **datenbankweit** eindeutig, nicht pro Tabelle. Beim Anlegen
der Schattentabelle wanderte nur der Tabellenname auf den Zwischen-Prefix, der Constraint-Name
blieb, und die Originaltabelle hielt ihn noch. Dazu zeigten die Verweise weiter auf die
Live-Tabellen statt auf die Schattentabellen.

**Entscheidung: Fremdschlüssel werden beim Anlegen abgetrennt und nach dem Umschalten gesetzt.**
Nicht: Constraint-Namen mit auf den Zwischen-Prefix umschreiben.

Gegen das Umschreiben sprechen drei Dinge:

1. **Der Name bliebe kleben.** Ein auf `rhstg_` umbenannter Constraint behält diesen Namen,
   wenn die Tabelle live geht. Der nächste Import erzeugt denselben Namen erneut und läuft in
   dieselbe Kollision. Ein Umbenennen beim Umschalten bräuchte `ALTER TABLE … RENAME CONSTRAINT`,
   das gibt es erst ab MySQL 8.0.28 und in MariaDB gar nicht.
2. **Das Umschalten würde komplizierter.** Ein `RENAME TABLE` über einen Satz Tabellen, die
   sich gegenseitig per Fremdschlüssel referenzieren, zwingt InnoDB zur Buchhaltung mitten in
   der Anweisung, auf die es beim atomaren Umschalten gerade ankommt. Ohne Fremdschlüssel in
   den Schattentabellen fällt das ersatzlos weg.
3. **Die Verweise stimmen von selbst.** Nachträglich gesetzt, zeigt jeder Fremdschlüssel auf
   die Live-Tabelle, ohne dass irgendetwas umgerechnet werden muss.

Der Preis: zwischen Umschalten und Setzen der Fremdschlüssel fehlen die Constraints kurz, und
wenn der Lauf dort stirbt, fehlen sie ganz. Beides ist folgenlos für den Betrieb, ein
Fremdschlüssel ist eine Integritätsregel und kein Inhalt. Deshalb bricht ein Fremdschlüssel,
der sich nicht setzen lässt, den Import auch nicht ab: er wird gezählt und über
`rh-db-engine/import_incomplete_constraints` gemeldet.

**Constraint-Namen werden bewusst NICHT normalisiert.** Der ursprüngliche Name wird
wiederhergestellt (nur ein führender Quell-Prefix wandert auf den Ziel-Prefix). Namen, die
den Prefix einer dritten Site tragen, sind Altlast aus einem früheren Import und bleiben,
wie sie sind: sie lassen sich nicht zuverlässig als solche erkennen, und ein Plugin, das
seinen Constraint beim Namen sucht, würde ein Umbenennen nicht überstehen. Gegen Kollisionen
hilft ohnehin nicht der Name, sondern dass die alte Tabelle vorher entfernt wurde. Bleibt der
Name dennoch belegt, greift ein abgeleiteter Ersatzname.

## Was bleibt für den Rückfallweg

Kann nicht umgeschaltet werden, ist der alte, gefährliche Weg aktiv. Nur dann:

- liegt der Options-Snapshot als Datei neben der Sicherungskopie,
- entsteht eine kurzlebige, kennwortgeschützte Notfall-Seite in `wp-content`, die die
  site-eigenen Options ohne WordPress zurückschreiben kann.

Beides verschwindet, sobald der Lauf endet. Auf dem normalen Weg entsteht die Notfall-Seite
gar nicht erst: das gefährliche Werkzeug existiert nur auf dem gefährlichen Pfad.

## Offen

Woran der Import am 2026-08-02 nach 17 Sekunden gestorben ist, ist weiterhin nicht belegt.
Das Verlaufsprotokoll (`wp-content/rh-blueprint-data/jobs/sync-trace.log`) beantwortet die
Frage beim nächsten Mal: es überlebt Speicher-Abbruch, Zeitlimit und Prozess-Abschuss und
hält pro Schritt den Speicherverbrauch fest.
