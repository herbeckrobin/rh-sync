<?php

declare(strict_types=1);

namespace RhSync\Sync;

final class PeerRegistry
{
    public const OPTION_NAME = 'rhbp_peers';

    /**
     * @return array<int, Peer>
     */
    public function all(): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) get_option(self::OPTION_NAME, []);

        $peers = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $peer = Peer::fromArray($entry);
            if ($peer->id !== '') {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    public function get(string $id): ?Peer
    {
        foreach ($this->all() as $peer) {
            if ($peer->id === $id) {
                return $peer;
            }
        }

        return null;
    }

    public function getByName(string $name): ?Peer
    {
        foreach ($this->all() as $peer) {
            if ($peer->name === $name) {
                return $peer;
            }
        }

        return null;
    }

    public function add(Peer $peer): void
    {
        $peers = $this->all();
        $peers[] = $peer;
        $this->save($peers);
    }

    public function update(Peer $peer): void
    {
        $peers = $this->all();
        $replaced = false;

        foreach ($peers as $index => $existing) {
            if ($existing->id === $peer->id) {
                $peers[$index] = $peer;
                $replaced = true;
                break;
            }
        }

        if (!$replaced) {
            $peers[] = $peer;
        }

        $this->save($peers);
    }

    public function remove(string $id): void
    {
        $peers = array_values(array_filter(
            $this->all(),
            static fn (Peer $p): bool => $p->id !== $id
        ));
        $this->save($peers);
    }

    /**
     * @param array<int, Peer> $peers
     */
    private function save(array $peers): void
    {
        $data = array_map(static fn (Peer $p): array => $p->toArray(), $peers);
        update_option(self::OPTION_NAME, $data);
    }
}
