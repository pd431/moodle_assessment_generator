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

namespace tool_assessmentgen\local\driver;

use tool_assessmentgen\local\options;

/**
 * Assignment (mod_assign) driver.
 *
 * The assignment is created with no dates so every submission is accepted, then the planned dates are written
 * once all submissions and marks exist. Lateness in mod_assign is worked out from the submission time and the
 * due date when it is displayed, so backdated submission times show as on time or late correctly.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign extends base {
    /**
     * Module name.
     *
     * @return string
     */
    public static function modname(): string {
        return 'assign';
    }

    /**
     * Create the assignment and carry out the plan.
     *
     * @param \stdClass $course
     * @param array $a
     * @param options $options
     * @return array
     */
    protected function execute(\stdClass $course, array $a, options $options): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $counts = ['cmid' => 0, 'submitted' => 0, 'drafts' => 0, 'grades' => 0, 'extensions' => 0];
        $mark = $this->log_mark();
        $created = $this->created_time($a, $options);

        $cm = $this->add_module($course, $a, [
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 1,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'sendlatenotifications' => 0,
            'sendstudentnotifications' => 0,
            'allowsubmissionsfromdate' => 0,
            'duedate' => 0,
            'cutoffdate' => 0,
            'gradingduedate' => 0,
            'grade' => 100,
            'teamsubmission' => $a['team'] ? 1 : 0,
            'requireallteammemberssubmit' => 0,
            'teamsubmissiongroupingid' => $a['groupingid'] ?? 0,
            'preventsubmissionnotingroup' => 0,
            'blindmarking' => $a['blind'] ? 1 : 0,
            'hidegrader' => 0,
            'markingworkflow' => $a['workflow'] ? 1 : 0,
            'markingallocation' => $a['allocation'] ? 1 : 0,
            'markinganonymous' => 0,
            'attemptreopenmethod' => 'untilpass',
            'maxattempts' => 1,
            'activityformat' => 0,
            'timelimit' => 0,
            'submissionattachments' => 0,
            'assignsubmission_onlinetext_enabled' => $a['format'] !== 'file' ? 1 : 0,
            'assignsubmission_onlinetext_wordlimit' => 0,
            'assignsubmission_onlinetext_wordlimit_enabled' => 0,
            'assignsubmission_file_enabled' => $a['format'] !== 'text' ? 1 : 0,
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 0,
            'assignsubmission_file_filetypes' => '',
            'assignfeedback_comments_enabled' => 1,
            'assignfeedback_comments_commentinline' => 0,
        ]);
        $counts['cmid'] = (int)$cm->id;
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $admin = (int)get_admin()->id;

        // Marker allocation and extensions are teacher actions; extensions need mod/assign:grantextension,
        // which non-editing teachers lack by default, so they are granted as the site admin.
        foreach ($a['units'] as $u) {
            if ($u['allocatedmarker']) {
                foreach ($u['members'] as $memberid) {
                    self::allocate($assign, $memberid, $u['allocatedmarker']);
                }
            }
            if ($u['extension']) {
                $this->as_user($admin, fn() => $assign->save_user_extension($u['userid'], $u['extension']));
                $counts['extensions']++;
            }
        }

        foreach ($a['units'] as $u) {
            if ($u['status'] === 'none') {
                continue;
            }
            $this->as_user($u['userid'], function () use ($assign, $a, $u) {
                $data = (object)['userid' => $u['userid']];
                if ($a['format'] !== 'file') {
                    $data->onlinetext_editor = [
                        'text' => self::online_text($u['userid'], $a['name']),
                        'format' => FORMAT_HTML,
                        'itemid' => file_get_unused_draft_itemid(),
                    ];
                }
                if ($a['format'] !== 'text') {
                    $data->files_filemanager = $this->draft_file();
                }
                $notices = [];
                if (!$assign->save_submission($data, $notices)) {
                    throw new \moodle_exception('submissionfailed', 'tool_assessmentgen', '', implode(' ', $notices));
                }
                if ($u['status'] === 'submitted') {
                    if (!$assign->submit_for_grading((object)['userid' => $u['userid']], $notices)) {
                        throw new \moodle_exception('submissionfailed', 'tool_assessmentgen', '', implode(' ', $notices));
                    }
                }
            });
            $counts[$u['status'] === 'draft' ? 'drafts' : 'submitted']++;
        }

        foreach ($a['units'] as $u) {
            if ($u['grade'] === null) {
                continue;
            }
            $data = (object)[
                'grade' => $u['grade'],
                'attemptnumber' => -1,
                'addattempt' => 0,
                'sendstudentnotifications' => false,
                'assignfeedbackcomments_editor' => ['text' => self::FEEDBACK[$u['feedback']], 'format' => FORMAT_HTML],
            ];
            if ($a['workflow']) {
                $data->workflowstate = $u['workflowstate'];
            }
            if ($a['allocation'] && !self::multimarker()) {
                $data->allocatedmarker = $u['allocatedmarker'];
            }
            if ($a['team']) {
                $data->applytoall = 1;
            }
            // A fresh instance per grade: assign caches the workflow states the first grader may use and
            // silently drops any other state, so a later marker's "released" would be lost.
            $grade = fn() => (new \assign($context, $cm, $course))->save_grade($u['userid'], clone $data);
            $marker = $u['marker'];
            if (
                $a['workflow'] && !$this->as_user($marker, fn() => array_key_exists(
                    $u['workflowstate'],
                    (new \assign($context, $cm, $course))->get_marking_workflow_states_for_current_user()
                ))
            ) {
                // A non-editing teacher cannot release; do it as admin, as a convenor would.
                $marker = $admin;
            }
            try {
                $this->as_user($marker, $grade);
            } catch (\required_capability_exception $e) {
                // The site's marker role cannot grade; grade as admin and record the marker afterwards.
                $this->as_user($admin, $grade);
            }
            $counts['grades'] += count($u['members']);
        }

        // Blind-marked work only reaches the gradebook once identities are revealed, which happens after
        // marking is complete.
        if ($a['blind'] && $a['gradingdue'] < $options->anchor) {
            $this->as_user($admin, fn() => $assign->reveal_identities());
        }

        $this->backdate($assign, $cm, $a, $created);
        $this->finalise($course, $cm, $a, $created);
        $this->backdate_logs($mark, (int)$cm->id, $a, $created);
        return $counts;
    }

    /**
     * Whether this Moodle stores allocated markers in their own table (5.1 and later) rather than user flags.
     *
     * @return bool
     */
    public static function multimarker(): bool {
        return method_exists(\assign::class, 'update_allocated_markers');
    }

    /**
     * Allocate a marker to a student.
     *
     * @param \assign $assign
     * @param int $studentid
     * @param int $markerid
     */
    protected static function allocate(\assign $assign, int $studentid, int $markerid): void {
        if (self::multimarker()) {
            $assign->update_allocated_markers($studentid, [$markerid]);
            return;
        }
        $flags = $assign->get_user_flags($studentid, true);
        $flags->allocatedmarker = $markerid;
        $assign->update_user_flags($flags);
    }

    /**
     * Move submissions, grades, gradebook rows and files to their planned times and markers.
     *
     * @param \assign $assign
     * @param \cm_info $cm
     * @param array $a
     * @param int $created
     */
    protected function backdate(\assign $assign, \cm_info $cm, array $a, int $created): void {
        global $DB;
        $instanceid = (int)$cm->instance;
        $gradeitemid = $DB->get_field('grade_items', 'id', ['courseid' => $cm->course, 'itemtype' => 'mod',
            'itemmodule' => 'assign', 'iteminstance' => $instanceid, 'itemnumber' => 0]);
        $contextid = \context_module::instance($cm->id)->id;

        foreach ($a['units'] as $u) {
            if ($u['status'] === 'none') {
                continue;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($u['members'], SQL_PARAMS_NAMED, 'u');
            $params = $inparams + ['a' => $instanceid, 'g' => $u['groupid']];
            $where = $u['groupid']
                ? "assignment = :a AND ((userid = 0 AND groupid = :g) OR userid $insql)"
                : "assignment = :a AND groupid = :g AND userid $insql";
            $submissionids = $DB->get_fieldset_select('assign_submission', 'id', $where, $params);
            $DB->execute("UPDATE {assign_submission} SET timecreated = :c, timemodified = :t, timestarted = NULL
                           WHERE $where", $params + ['c' => $u['time'] - 600, 't' => $u['time']]);
            if ($submissionids) {
                [$fsql, $fparams] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED, 's');
                $DB->execute(
                    "UPDATE {files} SET timecreated = :t, timemodified = :t2
                               WHERE contextid = :ctx AND component = 'assignsubmission_file' AND itemid $fsql",
                    $fparams + ['t' => $u['time'], 't2' => $u['time'], 'ctx' => $contextid]
                );
            }
            if ($u['grade'] === null) {
                continue;
            }
            $DB->execute(
                "UPDATE {assign_grades} SET timecreated = :t, timemodified = :t2, grader = :m
                           WHERE assignment = :a AND userid $insql",
                $inparams + ['t' => $u['gradetime'], 't2' => $u['gradetime'], 'm' => $u['marker'], 'a' => $instanceid]
            );
            if ($gradeitemid) {
                $gparams = $inparams + ['t' => $u['gradetime'], 'm' => $u['marker'], 'item' => $gradeitemid];
                $DB->execute("UPDATE {grade_grades} SET timemodified = :t, usermodified = :m
                               WHERE itemid = :item AND userid $insql AND finalgrade IS NOT NULL", $gparams);
                $DB->execute("UPDATE {grade_grades_history} SET timemodified = :t, loggeduser = :m
                               WHERE itemid = :item AND userid $insql AND finalgrade IS NOT NULL", $gparams);
            }
        }
    }

    /**
     * Write the planned dates, refresh calendar events and announce the change.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param array $a
     * @param int $created
     */
    protected function finalise(\stdClass $course, \cm_info $cm, array $a, int $created): void {
        global $DB;
        $DB->update_record('assign', (object)[
            'id' => $cm->instance,
            'allowsubmissionsfromdate' => $a['open'],
            'duedate' => $a['due'],
            'cutoffdate' => $a['cutoff'],
            'gradingduedate' => $a['gradingdue'],
            'timemodified' => $created,
        ]);
        $DB->set_field('course_modules', 'added', $created, ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);
        [$course, $cm] = get_course_and_cm_from_cmid($cm->id, 'assign');
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $assign->update_calendar($cm->id);
        // Lets anything indexing assessments (such as the new dashboard) re-read this activity.
        \core\event\course_module_updated::create_from_cm($cm)->trigger();
    }
}
