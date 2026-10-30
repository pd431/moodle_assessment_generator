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
 * Plans and creates the groups a run adds to a course, and removes them on purge.
 *
 * Generated groups get ID numbers AGEN-<tag>-<courseid>-g<n> and sit in one grouping per course and run, so
 * they can be found again on a retry and removed on purge without touching groups made by people.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_builder {
    /**
     * Decide which groups the course's group activities will use, and which new groups to create.
     *
     * @param random $rng
     * @param options $options
     * @param int $courseid
     * @param int[] $students
     * @param array $existing existing group id => student members
     * @return array{use: array<string, int[]>, new: array, grouping: ?array}
     *     use: group key => members, where a key is an existing group id or "new:<n>"
     */
    public static function plan(random $rng, options $options, int $courseid, array $students, array $existing): array {
        $create = $options->groups === 'always' || ($options->groups === 'missing' && !$existing);
        if (!$create || count($students) < 2) {
            return ['use' => $existing, 'new' => [], 'grouping' => null];
        }
        $shuffled = $students;
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = $rng->int(0, $i);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }
        $size = $rng->int(3, 6);
        // Keep every group within 3-6 members: at least ceil(n/6) groups, at most floor(n/3).
        $n = count($shuffled);
        $count = max((int)ceil($n / 6), min((int)round($n / $size), max(1, intdiv($n, 3))));
        $members = array_fill(0, $count, []);
        foreach ($shuffled as $i => $userid) {
            $members[$i % $count][] = $userid;
        }
        $new = [];
        $use = [];
        foreach ($members as $g => $ids) {
            sort($ids);
            $key = 'new:' . ($g + 1);
            $new[] = [
                'key' => $key,
                'name' => 'Group ' . ($g + 1) . ' (' . $options->tag . ')',
                'idnumber' => self::prefix($options->tag, $courseid) . 'g' . ($g + 1),
                'members' => $ids,
            ];
            $use[$key] = $ids;
        }
        return [
            'use' => $use,
            'new' => $new,
            'grouping' => [
                'name' => 'Generated groups (' . $options->tag . ')',
                'idnumber' => self::prefix($options->tag, $courseid) . 'grouping',
            ],
        ];
    }

    /**
     * ID number prefix for a course's generated groups.
     *
     * @param string $tag
     * @param int $courseid
     * @return string
     */
    public static function prefix(string $tag, int $courseid): string {
        return 'AGEN-' . $tag . '-' . $courseid . '-';
    }

    /**
     * Create the planned groups (or find them, on a retry).
     *
     * @param int $courseid
     * @param array $plan from plan()
     * @return array{map: array<string, int>, groupingid: int} group key => real group id
     */
    public static function create(int $courseid, array $plan): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $map = [];
        foreach (array_keys($plan['use']) as $key) {
            if (is_int($key)) {
                $map[$key] = $key;
            }
        }
        if (!$plan['new']) {
            return ['map' => $map, 'groupingid' => 0];
        }
        $groupingid = $DB->get_field('groupings', 'id', ['courseid' => $courseid,
            'idnumber' => $plan['grouping']['idnumber']]);
        if (!$groupingid) {
            $groupingid = groups_create_grouping((object)['courseid' => $courseid, 'name' => $plan['grouping']['name'],
                'idnumber' => $plan['grouping']['idnumber'], 'description' => '', 'descriptionformat' => FORMAT_HTML]);
        }
        foreach ($plan['new'] as $group) {
            $groupid = $DB->get_field('groups', 'id', ['courseid' => $courseid, 'idnumber' => $group['idnumber']]);
            if (!$groupid) {
                $groupid = groups_create_group((object)['courseid' => $courseid, 'name' => $group['name'],
                    'idnumber' => $group['idnumber'], 'description' => '', 'descriptionformat' => FORMAT_HTML]);
                foreach ($group['members'] as $userid) {
                    groups_add_member($groupid, $userid);
                }
                groups_assign_grouping($groupingid, $groupid);
            }
            $map[$group['key']] = (int)$groupid;
        }
        return ['map' => $map, 'groupingid' => (int)$groupingid];
    }
}
