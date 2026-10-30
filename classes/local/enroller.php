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
 * Enrols students and staff into courses that have none.
 *
 * Students come from people who hold only student roles on the site, markers from people who hold a marker role
 * somewhere. With generated students on, those top every course up to its drawn cohort size instead; with generated
 * staff on, a course with no markers gets a convenor (editing teacher) and markers from the run's staff pool. Only
 * enrolments the run made are ever removed, by the purger.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enroller {
    /** @var array|null Site pools, read once per request. */
    protected static ?array $pools = null;

    /**
     * Who to enrol into one course. Writes nothing.
     *
     * @param random $rng
     * @param options $options
     * @param int $courseid
     * @param array $people current participants: students and markers, each a list of user ids
     * @return array{students: int[], markers: int[], convenors: int[]}
     */
    public static function plan(random $rng, options $options, int $courseid, array $people): array {
        $plan = ['students' => [], 'markers' => [], 'convenors' => []];
        $key = course_factory::key($courseid, $options);
        if (student_pool::enabled($options)) {
            // Generated students top the course up to its drawn size; existing students count towards it.
            $size = student_pool::cohort_size(random::for_key($options->seed, "cohort:$key"), $options);
            $need = $size - count($people['students']);
            if ($need > 0) {
                $pool = array_diff(student_pool::ids($options), $people['students']);
                $plan['students'] = self::pick(random::for_key($options->seed, "cohortpick:$key"), $pool, $need);
            }
        }
        if (student_pool::staff_enabled($options) && !$people['markers']) {
            // A convenor and some markers from the run's staff pool, so a marker works on several courses.
            $staffrng = random::for_key($options->seed, "staff:$key");
            $count = $staffrng->int($options->markersmin, $options->markersmax);
            $picked = self::pick($staffrng, student_pool::ids($options, true), $count + 1);
            $convenor = $picked[$staffrng->int(0, count($picked) - 1)];
            $plan['convenors'] = [$convenor];
            $plan['markers'] = array_values(array_diff($picked, [$convenor]));
        }
        if ($options->enrol === 'never' || ($people['students'] && $people['markers'])) {
            return $plan;
        }
        $taken = course_factory::is_placeholder($courseid) ? [] : course_selector::enrolled_any($courseid);
        if (!$people['students'] && !student_pool::enabled($options)) {
            self::$pools ??= course_selector::site_pools();
            $plan['students'] = self::pick($rng, array_diff(self::$pools['students'], $taken), $rng->int(15, 40));
        }
        if (!$people['markers'] && !student_pool::staff_enabled($options)) {
            self::$pools ??= course_selector::site_pools();
            $plan['markers'] = self::pick($rng, array_diff(self::$pools['markers'], $taken), $rng->int(1, 3));
        }
        return $plan;
    }

    /**
     * Forget the cached site pools (tests change users between plans).
     */
    public static function reset_cache(): void {
        self::$pools = null;
        student_pool::reset_cache();
    }

    /**
     * Pick up to $count ids, the same ones for the same seed and pool.
     *
     * @param random $rng
     * @param int[] $pool
     * @param int $count
     * @return int[] sorted ascending
     */
    protected static function pick(random $rng, array $pool, int $count): array {
        $pool = array_values($pool);
        sort($pool);
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = $rng->int(0, $i);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }
        $picked = array_slice($pool, 0, $count);
        sort($picked);
        return $picked;
    }

    /**
     * Enrol the planned people through the course's manual enrolment, dated $time. Safe to repeat.
     *
     * @param int $runid
     * @param int $courseid
     * @param array $plan students, markers and (optionally) convenors to enrol, each a list of user ids
     * @param int $time enrolment date to record
     * @return int number of people enrolled now
     */
    public static function enrol(int $runid, int $courseid, array $plan, int $time): int {
        global $DB;
        if (!$plan['students'] && !$plan['markers'] && empty($plan['convenors'])) {
            return 0;
        }
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $instanceid = $manual->add_default_instance(get_course($courseid));
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }
        // The welcome message is read from the instance passed in, so a copy with it switched off keeps
        // people's inboxes (and the site's mail queue) clear without changing the course's own setting.
        $quiet = clone $instance;
        $quiet->customint1 = defined('ENROL_DO_NOT_SEND_EMAIL') ? ENROL_DO_NOT_SEND_EMAIL : 0;
        $roles = course_selector::enrol_roles();
        $context = \context_course::instance($courseid);
        $done = 0;
        $kinds = ['students' => $roles['student'], 'markers' => $roles['marker'], 'convenors' => $roles['convenor']];
        foreach ($kinds as $kind => $shortname) {
            if (empty($plan[$kind])) {
                continue;
            }
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
            foreach ($plan[$kind] as $userid) {
                if ($DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid])) {
                    continue;
                }
                $manual->enrol_user($quiet, $userid, $roleid);
                $DB->set_field('user_enrolments', 'timecreated', $time, ['enrolid' => $instance->id, 'userid' => $userid]);
                $DB->set_field('user_enrolments', 'timemodified', $time, ['enrolid' => $instance->id, 'userid' => $userid]);
                $DB->set_field(
                    'role_assignments',
                    'timemodified',
                    $time,
                    ['contextid' => $context->id, 'userid' => $userid, 'roleid' => $roleid]
                );
                $DB->insert_record('tool_assessmentgen_enrol', (object)[
                    'runid' => $runid,
                    'courseid' => $courseid,
                    'userid' => $userid,
                    'enrolid' => $instance->id,
                    'roleid' => $roleid,
                    'timecreated' => time(),
                ]);
                $done++;
            }
        }
        return $done;
    }
}
