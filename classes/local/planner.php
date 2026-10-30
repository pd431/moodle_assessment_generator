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
 * Builds the plan for one course: which assessments to add, with what settings and dates, and what each
 * student (or group) does. Nothing is written to the database here, so a plan doubles as the dry run.
 *
 * A course's plan depends only on the run options and the course's current students, markers and groups,
 * so planning courses in any order, or in parallel tasks, gives the same result.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner {
    /** @var int */
    public const DAY = 86400;

    /** @var string[] Name patterns; {n} is the number of that kind of assessment in the course. */
    public const NAMES = [
        'Essay {n}', 'Lab report {n}', 'Problem sheet {n}', 'Case study {n}', 'Reflective log {n}',
        'Literature review {n}', 'Project proposal {n}', 'Portfolio {n}', 'Critical analysis {n}', 'Data analysis {n}',
    ];

    /** @var string[] Quiz name patterns. */
    public const QUIZNAMES = ['Quiz {n}', 'Weekly test {n}', 'Knowledge check {n}', 'Practice quiz {n}'];

    /** @var string[] Peer assessment name patterns. */
    public const PEERNAMES = ['Group project {n}', 'Team report {n}', 'Group presentation {n}'];

    /** @var string[] Subtitles appended to some names. */
    public const SUBTITLES = [
        'Primary sources', 'Methods', 'Early findings', 'Applied theory', 'Group design', 'Peer feedback',
        'Final submission', 'Draft chapter', 'Ethics', 'Evaluation',
    ];

    /** @var string[] Non-final marking workflow states. */
    public const WORKFLOW_INPROGRESS = ['inmarking', 'readyforreview', 'inreview', 'readyforrelease'];

    /** @var options */
    protected options $options;

    /**
     * Constructor.
     *
     * @param options $options
     */
    public function __construct(options $options) {
        $this->options = $options;
    }

    /**
     * Plan one course.
     *
     * A course of many assessments and students plans to millions of units, too many to hold at once. With
     * $each, every assessment is handed over as soon as it is planned and kept afterwards without its units,
     * so memory stays at one assessment's worth however many there are. $before sees the course-level plan
     * (enrolments and groups) before the first assessment.
     *
     * @param int $courseid
     * @param string[] $types activity types that are actually available
     * @param callable|null $before function(array $plan): void, given everything but the assessments
     * @param callable|null $each function(array $assessment): void, given each assessment with its units
     * @return array{courseid: int, students: int, markers: int, groups: int, assessments: array, warnings: string[]}
     */
    public function plan_course(int $courseid, array $types, ?callable $before = null, ?callable $each = null): array {
        $o = $this->options;
        // Random streams are keyed by course, by a key a created course keeps from preview to run.
        $key = course_factory::key($courseid, $o);
        $rng = random::for_key($o->seed, "course:$key");
        $placeholder = course_factory::is_placeholder($courseid);
        $people = $placeholder ? ['students' => [], 'markers' => []] : course_selector::participants($courseid);
        // People the run will enrol take part as if they were already there.
        $enrolplan = enroller::plan(random::for_key($o->seed, "enrol:$key"), $o, $courseid, $people);
        $newmarkers = $enrolplan['markers'];
        if (course_selector::is_marker_role(course_selector::enrol_roles()['convenor'])) {
            $newmarkers = array_merge($newmarkers, $enrolplan['convenors']);
        }
        $people['students'] = array_values(array_unique(array_merge($people['students'], $enrolplan['students'])));
        $people['markers'] = array_values(array_unique(array_merge($people['markers'], $newmarkers)));
        sort($people['students']);
        sort($people['markers']);
        $existing = $placeholder ? [] : course_selector::groups($courseid, $people['students']);
        $groupplan = group_builder::plan(
            random::for_key($o->seed, "groups:$key"),
            $o,
            $courseid,
            $people['students'],
            $existing
        );
        $groups = $groupplan['use'];
        $grouping = $groupplan['new'] ? 'new' : 0;
        $warnings = [];
        if (!$people['students']) {
            $warnings[] = 'nostudents';
        }
        if (!$people['markers']) {
            $warnings[] = 'nomarkers';
        }
        if (!$groups && in_array('peerwork', $types)) {
            $types = array_values(array_diff($types, ['peerwork']));
            $warnings[] = 'nogroups';
        }

        $count = $types ? $rng->int($o->minper, $o->maxper) : 0;
        $plan = [
            'courseid' => $courseid,
            'students' => count($people['students']),
            'markers' => count($people['markers']),
            'groups' => count($groups),
            'newgroups' => count($groupplan['new']),
            'groupplan' => $groupplan,
            'enrolplan' => $enrolplan,
            'enrolled' => count($enrolplan['students']) + count($enrolplan['markers']) + count($enrolplan['convenors']),
            'enroltime' => self::enrol_time($o),
            'assessments' => [],
            'warnings' => $warnings,
        ];
        $plan['summary'] = self::summarise($plan);
        if ($before) {
            $before($plan);
        }
        $assessments = [];
        $teamindex = ($groups && $o->teamrate > 0) ? $rng->int(1, max(1, $count)) : 0;
        $namecounts = [];
        for ($i = 1; $i <= $count; $i++) {
            $type = $rng->pick($types);
            $pattern = $type === 'quiz' ? $rng->pick(self::QUIZNAMES) :
                ($type === 'peerwork' ? $rng->pick(self::PEERNAMES) : $rng->pick(self::NAMES));
            $namecounts[$pattern] = ($namecounts[$pattern] ?? 0) + 1;
            $name = str_replace('{n}', (string)$namecounts[$pattern], $pattern);
            if ($rng->chance(0.5)) {
                $name .= ': ' . $rng->pick(self::SUBTITLES);
            }

            $due = $o->anchor + $rng->int($o->duefrom * self::DAY, $o->dueto * self::DAY);
            $due -= $due % 3600;
            $open = $due - $rng->int($o->openmin, $o->openmax) * self::DAY;
            $assessment = [
                'type' => $type,
                'index' => $i,
                'name' => $name,
                'idnumber' => 'AGEN-' . $o->tag . '-' . $courseid . '-' . $i,
                'open' => $open,
                'due' => $due,
                'cutoff' => 0,
                'gradingdue' => $due + $o->turnaround * self::DAY,
                'team' => false,
                'grouping' => 0,
                'units' => [],
            ];
            // Kept on its own random stream so tagging does not shift the rest of the plan.
            $formative = str_starts_with($pattern, 'Practice')
                || random::for_key($o->seed, "tag:$key:$i")->chance($o->formativerate);
            $assessment['assessmenttag'] = $formative ? $o->formativetag : $o->summativetag;
            $assessment['formative'] = $formative;
            if ($type === 'peerwork') {
                $assessment = $this->plan_peerwork($rng, $assessment, $people['markers'], $groups, $grouping);
            } else if ($type === 'quiz') {
                $assessment = $this->plan_quiz($rng, $assessment, $people['markers'], $people['students']);
            } else {
                $team = $groups && ($i === $teamindex || $rng->chance($o->teamrate));
                $assessment = $this->plan_assign($rng, $assessment, $people, $team ? $groups : [], $grouping);
            }
            self::add_to_summary($plan['summary'], $assessment);
            if ($each) {
                $each($assessment);
                $assessment['units'] = [];
            }
            $assessments[] = $assessment;
        }
        $plan['assessments'] = $assessments;
        return $plan;
    }

    /**
     * When the run's enrolments start: a week before the earliest open date the options allow (an hour's
     * slack for due dates rounded down to the hour), and never after the anchor. Known before any
     * assessment is planned, so enrolment can happen first.
     *
     * @param options $o
     * @return int
     */
    public static function enrol_time(options $o): int {
        $earliestopen = $o->anchor + ($o->duefrom - $o->openmax) * self::DAY - 3600;
        return min($o->anchor, $earliestopen - 7 * self::DAY);
    }

    /**
     * Settings and units for an assignment.
     *
     * @param random $rng
     * @param array $a assessment with common fields
     * @param array $people students and markers
     * @param array $groups groups to submit as (group id => member ids), empty for individual submission
     * @param string|int $grouping 'new' when the groups are generated ones
     * @return array
     */
    protected function plan_assign(random $rng, array $a, array $people, array $groups, $grouping): array {
        $o = $this->options;
        $a['cutoff'] = $rng->chance($o->cutoffrate) ? $a['due'] + $rng->int(3, 14) * self::DAY : 0;
        $a['team'] = (bool)$groups;
        $a['grouping'] = $groups ? $grouping : 0;
        $a['workflow'] = $rng->chance($o->workflowrate);
        $a['allocation'] = $a['workflow'] && $people['markers'] && $rng->chance($o->allocationrate);
        $a['blind'] = !$groups && $rng->chance($o->blindrate);
        $a['format'] = $rng->pick(['text', 'file', 'both']);
        if ($groups) {
            foreach ($groups as $groupid => $members) {
                $unit = $this->plan_unit($rng, $a, $people['markers'], $rng->pick($members), $groupid);
                $unit['members'] = $members;
                $a['units'][] = $unit;
            }
        } else {
            foreach ($people['students'] as $userid) {
                $a['units'][] = $this->plan_unit($rng, $a, $people['markers'], $userid, 0);
            }
        }
        return $a;
    }

    /**
     * Settings and per-group units for a peer assessment.
     *
     * Each group's submitter uploads the file and rates their peers in the same step, as the peerwork form
     * does; some or all other members then rate too. Teachers grade the group, and WebPA turns the group grade
     * and the ratings into individual grades.
     *
     * @param random $rng
     * @param array $a
     * @param int[] $markers
     * @param array $groups group id => member ids
     * @param string|int $grouping
     * @return array
     */
    protected function plan_peerwork(random $rng, array $a, array $markers, array $groups, $grouping): array {
        $o = $this->options;
        $now = $o->anchor;
        $a['grouping'] = $grouping;
        $a['criteria'] = $rng->int(2, 3);
        $a['paweighting'] = $rng->int(30, 60);
        $a['allowlate'] = $rng->chance(0.5);
        $a['justification'] = $rng->chance(0.4);
        $a['selfgrading'] = $rng->chance(0.3);
        $a['noncompletionpenalty'] = $rng->pick([0, 0, 5, 10]);

        foreach ($groups as $groupid => $members) {
            $unit = $this->empty_unit($rng->pick($members), $groupid, $members);
            $unit['raters'] = [];
            $unit['revised'] = [];
            $unit['releasetime'] = 0;
            // Draw everything first so branches do not shift later draws.
            $r = $rng->float();
            $latedraw = $rng->float();
            $timedraw = $rng->float();
            $allrate = $rng->chance(0.6);
            $quality = [];
            foreach ($members as $m) {
                $quality[$m] = $rng->pick([1, 2, 2, 3, 3, 3, 4, 4]);
            }
            $ratedraws = [];
            foreach ($members as $m) {
                $ratedraws[$m] = ['rates' => $rng->chance(0.5), 'time' => $rng->float(), 'noise' => []];
                for ($c = 0; $c < $a['criteria']; $c++) {
                    foreach ($members as $f) {
                        $ratedraws[$m]['noise'][$c][$f] = $rng->int(-1, 1);
                    }
                }
            }
            $markdraw = $rng->float();
            $gradedraw = $rng->normal($o->grademean, $o->gradesd);
            $markerpick = $markers ? $rng->pick($markers) : 0;
            $turnaround = $rng->int(1, $o->turnaround);
            $releasedays = $rng->int(0, 3);
            $feedback = $rng->int(0, 9);
            $reviseddraws = [];
            foreach ($members as $m) {
                $reviseddraws[$m] = [$rng->chance(0.05), $rng->int(-10, 10)];
            }

            if ($a['open'] >= $now) {
                $a['units'][] = $unit;
                continue;
            }
            $elapsed = $a['due'] <= $now ? 1.0 : ($now - $a['open']) / max(1, $a['due'] - $a['open']);
            if ($r >= $o->submitrate * $elapsed) {
                $a['units'][] = $unit;
                continue;
            }
            $unit['status'] = 'submitted';
            $late = $a['allowlate'] && $a['due'] < $now && $latedraw < $o->laterate;
            $unit['late'] = $late;
            $unit['time'] = $late ? self::between($timedraw, $a['due'] + 60, min($now, $a['due'] + 7 * self::DAY))
                : self::between($timedraw, $a['open'] + 3600, min($now, $a['due']));
            $rateend = min($now, $late ? $a['due'] + 10 * self::DAY : $a['due']);
            foreach ($members as $m) {
                if ($m !== $unit['userid'] && !$allrate && !$ratedraws[$m]['rates']) {
                    continue;
                }
                $time = $m === $unit['userid'] ? $unit['time']
                    : self::between($ratedraws[$m]['time'], $unit['time'] + 60, max($unit['time'] + 120, $rateend));
                $ratings = [];
                for ($c = 0; $c < $a['criteria']; $c++) {
                    foreach ($members as $f) {
                        if ($f === $m && !$a['selfgrading']) {
                            continue;
                        }
                        $ratings[$c][$f] = max(0, min(4, $quality[$f] + $ratedraws[$m]['noise'][$c][$f]));
                    }
                }
                $unit['raters'][$m] = ['time' => min($time, $now), 'ratings' => $ratings];
            }

            $lastrating = max(array_column($unit['raters'], 'time'));
            $markfrom = max($a['due'], $lastrating);
            if ($markfrom >= $now || !$markers) {
                $a['units'][] = $unit;
                continue;
            }
            $days = ($now - $markfrom) / self::DAY;
            $overdue = $days > $o->turnaround;
            if ($markdraw >= ($overdue ? 0.97 : $days / $o->turnaround)) {
                $a['units'][] = $unit;
                continue;
            }
            $unit['marker'] = $markerpick;
            $unit['grade'] = (float)max(0, min(100, round($gradedraw)));
            $unit['feedback'] = $feedback;
            $unit['gradetime'] = $overdue ? min($now, $markfrom + $turnaround * self::DAY)
                : self::between($timedraw, $markfrom + 3600, $now);
            if ($overdue) {
                $unit['releasetime'] = min($now, $unit['gradetime'] + $releasedays * self::DAY);
            }
            foreach ($members as $m) {
                if ($reviseddraws[$m][0]) {
                    $unit['revised'][$m] = (float)max(0, min(100, $unit['grade'] + $reviseddraws[$m][1]));
                }
            }
            $a['units'][] = $unit;
        }
        return $a;
    }

    /**
     * Settings, questions and per-student attempts for a quiz.
     *
     * The quiz closes at the due date. Answers are right with a probability set by each student's ability,
     * which is drawn from the grade distribution, so quiz marks land near the grade mean.
     *
     * @param random $rng
     * @param array $a
     * @param int[] $markers
     * @param int[] $students
     * @return array
     */
    protected function plan_quiz(random $rng, array $a, array $markers, array $students): array {
        $o = $this->options;
        $now = $o->anchor;
        $count = $rng->int(4, 8);
        $a['questions'] = [];
        for ($q = 0; $q < $count; $q++) {
            $a['questions'][] = $rng->pick(['multichoice', 'truefalse', 'shortanswer']);
        }
        if ($rng->chance(1 / 3)) {
            $a['questions'][] = 'essay';
        }
        $a['timelimit'] = $rng->chance(0.4) ? $rng->int(20, 60) * 60 : 0;
        $a['attempts'] = $rng->chance(0.3) ? $rng->int(2, 3) : 1;
        $essayslot = in_array('essay', $a['questions']) ? count($a['questions']) : 0;

        foreach ($students as $userid) {
            $unit = $this->empty_unit($userid, 0, [$userid]);
            $unit['attempts'] = [];
            $unit['override'] = 0;
            $ability = max(0.05, min(0.98, $rng->normal($o->grademean, $o->gradesd) / 100));
            $r = $rng->float();
            $overridedraw = $rng->float();
            $overridedays = $rng->int(2, 14);
            $lateoverride = $rng->chance(0.7);
            $nattempts = $rng->int(1, $a['attempts']);
            $draws = [];
            for ($n = 0; $n < $a['attempts']; $n++) {
                $draws[$n] = ['start' => $rng->float(), 'length' => $rng->float(), 'answers' => []];
                foreach (array_keys($a['questions']) as $q) {
                    $draws[$n]['answers'][$q] = $rng->float();
                }
            }
            $markdraw = $rng->float();
            $essaymark = max(0, min(1, $rng->normal($ability, 0.15)));
            $markerpick = $markers ? $rng->pick($markers) : 0;
            $turnaround = $rng->int(1, $o->turnaround);
            $feedback = $rng->int(0, 9);

            if ($a['open'] >= $now) {
                $a['units'][] = $unit;
                continue;
            }
            $close = $a['due'];
            if ($close < $now && $overridedraw < $o->extensionrate) {
                $unit['override'] = $close + $overridedays * self::DAY;
                $unit['extensiontime'] = $close - (int)(($close - $a['open']) * 0.2);
                $unit['extension'] = $unit['override'];
                $close = $unit['override'];
            }
            $elapsed = $a['due'] <= $now ? 1.0 : ($now - $a['open']) / max(1, $a['due'] - $a['open']);
            $isopen = $close > $now;
            $state = 'none';
            if ($r < $o->submitrate * $elapsed) {
                $state = 'finished';
            } else if ($r < ($o->submitrate + $o->draftrate) * $elapsed) {
                $state = $isopen ? 'inprogress' : ($a['timelimit'] ? 'abandoned' : 'none');
            }
            if ($state === 'none') {
                $a['units'][] = $unit;
                continue;
            }
            $from = $a['open'] + 600;
            $windowend = min($now, $close);
            if ($unit['override'] && $lateoverride && $state === 'finished') {
                $from = max($from, $a['due'] + 60);
                $unit['late'] = true;
            }
            $attempts = $state === 'finished' ? $nattempts : 1;
            $slotlength = max(3600, (int)(($windowend - $from) / $attempts));
            for ($n = 0; $n < $attempts; $n++) {
                $start = min($windowend - 600, $from + $n * $slotlength + (int)($draws[$n]['start'] * $slotlength * 0.5));
                if ($state === 'inprogress' && $a['timelimit']) {
                    // A timed attempt still in progress must have started within its time limit.
                    $start = $now - (int)($a['timelimit'] * 0.5 * $draws[$n]['start']) - 60;
                }
                $start = max($a['open'] + 60, $start);
                $length = $a['timelimit'] ? (int)($a['timelimit'] * (0.4 + 0.55 * $draws[$n]['length']))
                    : (int)(300 + 2100 * $draws[$n]['length']);
                $finish = min($start + $length, $now);
                $last = $n === $attempts - 1;
                $answers = [];
                foreach (array_keys($a['questions']) as $q) {
                    $answers[$q + 1] = $draws[$n]['answers'][$q] < min(0.99, $ability + 0.05 * $n);
                }
                $unit['attempts'][] = [
                    'start' => $start,
                    'finish' => $finish,
                    'state' => $last ? $state : 'finished',
                    'answers' => $answers,
                ];
            }
            $lastattempt = end($unit['attempts']);
            $unit['status'] = $state;
            $unit['time'] = $lastattempt['state'] === 'finished' ? $lastattempt['finish'] : $lastattempt['start'];

            // Essays are marked by a teacher on the same turnaround as assignments; everything else is automatic.
            if ($state !== 'finished') {
                $a['units'][] = $unit;
                continue;
            }
            if (!$essayslot) {
                $unit['grade'] = -1.0;
                $a['units'][] = $unit;
                continue;
            }
            $markfrom = max($a['due'], $unit['time']);
            if ($markfrom < $now && $markers) {
                $days = ($now - $markfrom) / self::DAY;
                $overdue = $days > $o->turnaround;
                if ($markdraw < ($overdue ? 0.97 : $days / $o->turnaround)) {
                    $unit['grade'] = round($essaymark, 2);
                    $unit['marker'] = $markerpick;
                    $unit['feedback'] = $feedback;
                    $unit['gradetime'] = $overdue ? min($now, $markfrom + $turnaround * self::DAY)
                        : self::between($markdraw, $markfrom + 3600, $now);
                }
            }
            $a['units'][] = $unit;
        }
        $a['essayslot'] = $essayslot;
        return $a;
    }

    /**
     * A unit where nothing has happened yet.
     *
     * @param int $userid
     * @param string|int $groupid
     * @param int[] $members
     * @return array
     */
    protected function empty_unit(int $userid, $groupid, array $members): array {
        return [
            'userid' => $userid,
            'groupid' => $groupid,
            'members' => $members,
            'status' => 'none',
            'time' => 0,
            'late' => false,
            'extension' => 0,
            'extensiontime' => 0,
            'allocatedmarker' => 0,
            'grade' => null,
            'gradetime' => 0,
            'marker' => 0,
            'workflowstate' => null,
            'feedback' => 0,
        ];
    }

    /**
     * What one student, or one group through one of its members, does on one assessment.
     *
     * @param random $rng
     * @param array $a the assessment being planned
     * @param int[] $markers
     * @param int $userid the student who acts (the submitting member for a group)
     * @param string|int $groupid 0 unless team submission
     * @return array
     */
    protected function plan_unit(random $rng, array $a, array $markers, int $userid, $groupid): array {
        $o = $this->options;
        $now = $o->anchor;
        $unit = [
            'userid' => $userid,
            'groupid' => $groupid,
            'members' => [$userid],
            'status' => 'none',
            'time' => 0,
            'late' => false,
            'extension' => 0,
            'extensiontime' => 0,
            'allocatedmarker' => 0,
            'grade' => null,
            'gradetime' => 0,
            'marker' => 0,
            'workflowstate' => null,
            'feedback' => '',
        ];
        if ($a['allocation']) {
            $unit['allocatedmarker'] = $rng->pick($markers);
        }
        // Draw every random number whatever the branch, so one setting changing does not reshuffle the rest.
        $r = $rng->float();
        $latedraw = $rng->float();
        $extdraw = $rng->float();
        $extdays = $rng->int(2, 14);
        $timedraw = $rng->float();
        $markdraw = $rng->float();
        $gradedraw = $rng->normal($o->grademean, $o->gradesd);
        $markerpick = $markers ? $rng->pick($markers) : 0;
        $statepick = $rng->pick(self::WORKFLOW_INPROGRESS);
        $turnaround = $rng->int(1, $o->turnaround);
        $feedback = $rng->int(0, 9);

        if ($a['open'] >= $now) {
            return $unit;
        }
        if ($a['due'] < $now && !$groupid && $extdraw < $o->extensionrate) {
            $unit['extension'] = $a['due'] + $extdays * self::DAY;
            $unit['extensiontime'] = $a['due'] - (int)(($a['due'] - $a['open']) * 0.2);
        }

        // Before the deadline only part of the cohort has acted yet.
        $elapsed = $a['due'] <= $now ? 1.0 : ($now - $a['open']) / max(1, $a['due'] - $a['open']);
        if ($r < $o->draftrate * $elapsed) {
            $unit['status'] = 'draft';
            $unit['time'] = self::between($timedraw, $a['open'], min($now, $a['due']));
            return $unit;
        }
        if ($r >= ($o->draftrate + $o->submitrate) * $elapsed) {
            return $unit;
        }

        $unit['status'] = 'submitted';
        $lateend = $now;
        if ($unit['extension']) {
            $lateend = min($lateend, $unit['extension']);
        } else if ($a['cutoff']) {
            $lateend = min($lateend, $a['cutoff']);
        }
        $late = $a['due'] < $now && $lateend > $a['due'] + 3600
            && ($latedraw < $o->laterate || ($unit['extension'] && $latedraw < 0.7));
        if ($late) {
            $unit['late'] = true;
            $unit['time'] = self::between($timedraw, $a['due'] + 60, $lateend);
        } else {
            $unit['time'] = self::between($timedraw, $a['open'] + 3600, min($now, $a['due']));
        }

        // Marking starts once the deadline has passed and finishes over the turnaround period.
        $markfrom = max($a['due'], $unit['extension'], $unit['time']);
        if ($markfrom >= $now || !$markers) {
            return $unit;
        }
        $days = ($now - $markfrom) / self::DAY;
        $overdue = $days > $o->turnaround;
        $pmarked = $overdue ? 0.97 : $days / $o->turnaround;
        if ($markdraw >= $pmarked) {
            return $unit;
        }
        $unit['marker'] = $unit['allocatedmarker'] ?: $markerpick;
        $unit['grade'] = (float)max(0, min(100, round($gradedraw)));
        if ($overdue) {
            $unit['gradetime'] = min($now, $markfrom + $turnaround * self::DAY);
            $unit['workflowstate'] = $a['workflow'] ? 'released' : null;
        } else {
            $unit['gradetime'] = self::between($timedraw, $markfrom + 3600, $now);
            $unit['workflowstate'] = $a['workflow'] ? $statepick : null;
        }
        $unit['feedback'] = $feedback;
        return $unit;
    }

    /**
     * A time between two others, from a draw in [0, 1).
     *
     * @param float $draw
     * @param int $from
     * @param int $to
     * @return int
     */
    protected static function between(float $draw, int $from, int $to): int {
        if ($to <= $from) {
            return $from;
        }
        return $from + (int)floor($draw * ($to - $from));
    }

    /**
     * Totals for a plan, for previews and run summaries.
     *
     * @param array $plan from plan_course()
     * @return array<string, int>
     */
    public static function summarise(array $plan): array {
        $t = ['assessments' => 0, 'submitted' => 0, 'late' => 0, 'drafts' => 0, 'grades' => 0, 'released' => 0,
            'extensions' => 0, 'assign' => 0, 'peerwork' => 0, 'quiz' => 0, 'newgroups' => $plan['newgroups'] ?? 0,
            'enrolled' => $plan['enrolled'] ?? 0, 'summative' => 0, 'formative' => 0];
        foreach ($plan['assessments'] as $a) {
            self::add_to_summary($t, $a);
        }
        return $t;
    }

    /**
     * Add one assessment, with its units, to summary totals.
     *
     * @param array $t totals from summarise()
     * @param array $a
     */
    protected static function add_to_summary(array &$t, array $a): void {
        $t['assessments']++;
        $t[$a['type']] = ($t[$a['type']] ?? 0) + 1;
        $t[!empty($a['formative']) ? 'formative' : 'summative']++;
        foreach ($a['units'] as $u) {
            $t['submitted'] += in_array($u['status'], ['submitted', 'finished']) ? 1 : 0;
            $t['late'] += $u['late'] ? 1 : 0;
            $t['drafts'] += in_array($u['status'], ['draft', 'inprogress']) ? 1 : 0;
            $t['grades'] += $u['grade'] !== null ? 1 : 0;
            if ($u['grade'] !== null) {
                $released = match ($a['type']) {
                    'peerwork' => $u['releasetime'] > 0,
                    'assign' => !$a['workflow'] || $u['workflowstate'] === 'released',
                    default => true,
                };
                $t['released'] += $released ? 1 : 0;
            }
            $t['extensions'] += $u['extension'] ? 1 : 0;
        }
    }
}
