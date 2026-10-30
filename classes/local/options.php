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
 * The settings for one run, with defaults and validation.
 *
 * Rates are fractions (0.8 = 80%). Day offsets are relative to the run's anchor date.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class options {
    /** @var array Defaults for every option. */
    public const DEFAULTS = [
        'match' => '',
        'courseids' => [],
        'types' => ['assign', 'peerwork', 'quiz'],
        'groups' => 'missing',
        'enrol' => 'missing',
        'minper' => 2,
        'maxper' => 6,
        'duefrom' => -90,
        'dueto' => 120,
        'openmin' => 7,
        'openmax' => 28,
        'cutoffrate' => 0.4,
        'submitrate' => 0.8,
        'laterate' => 0.1,
        'draftrate' => 0.05,
        'extensionrate' => 0.05,
        'turnaround' => 21,
        'grademean' => 62,
        'gradesd' => 11,
        'workflowrate' => 0.4,
        'allocationrate' => 0.5,
        'blindrate' => 0.15,
        'teamrate' => 0.15,
        'formativerate' => 0.3,
        'summativetag' => '',
        'formativetag' => '',
        'studentsmin' => 0,
        'studentsmax' => 0,
        'studentsmean' => 0,
        'pool' => 0,
        'createcourses' => 0,
        'term' => '',
        'markersmin' => 0,
        'markersmax' => 0,
        'staffpool' => 0,
        'setupdone' => false,
        'passwordhash' => '',
        'partsize' => 10,
        'seed' => 0,
        'tag' => '',
        'anchor' => 0,
    ];

    /** @var array */
    protected array $values;

    /**
     * Constructor.
     *
     * @param array $values overrides of DEFAULTS
     */
    public function __construct(array $values = []) {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        if ($unknown) {
            throw new \coding_exception('Unknown tool_assessmentgen options: ' . implode(', ', $unknown));
        }
        $v = array_merge(self::DEFAULTS, $values);
        foreach (
            ['minper', 'maxper', 'duefrom', 'dueto', 'openmin', 'openmax', 'turnaround', 'seed', 'anchor',
                'studentsmin', 'studentsmax', 'pool', 'partsize', 'createcourses', 'markersmin', 'markersmax',
                'staffpool'] as $k
        ) {
            $v[$k] = (int)$v[$k];
        }
        $v['setupdone'] = (bool)$v['setupdone'];
        // A brand-new site has nobody to borrow, so created courses bring their own students and staff.
        if ($v['createcourses'] > 0 && $v['studentsmax'] <= 0) {
            [$v['studentsmin'], $v['studentsmax'], $v['studentsmean']] = [20, 60, 35];
        }
        if ($v['createcourses'] > 0 && $v['markersmax'] <= 0) {
            [$v['markersmin'], $v['markersmax']] = [1, 3];
        }
        $v['studentsmean'] = (float)$v['studentsmean'];
        if ($v['studentsmax'] > 0 && $v['studentsmean'] <= 0) {
            $v['studentsmean'] = ($v['studentsmin'] + $v['studentsmax']) / 2;
        }
        foreach (
            ['cutoffrate', 'submitrate', 'laterate', 'draftrate', 'extensionrate', 'grademean', 'gradesd',
                'workflowrate', 'allocationrate', 'blindrate', 'teamrate', 'formativerate'] as $k
        ) {
            $v[$k] = (float)$v[$k];
        }
        if (is_string($v['types'])) {
            $v['types'] = array_filter(array_map('trim', explode(',', $v['types'])));
        }
        $v['types'] = array_values(array_unique($v['types']));
        $v['courseids'] = array_values(array_map('intval', (array)$v['courseids']));
        if (!$v['anchor']) {
            $v['anchor'] = time();
        }
        if (!$v['seed']) {
            $v['seed'] = random_int(1, 999999);
        }
        // Tag names: the run's own, else the first in the dashboard's settings (the remake, block_le_dashboard,
        // then rc6's block_my_assessments, which can be installed alongside it), else a default.
        foreach (
            ['summativetag' => ['certaintags', 'Summative Assessment'],
                'formativetag' => ['formativetags', 'Formative Assessment']] as $k => [$setting, $default]
        ) {
            $v[$k] = trim((string)$v[$k]);
            foreach (['block_le_dashboard', 'block_my_assessments'] as $block) {
                if ($v[$k] === '') {
                    $configured = array_filter(array_map('trim', explode(',', (string)get_config($block, $setting))));
                    $v[$k] = $configured ? reset($configured) : '';
                }
            }
            if ($v[$k] === '') {
                $v[$k] = $default;
            }
        }
        $v['term'] = trim((string)$v['term']);
        if ($v['term'] === '') {
            // The academic year containing the anchor, as "202526": years start in August.
            $year = (int)date('Y', $v['anchor']) - ((int)date('n', $v['anchor']) < 8 ? 1 : 0);
            $v['term'] = $year . sprintf('%02d', ($year + 1) % 100);
        }
        if ($v['tag'] === '') {
            $v['tag'] = 'run' . $v['seed'];
        }
        $this->values = $v;
        $this->validate();
    }

    /**
     * Throws if the values cannot produce a sensible plan.
     */
    protected function validate(): void {
        $v = $this->values;
        $errors = [];
        if ($v['minper'] < 0 || $v['maxper'] < $v['minper']) {
            $errors[] = 'assessments per course must be a range like 2-6';
        }
        if ($v['dueto'] < $v['duefrom']) {
            $errors[] = 'due date window is reversed';
        }
        if ($v['openmin'] < 0 || $v['openmax'] < $v['openmin']) {
            $errors[] = 'open window must be a range like 7-28 days';
        }
        foreach (
            ['cutoffrate', 'submitrate', 'laterate', 'draftrate', 'extensionrate', 'workflowrate',
                'allocationrate', 'blindrate', 'teamrate', 'formativerate'] as $k
        ) {
            if ($v[$k] < 0 || $v[$k] > 1) {
                $errors[] = "$k must be between 0 and 1";
            }
        }
        if (\core_text::strtolower($v['summativetag']) === \core_text::strtolower($v['formativetag'])) {
            $errors[] = 'summative and formative tags must differ';
        }
        if ($v['submitrate'] + $v['draftrate'] > 1) {
            $errors[] = 'submit rate plus draft rate cannot exceed 100%';
        }
        if ($v['turnaround'] < 1) {
            $errors[] = 'marking turnaround must be at least 1 day';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v['tag'])) {
            $errors[] = 'run tag may only use letters, digits, - and _ (max 40)';
        }
        if (!$v['types']) {
            $errors[] = 'at least one activity type is needed';
        }
        if (!in_array($v['groups'], ['missing', 'always', 'never'], true)) {
            $errors[] = 'groups must be missing, always or never';
        }
        if (!in_array($v['enrol'], ['missing', 'never'], true)) {
            $errors[] = 'enrol must be missing or never';
        }
        if ($v['studentsmax'] > 0) {
            if ($v['studentsmin'] < 1 || $v['studentsmax'] < $v['studentsmin'] || $v['studentsmax'] > 5000) {
                $errors[] = 'students per course must be a range like 60-400, at most 5000';
            } else if (
                $v['studentsmax'] > $v['studentsmin']
                && ($v['studentsmean'] <= $v['studentsmin'] || $v['studentsmean'] >= $v['studentsmax'])
            ) {
                $errors[] = 'mean students per course must be above the minimum and below the maximum';
            }
            if ($v['pool'] && ($v['pool'] < $v['studentsmax'] || $v['pool'] > 50000)) {
                $errors[] = 'the student pool must hold at least the largest course, and at most 50000';
            }
        }
        if ($v['partsize'] < 1 || $v['partsize'] > 1000) {
            $errors[] = 'assessments per background task must be between 1 and 1000';
        }
        if ($v['match'] === '' && !$v['courseids'] && $v['createcourses'] <= 0) {
            $errors[] = 'give a course name match, a list of course ids or a number of courses to create';
        }
        if ($v['createcourses'] < 0 || $v['createcourses'] > 1000) {
            $errors[] = 'courses to create must be between 0 and 1000';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $v['term'])) {
            $errors[] = 'term may only use letters, digits, - and _ (max 20)';
        }
        if ($v['markersmax'] > 0 && ($v['markersmin'] < 0 || $v['markersmax'] < $v['markersmin'] || $v['markersmax'] > 50)) {
            $errors[] = 'markers per course must be a range like 1-3, at most 50';
        }
        if ($v['staffpool'] && $v['staffpool'] < $v['markersmax'] + 1) {
            $errors[] = 'the staff pool must hold at least one convenor and the most markers a course can have';
        }
        if ($errors) {
            throw new \invalid_parameter_exception(implode('; ', $errors));
        }
    }

    /**
     * Read an option.
     *
     * @param string $name
     * @return mixed
     */
    public function get(string $name) {
        if (!array_key_exists($name, $this->values)) {
            throw new \coding_exception("Unknown option $name");
        }
        return $this->values[$name];
    }

    /**
     * Magic read access, so $options->seed works.
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        return $this->get($name);
    }

    /**
     * All values.
     *
     * @return array
     */
    public function to_array(): array {
        return $this->values;
    }

    /**
     * A copy with some values changed.
     *
     * @param array $values
     * @return self
     */
    public function with(array $values): self {
        return new self(array_merge($this->values, $values));
    }

    /**
     * Build from the admin page form.
     *
     * @param \stdClass $data form data
     * @param int[] $courseids ticked courses, or none to use the name match
     * @return self
     */
    public static function from_form(\stdClass $data, array $courseids = []): self {
        $values = ['courseids' => $courseids];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (isset($data->$key) && !in_array($key, ['types', 'courseids', 'anchor', 'passwordhash', 'setupdone'])) {
                $values[$key] = $data->$key;
            }
        }
        $values['types'] = array_keys(array_filter((array)($data->types ?? ['assign' => 1])));
        return new self($values);
    }

    /**
     * Rebuild from a stored run.
     *
     * @param string $json
     * @return self
     */
    public static function from_json(string $json): self {
        return new self(json_decode($json, true));
    }
}
