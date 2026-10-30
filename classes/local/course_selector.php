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
 * Finds the courses, students and markers a run works on.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_selector {
    /**
     * Courses whose full name, short name or ID number contains the text (case-insensitive).
     *
     * @param string $match
     * @return \stdClass[] keyed by id, ordered by short name
     */
    public static function find(string $match): array {
        global $DB;
        $match = trim($match);
        if ($match === '') {
            return [];
        }
        $like = '%' . $DB->sql_like_escape($match) . '%';
        $where = [];
        $params = ['siteid' => SITEID];
        foreach (['fullname', 'shortname', 'idnumber'] as $i => $field) {
            $where[] = $DB->sql_like($field, ":m$i", false, false);
            $params["m$i"] = $like;
        }
        return $DB->get_records_select(
            'course',
            'id <> :siteid AND (' . implode(' OR ', $where) . ')',
            $params,
            'shortname ASC',
            'id, fullname, shortname, idnumber, visible'
        );
    }

    /**
     * Courses by id, skipping the site course and ids that do not exist.
     *
     * @param int[] $ids
     * @return \stdClass[]
     */
    public static function by_ids(array $ids): array {
        global $DB;
        $ids = array_diff(array_map('intval', $ids), [SITEID]);
        if (!$ids) {
            return [];
        }
        return $DB->get_records_list('course', 'id', $ids, 'shortname ASC', 'id, fullname, shortname, idnumber, visible');
    }

    /**
     * Role short names from a comma-separated setting.
     *
     * @param string $setting config name
     * @param string $default
     * @return string[]
     */
    protected static function roles(string $setting, string $default): array {
        $value = get_config('tool_assessmentgen', $setting);
        if ($value === false || trim($value) === '') {
            $value = $default;
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * User ids holding one of the roles in the course context, with an active enrolment.
     *
     * @param int $courseid
     * @param string[] $roleshortnames
     * @return int[] sorted ascending
     */
    protected static function users_with_roles(int $courseid, array $roleshortnames): array {
        global $DB;
        if (!$roleshortnames) {
            return [];
        }
        $context = \context_course::instance($courseid);
        [$insql, $params] = $DB->get_in_or_equal($roleshortnames, SQL_PARAMS_NAMED, 'r');
        [$esql, $eparams] = get_enrolled_sql($context, '', 0, true);
        // The guest account and suspended accounts never take part, even if a course has them enrolled.
        $sql = "SELECT DISTINCT ra.userid
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                   JOIN ($esql) e ON e.id = ra.userid
                   JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                  WHERE ra.contextid = :ctx AND r.shortname $insql AND u.id <> :guest
               ORDER BY ra.userid";
        $ids = $DB->get_fieldset_sql($sql, $params + $eparams + ['ctx' => $context->id, 'guest' => self::guest_id()]);
        return array_map('intval', $ids);
    }

    /**
     * The guest account's id.
     *
     * @return int
     */
    protected static function guest_id(): int {
        global $CFG;
        return (int)($CFG->siteguest ?? 0);
    }

    /**
     * People on the site who could be enrolled: students hold only student roles anywhere, markers hold at least
     * one marker role somewhere. The guest account, site admins, and suspended or deleted users are left out.
     *
     * @return array{students: int[], markers: int[]} sorted ascending
     */
    public static function site_pools(): array {
        global $CFG, $DB;
        $studentroles = self::roles('studentroles', 'student');
        $markerroles = self::roles('markerroles', 'editingteacher,teacher');
        $excluded = array_filter(array_merge([self::guest_id()], array_map('intval', explode(',', $CFG->siteadmins ?? ''))));
        [$exsql, $exparams] = $DB->get_in_or_equal($excluded ?: [0], SQL_PARAMS_NAMED, 'x', false);
        // Accounts another run generated are deleted when that run is purged, so never lend them out.
        $notgenerated = $DB->sql_like('u.username', ':agen', false, false, true);
        $exparams['agen'] = student_pool::USERNAME_PREFIX . '%';
        $base = "FROM {role_assignments} ra
                 JOIN {role} r ON r.id = ra.roleid
                 JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1
                WHERE u.id $exsql AND $notgenerated";

        $markers = [];
        if ($markerroles) {
            [$msql, $mparams] = $DB->get_in_or_equal($markerroles, SQL_PARAMS_NAMED, 'm');
            $markers = $DB->get_fieldset_sql(
                "SELECT DISTINCT ra.userid $base AND r.shortname $msql ORDER BY ra.userid",
                $exparams + $mparams
            );
        }
        $students = [];
        if ($studentroles) {
            [$ssql, $sparams] = $DB->get_in_or_equal($studentroles, SQL_PARAMS_NAMED, 's');
            [$nsql, $nparams] = $DB->get_in_or_equal($studentroles, SQL_PARAMS_NAMED, 'n', false);
            $students = $DB->get_fieldset_sql("SELECT DISTINCT ra.userid $base AND r.shortname $ssql
                AND NOT EXISTS (SELECT 1 FROM {role_assignments} ra2 JOIN {role} r2 ON r2.id = ra2.roleid
                                 WHERE ra2.userid = ra.userid AND r2.shortname $nsql)
                ORDER BY ra.userid", $exparams + $sparams + $nparams);
        }
        $markers = array_map('intval', $markers);
        return ['students' => array_values(array_diff(array_map('intval', $students), $markers)), 'markers' => $markers];
    }

    /**
     * Role short names used when enrolling: the first student role, and the non-editing teacher role if it is a
     * marker role (else the first marker role).
     *
     * Generated convenors are always editing teachers, so the course has someone who can edit it.
     *
     * @return array{student: string, marker: string, convenor: string}
     */
    public static function enrol_roles(): array {
        $markerroles = self::roles('markerroles', 'editingteacher,teacher');
        return [
            'student' => self::roles('studentroles', 'student')[0] ?? 'student',
            'marker' => in_array('teacher', $markerroles) ? 'teacher' : ($markerroles[0] ?? 'teacher'),
            'convenor' => 'editingteacher',
        ];
    }

    /**
     * Whether people holding a role count as markers.
     *
     * @param string $shortname
     * @return bool
     */
    public static function is_marker_role(string $shortname): bool {
        return in_array($shortname, self::roles('markerroles', 'editingteacher,teacher'));
    }

    /**
     * Everyone with any enrolment in a course, active or not.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function enrolled_any(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql('SELECT DISTINCT ue.userid FROM {user_enrolments} ue
            JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = ?', [$courseid]));
    }

    /**
     * Students and markers of a course. A user holding both kinds of role counts as a marker only.
     *
     * @param int $courseid
     * @return array{students: int[], markers: int[]}
     */
    public static function participants(int $courseid): array {
        $markers = self::users_with_roles($courseid, self::roles('markerroles', 'editingteacher,teacher'));
        $students = self::users_with_roles($courseid, self::roles('studentroles', 'student'));
        $students = array_values(array_diff($students, $markers));
        return ['students' => $students, 'markers' => $markers];
    }

    /**
     * Groups in the course and their student members.
     *
     * @param int $courseid
     * @param int[] $students
     * @return array<int, int[]> groupid => sorted student ids, groups with no students left out
     */
    public static function groups(int $courseid, array $students): array {
        global $DB;
        $result = [];
        $rows = $DB->get_recordset_sql(
            "SELECT gm.id, gm.groupid, gm.userid
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE g.courseid = :courseid
           ORDER BY gm.groupid, gm.userid",
            ['courseid' => $courseid]
        );
        $isstudent = array_flip($students);
        foreach ($rows as $row) {
            if (isset($isstudent[(int)$row->userid])) {
                $result[(int)$row->groupid][] = (int)$row->userid;
            }
        }
        $rows->close();
        ksort($result);
        return $result;
    }
}
