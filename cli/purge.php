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
 * Remove everything a run created.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_assessmentgen\local\run_manager;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$opts, $unrecognised] = cli_get_params(['tag' => '', 'all' => false, 'list' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($opts['help'] || (!$opts['tag'] && !$opts['all'] && !$opts['list'])) {
    echo "Delete everything a generator run made: its activities with their submissions, grades and calendar events,
its groups, its enrolments and any student accounts it created. Courses, other users and hand-made activities
are never touched. The run is stopped first. Anything that fails to delete is listed and skipped; run the
purge again to retry it.

  --list       List runs
  --tag=NAME   Purge one run
  --all        Purge every run
";
    exit(0);
}

\tool_assessmentgen\local\access::allowed() || cli_error(get_string('disabled', 'tool_assessmentgen'));
\core\session\manager::set_user(get_admin());

if ($opts['list']) {
    foreach ($DB->get_records('tool_assessmentgen_run', null, 'id') as $run) {
        $t = run_manager::totals((int)$run->id);
        cli_writeln(sprintf(
            '%-20s %-15s seed %-7d parts %d/%d, assessments %d, submissions %d, marks %d %s',
            $run->tag,
            $run->status,
            $run->seed,
            $run->coursesdone,
            $run->coursestotal,
            $t['assessments'],
            $t['submitted'],
            $t['grades'],
            $run->error ? '! ' . $run->error : ''
        ));
    }
    exit(0);
}

$runs = $opts['all'] ? $DB->get_records('tool_assessmentgen_run') : [run_manager::get_by_tag($opts['tag'])];
$failed = false;
foreach (array_filter($runs) as $run) {
    if ($run->status === 'purged') {
        continue;
    }
    $report = \tool_assessmentgen\local\purger::purge($run);
    cli_writeln("{$run->tag}: deleted {$report['activities']} activities, {$report['groups']} groups, " .
        "{$report['enrolments']} enrolments, {$report['accounts']} accounts and {$report['courses']} courses.");
    foreach ($report['errors'] as $error) {
        cli_writeln("  could not delete $error");
        $failed = true;
    }
}
if (!array_filter($runs)) {
    cli_error('No such run.');
}
if ($failed) {
    cli_error('Some things could not be deleted; run the purge again to retry them.');
}
