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

use tool_assessmentgen\local\run_manager;

/**
 * Generates one part of a course in a run (a range of its assessments) in the background. Tasks queued before
 * 0.6.0 carry no part and generate the whole course.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_course extends \core\task\adhoc_task {
    /**
     * Run the task.
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (isset($data->part)) {
            run_manager::execute_part((int)$data->runid, (int)$data->courseid, (int)$data->part);
        } else {
            run_manager::execute_course((int)$data->runid, (int)$data->courseid);
        }
        foreach (run_manager::course_report((int)$data->runid, (int)$data->courseid, $data->part ?? null) as $line) {
            mtrace('tool_assessmentgen: ' . $line);
        }
    }

    /**
     * Retrying would only repeat the same error; the run page shows it instead.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }
}
