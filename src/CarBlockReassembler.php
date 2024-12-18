<?php

namespace Matula\BlueskyCbor;

class CarBlockReassembler extends CarV2Decoder
{
    protected function decodeNextComplete()
    {
        $currentBlock = [];
        $currentMeta = null;

        while ($this->position < count($this->bytes)) {
            $startPos = $this->position;

            try {
                $byte = $this->bytes[$this->position];
                $majorType = $byte >> 5;
                $additionalInfo = $byte & 0x1f;

                $this->position++;
                $value = $this->decodeNext();

                // If we see a CID, start a new block
                if (is_array($value) && isset($value['cid'])) {
                    if (!empty($currentBlock)) {
                        yield $this->finalizeBlock($currentBlock, $currentMeta);
                    }
                    $currentMeta = $value['cid'];
                    $currentBlock = [];
                    continue;
                }

                // Handle text fragments that might be part of a record
                if (is_string($value) && str_starts_with($value, 'app.bsky.')) {
                    $currentBlock['type'] = $value;
                    continue;
                }

                // Handle record data
                if (is_array($value) && isset($value['text'])) {
                    $currentBlock['record'] = $value;
                    continue;
                }

                // Store other values that might be part of the current block
                $currentBlock[] = $value;

            } catch (\Exception $e) {
                if ($this->debug) {
                    dump("Error at position $startPos: " . $e->getMessage());
                }
                $this->position++;
            }
        }

        // Don't forget the last block
        if (!empty($currentBlock)) {
            yield $this->finalizeBlock($currentBlock, $currentMeta);
        }
    }

    protected function finalizeBlock(array $block, ?string $cid): array
    {
        // Try to identify the block type
        if (isset($block['type']) && str_starts_with($block['type'], 'app.bsky.')) {
            return [
                'cid' => $cid,
                'type' => $block['type'],
                'record' => $block['record'] ?? null,
                'raw' => $block
            ];
        }

        // Handle root blocks differently
        if (isset($block['roots'])) {
            return [
                'type' => 'car_root',
                'version' => $block['version'] ?? 1,
                'roots' => $block['roots']
            ];
        }

        // Default structure for unknown blocks
        return [
            'cid' => $cid,
            'data' => $block
        ];
    }

    public function reassembleBlocks(string $data): array
    {
        $blocks = parent::decode($data);
        $reassembled = [];

        foreach ($blocks as $key => $value) {
            // Handle root block
            if ($key === 0 && isset($value['roots'])) {
                $reassembled['root'] = $value;
                continue;
            }

            // Handle CID blocks
            if (is_string($key) && strlen($key) === 64) { // CID length
                $reassembled['blocks'][$key] = $this->reassembleBlock($value);
            }
        }

        return $reassembled;
    }

    private function reassembleBlock(array $fragmentedBlock): array
    {
        $reassembled = [
            'cid' => $fragmentedBlock['cid'] ?? null,
        ];

        // Try to identify the record type
        foreach ($fragmentedBlock as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'app.bsky.')) {
                $reassembled['type'] = $value;
                break;
            }
        }

        // Collect record data
        $record = [];
        foreach ($fragmentedBlock as $key => $value) {
            if (str_starts_with($key, 'value_at_')) {
                // Try to decode the binary data if possible
                if (is_string($value) && strlen($value) > 0) {
                    try {
                        $decoded = @json_decode($value, true);
                        if ($decoded) {
                            $record[$key] = $decoded;
                        } else {
                            $record[$key] = bin2hex($value);
                        }
                    } catch (\Exception $e) {
                        $record[$key] = bin2hex($value);
                    }
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