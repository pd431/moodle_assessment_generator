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
 * Accounts a run creates, shared by all its courses: students, and staff (a convenor and markers per course), and
 * the cohort size each course gets.
 *
 * One pool per run, so a student sits in several of the run's courses as real students do, which is what makes a
 * dashboard work hard. Each course draws its cohort size between a minimum and maximum with a chosen mean, and
 * tops up its existing students to that size from the pool. Accounts are created once, by whichever course task
 * runs first, and deleted when the run is purged.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_pool {
    /** @var string Every generated username starts with this. */
    public const USERNAME_PREFIX = 'agen-';

    /** @var int Pool members not created yet are planned under this id plus their index. */
    public const PLACEHOLDER = 1000000000;

    /** @var int The same for staff, so a planned student and a planned marker never share an id. */
    public const STAFF_PLACEHOLDER = 1200000000;

    /** @var int How many courses a typical generated marker works on, when the staff pool size is left to us. */
    public const COURSES_PER_MARKER = 3;

    /** @var int How many courses a typical generated student should be in, when the pool size is left to us. */
    public const COURSES_PER_STUDENT = 4;

    /** @var string[] */
    protected const FIRSTNAMES = [
        'Aisha', 'Ben', 'Chloe', 'Daniel', 'Eilidh', 'Farah', 'George', 'Hannah', 'Ibrahim', 'Jade', 'Kwame', 'Lena',
        'Mohammed', 'Niamh', 'Oliver', 'Priya', 'Qi', 'Rhys', 'Sofia', 'Tomasz', 'Uma', 'Victor', 'Wen', 'Xavier',
        'Yasmin', 'Zara', 'Amara', 'Callum', 'Deepa', 'Emre', 'Freya', 'Hamza', 'Isla', 'Jonas', 'Kofi', 'Leila',
        'Mateo', 'Nadia', 'Owen', 'Ruth',
    ];

    /** @var string[] */
    protected const LASTNAMES = [
        'Adeyemi', 'Brown', 'Chen', 'Davies', 'Evans', 'Fernandes', 'Garcia', 'Hughes', 'Iqbal', 'Jones', 'Kaur',
        'Lewis', 'Murphy', 'Nowak', 'Okafor', 'Patel', 'Quinn', 'Roberts', 'Smith', 'Thomas', 'Usman', 'Varga',
        'Williams', 'Xu', 'Young', 'Zielinski', 'Ahmed', 'Campbell', 'Doyle', 'Edwards', 'Fraser', 'Green', 'Hall',
        'Ivanova', 'Khan', 'Morgan', 'Nguyen', 'Owusu', 'Reid', 'Taylor',
    ];

    /** @var array<string, int[]> Pool ids by run tag and size, read once per request. */
    protected static array $ids = [];

    /**
     * Whether the run creates students at all.
     *
     * @param options $o
     * @return bool
     */
    public static function enabled(options $o): bool {
        return $o->studentsmax > 0;
    }

    /**
     * Whether the run creates staff.
     *
     * @param options $o
     * @return bool
     */
    public static function staff_enabled(options $o): bool {
        return $o->markersmax > 0;
    }

    /**
     * Staff pool size for a run over $courses courses: as given, else enough for each marker to work on a few
     * courses, and never fewer than one convenor plus the most markers one course can have.
     *
     * @param options $o
     * @param int $courses
     * @return int
     */
    public static function staff_pool_size(options $o, int $courses): int {
        if ($o->staffpool) {
            return $o->staffpool;
        }
        $percourse = 1 + ($o->markersmin + $o->markersmax) / 2;
        return max($o->markersmax + 1, (int)ceil(max(1, $courses) * $percourse / self::COURSES_PER_MARKER));
    }

    /**
     * Pool size for a run over $courses courses: as given, else enough for a typical student to be in a few courses.
     *
     * @param options $o
     * @param int $courses
     * @return int
     */
    public static function pool_size(options $o, int $courses): int {
        if ($o->pool) {
            return $o->pool;
        }
        return max($o->studentsmax, (int)ceil($o->studentsmean * max(1, $courses) / self::COURSES_PER_STUDENT));
    }

    /**
     * One course's cohort size between the minimum and maximum, averaging the requested mean.
     *
     * Drawn as min + (max - min) * u^k, whose mean is min + (max - min) / (k + 1). A mean below the middle gives
     * many courses near the minimum and a long tail of large ones, as real course sizes tend to be.
     *
     * @param random $rng
     * @param options $o
     * @return int
     */
    public static function cohort_size(random $rng, options $o): int {
        $a = $o->studentsmin;
        $b = $o->studentsmax;
        $u = $rng->float();
        if ($b <= $a) {
            return $a;
        }
        $k = ($b - $a) / ($o->studentsmean - $a) - 1;
        return (int)round($a + ($b - $a) * $u ** $k);
    }

    /**
     * The run's pool as user ids, in pool order. Members not created yet get placeholder ids, so a preview plans
     * them like anyone else.
     *
     * @param options $o
     * @param bool $staff the staff pool rather than the student pool
     * @return int[]
     */
    public static function ids(options $o, bool $staff = false): array {
        global $DB;
        $size = $staff ? $o->staffpool : $o->pool;
        $key = $o->tag . ':' . ($staff ? 'staff' : 'students') . ':' . $size;
        if (isset(self::$ids[$key])) {
            return self::$ids[$key];
        }
        $existing = [];
        if ($size) {
            $existing = $DB->get_records_select_menu(
                'user',
                'deleted = 0 AND ' . $DB->sql_like('username', ':prefix', false, false),
                ['prefix' => $DB->sql_like_escape(self::username($o, 0, false, $staff)) . '%'],
                '',
                'username, id'
            );
        }
        $placeholder = $staff ? self::STAFF_PLACEHOLDER : self::PLACEHOLDER;
        $ids = [];
        for ($i = 1; $i <= $size; $i++) {
            $ids[] = (int)($existing[self::username($o, $i, true, $staff)] ?? $placeholder + $i);
        }
        return self::$ids[$key] = $ids;
    }

    /**
     * Forget cached pool ids.
     */
    public static function reset_cache(): void {
        self::$ids = [];
    }

    /**
     * Username for pool member $i, or the prefix every member of the run shares.
     *
     * @param options $o
     * @param int $i
     * @param bool $full false for just the run's prefix
     * @param bool $staff a staff account ("agen-<tag>-staff-0001") rather than a student
     * @return string
     */
    public static function username(options $o, int $i, bool $full = true, bool $staff = false): string {
        $prefix = \core_text::strtolower(self::USERNAME_PREFIX . $o->tag . '-' . ($staff ? 'staff-' : ''));
        return $full ? $prefix . sprintf('%04d', $i) : $prefix;
    }

    /**
     * Create the pool members that do not exist yet. Parts may run in parallel, so a lock makes sure only one
     * creates them.
     *
     * @param int $runid
     * @param options $o
     * @return int accounts created now
     */
    public static function ensure(int $runid, options $o): int {
        global $CFG, $DB;
        if (!self::enabled($o) && !self::staff_enabled($o)) {
            return 0;
        }
        $created = locks::with("pool-$runid", function () use ($DB, $CFG, $runid, $o) {
            $have = [];
            foreach ($DB->get_records('tool_assessmentgen_user', ['runid' => $runid], '', 'id, kind, poolindex') as $row) {
                $have[$row->kind . ':' . $row->poolindex] = true;
            }
            $wanted = [];
            for ($i = 1; $i <= $o->pool; $i++) {
                $wanted[] = ['student', $i];
            }
            for ($i = 1; $i <= $o->staffpool; $i++) {
                $wanted[] = ['staff', $i];
            }
            $created = 0;
            // Accounts exist from a little before the earliest enrolment.
            $time = planner::enrol_time($o) - planner::DAY;
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($wanted as [$kind, $i]) {
                    if (isset($have["$kind:$i"])) {
                        continue;
                    }
                    $username = self::username($o, $i, true, $kind === 'staff');
                    if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                        throw new \moodle_exception('usernametaken', 'tool_assessmentgen', '', $username);
                    }
                    $rng = random::for_key($o->seed, $kind === 'staff' ? "staffname:$i" : "name:$i");
                    // Inserted directly rather than through user_create_user(): no events means no welcome
                    // messages or sync plugins reacting to thousands of test accounts, and it is fast.
                    $userid = $DB->insert_record('user', (object)[
                        'auth' => 'manual',
                        'confirmed' => 1,
                        'mnethostid' => $CFG->mnet_localhost_id,
                        'username' => $username,
                        'password' => $o->passwordhash ?: AUTH_PASSWORD_NOT_CACHED,
                        'firstname' => $rng->pick(self::FIRSTNAMES),
                        'lastname' => $rng->pick(self::LASTNAMES),
                        'email' => $username . '@example.invalid',
                        'emailstop' => 1,
                        'lang' => $CFG->lang ?? 'en',
                        'timecreated' => $time,
                        'timemodified' => $time,
                    ]);
                    \context_user::instance($userid);
                    $DB->insert_record('tool_assessmentgen_user', (object)[
                        'runid' => $runid,
                        'userid' => $userid,
                        'kind' => $kind,
                        'poolindex' => $i,
                        'timecreated' => time(),
                    ]);
                    $created++;
                }
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            $transaction->allow_commit();
            return $created;
        });
        self::reset_cache();
        return $created;
    }

    /**
     * How many of the run's courses each pool member was planned into, for previews.
     *
     * @param array<int, int> $counts courses per pool id, from the course plans
     * @param int $pool pool size
     * @return array{used: int, min: int, mean: float, max: int, pool: int}
     */
    public static function spread(array $counts, int $pool): array {
        if (!$counts) {
            return ['used' => 0, 'min' => 0, 'mean' => 0.0, 'max' => 0, 'pool' => $pool];
        }
        return ['used' => count($counts), 'min' => min($counts), 'mean' => round(array_sum($counts) / count($counts), 1),
            'max' => max($counts), 'pool' => $pool];
    }
}
