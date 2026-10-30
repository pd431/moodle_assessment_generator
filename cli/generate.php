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

/**
 * Generate assessments, submissions and marks on existing courses.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_assessmentgen\local\options;
use tool_assessmentgen\local\run_manager;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$opts, $unrecognised] = cli_get_params([
    'match' => '',
    'courseids' => '',
    'per-course' => '2-6',
    'part-size' => 10,
    'types' => 'assign,peerwork,quiz',
    'groups' => 'missing',
    'enrol' => 'missing',
    'students' => '',
    'students-mean' => 0,
    'pool' => 0,
    'create-courses' => 0,
    'term' => '',
    'markers' => '',
    'staff-pool' => 0,
    'due' => '-90,120',
    'open' => '7-28',
    'submit-rate' => 0.8,
    'late-rate' => 0.1,
    'draft-rate' => 0.05,
    'extension-rate' => 0.05,
    'cutoff-rate' => 0.4,
    'workflow-rate' => 0.4,
    'allocation-rate' => 0.5,
    'blind-rate' => 0.15,
    'team-rate' => 0.15,
    'formative-rate' => 0.3,
    'summative-tag' => '',
    'formative-tag' => '',
    'turnaround' => 21,
    'grade-mean' => 62,
    'grade-sd' => 11,
    'seed' => 0,
    'tag' => '',
    'anchor' => '',
    'dry-run' => false,
    'queue' => false,
    'help' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($opts['help'] || ($opts['match'] === '' && $opts['courseids'] === '' && !(int)$opts['create-courses'])) {
    echo "Add random assignments, quizzes and peer assessments to courses, submit for some students and mark some
of the work.

Existing courses are never created or deleted, and users only with --students or --markers. On a brand-new site,
--create-courses makes the courses and their students and staff too. Groups and enrolments are added where needed
(see --groups and --enrol), and purge.php removes them, and anything the run created, with the activities.

Options:
  --match=TEXT          Courses whose full name, short name or ID number contains TEXT
  --courseids=1,2,3     Or an explicit list of course ids
  --per-course=2-6      Assessments added to each course
  --part-size=10        Assessments per background task with --queue; several cron workers can then build one
                        course at once
  --types=assign,peerwork,quiz  Activity types (comma-separated); ones not installed are skipped
  --groups=missing      Create groups where a course has none (missing), in every course (always) or never
  --enrol=missing       Enrol existing people into courses with no students (15-40 people who hold only student
                        roles) or no markers (1-3 people who hold a marker role somewhere); never turns this off
  --students=60-400     Create student accounts and top every course up to a cohort size in this range; existing
                        students count towards it. Accounts are agen-<tag>-0001 and so on, with @example.invalid
                        emails, email stopped and one password per run, printed once. Purge deletes them.
  --students-mean=150   Mean cohort size (default the middle of the range)
  --pool=N              Accounts in the run's shared pool; courses draw their cohorts from it, so students sit
                        in several courses (default: enough for about 4 courses each)
  --create-courses=N    Create N courses in a new category \"Generated courses (<tag>)\", named like real modules
                        (\"History 101 202526\", short name HIS101-202526) and dated to cover the assessments.
                        --match becomes optional. Turns on --students=20-60 (mean 35) and --markers=1-3 unless
                        given. Purge deletes the courses and the category.
  --term=202526         Term in created course names (default the academic year of the anchor date)
  --markers=1-3         Create staff accounts (agen-<tag>-staff-0001 and so on) and give every course with no
                        markers one convenor (editing teacher) and this many markers (non-editing teachers)
                        from the run's staff pool, so markers work on several courses
  --staff-pool=N        Staff accounts in the pool (default: enough for about 3 courses each)
  --due=-90,120         Due dates between these day offsets from the anchor date
  --open=7-28           Days each assessment opens before its due date
  --submit-rate=0.8     Share of students who submit (scaled down before the due date)
  --late-rate=0.1       Share of submissions made after the due date
  --draft-rate=0.05     Share of students who leave a draft
  --extension-rate=0.05 Share of students given an extension on past-due work
  --cutoff-rate=0.4     Share of assessments with a cut-off date
  --workflow-rate=0.4   Share of assessments using marking workflow
  --allocation-rate=0.5 Share of workflow assessments using marker allocation
  --blind-rate=0.15     Share of assessments using blind marking
  --team-rate=0.15      Share of assessments using group submission (courses with groups get at least one)
  --formative-rate=0.3  Share of activities tagged formative (practice quizzes always are); the rest are summative
  --summative-tag=NAME  Tag for summative activities (default: the first summative tag set in block_le_dashboard,
                        else in block_my_assessments, else \"Summative Assessment\")
  --formative-tag=NAME  Tag for formative activities (default: the first formative tag set in the same order,
                        else \"Formative Assessment\")
  --turnaround=21       Days markers take; work due longer ago than this is marked and released
  --grade-mean=62 --grade-sd=11
  --seed=N              Same seed, settings and enrolments give the same plan (default random)
  --tag=NAME            Run tag used in activity ID numbers (default run<seed>)
  --anchor=YYYY-MM-DD   Treat this date as today (default now)
  --dry-run             Print the plan and write nothing
  --queue               Queue background tasks (one per part of each course) instead of running here.
                        Stop a run with cli/stop.php, or with Stop on the admin page.

Example:
  php admin/tool/assessmentgen/cli/generate.php --match=202526 --per-course=2-6 --seed=42 --tag=trial1
";
    exit(0);
}

\tool_assessmentgen\local\access::allowed() || cli_error(get_string('disabled', 'tool_assessmentgen'));

$range = function (string $value, string $sep): array {
    $parts = array_map('trim', explode($sep, $value, 2));
    return [(int)$parts[0], (int)($parts[1] ?? $parts[0])];
};
[$minper, $maxper] = $range($opts['per-course'], '-');
[$duefrom, $dueto] = $range($opts['due'], ',');
[$openmin, $openmax] = $range($opts['open'], '-');
[$studentsmin, $studentsmax] = $opts['students'] === '' ? [0, 0] : $range($opts['students'], '-');
[$markersmin, $markersmax] = $opts['markers'] === '' ? [0, 0] : $range($opts['markers'], '-');

try {
    $options = new options([
        'match' => $opts['match'],
        'courseids' => $opts['courseids'] === '' ? [] : explode(',', $opts['courseids']),
        'types' => $opts['types'],
        'groups' => $opts['groups'],
        'enrol' => $opts['enrol'],
        'studentsmin' => $studentsmin,
        'studentsmax' => $studentsmax,
        'studentsmean' => $opts['students-mean'],
        'pool' => $opts['pool'],
        'createcourses' => $opts['create-courses'],
        'term' => $opts['term'],
        'markersmin' => $markersmin,
        'markersmax' => $markersmax,
        'staffpool' => $opts['staff-pool'],
        'minper' => $minper,
        'maxper' => $maxper,
        'partsize' => $opts['part-size'],
        'duefrom' => $duefrom,
        'dueto' => $dueto,
        'openmin' => $openmin,
        'openmax' => $openmax,
        'submitrate' => $opts['submit-rate'],
        'laterate' => $opts['late-rate'],
        'draftrate' => $opts['draft-rate'],
        'extensionrate' => $opts['extension-rate'],
        'cutoffrate' => $opts['cutoff-rate'],
        'workflowrate' => $opts['workflow-rate'],
        'allocationrate' => $opts['allocation-rate'],
        'blindrate' => $opts['blind-rate'],
        'teamrate' => $opts['team-rate'],
        'formativerate' => $opts['formative-rate'],
        'summativetag' => $opts['summative-tag'],
        'formativetag' => $opts['formative-tag'],
        'turnaround' => $opts['turnaround'],
        'grademean' => $opts['grade-mean'],
        'gradesd' => $opts['grade-sd'],
        'seed' => $opts['seed'],
        'tag' => $opts['tag'],
        'anchor' => $opts['anchor'] === '' ? 0 : strtotime($opts['anchor'] . ' 12:00'),
    ]);
} catch (invalid_parameter_exception $e) {
    cli_error($e->debuginfo ?: $e->getMessage());
}

\core\session\manager::set_user(get_admin());

$preview = run_manager::preview($options);
foreach ($preview['unavailable'] as $type => $reason) {
    cli_writeln(get_string($reason, 'tool_assessmentgen', \tool_assessmentgen\local\registry::display_name($type)));
}
foreach ($preview['checks'] as $check) {
    cli_writeln(($check['level'] === 'fatal' ? 'Cannot run: ' : 'Warning: ') . $check['message']);
}
cli_writeln(get_string('coursesmatch', 'tool_assessmentgen', count($preview['courses']) - $preview['create']) .
    " (seed {$options->seed}, tag {$options->tag})");
if ($preview['create']) {
    cli_writeln(get_string('previewcreate', 'tool_assessmentgen', (object)['count' => $preview['create'],
        'category' => get_string('categoryname', 'tool_assessmentgen', $options->tag)]));
}
cli_writeln("Activities are tagged \"{$options->summativetag}\" or \"{$options->formativetag}\".");
if ($preview['students']) {
    cli_writeln(get_string('previewstudents', 'tool_assessmentgen', (object)$preview['students']));
}
if ($preview['staff']) {
    cli_writeln(get_string('previewstaff', 'tool_assessmentgen', (object)$preview['staff']));
}
foreach ($preview['courses'] as $plan) {
    foreach ($plan['warnings'] as $warning) {
        if (in_array($warning, ['nostudents', 'nomarkers', 'nogroups'])) {
            cli_writeln('  ! ' . get_string($warning, 'tool_assessmentgen', $plan['course']->shortname));
        }
    }
}

if ($opts['dry-run']) {
    foreach ($preview['courses'] as $plan) {
        cli_writeln($plan['course']->shortname . ": {$plan['students']} students, {$plan['markers']} markers, " .
            "{$plan['groups']} groups ({$plan['newgroups']} new), " . ($plan['enrolled'] ?? 0) . ' people to enrol');
        foreach ($plan['assessments'] as $a) {
            $flags = array_keys(array_filter(['team' => $a['team'], 'workflow' => $a['workflow'] ?? false,
                'allocation' => $a['allocation'] ?? false, 'blind' => $a['blind'] ?? false,
                'timed' => $a['timelimit'] ?? false, 'essay' => !empty($a['essayslot']),
                'late-allowed' => $a['allowlate'] ?? false, 'formative' => $a['formative'] ?? false]));
            cli_writeln(sprintf(
                '  %-8s %-40s due %s%s  %s',
                $a['type'],
                $a['name'],
                date('Y-m-d', $a['due']),
                $a['cutoff'] ? ' cut-off ' . date('Y-m-d', $a['cutoff']) : '',
                implode(',', $flags)
            ));
        }
    }
    cli_writeln(get_string('previewtotals', 'tool_assessmentgen', (object)$preview['totals']));
    exit(0);
}

try {
    $run = run_manager::create($options);
} catch (moodle_exception $e) {
    cli_error($e->getMessage());
}
if ($run->password) {
    cli_writeln(get_string('poolpassword', 'tool_assessmentgen', $run->password));
}
if ($opts['queue']) {
    run_manager::queue($run);
    cli_writeln(get_string('queued', 'tool_assessmentgen'));
    exit(0);
}

$started = time();
$run = run_manager::setup($run, fn($course) => cli_writeln('Created course ' . $course->shortname . ': ' .
    (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false)));
run_manager::execute($run, function ($course, $counts) use ($run) {
    foreach (run_manager::course_report((int)$run->id, (int)$course->id) as $line) {
        cli_writeln($line);
    }
});
$totals = run_manager::totals((int)$run->id);
$run = run_manager::get_by_tag($run->tag);
cli_writeln(sprintf(
    '%s in %ds. Courses %d, assessments %d, submissions %d, drafts %d, marks %d, extensions %d.',
    $run->status === 'stopped' ? 'Stopped' : 'Done',
    time() - $started,
    count(run_manager::options_for($run)->courseids),
    $totals['assessments'],
    $totals['submitted'],
    $totals['drafts'],
    $totals['grades'],
    $totals['extensions']
));
