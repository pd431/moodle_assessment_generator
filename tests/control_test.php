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

namespace tool_assessmentgen;

use tool_assessmentgen\local\options;
use tool_assessmentgen\local\purger;
use tool_assessmentgen\local\run_manager;
use tool_assessmentgen\task\generate_course;
use tool_assessmentgen\task\purge_run;

/**
 * Tests for splitting courses into parts, stopping and resuming runs, and purging past failures.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\run_manager
 * @covers     \tool_assessmentgen\local\purger
 * @covers     \tool_assessmentgen\task\generate_course
 * @covers     \tool_assessmentgen\task\purge_run
 */
final class control_test extends \advanced_testcase {
    /** @var int Fixed "today". */
    protected const ANCHOR = 1790000000;

    /**
     * A course matching "202526" with a teacher, a non-editing teacher and some students.
     *
     * @param string $shortname
     * @param int $students
     * @return \stdClass
     */
    protected function make_course(string $shortname, int $students = 8): \stdClass {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => $shortname, 'numsections' => 3]);
        $gen->create_and_enrol($course, 'editingteacher');
        $gen->create_and_enrol($course, 'teacher');
        for ($i = 0; $i < $students; $i++) {
            $gen->create_and_enrol($course, 'student');
        }
        return $course;
    }

    /**
     * Options for a run of seven assessments per course in parts of three.
     *
     * @param array $values
     * @return options
     */
    protected function options(array $values = []): options {
        return new options($values + ['match' => '202526', 'seed' => 21, 'anchor' => self::ANCHOR, 'minper' => 7,
            'maxper' => 7, 'partsize' => 3, 'types' => ['assign', 'quiz'], 'tag' => 'ctl']);
    }

    /**
     * Run the queued generator tasks, keeping their log output out of the test's.
     */
    protected function run_tasks(): void {
        ob_start();
        try {
            $this->runAdhocTasks(generate_course::class);
        } finally {
            ob_end_clean();
        }
    }

    public function test_courses_are_split_into_parts_that_build_the_same_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_course('P1-202526');
        $this->make_course('P2-202526');
        $preview = run_manager::preview($this->options());
        $run = run_manager::create($this->options());
        $this->assertSame(6, (int)$run->coursestotal);
        run_manager::queue($run);
        $this->assertCount(6, \core\task\manager::get_adhoc_tasks(generate_course::class));

        // Queuing again (say after a stop) does not double the waiting tasks.
        run_manager::queue($run);
        $this->assertCount(6, \core\task\manager::get_adhoc_tasks(generate_course::class));

        $this->run_tasks();
        $run = run_manager::get_by_tag('ctl');
        $this->assertSame('done', $run->status, (string)$run->error);
        $this->assertSame(['done' => 6], run_manager::part_counts((int)$run->id));
        $this->assertSame(14, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
        foreach ($preview['courses'] as $plan) {
            foreach ($plan['assessments'] as $a) {
                $this->assertTrue($DB->record_exists('course_modules', ['course' => $plan['courseid'],
                    'idnumber' => $a['idnumber']]));
            }
        }

        // Parts plan the whole course the same way, so together they build what one task would have built.
        $inparts = $this->item_stats((int)$run->id);
        run_manager::purge($run);
        $whole = run_manager::create($this->options(['tag' => 'ctl2', 'partsize' => 100]));
        $this->assertSame(2, (int)$whole->coursestotal);
        run_manager::execute($whole);
        $this->assertSame($inparts, $this->item_stats((int)$whole->id));
    }

    /**
     * What a run made, by course and assessment number.
     *
     * @param int $runid
     * @return array
     */
    protected function item_stats(int $runid): array {
        global $DB;
        $stats = [];
        foreach (
            $DB->get_records_sql('SELECT i.*, cm.idnumber FROM {tool_assessmentgen_item} i
                JOIN {course_modules} cm ON cm.id = i.cmid WHERE i.runid = ?', [$runid]) as $item
        ) {
            $index = (int)substr(strrchr($item->idnumber, '-'), 1);
            $stats[$item->courseid . ':' . $index] = [$item->modname, $item->submitted, $item->drafts, $item->grades,
                $item->extensions];
        }
        ksort($stats);
        return $stats;
    }

    public function test_stop_and_resume(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('S1-202526');
        $run = run_manager::create($this->options());
        run_manager::queue($run);
        run_manager::execute_part((int)$run->id, (int)$course->id, 0);

        $this->assertTrue(run_manager::stop($run));
        $this->run_tasks();
        $run = run_manager::get_by_tag('ctl');
        $this->assertSame('stopped', $run->status);
        $this->assertSame(3, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
        $this->assertSame(['done' => 1, 'queued' => 2], run_manager::part_counts((int)$run->id));
        $this->assertSame(1, (int)$run->coursesdone);
        // A stopped run cannot be stopped again, and a foreground run stops too.
        $this->assertFalse(run_manager::stop($run));
        run_manager::execute($run);
        $this->assertSame(3, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));

        run_manager::resume($run);
        $this->assertSame('running', run_manager::get_by_tag('ctl')->status);
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(generate_course::class));
        $this->run_tasks();
        $run = run_manager::get_by_tag('ctl');
        $this->assertSame('done', $run->status);
        $this->assertSame(7, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
        $this->assertSame(7, $DB->count_records_select('course_modules', $DB->sql_like('idnumber', '?'), ['AGEN-ctl-%']));
    }

    public function test_purge_keeps_going_past_failures_and_sweeps_leftovers(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Students elsewhere on the site, so the empty course gets some enrolled.
        $this->make_course('SOURCE', 8);
        $course = $this->make_course('F1-202526', 0);
        $options = $this->options(['groups' => 'always', 'types' => ['assign']]);
        $run = run_manager::create($options);
        run_manager::execute($run);
        $run = run_manager::get_by_tag('ctl');
        $this->assertSame('done', $run->status, (string)$run->error);
        $this->assertGreaterThan(0, $DB->count_records('tool_assessmentgen_enrol', ['runid' => $run->id]));
        $this->assertGreaterThan(0, $DB->count_records_select('groups', $DB->sql_like('idnumber', '?'), ['AGEN-ctl-%']));

        // An activity that has lost its instance cannot be deleted.
        $items = array_values($DB->get_records('tool_assessmentgen_item', ['runid' => $run->id], 'id'));
        $broken = $DB->get_record('course_modules', ['id' => $items[0]->cmid]);
        $DB->set_field('course_modules', 'instance', 0, ['id' => $broken->id]);
        // An activity a failed part left unrecorded, and one from a run whose tag merely starts the same way.
        $gen = $this->getDataGenerator();
        $leftover = $gen->create_module('assign', ['course' => $course->id, 'idnumber' => "AGEN-ctl-{$course->id}-99"]);
        $other = $gen->create_module('assign', ['course' => $course->id, 'idnumber' => "AGEN-ctl-{$course->id}-1-1"]);

        // Moodle complains about the broken activity whenever it rebuilds the course cache.
        ob_start();
        $report = purger::purge($run, 0);
        ob_end_clean();
        $run = run_manager::get_by_tag('ctl');
        $this->assertSame('purgeincomplete', $run->status);
        $this->assertCount(1, $report['errors']);
        $this->assertStringContainsString("activity {$broken->id}", $run->error);
        $this->assertSame(7, $report['activities']);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $leftover->cmid]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $other->cmid]));
        $this->assertEquals([$items[0]->id], array_keys($DB->get_records('tool_assessmentgen_item', ['runid' => $run->id])));
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_enrol', ['runid' => $run->id]));
        $this->assertSame(0, $DB->count_records_select('groups', $DB->sql_like('idnumber', '?'), ["AGEN-ctl-{$course->id}-g%"]));

        // Once the problem is fixed, purging again finishes the job.
        $DB->set_field('course_modules', 'instance', $broken->instance, ['id' => $broken->id]);
        $report = purger::purge($run, 0);
        $this->assertSame([], $report['errors']);
        $this->assertSame(1, $report['activities']);
        $this->assertSame('purged', run_manager::get_by_tag('ctl')->status);
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_part', ['runid' => $run->id]));
    }

    public function test_purge_from_the_page_stops_the_run_and_runs_in_the_background(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('B1-202526');
        $run = run_manager::create($this->options());
        run_manager::queue($run);
        run_manager::execute_part((int)$run->id, (int)$course->id, 0);

        purger::request($run);
        $this->assertSame('purging', run_manager::get_by_tag('ctl')->status);
        // Generator tasks still queued do nothing once a purge is under way.
        $this->run_tasks();
        $this->assertSame(3, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));

        ob_start();
        $this->runAdhocTasks(purge_run::class);
        $output = ob_get_clean();
        $this->assertStringContainsString('purged run ctl: 3 activities', $output);
        $this->assertSame('purged', run_manager::get_by_tag('ctl')->status);
        $this->assertSame(0, $DB->count_records_select('course_modules', $DB->sql_like('idnumber', '?'), ['AGEN-ctl-%']));
    }

    public function test_purge_removes_the_runs_waiting_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_course('T1-202526');
        $run = run_manager::create($this->options());
        run_manager::queue($run);
        $other = run_manager::create($this->options(['tag' => 'other']));
        run_manager::queue($other);
        $this->assertCount(6, \core\task\manager::get_adhoc_tasks(generate_course::class));

        purger::purge($run, 1);
        $left = \core\task\manager::get_adhoc_tasks(generate_course::class);
        $this->assertCount(3, $left);
        foreach ($left as $task) {
            $this->assertSame((int)$other->id, (int)$task->get_custom_data()->runid);
        }
        $this->assertSame('purged', run_manager::get_by_tag('ctl')->status);
    }
}
