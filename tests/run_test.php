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

use tool_assessmentgen\local\course_selector;
use tool_assessmentgen\local\options;
use tool_assessmentgen\local\planner;
use tool_assessmentgen\local\run_manager;

/**
 * Tests for planning, generating and purging runs.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\run_manager
 * @covers     \tool_assessmentgen\local\planner
 * @covers     \tool_assessmentgen\local\driver\assign
 */
final class run_test extends \advanced_testcase {
    /** @var int Fixed "today" so tests do not depend on the clock. */
    protected const ANCHOR = 1790000000;

    /**
     * A course with students, an editing teacher, a non-editing teacher and two groups.
     *
     * @param string $shortname
     * @param int $students
     * @return array{course: \stdClass, students: int[], teachers: int[]}
     */
    protected function make_course(string $shortname, int $students = 12): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => $shortname, 'fullname' => "Course $shortname", 'numsections' => 4]);
        $teachers = [];
        foreach (['editingteacher', 'teacher'] as $role) {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $course->id, $role);
            $teachers[] = (int)$u->id;
        }
        $g1 = $gen->create_group(['courseid' => $course->id]);
        $g2 = $gen->create_group(['courseid' => $course->id]);
        $ids = [];
        for ($i = 0; $i < $students; $i++) {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $course->id, 'student');
            $gen->create_group_member(['groupid' => $i % 2 ? $g1->id : $g2->id, 'userid' => $u->id]);
            $ids[] = (int)$u->id;
        }
        return ['course' => $course, 'students' => $ids, 'teachers' => $teachers];
    }

    /**
     * Turn on the standard log with no buffering, so backdating can be checked.
     */
    protected function enable_logs(): void {
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
    }

    public function test_course_match_checks_names_and_idnumber(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $gen->create_course(['fullname' => 'History 101 202526', 'shortname' => 'HIS101']);
        $gen->create_course(['fullname' => 'Maths', 'shortname' => 'MAT200-202526']);
        $gen->create_course(['fullname' => 'Sandbox', 'shortname' => 'SBX', 'idnumber' => 'TEST-202526']);
        $gen->create_course(['fullname' => 'History 101 202425', 'shortname' => 'HIS101-OLD']);

        $found = array_column(course_selector::find('202526'), 'shortname');
        sort($found);
        $this->assertSame(['HIS101', 'MAT200-202526', 'SBX'], $found);
        $this->assertSame([], course_selector::find(''));
    }

    public function test_participants_use_roles_and_active_enrolments(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        ['course' => $course, 'students' => $students] = $this->make_course('P1', 5);
        $suspended = $gen->create_user();
        $gen->enrol_user($suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $people = course_selector::participants((int)$course->id);
        $this->assertSame($students, $people['students']);
        $this->assertCount(2, $people['markers']);
    }

    public function test_same_seed_gives_same_plan(): void {
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('S1', 20);
        $plan = fn(int $seed) => (new planner(new options(['courseids' => [$course->id], 'seed' => $seed,
            'anchor' => self::ANCHOR])))->plan_course((int)$course->id, ['assign']);

        $this->assertSame($plan(42), $plan(42));
        $this->assertNotSame($plan(42), $plan(43));
    }

    public function test_plan_follows_rates_and_dates(): void {
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('R1', 400);
        $options = new options(['courseids' => [$course->id], 'seed' => 7, 'anchor' => self::ANCHOR, 'minper' => 8,
            'maxper' => 8, 'teamrate' => 0, 'duefrom' => -120, 'dueto' => -40]);
        $plan = (new planner($options))->plan_course((int)$course->id, ['assign']);

        $students = $submitted = $late = $drafts = $grades = $noext = 0;
        $marks = [];
        foreach ($plan['assessments'] as $a) {
            $this->assertGreaterThanOrEqual(self::ANCHOR - 120 * DAYSECS, $a['due']);
            $this->assertLessThanOrEqual(self::ANCHOR - 40 * DAYSECS, $a['due']);
            $opendays = ($a['due'] - $a['open']) / DAYSECS;
            $this->assertGreaterThanOrEqual(7, $opendays);
            $this->assertLessThanOrEqual(28, $opendays);
            foreach ($a['units'] as $u) {
                $students++;
                $submitted += $u['status'] === 'submitted' ? 1 : 0;
                $drafts += $u['status'] === 'draft' ? 1 : 0;
                // Extension holders are mostly late by design, so the late rate is measured without them.
                $late += $u['late'] && !$u['extension'] ? 1 : 0;
                $noext += $u['status'] === 'submitted' && !$u['extension'] ? 1 : 0;
                if ($u['grade'] !== null) {
                    $grades++;
                    $marks[] = $u['grade'];
                    $this->assertGreaterThan($u['time'], $u['gradetime']);
                    $this->assertLessThanOrEqual(self::ANCHOR, $u['gradetime']);
                }
                if ($u['time']) {
                    $this->assertGreaterThanOrEqual($a['open'], $u['time']);
                    $this->assertLessThanOrEqual(self::ANCHOR, $u['time']);
                    if ($a['cutoff'] && !$u['extension']) {
                        $this->assertLessThanOrEqual($a['cutoff'], $u['time']);
                    }
                    if ($u['extension']) {
                        $this->assertLessThanOrEqual($u['extension'], $u['time']);
                    }
                }
            }
        }
        // Everything is past due, so the rates apply in full.
        $this->assertEqualsWithDelta(0.8, $submitted / $students, 0.03);
        $this->assertEqualsWithDelta(0.05, $drafts / $students, 0.02);
        $this->assertEqualsWithDelta(0.1, $late / $noext, 0.04);
        // All work is more than 21 days past due, so nearly all of it is marked.
        $this->assertGreaterThan(0.94, $grades / $submitted);
        $this->assertEqualsWithDelta(62, array_sum($marks) / count($marks), 3);
        foreach ($marks as $mark) {
            $this->assertEquals(round($mark), $mark);
            $this->assertGreaterThanOrEqual(0, $mark);
            $this->assertLessThanOrEqual(100, $mark);
        }
    }

    public function test_future_assessments_have_no_activity(): void {
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('F1', 30);
        $options = new options(['courseids' => [$course->id], 'seed' => 3, 'anchor' => self::ANCHOR,
            'duefrom' => 40, 'dueto' => 90]);
        $plan = (new planner($options))->plan_course((int)$course->id, ['assign']);
        foreach ($plan['assessments'] as $a) {
            foreach ($a['units'] as $u) {
                $this->assertSame('none', $u['status']);
                $this->assertNull($u['grade']);
            }
        }
    }

    public function test_generate_creates_assignments_submissions_and_marks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_logs();
        ['course' => $course, 'students' => $students, 'teachers' => $teachers] = $this->make_course('G1', 24);
        $coursesbefore = $DB->count_records('course');
        $usersbefore = $DB->count_records('user');

        $record = ['courseids' => [$course->id], 'seed' => 11, 'tag' => 't1', 'types' => ['assign'], 'anchor' => self::ANCHOR,
            'minper' => 5, 'maxper' => 5, 'duefrom' => -60, 'dueto' => 10, 'workflowrate' => 0.5];
        $expected = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen')->plan($record);
        $run = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen')->create_run($record);

        $this->assertSame('done', $run->status);
        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertSame($usersbefore, $DB->count_records('user'));
        $this->assertSame(5, $run->totals['assessments']);

        $plan = $expected['courses'][0];
        $this->assertSame($expected['totals']['submitted'], $run->totals['submitted']);
        $this->assertSame($expected['totals']['drafts'], $run->totals['drafts']);

        $grademarker = 0;
        foreach ($plan['assessments'] as $a) {
            $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $a['idnumber']], '*', MUST_EXIST);
            $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
            $this->assertStringStartsWith('AGEN-t1-', $cm->idnumber);
            $this->assertEquals($a['due'], $assign->duedate);
            $this->assertEquals($a['open'], $assign->allowsubmissionsfromdate);
            $this->assertEquals($a['cutoff'], $assign->cutoffdate);
            $this->assertEquals(0, $assign->sendnotifications);
            $this->assertTrue($DB->record_exists('event', ['modulename' => 'assign', 'instance' => $assign->id,
                'eventtype' => 'due', 'timestart' => $a['due']]));

            $gradeitem = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
                'iteminstance' => $assign->id, 'itemnumber' => 0]);
            foreach ($a['units'] as $u) {
                $params = ['assignment' => $assign->id, 'userid' => $a['team'] ? 0 : $u['userid']];
                if ($a['team']) {
                    $params['groupid'] = $u['groupid'];
                }
                $submission = $DB->get_record('assign_submission', $params);
                if ($u['status'] === 'none') {
                    $this->assertTrue(!$submission || $submission->status === 'new');
                    continue;
                }
                $this->assertSame($u['status'] === 'draft' ? 'draft' : 'submitted', $submission->status);
                $this->assertEquals($u['time'], $submission->timemodified);
                $this->assertContains($u['userid'], $students);

                foreach ($u['members'] as $memberid) {
                    $grade = $DB->get_record('assign_grades', ['assignment' => $assign->id, 'userid' => $memberid]);
                    $gg = $gradeitem ? \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $memberid]) : null;
                    if ($u['grade'] === null) {
                        $this->assertTrue(!$grade || $grade->grade === null || (float)$grade->grade < 0);
                        continue;
                    }
                    $grademarker++;
                    $this->assertEquals($u['grade'], (float)$grade->grade);
                    $this->assertEquals($u['marker'], $grade->grader);
                    $this->assertContains((int)$grade->grader, $teachers);
                    $this->assertEquals($u['gradetime'], $grade->timemodified);
                    $released = !$a['workflow'] || $u['workflowstate'] === 'released';
                    if ($a['workflow']) {
                        $flags = $DB->get_record('assign_user_flags', ['assignment' => $assign->id, 'userid' => $memberid]);
                        $this->assertSame($u['workflowstate'], $flags->workflowstate, "Workflow state for {$a['name']}");
                    }
                    $visible = $gg && $gg->finalgrade !== null;
                    if ($a['blind'] && $a['gradingdue'] >= self::ANCHOR) {
                        $this->assertFalse($visible, 'Blind marks stay out of the gradebook until revealed');
                    } else {
                        $this->assertSame($released, $visible, "Gradebook visibility for {$a['name']}");
                    }
                }
            }

            // Nothing in the log for this activity is dated after the anchor.
            $this->assertSame(0, $DB->count_records_select(
                'logstore_standard_log',
                'contextinstanceid = :cm AND contextlevel = :lvl AND timecreated > :anchor',
                ['cm' => $cm->id, 'lvl' => CONTEXT_MODULE, 'anchor' => self::ANCHOR]
            ));
        }
        $this->assertGreaterThan(0, $grademarker, 'The fixed seed should produce some marks');
    }

    public function test_allocated_markers_mark_their_own_students(): void {
        global $DB;
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('A1', 20);
        $run = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen')->create_run([
            'courseids' => [$course->id], 'seed' => 5, 'types' => ['assign'], 'anchor' => self::ANCHOR,
            'minper' => 2, 'maxper' => 2,
            'workflowrate' => 1, 'allocationrate' => 1, 'teamrate' => 0, 'duefrom' => -60, 'dueto' => -30]);

        $items = $DB->get_records('tool_assessmentgen_item', ['runid' => $run->id]);
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $instance = $DB->get_field('course_modules', 'instance', ['id' => $item->cmid]);
            if (\tool_assessmentgen\local\driver\assign::multimarker()) {
                $allocated = $DB->get_records_menu(
                    'assign_allocated_marker',
                    ['assignment' => $instance],
                    '',
                    'student, marker'
                );
            } else {
                $allocated = $DB->get_records_menu(
                    'assign_user_flags',
                    ['assignment' => $instance],
                    '',
                    'userid, allocatedmarker'
                );
            }
            $this->assertCount(20, $allocated);
            foreach ($DB->get_records('assign_grades', ['assignment' => $instance]) as $grade) {
                $this->assertEquals($allocated[$grade->userid], $grade->grader);
            }
        }
    }

    public function test_purge_removes_only_that_run(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        ['course' => $course] = $this->make_course('X1', 6);
        $handmade = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $gen = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen');
        $one = $gen->create_run(['courseids' => [$course->id], 'tag' => 'one', 'seed' => 1, 'anchor' => self::ANCHOR]);
        $two = $gen->create_run(['courseids' => [$course->id], 'tag' => 'two', 'seed' => 2, 'anchor' => self::ANCHOR]);
        $twocms = $DB->get_fieldset_select('tool_assessmentgen_item', 'cmid', 'runid = ?', [$two->id]);
        $onecms = $DB->get_fieldset_select('tool_assessmentgen_item', 'cmid', 'runid = ?', [$one->id]);
        $users = $DB->count_records('user');

        $this->assertSame(count($onecms), run_manager::purge($one));

        foreach ($onecms as $cmid) {
            $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
        }
        foreach ($twocms as $cmid) {
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]));
        }
        $this->assertTrue($DB->record_exists('assign', ['id' => $handmade->id]));
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertSame($users, $DB->count_records('user'));
        $this->assertSame(0, $DB->count_records('tool_recyclebin_course', ['courseid' => $course->id]));
        $this->assertEquals(1, get_config('tool_recyclebin', 'coursebinenable'));
        $this->assertSame('purged', run_manager::get_by_tag('one')->status);
    }

    public function test_rerunning_a_course_does_not_duplicate(): void {
        global $DB;
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('D1', 4);
        $run = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen')->create_run([
            'courseids' => [$course->id], 'seed' => 9, 'anchor' => self::ANCHOR, 'minper' => 3, 'maxper' => 3]);
        run_manager::execute_course((int)$run->id, (int)$course->id);
        $this->assertSame(3, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
    }

    public function test_queue_creates_one_task_per_course(): void {
        $this->resetAfterTest();
        $this->make_course('Q1-202526', 3);
        $this->make_course('Q2-202526', 3);
        $this->setAdminUser();
        $run = run_manager::create(new options(['match' => '202526', 'seed' => 4]));
        run_manager::queue($run);
        $tasks = \core\task\manager::get_adhoc_tasks(\tool_assessmentgen\task\generate_course::class);
        $this->assertCount(2, $tasks);
        $this->expectOutputRegex('~tool_assessmentgen: Course Q1-202526 .*/course/view.php\?id=\d+~');
        $this->runAdhocTasks(\tool_assessmentgen\task\generate_course::class);
        $this->assertSame('done', run_manager::get_by_tag($run->tag)->status);
    }

    public function test_duplicate_tag_is_refused(): void {
        $this->resetAfterTest();
        $this->make_course('T1-202526', 2);
        run_manager::create(new options(['match' => '202526', 'tag' => 'same']));
        $this->expectException(\moodle_exception::class);
        run_manager::create(new options(['match' => '202526', 'tag' => 'same']));
    }

    public function test_streamed_plan_matches_full_plan(): void {
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('M1', 30);
        $options = new options(['courseids' => [$course->id], 'seed' => 5, 'anchor' => self::ANCHOR, 'minper' => 12,
            'maxper' => 12]);
        $full = (new planner($options))->plan_course((int)$course->id, ['assign', 'quiz']);

        $seen = [];
        $beforecalls = 0;
        $streamed = (new planner($options))->plan_course(
            (int)$course->id,
            ['assign', 'quiz'],
            function (array $plan) use (&$beforecalls, &$seen) {
                $beforecalls++;
                $this->assertSame([], $seen);
                $this->assertSame([], $plan['assessments']);
            },
            function (array $a) use (&$seen) {
                $seen[] = $a;
            }
        );

        $this->assertSame(1, $beforecalls);
        $this->assertSame($full['assessments'], $seen);
        $this->assertSame(planner::summarise($full), $streamed['summary']);
        foreach ($streamed['assessments'] as $a) {
            $this->assertSame([], $a['units']);
            // Enrolment comes first, so it must be dated before any assessment opens without seeing them.
            $this->assertLessThanOrEqual($a['open'] - 7 * DAYSECS, $streamed['enroltime']);
        }
    }
}
