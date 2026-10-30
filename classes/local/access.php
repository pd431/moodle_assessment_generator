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
 * Whether generation is allowed on this site.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * True when config.php sets $CFG->tool_assessmentgen_allow, or inside PHPUnit or Behat.
     *
     * @return bool
     */
    public static function allowed(): bool {
        global $CFG;
        if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST) || (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING)) {
            return true;
        }
        return !empty($CFG->tool_assessmentgen_allow);
    }

    /**
     * Throw unless allowed.
     */
    public static function require_allowed(): void {
        if (!self::allowed()) {
            throw new \moodle_exception('disabled', 'tool_assessmentgen');
        }
    }
}
