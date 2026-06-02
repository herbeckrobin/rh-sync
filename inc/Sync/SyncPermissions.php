<?php

declare(strict_types=1);

namespace RhSync\Sync;

use RhBlueprint\Core\Environment;

/**
 * Richtungs-Permissions pro Peer. Orthogonal zum SyncProfile (das den Daten-Scope
 * bestimmt, also WELCHE Tabellen). Hier geht es um OB eine Richtung erlaubt ist.
 *
 * Zwei Perspektiven, beide am selben Peer-Eintrag:
 *
 *   Outbound (was ICH gegenüber diesem Peer initiieren darf):
 *     - allowPullFrom: ich darf von diesem Peer ziehen
 *     - allowPushTo:   ich darf zu diesem Peer schieben
 *     Reine UI/UX-Sicherung (verhindert Versehen). Kein echter Schutz.
 *
 *   Inbound (was dieser Peer bei MIR auslösen darf, server-enforced im SyncController):
 *     - allowInboundExport: der Peer darf von mir exportieren (= ich bin Pull-Quelle)
 *     - allowInboundImport: der Peer darf in mich importieren (= ich bin Push-Ziel)
 *     Die echte Mauer: der REST-Endpoint lehnt mit 403 ab, wenn nicht erlaubt.
 *
 * Defaults kommen aus dem WP-Environment: eine Produktiv-Site ist von sich aus
 * KEIN Sync-Ziel und keine Sync-Quelle (Inbound dicht), muss bewusst geöffnet
 * werden. Outbound bleibt offen (was ich selbst tue, entscheide ich pro Peer).
 */
final class SyncPermissions
{
    public function __construct(
        public readonly bool $allowPullFrom,
        public readonly bool $allowPushTo,
        public readonly bool $allowInboundExport,
        public readonly bool $allowInboundImport,
    ) {
    }

    public static function defaults(): self
    {
        // Inbound-Default aus dem Environment der EIGENEN Site.
        $inboundOpen = ! Environment::isProduction();

        return new self(
            allowPullFrom: true,
            allowPushTo: true,
            allowInboundExport: $inboundOpen,
            allowInboundImport: $inboundOpen,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Bei fehlenden Keys (Alt-Peers ohne permissions) sichere Defaults aus dem Environment.
        $defaults = self::defaults();

        return new self(
            allowPullFrom: self::boolFlag($data, 'allowPullFrom', $defaults->allowPullFrom),
            allowPushTo: self::boolFlag($data, 'allowPushTo', $defaults->allowPushTo),
            allowInboundExport: self::boolFlag($data, 'allowInboundExport', $defaults->allowInboundExport),
            allowInboundImport: self::boolFlag($data, 'allowInboundImport', $defaults->allowInboundImport),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function boolFlag(array $data, string $key, bool $default): bool
    {
        return array_key_exists($key, $data) ? (bool) $data[$key] : $default;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'allowPullFrom' => $this->allowPullFrom,
            'allowPushTo' => $this->allowPushTo,
            'allowInboundExport' => $this->allowInboundExport,
            'allowInboundImport' => $this->allowInboundImport,
        ];
    }
}
