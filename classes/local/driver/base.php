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
use tool_assessmentgen\local\planner;

/**
 * One activity type the generator can create, submit to and mark.
 *
 * Drivers go through the module's own API as the student or marker, so events, the gradebook and the
 * calendar stay consistent, then move the timestamps to the planned times.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var string[] Feedback lines, picked by the plan's feedback index. */
    public const FEEDBACK = [
        'Clear structure and a well-argued conclusion.',
        'Good use of sources; the analysis could go further.',
        'Meets the brief. Referencing needs attention.',
        'Strong work. The second section is especially convincing.',
        'Some good points, but the argument loses focus halfway.',
        'Methods are sound; results need more discussion.',
        'Well presented. Check the word count next time.',
        'A solid attempt. See the comments on structure.',
        'Excellent engagement with the reading.',
        'Needs a clearer research question.',
    ];

    /**
     * Module name, e.g. "assign".
     *
     * @return string
     */
    abstract public static function modname(): string;

    /**
     * Whether this type can be used on this site.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $DB;
        return $DB->record_exists('modules', ['name' => static::modname(), 'visible' => 1]);
    }

    /** @var int Longest wait, in seconds, for another part of the same course to finish its assessment. */
    public const COURSE_LOCK_WAIT = 1800;

    /**
     * Build one assessment: create the activity and carry out every unit of the plan, holding the course's lock
     * throughout.
     *
     * Parts of one course run in parallel cron workers. Every phase that writes grades (submitting a quiz attempt,
     * saving an assignment grade, releasing peer assessment grades, creating the activity's grade item) regrades
     * the whole course, and two regrades of one course at once fail on MariaDB with a duplicate key on
     * grade_grades or a deadlock in grade category aggregation. So one assessment is built at a time per course;
     * parts of different courses are unaffected.
     *
     * @param \stdClass $course
     * @param array $assessment one entry of planner::plan_course()['assessments']
     * @param options $options
     * @return array{cmid: int, submitted: int, drafts: int, grades: int, extensions: int}
     */
    final public function build(\stdClass $course, array $assessment, options $options): array {
        return \tool_assessmentgen\local\locks::with(
            "course-{$course->id}",
            fn() => $this->execute($course, $assessment, $options),
            self::COURSE_LOCK_WAIT
        );
    }

    /**
     * Create the activity and carry out every unit of the plan. Called by build(), which holds the course's lock.
     *
     * @param \stdClass $course
     * @param array $assessment one entry of planner::plan_course()['assessments']
     * @param options $options
     * @return array{cmid: int, submitted: int, drafts: int, grades: int, extensions: int}
     */
    abstract protected function execute(\stdClass $course, array $assessment, options $options): array;

    /**
     * Create the module with add_moduleinfo() in a random existing section.
     *
     * @param \stdClass $course
     * @param array $assessment
     * @param array $settings module-specific fields
     * @return \cm_info
     */
    protected function add_module(\stdClass $course, array $assessment, array $settings): \cm_info {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $lastsection = (int)course_get_format($course)->get_last_section_number();
        $section = $lastsection > 0 ? 1 + (abs(crc32($assessment['idnumber'])) % $lastsection) : 0;
        $moduleinfo = (object)array_merge([
            'modulename' => static::modname(),
            'module' => $DB->get_field('modules', 'id', ['name' => static::modname()], MUST_EXIST),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'name' => $assessment['name'],
            'intro' => '<p>Generated assessment. Submit your work before the due date.</p>',
            'introformat' => FORMAT_HTML,
            'cmidnumber' => $assessment['idnumber'],
            'groupmode' => $assessment['team'] ? SEPARATEGROUPS : NOGROUPS,
            'groupingid' => 0,
            'availability' => null,
            'completion' => 0,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
        ], $settings);
        // build() holds the course's lock, so parts of a run add modules to a course one at a time, which keeps
        // the course's section sequences and grade item order intact. Tags are shared site-wide, so they are
        // locked too.
        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        // Where a busy database fails while the activity is half made; tests arm faults here.
        \tool_assessmentgen\local\testing::fault('addmodule', ['courseid' => (int)$course->id]);
        if (!empty($assessment['assessmenttag'])) {
            // Set directly rather than through add_moduleinfo(), which skips tags when the site has them turned off.
            \tool_assessmentgen\local\locks::with('tags', fn() => \core_tag_tag::set_item_tags(
                'core',
                'course_modules',
                $moduleinfo->coursemodule,
                \context_module::instance($moduleinfo->coursemodule),
                [$assessment['assessmenttag']]
            ));
        }
        get_fast_modinfo($course->id, 0, true);
        return get_fast_modinfo($course->id)->get_cm($moduleinfo->coursemodule);
    }

    /**
     * Run code as another user, then switch back.
     *
     * @param int $userid
     * @param callable $fn
     * @return mixed what $fn returns
     */
    protected function as_user(int $userid, callable $fn) {
        global $USER;
        $previous = $USER;
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        if (isguestuser($user) || $user->deleted || $user->suspended) {
            throw new \moodle_exception('cannotactas', 'tool_assessmentgen', '', fullname($user) . " (id $userid)");
        }
        \core\session\manager::set_user($user);
        try {
            return $fn();
        } finally {
            \core\session\manager::set_user($previous);
        }
    }

    /**
     * When the activity should look like it was created: a couple of days before it opened.
     *
     * @param array $assessment
     * @param options $options
     * @return int
     */
    protected function created_time(array $assessment, options $options): int {
        return min($options->anchor, $assessment['open'] - 2 * planner::DAY);
    }

    /**
     * Flush buffered log writes and return the highest standard log id, so later rows can be backdated.
     *
     * @return int
     */
    protected function log_mark(): int {
        global $DB;
        get_log_manager(true);
        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            return -1;
        }
        return (int)$DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {logstore_standard_log}');
    }

    /**
     * Move this run's log rows for one course module to the planned times.
     *
     * Rows by a student take their submission time, rows about a student by someone else take the grade or
     * extension time, and everything left (creating and updating the activity) takes the creation time.
     *
     * @param int $mark from log_mark()
     * @param int $cmid
     * @param array $assessment
     * @param int $created
     */
    protected function backdate_logs(int $mark, int $cmid, array $assessment, int $created): void {
        global $DB;
        get_log_manager(true);
        if ($mark < 0) {
            return;
        }
        $base = 'id > :mark AND contextlevel = :level AND contextinstanceid = :cmid';
        $params = ['mark' => $mark, 'level' => CONTEXT_MODULE, 'cmid' => $cmid];
        foreach ($assessment['units'] as $u) {
            if ($u['extension']) {
                $DB->execute(
                    "UPDATE {logstore_standard_log} SET timecreated = :t
                                WHERE $base AND relateduserid = :uid AND eventname = :ev",
                    $params + ['t' => $u['extensiontime'], 'uid' => $u['userid'],
                    'ev' => '\\mod_' . static::modname() . '\\event\\extension_granted']
                );
            }
            if ($u['time']) {
                $DB->execute(
                    "UPDATE {logstore_standard_log} SET timecreated = :t WHERE $base AND userid = :uid",
                    $params + ['t' => $u['time'], 'uid' => $u['userid']]
                );
            }
            if ($u['gradetime']) {
                [$insql, $inparams] = $DB->get_in_or_equal($u['members'], SQL_PARAMS_NAMED, 'm');
                $DB->execute(
                    "UPDATE {logstore_standard_log} SET timecreated = :t
                                WHERE $base AND relateduserid $insql AND userid = :marker AND timecreated > :created",
                    $params + $inparams + ['t' => $u['gradetime'], 'marker' => $u['marker'], 'created' => $created]
                );
            }
        }
        // Anything not moved above still carries the real clock time, which is later than every planned time
        // except possibly the anchor itself.
        $DB->execute(
            "UPDATE {logstore_standard_log} SET timecreated = :t WHERE $base AND timecreated >= :runstart",
            $params + ['t' => $created, 'runstart' => $this->runstart]
        );
    }

    /**
     * A draft file area holding one placeholder file, owned by the current user.
     *
     * @return int draft item id
     */
    protected function draft_file(): int {
        global $USER;
        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'submission.txt',
        ], self::placeholder_file());
        return $itemid;
    }

    /**
     * Move the log rows written since a mark to one time, and return a fresh mark.
     *
     * Call it after each action that has its own planned time, so one person acting on several units keeps
     * a separate time for each.
     *
     * @param int $mark from log_mark()
     * @param int $cmid
     * @param int $time
     * @return int a new mark
     */
    protected function stamp_logs(int $mark, int $cmid, int $time): int {
        global $DB;
        $next = $this->log_mark();
        if ($mark < 0 || $next <= $mark) {
            return $next;
        }
        $DB->execute(
            'UPDATE {logstore_standard_log} SET timecreated = :t
              WHERE id > :mark AND id <= :next AND contextlevel = :level AND contextinstanceid = :cmid',
            ['t' => $time, 'mark' => $mark, 'next' => $next, 'level' => CONTEXT_MODULE, 'cmid' => $cmid]
        );
        return $next;
    }

    /**
     * Move every log row for a course module still carrying the real clock time to the creation time.
     *
     * @param int $mark from log_mark() at the start
     * @param int $cmid
     * @param int $created
     */
    protected function stamp_remaining_logs(int $mark, int $cmid, int $created): void {
        global $DB;
        get_log_manager(true);
        if ($mark < 0) {
            return;
        }
        $DB->execute(
            'UPDATE {logstore_standard_log} SET timecreated = :t
              WHERE id > :mark AND contextlevel = :level AND contextinstanceid = :cmid AND timecreated >= :runstart',
            ['t' => $created, 'mark' => $mark, 'level' => CONTEXT_MODULE, 'cmid' => $cmid, 'runstart' => $this->runstart]
        );
    }

    /** @var int Real time this driver started, to tell untouched log rows apart. */
    protected int $runstart = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->runstart = time();
    }

    /**
     * Placeholder file content. Identical for every submission, so file storage holds one copy.
     *
     * @return string
     */
    protected static function placeholder_file(): string {
        return "Generated submission from tool_assessmentgen.\nThis file is a placeholder and has no real content.\n";
    }

    /**
     * A few paragraphs of online text.
     *
     * @param int $userid
     * @param string $name
     * @return string
     */
    protected static function online_text(int $userid, string $name): string {
        return '<p>Submission for ' . s($name) . ' (generated for user ' . $userid . ').</p>' .
            '<p>This response outlines the question, reviews the main sources and sets out an argument.</p>' .
            '<p>The conclusion summarises the findings and notes limitations for further work.</p>';
    }
}
