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
use tool_assessmentgen\local\planner;
use tool_assessmentgen\local\run_manager;

/**
 * Tests for summative and formative tags.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\planner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\options::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\driver\base::class)]
final class tags_test extends \advanced_testcase {
    /** @var int Fixed "today". */
    protected const ANCHOR = 1790000000;

    /**
     * A course with a teacher and a few students.
     *
     * @return \stdClass
     */
    protected function make_course(): \stdClass {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'T-202526']);
        $gen->create_and_enrol($course, 'editingteacher');
        for ($i = 0; $i < 6; $i++) {
            $gen->create_and_enrol($course, 'student');
        }
        return $course;
    }

    public function test_tag_names_come_from_the_run_then_the_block_then_defaults(): void {
        $this->resetAfterTest();
        $o = new options(['match' => 'x']);
        $this->assertSame('Summative Assessment', $o->summativetag);
        $this->assertSame('Formative Assessment', $o->formativetag);

        set_config('certaintags', ' Summative Assessment B , summative', 'block_my_assessments');
        set_config('formativetags', 'formative,practice', 'block_my_assessments');
        $o = new options(['match' => 'x']);
        $this->assertSame('Summative Assessment B', $o->summativetag);
        $this->assertSame('formative', $o->formativetag);

        // The remake's own settings win over rc6's when both blocks are installed.
        set_config('certaintags', 'Assessed work', 'block_le_dashboard');
        $o = new options(['match' => 'x']);
        $this->assertSame('Assessed work', $o->summativetag);
        $this->assertSame('formative', $o->formativetag);

        $o = new options(['match' => 'x', 'summativetag' => 'Assessed', 'formativetag' => 'Practice']);
        $this->assertSame('Assessed', $o->summativetag);
        $this->assertSame('Practice', $o->formativetag);

        $this->expectException(\invalid_parameter_exception::class);
        new options(['match' => 'x', 'summativetag' => 'Same', 'formativetag' => 'same']);
    }

    public function test_formative_share_and_practice_quizzes(): void {
        $this->resetAfterTest();
        $course = $this->make_course();
        $formative = $total = 0;
        foreach (range(1, 40) as $seed) {
            $plan = (new planner(new options(['courseids' => [$course->id], 'seed' => $seed, 'minper' => 5,
                'maxper' => 5, 'anchor' => self::ANCHOR, 'enrol' => 'never'])))->plan_course((int)$course->id, ['assign', 'quiz']);
            foreach ($plan['assessments'] as $a) {
                $total++;
                $formative += $a['formative'] ? 1 : 0;
                $this->assertSame($a['formative'] ? 'Formative Assessment' : 'Summative Assessment', $a['assessmenttag']);
                if (str_starts_with($a['name'], 'Practice')) {
                    $this->assertTrue($a['formative']);
                }
            }
        }
        $this->assertGreaterThan(0.2, $formative / $total);
        $this->assertLessThan(0.45, $formative / $total);

        $plan = (new planner(new options(['courseids' => [$course->id], 'seed' => 1, 'minper' => 6, 'maxper' => 6,
            'formativerate' => 0, 'anchor' => self::ANCHOR])))->plan_course((int)$course->id, ['assign']);
        $this->assertSame([false], array_values(array_unique(array_column($plan['assessments'], 'formative'))));
    }

    public function test_generated_activities_carry_their_tag_and_purge_removes_their_tags(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->make_course();
        $record = ['courseids' => [$course->id], 'seed' => 4, 'tag' => 'tg', 'types' => ['assign', 'quiz'],
            'minper' => 4, 'maxper' => 4, 'anchor' => self::ANCHOR, 'formativerate' => 0.5];
        $gen = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen');
        $plan = $gen->plan($record)['courses'][0];
        $run = $gen->create_run($record);

        foreach ($plan['assessments'] as $a) {
            $cmid = $DB->get_field('course_modules', 'id', ['course' => $course->id, 'idnumber' => $a['idnumber']]);
            $this->assertSame(
                [$a['assessmenttag']],
                array_values(\core_tag_tag::get_item_tags_array('core', 'course_modules', $cmid))
            );
        }
        $lines = run_manager::course_report((int)$run->id, (int)$course->id);
        $this->assertMatchesRegularExpression('~\[(Summative|Formative) Assessment\]~', $lines[2]);

        run_manager::purge($run);
        $this->assertSame(0, $DB->count_records('tag_instance', ['itemtype' => 'course_modules']));
    }
}
