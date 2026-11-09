# Assessment data generator (tool_assessmentgen)

Admin tool for Moodle 4.5 and 5.3 that fills courses with assessment activity for testing dashboards. It adds random assignments, quizzes and peer assessments to courses matched by name, submits on behalf of some students, and marks some of the work as the courses' own teachers. Existing courses are never created or deleted, and users only when you ask for generated students or markers. Courses with nobody in them are topped up with existing people. On a brand-new site with no courses or users, one option makes the courses, students and staff as well (see "A brand-new site"). Every run can be purged.

## Read this first: side effects outside the generated activities

1. **A site-wide scale.** Peer assessment criteria need a scale, so the first run that makes a peer assessment creates one site-level scale named **"Peer contribution (1-5)"** (1 Poor to 5 Excellent). Later runs reuse it. **Purge leaves it in place**, because other activities may have started using it. Delete it by hand under Site administration > Grades > Scales once nothing uses it.
2. **Generated groups change what existing activities show.** With the default `--groups=missing`, a course with no groups gets groups of 3 to 6 active students, in a grouping called "Generated groups (&lt;tag&gt;)". With `--groups=always`, courses that already have groups get them too. **Any existing activity in that course set to separate or visible groups starts filtering by these groups** (group selectors, grading tables, forum posts) until the run is purged. Purge deletes the generated groups and grouping and leaves every other group and its members alone. Use `--groups=never` to avoid this; peer assessment is then skipped in courses with no groups.
3. **Enrolments in empty courses.** With the default `--enrol=missing`, a course with no students gets 15 to 40 existing users who hold **only student roles** anywhere on the site, and a course with no markers gets 1 to 3 users who hold a **marker role** (editingteacher or teacher by default) somewhere. They are enrolled through the course's manual enrolment (one is added if the course has none) as student or non-editing teacher, with no welcome message, dated at least a week before any generated activity can open. The guest account, site admins, and suspended or deleted users are never picked. Purge removes exactly these enrolments and nothing else. `--enrol=never` turns this off.
4. **Generated student accounts (only with `--students` or `--create-courses`, off by default).** The run creates a pool of student accounts and tops every course up to a cohort size drawn from your range (existing students count towards it; see below). Purge deletes them with Moodle's normal user deletion, which leaves a deleted stub row per account in the user table, as it does for any deleted user.
5. **Generated staff accounts (only with `--markers` or `--create-courses`).** Courses with no markers get a convenor (editing teacher) and markers (non-editing teachers) from a pool of generated staff accounts, instead of borrowed existing staff. Purge deletes them like the students.
6. **Created courses and a category (only with `--create-courses`).** The run makes a course category "Generated courses (&lt;tag&gt;)" and courses in it. Purge deletes them with Moodle's course deletion, and the category if nothing else has been put in it. Course creation fires the usual events and adds each course's default blocks and enrolment methods, as creating a course by hand does.

## Enable

The tool does nothing unless `config.php` has:

```php
$CFG->tool_assessmentgen_allow = true;
```

(PHPUnit and Behat runs are always allowed.) Students and markers come from **Site administration > Development > Assessment data generator settings**: student roles default to `student`, marker roles to `editingteacher,teacher`. Only active enrolments count.

## Use

**Admin page:** Site administration > Development > Assessment data generator. Type a match such as `202526`, pick the activity types and the group setting, and press Preview to list the matching courses with their students, markers, new groups and planned activity. Untick any courses you don't want, then press Generate. That queues background tasks (one per part of each course, see below), so cron does the work. The runs table shows parts done, totals and errors, with **Stop**, **Resume** and **Purge** buttons for each run.

**CLI** (runs in the foreground, or queues with `--queue`):

```
php admin/tool/assessmentgen/cli/generate.php --match=202526 --per-course=2-6 --seed=42 --tag=trial1 --dry-run
php admin/tool/assessmentgen/cli/generate.php --match=202526 --types=assign,quiz,peerwork --groups=missing --tag=trial1
php admin/tool/assessmentgen/cli/generate.php --match=202526 --students=60-400 --students-mean=150 --per-course=50-80 --tag=load1 --queue
php admin/tool/assessmentgen/cli/generate.php --create-courses=40 --per-course=50-80 --tag=fresh1 --queue
php admin/tool/assessmentgen/cli/stop.php --tag=load1
php admin/tool/assessmentgen/cli/stop.php --tag=load1 --resume
php admin/tool/assessmentgen/cli/purge.php --list
php admin/tool/assessmentgen/cli/purge.php --tag=trial1
```

On Moodle 5.1 and later the scripts live under `public/`: `php public/admin/tool/assessmentgen/cli/generate.php ...`.

`--help` lists every rate. The defaults are: all three types, picked with equal odds; due dates from -90 to +120 days, opening 7–28 days before the due date; 80% submit, 10% of submissions late, 5% drafts, 5% extensions; 40% with a cut-off date, 40% with marking workflow (half of those with allocation), 15% blind, 15% group submission; a 21-day marking turnaround; and marks with mean 62 and SD 11. A purged run's tag can be used again.

## Large runs: parts, parallel workers, stopping and purging

- **Parts.** Each course is split into parts of `--part-size` assessments (default 10; "Assessments per background task" on the admin page), one background task each. A part plans the course from the start up to its own last assessment and builds only its own range, so the parts together build exactly what one task would have (tested). A course of 200 assessments is 20 tasks.
- **Parallel workers.** Parts run at the same time when cron has several workers. Moodle runs up to `$CFG->task_adhoc_concurrency_limit` adhoc tasks at once (default 3). For more, raise it in `config.php` and start extra workers, each running `php admin/cli/adhoc_task.php --execute --keep-alive=3600` (on 5.1 and later, `php public/admin/cli/...`). Each worker is one PHP process and one database connection, so on a laptop stay at or below the number of CPU cores. The database is usually the bottleneck. On the build machine, 3 workers built 4 parts of one course side by side.
- **What stays one-at-a-time.** Enrolments and groups for a course are made once, under a lock, by whichever part gets there first. Activities are added to a course one at a time, so course sections never lose an activity. Tags, the peer scale and generated accounts are created once. Submissions, attempts and marking, which are most of the work, run fully in parallel.
- **Stop** (button, or `cli/stop.php --tag=X`): no part starts another assessment, and a part in the middle of one finishes it first. The status reads "Stopping" until those parts are done. Everything made so far stays. A foreground `generate.php` notices the stop too.
- **Resume** (button, or `cli/stop.php --tag=X --resume`) queues the parts that are not finished, for stopped or failed runs. Assessments that already exist are skipped, and tasks still waiting from before are not doubled.
- **Purge** from the admin page stops the run, then runs as a background task, so a big run can't time out the page. The runs table shows "Purging (N activities left)". `cli/purge.php` purges in the foreground. Purge waits up to 15 minutes for parts that are building an assessment.
- **Purge keeps going.** Every activity, group, enrolment and account is deleted separately. If one fails, the rest still go, and the run shows **"Purge incomplete"** with the failures listed. Purge again to retry only what is left. A failed activity deletion is retried once after rebuilding the course cache, which fixes the usual cause. Purge also sweeps each course for activities and groups whose ID numbers carry the run's tag but were never recorded, such as a half-made activity from a worker that died, and for generated accounts by username. It matches ID numbers exactly, so run `a` never touches run `a-1`'s activities.

## Generated students

For load tests on a site with few test users, `--students=60-400 --students-mean=150` (or the "students per course" fields on the admin page) gives every course a cohort of 60 to 400 students averaging 150:

- **Cohort sizes** are drawn as min + (max − min) × u^k, with k set so the average is the mean you ask for. A mean below the middle gives many smaller courses and a tail of big ones, like real course sizes. The mean must lie strictly between the minimum and maximum (default: the middle).
- **Existing students count.** A course with 50 students and a drawn size of 120 gets 70 generated students; a course already above its size gets none. Markers come from existing staff (`--enrol`) unless you also ask for generated markers (`--markers`).
- **One shared pool per run** (`--pool=N`). Every course draws its cohort from the same accounts, so a student sits in several courses. That is what makes a dashboard work: its cost depends on how many courses and activities each student has, not only on cohort size. By default the pool is big enough for a typical student to be in about four of the run's courses (and never smaller than the largest cohort). The preview and dry run show how many accounts will be created and how many courses each student lands in.
- **The accounts**: usernames `agen-<tag>-0001` and up, emails `<username>@example.invalid` with email stopped, manual authentication, names from a mixed list, and student roles only in the run's courses. They are inserted directly rather than through `user_create_user()`, so no "user created" events fire and nothing reacts to thousands of new accounts. One password for all of them is shown once, on the page after Generate or in the CLI output; only its hash is kept. Admins can also use "Log in as". The accounts are created by whichever course task runs first, under a lock.
- Generated accounts are never picked as "existing" students by other runs, because they disappear when their run is purged.
- If a username is already taken (say a run tag was reused outside the generator), the course task stops with "Username … already exists: use another run tag".
- Unticking courses after a preview changes the default pool size, so the run may place students slightly differently from the preview. Set `--pool` (or the field) to keep them identical.

## A brand-new site

`--create-courses=40` (or "Courses to create" on the admin page) is for a site with no courses or users, such as a fresh install for a load test. `--match` becomes optional; give it too to include existing courses as well.

- **Courses.** 40 courses in a new category "Generated courses (&lt;tag&gt;)", named like real modules: "History 101 202526", short name `HIS101-202526`, ID number `AGEN-<tag>-course-001` and so on. The term defaults to the academic year of the anchor date (years start in August); set it with `--term`. A short name another course already uses is skipped by bumping the number. Courses use the topics format with 10 sections, start a week before the earliest enrolment and end 30 days after the latest due date plus the marking turnaround. Names are drawn from the seed, so the preview shows the names the run will use.
- **Students and staff come on by default.** Created courses turn on `--students=20-60` (mean 35) and `--markers=1-3` unless you give your own.
- **Staff** (`--markers=1-3`, also usable on existing courses): every course with no markers gets one convenor as editing teacher and 1 to 3 markers as non-editing teachers, from one pool of generated staff (`agen-<tag>-staff-0001` and up, `--staff-pool=N`; by default enough for each to work on about 3 courses). Staff accounts are made like the generated students (`@example.invalid`, email stopped, the run's one password) and never hold a student role, so the dashboard shows them its staff view. Existing courses that already have markers get none.
- **Order.** On the admin page or with `--queue`, one background task creates every course and account first, then queues the courses' parts, which run in parallel as usual. Stopping before that task runs means nothing is created until you resume. In the foreground the CLI lists each course as it is created.
- **Site checks.** The preview lists what the site is missing. Manual enrolment disabled, or no `student`, `teacher` or `editingteacher` role, stops the run with a message saying what to change. No dashboard block installed (neither block_le_dashboard nor block_my_assessments) is a warning: the data is made, but nothing shows it. The generator never changes site settings itself.
- **Not done:** the dashboard block is not added to anyone's Dashboard. On a fresh site, add it to the default Dashboard (Site administration > Appearance > Default Dashboard page, then "Reset Dashboard for all users") or have test users add it.
- **Purge** deletes the run's courses first (which removes everything in them), then the category if it is empty, then the accounts.

## Summative and formative tags

The My assessments dashboard lists only tagged activities, so every generated activity gets exactly one course-module tag:

- **Names:** `--summative-tag` and `--formative-tag` (or the admin form fields) if given; otherwise the first tag in the dashboard's "Summative tags" and "Formative tags" settings, read from the remake (`block_le_dashboard`) first and then rc6 (`block_my_assessments`), so both can be installed side by side; otherwise "Summative Assessment" and "Formative Assessment".
- **Share:** about 30% are formative (`--formative-rate`, 0 makes everything summative). Quizzes named "Practice quiz N" are always formative.
- The preview and dry run show the split, and the task log shows each activity's tag next to its link.
- Purge deletes the tag instances with the activities. Moodle then drops a tag name that nothing else uses unless it is a standard tag; the block's configured tags are unaffected as settings.
- Runs made before 0.4.0 have no tags, so the dashboard won't list them. Purge and regenerate.

## What a run produces

All activities get ID numbers `AGEN-<tag>-<courseid>-<n>` and sit in a random existing section. Students only act on activities that are already open; before the due date only the elapsed share of the cohort has acted. Marking follows the turnaround: work more than 21 days past due is about 97% marked, more recent work is partly marked.

- **Assignments:** names like "Essay 2: Methods", a maximum grade of 100, online text, file or both. Late work falls between the due date and the cut-off (or extension) date. With marking workflow, recent marks are spread across in marking, ready for review, in review and ready for release. Allocated markers mark only their own students (on 5.1 and later through the allocated-marker table). Blind-marked assignments reveal identities once marking is due.
- **Quizzes:** 4 to 8 questions (multiple choice, true/false, short answer), plus one essay in about a third of quizzes. Questions are created in the quiz's own question category, so purging the quiz removes them and no course question bank is touched. About 40% have a 20–60 minute time limit and about 30% allow 2 or 3 attempts (highest grade counts). Students end up with no attempt, an attempt in progress (only while the quiz is open), finished attempts, or an abandoned timed attempt. Right answers follow each student's ability, so averages land near the grade mean. Essays are marked by a teacher on the turnaround, only on the student's last attempt; until then the attempt shows "needs grading". About 5% of students on past quizzes get a user override with a later close date.
- **Peer assessments** (mod_peerwork with the WebPA calculator): names like "Group project 1", 2 or 3 criteria on the scale above, PA weighting 30–60%, some with late submissions, justifications or self-rating. One member submits a placeholder file, then members rate their peers at their own times. Teachers grade the group, about 5% of individual grades get a revised grade (no comment), and released grades reach the gradebook. Unreleased ones stay out of it.
- **Timestamps** on submissions, attempts and question steps, ratings, grades, gradebook rows, files and the standard log are moved to the planned times. Nothing is dated after the run's anchor. Each activity ends with a `course_module_updated` event, so anything indexing assessments re-reads it.
- **Who takes part:** students and markers with an active enrolment and the right role in the course, plus any generated students. The guest account and suspended accounts are always left out, even when a course has them enrolled. (Before 0.3.0 a course with the guest account enrolled as a student failed with "No guests here!".)
- **Reproducible:** the same seed, settings and enrolments give the same plan. Each course is planned independently, so tasks can run in any order or in parallel.

## Task log and CLI output

Each course's background task (and the CLI) prints what it made, with links:

```
tool_assessmentgen: Course HIS101-202526 (History 101): https://moodle.example/course/view.php?id=2
tool_assessmentgen:   Enrolled: nobody. Groups made: 13.
tool_assessmentgen:   quiz     Knowledge check 1: https://moodle.example/mod/quiz/view.php?id=54 (submitted 35, in progress or draft 0, marked 35, extensions 2)
tool_assessmentgen:   assign   Portfolio 1: https://moodle.example/mod/assign/view.php?id=56 (submitted 31, in progress or draft 3, marked 29, extensions 1)
```

A task for a run that was purged while the task was queued prints "Skipped: run <tag> was purged before this task ran." instead of an empty `[]`.

## Other things to know

- **Submission receipts are switched off during a run**, for that process only, via `forced_plugin_settings`. Grading notifications are off on the activities themselves.
- **Purge skips the recycle bin** for that process only. It removes the activities and everything under them (submissions, attempts, questions, ratings, grades, gradebook items, files, calendar events and overrides), plus the run's groups, enrolments, generated accounts, and any courses and category it created (the course recycle bin is skipped too). Log rows stay. On PostgreSQL, purge first refreshes the planner statistics of the quiz attempt tables (`ANALYZE`): straight after a big run on a fresh install they can still read as empty, and deleting each quiz then took minutes instead of milliseconds in testing.
- **No outer transaction per activity.** Moodle's log store only writes events after the outermost transaction commits, which is too late to backdate them. So if a driver fails part-way, the half-made activity is deleted and the error is recorded on the run.
- **Speed:** everything goes through the module APIs. Rough figures from the build machine: assignments about 30 submissions or marks a second; quizzes about 14 attempts a second (571 attempts on 6 quizzes in about 40 s); peer assessment is dominated by ratings, which grow with the square of group size. A course whose existing groups have 40 students each made about 20,000 rating log rows for 3 peer assessments. Use `--groups=always` to get small groups instead.
- **Memory and size.** Since 0.4.1 each assessment is planned, built (or counted, for a preview) and dropped before the next, so memory no longer grows with the number of assessments: a preview of 3,000 assessments on a 250-student course peaks at about 17 MB. Before that, about 4 KB per student per assessment was held at once, so 500 assessments on a 250-student course exceeded a 128 MB `memory_limit` and the page failed with a 500 error straight away. Time is now the limit: at the speeds above, 1,000 assessments on a 250-student course is a few hours of cron for that one course. For dashboard load tests, 50–100 assessments per course across many courses is quicker to build and closer to what a student sees.
- A non-editing teacher cannot grant extensions or overrides, or release marks under marking workflow, so those are done as the site admin (the planned marker is still recorded as the grader). Before 0.5.0, when a non-editing teacher happened to mark first, Moodle's per-activity cache of allowed workflow states made later "released" marks on that assignment silently stay unreleased; regenerate older runs if released marks look short. If a marker role can't grade, the grade is saved as admin and the grader is set back to the planned marker.
- **mod_peerwork on 5.2 and 5.3:** peerwork 4.5.0.5 declares support for 4.3–4.5 only, but it installed and passed every test here on 5.2.4 and 5.3. It prints a deprecation notice about its `subplugins.json` on 5.2 and later; that is peerwork's, not this plugin's. If peerwork is missing or broken on a site, the generator skips peer assessment and says so in the preview.
- Quiz questions are built from the same form data the question editor submits; core's test data generator is not used, because it needs PHPUnit, which a normal site doesn't have.

## In tests

PHPUnit:

```php
$gen = $this->getDataGenerator()->get_plugin_generator('tool_assessmentgen');
$record = ['courseids' => [$course->id], 'seed' => 42, 'types' => ['quiz'], 'minper' => 3, 'maxper' => 3,
    'anchor' => 1790000000];
$plan = $gen->plan($record);       // What create_run() will build.
$run = $gen->create_run($record);
```

Generated groups appear in a plan as keys `new:1`, `new:2` …; after the run they have ID numbers `AGEN-<tag>-<courseid>-g1` and so on.

Behat:

```gherkin
Given the following "tool_assessmentgen > runs" exist:
  | course        | tag   | types | seed | minper | maxper | duefrom | dueto | submitrate | draftrate |
  | HIS101-202526 | marks | quiz  | 7    | 1      | 1      | -60     | -40   | 1          | 0         |
And I am on activity "1" of generator run "marks"
```

(`I am on assignment "N" of generator run "tag"` still works.)

## Not done

- The dashboard block is not placed on the default Dashboard; see "A brand-new site".
- Assign user overrides (`assign_overrides`) are not generated; extensions cover per-student dates on assignments.
- Quiz attempts are not generated for random questions, question variants or attachments.
- coursework is out of scope (not on the test site).

## Tests

- `tests/run_test.php` (12 tests): selection, roles, seed reproducibility, rate statistics, dates, full assignment generation checked against the plan, allocation, purge, retry, queueing, and streamed planning giving the same plan as planning all at once.
- `tests/types_test.php` (7 tests): group sizes and coverage, the missing/always/never setting, purge removing only generated groups, peer assessment end to end (submissions, files, ratings, grades, release, gradebook, log times), peer assessment skipped without groups, quizzes end to end (questions in the quiz's own context, attempt states and times, overrides, essay marking, gradebook, log times), and purge removing quiz questions.
- `tests/enrol_test.php` (5 tests): guest and suspended users never take part, who counts as a student or marker on the site, topping up an empty course (15–40 students, 1–3 markers, dated before the first open date) and purge removing only those enrolments, `--enrol=never`, and the linked course report including purged runs.
- `tests/behat/generate.feature` (6 scenarios): preview, generate with a course unticked then purge in the background, stop and resume from the runs table, a student sees an assignment submission and mark, a student sees a finished quiz attempt, and a peer assessment on generated groups that purge removes.

- `tests/students_test.php` (4 tests): cohort sizes within the range with the requested mean and a skew, mean validation, the preview topping courses up from one shared pool, a full run creating safe accounts (usernames, emails, email stopped, password, student role only) that submit like anyone else and match the preview, other runs not borrowing them, purge deleting only them, and a taken username stopping the run clearly.
- `tests/control_test.php` (4 tests): parts queued per course, not doubled on re-queue, and building the same as one task; stop (queued tasks then do nothing, a foreground run stops too) and resume without duplicates; purge going past an activity that won't delete, sweeping an unrecorded leftover but not a lookalike tag, and finishing on a second purge; purge from the page stopping the run and running as a background task.
- `tests/tags_test.php` (3 tests): tag names from the run, then the block settings, then defaults; the formative share and practice quizzes; tags on generated activities and their removal on purge.

Results for 0.7.2 (CI fixes only: string order, a test's row order, the peer assessment scenario skips without mod_peerwork): GitHub Actions on 4.5 and 5.3 x MariaDB and PostgreSQL. Results for 0.7.1: Moodle 4.5.15+ (MariaDB 10.11) PHPUnit 50/50 and Behat 10/10; Moodle 5.3 (MariaDB 10.11) PHPUnit 50/50 and Behat 10/10; a 6-course `--create-courses --queue` run built by 3 parallel `adhoc_task.php` workers on each, 18/18 parts and 57 assessments with no database errors, purged in 8 s; uninstall on 4.5 PostgreSQL 16 left no activities, accounts or tasks. Results for 0.7.0: Moodle 4.5.14+ PHPUnit 40/40 and Behat 7/7; Moodle 5.2.4 PHPUnit 40/40 and Behat 7/7; plus, on 4.5, a 4-course `--create-courses --queue` run built by 3 parallel `adhoc_task.php` workers and then purged (4 courses, the category and 64 accounts in 7 s). Results for 0.6.0: Moodle 4.5.14+ PHPUnit 35/35 and Behat 6/6; Moodle 5.2.4 PHPUnit 35/35 and Behat 6/6; plus a 4-part run built by 3 parallel `adhoc_task.php` workers on 4.5 (all 12 activities in their sections). Results for 0.5.1: Moodle 4.5.14+ PHPUnit 31/31; Moodle 5.2.4 PHPUnit 31/31 (only the tag-name lookup changed). Results for 0.5.0: Moodle 4.5.14+ PHPUnit 31/31 and Behat 5/5; Moodle 5.2.4 PHPUnit 31/31 and Behat 5/5. Results for 0.4.1: Moodle 4.5.14+ PHPUnit 27/27 and Behat 5/5; Moodle 5.2.4 PHPUnit 27/27 and Behat 5/5. Results for 0.4.0: Moodle 4.5.14+ PHPUnit 26/26 and Behat 5/5; Moodle 5.2.4 PHPUnit 26/26 and Behat 5/5. Results for 0.3.0: Moodle 4.5.14+ PHPUnit 23/23 and Behat 5/5; Moodle 5.2.4 PHPUnit 23/23 and Behat 5/5, plus a full `admin/cli/cron.php` pass on 5.2.4 with the guest account enrolled as a student (PHP 8.3, PostgreSQL 16, mod_peerwork 4.5.0.5 installed on both). moodle-cs (`moodle-extra`) reports no issues.
