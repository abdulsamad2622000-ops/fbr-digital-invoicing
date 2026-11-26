<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal QR Code generator (byte mode, versions 2-3, error correction L/M).
 *
 * FBR Digital Invoicing asks for a Version 2 (25x25) QR code that carries the
 * FBR invoice number, so this covers what the invoices need without an extra
 * Composer dependency. Content longer than Version 2 allows falls back to
 * Version 3 so an invoice never fails to render.
 */
class QrCode
{
    // [version][level] => [total codewords, ec codewords] (single block only)
    private const CAPACITY = [
        2 => ['L' => [44, 10], 'M' => [44, 16]],
        3 => ['L' => [70, 15], 'M' => [70, 26]],
    ];

    private const ALIGNMENT = [2 => 18, 3 => 22];

    private const LEVEL_BITS = ['L' => 0b01, 'M' => 0b00];

    /** @var array<int, array<int, ?bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $reserved = [];

    private int $size = 0;

    /**
     * Returns the module matrix (true = dark) for the given text.
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $data): array
    {
        return (new self())->build($data);
    }

    /**
     * Returns an SVG image of the QR code, including a 4-module quiet zone.
     */
    public static function svg(string $data, int $pixelSize = 96): string
    {
        $matrix = self::matrix($data);
        $count  = count($matrix);
        $total  = $count + 8;
        $rects  = '';

        // One <rect> per horizontal run of dark modules: plain rects render
        // reliably in browsers and in DomPDF's SVG engine.
        foreach ($matrix as $y => $row) {
            $x = 0;
            while ($x < $count) {
                if (!$row[$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $count && $row[$x]) {
                    $x++;
                }
                $rects .= '<rect x="' . ($start + 4) . '" y="' . ($y + 4) . '" width="' . ($x - $start) . '" height="1"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $pixelSize . '" height="' . $pixelSize . '"'
            . ' viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges">'
            . '<rect x="0" y="0" width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<g fill="#000000">' . $rects . '</g></svg>';
    }

    /**
     * Data URI suitable for an <img src> (works in browsers and DomPDF).
     */
    public static function dataUri(string $data, int $pixelSize = 96): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $pixelSize));
    }

    private function build(string $data): array
    {
        [$version, $level] = $this->chooseVersion(strlen($data));
        [$total, $ecCount] = self::CAPACITY[$version][$level];
        $dataCount = $total - $ecCount;

        $codewords = $this->encodeData($data, $dataCount);
        $codewords = array_merge($codewords, $this->reedSolomon($codewords, $ecCount));

        $this->size = 17 + 4 * $version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, null));
        $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));

        $this->placeFunctionPatterns($version);
        $this->placeData($codewords);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->applyMask($mask);
            $this->placeFormat($candidate, $level, $mask);
            $penalty = $this->penalty($candidate);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return array_map(fn ($row) => array_map(fn ($m) => (bool) $m, $row), $best);
    }

    private function chooseVersion(int $length): array
    {
        foreach ([2, 3] as $version) {
            foreach (['M', 'L'] as $level) {
                [$total, $ec] = self::CAPACITY[$version][$level];
                if ($length <= $total - $ec - 2) {
                    return [$version, $level];
                }
            }
        }

        throw new InvalidArgumentException('QR content is too long (' . $length . ' bytes).');
    }

    private function encodeData(string $data, int $dataCount): array
    {
        $bits = '0100' . str_pad(decbin(strlen($data)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $capacity = $dataCount * 8;
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($bytes) < $dataCount; $pad++) {
            $bytes[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        return $bytes;
    }

    private function reedSolomon(array $data, int $ecCount): array
    {
        [$exp, $log] = $this->galoisTables();

        $generator = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= $this->gfMul($coef, $exp[$i], $exp, $log);
            }
            $generator = $next;
        }

        $remainder = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            for ($j = 0; $j < $ecCount; $j++) {
                $remainder[$j] ^= $this->gfMul($generator[$j + 1], $factor, $exp, $log);
            }
        }

        return $remainder;
    }

    private function galoisTables(): array
    {
        static $tables = null;
        if ($tables === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
            $tables = [$exp, $log];
        }

        return $tables;
    }

    private function gfMul(int $a, int $b, array $exp, array $log): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $exp[$log[$a] + $log[$b]];
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }

    private function placeFunctionPatterns(int $version): void
    {
        $n = $this->size;

        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$fx, $fy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $fx + $dx;
                    $y = $fy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                        continue;
                    }
                    $inRing  = max(abs($dx - 3), abs($dy - 3));
                    $this->setFunction($x, $y, $inRing !== 2 && $inRing !== 4);
                }
            }
        }

        for ($i = 8; $i < $n - 8; $i++) {
            $this->setFunction($i, 6, $i % 2 === 0);
            $this->setFunction(6, $i, $i % 2 === 0);
        }

        $c = self::ALIGNMENT[$version];
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($c + $dx, $c + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }

        // Dark module and format-information areas.
        $this->setFunction(8, $n - 8, true);
        for ($i = 0; $i < 9; $i++) {
            if (!$this->reserved[8][$i]) {
                $this->setFunction($i, 8, false);
            }
            if (!$this->reserved[$i][8]) {
                $this->setFunction(8, $i, false);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($n - 1 - $i, 8, false);
            if (!$this->reserved[$n - 1 - $i][8]) {
                $this->setFunction(8, $n - 1 - $i, false);
            }
        }
    }

    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        $n = $this->size;
        $i = 0;
        $upward = true;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $n; $vert++) {
                $y = $upward ? $n - 1 - $vert : $vert;
                for ($k = 0; $k < 2; $k++) {
                    $x = $right - $k;
                    if ($this->reserved[$y][$x]) {
                        continue;
                    }
                    $this->modules[$y][$x] = $i < strlen($bits) && $bits[$i] === '1';
                    $i++;
                }
            }
            $upward = !$upward;
        }
    }

    private function applyMask(int $mask): array
    {
        $result = $this->modules;
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($flip) {
                    $result[$y][$x] = !$result[$y][$x];
                }
            }
        }

        return $result;
    }

    private function placeFormat(array &$matrix, string $level, int $mask): void
    {
        $data = (self::LEVEL_BITS[$level] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn (int $i) => (($bits >> $i) & 1) === 1;
        $n = $this->size;

        for ($i = 0; $i <= 5; $i++) {
            $matrix[$i][8] = $bit($i);
        }
        $matrix[7][8] = $bit(6);
        $matrix[8][8] = $bit(7);
        $matrix[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $matrix[8][14 - $i] = $bit($i);
        }

        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$n - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $matrix[$n - 15 + $i][8] = $bit($i);
        }
        $matrix[$n - 8][8] = true;
    }

    private function penalty(array $m): int
    {
        $n = $this->size;
        $score = 0;

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $run = 1;
                for ($b = 1; $b < $n; $b++) {
                    $cur  = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += $run - 2;
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $score += $run - 2;
                }
            }
        }

        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        $patterns = [[true, false, true, true, true, false, true, false, false, false, false],
                     [false, false, false, false, true, false, true, true, true, false, true]];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x <= $n - 11; $x++) {
                foreach ($patterns as $p) {
                    $rowMatch = true;
                    $colMatch = true;
                    for ($k = 0; $k < 11; $k++) {
                        $rowMatch = $rowMatch && $m[$y][$x + $k] === $p[$k];
                        $colMatch = $colMatch && $m[$x + $k][$y] === $p[$k];
                    }
                    $score += ($rowMatch ? 40 : 0) + ($colMatch ? 40 : 0);
                }
            }
        }

        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                $dark += $cell ? 1 : 0;
            }
        }
        $score += intdiv(abs($dark * 20 - $n * $n * 10), $n * $n) * 10;

        return $score;
    }
}
