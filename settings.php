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
 * Admin settings and page registration.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('development', new admin_externalpage(
        'tool_assessmentgen',
        get_string('pluginname', 'tool_assessmentgen'),
        new moodle_url('/admin/tool/assessmentgen/index.php'),
        'moodle/site:config'
    ));

    $settings = new admin_settingpage('tool_assessmentgen_settings', get_string('settings', 'tool_assessmentgen'));
    $settings->add(new admin_setting_configtext(
        'tool_assessmentgen/studentroles',
        get_string('studentroles', 'tool_assessmentgen'),
        get_string('studentroles_desc', 'tool_assessmentgen'),
        'student',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_assessmentgen/markerroles',
        get_string('markerroles', 'tool_assessmentgen'),
        get_string('markerroles_desc', 'tool_assessmentgen'),
        'editingteacher,teacher',
        PARAM_TEXT
    ));
    $ADMIN->add('development', $settings);
}
