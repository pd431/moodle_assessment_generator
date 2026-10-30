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

use tool_assessmentgen\local\course_factory;
use tool_assessmentgen\local\options;
use tool_assessmentgen\local\purger;
use tool_assessmentgen\local\run_manager;
use tool_assessmentgen\local\site_checks;
use tool_assessmentgen\local\student_pool;
use tool_assessmentgen\task\generate_course;
use tool_assessmentgen\task\setup_run;

/**
 * Tests for runs on a brand-new site: created courses, generated staff, site checks, and purging all of it.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\course_factory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\site_checks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\enroller::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\run_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\purger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\task\setup_run::class)]
final class setup_test extends \advanced_testcase {
    /** @var int Fixed "today": 2026-09-21, in the 2026/27 academic year. */
    protected const ANCHOR = 1790000000;

    /**
     * Options for a run that creates three courses of four assessments.
     *
     * @param array $values
     * @return options
     */
    protected function options(array $values = []): options {
        return new options($values + ['createcourses' => 3, 'seed' => 33, 'anchor' => self::ANCHOR, 'minper' => 4,
            'maxper' => 4, 'partsize' => 2, 'types' => ['assign', 'quiz'], 'tag' => 'fresh']);
    }

    /**
     * Run queued tasks of one class, keeping their log output out of the test's.
     *
     * @param string $class
     * @return string what they printed
     */
    protected function run_tasks(string $class): string {
        ob_start();
        try {
            $this->runAdhocTasks($class);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    /**
     * Short names of a course's users holding a role, by role.
     *
     * @param int $courseid
     * @return array<string, int[]>
     */
    protected function roles_in(int $courseid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT ra.id, r.shortname, ra.userid FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid WHERE ra.contextid = ?', [\context_course::instance($courseid)->id]);
        $roles = [];
        foreach ($rows as $row) {
            $roles[$row->shortname][] = (int)$row->userid;
        }
        return $roles;
    }

    public function test_a_site_with_no_courses_or_users_gets_everything_it_needs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame(1, $DB->count_records('course'));
        $usersbefore = $DB->count_records('user');

        $options = $this->options();
        // Created courses bring their own students and staff.
        $this->assertSame([20, 60, 35.0], [$options->studentsmin, $options->studentsmax, $options->studentsmean]);
        $this->assertSame([1, 3], [$options->markersmin, $options->markersmax]);
        $this->assertSame('202627', $options->term);

        $preview = run_manager::preview($options, true);
        $this->assertSame(3, $preview['create']);
        $this->assertCount(3, $preview['courses']);
        $this->assertSame(3, $preview['staff']['courses']);
        $this->assertGreaterThan(0, $preview['students']['create']);
        // The preview writes nothing.
        $this->assertSame(1, $DB->count_records('course'));
        $this->assertSame($usersbefore, $DB->count_records('user'));

        $run = run_manager::create($options);
        $password = $run->password;
        $this->assertNotEmpty($password);
        $this->assertSame(6, (int)$run->coursestotal);
        run_manager::queue($run);
        // Courses come first, in one task; parts are queued once they all exist.
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(setup_run::class));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(generate_course::class));
        $this->assertSame('queued', run_manager::get_by_tag('fresh')->status);
        $output = $this->run_tasks(setup_run::class);
        $this->assertStringContainsString('created course', $output);
        $this->assertCount(6, \core\task\manager::get_adhoc_tasks(generate_course::class));
        $this->run_tasks(generate_course::class);

        $run = run_manager::get_by_tag('fresh');
        $this->assertSame('done', $run->status, (string)$run->error);
        $courseids = course_factory::created('fresh');
        $this->assertCount(3, $courseids);
        $this->assertEquals($courseids, run_manager::options_for($run)->courseids);
        $category = $DB->get_record('course_categories', ['idnumber' => 'AGEN-fresh-category'], '*', MUST_EXIST);
        $this->assertSame('Generated courses (fresh)', $category->name);
        $this->assertSame(12, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));

        $staffprefix = student_pool::username($options, 0, false, true);
        foreach ($preview['courses'] as $n => $plan) {
            $course = $DB->get_record('course', ['idnumber' => sprintf('AGEN-fresh-course-%03d', $n + 1)], '*', MUST_EXIST);
            $this->assertEquals($category->id, $course->category);
            // Created as previewed.
            $this->assertSame($plan['course']->shortname, $course->shortname);
            $this->assertMatchesRegularExpression('/^[A-Z]{3}\d{3}-202627$/', $course->shortname);
            $this->assertMatchesRegularExpression('/^[A-Za-z ]+ \d{3} 202627$/', $course->fullname);
            $this->assertSame($plan['students'], count($this->roles_in((int)$course->id)['student']));
            $modinfo = get_fast_modinfo($course);
            foreach ($plan['assessments'] as $a) {
                $cm = $DB->get_record('course_modules', ['course' => $course->id,
                    'idnumber' => "AGEN-fresh-{$course->id}-{$a['index']}"], '*', MUST_EXIST);
                $this->assertSame($a['name'], $modinfo->get_cm($cm->id)->name);
                $this->assertLessThan($a['open'], (int)$course->startdate);
                $this->assertGreaterThan($a['due'], (int)$course->enddate);
            }

            // One convenor and one to three markers, all generated staff; students between 20 and 60.
            $roles = $this->roles_in((int)$course->id);
            $this->assertCount(1, $roles['editingteacher']);
            $this->assertGreaterThanOrEqual(1, count($roles['teacher']));
            $this->assertLessThanOrEqual(3, count($roles['teacher']));
            $this->assertGreaterThanOrEqual(20, count($roles['student']));
            $this->assertLessThanOrEqual(60, count($roles['student']));
            foreach (array_merge($roles['editingteacher'], $roles['teacher']) as $userid) {
                $user = $DB->get_record('user', ['id' => $userid]);
                $this->assertStringStartsWith($staffprefix, $user->username);
                $this->assertStringEndsWith('@example.invalid', $user->email);
                $this->assertEquals(1, $user->emailstop);
                $this->assertTrue(validate_internal_user_password($user, $password));
            }
        }
        // Staff never hold a student role, anywhere.
        $this->assertSame(0, (int)$DB->count_records_sql('SELECT COUNT(1) FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid JOIN {user} u ON u.id = ra.userid
            WHERE r.shortname = ? AND ' . $DB->sql_like('u.username', '?'), ['student', $staffprefix . '%']));
        $this->assertSame(
            run_manager::options_for($run)->staffpool,
            $DB->count_records('tool_assessmentgen_user', ['runid' => $run->id, 'kind' => 'staff'])
        );

        // Purge removes the courses, the category and every account.
        $report = purger::purge($run, 0);
        $this->assertSame([], $report['errors']);
        $this->assertSame(3, $report['courses']);
        $this->assertSame('purged', run_manager::get_by_tag('fresh')->status);
        $this->assertSame(1, $DB->count_records('course'));
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $category->id]));
        $this->assertSame(0, $DB->count_records_select('user', 'deleted = 0 AND ' .
            $DB->sql_like('username', '?'), ['agen-fresh-%']));
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_user', ['runid' => $run->id]));
    }

    public function test_courses_are_found_again_on_retry_and_inline_runs_set_up_first(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // A course elsewhere already uses one of the planned short names.
        $planned = course_factory::planned($this->options());
        $this->getDataGenerator()->create_course(['shortname' => $planned[2]['shortname']]);
        $replanned = course_factory::planned($this->options());
        $this->assertSame($planned[1]['shortname'], $replanned[1]['shortname']);
        $this->assertNotSame($planned[2]['shortname'], $replanned[2]['shortname']);

        $run = run_manager::create($this->options(['minper' => 1, 'maxper' => 1]));
        // Half-made by an earlier attempt.
        course_factory::create(run_manager::options_for($run));
        $this->assertCount(3, course_factory::created('fresh'));
        run_manager::execute($run);
        $run = run_manager::get_by_tag('fresh');
        $this->assertSame('done', $run->status, (string)$run->error);
        $this->assertCount(3, course_factory::created('fresh'));
        $this->assertSame(3, $DB->count_records('tool_assessmentgen_item', ['runid' => $run->id]));
    }

    public function test_a_run_stopped_before_its_courses_exist_makes_nothing_until_resumed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $run = run_manager::create($this->options(['minper' => 1, 'maxper' => 1]));
        run_manager::queue($run);
        $this->assertTrue(run_manager::stop($run));
        $this->run_tasks(setup_run::class);
        $this->assertSame([], course_factory::created('fresh'));
        $this->assertSame('stopped', run_manager::get_by_tag('fresh')->status);

        run_manager::resume($run);
        $this->run_tasks(setup_run::class);
        $this->run_tasks(generate_course::class);
        $this->assertSame('done', run_manager::get_by_tag('fresh')->status);
        $this->assertCount(3, course_factory::created('fresh'));
    }

    public function test_existing_courses_without_markers_get_generated_staff(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        // Someone who could be borrowed as a marker, if generated staff were not asked for.
        $other = $gen->create_course();
        $gen->create_and_enrol($other, 'teacher');
        $nomarkers = $gen->create_course(['shortname' => 'NM-202526']);
        $hasmarkers = $gen->create_course(['shortname' => 'HM-202526']);
        $teacher = $gen->create_and_enrol($hasmarkers, 'editingteacher');
        foreach ([$nomarkers, $hasmarkers] as $course) {
            for ($i = 0; $i < 6; $i++) {
                $gen->create_and_enrol($course, 'student');
            }
        }
        $options = new options(['match' => '202526', 'markersmin' => 2, 'markersmax' => 2, 'seed' => 5,
            'anchor' => self::ANCHOR, 'minper' => 1, 'maxper' => 1, 'types' => ['assign'], 'tag' => 'staff']);
        $run = run_manager::create($options);
        run_manager::execute($run);
        $this->assertSame('done', run_manager::get_by_tag('staff')->status);

        $roles = $this->roles_in((int)$nomarkers->id);
        $this->assertCount(1, $roles['editingteacher']);
        $this->assertCount(2, $roles['teacher']);
        $prefix = student_pool::username($options, 0, false, true);
        foreach (array_merge($roles['editingteacher'], $roles['teacher']) as $userid) {
            $this->assertStringStartsWith($prefix, $DB->get_field('user', 'username', ['id' => $userid]));
        }
        $this->assertSame(
            ['editingteacher' => [(int)$teacher->id], 'student' => $this->roles_in((int)$hasmarkers->id)['student']],
            $this->roles_in((int)$hasmarkers->id)
        );
        // No students were generated, and no courses created.
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_user', ['runid' => $run->id, 'kind' => 'student']));
        $this->assertSame([], course_factory::created('staff'));
    }

    public function test_site_checks_stop_a_run_the_site_cannot_support(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $checks = array_column(site_checks::run($this->options()), 'level', 'check');
        $this->assertArrayNotHasKey('manual', $checks);

        set_config('enrol_plugins_enabled', 'self');
        $checks = array_column(site_checks::run($this->options()), 'level', 'check');
        $this->assertSame(site_checks::FATAL, $checks['manual']);
        // Borrowing people for existing courses only warns.
        $checks = array_column(site_checks::run(new options(['match' => 'x', 'anchor' => self::ANCHOR])), 'level', 'check');
        $this->assertSame(site_checks::WARNING, $checks['manual']);
        $preview = run_manager::preview($this->options());
        $this->assertContains('manual', array_column($preview['checks'], 'check'));
        try {
            run_manager::create($this->options());
            $this->fail('A run that cannot enrol anyone was created');
        } catch (\moodle_exception $e) {
            $this->assertSame('sitenotready', $e->errorcode);
        }
        $this->assertNull(run_manager::get_by_tag('fresh'));
        // Nothing was switched on behind the person's back.
        $this->assertSame('self', get_config('core', 'enrol_plugins_enabled'));
    }
}
