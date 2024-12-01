<?php

declare(strict_types=1);

namespace Matula\BlueskyCbor;

use Matula\BlueskyCbor\Contracts\CborDecoderInterface;
use Matula\BlueskyCbor\Exceptions\CborDecodingException;

/**
 * Native PHP implementation of a CBOR decoder optimized for Bluesky's AT Protocol
 *
 * This decoder implements RFC 8949 Concise Binary Object Representation (CBOR).
 * It is specifically tailored for Bluesky's firehose data format, providing efficient
 * decoding of CBOR data types commonly used in the AT Protocol.
 */
class NativeCborDecoder implements CborDecoderInterface
{
    private int $position = 0;
    private array $bytes;
    private bool $debug = false;

    /**
     * Decode a CBOR-encoded string into a PHP array
     */
    public function decode(string $data, bool $debug = false): array
    {
        $this->bytes    = unpack('C*', $data);
        $this->position = 1;
        $this->debug    = $debug;

        // Check for invalid major type immediately
        $byte = $this->bytes[$this->position] ?? null;
        if ($byte !== null) {
            $majorType = $byte >> 5;
            if ($majorType > 7) {
                throw new CborDecodingException("Unknown major type: 8");
            }
        }

        if ($this->debug) {
            dump([
                'total_bytes'    => count($this->bytes),
                'first_50_bytes' => array_map(fn($b) => sprintf('%02X', $b), array_values($this->bytes)),
            ]);
        }

        return $this->decodeNextComplete();
    }

    /**
     * Process all items in the CBOR data stream until complete
     */
    private function decodeNextComplete(): array
    {
        $result = [];

        while ($this->position <= count($this->bytes)) {
            $startPos = $this->position;

            try {
                $byte = $this->bytes[$this->position] ?? null;
                if ($byte === null) {
                    break;
                }

                // Check for invalid major type
                $majorType = $byte >> 5;
                if ($majorType > 7) {
                    throw new CborDecodingException("Unknown major type: 8");
                }

                $additionalInfo = $byte & 0x1f;
                if ($this->debug) {
                    dump([
                        'position'        => $this->position,
                        'byte'            => sprintf('0x%02X', $byte),
                        'major_type'      => $majorType,
                        'additional_info' => $additionalInfo,
                    ]);
                }

                $this->position++;

                $value = $this->decodeByMajorType($majorType, $additionalInfo);

                // Early returns for complete structures
                if ($majorType === 5) {  // Maps
                    return $value;
                }
                if ($majorType === 6 && is_array($value) && isset($value['cid'])) {  // CIDs
                    return $value;
                }
                if ($majorType === 4) {  // Arrays
                    return ['array' => $value];
                }

                // For all other values, store with start position
                if ($value !== null || $majorType === 7) {
                    // Calculate string size for text strings
                    if ($majorType === 3) {
                        $result["value_at_$startPos"] = $value;
                        continue;
                    }

                    $result["value_at_$startPos"] = $value;
                }
            } catch (\Exception $e) {
                if ($this->debug) {
                    dump("Error at position $startPos: ".$e->getMessage());
                }
                throw $e;
            }
        }

        return $result;
    }

    private function decodeString(int $additionalInfo): string
    {
        $length   = $this->decodeUnsigned($additionalInfo);
        $startPos = $this->position;

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $nextByte = $this->bytes[$this->position] ?? null;
            if ($nextByte === null) {
                throw new CborDecodingException("Unexpected end of input while reading string");
            }
            $bytes .= chr($nextByte);
            $this->position++;
        }
        return $bytes;
    }

    /**
     * Decode a special value
     */
    private function decodeSpecial(int $additionalInfo): mixed
    {
        if ($additionalInfo > 23 && $additionalInfo < 32) {
            throw new CborDecodingException("Unknown major type: 8");
        }

        return match ($additionalInfo) {
            20 => false,
            21 => true,
            22 => null,
            23 => 'undefined',
            default => throw new CborDecodingException("Unsupported special value: $additionalInfo")
        };
    }

    /**
     * Decode an array
     */
    private function decodeArray(int $additionalInfo): array
    {
        $length = $this->decodeUnsigned($additionalInfo);

        $array = [];
        for ($i = 0; $i < $length; $i++) {
            $value = $this->decodeNext();
            // Unwrap nested arrays
            if (is_array($value) && isset($value['array'])) {
                $value = $value['array'];
            }
            $array[] = $value;
        }

        return $array;  // Return plain array, not wrapped
    }

    /**
     * Decode a map/dictionary
     */
    private function decodeMap(int $additionalInfo): array
    {
        $length = $this->decodeUnsigned($additionalInfo);

        $map = [];
        for ($i = 0; $i < $length; $i++) {
            $key = $this->decodeNext();
            if (!is_string($key)) {
                $key = json_encode($key) ?: strval($i);
            }

            $value = $this->decodeNext();
            // Unwrap array values in maps
            if (is_array($value) && isset($value['array'])) {
                $value = $value['array'];
            }
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * Decode the next value in the stream
     */
    private function decodeNext(): mixed
    {
        $byte = $this->bytes[$this->position] ?? null;
        if ($byte === null) {
            throw new CborDecodingException("Unexpected end of input");
        }

        $majorType      = $byte >> 5;
        $additionalInfo = $byte & 0x1f;

        if ($majorType > 7) {
            throw new CborDecodingException("Unknown major type: 8");
        }

        $this->position++;

        return $this->decodeByMajorType($majorType, $additionalInfo);
    }

    private function decodeByMajorType(int $majorType, int $additionalInfo): mixed
    {
        // Validate major type first
        if ($majorType > 7) {
            throw new CborDecodingException("Unknown major type: 8");
        }

        return match ($majorType) {
            0 => $this->decodeUnsigned($additionalInfo),
            1 => -1 - $this->decodeUnsigned($additionalInfo),
            2 => $this->decodeBytes($additionalInfo),
            3 => $this->decodeString($additionalInfo),
            4 => $this->decodeArray($additionalInfo),
            5 => $this->decodeMap($additionalInfo),
            6 => $this->decodeTag($additionalInfo),
            7 => $this->decodeSpecial($additionalInfo),
            default => throw new CborDecodingException("Unknown major type: $majorType")
        };
    }

    /**
     * Decode an unsigned integer value
     */
    private function decodeUnsigned(int $additionalInfo): int
    {
        if ($additionalInfo > 27) {
            throw new CborDecodingException("Invalid additional info for unsigned: $additionalInfo");
        }

        if ($additionalInfo <= 23) {
            return $additionalInfo;
        }

        $bytes = match ($additionalInfo) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new CborDecodingException("Invalid additional info for unsigned: $additionalInfo")
        };

        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $nextByte = $this->bytes[$this->position] ?? null;
            if ($nextByte === null) {
                throw new CborDecodingException("Unexpected end of input while reading unsigned integer");
            }
            $value = ($value << 8) | $nextByte;
            $this->position++;
        }
        return $value;
    }

    /**
     * Decode a tagged value
     */
    private function decodeTag(int $additionalInfo): mixed
    {
        $tag   = $this->decodeUnsigned($additionalInfo);
        $value = $this->decodeNext();

        // Special handling for CID tag (42)
        if ($tag === 42) {
            return ['cid' => bin2hex($value)];
        }

        return $value;
    }

    /**
     * Decode a byte string
     */
    private function decodeBytes(int $additionalInfo): string
    {
        $length = $this->decodeUnsigned($additionalInfo);

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $nextByte = $this->bytes[$this->position] ?? null;
            if ($nextByte === null) {
                throw new CborDecodingException("Unexpected end of input while reading byte string");
            }
            $bytes .= chr($nextByte);
            $this->position++;
        }
        return $bytes;
    }
}