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

/**
 * Uninstall: purge every run first, so no generated activity, group, enrolment, course or account is left behind.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Purge every run that is not purged yet, in the foreground, and report what could not be deleted.
 *
 * Uninstall never refuses: a run whose purge is incomplete is reported, and the plugin's own tables go with
 * the plugin as usual.
 *
 * @return bool
 */
function xmldb_tool_assessmentgen_uninstall(): bool {
    global $CFG, $DB;
    // The gate in config.php stops the generator being used on a site it should not touch; removing what it made
    // is wanted on any site, so it is opened for this process only.
    $CFG->tool_assessmentgen_allow = true;
    foreach ($DB->get_records_select('tool_assessmentgen_run', "status <> 'purged'", null, 'id') as $run) {
        $report = \tool_assessmentgen\local\purger::purge($run, 60);
        $lines = [get_string('uninstallpurged', 'tool_assessmentgen', (object)(['tag' => $run->tag] + $report))];
        foreach ($report['errors'] as $error) {
            $lines[] = get_string('uninstallnotdeleted', 'tool_assessmentgen', $error);
        }
        foreach ($lines as $line) {
            if (CLI_SCRIPT) {
                mtrace($line);
            } else {
                \core\notification::add($line, $report['errors'] ? \core\notification::WARNING : \core\notification::INFO);
            }
        }
    }
    return true;
}
