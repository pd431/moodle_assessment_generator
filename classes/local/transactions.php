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
 * Recording a failure durably when core code failed inside a delegated transaction.
 *
 * Core's add_moduleinfo() opens a delegated transaction and does not close it when it throws. Anything written
 * after that, until the process ends, is rolled back with it: a part that recorded its failure would stay
 * "running" for ever. So before writing bookkeeping about a failure, the open transaction is discarded.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transactions {
    /**
     * Whether a transaction is open now. Take this before the work starts and give it to rollback_open() after a
     * failure, so a transaction the caller owns (PHPUnit wraps every test in one) is left alone.
     *
     * @return bool
     */
    public static function open(): bool {
        global $DB;
        return $DB->is_transaction_started();
    }

    /**
     * Discard a delegated transaction that a failure left open, so what is written next is kept.
     *
     * @param bool $openbefore what open() returned before the work started
     * @return bool whether a transaction was discarded
     */
    public static function rollback_open(bool $openbefore): bool {
        global $DB;
        if ($openbefore || !$DB->is_transaction_started()) {
            return false;
        }
        $DB->force_transaction_rollback();
        return true;
    }
}
