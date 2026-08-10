<?php

declare(strict_types=1);

namespace RhSync\Cli;

/**
 * Die Schranke vor den schreibenden Werkzeugen.
 *
 * Die Befehle unter `wp rh sync fixture` und `wp rh sync selftest` legen Beiträge an, löschen
 * Termine und fahren einen echten Sync gegen die eigene Website. Auf einer laufenden Kundenseite
 * hat das nichts zu suchen, und ein Tippfehler im falschen Verzeichnis reicht.
 *
 * Zwei Riegel, bewusst beide:
 *   - Die Umgebung muss laut WordPress ausdrücklich keine Produktion sein. Das ist die harte
 *     Grenze, sie lässt sich nicht mit einem Flag umgehen. Wer sie verschieben will, setzt
 *     WP_ENVIRONMENT_TYPE, und das ist eine bewusste Entscheidung an der Installation.
 *   - Eine Rückfrage, die mit --yes übersprungen werden kann. Das ist der Schutz gegen
 *     Vertippen, nicht gegen die falsche Website.
 *
 * Reparieren zählt NICHT dazu: `wp rh sync schedule repair` stellt nur wieder her, was ohnehin
 * dastehen sollte, und ist auf einer Produktionsseite genau das Werkzeug, das man braucht.
 */
final class Guard
{
    /**
     * Bricht ab, wenn WordPress diese Installation für eine Produktion hält.
     */
    public static function requireNonProduction(string $what): void
    {
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

        if ($env !== 'production') {
            return;
        }

        \WP_CLI::error(sprintf(
            /* translators: %s = Name des Befehls */
            'Diese Website gilt als Produktion. %s legt Testdaten an und verändert Termine, das läuft hier nicht. Wenn das falsch erkannt ist, setze WP_ENVIRONMENT_TYPE auf "staging" oder "development".',
            $what
        ));
    }

    /**
     * Rückfrage vor einem Eingriff. Mit --yes übersprungen.
     *
     * @param array<string, mixed> $assoc
     */
    public static function confirm(string $question, array $assoc): void
    {
        if (isset($assoc['yes'])) {
            return;
        }

        \WP_CLI::confirm($question);
    }

    /**
     * Die erkannte Umgebung, für die Ausgabe.
     */
    public static function environment(): string
    {
        return function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unbekannt';
    }
}
