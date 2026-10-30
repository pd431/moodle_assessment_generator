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

use tool_assessmentgen\local\group_builder;
use tool_assessmentgen\local\options;
use tool_assessmentgen\local\planner;
use tool_assessmentgen\local\registry;
use tool_assessmentgen\local\run_manager;

/**
 * Tests for generated groups, peer assessment and quizzes.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\group_builder
 * @covers     \tool_assessmentgen\local\driver\peerwork
 * @covers     \tool_assessmentgen\local\driver\quiz
 */
final class types_test extends \advanced_testcase {
    /** @var int Fixed "today" so tests do not depend on the clock. */
    protected const ANCHOR = 1790000000;

    /**
     * A course with students, a teacher and optionally some groups.
     *
     * @param string $shortname
     * @param int $students
     * @param int $groups number of existing groups (students spread across them)
     * @return array{course: \stdClass, students: int[], teachers: int[]}
     */
    protected function make_course(string $shortname, int $students, int $groups = 0): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => $shortname, 'fullname' => "Course $shortname", 'numsections' => 3]);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $groupids = [];
        for ($g = 0; $g < $groups; $g++) {
            $groupids[] = $gen->create_group(['courseid' => $course->id])->id;
        }
        $ids = [];
        for ($i = 0; $i < $students; $i++) {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $course->id, 'student');
            if ($groupids) {
                $gen->create_group_member(['groupid' => $groupids[$i % $groups], 'userid' => $u->id]);
            }
            $ids[] = (int)$u->id;
        }
        return ['course' => $course, 'students' => $ids, 'teachers' => [(int)$teacher->id]];
    }

    /**
     * Turn on the standard log with no buffering, so backdating can be checked.
     */
    protected function enable_logs(): void {
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * Number of log rows for a course module dated after the anchor.
     *
     * @param int $cmid
     * @return int
     */
    protected function logs_after_anchor(int $cmid): int {
        global $DB;
        return $DB->count_records_select(
            'logstore_standard_log',
            'contextinstanceid = :cm AND contextlevel = :lvl AND timecreated > :anchor',
            ['cm' => $cmid, 'lvl' => CONTEXT_MODULE, 'anchor' => self::ANCHOR]
        );
    }

    /**
     * Run a generation as admin and return the run and its plan.
     *
     * @param array $record
     * @return array{0: \stdClass, 1: array}
     */
    protected function generate(array $record): array {
        $record += ['anchor' => self::ANCHOR];
        $gen = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen');
        $plan = $gen->plan($record)['courses'][0];
        return [$gen->create_run($record), $plan];
    }

    public function test_generated_groups_are_bounded_and_cover_every_student(): void {
        global $DB;
        $this->resetAfterTest();
        ['course' => $course, 'students' => $students, 'teachers' => $teachers] = $this->make_course('G-202526', 40);
        foreach ([1, 2, 3, 42] as $seed) {
            $options = new options(['courseids' => [$course->id], 'seed' => $seed, 'tag' => "s$seed",
                'anchor' => self::ANCHOR]);
            $groupplan = (new planner($options))->plan_course((int)$course->id, ['assign'])['groupplan'];
            $this->assertGreaterThanOrEqual(7, count($groupplan['new']));
            $this->assertLessThanOrEqual(13, count($groupplan['new']));
            foreach ($groupplan['new'] as $group) {
                $this->assertGreaterThanOrEqual(3, count($group['members']));
                $this->assertLessThanOrEqual(6, count($group['members']));
            }
        }

        [$run] = $this->generate(['courseids' => [$course->id], 'seed' => 42, 'tag' => 'g1', 'types' => ['assign'],
            'minper' => 1, 'maxper' => 1]);
        $groups = $DB->get_records('groups', ['courseid' => $course->id]);
        $members = $DB->get_records_sql('SELECT gm.userid, COUNT(1) AS n FROM {groups_members} gm
                JOIN {groups} g ON g.id = gm.groupid WHERE g.courseid = ? GROUP BY gm.userid', [$course->id]);
        $this->assertEqualsCanonicalizing($students, array_map('intval', array_keys($members)));
        foreach ($members as $m) {
            $this->assertEquals(1, $m->n);
        }
        foreach ($teachers as $teacherid) {
            $this->assertArrayNotHasKey($teacherid, $members);
        }
        foreach ($groups as $group) {
            $this->assertStringStartsWith('AGEN-g1-' . $course->id . '-g', $group->idnumber);
            $this->assertStringEndsWith('(g1)', $group->name);
        }
        $grouping = $DB->get_record('groupings', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame('Generated groups (g1)', $grouping->name);
        $this->assertCount(count($groups), $DB->get_records('groupings_groups', ['groupingid' => $grouping->id]));
        $this->assertSame('done', $run->status);
    }

    public function test_group_setting_decides_where_groups_are_made(): void {
        $this->resetAfterTest();
        ['course' => $a] = $this->make_course('A-202526', 12);
        ['course' => $b] = $this->make_course('B-202526', 12, 2);
        $expect = ['missing' => [true, false], 'always' => [true, true], 'never' => [false, false]];
        foreach ($expect as $setting => [$ina, $inb]) {
            $preview = run_manager::preview(new options(['match' => '202526', 'seed' => 1, 'groups' => $setting,
                'anchor' => self::ANCHOR]));
            $new = [];
            foreach ($preview['courses'] as $plan) {
                $new[$plan['courseid']] = count($plan['groupplan']['new']);
            }
            $this->assertSame($ina, $new[$a->id] > 0, "$setting, course without groups");
            $this->assertSame($inb, $new[$b->id] > 0, "$setting, course with groups");
        }
    }

    public function test_purge_removes_generated_groups_and_keeps_existing_ones(): void {
        global $DB;
        $this->resetAfterTest();
        ['course' => $a] = $this->make_course('A-202526', 12);
        ['course' => $b] = $this->make_course('B-202526', 12, 2);
        $before = $DB->get_records_sql('SELECT gm.id, gm.groupid, gm.userid FROM {groups_members} gm
                JOIN {groups} g ON g.id = gm.groupid WHERE g.courseid = ?', [$b->id]);
        $enrolments = $DB->count_records('user_enrolments');

        [$run] = $this->generate(['match' => '202526', 'seed' => 3, 'tag' => 'p1', 'groups' => 'missing',
            'types' => ['assign'], 'minper' => 2, 'maxper' => 2, 'teamrate' => 1]);
        $this->assertGreaterThan(0, $DB->count_records('groups', ['courseid' => $a->id]));
        // Team assignments in the course without groups use the generated grouping.
        $teamcms = $DB->get_records_select(
            'course_modules',
            'course = ? AND ' . $DB->sql_like('idnumber', '?'),
            [$a->id, 'AGEN-p1-%']
        );
        $usedgrouping = false;
        foreach ($teamcms as $cm) {
            $assign = $DB->get_record('assign', ['id' => $cm->instance]);
            if ($assign && $assign->teamsubmission) {
                $this->assertNotEquals(0, $assign->teamsubmissiongroupingid);
                $usedgrouping = true;
            }
        }
        $this->assertTrue($usedgrouping);

        run_manager::purge($run);
        $this->assertSame(0, $DB->count_records('groups', ['courseid' => $a->id]));
        $this->assertSame(0, $DB->count_records('groupings', ['courseid' => $a->id]));
        $after = $DB->get_records_sql('SELECT gm.id, gm.groupid, gm.userid FROM {groups_members} gm
                JOIN {groups} g ON g.id = gm.groupid WHERE g.courseid = ?', [$b->id]);
        $this->assertEquals($before, $after);
        $this->assertSame($enrolments, $DB->count_records('user_enrolments'));
    }

    public function test_peerwork_submissions_ratings_grades_and_release(): void {
        global $DB;
        if (!registry::available(['peerwork'])) {
            $this->markTestSkipped('mod_peerwork is not installed');
        }
        $this->resetAfterTest();
        $this->enable_logs();
        ['course' => $course, 'teachers' => $teachers] = $this->make_course('PW-202526', 24);
        [$run, $plan] = $this->generate(['courseids' => [$course->id], 'seed' => 8, 'tag' => 'pw', 'types' => ['peerwork'],
            'minper' => 3, 'maxper' => 3, 'duefrom' => -70, 'dueto' => -25]);
        $this->assertSame('done', $run->status, (string)$run->error);
        $this->assertSame(3, $run->totals['assessments']);

        $graded = $released = 0;
        foreach ($plan['assessments'] as $a) {
            $this->assertSame('peerwork', $a['type']);
            $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $a['idnumber']], '*', MUST_EXIST);
            $peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);
            $this->assertEquals($a['due'], $peerwork->duedate);
            $this->assertEquals($a['paweighting'], $peerwork->paweighting);
            $this->assertNotEquals(0, $peerwork->pwgroupingid);
            $criteria = $DB->get_records('peerwork_criteria', ['peerworkid' => $peerwork->id]);
            $this->assertCount($a['criteria'], $criteria);
            foreach ($criteria as $criterion) {
                $this->assertEquals(-\tool_assessmentgen\local\driver\peerwork::scale_id(), $criterion->grade);
            }
            $context = \context_module::instance($cm->id);
            $gradeitem = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'peerwork',
                'iteminstance' => $peerwork->id, 'itemnumber' => 0]);
            foreach ($a['units'] as $u) {
                // Units in the plan name generated groups by key; the run gave them ID numbers.
                if (!is_numeric($u['groupid'])) {
                    $u['groupid'] = (int)$DB->get_field('groups', 'id', ['courseid' => $course->id,
                        'idnumber' => 'AGEN-pw-' . $course->id . '-g' . substr($u['groupid'], 4)], MUST_EXIST);
                }
                $submission = $DB->get_record('peerwork_submission', ['peerworkid' => $peerwork->id,
                    'groupid' => $u['groupid']]);
                if ($u['status'] === 'none') {
                    $this->assertFalse($submission);
                    continue;
                }
                $this->assertEquals($u['time'], $submission->timecreated);
                $this->assertLessThanOrEqual(self::ANCHOR, $submission->timemodified);
                $files = get_file_storage()->get_area_files(
                    $context->id,
                    'mod_peerwork',
                    'submission',
                    $u['groupid'],
                    'id',
                    false
                );
                $this->assertCount(1, $files);
                foreach ($u['raters'] as $raterid => $rater) {
                    $rows = $DB->get_records('peerwork_peers', ['peerwork' => $peerwork->id, 'gradedby' => $raterid]);
                    $peers = count($u['members']) - ($a['selfgrading'] ? 0 : 1);
                    $this->assertCount($peers * $a['criteria'], $rows);
                    foreach ($rows as $row) {
                        $this->assertEquals($rater['time'], $row->timecreated);
                        if (!$a['selfgrading']) {
                            $this->assertNotEquals($raterid, $row->gradefor);
                        }
                    }
                }
                if ($u['grade'] === null) {
                    $this->assertEmpty($submission->timegraded);
                    continue;
                }
                $graded++;
                $this->assertEquals($u['grade'], (float)$submission->grade);
                $this->assertEquals($u['gradetime'], $submission->timegraded);
                $this->assertContains((int)$submission->gradedby, $teachers);
                $this->assertGreaterThanOrEqual(max(array_column($u['raters'], 'time')), $u['gradetime']);
                foreach ($u['members'] as $memberid) {
                    $gg = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $memberid]);
                    $visible = $gg && $gg->finalgrade !== null;
                    $this->assertSame((bool)$u['releasetime'], $visible);
                }
                $released += $u['releasetime'] ? 1 : 0;
            }
            $this->assertSame(0, $this->logs_after_anchor((int)$cm->id));
        }
        $this->assertGreaterThan(0, $graded);
        $this->assertGreaterThan(0, $released);
    }

    public function test_peerwork_is_skipped_without_groups(): void {
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('NG-202526', 10);
        $options = new options(['courseids' => [$course->id], 'seed' => 2, 'groups' => 'never', 'anchor' => self::ANCHOR]);
        $plan = (new planner($options))->plan_course((int)$course->id, ['peerwork', 'quiz']);
        $this->assertContains('nogroups', $plan['warnings']);
        $this->assertNotContains('peerwork', array_column($plan['assessments'], 'type'));
    }

    public function test_quiz_questions_attempts_marks_and_overrides(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_logs();
        ['course' => $course] = $this->make_course('QZ-202526', 30);
        [$run, $plan] = $this->generate(['courseids' => [$course->id], 'seed' => 4, 'tag' => 'qz', 'types' => ['quiz'],
            'minper' => 4, 'maxper' => 4, 'duefrom' => -60, 'dueto' => -25, 'extensionrate' => 0.2]);
        $this->assertSame('done', $run->status, (string)$run->error);

        $states = [];
        $essaysmarked = $overrides = 0;
        foreach ($plan['assessments'] as $a) {
            $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => $a['idnumber']], '*', MUST_EXIST);
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            $this->assertEquals($a['open'], $quiz->timeopen);
            $this->assertEquals($a['due'], $quiz->timeclose);
            $this->assertEquals($a['timelimit'], $quiz->timelimit);
            $this->assertEquals(count($a['questions']), $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
            $this->assertEquals(count($a['questions']), (float)$quiz->sumgrades);
            // Every question lives in a category of the quiz's own context.
            $contexts = $DB->get_fieldset_sql('SELECT DISTINCT qc.contextid FROM {quiz_slots} s
                    JOIN {question_references} r ON r.itemid = s.id AND r.component = ? AND r.questionarea = ?
                    JOIN {question_bank_entries} e ON e.id = r.questionbankentryid
                    JOIN {question_categories} qc ON qc.id = e.questioncategoryid
                   WHERE s.quizid = ?', ['mod_quiz', 'slot', $quiz->id]);
            $this->assertEquals([\context_module::instance($cm->id)->id], array_map('intval', $contexts));

            foreach ($a['units'] as $u) {
                $attempts = array_values($DB->get_records(
                    'quiz_attempts',
                    ['quiz' => $quiz->id, 'userid' => $u['userid']],
                    'attempt'
                ));
                $this->assertCount(count($u['attempts']), $attempts);
                if ($u['override']) {
                    $overrides++;
                    $this->assertEquals($u['override'], $DB->get_field(
                        'quiz_overrides',
                        'timeclose',
                        ['quiz' => $quiz->id, 'userid' => $u['userid']]
                    ));
                }
                foreach ($u['attempts'] as $n => $p) {
                    $attempt = $attempts[$n];
                    $states[$p['state']] = ($states[$p['state']] ?? 0) + 1;
                    $this->assertSame($p['state'], $attempt->state);
                    $this->assertEquals($p['start'], $attempt->timestart);
                    $this->assertGreaterThanOrEqual($a['open'], $attempt->timestart);
                    if ($p['state'] === 'finished') {
                        $this->assertEquals($p['finish'], $attempt->timefinish);
                    } else if ($p['state'] === 'abandoned') {
                        // Abandoning records no finish time, only the last change.
                        $this->assertEquals($p['finish'], $attempt->timemodified);
                    }
                    $this->assertLessThanOrEqual(self::ANCHOR, $attempt->timemodified);
                    if ($a['timelimit'] && $p['state'] === 'finished') {
                        $this->assertLessThanOrEqual($a['timelimit'], $attempt->timefinish - $attempt->timestart);
                    }
                    $maxstep = $DB->get_field_sql(
                        'SELECT MAX(qas.timecreated) FROM {question_attempt_steps} qas
                            JOIN {question_attempts} qa ON qa.id = qas.questionattemptid WHERE qa.questionusageid = ?',
                        [$attempt->uniqueid]
                    );
                    $this->assertLessThanOrEqual(self::ANCHOR, $maxstep);
                }
                if ($u['status'] !== 'finished') {
                    continue;
                }
                $last = end($attempts);
                $quizgrade = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $u['userid']]);
                if ($a['essayslot'] && $u['grade'] === null) {
                    $this->assertNull($last->sumgrades, 'Unmarked essays leave the attempt without a total');
                    continue;
                }
                $this->assertNotNull($last->sumgrades);
                $this->assertNotFalse($quizgrade);
                $this->assertLessThanOrEqual(self::ANCHOR, $quizgrade->timemodified);
                if ($a['essayslot']) {
                    $essaysmarked++;
                }
            }
            $this->assertSame(0, $this->logs_after_anchor((int)$cm->id));
        }
        $this->assertGreaterThan(0, $states['finished'] ?? 0);
        $this->assertGreaterThan(0, $overrides);
        if (array_filter(array_column($plan['assessments'], 'essayslot'))) {
            $this->assertGreaterThan(0, $essaysmarked);
        }
    }

    public function test_purge_removes_quizzes_and_their_questions(): void {
        global $DB;
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('QP-202526', 6);
        $questions = $DB->count_records('question');
        [$run] = $this->generate(['courseids' => [$course->id], 'seed' => 6, 'tag' => 'qp', 'types' => ['quiz'],
            'minper' => 2, 'maxper' => 2, 'duefrom' => -30, 'dueto' => -10]);
        $this->assertGreaterThan($questions, $DB->count_records('question'));
        run_manager::purge($run);
        $this->assertSame(0, $DB->count_records('quiz', ['course' => $course->id]));
        $this->assertSame($questions, $DB->count_records('question'));
    }

    public function test_allocated_markers_are_recorded_for_each_student(): void {
        global $DB;
        $this->resetAfterTest();
        ['course' => $course] = $this->make_course('AL-202526', 10);
        [$run, $plan] = $this->generate(['courseids' => [$course->id], 'seed' => 4, 'tag' => 'al', 'types' => ['assign'],
            'minper' => 2, 'maxper' => 2, 'workflowrate' => 1, 'allocationrate' => 1, 'teamrate' => 0, 'blindrate' => 0]);
        $this->assertSame(2, $run->totals['assessments']);
        $recorded = 0;
        foreach ($plan['assessments'] as $a) {
            $this->assertTrue($a['allocation']);
            $instance = $DB->get_field('course_modules', 'instance', ['course' => $course->id, 'idnumber' => $a['idnumber']],
                MUST_EXIST);
            foreach ($a['units'] as $u) {
                if (!$u['allocatedmarker']) {
                    continue;
                }
                if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
                    // Moodle 5.1 and later.
                    $this->assertTrue($DB->record_exists('assign_allocated_marker', ['assignment' => $instance,
                        'student' => $u['userid'], 'marker' => $u['allocatedmarker']]), "Marker of user {$u['userid']}");
                } else {
                    $this->assertEquals($u['allocatedmarker'], $DB->get_field('assign_user_flags', 'allocatedmarker',
                        ['assignment' => $instance, 'userid' => $u['userid']]), "Marker of user {$u['userid']}");
                }
                $recorded++;
            }
        }
        $this->assertGreaterThan(0, $recorded);
    }
}
