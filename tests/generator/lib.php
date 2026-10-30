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
 * Data generator, so PHPUnit tests and Behat scenarios can seed assessment data in one call.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_assessmentgen\local\options;
use tool_assessmentgen\local\run_manager;

/**
 * Generator for tool_assessmentgen.
 *
 * Example in a PHPUnit test:
 *     $run = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen')
 *         ->create_run(['courseids' => [$course->id], 'minper' => 3, 'maxper' => 3, 'seed' => 42]);
 */
class tool_assessmentgen_generator extends component_generator_base {
    /**
     * Create a run and carry it out immediately.
     *
     * @param array $record any options::DEFAULTS keys; needs 'match' or 'courseids'
     * @return stdClass the run record, with a 'totals' property added
     */
    public function create_run(array $record): stdClass {
        global $USER;
        $previous = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            $run = run_manager::create(new options($record));
            run_manager::execute($run);
        } finally {
            \core\session\manager::set_user($previous);
        }
        $run = run_manager::get_by_tag($run->tag);
        $run->totals = run_manager::totals((int)$run->id);
        return $run;
    }

    /**
     * The plan a run would carry out, without writing anything.
     *
     * @param array $record as for create_run()
     * @return array from run_manager::preview()
     */
    public function plan(array $record): array {
        return run_manager::preview(new options($record), true);
    }
}
