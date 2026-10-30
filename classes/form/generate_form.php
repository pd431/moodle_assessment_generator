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

namespace tool_assessmentgen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Run settings, and after a preview, the course list to tick.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $d = \tool_assessmentgen\local\options::DEFAULTS;
        $courses = $this->_customdata['courses'] ?? [];

        $mform->addElement('text', 'match', get_string('coursematch', 'tool_assessmentgen'));
        $mform->setType('match', PARAM_TEXT);
        $mform->addHelpButton('match', 'coursematch', 'tool_assessmentgen');

        // For a site with no courses to match.
        $mform->addElement('text', 'createcourses', get_string('createcourses', 'tool_assessmentgen'), ['size' => 6]);
        $mform->setType('createcourses', PARAM_INT);
        $mform->setDefault('createcourses', 0);
        $mform->addHelpButton('createcourses', 'createcourses', 'tool_assessmentgen');
        $mform->addElement('text', 'term', get_string('term', 'tool_assessmentgen'), ['size' => 10]);
        $mform->setType('term', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('term', 'term', 'tool_assessmentgen');

        $types = [];
        foreach (array_keys(\tool_assessmentgen\local\registry::drivers()) as $type) {
            $types[] = $mform->createElement('advcheckbox', $type, '', \tool_assessmentgen\local\registry::display_name($type));
            $mform->setDefault("types[$type]", 1);
        }
        $mform->addGroup($types, 'types', get_string('activitytypes', 'tool_assessmentgen'), ' ', true);

        $mform->addElement('select', 'groups', get_string('groups', 'tool_assessmentgen'), [
            'missing' => get_string('groupsmissing', 'tool_assessmentgen'),
            'always' => get_string('groupsalways', 'tool_assessmentgen'),
            'never' => get_string('groupsnever', 'tool_assessmentgen'),
        ]);
        $mform->setDefault('groups', 'missing');

        $mform->addElement('select', 'enrol', get_string('enrol', 'tool_assessmentgen'), [
            'missing' => get_string('enrolmissing', 'tool_assessmentgen'),
            'never' => get_string('enrolnever', 'tool_assessmentgen'),
        ]);
        $mform->setDefault('enrol', 'missing');
        $mform->addHelpButton('enrol', 'enrol', 'tool_assessmentgen');

        foreach (['studentsmin', 'studentsmax', 'studentsmean', 'pool'] as $name) {
            $mform->addElement('text', $name, get_string($name, 'tool_assessmentgen'), ['size' => 6]);
            $mform->setType($name, PARAM_INT);
            $mform->setDefault($name, 0);
        }
        $mform->addHelpButton('studentsmax', 'students', 'tool_assessmentgen');
        $mform->addHelpButton('pool', 'pool', 'tool_assessmentgen');
        foreach (['markersmin', 'markersmax', 'staffpool'] as $name) {
            $mform->addElement('text', $name, get_string($name, 'tool_assessmentgen'), ['size' => 6]);
            $mform->setType($name, PARAM_INT);
            $mform->setDefault($name, 0);
        }
        $mform->addHelpButton('markersmax', 'markersmax', 'tool_assessmentgen');

        $ints = ['minper', 'maxper', 'partsize', 'duefrom', 'dueto', 'turnaround', 'seed'];
        $floats = ['submitrate', 'laterate', 'draftrate', 'extensionrate', 'cutoffrate', 'workflowrate',
            'allocationrate', 'blindrate', 'teamrate', 'formativerate', 'grademean', 'gradesd'];
        foreach (array_merge($ints, $floats) as $name) {
            $mform->addElement('text', $name, get_string($name, 'tool_assessmentgen'), ['size' => 6]);
            $mform->setType($name, in_array($name, $ints) ? PARAM_INT : PARAM_FLOAT);
            $mform->setDefault($name, $d[$name]);
        }
        $mform->addHelpButton('seed', 'seed', 'tool_assessmentgen');
        $mform->addHelpButton('partsize', 'partsize', 'tool_assessmentgen');
        foreach (['summativetag', 'formativetag'] as $name) {
            $mform->addElement('text', $name, get_string($name, 'tool_assessmentgen'));
            $mform->setType($name, PARAM_TAG);
            $mform->addHelpButton($name, $name, 'tool_assessmentgen');
        }
        $mform->addElement('text', 'tag', get_string('runtag', 'tool_assessmentgen'));
        $mform->setType('tag', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('tag', 'runtag', 'tool_assessmentgen');

        // A preview fixes the seed and tag, so pressing Generate builds exactly what was previewed.
        if (!empty($this->_customdata['seed'])) {
            $mform->setConstant('seed', $this->_customdata['seed']);
            $mform->setConstant('tag', $this->_customdata['tag']);
        }

        if ($courses) {
            $mform->addElement(
                'header',
                'courseshdr',
                get_string('coursesmatch', 'tool_assessmentgen', count($courses))
            );
            $mform->setExpanded('courseshdr');
            foreach ($courses as $id => $label) {
                // Plain checkboxes: an unticked box sends nothing, which reads as unticked. A fresh preview
                // ticks every course regardless of what was submitted before.
                $mform->addElement('checkbox', "course[$id]", '', $label);
                if (!empty($this->_customdata['tickall'])) {
                    $mform->setConstant("course[$id]", 1);
                }
            }
        }

        $buttons = [$mform->createElement('submit', 'previewbutton', get_string('preview', 'tool_assessmentgen'))];
        if ($courses || !empty($this->_customdata['create'])) {
            $buttons[] = $mform->createElement('submit', 'generatebutton', get_string('generate', 'tool_assessmentgen'));
        }
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
    }

    /**
     * Validate through the options class so the page and the CLI agree.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        try {
            \tool_assessmentgen\local\options::from_form((object)$data);
        } catch (\invalid_parameter_exception $e) {
            $errors['match'] = $e->debuginfo ?: $e->getMessage();
        }
        return $errors;
    }
}
