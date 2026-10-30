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
 * Stop a run, or resume a stopped one.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_assessmentgen\local\run_manager;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$opts, $unrecognised] = cli_get_params(['tag' => '', 'resume' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($opts['help'] || !$opts['tag']) {
    echo "Stop a generator run, in cron or in a foreground generate.php. No part starts another assessment, and parts
building one stop after it. Everything made so far stays; purge.php removes it.

  --tag=NAME   The run
  --resume     Instead, queue a stopped or failed run's unfinished parts again for cron
";
    exit(0);
}

\tool_assessmentgen\local\access::allowed() || cli_error(get_string('disabled', 'tool_assessmentgen'));
\core\session\manager::set_user(get_admin());

$run = run_manager::get_by_tag($opts['tag']) ?? cli_error('No such run.');
if ($opts['resume']) {
    run_manager::resume($run);
    cli_writeln(get_string('resumedone', 'tool_assessmentgen', $run->tag));
} else if (run_manager::stop($run)) {
    cli_writeln(get_string('stopdone', 'tool_assessmentgen', $run->tag));
} else {
    cli_error("Run {$run->tag} is {$run->status}; only a queued or running run can be stopped.");
}
