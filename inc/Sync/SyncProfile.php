<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Sync-Profil pro Peer: Welche Bereiche werden übertragen?
 *
 * Acht boolesche Flags entscheiden, was beim Pull/Push übernommen wird.
 * Standardmäßig sind alle Flags `true` (Full-Sync).
 *
 * Die Mapping-Logik in `tableInGroup()` ordnet jede WordPress-Core-Tabelle
 * einer Gruppe zu. Tabellen die nicht zu einer Core-Gruppe gehören,
 * landen in der `customTables`-Gruppe (Plugins mit eigenen Tabellen).
 */
final class SyncProfile
{
    public function __construct(
        public readonly bool $content,       // wp_posts, wp_postmeta
        public readonly bool $taxonomies,    // wp_terms, wp_termmeta, wp_term_taxonomy, wp_term_relationships
        public readonly bool $comments,      // wp_comments, wp_commentmeta
        public readonly bool $users,         // wp_users, wp_usermeta
        public readonly bool $options,       // wp_options
        public readonly bool $links,         // wp_links
        public readonly bool $customTables,  // alles andere mit Prefix
        public readonly bool $uploads,       // wp-content/uploads/
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            content: true,
            taxonomies: true,
            comments: true,
            users: true,
            options: true,
            links: true,
            customTables: true,
            uploads: true,
        );
    }

    /**
     * Erzeugt ein Profile aus einem Array. Fehlende Keys werden als `true`
     * interpretiert, wichtig für Backwards-Compat bei existierenden Peers
     * ohne `profile`-Eintrag.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: self::boolFlag($data, 'content'),
            taxonomies: self::boolFlag($data, 'taxonomies'),
            comments: self::boolFlag($data, 'comments'),
            users: self::boolFlag($data, 'users'),
            options: self::boolFlag($data, 'options'),
            links: self::boolFlag($data, 'links'),
            customTables: self::boolFlag($data, 'customTables'),
            uploads: self::boolFlag($data, 'uploads'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function boolFlag(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data)) {
            return true;
        }
        return (bool) $data[$key];
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'taxonomies' => $this->taxonomies,
            'comments' => $this->comments,
            'users' => $this->users,
            'options' => $this->options,
            'links' => $this->links,
            'customTables' => $this->customTables,
            'uploads' => $this->uploads,
        ];
    }

    public function isFullSync(): bool
    {
        return $this->content
            && $this->taxonomies
            && $this->comments
            && $this->users
            && $this->options
            && $this->links
            && $this->customTables
            && $this->uploads;
    }

    public function isEmpty(): bool
    {
        return !$this->content
            && !$this->taxonomies
            && !$this->comments
            && !$this->users
            && !$this->options
            && !$this->links
            && !$this->customTables
            && !$this->uploads;
    }

    public function activeCount(): int
    {
        return (int) $this->content
            + (int) $this->taxonomies
            + (int) $this->comments
            + (int) $this->users
            + (int) $this->options
            + (int) $this->links
            + (int) $this->customTables
            + (int) $this->uploads;
    }

    /**
     * Aktive Gruppen-IDs (z.B. für UI-Anzeige).
     *
     * @return array<int, string>
     */
    public function activeGroups(): array
    {
        $groups = [];
        if ($this->content) {
            $groups[] = 'content';
        }
        if ($this->taxonomies) {
            $groups[] = 'taxonomies';
        }
        if ($this->comments) {
            $groups[] = 'comments';
        }
        if ($this->users) {
            $groups[] = 'users';
        }
        if ($this->options) {
            $groups[] = 'options';
        }
        if ($this->links) {
            $groups[] = 'links';
        }
        if ($this->customTables) {
            $groups[] = 'customTables';
        }
        if ($this->uploads) {
            $groups[] = 'uploads';
        }
        return $groups;
    }

    /**
     * Mappt einen Tabellennamen auf eine Gruppe.
     * Gibt die Gruppe zurück wenn aktiv, sonst `null`.
     *
     * $table:  z.B. "wp_posts" (vollqualifiziert mit Prefix)
     * $prefix: z.B. "wp_" (Ziel-Prefix)
     */
    public function tableInGroup(string $table, string $prefix): ?string
    {
        // Tabellen ohne den erwarteten Prefix gehören nicht zu dieser Instanz
        if (!str_starts_with($table, $prefix)) {
            return null;
        }

        $suffix = substr($table, strlen($prefix));

        $group = match (true) {
            $suffix === 'posts' || $suffix === 'postmeta' => 'content',
            $suffix === 'terms' || $suffix === 'termmeta' || $suffix === 'term_taxonomy' || $suffix === 'term_relationships' => 'taxonomies',
            $suffix === 'comments' || $suffix === 'commentmeta' => 'comments',
            $suffix === 'users' || $suffix === 'usermeta' => 'users',
            $suffix === 'options' => 'options',
            $suffix === 'links' => 'links',
            default => 'customTables',
        };

        $active = match ($group) {
            'content' => $this->content,
            'taxonomies' => $this->taxonomies,
            'comments' => $this->comments,
            'users' => $this->users,
            'options' => $this->options,
            'links' => $this->links,
            'customTables' => $this->customTables,
        };

        return $active ? $group : null;
    }

    /**
     * Liefert ein generisches Table-Predicate für den Import-Filter.
     *
     * Das entkoppelt den Importer von SyncProfile: er bekommt nur eine
     * Closure `fn(string $table): bool` und muss das Sync-Konzept nicht kennen.
     *
     * @return callable(string): bool  fn(vollqualifizierter Tabellenname): bool
     */
    public function tableFilter(string $targetPrefix): callable
    {
        return fn (string $table): bool => $this->tableInGroup($table, $targetPrefix) !== null;
    }

    /**
     * Labels für UI-Anzeige.
     *
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'content' => __('Inhalte', 'rh-sync'),
            'taxonomies' => __('Taxonomien', 'rh-sync'),
            'comments' => __('Kommentare', 'rh-sync'),
            'users' => __('Benutzer', 'rh-sync'),
            'options' => __('Einstellungen', 'rh-sync'),
            'links' => __('Links', 'rh-sync'),
            'customTables' => __('Custom-Tabellen', 'rh-sync'),
            'uploads' => __('Mediathek-Dateien', 'rh-sync'),
        ];
    }

    /**
     * Kurze Beschreibung pro Gruppe (z.B. für Tooltip-Hilfe).
     *
     * @return array<string, string>
     */
    public static function groupDescriptions(): array
    {
        return [
            'content' => __('Beiträge, Seiten, Custom Post Types und ihre Metadaten', 'rh-sync'),
            'taxonomies' => __('Kategorien, Tags und Custom Taxonomien', 'rh-sync'),
            'comments' => __('Kommentare und Kommentar-Metadaten', 'rh-sync'),
            'users' => __('Benutzerkonten, Rollen und Profile', 'rh-sync'),
            'options' => __('WordPress-, Plugin- und Theme-Einstellungen (kritische werden geschuetzt)', 'rh-sync'),
            'links' => __('WordPress-Links (Blogroll, selten genutzt)', 'rh-sync'),
            'customTables' => __('Eigene Tabellen von Plugins (ACF, WooCommerce-Custom-Order-Tables, etc.)', 'rh-sync'),
            'uploads' => __('Bilder und Dateien aus dem Medienpool', 'rh-sync'),
        ];
    }
}
