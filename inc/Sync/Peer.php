<?php

declare(strict_types=1);

namespace RhSync\Sync;

final class Peer
{
    /**
     * @param array{direction: string, timestamp: int, status: string, bytes: int}|null $lastSync
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url,
        public readonly string $token,
        public readonly ?array $lastSync,
        public readonly int $createdAt,
        public readonly SyncProfile $profile,
        public readonly SyncPermissions $permissions,
    ) {
    }

    public static function create(string $name, string $url, ?string $token = null, ?string $id = null): self
    {
        return new self(
            id: $id !== null && $id !== '' ? $id : wp_generate_uuid4(),
            name: $name,
            url: untrailingslashit(trim($url)),
            token: $token !== null && $token !== '' ? $token : self::generateToken(),
            lastSync: null,
            createdAt: time(),
            profile: SyncProfile::defaults(),
            permissions: SyncPermissions::defaults(),
        );
    }

    /**
     * Erzeugt einen Pairing-Code, den man auf der Gegenseite im "Code eingeben"-Dialog
     * einfügt. Enthält die geteilte UUID + das Token, base64-kodiert.
     *
     * WICHTIG: Name und URL im Code sind die der EIGENEN Site (home_url + eigener
     * Site-Name), NICHT die des Peer-Objekts (das die Gegenseite beschreibt). So legt
     * die Gegenseite einen Peer an, der zurück auf mich zeigt, und beide Seiten können
     * hin und her synchronisieren.
     *
     * SICHERHEIT: Der Code enthält das Token im Klartext. Darf nur über sichere Kanaele
     * weitergegeben werden (1Password, verschlüsselte Messenger, direkt am Geraet).
     */
    public function makePairingCode(): string
    {
        $payload = [
            'v' => 1,
            'id' => $this->id,
            'token' => $this->token,
            'name' => self::ownSiteName(),
            'url' => home_url(),
        ];

        return base64_encode((string) wp_json_encode($payload));
    }

    /**
     * Default-Name, unter dem die Gegenseite diese Site sieht: der Host der eigenen
     * Domain (technisch eindeutig, auf der Gegenseite frei umbenennbar).
     */
    private static function ownSiteName(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return $host !== '' ? $host : (string) get_bloginfo('name');
    }

    /**
     * @return array{v: int, id: string, token: string, name: string, url: string}|null
     */
    public static function decodePairingCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $decoded = base64_decode($code, true);
        if ($decoded === false) {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }

        $id = isset($data['id']) ? (string) $data['id'] : '';
        $token = isset($data['token']) ? (string) $data['token'] : '';

        if ($id === '' || $token === '') {
            return null;
        }

        return [
            'v' => isset($data['v']) ? (int) $data['v'] : 1,
            'id' => $id,
            'token' => $token,
            'name' => isset($data['name']) ? (string) $data['name'] : '',
            'url' => isset($data['url']) ? (string) $data['url'] : '',
        ];
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{direction: string, timestamp: int, status: string, bytes: int}|null $lastSync */
        $lastSync = isset($data['last_sync']) && is_array($data['last_sync']) ? $data['last_sync'] : null;

        /** @var array<string, mixed> $profileData */
        $profileData = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : [];

        /** @var array<string, mixed> $permissionsData */
        $permissionsData = isset($data['permissions']) && is_array($data['permissions']) ? $data['permissions'] : [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            token: (string) ($data['token'] ?? ''),
            lastSync: $lastSync,
            createdAt: (int) ($data['created_at'] ?? 0),
            profile: SyncProfile::fromArray($profileData),
            permissions: SyncPermissions::fromArray($permissionsData),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'token' => $this->token,
            'last_sync' => $this->lastSync,
            'created_at' => $this->createdAt,
            'profile' => $this->profile->toArray(),
            'permissions' => $this->permissions->toArray(),
        ];
    }

    public function withLastSync(string $direction, string $status, int $bytes): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            url: $this->url,
            token: $this->token,
            lastSync: [
                'direction' => $direction,
                'status' => $status,
                'bytes' => $bytes,
                'timestamp' => time(),
            ],
            createdAt: $this->createdAt,
            profile: $this->profile,
            permissions: $this->permissions,
        );
    }

    public function withToken(string $token): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            url: $this->url,
            token: $token,
            lastSync: $this->lastSync,
            createdAt: $this->createdAt,
            profile: $this->profile,
            permissions: $this->permissions,
        );
    }

    public function withProfile(SyncProfile $profile): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            url: $this->url,
            token: $this->token,
            lastSync: $this->lastSync,
            createdAt: $this->createdAt,
            profile: $profile,
            permissions: $this->permissions,
        );
    }

    public function withPermissions(SyncPermissions $permissions): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            url: $this->url,
            token: $this->token,
            lastSync: $this->lastSync,
            createdAt: $this->createdAt,
            profile: $this->profile,
            permissions: $permissions,
        );
    }

    public function maskedToken(): string
    {
        if (strlen($this->token) < 12) {
            return str_repeat('*', 8);
        }

        return substr($this->token, 0, 4) . str_repeat('•', 24) . substr($this->token, -4);
    }
}
