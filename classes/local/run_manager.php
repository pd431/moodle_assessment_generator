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
 * Creates, runs and purges generation runs.
 *
 * This is the API that the admin page, the CLI, the background task and test setup all call.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_manager {
    /**
     * Courses selected by the options.
     *
     * @param options $options
     * @return \stdClass[] keyed by id
     */
    public static function courses(options $options): array {
        if ($options->courseids) {
            return course_selector::by_ids($options->courseids);
        }
        return course_selector::find($options->match);
    }

    /**
     * Plan every selected course without writing anything.
     *
     * @param options $options
     * @param bool $keepunits keep each student's planned actions (tests need them; previews only need counts)
     * @return array{courses: array, totals: array, unavailable: array, students: ?array, staff: ?array,
     *     checks: array, options: options, create: int}
     */
    public static function preview(options $options, bool $keepunits = false): array {
        access::require_allowed();
        $types = registry::available($options->types);
        $matched = self::courses($options);
        $options = self::with_pool($options, count($matched));
        if (self::needs_setup($options)) {
            $matched += course_factory::placeholders($options);
        }
        $planner = new planner($options);
        $courses = [];
        $totals = [];
        $percourse = [];
        foreach ($matched as $course) {
            // Without $keepunits each assessment's units are dropped as soon as they are counted, so a preview
            // of hundreds of assessments on a large course fits a web request's memory limit.
            $plan = $types ? $planner->plan_course((int)$course->id, $types, null, $keepunits ? null : fn() => null) : [
                'courseid' => (int)$course->id, 'students' => 0, 'markers' => 0, 'groups' => 0,
                'assessments' => [], 'warnings' => ['notypes'],
            ];
            $plan['course'] = $course;
            $plan['summary'] ??= planner::summarise($plan);
            foreach ($plan['summary'] as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
            foreach ($plan['enrolplan']['students'] ?? [] as $userid) {
                $percourse[$userid] = ($percourse[$userid] ?? 0) + 1;
            }
            $courses[] = $plan;
        }
        $students = null;
        if (student_pool::enabled($options)) {
            $pool = student_pool::ids($options);
            $students = student_pool::spread(array_intersect_key($percourse, array_flip($pool)), $options->pool);
            $students['create'] = count(array_filter($pool, fn($id) => $id > student_pool::PLACEHOLDER));
        }
        $staff = null;
        if (student_pool::staff_enabled($options)) {
            $pool = student_pool::ids($options, true);
            $staff = ['pool' => $options->staffpool, 'create' => count(array_filter(
                $pool,
                fn($id) => $id > student_pool::STAFF_PLACEHOLDER
            )), 'courses' => count(array_filter($courses, fn($plan) => !empty($plan['enrolplan']['convenors'])))];
        }
        return ['courses' => $courses, 'totals' => $totals, 'unavailable' => registry::unavailable($options->types),
            'students' => $students, 'staff' => $staff, 'checks' => site_checks::run($options), 'options' => $options,
            'create' => self::needs_setup($options) ? $options->createcourses : 0];
    }

    /**
     * Options with the generated student and staff pools' sizes settled for a run over $courses existing courses
     * and the courses it will create.
     *
     * @param options $options
     * @param int $courses
     * @return options
     */
    protected static function with_pool(options $options, int $courses): options {
        if (self::needs_setup($options)) {
            $courses += $options->createcourses;
        }
        $values = [];
        if (student_pool::enabled($options) && !$options->pool) {
            $values['pool'] = student_pool::pool_size($options, $courses);
        }
        if (student_pool::staff_enabled($options) && !$options->staffpool) {
            $values['staffpool'] = student_pool::staff_pool_size($options, $courses);
        }
        return $values ? $options->with($values) : $options;
    }

    /**
     * Whether the run still has to create its courses.
     *
     * @param options $options
     * @return bool
     */
    public static function needs_setup(options $options): bool {
        return $options->createcourses > 0 && !$options->setupdone;
    }

    /**
     * Record a run. The course list is fixed now, so courses renamed later do not change the run.
     *
     * @param options $options
     * @return \stdClass run record
     */
    public static function create(options $options): \stdClass {
        global $DB;
        access::require_allowed();
        // A purged run's tag can be used again; its row is only history.
        $DB->delete_records('tool_assessmentgen_run', ['tag' => $options->tag, 'status' => 'purged']);
        if ($DB->record_exists('tool_assessmentgen_run', ['tag' => $options->tag])) {
            throw new \moodle_exception('tagexists', 'tool_assessmentgen', '', $options->tag);
        }
        if (!registry::available($options->types)) {
            throw new \moodle_exception('notypes', 'tool_assessmentgen');
        }
        $fatal = array_filter(site_checks::run($options), fn($check) => $check['level'] === site_checks::FATAL);
        if ($fatal) {
            throw new \moodle_exception('sitenotready', 'tool_assessmentgen', '', implode(' ', array_column($fatal, 'message')));
        }
        $courseids = array_map('intval', array_keys(self::courses($options)));
        $options = self::with_pool($options, count($courseids));
        $password = null;
        if ((student_pool::enabled($options) || student_pool::staff_enabled($options)) && !$options->passwordhash) {
            // One password for every account the run creates. Only its hash is kept, so it is shown once.
            $password = 'Agen-' . random_string(10) . '-1';
            $options = $options->with(['passwordhash' => hash_internal_user_password($password)]);
        }
        $values = $options->to_array();
        $values['courseids'] = $courseids;
        $now = time();
        $parts = self::parts_per_course($options);
        $run = (object)[
            'tag' => $options->tag,
            'seed' => $options->seed,
            'settings' => json_encode($values),
            'anchor' => $options->anchor,
            'status' => 'queued',
            'coursestotal' => (count($courseids) + (self::needs_setup($options) ? $options->createcourses : 0)) * $parts,
            'coursesdone' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $run->id = $DB->insert_record('tool_assessmentgen_run', $run);
        $rows = [];
        foreach ($courseids as $courseid) {
            for ($part = 0; $part < $parts; $part++) {
                $rows[] = ['runid' => $run->id, 'courseid' => $courseid, 'part' => $part, 'status' => 'queued',
                    'timemodified' => $now];
            }
        }
        $DB->insert_records('tool_assessmentgen_part', $rows);
        // Tags are site-wide; making them now saves parallel parts racing to create them.
        $collection = \core_tag_area::get_collection('core', 'course_modules');
        \core_tag_tag::create_if_missing($collection, [$options->summativetag, $options->formativetag]);
        // Not stored: the caller shows it once.
        $run->password = $password;
        return $run;
    }

    /**
     * How many parts (background tasks) each course is split into: enough for the most assessments a course
     * can get. Parts past a course's actual count finish at once.
     *
     * @param options $options
     * @return int
     */
    public static function parts_per_course(options $options): int {
        return max(1, (int)ceil($options->maxper / $options->partsize));
    }

    /**
     * Create the run's courses, add them to the run and create its accounts, once. Every course exists before any
     * part is queued, so parts never wait on each other's setup.
     *
     * @param \stdClass $run
     * @param callable|null $progress called with each course record as it is made
     * @return \stdClass the run, with its settings brought up to date
     */
    public static function setup(\stdClass $run, ?callable $progress = null): \stdClass {
        global $DB;
        access::require_allowed();
        $runid = (int)$run->id;
        locks::with("setup-$runid", function () use ($DB, $runid, $progress) {
            $run = $DB->get_record('tool_assessmentgen_run', ['id' => $runid], '*', MUST_EXIST);
            $options = self::options_for($run);
            if (!self::needs_setup($options) || !self::active($runid)) {
                return;
            }
            try {
                $created = course_factory::create($options, $progress);
                $values = $options->to_array();
                $values['courseids'] = array_values(array_unique(array_merge($options->courseids, $created)));
                $values['setupdone'] = true;
                $now = time();
                $parts = self::parts_per_course($options);
                foreach ($created as $courseid) {
                    for ($part = 0; $part < $parts; $part++) {
                        $key = ['runid' => $runid, 'courseid' => $courseid, 'part' => $part];
                        if (!$DB->record_exists('tool_assessmentgen_part', $key)) {
                            $DB->insert_record('tool_assessmentgen_part', (object)($key + ['status' => 'queued',
                                'timemodified' => $now]));
                        }
                    }
                }
                $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'settings' => json_encode($values),
                    'timemodified' => $now]);
                course_factory::reset_cache();
                // Accounts too, so the first parts do not all queue on the pool lock.
                student_pool::ensure($runid, options::from_json(json_encode($values)));
            } catch (\Throwable $e) {
                $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'status' => 'failed',
                    'error' => get_string('setupfailed', 'tool_assessmentgen', $e->getMessage()), 'timemodified' => time()]);
                throw $e;
            }
        });
        return $DB->get_record('tool_assessmentgen_run', ['id' => $runid], '*', MUST_EXIST);
    }

    /**
     * Queue the run's background work: its setup first if it creates courses (which then queues the parts), else one
     * task per unfinished part. Cron workers run parts in parallel, up to the site's adhoc task concurrency.
     *
     * @param \stdClass $run
     */
    public static function queue(\stdClass $run): void {
        global $DB;
        if (self::needs_setup(self::options_for($DB->get_record('tool_assessmentgen_run', ['id' => $run->id], '*', MUST_EXIST)))) {
            $task = new \tool_assessmentgen\task\setup_run();
            $task->set_custom_data(['runid' => (int)$run->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            self::refresh_status((int)$run->id);
            return;
        }
        $parts = $DB->get_records_select(
            'tool_assessmentgen_part',
            "runid = ? AND status <> 'done'",
            [$run->id],
            'courseid, part'
        );
        foreach ($parts as $part) {
            $task = new \tool_assessmentgen\task\generate_course();
            $task->set_custom_data(['runid' => (int)$run->id, 'courseid' => (int)$part->courseid, 'part' => (int)$part->part]);
            // An identical task still waiting from before a stop is reused rather than doubled.
            \core\task\manager::queue_adhoc_task($task, true);
        }
        self::refresh_status((int)$run->id);
    }

    /**
     * Run every course now, in this process. For the CLI and test setup. Stops when the run is stopped.
     *
     * @param \stdClass $run
     * @param callable|null $progress called with (course record, counts) after each course
     */
    public static function execute(\stdClass $run, ?callable $progress = null): void {
        $run = self::setup($run);
        if (self::needs_setup(self::options_for($run))) {
            // Stopped before its courses were made.
            return;
        }
        foreach (self::options_for($run)->courseids as $courseid) {
            $counts = self::execute_course((int)$run->id, (int)$courseid);
            if ($progress) {
                $progress(get_course($courseid), $counts);
            }
            if (!self::active((int)$run->id)) {
                break;
            }
        }
        self::refresh_status((int)$run->id);
    }

    /**
     * Generate every part of one course, one after another.
     *
     * @param int $runid
     * @param int $courseid
     * @return array<string, int> what was created
     */
    public static function execute_course(int $runid, int $courseid): array {
        global $DB;
        $totals = ['assessments' => 0, 'submitted' => 0, 'drafts' => 0, 'grades' => 0, 'extensions' => 0];
        $parts = $DB->get_fieldset_select(
            'tool_assessmentgen_part',
            'part',
            'runid = ? AND courseid = ? ORDER BY part',
            [$runid, $courseid]
        );
        foreach ($parts as $part) {
            foreach (self::execute_part($runid, $courseid, (int)$part) as $k => $v) {
                $totals[$k] += $v;
            }
        }
        return $totals;
    }

    /**
     * Whether a run may still build: not stopped, purging or purged.
     *
     * @param int $runid
     * @return bool
     */
    public static function active(int $runid): bool {
        global $DB;
        return in_array($DB->get_field('tool_assessmentgen_run', 'status', ['id' => $runid]), ['queued', 'running', 'failed']);
    }

    /**
     * Generate one part of a course: the assessments numbered part*partsize+1 to (part+1)*partsize. Safe to
     * retry, and safe to run alongside the course's other parts: assessments that exist are skipped, the course's
     * enrolments and groups are made once under a lock, and a part another worker is running is left to it.
     *
     * Between assessments the run's status is checked, so stopping or purging a run takes effect after the
     * assessment being built.
     *
     * @param int $runid
     * @param int $courseid
     * @param int $part
     * @return array<string, int> what was created
     */
    public static function execute_part(int $runid, int $courseid, int $part): array {
        global $DB;
        access::require_allowed();
        $totals = ['assessments' => 0, 'submitted' => 0, 'drafts' => 0, 'grades' => 0, 'extensions' => 0];
        $row = $DB->get_record('tool_assessmentgen_part', ['runid' => $runid, 'courseid' => $courseid, 'part' => $part]);
        if (!$row || $row->status === 'done' || !self::active($runid)) {
            return $totals;
        }
        locks::try("part-$runid-$courseid-$part", function () use ($DB, $runid, $courseid, $part, $row, &$totals) {
            $run = $DB->get_record('tool_assessmentgen_run', ['id' => $runid], '*', MUST_EXIST);
            $DB->set_field_select('tool_assessmentgen_run', 'status', 'running', "id = ? AND status = 'queued'", [$runid]);
            self::set_part($row->id, 'running');
            $options = self::options_for($run);
            $types = registry::available($options->types);
            $course = get_course($courseid);
            $first = $part * $options->partsize + 1;
            $last = ($part + 1) * $options->partsize;
            try {
                student_pool::ensure($runid, $options);
                // Each assessment is built as soon as it is planned, so only one is in memory at a time.
                $groups = [];
                $before = function (array $plan) use ($runid, $courseid, &$groups) {
                    $groups = locks::with("prep-$runid-$courseid", function () use ($runid, $courseid, $plan) {
                        enroller::enrol($runid, $courseid, $plan['enrolplan'], $plan['enroltime']);
                        return group_builder::create($courseid, $plan['groupplan']);
                    });
                };
                $each = function (array $assessment) use (
                    $DB,
                    $runid,
                    $courseid,
                    $course,
                    $options,
                    $first,
                    $last,
                    &$groups,
                    &$totals
) {
                    if ($assessment['index'] < $first) {
                        return;
                    }
                    if ($assessment['index'] > $last) {
                        // Later assessments belong to later parts; no need to plan them.
                        throw new stop_signal('partdone');
                    }
                    if (!self::active($runid)) {
                        throw new stop_signal('stopped');
                    }
                    $assessment = self::resolve_groups($assessment, $groups);
                    if ($DB->record_exists('course_modules', ['course' => $courseid, 'idnumber' => $assessment['idnumber']])) {
                        return;
                    }
                    // No outer transaction: the log store only writes events once the outermost transaction
                    // commits, which would be too late to backdate them. A failure deletes the half-made activity.
                    try {
                        // Submission receipts would put one notification per submission in students' inboxes.
                        $counts = settings_override::with(
                            ['assign' => ['submissionreceipts' => 0]],
                            fn() => registry::driver($assessment['type'])->execute($course, $assessment, $options)
                        );
                    } catch (\Throwable $e) {
                        self::delete_partial($courseid, $assessment['idnumber']);
                        throw $e;
                    }
                    $DB->insert_record('tool_assessmentgen_item', (object)[
                        'runid' => $runid,
                        'courseid' => $courseid,
                        'cmid' => $counts['cmid'],
                        'modname' => $assessment['type'],
                        'submitted' => $counts['submitted'],
                        'drafts' => $counts['drafts'],
                        'grades' => $counts['grades'],
                        'extensions' => $counts['extensions'],
                        'timecreated' => time(),
                    ]);
                    $totals['assessments']++;
                    foreach (['submitted', 'drafts', 'grades', 'extensions'] as $k) {
                        $totals[$k] += $counts[$k];
                    }
                };
                try {
                    (new planner($options))->plan_course($courseid, $types, $before, $each);
                    self::set_part($row->id, 'done');
                } catch (stop_signal $signal) {
                    self::set_part($row->id, $signal->getMessage() === 'stopped' ? 'stopped' : 'done');
                }
            } catch (\Throwable $e) {
                $message = $course->shortname . ' part ' . ($part + 1) . ': ' . $e->getMessage();
                self::set_part($row->id, 'failed', $message);
                $DB->set_field('tool_assessmentgen_run', 'error', $message, ['id' => $runid]);
                self::refresh_status($runid);
                throw $e;
            }
            self::refresh_status($runid);
        });
        return $totals;
    }

    /**
     * Stop a run: no part starts another assessment, and parts that are building one stop after it.
     * Everything made so far stays.
     *
     * @param \stdClass $run
     * @return bool whether the run was stopped (false if it had already finished, stopped or been purged)
     */
    public static function stop(\stdClass $run): bool {
        global $DB;
        access::require_allowed();
        if (!self::active((int)$run->id)) {
            return false;
        }
        $DB->set_field_select(
            'tool_assessmentgen_run',
            'status',
            'stopped',
            "id = ? AND status IN ('queued', 'running', 'failed')",
            [$run->id]
        );
        return true;
    }

    /**
     * Queue a stopped or failed run's unfinished parts again. Assessments already made are skipped.
     *
     * @param \stdClass $run
     */
    public static function resume(\stdClass $run): void {
        global $DB;
        access::require_allowed();
        $status = $DB->get_field('tool_assessmentgen_run', 'status', ['id' => $run->id]);
        if (!in_array($status, ['stopped', 'failed'])) {
            return;
        }
        $DB->set_field_select('tool_assessmentgen_part', 'status', 'queued', "runid = ? AND status <> 'done'", [$run->id]);
        $DB->update_record('tool_assessmentgen_run', (object)['id' => $run->id, 'status' => 'queued', 'error' => null,
            'timemodified' => time()]);
        self::queue($run);
    }

    /**
     * Parts of a run by status.
     *
     * @param int $runid
     * @return array<string, int>
     */
    public static function part_counts(int $runid): array {
        global $DB;
        return array_map('intval', $DB->get_records_sql_menu(
            'SELECT status, COUNT(1) FROM {tool_assessmentgen_part} WHERE runid = ? GROUP BY status',
            [$runid]
        ));
    }

    /**
     * Bring a run's progress and status up to date with its parts.
     *
     * @param int $runid
     */
    protected static function refresh_status(int $runid): void {
        global $DB;
        $counts = self::part_counts($runid);
        $done = $counts['done'] ?? 0;
        $total = array_sum($counts);
        $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'coursesdone' => $done,
            'coursestotal' => max($total, $DB->get_field('tool_assessmentgen_run', 'coursestotal', ['id' => $runid])),
            'timemodified' => time()]);
        $run = $DB->get_record('tool_assessmentgen_run', ['id' => $runid], 'id, status, settings');
        $status = $run->status;
        if (!in_array($status, ['queued', 'running', 'failed'])) {
            return;
        }
        if (self::needs_setup(self::options_for($run))) {
            // Its courses, and so most of its parts, do not exist yet.
            $new = $status === 'failed' ? 'failed' : ($done ? 'running' : 'queued');
        } else if ($done === $total) {
            $new = 'done';
        } else if (!empty($counts['failed']) && empty($counts['queued']) && empty($counts['running'])) {
            $new = 'failed';
        } else if (!empty($counts['running']) || $done || !empty($counts['failed'])) {
            $new = 'running';
        } else {
            $new = 'queued';
        }
        if ($new !== $status) {
            self::set_status($runid, $new);
        }
    }

    /**
     * Record a part's status.
     *
     * @param int $partid
     * @param string $status
     * @param string|null $error
     */
    protected static function set_part(int $partid, string $status, ?string $error = null): void {
        global $DB;
        $DB->update_record('tool_assessmentgen_part', (object)['id' => $partid, 'status' => $status, 'error' => $error,
            'timemodified' => time()]);
    }

    /**
     * Plain-text lines describing what a run made in one course, or one part of it, with links, for task logs and
     * the CLI.
     *
     * @param int $runid
     * @param int $courseid
     * @param int|null $part only this part's assessments (and enrolments and groups only for the first part)
     * @return string[]
     */
    public static function course_report(int $runid, int $courseid, ?int $part = null): array {
        global $DB;
        $run = $DB->get_record('tool_assessmentgen_run', ['id' => $runid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname', MUST_EXIST);
        $options = self::options_for($run);
        $lines = [];
        $heading = 'Course ' . $course->shortname . ' (' . $course->fullname . ')';
        if ($part !== null && self::parts_per_course($options) > 1) {
            $heading .= ' part ' . ($part + 1) . ' of ' . self::parts_per_course($options) . ', assessments ' .
                ($part * $options->partsize + 1) . '-' . (($part + 1) * $options->partsize);
        }
        $lines[] = $heading . ': ' . (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        if (in_array($run->status, ['purging', 'purged', 'purgeincomplete'])) {
            $lines[] = "  Skipped: run {$run->tag} was purged before this task ran.";
            return $lines;
        }
        if ($run->status === 'stopped') {
            $lines[] = "  Run {$run->tag} is stopped; resume it to finish this course.";
        }
        $enrolled = $DB->get_records_sql_menu('SELECT r.shortname, COUNT(1) FROM {tool_assessmentgen_enrol} e
            JOIN {role} r ON r.id = e.roleid WHERE e.runid = ? AND e.courseid = ? GROUP BY r.shortname', [$runid, $courseid]);
        $groups = $DB->count_records_select(
            'groups',
            'courseid = ? AND ' . $DB->sql_like('idnumber', '?'),
            [$courseid, group_builder::prefix($run->tag, $courseid) . 'g%']
        );
        $people = [];
        foreach ($enrolled as $role => $count) {
            $people[] = "$count as $role";
        }
        if (!$part) {
            $lines[] = '  Enrolled: ' . ($people ? implode(', ', $people) : 'nobody') . '. Groups made: ' . $groups . '.';
        }
        $items = $DB->get_records_sql(
            'SELECT i.*, cm.idnumber FROM {tool_assessmentgen_item} i
            JOIN {course_modules} cm ON cm.id = i.cmid WHERE i.runid = ? AND i.courseid = ? ORDER BY cm.idnumber',
            [$runid, $courseid]
        );
        if ($part !== null) {
            $first = $part * $options->partsize + 1;
            $last = ($part + 1) * $options->partsize;
            $items = array_filter($items, function ($item) use ($first, $last) {
                $index = (int)substr(strrchr($item->idnumber, '-'), 1);
                return $index >= $first && $index <= $last;
            });
        }
        if (!$items) {
            $lines[] = '  No activities.';
        }
        $modinfo = get_fast_modinfo($courseid);
        foreach ($items as $item) {
            $cm = $modinfo->get_cm($item->cmid);
            $tags = \core_tag_tag::get_item_tags_array('core', 'course_modules', $item->cmid);
            $lines[] = sprintf(
                '  %-8s [%s] %s: %s (submitted %d, in progress or draft %d, marked %d, extensions %d)',
                $item->modname,
                $tags ? implode(', ', $tags) : 'untagged',
                $cm->get_formatted_name(),
                $cm->url ? $cm->url->out(false) : '',
                $item->submitted,
                $item->drafts,
                $item->grades,
                $item->extensions
            );
        }
        return $lines;
    }

    /**
     * Swap planned group keys ("new:3") for the ids of the groups that were created.
     *
     * @param array $assessment
     * @param array $groups from group_builder::create()
     * @return array
     */
    protected static function resolve_groups(array $assessment, array $groups): array {
        $assessment['groupingid'] = $assessment['grouping'] === 'new' ? $groups['groupingid'] : 0;
        foreach ($assessment['units'] as $i => $unit) {
            if ($unit['groupid'] !== 0) {
                $assessment['units'][$i]['groupid'] = $groups['map'][$unit['groupid']];
            }
        }
        return $assessment;
    }

    /**
     * Purge a run now, in this process (CLI and tests). See purger::purge().
     *
     * @param \stdClass $run
     * @return int number of activities deleted
     */
    public static function purge(\stdClass $run): int {
        return purger::purge($run)['activities'];
    }

    /**
     * Delete an activity a failed driver left behind, so a retry starts clean.
     *
     * @param int $courseid
     * @param string $idnumber
     */
    protected static function delete_partial(int $courseid, string $idnumber): void {
        global $DB;
        $cmid = $DB->get_field('course_modules', 'id', ['course' => $courseid, 'idnumber' => $idnumber]);
        if ($cmid) {
            settings_override::with(['tool_recyclebin' => ['coursebinenable' => 0]], fn() => purger::delete_cm((int)$cmid));
        }
    }

    /**
     * A run by tag.
     *
     * @param string $tag
     * @return \stdClass|null
     */
    public static function get_by_tag(string $tag): ?\stdClass {
        global $DB;
        return $DB->get_record('tool_assessmentgen_run', ['tag' => $tag]) ?: null;
    }

    /**
     * Totals for a run from the activities it created.
     *
     * @param int $runid
     * @return array<string, int>
     */
    public static function totals(int $runid): array {
        global $DB;
        $row = $DB->get_record_sql('SELECT COUNT(1) AS assessments, COALESCE(SUM(submitted), 0) AS submitted,
                                           COALESCE(SUM(drafts), 0) AS drafts, COALESCE(SUM(grades), 0) AS grades,
                                           COALESCE(SUM(extensions), 0) AS extensions
                                      FROM {tool_assessmentgen_item} WHERE runid = :runid', ['runid' => $runid]);
        return array_map('intval', (array)$row);
    }

    /**
     * The options a run was created with.
     *
     * @param \stdClass $run
     * @return options
     */
    public static function options_for(\stdClass $run): options {
        return options::from_json($run->settings);
    }

    /**
     * Change a run's status.
     *
     * @param int $runid
     * @param string $status
     */
    protected static function set_status(int $runid, string $status): void {
        global $DB;
        $DB->update_record('tool_assessmentgen_run', (object)['id' => $runid, 'status' => $status, 'timemodified' => time()]);
    }
}
