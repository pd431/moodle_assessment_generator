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

namespace tool_assessmentgen\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider. The plugin records which enrolments a run made and which accounts it created, so purge can
 * remove them. Generated submissions, attempts and grades belong to the activities and are covered by their own providers.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tool_assessmentgen_enrol', [
            'userid' => 'privacy:metadata:enrol:userid',
            'courseid' => 'privacy:metadata:enrol:courseid',
            'timecreated' => 'privacy:metadata:enrol:timecreated',
        ], 'privacy:metadata:enrol');
        $collection->add_database_table('tool_assessmentgen_user', [
            'userid' => 'privacy:metadata:user:userid',
            'timecreated' => 'privacy:metadata:user:timecreated',
        ], 'privacy:metadata:user');
        return $collection;
    }

    /**
     * Course contexts where a run enrolled the user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql('SELECT ctx.id FROM {tool_assessmentgen_enrol} e
            JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :level
            WHERE e.userid = :userid', ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        $contextlist->add_from_sql('SELECT ctx.id FROM {tool_assessmentgen_user} u
            JOIN {context} ctx ON ctx.instanceid = u.userid AND ctx.contextlevel = :level
            WHERE u.userid = :userid', ['level' => CONTEXT_USER, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users a run enrolled in a course context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {tool_assessmentgen_enrol} WHERE courseid = ?',
                [$context->instanceid]
            );
        } else if ($context->contextlevel == CONTEXT_USER) {
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {tool_assessmentgen_user} WHERE userid = ?',
                [$context->instanceid]
            );
        }
    }

    /**
     * Export the enrolment records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $userid) {
                $row = $DB->get_record('tool_assessmentgen_user', ['userid' => $userid]);
                if ($row) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'tool_assessmentgen')],
                        (object)['createdbygenerator' => \core_privacy\local\request\transform::datetime($row->timecreated)]
                    );
                }
                continue;
            }
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $rows = $DB->get_records(
                'tool_assessmentgen_enrol',
                ['userid' => $userid, 'courseid' => $context->instanceid],
                'id',
                'id, timecreated'
            );
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'tool_assessmentgen')],
                (object)['enrolledbygenerator' => array_values(array_map(fn($r) => ['timecreated' =>
                    \core_privacy\local\request\transform::datetime($r->timecreated)], $rows))]
            );
        }
    }

    /**
     * Delete records for every user in a course.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->delete_records('tool_assessmentgen_enrol', ['courseid' => $context->instanceid]);
        } else if ($context->contextlevel == CONTEXT_USER) {
            $DB->delete_records('tool_assessmentgen_user', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete one user's records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $DB->delete_records('tool_assessmentgen_enrol', ['userid' => $contextlist->get_user()->id,
                    'courseid' => $context->instanceid]);
            } else if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $contextlist->get_user()->id) {
                $DB->delete_records('tool_assessmentgen_user', ['userid' => $context->instanceid]);
            }
        }
    }

    /**
     * Delete records for several users in a course.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_USER && in_array($context->instanceid, $userlist->get_userids())) {
            $DB->delete_records('tool_assessmentgen_user', ['userid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'tool_assessmentgen_enrol',
            "courseid = :courseid AND userid $insql",
            $params + ['courseid' => $context->instanceid]
        );
    }
}
