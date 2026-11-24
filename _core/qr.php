<?php
declare(strict_types=1);

/*
 * Minimal QR code generator (byte mode, error correction level M,
 * versions 1–10 = up to 213 bytes, plenty for a web address).
 * Runs entirely on the server: no external service ever sees the address.
 * Follows ISO/IEC 18004; structure after Project Nayuki's reference design.
 */

final class QrCode
{
    /** version => [EC codewords per block, [[block count, data codewords per block], …]] (level M) */
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]], 6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private int $size;
    /** @var array<int, array<int, bool>> [y][x] */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];

    private function __construct(private int $version)
    {
        $this->size = 17 + 4 * $version;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    /** @return array<int, array<int, bool>> dark modules, [row][col] */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);
        $version = 0;
        foreach (self::BLOCKS as $v => [$ec, $groups]) {
            $dataCw = 0;
            foreach ($groups as [$n, $d]) {
                $dataCw += $n * $d;
            }
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $dataCw * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('text too long for QR');
        }

        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($qr->dataCodewords($bytes)));

        $best = -1;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->applyMask($mask);
            $qr->drawFormatBits($mask);
            $p = $qr->penalty();
            if ($p < $bestPenalty) {
                $best = $mask;
                $bestPenalty = $p;
            }
            $qr->applyMask($mask); // undo (XOR)
        }
        $qr->applyMask($best);
        $qr->drawFormatBits($best);
        return $qr->modules;
    }

    /** Scalable SVG (no inline styles: works under a strict CSP). */
    public static function svg(string $text, int $quiet = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $full = $n + 2 * $quiet;
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $quiet) . ',' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges" role="img" aria-label="QR">'
            . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /* ------------------------------ data ------------------------------ */

    private function dataCodewords(array $bytes): array
    {
        [$ec, $groups] = self::BLOCKS[$this->version];
        $capacity = 0;
        foreach ($groups as [$n, $d]) {
            $capacity += $n * $d;
        }
        $bits = [];
        $push = function (int $val, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(0b0100, 4); // byte mode
        $push(count($bytes), $this->version < 10 ? 8 : 16);
        foreach ($bytes as $b) {
            $push($b, 8);
        }
        $push(0, min(4, $capacity * 8 - count($bits))); // terminator
        $push(0, (8 - count($bits) % 8) % 8);
        $cw = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $cw[] = bindec(implode('', $chunk));
        }
        for ($pad = 0xEC; count($cw) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $cw[] = $pad;
        }
        return $cw;
    }

    private function addEccAndInterleave(array $data): array
    {
        [$ecLen, $groups] = self::BLOCKS[$this->version];
        $divisor = self::rsDivisor($ecLen);
        $blocks = [];
        $k = 0;
        foreach ($groups as [$n, $d]) {
            for ($i = 0; $i < $n; $i++) {
                $dat = array_slice($data, $k, $d);
                $k += $d;
                $blocks[] = [$dat, self::rsRemainder($dat, $divisor)];
            }
        }
        $out = [];
        $maxData = max(array_map(fn ($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as [$dat]) {
                if ($i < count($dat)) {
                    $out[] = $dat[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($blocks as [, $ecc]) {
                $out[] = $ecc[$i];
            }
        }
        return $out;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    /* ---------------------------- drawing ---------------------------- */

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $dist = max(abs($dx), abs($dy));
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$this->version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserve
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true);
    }

    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Standard penalty score (lower = easier to scan). */
    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $n; $j++) {
                $row .= $m[$i][$j] ? '1' : '0';
                $col .= $m[$j][$i] ? '1' : '0';
                $dark += $m[$i][$j] ? 1 : 0;
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $runs)) {
                foreach ($runs[0] as $r) {
                    $score += 3 + strlen($r) - 5;
                }
            }
            $padded = '0000' . $line . '0000';
            $score += 40 * (substr_count($padded, '00001011101') + substr_count($padded, '10111010000'));
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $score + max(0, $k) * 10;
    }
}
