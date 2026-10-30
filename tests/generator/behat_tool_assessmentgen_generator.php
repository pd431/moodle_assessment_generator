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
 * Behat data generator, for steps like:
 *
 *     Given the following "tool_assessmentgen > runs" exist:
 *       | match  | minper | maxper | seed | tag    |
 *       | 202526 | 2      | 4      | 42   | trial1 |
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_assessmentgen_generator extends behat_generator_base {
    /**
     * Entities this generator can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'runs' => [
                'singular' => 'run',
                'datagenerator' => 'run',
                'required' => ['seed'],
                'switchids' => ['course' => 'courseid'],
            ],
        ];
    }

    /**
     * Create a run; a "course" column (short name) is turned into courseids.
     *
     * @param array $data
     */
    protected function process_run(array $data): void {
        if (isset($data['courseid'])) {
            $data['courseids'] = [$data['courseid']];
            unset($data['courseid']);
        }
        if (isset($data['types'])) {
            $data['types'] = array_map('trim', explode(',', $data['types']));
        }
        $this->componentdatagenerator->create_run($data);
    }
}
