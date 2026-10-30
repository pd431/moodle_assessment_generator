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

namespace tool_assessmentgen\local;

/**
 * Removes everything a run made: the courses and category it created, activities (with their submissions, attempts,
 * questions, grades, files and events), groups, enrolments and generated accounts. Courses the run did not create,
 * other users and hand-made activities are never touched.
 *
 * Each thing is deleted on its own, so one that fails does not stop the rest; failures are listed on the run, which
 * is then "purge incomplete" and can be purged again to retry only what is left. Besides the records the run kept,
 * the course is swept for activities and groups carrying the run's ID numbers, so a half-made activity from an
 * interrupted part is caught too.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purger {
    /** @var int Longest wait, in seconds, for parts that are building an assessment to finish it. */
    public const WAIT = 900;

    /** @var int Failures listed on the run. */
    protected const SHOWN_ERRORS = 5;

    /**
     * Stop the run and queue its purge as a background task, for the admin page.
     *
     * @param \stdClass $run
     */
    public static function request(\stdClass $run): void {
        global $DB;
        access::require_allowed();
        $DB->update_record('tool_assessmentgen_run', (object)['id' => $run->id, 'status' => 'purging',
            'timemodified' => time()]);
        $task = new \tool_assessmentgen\task\purge_run();
        $task->set_custom_data(['runid' => (int)$run->id]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Purge a run now. The run is stopped first, and parts still building an assessment are given time to finish
     * it, so nothing is made after the purge.
     *
     * @param \stdClass $run
     * @param int $wait seconds to wait for running parts
     * @return array{courses: int, activities: int, groups: int, enrolments: int, accounts: int, errors: string[]}
     */
    public static function purge(\stdClass $run, int $wait = self::WAIT): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        access::require_allowed();
        \core_php_time_limit::raise();
        $runid = (int)$run->id;
        $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'status' => 'purging', 'timemodified' => time()]);
        self::wait_for_parts($runid, $wait);

        $options = run_manager::options_for($run);
        self::refresh_statistics();
        $report = ['courses' => 0, 'activities' => 0, 'groups' => 0, 'enrolments' => 0, 'accounts' => 0, 'errors' => []];
        $attempt = function (string $what, callable $fn) use (&$report): bool {
            try {
                $fn();
                return true;
            } catch (\Throwable $e) {
                $report['errors'][] = "$what: " . $e->getMessage();
                return false;
            }
        };

        $nobin = ['tool_recyclebin' => ['coursebinenable' => 0, 'categorybinenable' => 0]];
        settings_override::with($nobin, function () use ($DB, $run, $attempt, &$report) {
            // Courses the run created go first, with everything in them, so nothing below has to go one by one.
            foreach (course_factory::created($run->tag) as $courseid) {
                if ($attempt("course $courseid", fn() => self::delete_course($courseid))) {
                    $report['courses']++;
                }
            }
            $category = $DB->get_record('course_categories', ['idnumber' => course_factory::category_idnumber($run->tag)]);
            if ($category) {
                $attempt("category {$category->id}", function () use ($category) {
                    $category = \core_course_category::get($category->id, MUST_EXIST, true);
                    // Someone may have put their own courses in it since.
                    if (!$category->has_courses() && !$category->has_children()) {
                        $category->delete_full(false);
                    }
                });
            }
        });

        settings_override::with($nobin, function () use (
            $DB,
            $runid,
            $run,
            $options,
            $attempt,
            &$report
) {
            // Activities the run recorded, then any others carrying its ID numbers.
            $failed = [];
            foreach ($DB->get_records('tool_assessmentgen_item', ['runid' => $runid]) as $item) {
                if ($DB->record_exists('course_modules', ['id' => $item->cmid])) {
                    if (!$attempt("activity {$item->cmid}", fn() => self::delete_cm((int)$item->cmid))) {
                        $failed[$item->cmid] = true;
                        continue;
                    }
                    $report['activities']++;
                }
                $DB->delete_records('tool_assessmentgen_item', ['id' => $item->id]);
            }
            foreach ($options->courseids as $courseid) {
                foreach (self::swept('course_modules', 'course', $courseid, $run->tag, '\d+') as $cmid) {
                    if (empty($failed[$cmid]) && $attempt("activity $cmid", fn() => self::delete_cm($cmid))) {
                        $report['activities']++;
                    }
                }
            }
        });

        foreach ($options->courseids as $courseid) {
            foreach (self::swept('groups', 'courseid', $courseid, $run->tag, 'g\d+') as $groupid) {
                if ($attempt("group $groupid", fn() => groups_delete_group($groupid))) {
                    $report['groups']++;
                }
            }
            foreach (self::swept('groupings', 'courseid', $courseid, $run->tag, 'grouping') as $groupingid) {
                $attempt("grouping $groupingid", fn() => groups_delete_grouping($groupingid));
            }
        }

        $manual = enrol_get_plugin('manual');
        foreach ($DB->get_records('tool_assessmentgen_enrol', ['runid' => $runid]) as $row) {
            $instance = $DB->get_record('enrol', ['id' => $row->enrolid]);
            $enrolled = $instance && $DB->record_exists('user_enrolments', ['enrolid' => $instance->id,
                'userid' => $row->userid]);
            if ($enrolled) {
                $what = "enrolment of user {$row->userid} in course {$row->courseid}";
                if (!$attempt($what, fn() => $manual->unenrol_user($instance, $row->userid))) {
                    continue;
                }
                $report['enrolments']++;
            }
            $DB->delete_records('tool_assessmentgen_enrol', ['id' => $row->id]);
        }

        $accounts = $DB->get_records_menu('tool_assessmentgen_user', ['runid' => $runid], '', 'userid, id');
        foreach ([false, true] as $staff) {
            if (!($staff ? student_pool::staff_enabled($options) : student_pool::enabled($options))) {
                continue;
            }
            // Accounts made by a part that failed before recording them.
            $prefix = student_pool::username($options, 0, false, $staff);
            $sql = 'deleted = 0 AND ' . $DB->sql_like('username', ':prefix', false, false);
            foreach (
                $DB->get_records_select_menu(
                    'user',
                    $sql,
                    ['prefix' => $DB->sql_like_escape($prefix) . '%'],
                    '',
                    'id, username'
                ) as $userid => $username
            ) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '\d{4,}$/', $username)) {
                    $accounts[$userid] ??= 0;
                }
            }
        }
        foreach ($accounts as $userid => $rowid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if ($user) {
                if (!$attempt("account {$user->username}", fn() => delete_user($user))) {
                    continue;
                }
                $report['accounts']++;
            }
            if ($rowid) {
                $DB->delete_records('tool_assessmentgen_user', ['id' => $rowid]);
            }
        }
        student_pool::reset_cache();

        if ($report['errors']) {
            $shown = array_slice($report['errors'], 0, self::SHOWN_ERRORS);
            $more = count($report['errors']) - count($shown);
            $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'status' => 'purgeincomplete',
                'error' => implode("\n", $shown) . ($more ? "\n… and $more more" : ''), 'timemodified' => time()]);
        } else {
            $DB->delete_records('tool_assessmentgen_part', ['runid' => $runid]);
            $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'status' => 'purged', 'error' => null,
                'timemodified' => time()]);
        }
        return $report;
    }

    /**
     * On PostgreSQL, bring the planner's row counts for the quiz attempt tables up to date. Deleting a quiz deletes
     * its attempts with one nested query; if the tables were filled since they were last analysed (a big run
     * straight after a fresh install, before autovacuum catches up), the planner thinks they are empty and that
     * query takes minutes per quiz instead of milliseconds. Best effort: a purge works without it, only slower.
     */
    protected static function refresh_statistics(): void {
        global $DB;
        if ($DB->get_dbfamily() !== 'postgres') {
            return;
        }
        foreach (
            ['quiz_attempts', 'question_usages', 'question_attempts', 'question_attempt_steps',
                'question_attempt_step_data'] as $table
        ) {
            try {
                $DB->execute('ANALYZE {' . $table . '}');
            } catch (\Throwable $e) {
                debugging('Could not analyse ' . $table . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Delete a course the run created, without the progress output delete_course() prints.
     *
     * @param int $courseid
     */
    protected static function delete_course(int $courseid): void {
        ob_start();
        try {
            if (!delete_course($courseid, false)) {
                throw new \moodle_exception('cannotdeletecategorycourse', 'error', '', $courseid);
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Ids of rows in one course whose ID number is exactly one the run gives: AGEN-<tag>-<courseid>-<suffix>.
     *
     * @param string $table
     * @param string $coursefield
     * @param int $courseid
     * @param string $tag
     * @param string $suffix regular expression for what follows the prefix
     * @return int[]
     */
    protected static function swept(string $table, string $coursefield, int $courseid, string $tag, string $suffix): array {
        global $DB;
        $prefix = group_builder::prefix($tag, $courseid);
        $rows = $DB->get_records_select_menu(
            $table,
            "$coursefield = :courseid AND " . $DB->sql_like('idnumber', ':prefix'),
            ['courseid' => $courseid, 'prefix' => $DB->sql_like_escape($prefix) . '%'],
            '',
            'id, idnumber'
        );
        // LIKE alone would also match a run tagged "<tag>-<n>"; the exact pattern does not.
        $pattern = '/^' . preg_quote($prefix, '/') . $suffix . '$/';
        return array_map('intval', array_keys(array_filter($rows, fn($idnumber) => preg_match($pattern, $idnumber))));
    }

    /**
     * Wait for parts that are building an assessment to finish it. A part notices the purge between assessments.
     *
     * @param int $runid
     * @param int $wait seconds
     */
    protected static function wait_for_parts(int $runid, int $wait): void {
        global $DB;
        $until = time() + $wait;
        // A part marked running long ago belongs to a worker that died; it will not finish.
        $stale = time() - 2 * HOURSECS;
        while (
            time() < $until && $DB->record_exists_select(
                'tool_assessmentgen_part',
                "runid = ? AND status = 'running' AND timemodified > ?",
                [$runid, $stale]
            )
        ) {
            sleep(2);
        }
    }

    /**
     * Delete a course module now, through the course format actions where they exist (Moodle 5.2) or
     * course_delete_module(). If that fails, the course cache is rebuilt and it is tried once more: a stale cache
     * is the usual reason an activity that is otherwise fine will not delete.
     *
     * @param int $cmid
     */
    public static function delete_cm(int $cmid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $courseid = (int)$DB->get_field('course_modules', 'course', ['id' => $cmid]);
        if (!$courseid) {
            return;
        }
        $delete = method_exists(\core_courseformat\local\cmactions::class, 'delete')
            ? fn() => \core_courseformat\formatactions::cm($courseid)->delete($cmid)
            : fn() => course_delete_module($cmid);
        try {
            $delete();
        } catch (\Throwable $e) {
            if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
                return;
            }
            rebuild_course_cache($courseid, true);
            $delete();
        }
    }
}
