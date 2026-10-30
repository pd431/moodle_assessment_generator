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

namespace tool_assessmentgen\task;

use tool_assessmentgen\local\purger;

/**
 * Purges a run in the background, so a large run cannot time out the admin page.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_run extends \core\task\adhoc_task {
    /**
     * Run the task.
     */
    public function execute() {
        global $DB;
        $run = $DB->get_record('tool_assessmentgen_run', ['id' => $this->get_custom_data()->runid]);
        if (!$run || $run->status === 'purged') {
            return;
        }
        $report = purger::purge($run);
        mtrace("tool_assessmentgen: purged run {$run->tag}: {$report['activities']} activities, {$report['groups']} groups, " .
            "{$report['enrolments']} enrolments, {$report['accounts']} accounts, {$report['courses']} courses.");
        foreach ($report['errors'] as $error) {
            mtrace("tool_assessmentgen:   could not delete $error");
        }
    }

    /**
     * Failures are listed on the run, which can be purged again.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }
}
