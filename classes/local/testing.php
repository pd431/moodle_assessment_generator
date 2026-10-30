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
 * Faults that tests can arm, so failure handling can be exercised without a broken database.
 *
 * Everything here is behind one gate: on a site that is not running PHPUnit or Behat, fault() returns at once and
 * no setting is read.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testing {
    /** @var string Throw with a delegated transaction left open, as a failure inside core's add_moduleinfo() does. */
    public const FAULT_TRANSACTION = 'transaction';

    /** @var string Throw a database write error, as a duplicate key or deadlock does. */
    public const FAULT_DMLWRITE = 'dmlwrite';

    /** @var string Record whether the course lock is held at this point instead of failing. */
    public const FAULT_LOCKCHECK = 'lockcheck';

    /**
     * Whether faults can be armed at all.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING');
    }

    /**
     * Arm a fault at a point, for the next $times visits.
     *
     * @param string $point where in the code, e.g. "addmodule"
     * @param string $kind one of the FAULT_ constants
     * @param int $times
     */
    public static function arm(string $point, string $kind, int $times = 1): void {
        if (!self::enabled()) {
            throw new \coding_exception('Faults can only be armed on a test site');
        }
        set_config('fault_' . $point, json_encode(['kind' => $kind, 'times' => $times]), 'tool_assessmentgen');
    }

    /**
     * Remove every armed fault and recorded observation.
     */
    public static function disarm(): void {
        if (!self::enabled()) {
            return;
        }
        foreach ((array)get_config('tool_assessmentgen') as $name => $unused) {
            if (str_starts_with($name, 'fault_') || str_starts_with($name, 'observed_')) {
                unset_config($name, 'tool_assessmentgen');
            }
        }
    }

    /**
     * A fault point. Does nothing unless a test armed a fault here.
     *
     * @param string $point
     * @param array $context what the point knows, e.g. ['courseid' => 3]
     */
    public static function fault(string $point, array $context = []): void {
        global $DB;
        if (!self::enabled()) {
            return;
        }
        $armed = get_config('tool_assessmentgen', 'fault_' . $point);
        if ($armed === false) {
            return;
        }
        $armed = json_decode($armed, true);
        if (($armed['times'] ?? 0) <= 0) {
            return;
        }
        $armed['times']--;
        set_config('fault_' . $point, json_encode($armed), 'tool_assessmentgen');
        switch ($armed['kind']) {
            case self::FAULT_TRANSACTION:
                // Left open on purpose: the caller must deal with it, as it must with core's.
                $DB->start_delegated_transaction();
                throw new \moodle_exception('generalexceptionmessage', 'error', '', "fault: $point");
            case self::FAULT_DMLWRITE:
                throw new \dml_write_exception("fault: $point", 'INSERT', []);
            case self::FAULT_LOCKCHECK:
                self::observe($point, ['courselockheld' => locks::held('course-' . ($context['courseid'] ?? 0))]);
                return;
        }
    }

    /**
     * Record something a fault point saw, for a test to read back.
     *
     * @param string $point
     * @param array $data
     */
    protected static function observe(string $point, array $data): void {
        set_config('observed_' . $point, json_encode($data), 'tool_assessmentgen');
    }

    /**
     * What a fault point recorded, or null.
     *
     * @param string $point
     * @return array|null
     */
    public static function observed(string $point): ?array {
        $value = get_config('tool_assessmentgen', 'observed_' . $point);
        return $value === false ? null : json_decode($value, true);
    }
}
