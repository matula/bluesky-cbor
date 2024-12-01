<?php
declare(strict_types=1);

namespace Matula\BlueskyCbor;

class NativeCborDecoder
{
    private int $position = 0;
    private array $bytes;
    private bool $debug = false;

    public function decode(string $data, bool $debug = false): array
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

        return $this->decodeNextComplete();
    }

    private function decodeNextComplete(): array
    {
        $result = [];

        while ($this->position < count($this->bytes)) {
            $startPos = $this->position;

            try {
                $byte = $this->bytes[$this->position];
                $majorType = $byte >> 5;
                $additionalInfo = $byte & 0x1f;

                if ($this->debug) {
                    dump([
                        'position' => $this->position,
                        'byte' => sprintf('0x%02X', $byte),
                        'major_type' => $majorType,
                        'additional_info' => $additionalInfo
                    ]);
                }

                $this->position++;

                $value = match($majorType) {
                    0 => $this->decodeUnsigned($additionalInfo),
                    1 => -1 - $this->decodeUnsigned($additionalInfo),
                    2 => $this->decodeBytes($additionalInfo),
                    3 => $this->decodeString($additionalInfo),
                    4 => $this->decodeArray($additionalInfo),
                    5 => $this->decodeMap($additionalInfo),
                    6 => $this->decodeTag($additionalInfo),
                    7 => $this->decodeSpecial($additionalInfo),
                    default => null
                };

                if ($value !== null) {
                    if (is_array($value)) {
                        $result = array_merge($result, $value);
                    } else if ($this->position - $startPos > 1) {
                        // Only add if we actually read something
                        $result["value_at_$startPos"] = $value;
                    }
                }
            } catch (\Exception $e) {
                if ($this->debug) {
                    dump("Error at position $startPos: " . $e->getMessage());
                }
                break;
            }
        }

        return $result;
    }

    private function decodeUnsigned(int $additionalInfo): int
    {
        if ($additionalInfo <= 23) {
            return $additionalInfo;
        }

        $bytes = match($additionalInfo) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new \Exception("Invalid additional info for unsigned: $additionalInfo")
        };

        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | $this->bytes[$this->position++];
        }
        return $value;
    }

    private function decodeBytes(int $additionalInfo): string
    {
        $length = $this->decodeUnsigned($additionalInfo);
        if ($this->debug) dump("Decoding bytes of length $length");

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr($this->bytes[$this->position++]);
        }
        return $bytes;
    }

    private function decodeString(int $additionalInfo): string
    {
        return $this->decodeBytes($additionalInfo);
    }

    private function decodeArray(int $additionalInfo): array
    {
        $length = $this->decodeUnsigned($additionalInfo);
        if ($this->debug) dump("Decoding array of length $length");

        $array = [];
        for ($i = 0; $i < $length; $i++) {
            $array[] = $this->decodeNext();
        }
        return ['array' => $array];
    }

    private function decodeMap(int $additionalInfo): array
    {
        $length = $this->decodeUnsigned($additionalInfo);
        if ($this->debug) dump("Decoding map of length $length");

        $map = [];
        for ($i = 0; $i < $length; $i++) {
            $key = $this->decodeNext();
            if (!is_string($key)) {
                $key = json_encode($key);
            }
            $map[$key] = $this->decodeNext();
        }
        return $map;
    }

    private function decodeNext(): mixed
    {
        $byte = $this->bytes[$this->position];
        $majorType = $byte >> 5;
        $additionalInfo = $byte & 0x1f;

        if ($this->debug) {
            dump([
                'position' => $this->position,
                'byte' => sprintf('0x%02X', $byte),
                'major_type' => $majorType,
                'additional_info' => $additionalInfo
            ]);
        }

        $this->position++;

        return match($majorType) {
            0 => $this->decodeUnsigned($additionalInfo),
            1 => -1 - $this->decodeUnsigned($additionalInfo),
            2 => $this->decodeBytes($additionalInfo),
            3 => $this->decodeString($additionalInfo),
            4 => $this->decodeArray($additionalInfo),
            5 => $this->decodeMap($additionalInfo),
            6 => $this->decodeTag($additionalInfo),
            7 => $this->decodeSpecial($additionalInfo),
            default => throw new \Exception("Unknown major type: $majorType")
        };
    }

    private function decodeTag(int $additionalInfo): mixed
    {
        $tag = $this->decodeUnsigned($additionalInfo);
        if ($this->debug) dump("Decoding tag $tag");

        $value = $this->decodeNext();

        if ($tag === 42) {
            return ['cid' => bin2hex($value)];
        }

        return $value;
    }

    private function decodeSpecial(int $additionalInfo): mixed
    {
        return match($additionalInfo) {
            20 => false,
            21 => true,
            22 => null,
            23 => 'undefined',
            default => throw new \Exception("Unsupported special value: $additionalInfo")
        };
    }
}