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
use tool_assessmentgen\local\enroller;
use tool_assessmentgen\local\options;
use tool_assessmentgen\local\planner;
use tool_assessmentgen\local\run_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\enroller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\course_selector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\run_manager::class)]
/**
 * Tests for who takes part, enrolling people into empty courses, and the run report.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\enroller
 * @covers     \tool_assessmentgen\local\course_selector
 * @covers     \tool_assessmentgen\local\run_manager
 */
final class enrol_test extends \advanced_testcase {
    /** @var int Fixed "today". */
    protected const ANCHOR = 1790000000;

    /**
     * A source course full of people, so the site has students and staff to draw on.
     *
     * @param int $students
     * @param int $teachers
     * @return array{students: int[], teachers: int[]}
     */
    protected function make_pool(int $students, int $teachers): array {
        $gen = $this->getDataGenerator();
        $source = $gen->create_course(['shortname' => 'SOURCE']);
        $ids = ['students' => [], 'teachers' => []];
        for ($i = 0; $i < $students; $i++) {
            $ids['students'][] = (int)$gen->create_and_enrol($source, 'student')->id;
        }
        for ($i = 0; $i < $teachers; $i++) {
            $ids['teachers'][] = (int)$gen->create_and_enrol($source, $i % 2 ? 'teacher' : 'editingteacher')->id;
        }
        return $ids;
    }

    /**
     * Ids of users enrolled in a course.
     *
     * @param int $courseid
     * @return int[]
     */
    protected function enrolled(int $courseid): array {
        $ids = course_selector::enrolled_any($courseid);
        sort($ids);
        return $ids;
    }

    public function test_guest_and_suspended_users_never_take_part(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'A-202526']);
        $gen->create_and_enrol($course, 'editingteacher');
        $real = $gen->create_and_enrol($course, 'student');
        $suspended = $gen->create_and_enrol($course, 'student');
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        // Enrolling sends a course welcome message, which the guest account cannot receive.
        $this->redirectMessages();
        enrol_get_plugin('manual')->enrol_user(
            $instance,
            $CFG->siteguest,
            $DB->get_field('role', 'id', ['shortname' => 'student'])
        );

        $this->assertSame([(int)$real->id], course_selector::participants((int)$course->id)['students']);

        $gen->get_plugin_generator('tool_assessmentgen')->create_run(['courseids' => [$course->id], 'seed' => 3,
            'types' => ['assign'], 'anchor' => self::ANCHOR, 'minper' => 2, 'maxper' => 2, 'duefrom' => -40,
            'dueto' => -30, 'submitrate' => 1, 'draftrate' => 0]);
        foreach ([$CFG->siteguest, $suspended->id] as $userid) {
            $this->assertFalse($DB->record_exists('assign_submission', ['userid' => $userid]));
            $this->assertFalse($DB->record_exists('groups_members', ['userid' => $userid]));
        }
        $this->assertTrue($DB->record_exists('assign_submission', ['userid' => $real->id, 'status' => 'submitted']));
    }

    public function test_site_pools_follow_roles(): void {
        global $CFG;
        $this->resetAfterTest();
        $pool = $this->make_pool(5, 2);
        $gen = $this->getDataGenerator();
        // Someone who is a student in one course and a teacher in another counts as staff only.
        $both = $gen->create_and_enrol($gen->create_course(), 'student');
        $gen->enrol_user($both->id, $gen->create_course()->id, 'teacher');
        // Someone holding a non-student, non-marker role is not a student.
        $manager = $gen->create_and_enrol($gen->create_course(), 'student');
        $gen->role_assign($gen->create_role(['shortname' => 'observer']), $manager->id);

        $pools = course_selector::site_pools();
        $this->assertEqualsCanonicalizing($pool['students'], $pools['students']);
        $this->assertEqualsCanonicalizing(array_merge($pool['teachers'], [(int)$both->id]), $pools['markers']);
        $this->assertNotContains((int)$CFG->siteguest, $pools['students']);
        $this->assertNotContains(2, $pools['markers'], 'Site admin is never picked');
    }

    public function test_empty_courses_are_topped_up_and_purge_removes_only_those_enrolments(): void {
        global $DB;
        $this->resetAfterTest();
        $this->make_pool(60, 4);
        $gen = $this->getDataGenerator();
        $empty = $gen->create_course(['shortname' => 'EMPTY-202526']);
        $full = $gen->create_course(['shortname' => 'FULL-202526']);
        $gen->create_and_enrol($full, 'editingteacher');
        for ($i = 0; $i < 5; $i++) {
            $gen->create_and_enrol($full, 'student');
        }
        $fullbefore = $this->enrolled((int)$full->id);
        enroller::reset_cache();

        $record = ['match' => '202526', 'seed' => 9, 'tag' => 'en', 'types' => ['assign'], 'anchor' => self::ANCHOR,
            'minper' => 2, 'maxper' => 2, 'duefrom' => -40, 'dueto' => -20];
        $plan = run_manager::preview(new options($record));
        $this->assertSame([], $this->enrolled((int)$empty->id), 'Preview writes nothing');
        $byid = array_column($plan['courses'], null, 'courseid');
        $this->assertSame(0, $byid[$full->id]['enrolled']);
        $this->assertGreaterThan(0, $byid[$empty->id]['enrolled']);

        $run = $gen->get_plugin_generator('tool_assessmentgen')->create_run($record);
        $this->assertSame($fullbefore, $this->enrolled((int)$full->id));
        $context = \context_course::instance($empty->id);
        $students = array_keys(get_role_users($DB->get_field('role', 'id', ['shortname' => 'student']), $context));
        $markers = array_keys(get_role_users($DB->get_field('role', 'id', ['shortname' => 'teacher']), $context));
        $this->assertGreaterThanOrEqual(15, count($students));
        $this->assertLessThanOrEqual(40, count($students));
        $this->assertGreaterThanOrEqual(1, count($markers));
        $this->assertLessThanOrEqual(3, count($markers));
        $earliestopen = min(array_column($byid[$empty->id]['assessments'], 'open'));
        foreach (
            $DB->get_records_sql('SELECT ue.* FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                WHERE e.courseid = ?', [$empty->id]) as $ue
        ) {
            $this->assertLessThan($earliestopen, $ue->timecreated);
        }
        // The new people took part.
        $this->assertGreaterThan(0, $DB->count_records_sql('SELECT COUNT(1) FROM {assign_submission} s
            JOIN {assign} a ON a.id = s.assignment WHERE a.course = ? AND s.status = ?', [$empty->id, 'submitted']));

        $users = $DB->count_records('user');
        run_manager::purge($run);
        $this->assertSame([], $this->enrolled((int)$empty->id));
        $this->assertSame($fullbefore, $this->enrolled((int)$full->id));
        $this->assertSame($users, $DB->count_records('user'));
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_enrol'));
    }

    public function test_enrol_never_adds_nobody(): void {
        $this->resetAfterTest();
        $this->make_pool(20, 2);
        $empty = $this->getDataGenerator()->create_course(['shortname' => 'EMPTY-202526']);
        enroller::reset_cache();
        $plan = (new planner(new options(['courseids' => [$empty->id], 'seed' => 1, 'enrol' => 'never',
            'anchor' => self::ANCHOR])))->plan_course((int)$empty->id, ['assign']);
        $this->assertSame(0, $plan['enrolled']);
        $this->assertContains('nostudents', $plan['warnings']);
    }

    public function test_report_lists_activities_with_links_and_purged_runs_say_so(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'R-202526']);
        $gen->create_and_enrol($course, 'editingteacher');
        $gen->create_and_enrol($course, 'student');
        $run = $gen->get_plugin_generator('tool_assessmentgen')->create_run(['courseids' => [$course->id], 'seed' => 2,
            'tag' => 'rep', 'types' => ['assign', 'quiz'], 'anchor' => self::ANCHOR, 'minper' => 2, 'maxper' => 2]);

        $lines = run_manager::course_report((int)$run->id, (int)$course->id);
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $lines[0]);
        $this->assertStringContainsString('Enrolled: nobody', $lines[1]);
        $this->assertCount(4, $lines);
        foreach (array_slice($lines, 2) as $line) {
            $this->assertMatchesRegularExpression('~/mod/(assign|quiz)/view\.php\?id=\d+ \(submitted \d+~', $line);
        }

        run_manager::purge($run);
        $lines = run_manager::course_report((int)$run->id, (int)$course->id);
        $this->assertStringContainsString('Skipped: run rep was purged', $lines[1]);
        $this->assertSame(0, run_manager::execute_course((int)$run->id, (int)$course->id)['assessments']);
    }
}
