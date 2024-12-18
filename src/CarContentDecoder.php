<?php

namespace Matula\BlueskyCbor;

class CarContentDecoder extends CarDecoder
{
    private function reassembleBlock(array $block): array
    {
        // If it's a root block, return as is
        if (isset($block['roots'])) {
            return [
                'type' => 'root',
                'data' => $block
            ];
        }

        $content = [];

        // Try to identify the type of block
        foreach ($block as $key => $value) {
            // Skip binary headers we don't need
            if ($key === 'value_at_2' && is_string($value) && str_starts_with($value, "\x12 ")) {
                continue;
            }

            if (is_string($value)) {
                // Look for Bluesky namespaces
                if (str_contains($value, '.bsky.feed.')) {
                    $content['type'] = trim($value, ".");
                }
                // Look for record IDs
                else if (str_contains($value, '/')) {
                    $parts = explode('/', $value);
                    if (count($parts) === 2) {
                        $content['record_type'] = $parts[0];
                        $content['record_id'] = $parts[1];
                    }
                }
            }

            // Store CID references
            if (is_array($value) && isset($value['cid'])) {
                $content['refs'][] = $value['cid'];
            }

            // Store other metadata
            if (in_array($key, ['k', 'p', 'cid'])) {
                $content['meta'][$key] = $value;
            }
        }

        // Try to decode any remaining binary data
        foreach ($block as $key => $value) {
            if (str_starts_with($key, 'value_at_') && is_string($value)) {
                try {
                    // Try to decode as UTF-8 first
                    if (mb_check_encoding($value, 'UTF-8')) {
                        $content['data'][$key] = $value;
                    } else {
                        // Store as hex if not valid UTF-8
                        $content['data'][$key] = bin2hex($value);
                    }
                } catch (\Exception $e) {
                    $content['data'][$key] = bin2hex($value);
                }
            }
        }

        return $content;
    }

    public function decode(string $data, bool $debug = false): array
    {
        $rawBlocks = parent::decode($data, $debug);
        $decodedBlocks = [];

        foreach ($rawBlocks as $index => $block) {
            $decodedBlocks[$index] = $this->reassembleBlock($block);

            if ($debug) {
                dump("Block $index decoded:", $decodedBlocks[$index]);
            }
        }

        return $decodedBlocks;
    }
}