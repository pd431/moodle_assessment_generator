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
 * Peer assessment (mod_peerwork) driver, written against mod_peerwork 4.5.0.5.
 *
 * Students act through peerwork_save(), the function the submission form calls, so each save stores the
 * student's peer ratings and (for the first member) the group's file. Teachers grade through
 * \mod_peerwork\group_grader, and release copies what release.php does.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peerwork extends base {
    /** @var string Name of the site scale peer criteria are rated on. */
    public const SCALENAME = 'Peer contribution (1-5)';

    /** @var string[] Criteria descriptions. */
    public const CRITERIA = [
        'Contributed ideas and effort to the group',
        'Met deadlines and did their share',
        'Communicated well and supported others',
    ];

    /**
     * Module name.
     *
     * @return string
     */
    public static function modname(): string {
        return 'peerwork';
    }

    /**
     * Peerwork needs its WebPA calculator as well as the module.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $CFG;
        return parent::is_available() && file_exists($CFG->dirroot . '/mod/peerwork/calculator/webpa/version.php');
    }

    /**
     * The 1-5 site scale used for peer criteria, created the first time it is needed.
     *
     * @return int scale id
     */
    public static function scale_id(): int {
        global $DB;
        $id = $DB->get_field('scale', 'id', ['courseid' => 0, 'name' => self::SCALENAME]);
        if ($id) {
            return (int)$id;
        }
        // Parallel parts could otherwise each create one.
        return \tool_assessmentgen\local\locks::with('scale', fn() => (int)($DB->get_field(
            'scale',
            'id',
            ['courseid' => 0, 'name' => self::SCALENAME]
        ) ?: self::create_scale()));
    }

    /**
     * Create the peer scale.
     *
     * @return int scale id
     */
    protected static function create_scale(): int {
        global $DB;
        return (int)$DB->insert_record('scale', (object)[
            'courseid' => 0,
            'userid' => get_admin()->id,
            'name' => self::SCALENAME,
            'scale' => '1 - Poor,2 - Below average,3 - Average,4 - Good,5 - Excellent',
            'description' => 'Created by the assessment data generator for peer assessment criteria.',
            'descriptionformat' => FORMAT_HTML,
            'timemodified' => time(),
        ]);
    }

    /**
     * Create the peer assessment and carry out the plan.
     *
     * @param \stdClass $course
     * @param array $a
     * @param options $options
     * @return array
     */
    public function execute(\stdClass $course, array $a, options $options): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerwork/locallib.php');

        $counts = ['cmid' => 0, 'submitted' => 0, 'drafts' => 0, 'grades' => 0, 'extensions' => 0];
        $mark = $this->log_mark();
        $created = $this->created_time($a, $options);
        $scaleid = self::scale_id();

        $criteria = [];
        for ($c = 0; $c < $a['criteria']; $c++) {
            $criteria[] = (object)[
                'description' => self::CRITERIA[$c],
                'descriptionformat' => FORMAT_HTML,
                'grade' => -$scaleid,
                'weight' => 1,
                'sortorder' => $c,
            ];
        }
        $cm = $this->add_module($course, $a, [
            'groupmode' => SEPARATEGROUPS,
            'groupingid' => $a['groupingid'] ?? 0,
            'pwgroupingid' => $a['groupingid'] ?? 0,
            'selfgrading' => $a['selfgrading'] ? 1 : 0,
            'fromdate' => $a['open'],
            'duedate' => $a['due'],
            'maxfiles' => 1,
            'allowlatesubmissions' => $a['allowlate'] ? 1 : 0,
            'justification' => $a['justification'] ? MOD_PEERWORK_JUSTIFICATION_HIDDEN : MOD_PEERWORK_JUSTIFICATION_DISABLED,
            'justificationtype' => MOD_PEERWORK_JUSTIFICATION_SUMMARY,
            'justificationmaxlength' => 0,
            'paweighting' => $a['paweighting'],
            'noncompletionpenalty' => $a['noncompletionpenalty'],
            'completiongradedpeers' => 0,
            'peergradesvisibility' => MOD_PEERWORK_PEER_GRADES_HIDDEN,
            'displaypeergradestotals' => 0,
            'lockediting' => 0,
            'calculator' => 'webpa',
            'assessmentcriteria' => $criteria,
            'grade' => 100,
        ]);
        $counts['cmid'] = (int)$cm->id;
        $logmark = $this->stamp_logs($mark, (int)$cm->id, $created);
        $context = \context_module::instance($cm->id);
        $peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);
        $critids = array_keys((new \mod_peerwork_criteria($peerwork->id))->get_criteria());

        foreach ($a['units'] as $u) {
            if ($u['status'] === 'none') {
                continue;
            }
            $group = $DB->get_record('groups', ['id' => $u['groupid']], '*', MUST_EXIST);
            // The submitter goes first, so their save creates the submission and stores the file.
            $raters = $u['raters'];
            uksort($raters, fn($x, $y) => ($y === $u['userid']) <=> ($x === $u['userid']));
            foreach ($raters as $raterid => $rater) {
                $this->as_user($raterid, function () use (
                    $peerwork,
                    $group,
                    $course,
                    $cm,
                    $context,
                    $critids,
                    $raterid,
                    $rater,
                    $u
) {
                    global $DB;
                    $submission = $DB->get_record('peerwork_submission', ['peerworkid' => $peerwork->id,
                        'groupid' => $group->id]);
                    $data = (object)['files' => 0, 'justifications' => []];
                    foreach ($critids as $c => $critid) {
                        $data->{'grade_idx_' . $critid} = $rater['ratings'][$c] ?? [];
                    }
                    $members = peerwork_get_peers($course, $peerwork, $peerwork->pwgroupingid, $group->id, $raterid);
                    foreach ($members as $member) {
                        $data->justifications[$member->id] = 'Rated on their contribution to the group.';
                    }
                    $draftitemid = $raterid === $u['userid'] ? $this->draft_file() : file_get_unused_draft_itemid();
                    if ($raterid === $u['userid']) {
                        $data->files = 1;
                    }
                    try {
                        peerwork_save(
                            $peerwork,
                            $submission ?: false,
                            $group,
                            $course,
                            $cm,
                            $context,
                            $data,
                            $draftitemid,
                            $members
                        );
                    } catch (\moodle_exception $e) {
                        // The function peerwork_save() throws after saving everything when the confirmation email fails.
                        if (strpos($e->getMessage(), 'no email sent') === false) {
                            throw $e;
                        }
                    }
                });
                $logmark = $this->stamp_logs($logmark, (int)$cm->id, $rater['time']);
            }
            $counts['submitted']++;

            if ($u['grade'] === null) {
                continue;
            }
            $this->as_user($u['marker'], function () use ($peerwork, $group, $u) {
                $grader = new \mod_peerwork\group_grader($peerwork, $group->id);
                $grader->set_grade($u['grade'], $peerwork->paweighting);
                $grader->set_feedback('<p>' . self::FEEDBACK[$u['feedback']] . '</p>', FORMAT_HTML, 0);
                $grader->set_revised_grades($u['revised'] ?: null);
                $grader->commit();
            });
            $logmark = $this->stamp_logs($logmark, (int)$cm->id, $u['gradetime']);
            $counts['grades'] += count($u['members']);
            if ($u['releasetime']) {
                $this->as_user($u['marker'], fn() => $this->release($peerwork, $context, $group->id));
                $logmark = $this->stamp_logs($logmark, (int)$cm->id, $u['releasetime']);
            }
        }

        $this->backdate($peerwork, $cm, $a, $created);
        $DB->set_field('peerwork', 'timemodified', $created, ['id' => $peerwork->id]);
        $DB->set_field('peerwork', 'timecreated', $created, ['id' => $peerwork->id]);
        $DB->set_field('course_modules', 'added', $created, ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);
        \core\event\course_module_updated::create_from_cm(get_fast_modinfo($course->id)->get_cm($cm->id))->trigger();
        $this->stamp_remaining_logs($mark, (int)$cm->id, $created);
        return $counts;
    }

    /**
     * Release a group's grade, as release.php does.
     *
     * @param \stdClass $peerwork
     * @param \context_module $context
     * @param int $groupid
     */
    protected function release(\stdClass $peerwork, \context_module $context, int $groupid): void {
        global $DB, $USER;
        $submission = $DB->get_record(
            'peerwork_submission',
            ['peerworkid' => $peerwork->id, 'groupid' => $groupid],
            '*',
            MUST_EXIST
        );
        $submission->released = time();
        $submission->releasedby = $USER->id;
        $DB->update_record('peerwork_submission', $submission);
        $event = \mod_peerwork\event\grades_released::create(['objectid' => $submission->id, 'context' => $context,
            'other' => ['groupid' => $groupid]]);
        $event->add_record_snapshot('peerwork_submission', $submission);
        $event->trigger();
        peerwork_update_grades($peerwork);
    }

    /**
     * Move submissions, ratings, grades and files to their planned times.
     *
     * @param \stdClass $peerwork
     * @param \cm_info $cm
     * @param array $a
     * @param int $created
     */
    protected function backdate(\stdClass $peerwork, \cm_info $cm, array $a, int $created): void {
        global $DB;
        $contextid = \context_module::instance($cm->id)->id;
        $gradeitemid = $DB->get_field('grade_items', 'id', ['courseid' => $cm->course, 'itemtype' => 'mod',
            'itemmodule' => 'peerwork', 'iteminstance' => $peerwork->id, 'itemnumber' => 0]);
        foreach ($a['units'] as $u) {
            if ($u['status'] === 'none') {
                continue;
            }
            $lastrating = max(array_column($u['raters'], 'time'));
            $submission = $DB->get_record(
                'peerwork_submission',
                ['peerworkid' => $peerwork->id, 'groupid' => $u['groupid']],
                '*',
                MUST_EXIST
            );
            $submission->timecreated = $u['time'];
            $submission->timemodified = $lastrating;
            if ($u['grade'] !== null) {
                $submission->timegraded = $u['gradetime'];
                $submission->gradedby = $u['marker'];
            }
            if ($u['releasetime']) {
                $submission->released = $u['releasetime'];
                $submission->releasedby = $u['marker'];
            }
            $DB->update_record('peerwork_submission', $submission);
            foreach ($u['raters'] as $raterid => $rater) {
                $DB->execute(
                    'UPDATE {peerwork_peers} SET timecreated = :t, timemodified = :t2
                               WHERE peerwork = :p AND groupid = :g AND gradedby = :u',
                    ['t' => $rater['time'], 't2' => $rater['time'], 'p' => $peerwork->id, 'g' => $u['groupid'],
                    'u' => $raterid]
                );
            }
            $DB->execute("UPDATE {files} SET timecreated = :t, timemodified = :t2
                           WHERE contextid = :ctx AND component = 'mod_peerwork' AND filearea = 'submission'
                             AND itemid = :g", ['t' => $u['time'], 't2' => $u['time'], 'ctx' => $contextid,
                                'g' => $u['groupid']]);
            if ($gradeitemid && $u['releasetime']) {
                [$insql, $params] = $DB->get_in_or_equal($u['members'], SQL_PARAMS_NAMED, 'm');
                $params += ['t' => $u['releasetime'], 'm' => $u['marker'], 'item' => $gradeitemid];
                $DB->execute("UPDATE {grade_grades} SET timemodified = :t, usermodified = :m
                               WHERE itemid = :item AND userid $insql", $params);
                $DB->execute("UPDATE {grade_grades_history} SET timemodified = :t, loggeduser = :m
                               WHERE itemid = :item AND userid $insql", $params);
            }
        }
    }
}
