<?php

namespace Matula\BlueskyCbor;

class CarBlockReassembler extends CarV2Decoder
{
    public function decode(string $data, bool $debug = false): array
    {
        return parent::decode($data, $debug);
    }

    public function decodeCarBlocks(string $data, bool $debug = false): array
    {
        $this->debug = $debug;

        // First decode using parent method
        $rawBlocks = parent::decode($data, $debug);

        if ($this->debug) {
            dump("Raw blocks:", $rawBlocks);
        }

        $reassembled = [];

        foreach ($rawBlocks as $key => $value) {
            // Skip numeric keys that aren't actually blocks
            if (is_numeric($key) && !isset($value['cid'])) {
                continue;
            }

            // Handle CID blocks
            if (is_string($key) && strlen($key) > 32) { // CIDs are typically long hex strings
                $reassembled[$key] = $this->reassembleBlock($value);
            }
        }

        if ($this->debug) {
            dump("Reassembled blocks:", $reassembled);
        }

        return $reassembled;
    }

    private function reassembleBlock(array $fragmentedBlock): array
    {
        $reassembled = [
            'cid' => $fragmentedBlock['cid'] ?? null,
        ];

        // Extract record type if present
        foreach ($fragmentedBlock as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'app.bsky.')) {
                $reassembled['type'] = $value;
                break;
            }
        }

        // Collect all values
        $record = [];
        foreach ($fragmentedBlock as $key => $value) {
            // Skip some internal keys
            if ($key === 'cid' || $key === 'type') {
                continue;
            }

            if (str_starts_with($key, 'value_at_')) {
                if (is_string($value) && !mb_check_encoding($value, 'ASCII')) {
                    // It's binary data, keep it as hex
                    $record[$key] = bin2hex($value);
                } else {
                    $record[$key] = $value;
                }
            } else {
                $record[$key] = $value;
            }
        }

        $reassembled['record'] = $record;

        return $reassembled;
    }
}