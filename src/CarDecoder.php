<?php

namespace Matula\BlueskyCbor;

class CarDecoder
{
    private string $data;
    private int $position = 0;
    private bool $debug;

    public function __construct(protected NativeCborDecoder $cborDecoder)
    {
    }

    public function decode(string $data, bool $debug = false): array
    {
        $this->data = $data;
        $this->position = 0;
        $this->debug = $debug;

        $blocks = [];

        while ($this->position < strlen($this->data)) {
            try {
                // Read block length as varint
                $blockLength = $this->readVarint();
                if ($blockLength === 0) {
                    break;
                }

                if ($this->debug) {
                    dump("Found block of length: $blockLength at position: {$this->position}");
                }

                // Extract the block data
                $blockData = substr($this->data, $this->position, $blockLength);
                $this->position += $blockLength;

                // Decode the block data as CBOR
                $decoded = $this->cborDecoder->decode($blockData);

                if ($this->debug) {
                    dump("Decoded block:", $decoded);
                }

                // Add to blocks array
                $blocks[] = $decoded;

            } catch (\Exception $e) {
                if ($this->debug) {
                    dump("Error at position {$this->position}: " . $e->getMessage());
                    dump("Next bytes: " . bin2hex(substr($this->data, $this->position, 20)));
                }
                // Skip to next byte and try again
                $this->position++;
            }
        }

        return $blocks;
    }

    private function readVarint(): int
    {
        $value = 0;
        $shift = 0;

        while ($this->position < strlen($this->data)) {
            $byte = ord($this->data[$this->position++]);
            $value |= ($byte & 0x7F) << $shift;

            // If highest bit is not set, this is the last byte of the varint
            if (($byte & 0x80) === 0) {
                break;
            }

            $shift += 7;
            if ($shift >= 64) {
                throw new Exceptions\CborDecodingException("Invalid varint: too long");
            }
        }

        return $value;
    }
}