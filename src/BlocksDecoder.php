<?php

namespace Matula\BlueskyCbor;

class BlocksDecoder extends NativeCborDecoder
{
    /**
     * Decode blocks which contain multiple CBOR-encoded entries
     */
    public function decodeBlocks(string $data, bool $debug = false): array
    {
        $this->bytes = unpack('C*', $data);
        $this->position = 1;
        $this->debug = $debug;

        if ($this->debug) {
            $hexData = array_map(fn($b) => sprintf('%02X', $b), array_values($this->bytes));
            dump([
                'total_bytes' => count($this->bytes),
                'first_50_bytes' => array_slice($hexData, 0, 50)
            ]);
        }

        $blocks = [];
        $currentBlock = [];
        $currentCid = null;

        while ($this->position < count($this->bytes)) {
            $startPos = $this->position;

            try {
                // Try to read a varint that might indicate block length
                $blockLength = $this->readVarint();
                if ($blockLength === 0) break;

                if ($this->debug) {
                    dump("Found block of length: $blockLength at position: $startPos");
                }

                // Read the CID
                $cidResult = $this->decodeNext();
                if (isset($cidResult['cid'])) {
                    $currentCid = $cidResult['cid'];
                }

                // Read the actual block data
                $blockData = $this->decodeNext();

                if ($currentCid && $blockData) {
                    $blocks[$currentCid] = $blockData;
                    $currentCid = null;
                }

            } catch (\Exception $e) {
                if ($this->debug) {
                    dump("Error processing block at position $startPos: " . $e->getMessage());
                    dump("Current bytes: " . bin2hex(substr($data, $startPos, 50)));
                }
                // Skip to next byte and try again
                $this->position++;
            }
        }

        return $blocks;
    }

    /**
     * Read a variable-length integer
     */
    private function readVarint(): int
    {
        $value = 0;
        $shift = 0;

        while ($this->position < count($this->bytes)) {
            $byte = $this->bytes[$this->position++];
            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                break;
            }

            $shift += 7;

            if ($shift > 63) {
                throw new Exceptions\CborDecodingException("Varint is too long");
            }
        }

        return $value;
    }

    /**
     * Override decodeBytes to handle potential CID prefixes
     */
    protected function decodeBytes(int $additionalInfo): string
    {
        $length = $this->decodeUnsigned($additionalInfo);
        if ($this->debug) dump("Decoding bytes of length $length");

        // Check for CID prefix
        if ($length > 0 && isset($this->bytes[$this->position]) && $this->bytes[$this->position] === 0x00) {
            $this->position++; // Skip prefix
            $length--;
        }

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr($this->bytes[$this->position++]);
        }
        return $bytes;
    }
}