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
 * Named locks, so a run's parts can run in parallel cron workers without making the same thing twice.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locks {
    /**
     * Run $fn while holding the lock $key, waiting up to $timeout seconds for it.
     *
     * @param string $key
     * @param callable $fn
     * @param int $timeout
     * @return mixed what $fn returns
     */
    public static function with(string $key, callable $fn, int $timeout = 600) {
        $lock = \core\lock\lock_config::get_lock_factory('tool_assessmentgen')->get_lock($key, $timeout);
        if (!$lock) {
            throw new \moodle_exception('lockbusy', 'tool_assessmentgen', '', $key);
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Run $fn only if nobody else holds $key right now.
     *
     * @param string $key
     * @param callable $fn
     * @return bool whether $fn ran
     */
    public static function try(string $key, callable $fn): bool {
        $lock = \core\lock\lock_config::get_lock_factory('tool_assessmentgen')->get_lock($key, 0);
        if (!$lock) {
            return false;
        }
        try {
            $fn();
            return true;
        } finally {
            $lock->release();
        }
    }
}
