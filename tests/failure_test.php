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
use tool_assessmentgen\local\run_manager;
use tool_assessmentgen\local\testing;

/**
 * Tests for what happens when building an assessment fails on a busy database.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\run_manager
 * @covers     \tool_assessmentgen\local\transactions
 * @covers     \tool_assessmentgen\local\testing
 */
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
}
