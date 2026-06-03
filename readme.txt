=== RH Sync ===
Contributors: robinherbeck
Tags: sync, migration, staging, database, deployment
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Datenbank und Uploads zwischen zwei WordPress-Instanzen abgleichen. Verschlüsselt per HMAC, mit feinen Berechtigungen pro Gegenstelle.

== Description ==

RH Sync gleicht Inhalte zwischen zwei WordPress-Installationen ab, zum Beispiel zwischen lokaler Entwicklung, Staging und Produktion. Du koppelst zwei Instanzen einmal miteinander und ziehst (Pull) oder schiebst (Push) dann Datenbank und Uploads zwischen ihnen.

Die Kopplung läuft direkt zwischen deinen Seiten über die WordPress-REST-API. Es gibt keinen zentralen Server und keinen Drittanbieter dazwischen. Jede Anfrage wird per HMAC-SHA256 signiert, läuft ausschließlich über HTTPS und ist gegen Replay- und SSRF-Angriffe abgesichert.

= Was es kann =

* Zwei WordPress-Instanzen koppeln, über einen Pairing-Code oder per manueller Eingabe
* Pull und Push von Datenbank und Uploads zwischen den gekoppelten Seiten
* Sync-Profile: pro Gegenstelle festlegen, welche Daten übertragen werden (Inhalte, Taxonomien, Kommentare, Benutzer, Optionen, eigene Tabellen, Uploads)
* Berechtigungen pro Gegenstelle, getrennt für eingehend und ausgehend: was darf die Gegenstelle bei dir auslösen, was darfst du bei ihr
* Sichere Voreinstellung: auf Produktions-Umgebungen ist eingehender Zugriff standardmäßig zu

= Sicherheit =

* Jede Anfrage signiert per HMAC-SHA256, Verifizierung serverseitig
* HTTPS-Zwang auf allen Endpunkten der Gegenstelle
* Schutz gegen Replay-Angriffe und SSRF
* Eingehende Berechtigungen werden serverseitig erzwungen, nicht nur in der Oberfläche
* Jede Aktion im Backend ist durch Capability-Prüfung und Nonce abgesichert

= Teil der rh-blueprint Kollektion =

RH Sync gehört zu einer Familie kleiner, fokussierter Plugins von Robin Herbeck. Es läuft eigenständig und braucht kein weiteres Modul. Mehrere Plugins der Kollektion teilen sich dieselbe Oberfläche und dasselbe Einstellungs-System.

== Installation ==

1. Plugin auf beiden Seiten installieren, die du abgleichen willst.
2. Aktivieren.
3. Unter RH Blueprint -> Sync auf der ersten Seite eine Verbindung erzeugen, der Code enthält die Adresse der Seite.
4. Auf der zweiten Seite den Code eingeben, um die Kopplung abzuschließen.
5. Berechtigungen und Sync-Profil pro Gegenstelle einstellen, dann Pull oder Push starten.

== Frequently Asked Questions ==

= Brauche ich einen externen Dienst? =

Nein. RH Sync verbindet deine beiden Seiten direkt miteinander. Es gibt keinen zentralen Server und keinen Drittanbieter.

= Ist die Übertragung sicher? =

Ja. Jede Anfrage ist per HMAC-SHA256 signiert, läuft über HTTPS und ist gegen Replay- und SSRF-Angriffe abgesichert. Eingehende Rechte werden serverseitig erzwungen.

= Kann eine gekoppelte Seite einfach alles bei mir überschreiben? =

Nein. Du legst pro Gegenstelle getrennt fest, was eingehend und ausgehend erlaubt ist. Auf Produktions-Umgebungen ist eingehender Zugriff standardmäßig deaktiviert.

= Welche Daten werden übertragen? =

Das bestimmst du über das Sync-Profil pro Gegenstelle: Inhalte, Taxonomien, Kommentare, Benutzer, Optionen, eigene Tabellen und Uploads lassen sich einzeln zuschalten.

= Brauche ich RH Backup zusätzlich? =

Nein, RH Sync läuft eigenständig. RH Backup ist das Schwester-Plugin für lokale Sicherungen einer einzelnen Seite.

== Changelog ==

= 0.3.2 =
* Erste Veröffentlichung im WordPress-Plugin-Verzeichnis.
* Peer-to-Peer Sync von Datenbank und Uploads zwischen zwei WordPress-Instanzen.
* Kopplung über Pairing-Code oder manuelle Eingabe.
* Sync-Profile und getrennte Berechtigungen pro Gegenstelle (eingehend/ausgehend).
* Absicherung per HMAC-SHA256, HTTPS-Zwang sowie Replay- und SSRF-Schutz.
* Aufgeräumte Oberfläche im nativen WordPress-Stil.

== Upgrade Notice ==

= 0.3.2 =
Erste Version im WordPress-Plugin-Verzeichnis.
