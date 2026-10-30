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
use tool_assessmentgen\local\testing;

/**
 * Tests for what happens when building an assessment fails on a busy database.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\run_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\transactions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\testing::class)]
final class failure_test extends \advanced_testcase {
    /** @var int Fixed "today". */
    protected const ANCHOR = 1790000000;

    /**
     * A course matching "202526" with a teacher, a non-editing teacher and some students.
     *
     * @param string $shortname
     * @return \stdClass
     */
    protected function make_course(string $shortname): \stdClass {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => $shortname, 'numsections' => 3]);
        $gen->create_and_enrol($course, 'editingteacher');
        $gen->create_and_enrol($course, 'teacher');
        for ($i = 0; $i < 6; $i++) {
            $gen->create_and_enrol($course, 'student');
        }
        return $course;
    }

    /**
     * A queued run of two assignments in one part.
     *
     * @param string $tag
     * @return \stdClass
     */
    protected function queued_run(string $tag): \stdClass {
        $run = run_manager::create(new options(['match' => '202526', 'seed' => 5, 'anchor' => self::ANCHOR,
            'minper' => 2, 'maxper' => 2, 'partsize' => 2, 'types' => ['assign'], 'tag' => $tag]));
        run_manager::queue($run);
        return $run;
    }

    public function test_failure_inside_module_transaction_still_marks_the_part_failed(): void {
        global $DB;
        $this->resetAfterTest();
        // Without the test's own transaction around everything, so the open one the fault leaves behind is the
        // only one, as on a real site.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $course = $this->make_course('TX-202526');
        $run = $this->queued_run('tx');
        testing::arm('addmodule', testing::FAULT_TRANSACTION);

        try {
            run_manager::execute_part((int)$run->id, (int)$course->id, 0);
            $this->fail('The armed fault should have been thrown');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('fault: addmodule', $e->getMessage());
        }

        $this->assertFalse($DB->is_transaction_started(), 'The transaction the failure left open is discarded');
        $part = $DB->get_record('tool_assessmentgen_part', ['runid' => $run->id, 'courseid' => $course->id, 'part' => 0]);
        $this->assertSame('failed', $part->status);
        $this->assertStringContainsString('fault: addmodule', $part->error);
        $run = run_manager::get_by_tag('tx');
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('TX-202526 part 1: ', $run->error);
        // The half-made activity was deleted too, so a resume starts clean.
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
    }

    public function test_a_part_whose_worker_died_is_failed_not_waited_for(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('DEAD-202526');
        $run = $this->queued_run('dead');
        // What a killed worker leaves behind: the part says running, nobody holds its lock.
        $DB->set_field('tool_assessmentgen_part', 'status', 'running', ['runid' => $run->id]);
        $DB->set_field('tool_assessmentgen_run', 'status', 'running', ['id' => $run->id]);

        run_manager::refresh_status((int)$run->id);
        $part = $DB->get_record('tool_assessmentgen_part', ['runid' => $run->id, 'courseid' => $course->id, 'part' => 0]);
        $this->assertSame('failed', $part->status);
        $this->assertStringContainsString('worker ended without recording a result', $part->error);
        $run = run_manager::get_by_tag('dead');
        $this->assertSame('failed', $run->status, 'Nothing is running, so the run can be resumed');
        $this->assertStringContainsString('Part 1 of DEAD-202526 failed', $run->error);

        // A purge does not wait for it either.
        $DB->set_field('tool_assessmentgen_part', 'status', 'running', ['runid' => $run->id]);
        $start = time();
        $report = purger::purge($run, 30);
        $this->assertLessThan(10, time() - $start);
        $this->assertSame([], $report['errors']);
        $this->assertSame('purged', run_manager::get_by_tag('dead')->status);
    }

    public function test_a_running_part_whose_worker_holds_its_lock_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('LIVE-202526');
        $run = $this->queued_run('live');
        $DB->set_field('tool_assessmentgen_part', 'status', 'running', ['runid' => $run->id]);
        // As the worker itself sees it while it holds the part's lock.
        \tool_assessmentgen\local\locks::with(run_manager::part_lock((int)$run->id, (int)$course->id, 0), function () use ($run) {
            $this->assertSame(0, run_manager::reap_dead_parts((int)$run->id));
            $this->assertSame(['running' => 1], run_manager::part_counts((int)$run->id));
        });
        $this->assertSame(1, run_manager::reap_dead_parts((int)$run->id));
    }

    public function test_the_course_lock_is_held_while_an_assessment_is_built(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('LOCK-202526');
        $run = $this->queued_run('lock');
        testing::arm('addmodule', testing::FAULT_LOCKCHECK, 2);
        run_manager::execute_part((int)$run->id, (int)$course->id, 0);
        $this->assertSame(['courselockheld' => true], testing::observed('addmodule'));
        $this->assertFalse(\tool_assessmentgen\local\locks::held("course-{$course->id}"), 'Released afterwards');
        $this->assertSame('done', run_manager::get_by_tag('lock')->status);
    }

    public function test_a_database_write_error_is_retried_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('RETRY-202526');
        $run = $this->queued_run('retry');
        testing::arm('addmodule', testing::FAULT_DMLWRITE, 1);
        ob_start();
        try {
            run_manager::execute_part((int)$run->id, (int)$course->id, 0);
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('retrying AGEN-retry-', $output);
        $this->assertSame('done', run_manager::get_by_tag('retry')->status);
        // Two assessments, each once: the half-made first attempt was deleted.
        $this->assertSame(2, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(2, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
    }

    public function test_a_second_database_write_error_fails_the_part(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->make_course('TWICE-202526');
        $run = $this->queued_run('twice');
        testing::arm('addmodule', testing::FAULT_DMLWRITE, 2);
        ob_start();
        try {
            run_manager::execute_part((int)$run->id, (int)$course->id, 0);
            $this->fail('The second failure should have been thrown');
        } catch (\dml_write_exception $e) {
            $this->assertStringContainsString('fault: addmodule', $e->getMessage());
        } finally {
            ob_end_clean();
        }
        $run = run_manager::get_by_tag('twice');
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('fault: addmodule', $run->error);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    public function test_a_user_enrolled_by_another_worker_can_act_in_the_new_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'OTHER-202526']);
        $student = $gen->create_user();
        $context = \context_course::instance($course->id);
        $driver = new class extends \tool_assessmentgen\local\driver\assign {
            /**
             * Whether $userid can submit in $context, acting as them.
             *
             * @param int $userid
             * @param \context $context
             * @return bool
             */
            public function can_submit(int $userid, \context $context): bool {
                return $this->as_user($userid, fn() => has_capability('mod/assign:submit', $context));
            }
        };
        // This process has acted as the student, so their roles are cached: none in the course yet.
        $this->assertFalse($driver->can_submit((int)$student->id, $context));

        // Another worker enrols them: the rows appear, but nothing in this process is told. (Enrolling through core
        // here would clear this process's cache itself, which is exactly what another process cannot do.)
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $DB->insert_record('user_enrolments', (object)['enrolid' => $instance->id, 'userid' => $student->id, 'status' => 0,
            'timestart' => 0, 'timeend' => 0, 'modifierid' => 2, 'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('role_assignments', (object)['roleid' => $DB->get_field('role', 'id', ['shortname' => 'student']),
            'contextid' => $context->id, 'userid' => $student->id, 'timemodified' => time(), 'modifierid' => 2,
            'component' => '', 'itemid' => 0, 'sortorder' => 0]);
        $this->assertTrue($driver->can_submit((int)$student->id, $context));
    }
}
