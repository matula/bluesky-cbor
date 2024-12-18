<?php

namespace Matula\BlueskyCbor;

use Matula\BlueskyCbor\Exceptions\CborDecodingException;

class CarV2Decoder extends NativeCborDecoder
{
    protected function decodeSpecial(int $additionalInfo): mixed
    {
        return match($additionalInfo) {
            20 => false,
            21 => true,
            22 => null,
            23 => 'undefined',
            24 => $this->decodeSimpleValue(),
            25 => $this->decodeFloat16(),
            26 => $this->decodeFloat32(),
            27 => $this->decodeFloat64(),
            31 => 'break',
            default => parent::decodeSpecial($additionalInfo)
        };
    }

    private function decodeSimpleValue(): int
    {
        return $this->bytes[$this->position++];
    }

    private function decodeFloat16(): float
    {
        // Get the 2 bytes for the half-float
        $half = ($this->bytes[$this->position] << 8) | $this->bytes[$this->position + 1];
        $this->position += 2;

        // Extract the parts of the half-float
        $sign = ($half & 0x8000) >> 15;
        $exp = ($half & 0x7C00) >> 10;
        $mant = $half & 0x03FF;

        if ($exp === 0) {
            $val = $mant * 2 ** -24;
        } elseif ($exp === 0x1F) {
            $val = $mant ? NAN : INF;
        } else {
            $val = ($mant + 1024) * 2 ** ($exp - 25);
        }

        return $sign ? -$val : $val;
    }

    private function decodeFloat32(): float
    {
        $value = unpack('G', pack('C*',
            $this->bytes[$this->position + 3],
            $this->bytes[$this->position + 2],
            $this->bytes[$this->position + 1],
            $this->bytes[$this->position]
        ))[1];
        $this->position += 4;
        return $value;
    }

    private function decodeFloat64(): float
    {
        $value = unpack('E', pack('C*',
            $this->bytes[$this->position + 7],
            $this->bytes[$this->position + 6],
            $this->bytes[$this->position + 5],
            $this->bytes[$this->position + 4],
            $this->bytes[$this->position + 3],
            $this->bytes[$this->position + 2],
            $this->bytes[$this->position + 1],
            $this->bytes[$this->position]
        ))[1];
        $this->position += 8;
        return $value;
    }

    protected function decodeTag(int $additionalInfo): mixed
    {
        $tag = $this->decodeUnsigned($additionalInfo);

        if ($this->debug) {
            dump("Decoding tag $tag at position {$this->position}");
        }

        $value = $this->decodeNext();

        // Handle special tags
        return match($tag) {
            // CID tag (42)
            42 => ['cid' => $this->formatCID($value)],
            // Handle other known tags
            0 => ['date' => $value], // Standard date/time string
            1 => ['timestamp' => $value], // Epoch timestamp
            // You can add more tag handlers here
            default => ['tag' => $tag, 'value' => $value]
        };
    }

    private function formatCID(string $bytes): string
    {
        // Remove potential CID prefix (0x00)
        if (ord($bytes[0]) === 0x00) {
            $bytes = substr($bytes, 1);
        }

        // Convert to hex
        return bin2hex($bytes);
    }

    public function decodeCarBlocks(string $data): array
    {
        $blocks = [];
        $pos = 0;
        $length = strlen($data);

        while ($pos < $length) {
            try {
                // Read section length if present (CAR v2 format)
                $sectionLength = $this->readVarint(substr($data, $pos));
                $pos += $this->getVarintSize($sectionLength);

                if ($sectionLength === 0) {
                    break;
                }

                // Decode the section
                $section = substr($data, $pos, $sectionLength);
                $decoded = $this->decode($section, $this->debug);

                if (isset($decoded['cid'])) {
                    $blocks[$decoded['cid']] = $decoded;
                } else {
                    $blocks[] = $decoded;
                }

                $pos += $sectionLength;

            } catch (\Exception $e) {
                if ($this->debug) {
                    dump("Error at position $pos: " . $e->getMessage());
                    dump("Bytes: " . bin2hex(substr($data, $pos, 20)));
                }
                $pos++; // Skip problematic byte
            }
        }

        return $blocks;
    }

    private function readVarint(string $data): int
    {
        $value = 0;
        $shift = 0;
        $pos = 0;

        while (true) {
            if ($pos >= strlen($data)) {
                throw new CborDecodingException("Incomplete varint");
            }

            $byte = ord($data[$pos++]);
            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                break;
            }

            $shift += 7;
            if ($shift > 63) {
                throw new CborDecodingException("Varint too large");
            }
        }

        return $value;
    }

    private function getVarintSize(int $value): int
    {
        $size = 0;
        do {
            $value >>= 7;
            $size++;
        } while ($value > 0);
        return $size;
    }
}