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

    /** @var int Fixed "today" for runs made by steps. */
    protected const ANCHOR = 1790000000;

    /**
     * A run of $parts parts (two assignments each) in one course, queued but not run.
     *
     * @param string $tag
     * @param int $parts
     * @param string $shortname
     * @return stdClass
     */
    protected function queue_run(string $tag, int $parts, string $shortname): stdClass {
        global $DB;
        $courseid = (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $run = \tool_assessmentgen\local\run_manager::create(new \tool_assessmentgen\local\options([
            'courseids' => [$courseid], 'seed' => 5, 'anchor' => self::ANCHOR, 'minper' => 2 * $parts,
            'maxper' => 2 * $parts, 'partsize' => 2, 'types' => ['assign'], 'tag' => $tag,
        ]));
        \tool_assessmentgen\local\run_manager::queue($run);
        return $run;
    }

    /**
     * A run by tag.
     *
     * @param string $tag
     * @return stdClass
     */
    protected function run(string $tag): stdClass {
        global $DB;
        return $DB->get_record('tool_assessmentgen_run', ['tag' => $tag], '*', MUST_EXIST);
    }

    /**
     * Queue a run of some parts in a course, without running it.
     *
     * @Given /^a generator run "(?P<tag>[^"]*)" is queued with (?P<parts>\d+) parts? in course "(?P<shortname>[^"]*)"$/
     * @param string $tag
     * @param int $parts
     * @param string $shortname
     */
    public function a_run_is_queued(string $tag, int $parts, string $shortname): void {
        $this->queue_run($tag, $parts, $shortname);
    }

    /**
     * Queue a run and stop it before any part ran, so its tasks are left waiting.
     *
     * @Given /^a generator run "(?P<tag>[^"]*)" is queued with (?P<parts>\d+) parts and stopped before they ran$/
     * @param string $tag
     * @param int $parts
     */
    public function a_run_is_queued_and_stopped(string $tag, int $parts): void {
        $run = $this->queue_run($tag, $parts, 'HIS101-202526');
        \tool_assessmentgen\local\run_manager::stop($run);
    }

    /**
     * What a killed worker leaves behind: a part that says running while nobody holds its lock.
     *
     * @Given /^a generator run "(?P<tag>[^"]*)" has a part marked "running" with no lock held$/
     * @param string $tag
     */
    public function a_run_has_a_dead_part(string $tag): void {
        global $DB;
        $run = $this->queue_run($tag, 1, 'HIS101-202526');
        $DB->set_field('tool_assessmentgen_part', 'status', 'running', ['runid' => $run->id]);
        $DB->set_field('tool_assessmentgen_run', 'status', 'running', ['id' => $run->id]);
    }

    /**
     * Arm a test fault, by its description.
     *
     * @Given /^the test fault "(?P<fault>[^"]*)" is armed$/
     * @param string $fault
     */
    public function the_test_fault_is_armed(string $fault): void {
        $faults = [
            'throw inside add_moduleinfo' => ['addmodule', \tool_assessmentgen\local\testing::FAULT_TRANSACTION, 1],
            'dml write error on the first attempt of assessment 1' =>
                ['addmodule', \tool_assessmentgen\local\testing::FAULT_DMLWRITE, 1],
        ];
        if (!isset($faults[$fault])) {
            throw new ExpectationException("Unknown test fault: $fault", $this->getSession());
        }
        \tool_assessmentgen\local\testing::arm(...$faults[$fault]);
    }

    /**
     * Run every waiting adhoc task, as cron would, without failing when one of them fails.
     *
     * @When /^I run all adhoc tasks, letting them fail$/
     */
    public function i_run_all_adhoc_tasks_letting_them_fail(): void {
        \core\cron::setup_user();
        ob_start();
        try {
            $timenow = time();
            while (
                !\core\task\manager::static_caches_cleared_since($timenow) &&
                $task = \core\task\manager::get_next_adhoc_task($timenow)
            ) {
                \core\cron::run_inner_adhoc_task($task);
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Skip the scenario on a site without the activity type (mod_peerwork is not part of Moodle).
     *
     * @Given /^the activity type "(?P<type>[^"]*)" is available on this site$/
     * @param string $type
     */
    public function the_activity_type_is_available(string $type): void {
        if (!\tool_assessmentgen\local\registry::available([$type])) {
            throw new \Moodle\BehatExtension\Exception\SkippedException("Activity type $type is not installed");
        }
    }

    /**
     * Purge a run in the foreground.
     *
     * @When /^I purge run "(?P<tag>[^"]*)"$/
     * @param string $tag
     */
    public function i_purge_run(string $tag): void {
        \tool_assessmentgen\local\purger::purge($this->run($tag), 5);
    }

    /**
     * Check a part's status.
     *
     * @Then /^the part "(?P<part>\d+)" of run "(?P<tag>[^"]*)" has status "(?P<status>[^"]*)"$/
     * @param int $part 1-based
     * @param string $tag
     * @param string $status
     */
    public function the_part_has_status(int $part, string $tag, string $status): void {
        global $DB;
        $actual = $DB->get_field('tool_assessmentgen_part', 'status', ['runid' => $this->run($tag)->id, 'part' => $part - 1]);
        if ($actual !== $status) {
            throw new ExpectationException("Part $part of run $tag is $actual, expected $status", $this->getSession());
        }
    }

    /**
     * Check a run's status.
     *
     * @Then /^the run "(?P<tag>[^"]*)" has status "(?P<status>[^"]*)"$/
     * @param string $tag
     * @param string $status
     */
    public function the_run_has_status(string $tag, string $status): void {
        $run = $this->run($tag);
        if ($run->status !== $status) {
            throw new ExpectationException("Run $tag is {$run->status} ({$run->error}), expected $status", $this->getSession());
        }
    }

    /**
     * Check the error a run shows.
     *
     * @Then /^the run "(?P<tag>[^"]*)" shows the error "(?P<text>[^"]*)"$/
     * @param string $tag
     * @param string $text
     */
    public function the_run_shows_the_error(string $tag, string $text): void {
        $run = $this->run($tag);
        if (!str_contains((string)$run->error, $text)) {
            throw new ExpectationException("Run $tag's error is \"{$run->error}\", expected \"$text\"", $this->getSession());
        }
    }

    /**
     * Check that no task of the run is waiting.
     *
     * @Then /^no adhoc task for run "(?P<tag>[^"]*)" is queued$/
     * @param string $tag
     */
    public function no_adhoc_task_is_queued(string $tag): void {
        global $DB;
        $runid = (int)$this->run($tag)->id;
        foreach ($DB->get_records('task_adhoc', ['component' => 'tool_assessmentgen']) as $task) {
            if ((int)(json_decode($task->customdata)->runid ?? 0) === $runid) {
                throw new ExpectationException("Task {$task->classname} for run $tag is still queued", $this->getSession());
            }
        }
    }

    /**
     * Check that the failure handling closed what core left open.
     *
     * @Then /^no database transaction is left open$/
     */
    public function no_transaction_is_left_open(): void {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new ExpectationException('A database transaction is still open', $this->getSession());
        }
    }
}
