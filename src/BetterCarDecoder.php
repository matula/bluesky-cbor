<?php

namespace Matula\BlueskyCbor;

class BetterCarDecoder extends CarDecoder
{
    private function decodeHash(string $value): ?array
    {
        // Check if it starts with the hash identifier pattern
        if (str_starts_with(bin2hex($value), '1220')) {
            return [
                'type' => 'hash',
                'algorithm' => 'sha256',
                'value' => substr(bin2hex($value), 4)  // Skip the 1220 prefix
            ];
        }
        return null;
    }

    private function decodeBskyRecord(array $data): array
    {
        $record = [];

        // Look for Bluesky record identifiers
        foreach ($data as $key => $value) {
            if (is_string($value) && str_contains($value, '.bsky.')) {
                $record['record_type'] = trim($value, '.');
                continue;
            }

            // Handle hash values
            if (str_starts_with($key, 'value_at_') && is_string($value)) {
                if ($hash = $this->decodeHash($value)) {
                    $record['hashes'][] = $hash;
                    continue;
                }

                // Try to decode as UTF-8
                if (mb_check_encoding($value, 'UTF-8')) {
                    $record['fields'][] = [
                        'position' => substr($key, 9),  // Remove 'value_at_'
                        'value' => $value
                    ];
                } else {
                    // Store raw hex if not UTF-8
                    $record['fields'][] = [
                        'position' => substr($key, 9),
                        'value' => bin2hex($value)
                    ];
                }
            }
        }

        return $record;
    }

    private function reassembleBlock(array $block): array
    {
        // Handle root block
        if (isset($block['roots'])) {
            return [
                'type' => 'root',
                'roots' => $block['roots'],
                'version' => $block['version']
            ];
        }

        // Handle blocks with just a hash
        if (count($block) === 1 && isset($block['value_at_2'])) {
            if ($hash = $this->decodeHash($block['value_at_2'])) {
                return [
                    'type' => 'hash_block',
                    'hash' => $hash
                ];
            }
        }

        // Handle Bluesky record blocks
        return [
            'type' => 'record_block',
            'content' => $this->decodeBskyRecord($block)
        ];
    }

    public function decode(string $data, bool $debug = false): array
    {
        $rawBlocks = parent::decode($data, $debug);
        $decodedBlocks = [];

        foreach ($rawBlocks as $index => $block) {
            $decodedBlocks[$index] = $this->reassembleBlock($block);

            if ($debug) {
                dump("Block $index:", $decodedBlocks[$index]);
            }
        }

        return $decodedBlocks;
    }
}