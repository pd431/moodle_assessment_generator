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
use tool_assessmentgen\local\random;
use tool_assessmentgen\local\run_manager;
use tool_assessmentgen\local\student_pool;

/**
 * Tests for generated students: cohort sizes, the shared pool, the accounts and their purge.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\student_pool
 * @covers     \tool_assessmentgen\local\enroller
 * @covers     \tool_assessmentgen\local\run_manager
 */
final class students_test extends \advanced_testcase {
    /** @var int Fixed "today". */
    protected const ANCHOR = 1790000000;

    protected function setUp(): void {
        parent::setUp();
        enroller::reset_cache();
    }

    /**
     * Courses matching "202526", each with one teacher and $existing[$i] students.
     *
     * @param int[] $existing
     * @return array{courses: \stdClass[], students: int[]}
     */
    protected function make_courses(array $existing): array {
        $gen = $this->getDataGenerator();
        $courses = [];
        $students = [];
        foreach ($existing as $i => $n) {
            $course = $gen->create_course(['shortname' => "C$i-202526"]);
            $gen->create_and_enrol($course, 'editingteacher');
            for ($j = 0; $j < $n; $j++) {
                $students[] = (int)$gen->create_and_enrol($course, 'student')->id;
            }
            $courses[] = $course;
        }
        return ['courses' => $courses, 'students' => $students];
    }

    /**
     * Run options for these tests.
     *
     * @param array $values
     * @return options
     */
    protected function options(array $values = []): options {
        return new options($values + ['match' => '202526', 'seed' => 11, 'anchor' => self::ANCHOR, 'types' => ['assign'],
            'minper' => 1, 'maxper' => 1, 'duefrom' => -60, 'dueto' => -30, 'studentsmin' => 5, 'studentsmax' => 15,
            'studentsmean' => 9, 'tag' => 'gs']);
    }

    public function test_cohort_sizes_follow_range_and_mean(): void {
        $this->resetAfterTest();
        $options = $this->options(['studentsmin' => 60, 'studentsmax' => 400, 'studentsmean' => 150]);
        $sizes = [];
        for ($i = 0; $i < 4000; $i++) {
            $sizes[] = student_pool::cohort_size(random::for_key(1, "cohort:$i"), $options);
        }
        $this->assertGreaterThanOrEqual(60, min($sizes));
        $this->assertLessThanOrEqual(400, max($sizes));
        $this->assertEqualsWithDelta(150, array_sum($sizes) / count($sizes), 150 * 0.05);
        // Skewed like real courses: most are smaller than the mean.
        $this->assertGreaterThan(2000, count(array_filter($sizes, fn($n) => $n < 150)));
        $this->assertSame(
            student_pool::cohort_size(random::for_key(1, 'cohort:7'), $options),
            student_pool::cohort_size(random::for_key(1, 'cohort:7'), $options)
        );

        // A mean the range cannot produce is refused with the range that would work.
        try {
            $this->options(['studentsmin' => 60, 'studentsmax' => 400, 'studentsmean' => 400]);
            $this->fail('Expected the mean to be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('below the maximum', $e->debuginfo);
        }
        $this->expectException(\invalid_parameter_exception::class);
        $this->options(['studentsmin' => 10, 'studentsmax' => 20, 'pool' => 5]);
    }

    public function test_preview_tops_courses_up_from_a_shared_pool(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // One course already above the largest cohort, one part-full, the rest empty.
        ['courses' => $courses] = $this->make_courses([20, 4, 0, 0, 0, 0, 0, 0]);
        $preview = run_manager::preview($this->options());

        $byname = [];
        foreach ($preview['courses'] as $plan) {
            $byname[$plan['course']->shortname] = $plan;
        }
        $this->assertSame(20, $byname['C0-202526']['students']);
        $this->assertSame([], $byname['C0-202526']['enrolplan']['students']);
        $this->assertGreaterThanOrEqual(5, $byname['C1-202526']['students']);
        foreach ($byname as $plan) {
            $this->assertLessThanOrEqual(max(15, $plan['students']), $plan['students']);
            $this->assertGreaterThanOrEqual(5, $plan['students']);
        }
        // Eight courses with a mean of 9 make a pool of 18, so the pool is shared between courses.
        $this->assertSame(18, $preview['options']->pool);
        $this->assertSame(18, $preview['students']['create']);
        $this->assertGreaterThan(1.5, $preview['students']['mean']);
        $this->assertSame(count($courses), count($preview['courses']));
        $this->assertSame(0, (int)$GLOBALS['DB']->count_records('tool_assessmentgen_user'));
    }

    public function test_run_creates_safe_accounts_that_take_part_and_purge_deletes_them(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        ['courses' => $courses, 'students' => $existing] = $this->make_courses([3, 0, 0, 0]);
        $options = $this->options();
        $preview = run_manager::preview($options);
        $run = run_manager::create($options);
        $this->assertNotEmpty($run->password);
        $settings = $DB->get_field('tool_assessmentgen_run', 'settings', ['id' => $run->id]);
        $this->assertStringNotContainsString($run->password, $settings);
        run_manager::execute($run);

        $rows = $DB->get_records('tool_assessmentgen_user', ['runid' => $run->id], 'poolindex');
        $this->assertCount($preview['options']->pool, $rows);
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $generated = [];
        foreach ($rows as $row) {
            $user = $DB->get_record('user', ['id' => $row->userid], '*', MUST_EXIST);
            $this->assertSame(sprintf('agen-gs-%04d', $row->poolindex), $user->username);
            $this->assertSame($user->username . '@example.invalid', $user->email);
            $this->assertEquals(1, $user->emailstop);
            $this->assertSame('manual', $user->auth);
            $this->assertTrue(validate_internal_user_password($user, $run->password));
            $roles = $DB->get_fieldset_select('role_assignments', 'DISTINCT roleid', 'userid = ?', [$user->id]);
            $this->assertContains(count($roles), [0, 1]);
            if ($roles) {
                $this->assertEquals($studentrole, reset($roles));
            }
            $generated[] = (int)$user->id;
        }

        // Every course ends up with the planned cohort, generated students act like anyone else, and the
        // plan the preview showed is what was built.
        foreach ($preview['courses'] as $plan) {
            $people = course_selector::participants((int)$plan['course']->id);
            $this->assertCount($plan['students'], $people['students']);
        }
        $submitters = $DB->get_fieldset_sql("SELECT DISTINCT userid FROM {assign_submission}
            WHERE status = 'submitted' AND latest = 1");
        $this->assertNotEmpty(array_intersect(array_map('intval', $submitters), $generated));
        $this->assertSame($preview['totals']['submitted'], run_manager::totals((int)$run->id)['submitted']);
        $firstopen = min(array_map(fn($a) => $a['open'], array_merge(...array_column($preview['courses'], 'assessments'))));
        $this->assertLessThanOrEqual($firstopen - 7 * DAYSECS, (int)$DB->get_field_sql(
            "SELECT MAX(ue.timecreated) FROM {user_enrolments} ue JOIN {tool_assessmentgen_user} g ON g.userid = ue.userid"
        ));

        // Other runs never borrow these accounts as "existing" students.
        enroller::reset_cache();
        $this->assertSame([], array_intersect(course_selector::site_pools()['students'], $generated));

        // A second pass creates nothing new.
        $this->assertSame(0, student_pool::ensure((int)$run->id, run_manager::options_for($run)));

        run_manager::purge($DB->get_record('tool_assessmentgen_run', ['id' => $run->id]));
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_user'));
        [$insql, $params] = $DB->get_in_or_equal($generated);
        $this->assertSame(0, $DB->count_records_select('user', "id $insql AND deleted = 0", $params));
        foreach ($existing as $userid) {
            $this->assertEquals(0, $DB->get_field('user', 'deleted', ['id' => $userid]));
        }
        $this->assertCount(3, course_selector::participants((int)$courses[0]->id)['students']);
    }

    public function test_taken_username_stops_the_run_clearly(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->make_courses([0]);
        $this->getDataGenerator()->create_user(['username' => 'agen-gs-0002']);
        $run = run_manager::create($this->options());
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('agen-gs-0002');
        run_manager::execute($run);
    }
}
