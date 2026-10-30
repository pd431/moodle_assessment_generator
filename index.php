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
 * Admin page: pick courses, preview, generate, and manage runs.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_assessmentgen\form\generate_form;
use tool_assessmentgen\local\access;
use tool_assessmentgen\local\options;
use tool_assessmentgen\local\purger;
use tool_assessmentgen\local\run_manager;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_assessmentgen');
$url = new moodle_url('/admin/tool/assessmentgen/index.php');
$title = get_string('pluginname', 'tool_assessmentgen');

if (!access::allowed()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($title);
    echo $OUTPUT->notification(get_string('disabled', 'tool_assessmentgen'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$purgeid = optional_param('purge', 0, PARAM_INT);
if ($purgeid) {
    $run = $DB->get_record('tool_assessmentgen_run', ['id' => $purgeid], '*', MUST_EXIST);
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        // In the background: a large run can take longer to delete than a page request is allowed.
        purger::request($run);
        redirect(
            $url,
            get_string('purgequeued', 'tool_assessmentgen', s($run->tag)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('purgeconfirm', 'tool_assessmentgen', s($run->tag)),
        new moodle_url($url, ['purge' => $run->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}
foreach (['stop', 'resume'] as $action) {
    $runid = optional_param($action, 0, PARAM_INT);
    if ($runid && confirm_sesskey()) {
        $run = $DB->get_record('tool_assessmentgen_run', ['id' => $runid], '*', MUST_EXIST);
        if ($action === 'stop') {
            run_manager::stop($run);
        } else {
            run_manager::resume($run);
        }
        redirect(
            $url,
            get_string($action . 'done', 'tool_assessmentgen', s($run->tag)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// The course list depends on the match being previewed, so work it out before building the form.
$customdata = [];
$preview = null;
$options = null;
if (optional_param('previewbutton', '', PARAM_RAW) || optional_param('generatebutton', '', PARAM_RAW)) {
    require_sesskey();
    $raw = (object)[
        'match' => optional_param('match', '', PARAM_TEXT),
        'types' => optional_param_array('types', ['assign' => 1], PARAM_INT),
        'groups' => optional_param('groups', 'missing', PARAM_ALPHA),
        'enrol' => optional_param('enrol', 'missing', PARAM_ALPHA),
        'summativetag' => optional_param('summativetag', '', PARAM_TAG),
        'formativetag' => optional_param('formativetag', '', PARAM_TAG),
        'seed' => optional_param('seed', 0, PARAM_INT),
        'tag' => optional_param('tag', '', PARAM_ALPHANUMEXT),
        'term' => optional_param('term', '', PARAM_ALPHANUMEXT),
    ];
    $ints = ['studentsmin', 'studentsmax', 'studentsmean', 'pool', 'createcourses', 'markersmin', 'markersmax', 'staffpool'];
    foreach ($ints as $k) {
        $raw->$k = optional_param($k, 0, PARAM_INT);
    }
    foreach (['minper', 'maxper', 'partsize', 'duefrom', 'dueto', 'turnaround'] as $k) {
        $raw->$k = optional_param($k, options::DEFAULTS[$k], PARAM_INT);
    }
    foreach (
        ['submitrate', 'laterate', 'draftrate', 'extensionrate', 'cutoffrate', 'workflowrate', 'allocationrate',
            'blindrate', 'teamrate', 'formativerate', 'grademean', 'gradesd'] as $k
    ) {
        $raw->$k = optional_param($k, options::DEFAULTS[$k], PARAM_FLOAT);
    }
    try {
        $options = options::from_form($raw);
        // Planning many large courses can outlast the default 30-second request limit.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);
        $preview = run_manager::preview($options);
        foreach ($preview['courses'] as $plan) {
            $c = $plan['course'];
            if (!empty($c->placeholder)) {
                // Courses to be created are not ticked: the number to create decides them.
                continue;
            }
            $customdata['courses'][$c->id] = format_string($c->shortname) . ' · ' . format_string($c->fullname) .
                " ({$plan['students']} students, {$plan['markers']} markers, " .
                count($plan['assessments']) . ' assessments' .
                (!empty($plan['enrolled']) ? ', ' . $plan['enrolled'] . ' people to enrol' : '') . ')';
        }
        $customdata['tickall'] = (bool)optional_param('previewbutton', '', PARAM_RAW);
        $customdata['seed'] = $options->seed;
        $customdata['tag'] = $options->tag;
        $customdata['create'] = $preview['create'];
    } catch (invalid_parameter_exception $e) {
        // The form's own validation reports it.
        $options = null;
    }
}

$mform = new generate_form($url, $customdata);

if ($data = $mform->get_data()) {
    if (!empty($data->generatebutton) && $options) {
        $ticked = array_map('intval', array_keys(array_filter((array)($data->course ?? []))));
        if ($ticked || $options->createcourses > 0) {
            if (!$ticked) {
                // Only new courses: no existing course was ticked, so the match must not pick any.
                $data->match = '';
            }
            try {
                $run = run_manager::create(options::from_form($data, $ticked));
            } catch (moodle_exception $e) {
                redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_WARNING);
            }
            run_manager::queue($run);
            if ($run->password) {
                // Shown on the page itself rather than after a redirect, so it is not lost in a message queue.
                echo $OUTPUT->header();
                echo $OUTPUT->heading($title);
                echo $OUTPUT->notification(get_string('queuedpassword', 'tool_assessmentgen', s($run->password)), 'success');
                echo $OUTPUT->continue_button($url);
                echo $OUTPUT->footer();
                exit;
            }
            redirect($url, get_string('queued', 'tool_assessmentgen'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);

if ($preview) {
    foreach ($preview['checks'] as $check) {
        echo $OUTPUT->notification($check['message'], 'warning');
    }
    if ($preview['create']) {
        echo html_writer::tag('p', get_string('previewcreate', 'tool_assessmentgen', (object)['count' => $preview['create'],
            'category' => get_string('categoryname', 'tool_assessmentgen', $options->tag)]));
    }
    foreach ($preview['unavailable'] as $type => $reason) {
        $typename = \tool_assessmentgen\local\registry::display_name($type);
        echo $OUTPUT->notification(get_string($reason, 'tool_assessmentgen', $typename), 'warning');
    }
    $students = array_sum(array_column($preview['courses'], 'students'));
    $markers = array_sum(array_column($preview['courses'], 'markers'));
    echo html_writer::tag('p', get_string(
        'participants',
        'tool_assessmentgen',
        (object)['students' => $students, 'markers' => $markers, 'courses' => count($preview['courses'])]
    ));
    if ($preview['students']) {
        echo html_writer::tag('p', get_string('previewstudents', 'tool_assessmentgen', (object)$preview['students']));
    }
    if ($preview['staff']) {
        echo html_writer::tag('p', get_string('previewstaff', 'tool_assessmentgen', (object)$preview['staff']));
    }
    if ($preview['totals']) {
        echo html_writer::tag('p', get_string('previewtotals', 'tool_assessmentgen', (object)$preview['totals']));
    }
    foreach ($preview['courses'] as $plan) {
        foreach (array_intersect($plan['warnings'], ['nostudents', 'nomarkers', 'nogroups']) as $warning) {
            echo $OUTPUT->notification(
                get_string($warning, 'tool_assessmentgen', format_string($plan['course']->shortname)),
                'info'
            );
        }
    }
}

$mform->display();

$runs = $DB->get_records('tool_assessmentgen_run', null, 'id DESC');
if ($runs) {
    echo $OUTPUT->heading(get_string('runs', 'tool_assessmentgen'), 3);
    $table = new html_table();
    $table->head = [get_string('runtag', 'tool_assessmentgen'), get_string('status'),
        get_string('progress', 'tool_assessmentgen'), 'Assessments', 'Submissions', 'Marks', 'Extensions',
        get_string('seed', 'tool_assessmentgen'), ''];
    foreach ($runs as $run) {
        $t = run_manager::totals((int)$run->id);
        $parts = run_manager::part_counts((int)$run->id);
        $status = get_string('status' . $run->status, 'tool_assessmentgen');
        if ($run->status === 'stopped' && !empty($parts['running'])) {
            $status = get_string('statusstopping', 'tool_assessmentgen', $parts['running']);
        }
        if ($run->status === 'purging') {
            $status .= ' ' . get_string('purgeleft', 'tool_assessmentgen', $t['assessments']);
        }
        $status = s($status) . ($run->error ? html_writer::div(nl2br(s($run->error)), 'text-danger small') : '');
        $progress = get_string('partsdone', 'tool_assessmentgen', (object)['done' => $parts['done'] ?? 0,
            'total' => array_sum($parts), 'running' => $parts['running'] ?? 0, 'failed' => $parts['failed'] ?? 0]);
        $buttons = '';
        $actions = [
            'stop' => in_array($run->status, ['queued', 'running']),
            'resume' => in_array($run->status, ['stopped', 'failed']),
            'purge' => !in_array($run->status, ['purging', 'purged']),
        ];
        foreach (array_keys(array_filter($actions)) as $action) {
            $params = [$action => $run->id] + ($action === 'purge' ? [] : ['sesskey' => sesskey()]);
            $buttons .= $OUTPUT->single_button(
                new moodle_url($url, $params),
                get_string($action, 'tool_assessmentgen'),
                $action === 'purge' ? 'get' : 'post'
            );
        }
        $table->data[] = [s($run->tag), $status, $progress, $t['assessments'], $t['submitted'], $t['grades'],
            $t['extensions'], $run->seed, $buttons];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
