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
 * Creates a run's courses and accounts in the background, then queues its parts.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_run extends \core\task\adhoc_task {
    /**
     * Run the task.
     */
    public function execute() {
        global $DB;
        $run = $DB->get_record('tool_assessmentgen_run', ['id' => $this->get_custom_data()->runid]);
        if (!$run || !run_manager::active((int)$run->id)) {
            mtrace('tool_assessmentgen: run is stopped or purged; nothing to set up.');
            return;
        }
        $run = run_manager::setup($run, function (\stdClass $course) {
            mtrace('tool_assessmentgen: created course ' . $course->shortname . ': ' .
                (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false));
        });
        if (run_manager::active((int)$run->id)) {
            run_manager::queue($run);
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
