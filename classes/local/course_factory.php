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
 * Courses a run creates, for sites with none to match: one category per run, with courses named like real modules.
 *
 * Created courses get ID numbers AGEN-<tag>-course-<nnn> and their category AGEN-<tag>-category, so a retry finds
 * them and a purge removes exactly them. Before they exist a preview plans them under placeholder ids; each is
 * planned from a key that stays the same once it is created ("new<n>"), so the preview shows what the run makes.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_factory {
    /** @var int Courses not created yet are planned under this id plus their number. */
    public const PLACEHOLDER = 1500000000;

    /** @var array<int, string[]> Subject code and name. */
    protected const SUBJECTS = [
        ['ACC', 'Accounting'], ['ANT', 'Anthropology'], ['ARC', 'Architecture'], ['ART', 'Fine Art'],
        ['BIO', 'Biology'], ['BUS', 'Business Management'], ['CHE', 'Chemistry'], ['CLA', 'Classics'],
        ['COM', 'Computer Science'], ['CRI', 'Criminology'], ['DRA', 'Drama'], ['ECO', 'Economics'],
        ['EDU', 'Education'], ['ENG', 'English Literature'], ['ENV', 'Environmental Science'], ['FIL', 'Film Studies'],
        ['FRE', 'French'], ['GEO', 'Geography'], ['GER', 'German'], ['HIS', 'History'], ['LAW', 'Law'],
        ['LIN', 'Linguistics'], ['MAT', 'Mathematics'], ['MED', 'Medicine'], ['MUS', 'Music'], ['NUR', 'Nursing'],
        ['PHI', 'Philosophy'], ['PHY', 'Physics'], ['POL', 'Politics'], ['PSY', 'Psychology'],
        ['SOC', 'Sociology'], ['SPA', 'Spanish'], ['STA', 'Statistics'], ['THE', 'Theology'],
    ];

    /** @var array<string, string> Course keys by id, read once per request. */
    protected static array $keys = [];

    /**
     * ID number of the run's created course $n.
     *
     * @param options $o
     * @param int $n from 1
     * @return string
     */
    public static function idnumber(options $o, int $n): string {
        return sprintf('AGEN-%s-course-%03d', $o->tag, $n);
    }

    /**
     * ID number of the run's category.
     *
     * @param string $tag
     * @return string
     */
    public static function category_idnumber(string $tag): string {
        return "AGEN-$tag-category";
    }

    /**
     * Whether an id is a course not created yet.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_placeholder(int $courseid): bool {
        return $courseid > self::PLACEHOLDER;
    }

    /**
     * The key a course is planned under: "new<n>" for the run's created course n, whether created yet or not,
     * else the course id, as in runs before 0.7.0.
     *
     * @param int $courseid
     * @param options $o
     * @return string
     */
    public static function key(int $courseid, options $o): string {
        global $DB;
        if (self::is_placeholder($courseid)) {
            return 'new' . ($courseid - self::PLACEHOLDER);
        }
        if ($o->createcourses <= 0) {
            return (string)$courseid;
        }
        $cachekey = $o->tag . ':' . $courseid;
        if (!isset(self::$keys[$cachekey])) {
            $idnumber = (string)$DB->get_field('course', 'idnumber', ['id' => $courseid]);
            $pattern = '/^' . preg_quote("AGEN-{$o->tag}-course-", '/') . '(\d+)$/';
            self::$keys[$cachekey] = preg_match($pattern, $idnumber, $m) ? 'new' . (int)$m[1] : (string)$courseid;
        }
        return self::$keys[$cachekey];
    }

    /**
     * Forget cached keys.
     */
    public static function reset_cache(): void {
        self::$keys = [];
    }

    /**
     * Names of the courses the run will create, the same for the same seed. Short names already used by other
     * courses are skipped, so they can be created as planned.
     *
     * @param options $o
     * @return array<int, array{n: int, fullname: string, shortname: string, idnumber: string}> keyed by n
     */
    public static function planned(options $o): array {
        global $DB;
        if ($o->createcourses <= 0) {
            return [];
        }
        $prefix = "AGEN-{$o->tag}-course-";
        $taken = [];
        $rows = $DB->get_records_select_menu(
            'course',
            $DB->sql_like('shortname', ':term'),
            ['term' => '%-' . $DB->sql_like_escape($o->term)],
            '',
            'shortname, idnumber'
        );
        foreach ($rows as $shortname => $idnumber) {
            // The run's own courses keep their names on a retry.
            if (!str_starts_with((string)$idnumber, $prefix)) {
                $taken[\core_text::strtoupper($shortname)] = true;
            }
        }
        $planned = [];
        for ($n = 1; $n <= $o->createcourses; $n++) {
            $rng = random::for_key($o->seed, "coursename:$n");
            [$code, $name] = $rng->pick(self::SUBJECTS);
            $number = $rng->int(1, 3) * 100 + $rng->int(1, 40);
            while (isset($taken[\core_text::strtoupper("$code$number-{$o->term}")])) {
                $number++;
            }
            $shortname = "$code$number-{$o->term}";
            $taken[\core_text::strtoupper($shortname)] = true;
            $planned[$n] = ['n' => $n, 'fullname' => "$name $number {$o->term}", 'shortname' => $shortname,
                'idnumber' => self::idnumber($o, $n)];
        }
        return $planned;
    }

    /**
     * Course records for previews, standing in for the courses the run will create.
     *
     * @param options $o
     * @return \stdClass[] keyed by placeholder id
     */
    public static function placeholders(options $o): array {
        $courses = [];
        foreach (self::planned($o) as $n => $c) {
            $id = self::PLACEHOLDER + $n;
            $courses[$id] = (object)['id' => $id, 'fullname' => $c['fullname'], 'shortname' => $c['shortname'],
                'idnumber' => $c['idnumber'], 'visible' => 1, 'placeholder' => true];
        }
        return $courses;
    }

    /**
     * Start and end dates that cover every assessment the run can make, and its marking.
     *
     * @param options $o
     * @return int[] [startdate, enddate]
     */
    public static function dates(options $o): array {
        $start = planner::enrol_time($o) - 7 * planner::DAY;
        $start -= $start % planner::DAY;
        $end = $o->anchor + ($o->dueto + $o->turnaround + 30) * planner::DAY;
        return [$start, $end - $end % planner::DAY];
    }

    /**
     * Create the run's category and courses, or find them on a retry.
     *
     * @param options $o
     * @param callable|null $progress called with each course record as it is made
     * @return int[] course ids, in order of n
     */
    public static function create(options $o, ?callable $progress = null): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $planned = self::planned($o);
        if (!$planned) {
            return [];
        }
        $categoryidnumber = self::category_idnumber($o->tag);
        $categoryid = $DB->get_field('course_categories', 'id', ['idnumber' => $categoryidnumber]);
        if (!$categoryid) {
            $categoryid = \core_course_category::create((object)[
                'name' => get_string('categoryname', 'tool_assessmentgen', $o->tag),
                'idnumber' => $categoryidnumber,
                'description' => '',
            ])->id;
        }
        [$start, $end] = self::dates($o);
        $ids = [];
        foreach ($planned as $n => $c) {
            $id = $DB->get_field('course', 'id', ['idnumber' => $c['idnumber']]);
            if (!$id) {
                $course = create_course((object)[
                    'category' => $categoryid,
                    'fullname' => $c['fullname'],
                    'shortname' => $c['shortname'],
                    'idnumber' => $c['idnumber'],
                    'summary' => '',
                    'summaryformat' => FORMAT_HTML,
                    'format' => 'topics',
                    'numsections' => 10,
                    'startdate' => $start,
                    'enddate' => $end,
                    'visible' => 1,
                ]);
                $id = $course->id;
                if ($progress) {
                    $progress($course);
                }
            }
            $ids[] = (int)$id;
        }
        return $ids;
    }

    /**
     * Ids of the run's created courses, found by their exact ID numbers.
     *
     * @param string $tag
     * @return int[]
     */
    public static function created(string $tag): array {
        global $DB;
        $prefix = "AGEN-$tag-course-";
        $rows = $DB->get_records_select_menu(
            'course',
            $DB->sql_like('idnumber', ':prefix'),
            ['prefix' => $DB->sql_like_escape($prefix) . '%'],
            'id',
            'id, idnumber'
        );
        $pattern = '/^' . preg_quote($prefix, '/') . '\d+$/';
        return array_map('intval', array_keys(array_filter($rows, fn($idnumber) => preg_match($pattern, $idnumber))));
    }
}
