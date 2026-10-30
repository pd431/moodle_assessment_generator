<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_assessmentgen\local;

/**
 * Small seeded random number generator.
 *
 * PHP's mt_rand() is global state, so anything else that calls it during a run would change the plan.
 * This keeps each course's plan reproducible from (seed, courseid) alone.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class random {
    /** @var int 32-bit state. */
    protected int $state;

    /**
     * Constructor.
     *
     * @param int $seed
     */
    public function __construct(int $seed) {
        $this->state = $seed & 0xffffffff;
    }

    /**
     * A generator for one named part of the plan, independent of the order parts are planned in.
     *
     * @param int $seed run seed
     * @param string $key e.g. "course:12"
     * @return self
     */
    public static function for_key(int $seed, string $key): self {
        return new self(($seed ^ crc32($key)) & 0xffffffff);
    }

    /**
     * Next float in [0, 1), mulberry32.
     *
     * @return float
     */
    public function float(): float {
        $this->state = ($this->state + 0x6D2B79F5) & 0xffffffff;
        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xffffffff;
        $t = ($t ^ ($t >> 14)) & 0xffffffff;
        return $t / 4294967296;
    }

    /**
     * 32-bit multiply.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    protected static function imul(int $a, int $b): int {
        $alo = $a & 0xffff;
        $ahi = ($a >> 16) & 0xffff;
        return (($alo * $b) + (($ahi * $b & 0xffff) << 16)) & 0xffffffff;
    }

    /**
     * Integer in [min, max].
     *
     * @param int $min
     * @param int $max
     * @return int
     */
    public function int(int $min, int $max): int {
        if ($max <= $min) {
            return $min;
        }
        return $min + (int)floor($this->float() * ($max - $min + 1));
    }

    /**
     * True with probability p.
     *
     * @param float $p
     * @return bool
     */
    public function chance(float $p): bool {
        return $this->float() < $p;
    }

    /**
     * One element of a non-empty list.
     *
     * @param array $items
     * @return mixed
     */
    public function pick(array $items) {
        $items = array_values($items);
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * Normally distributed value (Box-Muller).
     *
     * @param float $mean
     * @param float $sd
     * @return float
     */
    public function normal(float $mean, float $sd): float {
        $u = max($this->float(), 1e-12);
        $v = $this->float();
        return $mean + $sd * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
