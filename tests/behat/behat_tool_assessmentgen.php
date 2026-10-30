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
 * Behat steps for tool_assessmentgen.
 *
 * @package    tool_assessmentgen
 * @category   test
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps for reaching what a run created; activity names are random, so scenarios find them by run tag.
 */
class behat_tool_assessmentgen extends behat_base {
    /**
     * The course module ids a run created, in creation order.
     *
     * @param string $tag
     * @return int[]
     */
    protected function cmids(string $tag): array {
        global $DB;
        $run = $DB->get_record('tool_assessmentgen_run', ['tag' => $tag], '*', MUST_EXIST);
        return array_map('intval', $DB->get_fieldset_select(
            'tool_assessmentgen_item',
            'cmid',
            'runid = ? ORDER BY id',
            [$run->id]
        ));
    }

    /**
     * Open the nth activity a run created, whatever its type.
     *
     * @Given /^I am on (?:assignment|activity) "(?P<n>\d+)" of generator run "(?P<tag>[^"]*)"$/
     * @param int $n 1-based
     * @param string $tag
     */
    public function i_am_on_generated_activity(int $n, string $tag): void {
        $cmids = $this->cmids($tag);
        if (!isset($cmids[$n - 1])) {
            throw new ExpectationException("Run $tag created " . count($cmids) . ' activities', $this->getSession());
        }
        global $DB;
        $modname = $DB->get_field_sql('SELECT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
                                        WHERE cm.id = ?', [$cmids[$n - 1]]);
        $this->execute('behat_general::i_visit', [new moodle_url("/mod/$modname/view.php", ['id' => $cmids[$n - 1]])]);
    }

    /**
     * Check how many activities a run has left on the site.
     *
     * @Then /^generator run "(?P<tag>[^"]*)" should have "(?P<count>\d+)" activities on the site$/
     * @param string $tag
     * @param int $count
     */
    public function run_should_have_activities(string $tag, int $count): void {
        global $DB;
        $run = $DB->get_record('tool_assessmentgen_run', ['tag' => $tag], '*', MUST_EXIST);
        $actual = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {course_modules} cm
                                            JOIN {course} c ON c.id = cm.course
                                           WHERE ' . $DB->sql_like('cm.idnumber', ':prefix'),
            ['prefix' => 'AGEN-' . $run->tag . '-%']
        );
        if ($actual !== $count) {
            throw new ExpectationException("Run $tag has $actual activities, expected $count", $this->getSession());
        }
    }
}
