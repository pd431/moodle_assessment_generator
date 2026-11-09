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

use tool_assessmentgen\local\run_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_assessmentgen\local\purger::class)]
/**
 * Tests for what uninstalling the plugin leaves behind: nothing it made.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_assessmentgen\local\purger
 */
final class uninstall_test extends \advanced_testcase {
    public function test_uninstall_purges_every_run_first(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/admin/tool/assessmentgen/db/uninstall.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        foreach (['U1-202526', 'U2-202526'] as $shortname) {
            $course = $gen->create_course(['shortname' => $shortname]);
            $gen->create_and_enrol($course, 'editingteacher');
            for ($i = 0; $i < 4; $i++) {
                $gen->create_and_enrol($course, 'student');
            }
        }
        $plugingen = $gen->get_plugin_generator('tool_assessmentgen');
        $common = ['seed' => 3, 'types' => ['assign'], 'anchor' => 1790000000, 'minper' => 2, 'maxper' => 2,
            'duefrom' => -40, 'dueto' => -30, 'studentsmin' => 6, 'studentsmax' => 6, 'pool' => 10];
        $plugingen->create_run($common + ['match' => 'U1-202526', 'tag' => 'u1']);
        $plugingen->create_run($common + ['match' => 'U2-202526', 'tag' => 'u2']);
        $this->assertSame(4, $DB->count_records_select('course_modules', $DB->sql_like('idnumber', '?'), ['AGEN-u%']));
        $this->assertGreaterThan(0, $DB->count_records('tool_assessmentgen_user'));

        ob_start();
        try {
            $this->assertTrue(xmldb_tool_assessmentgen_uninstall());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $DB->count_records_select('course_modules', $DB->sql_like('idnumber', '?'), ['AGEN-u%']));
        $this->assertSame(0, $DB->count_records('tool_assessmentgen_user'));
        $this->assertSame(0, $DB->count_records_select('user', "deleted = 0 AND username LIKE 'agen%'"));
        $this->assertSame('purged', run_manager::get_by_tag('u1')->status);
        $this->assertSame('purged', run_manager::get_by_tag('u2')->status);
    }
}
