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
 * What a site needs for a run, checked before anything is made. Nothing here changes a site setting: a missing piece
 * either stops the run with a message saying what to turn on, or is reported as a warning.
 *
 * Activity types that are not installed or are disabled are reported separately, by the registry, and skipped.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_checks {
    /** @var string The run cannot work: it is refused. */
    public const FATAL = 'fatal';

    /** @var string The run works but something is missing that the person probably wants. */
    public const WARNING = 'warning';

    /**
     * Problems found for a run with these options; empty when the site has everything.
     *
     * @param options $o
     * @return array<int, array{check: string, level: string, message: string}>
     */
    public static function run(options $o): array {
        global $DB;
        $problems = [];
        // Generated people are always enrolled; borrowed ones only into courses missing them, which a run on
        // existing courses may never need, so there it is only a warning.
        $generates = $o->createcourses > 0 || student_pool::enabled($o) || student_pool::staff_enabled($o);
        $enrols = $generates || $o->enrol === 'missing';
        if ($enrols && !enrol_is_enabled('manual')) {
            $problems[] = ['check' => 'manual', 'level' => $generates ? self::FATAL : self::WARNING,
                'message' => get_string('checkmanual', 'tool_assessmentgen')];
        }
        if ($enrols) {
            $roles = course_selector::enrol_roles();
            $needed = [$roles['student'], $roles['marker']];
            if (student_pool::staff_enabled($o)) {
                $needed[] = $roles['convenor'];
            }
            $missing = [];
            foreach (array_unique($needed) as $shortname) {
                if (!$DB->record_exists('role', ['shortname' => $shortname])) {
                    $missing[] = $shortname;
                }
            }
            if ($missing) {
                $problems[] = ['check' => 'roles', 'level' => $generates ? self::FATAL : self::WARNING,
                    'message' => get_string('checkroles', 'tool_assessmentgen', implode(', ', $missing))];
            }
        }
        $plugins = \core_plugin_manager::instance()->get_installed_plugins('block');
        if (!isset($plugins['le_dashboard']) && !isset($plugins['my_assessments'])) {
            $problems[] = ['check' => 'dashboard', 'level' => self::WARNING,
                'message' => get_string('checkdashboard', 'tool_assessmentgen')];
        }
        return $problems;
    }
}
