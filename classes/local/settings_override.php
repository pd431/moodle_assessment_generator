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
 * Overrides plugin settings for this request only, without writing them to the database.
 *
 * Uses the same mechanism as $CFG->forced_plugin_settings in config.php, so other processes (cron, other
 * background tasks, web requests) never see the change and nothing is left behind if the run fails.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_override {
    /**
     * Run code with some plugin settings forced.
     *
     * @param array<string, array<string, mixed>> $overrides plugin => [name => value]
     * @param callable $fn
     * @return mixed what $fn returns
     */
    public static function with(array $overrides, callable $fn) {
        global $CFG;
        $saved = [];
        foreach ($overrides as $plugin => $settings) {
            foreach ($settings as $name => $value) {
                $had = isset($CFG->forced_plugin_settings[$plugin]) &&
                    array_key_exists($name, $CFG->forced_plugin_settings[$plugin]);
                $saved[] = [$plugin, $name, $had, $had ? $CFG->forced_plugin_settings[$plugin][$name] : null];
                $CFG->forced_plugin_settings[$plugin][$name] = $value;
            }
        }
        try {
            return $fn();
        } finally {
            foreach (array_reverse($saved) as [$plugin, $name, $had, $value]) {
                if ($had) {
                    $CFG->forced_plugin_settings[$plugin][$name] = $value;
                } else {
                    unset($CFG->forced_plugin_settings[$plugin][$name]);
                }
            }
        }
    }
}
