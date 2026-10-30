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

namespace tool_assessmentgen\local\driver;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use tool_assessmentgen\local\options;

/**
 * Quiz driver, for Moodle 4.5 and 5.x.
 *
 * Questions go into the quiz's own question category, so purging the quiz removes them. Attempts are started,
 * answered and finished through the quiz API as the student at the planned times, and essays are marked through
 * the same path the manual grading page uses.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz extends base {
    /**
     * Module name.
     *
     * @return string
     */
    public static function modname(): string {
        return 'quiz';
    }

    /**
     * Create the quiz and carry out the plan.
     *
     * @param \stdClass $course
     * @param array $a
     * @param options $options
     * @return array
     */
    public function execute(\stdClass $course, array $a, options $options): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $counts = ['cmid' => 0, 'submitted' => 0, 'drafts' => 0, 'grades' => 0, 'extensions' => 0];
        $mark = $this->log_mark();
        $created = $this->created_time($a, $options);
        $cm = $this->add_module($course, $a, self::settings($a));
        $counts['cmid'] = (int)$cm->id;
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->add_questions($quiz, $cm, $a['questions']);
        $logmark = $this->stamp_logs($mark, (int)$cm->id, $created);

        foreach ($a['units'] as $u) {
            if ($u['override']) {
                quiz_settings::create($quiz->id)->get_override_manager()->save_override([
                    'userid' => $u['userid'],
                    'timeclose' => $u['override'],
                ]);
                $logmark = $this->stamp_logs($logmark, (int)$cm->id, $u['extensiontime']);
                $counts['extensions']++;
            }
            if ($u['status'] === 'none') {
                continue;
            }
            $previous = false;
            foreach ($u['attempts'] as $n => $plan) {
                $previous = $this->as_user($u['userid'], fn() => $this->attempt(
                    $quiz,
                    $u['userid'],
                    $n + 1,
                    $previous,
                    $plan,
                    (int)$a['essayslot']
                ));
                $logmark = $this->stamp_logs($logmark, (int)$cm->id, $plan['state'] === 'inprogress' ? $plan['start'] :
                    $plan['finish']);
            }
            if ($u['status'] === 'finished') {
                $counts['submitted']++;
            } else {
                $counts['drafts']++;
            }
            if ($u['grade'] === null) {
                continue;
            }
            if ($u['grade'] >= 0) {
                $this->mark_essay($previous->id, (int)$a['essayslot'], (float)$u['grade'], $u);
                $logmark = $this->stamp_logs($logmark, (int)$cm->id, $u['gradetime']);
            }
            $counts['grades']++;
        }

        $this->backdate($quiz, $cm, $a);
        $DB->set_field('quiz', 'timecreated', $created, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'timemodified', $created, ['id' => $quiz->id]);
        $DB->set_field('course_modules', 'added', $created, ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);
        \core\event\course_module_updated::create_from_cm(get_fast_modinfo($course->id)->get_cm($cm->id))->trigger();
        $this->stamp_remaining_logs($mark, (int)$cm->id, $created);
        return $counts;
    }

    /**
     * Quiz settings, matching the defaults of the quiz test generator.
     *
     * @param array $a
     * @return array
     */
    protected static function settings(array $a): array {
        $settings = [
            'groupmode' => NOGROUPS,
            'timeopen' => $a['open'],
            'timeclose' => $a['due'],
            'timelimit' => $a['timelimit'],
            'overduehandling' => $a['timelimit'] ? 'autoabandon' : 'autosubmit',
            'graceperiod' => 0,
            'preferredbehaviour' => 'deferredfeedback',
            'attempts' => $a['attempts'],
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 1,
            'shuffleanswers' => 1,
            'sumgrades' => 0,
            'grade' => 100,
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
        ];
        foreach (
            ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
                'rightanswer', 'overallfeedback'] as $field
        ) {
            foreach (['during', 'immediately', 'open', 'closed'] as $when) {
                $settings[$field . $when] = ($field === 'overallfeedback' && $when === 'during') ? 0 : 1;
            }
        }
        return $settings;
    }

    /**
     * Create the questions in the quiz's own category and add them, one per page.
     *
     * @param \stdClass $quiz
     * @param \cm_info $cm
     * @param string[] $types
     */
    protected function add_questions(\stdClass $quiz, \cm_info $cm, array $types): void {
        $context = \context_module::instance($cm->id);
        if (function_exists('question_make_default_categories')) {
            $category = question_make_default_categories([$context]);
        } else {
            $category = question_get_default_category($context->id, true);
        }
        foreach ($types as $i => $type) {
            $form = self::question_form($type, $i + 1);
            $form->category = $category->id . ',' . $context->id;
            $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
            $question = \question_bank::get_qtype($type)->save_question((object)['qtype' => $type], $form);
            quiz_add_quiz_question($question->id, $quiz, 0);
        }
        quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    }

    /**
     * Question form data, in the shape the question edit form submits.
     *
     * @param string $type multichoice, truefalse, shortanswer or essay
     * @param int $n question number, used in the name and text
     * @return \stdClass
     */
    protected static function question_form(string $type, int $n): \stdClass {
        $html = fn(string $text) => ['text' => $text, 'format' => FORMAT_HTML];
        $form = (object)[
            'name' => "Q$n ($type)",
            'defaultmark' => 1,
            'penalty' => 0.3333333,
            'generalfeedback' => $html(''),
            'idnumber' => '',
        ];
        switch ($type) {
            case 'multichoice':
                $form->questiontext = $html("<p>Question $n: which of these statements is correct?</p>");
                $form->single = 1;
                $form->shuffleanswers = 1;
                $form->answernumbering = 'abc';
                $form->showstandardinstruction = 0;
                $form->shownumcorrect = 0;
                $form->noanswers = 4;
                $form->answer = [];
                $form->fraction = ['1.0', '0.0', '0.0', '0.0'];
                $form->feedback = [];
                foreach (
                    ['The correct statement', 'A common misconception', 'A partly true statement',
                        'An unrelated statement'] as $text
                ) {
                    $form->answer[] = ['text' => $text, 'format' => FORMAT_PLAIN];
                    $form->feedback[] = $html('');
                }
                foreach (['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'] as $field) {
                    $form->$field = $html('');
                }
                break;
            case 'truefalse':
                $form->questiontext = $html("<p>Question $n: this statement is true.</p>");
                $form->correctanswer = '1';
                $form->feedbacktrue = $html('');
                $form->feedbackfalse = $html('');
                $form->penalty = 1;
                break;
            case 'shortanswer':
                $form->questiontext = $html("<p>Question $n: name the key term (one word).</p>");
                $form->usecase = 0;
                $form->answer = ['answer', '*'];
                $form->fraction = ['1.0', '0.0'];
                $form->feedback = [$html(''), $html('')];
                break;
            case 'essay':
                $form->questiontext = $html("<p>Question $n: explain your reasoning in a short paragraph.</p>");
                $form->responseformat = 'editor';
                $form->responserequired = 1;
                $form->responsefieldlines = 10;
                $form->attachments = 0;
                $form->attachmentsrequired = 0;
                $form->maxbytes = 0;
                $form->filetypeslist = '';
                $form->graderinfo = $html('');
                $form->responsetemplate = $html('');
                $form->penalty = 0;
                break;
        }
        return $form;
    }

    /**
     * Start, answer and finish (or leave or abandon) one attempt as the current user.
     *
     * @param \stdClass $quiz
     * @param int $userid
     * @param int $number attempt number from 1
     * @param \stdClass|false $previous the previous attempt
     * @param array $plan
     * @param int $essayslot 0 if none
     * @return \stdClass the attempt row
     */
    protected function attempt(\stdClass $quiz, int $userid, int $number, $previous, array $plan, int $essayslot): \stdClass {
        $quizobj = quiz_settings::create($quiz->id, $userid);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, $number, $previous, $plan['start'], false, $userid);
        $attempt = quiz_start_new_attempt($quizobj, $quba, $attempt, $number, $plan['start']);
        // Moodle 5.0 added the start time as a parameter; 4.5 reads it from the attempt.
        if ((new \ReflectionFunction('quiz_attempt_save_started'))->getNumberOfParameters() > 3) {
            $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt, $plan['start']);
        } else {
            $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt);
        }

        $attemptobj = quiz_attempt::create($attempt->id);
        $answered = $plan['answers'];
        if ($plan['state'] === 'inprogress') {
            // Someone still working has answered roughly the first half.
            $answered = array_slice($answered, 0, (int)ceil(count($answered) / 2), true);
        }
        $postdata = $this->responses($attemptobj, $answered, $essayslot);
        $answertime = min($plan['finish'], $plan['start'] + max(60, (int)(($plan['finish'] - $plan['start']) * 0.9)));
        $attemptobj->process_submitted_actions(
            $plan['state'] === 'inprogress' ? $plan['start'] + 60 : $answertime,
            false,
            $postdata
        );

        $attemptobj = quiz_attempt::create($attempt->id);
        if ($plan['state'] === 'finished') {
            if (method_exists($attemptobj, 'process_submit')) {
                $attemptobj->process_submit($plan['finish'], false);
                $attemptobj->process_grade_submission($plan['finish']);
            } else {
                $attemptobj->process_finish($plan['finish'], false);
            }
        } else if ($plan['state'] === 'abandoned') {
            $attemptobj->process_abandon($plan['finish'], false);
        }
        return quiz_attempt::create($attempt->id)->get_attempt();
    }

    /**
     * Post data answering the given slots, right or wrong as planned.
     *
     * @param quiz_attempt $attemptobj
     * @param bool[] $answers slot => correct
     * @param int $essayslot
     * @return array
     */
    protected function responses(quiz_attempt $attemptobj, array $answers, int $essayslot): array {
        $slots = [];
        $postdata = [];
        foreach ($answers as $slot => $correct) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            if ($slot === $essayslot) {
                $response = ['answer' => self::online_text($attemptobj->get_userid(), $question->name),
                    'answerformat' => FORMAT_HTML];
            } else {
                $response = $question->get_correct_response();
                if (!$correct) {
                    $response = self::wrong($question->get_type_name(), $response);
                }
            }
            foreach ($response as $name => $value) {
                $postdata[$qa->get_qt_field_name($name)] = $value;
            }
            $postdata[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
            $slots[] = $slot;
        }
        $postdata['slots'] = implode(',', $slots);
        return $postdata;
    }

    /**
     * A wrong response for one of the generated question types.
     *
     * @param string $type
     * @param array $right the correct response
     * @return array
     */
    protected static function wrong(string $type, array $right): array {
        return match ($type) {
            'multichoice' => ['answer' => ((int)$right['answer'] + 1) % 4],
            'truefalse' => ['answer' => 1 - (int)$right['answer']],
            default => ['answer' => 'no idea'],
        };
    }

    /**
     * Mark the essay as the planned marker, the way the manual grading page does.
     *
     * @param int $attemptid
     * @param int $slot
     * @param float $fraction 0..1
     * @param array $u the unit
     */
    protected function mark_essay(int $attemptid, int $slot, float $fraction, array $u): void {
        $grade = function () use ($attemptid, $slot, $fraction, $u) {
            $attemptobj = quiz_attempt::create($attemptid);
            $qa = $attemptobj->get_question_attempt($slot);
            $maxmark = $qa->get_max_mark();
            $attemptobj->process_submitted_actions($u['gradetime'], false, [
                'slots' => (string)$slot,
                $qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count(),
                $qa->get_behaviour_field_name('comment') => '<p>' . self::FEEDBACK[$u['feedback']] . '</p>',
                // The comment is read through its draft file area, which may be empty but must exist.
                $qa->get_behaviour_field_name('comment:itemid') => file_get_unused_draft_itemid(),
                $qa->get_behaviour_field_name('commentformat') => FORMAT_HTML,
                $qa->get_behaviour_field_name('mark') => format_float($fraction * $maxmark, 7),
                $qa->get_behaviour_field_name('maxmark') => $maxmark,
            ]);
        };
        try {
            $this->as_user($u['marker'], $grade);
        } catch (\required_capability_exception $e) {
            $this->as_user(get_admin()->id, $grade);
        }
    }

    /**
     * Move the quiz grade and gradebook rows to the time of the last attempt or the essay mark.
     *
     * @param \stdClass $quiz
     * @param \cm_info $cm
     * @param array $a
     */
    protected function backdate(\stdClass $quiz, \cm_info $cm, array $a): void {
        global $DB;
        $gradeitemid = $DB->get_field('grade_items', 'id', ['courseid' => $cm->course, 'itemtype' => 'mod',
            'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0]);
        foreach ($a['units'] as $u) {
            if ($u['status'] === 'none') {
                continue;
            }
            $time = max($u['time'], $u['gradetime']);
            $DB->set_field('quiz_grades', 'timemodified', $time, ['quiz' => $quiz->id, 'userid' => $u['userid']]);
            if ($gradeitemid) {
                $params = ['t' => $time, 'item' => $gradeitemid, 'u' => $u['userid']];
                $DB->execute('UPDATE {grade_grades} SET timemodified = :t WHERE itemid = :item AND userid = :u', $params);
                $DB->execute(
                    'UPDATE {grade_grades_history} SET timemodified = :t WHERE itemid = :item AND userid = :u',
                    $params
                );
            }
        }
    }
}
