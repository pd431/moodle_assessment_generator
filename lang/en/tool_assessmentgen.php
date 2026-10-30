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
 * Language strings.
 *
 * @package    tool_assessmentgen
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitytypes'] = 'Activity types';
$string['allocationrate'] = 'Marker allocation (share of workflow assignments)';
$string['blindrate'] = 'Blind marking rate';
$string['cannotactas'] = 'The generator cannot act as {$a}: guest, deleted and suspended accounts never take part';
$string['categoryname'] = 'Generated courses ({$a})';
$string['checkdashboard'] = 'Neither block_le_dashboard nor block_my_assessments is installed. The data will be made, but no dashboard will show it.';
$string['checkmanual'] = 'Manual enrolments are disabled. Enable them under Site administration > Plugins > Enrolments > Manage enrol plugins; the generator does not change site settings.';
$string['checkroles'] = 'The site has no role with these short names: {$a}. Restore the standard roles, or set the generator\'s student and marker roles to ones that exist.';
$string['coursematch'] = 'Course name contains';
$string['coursematch_help'] = 'Matches the course full name, short name or ID number, ignoring case. For example 202526.';
$string['courseselected'] = '{$a} course selected';
$string['coursesmatch'] = '{$a} courses match';
$string['coursesselected'] = '{$a} courses selected';
$string['createcourses'] = 'Courses to create';
$string['createcourses_help'] = 'Create this many courses in a new category, named like real modules (for example "History 101 202526"), for a site with no courses to match. Created courses also get generated students (20 to 60 each unless set above) and a convenor and 1 to 3 markers each (unless set below). Purge deletes the courses and the category.';
$string['cutoffrate'] = 'Cut-off date rate';
$string['disabled'] = 'Data generation is disabled on this site. Add $CFG->tool_assessmentgen_allow = true; to config.php to enable it.';
$string['draftrate'] = 'Draft (never submitted) rate';
$string['duefrom'] = 'Due dates from (days relative to today)';
$string['dueto'] = 'Due dates to (days relative to today)';
$string['enrol'] = 'Enrol people where missing';
$string['enrol_help'] = 'Courses with no students get 15 to 40 existing users who hold only student roles on the site. Courses with no markers get 1 to 3 users who hold a marker role somewhere. With generated students on, those fill the courses instead and only markers come from existing users. Purge removes only the enrolments the run made.';
$string['enrolmissing'] = 'Only courses with no students or no markers';
$string['enrolnever'] = 'Never';
$string['extensionrate'] = 'Extension rate';
$string['formativerate'] = 'Formative share';
$string['formativetag'] = 'Formative tag';
$string['formativetag_help'] = 'Tag given to formative activities. Leave empty to use the first formative tag set in the dashboard block (block_le_dashboard, then block_my_assessments), or "Formative Assessment" if none is set. Practice quizzes are always formative.';
$string['generate'] = 'Generate';
$string['grademean'] = 'Grade mean';
$string['gradesd'] = 'Grade standard deviation';
$string['groups'] = 'Create groups';
$string['groupsalways'] = 'In every course, in a grouping of their own';
$string['groupsmissing'] = 'Only in courses that have no groups';
$string['groupsnever'] = 'Never';
$string['laterate'] = 'Late submission rate (share of submissions)';
$string['lockbusy'] = 'Timed out waiting for another task to release {$a}; the task can be run again';
$string['markerroles'] = 'Marker roles';
$string['markerroles_desc'] = 'Comma-separated role short names whose holders mark generated work.';
$string['markersmax'] = 'Most generated markers per course';
$string['markersmax_help'] = 'Create staff accounts (agen-&lt;tag&gt;-staff-0001 and so on) and give every course with no markers one convenor (editing teacher) and between the fewest and most markers (non-editing teachers), drawn from one staff pool so markers work on several courses. 0 for none, unless courses are created.';
$string['markersmin'] = 'Fewest generated markers per course';
$string['maxper'] = 'Most assessments per course';
$string['minper'] = 'Fewest assessments per course';
$string['nodriver'] = 'The generator cannot create {$a} activities yet';
$string['nogroups'] = '{$a} has no groups and groups are not being generated: no peer assessments will be added there';
$string['nomarkers'] = '{$a} has no markers and none could be enrolled: submissions will not be marked';
$string['nostudents'] = '{$a} has no students and none could be enrolled: assessments will be created with no submissions';
$string['notinstalled'] = '{$a} is not installed on this site and will be skipped';
$string['notypes'] = 'None of the chosen activity types is available on this site.';
$string['participants'] = '{$a->students} students and {$a->markers} markers across {$a->courses} courses';
$string['partsdone'] = '{$a->done} of {$a->total} parts done ({$a->running} running, {$a->failed} failed)';
$string['partsize'] = 'Assessments per background task';
$string['partsize_help'] = 'Each course is split into parts of this many assessments, one background task each, so several cron workers can build one course at once. Smaller parts spread the work more evenly; each part re-plans the course up to its own assessments, so very small parts waste a little time on planning.';
$string['pluginname'] = 'Assessment data generator';
$string['pool'] = 'Student pool size';
$string['pool_help'] = 'How many accounts the run creates. Every course draws its cohort from this one pool, so each student sits in several courses, as real students do. Leave 0 for enough accounts that a typical student is in about four of the run\'s courses.';
$string['poolpassword'] = 'Password for every account this run creates (shown only now): {$a}';
$string['preview'] = 'Preview';
$string['previewcreate'] = '{$a->count} new courses will be created in the category "{$a->category}".';
$string['previewstaff'] = 'Generated staff: {$a->create} accounts to create in a pool of {$a->pool}; {$a->courses} courses get a convenor and markers from it';
$string['previewstudents'] = 'Generated students: {$a->create} accounts to create in a pool of {$a->pool}; {$a->used} placed in courses, each in {$a->min} to {$a->max} courses (mean {$a->mean})';
$string['previewtotals'] = 'Planned: {$a->assessments} assessments ({$a->summative} summative, {$a->formative} formative; {$a->assign} assignments, {$a->quiz} quizzes, {$a->peerwork} peer assessments), {$a->newgroups} new groups, {$a->enrolled} people to enrol, {$a->submitted} submissions ({$a->late} late), {$a->drafts} drafts, {$a->grades} grades ({$a->released} released), {$a->extensions} extensions';
$string['privacy:metadata:enrol'] = 'Enrolments a generation run made, so purging the run can remove them. Generated submissions and grades are stored by the activities themselves.';
$string['privacy:metadata:enrol:courseid'] = 'The course the user was enrolled in';
$string['privacy:metadata:enrol:timecreated'] = 'When the run enrolled the user';
$string['privacy:metadata:enrol:userid'] = 'The user the run enrolled';
$string['privacy:metadata:user'] = 'Student and staff accounts a generation run created, so purging the run can delete them.';
$string['privacy:metadata:user:timecreated'] = 'When the run created the account';
$string['privacy:metadata:user:userid'] = 'The account the run created';
$string['progress'] = 'Progress';
$string['purge'] = 'Purge';
$string['purgeconfirm'] = 'Stop run {$a} and delete everything it made: its activities with all their submissions, grades and calendar events, its groups, its enrolments, and any courses and accounts it created? Other courses and users are not affected. The purge runs as a background task.';
$string['purged'] = 'Deleted {$a} activities.';
$string['purgeleft'] = '({$a} activities left)';
$string['purgequeued'] = 'Purge of run {$a} queued as a background task. The run stops first; progress appears below.';
$string['queued'] = 'Queued as background tasks, one per part of each course. Progress appears below.';
$string['queuedpassword'] = 'Background tasks queued. Password for every account this run creates, shown only now: {$a}';
$string['resume'] = 'Resume';
$string['resumedone'] = 'Run {$a} resumed: its unfinished parts are queued again.';
$string['runs'] = 'Runs';
$string['runtag'] = 'Run tag';
$string['runtag_help'] = 'Letters, digits, - and _. Used in the ID number of every activity the run creates, so the run can be purged later.';
$string['seed'] = 'Seed';
$string['seed_help'] = 'The same seed, settings and enrolments always produce the same plan. Leave 0 for a random seed.';
$string['settings'] = 'Assessment data generator settings';
$string['setupfailed'] = 'Creating the run\'s courses failed: {$a}';
$string['sitenotready'] = 'The site is not ready for this run: {$a}';
$string['staffpool'] = 'Staff pool size';
$string['statusdone'] = 'Done';
$string['statusfailed'] = 'Failed';
$string['statuspurged'] = 'Purged';
$string['statuspurgeincomplete'] = 'Purge incomplete: purge again to retry what is left';
$string['statuspurging'] = 'Purging';
$string['statusqueued'] = 'Queued';
$string['statusrunning'] = 'Running';
$string['statusstopped'] = 'Stopped';
$string['statusstopping'] = 'Stopping: {$a} parts finishing their current assessment';
$string['stop'] = 'Stop';
$string['stopdone'] = 'Run {$a} stopped. Parts building an assessment finish that one and stop; everything made so far stays.';
$string['studentroles'] = 'Student roles';
$string['studentroles_desc'] = 'Comma-separated role short names whose holders submit work. Users who also hold a marker role are not treated as students.';
$string['students'] = 'Generate students';
$string['students_help'] = 'Create student accounts and top every course up to a cohort size drawn between the minimum and maximum, with the mean you give (default: the middle). Existing students count towards it. Accounts are named agen-<run tag>-0001 and so on, with @example.invalid emails and email stopped, share one password shown once when you press Generate, and are deleted when the run is purged. Leave the maximum at 0 to create nobody.';
$string['studentsmax'] = 'Most students per course';
$string['studentsmean'] = 'Mean students per course';
$string['studentsmin'] = 'Fewest students per course';
$string['submissionfailed'] = 'Submission failed: {$a}';
$string['submitrate'] = 'Submission rate';
$string['summativetag'] = 'Summative tag';
$string['summativetag_help'] = 'Tag given to summative activities. Leave empty to use the first summative tag set in the dashboard block (block_le_dashboard, then block_my_assessments), or "Summative Assessment" if none is set. The dashboard lists only tagged activities.';
$string['tagexists'] = 'A run tagged {$a} already exists. Pick another tag or purge that run first.';
$string['teamrate'] = 'Group submission rate';
$string['term'] = 'Term in course names';
$string['term_help'] = 'Appended to the names of created courses, as in "History 101 202526". Defaults to the academic year of today\'s date.';
$string['turnaround'] = 'Marking turnaround (days)';
$string['usernametaken'] = 'Username {$a} already exists: use another run tag';
$string['workflowrate'] = 'Marking workflow rate';
