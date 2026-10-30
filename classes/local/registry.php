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

use tool_assessmentgen\local\driver\base;

/**
 * Known activity types and which of them this site can use.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /**
     * Every driver this plugin ships, keyed by module name.
     *
     * @return array<string, class-string<base>>
     */
    public static function drivers(): array {
        return [
            'assign' => driver\assign::class,
            'peerwork' => driver\peerwork::class,
            'quiz' => driver\quiz::class,
        ];
    }

    /**
     * The activity type's name for people, e.g. "Peer Assessment", falling back to the module name.
     *
     * @param string $type
     * @return string
     */
    public static function display_name(string $type): string {
        $sm = get_string_manager();
        return $sm->string_exists('modulename', 'mod_' . $type) ? get_string('modulename', 'mod_' . $type) : $type;
    }

    /**
     * The requested types that have a driver and are installed.
     *
     * @param string[] $types
     * @return string[]
     */
    public static function available(array $types): array {
        $drivers = self::drivers();
        return array_values(array_filter($types, fn($t) => isset($drivers[$t]) && $drivers[$t]::is_available()));
    }

    /**
     * Requested types that cannot be used, with the reason.
     *
     * @param string[] $types
     * @return array<string, string> type => 'nodriver' | 'notinstalled'
     */
    public static function unavailable(array $types): array {
        $drivers = self::drivers();
        $result = [];
        foreach ($types as $t) {
            if (!isset($drivers[$t])) {
                $result[$t] = 'nodriver';
            } else if (!$drivers[$t]::is_available()) {
                $result[$t] = 'notinstalled';
            }
        }
        return $result;
    }

    /**
     * A driver instance.
     *
     * @param string $type
     * @return base
     */
    public static function driver(string $type): base {
        $drivers = self::drivers();
        if (!isset($drivers[$type])) {
            throw new \coding_exception("No tool_assessmentgen driver for $type");
        }
        return new $drivers[$type]();
    }
}
