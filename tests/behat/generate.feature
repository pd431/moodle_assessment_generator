@tool @tool_assessmentgen
Feature: Generate assessment data on existing courses
  In order to test assessment dashboards with realistic data
  As a site administrator
  I need to add assignments, quizzes, peer assessments, submissions and marks to courses I pick by name, and remove them again

  Background:
    Given the following "courses" exist:
      | fullname           | shortname     |
      | History 101 202526 | HIS101-202526 |
      | Maths 200 202526   | MAT200-202526 |
      | History 101 202425 | HIS101-202425 |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sasha     | Student  |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course        | role           |
      | student1 | HIS101-202526 | student        |
      | student2 | HIS101-202526 | student        |
      | student1 | MAT200-202526 | student        |
      | teacher1 | HIS101-202526 | editingteacher |
      | teacher1 | MAT200-202526 | editingteacher |

  Scenario: Preview lists the courses that match and what will be created
    Given I log in as "admin"
    And I navigate to "Development > Assessment data generator" in site administration
    When I set the field "Course name contains" to "202526"
    And I press "Preview"
    Then I should see "2 courses match"
    And I should see "HIS101-202526"
    And I should see "MAT200-202526"
    And I should not see "HIS101-202425"
    And I should see "3 students and 2 markers across 2 courses"

  Scenario: Generating queues a task per course and the run can be purged
    Given I log in as "admin"
    And I navigate to "Development > Assessment data generator" in site administration
    And I set the following fields to these values:
      | Course name contains         | 202526 |
      | Fewest assessments per course | 2      |
      | Most assessments per course   | 2      |
      | Run tag                       | trial1 |
      | Seed                          | 42     |
    And I press "Preview"
    And I set the field "MAT200-202526 · Maths 200 202526 (1 students, 1 markers, 2 assessments)" to "0"
    When I press "Generate"
    Then I should see "Queued as background tasks"
    And I run all adhoc tasks
    And I navigate to "Development > Assessment data generator" in site administration
    And I should see "Done" in the "trial1" "table_row"
    And generator run "trial1" should have "2" activities on the site
    And I click on "Purge" "button" in the "trial1" "table_row"
    And I press "Continue"
    And I should see "Purge of run trial1 queued as a background task"
    And I should see "Purging" in the "trial1" "table_row"
    And I run all adhoc tasks
    And I navigate to "Development > Assessment data generator" in site administration
    And generator run "trial1" should have "0" activities on the site
    And I should see "Purged" in the "trial1" "table_row"

  Scenario: A run can be stopped and resumed from the runs table
    Given I log in as "admin"
    And I navigate to "Development > Assessment data generator" in site administration
    And I set the following fields to these values:
      | Course name contains            | HIS101-202526 |
      | Fewest assessments per course   | 2             |
      | Most assessments per course     | 2             |
      | Assessments per background task | 1             |
      | Run tag                         | halt          |
    And I press "Preview"
    And I press "Generate"
    When I click on "Stop" "button" in the "halt" "table_row"
    Then I should see "Run halt stopped"
    And I should see "Stopped" in the "halt" "table_row"
    And I run all adhoc tasks
    And generator run "halt" should have "0" activities on the site
    And I navigate to "Development > Assessment data generator" in site administration
    And I should see "0 of 2 parts done" in the "halt" "table_row"
    And I click on "Resume" "button" in the "halt" "table_row"
    And I should see "Run halt resumed"
    And I run all adhoc tasks
    And I navigate to "Development > Assessment data generator" in site administration
    And I should see "Done" in the "halt" "table_row"
    And I should see "2 of 2 parts done" in the "halt" "table_row"
    And generator run "halt" should have "2" activities on the site

  Scenario: Students see their generated submission and released mark
    Given the following "tool_assessmentgen > runs" exist:
      | course        | tag   | types  | seed | minper | maxper | duefrom | dueto | submitrate | draftrate | laterate | workflowrate | blindrate | teamrate | extensionrate |
      | HIS101-202526 | marks | assign | 7    | 1      | 1      | -60     | -40   | 1          | 0         | 0        | 0            | 0         | 0        | 0             |
    When I am on the "HIS101-202526" course page logged in as "student1"
    And I am on assignment "1" of generator run "marks"
    Then I should see "Submitted for grading"
    And I should see "Graded"
    And I should see "Feedback"
    And I should see "100.00"

  Scenario: Students see their finished quiz attempt and its grade
    Given the following "tool_assessmentgen > runs" exist:
      | course        | tag  | types | seed | minper | maxper | duefrom | dueto | submitrate | draftrate | extensionrate |
      | HIS101-202526 | quiz | quiz  | 3    | 1      | 1      | -40     | -30   | 1          | 0         | 0             |
    When I am on the "HIS101-202526" course page logged in as "student1"
    And I am on activity "1" of generator run "quiz"
    Then I should see "Your attempts"
    And I should see "Finished"

  Scenario: Peer assessment uses generated groups that purge removes
    Given the following "users" exist:
      | username | firstname | lastname |
      | student3 | Sol       | Student  |
      | student4 | Sid       | Student  |
    And the following "course enrolments" exist:
      | user     | course        | role    |
      | student3 | HIS101-202526 | student |
      | student4 | HIS101-202526 | student |
    And the following "tool_assessmentgen > runs" exist:
      | course        | tag   | types    | groups  | seed | minper | maxper | duefrom | dueto | submitrate | draftrate |
      | HIS101-202526 | peers | peerwork | missing | 5    | 1      | 1      | -40     | -30   | 1          | 0         |
    When I am on the "HIS101-202526" course page logged in as "teacher1"
    And I am on activity "1" of generator run "peers"
    Then I should see "Group 1 (peers)"
    And I log out
    And I log in as "admin"
    And I navigate to "Development > Assessment data generator" in site administration
    And I click on "Purge" "button" in the "peers" "table_row"
    And I press "Continue"
    And I run all adhoc tasks
    And generator run "peers" should have "0" activities on the site
    And I am on the "HIS101-202526" "groups" page
    And I should not see "Group 1 (peers)"

  Scenario: A run can create its own courses, students and staff, and purge removes them
    Given I log in as "admin"
    And I navigate to "Development > Assessment data generator" in site administration
    And I set the following fields to these values:
      | Courses to create             | 2      |
      | Term in course names          | 202526 |
      | Fewest students per course    | 3      |
      | Most students per course      | 5      |
      | Mean students per course      | 4      |
      | Fewest assessments per course | 1      |
      | Most assessments per course   | 1      |
      | Run tag                       | fresh  |
      | Seed                          | 7      |
    When I press "Preview"
    Then I should see "2 new courses will be created in the category \"Generated courses (fresh)\""
    And I should see "Generated staff"
    And I press "Generate"
    And I should see "Password for every account this run creates"
    And I press "Continue"
    And I should see "Queued" in the "fresh" "table_row"
    # The first pass creates the courses and queues their parts; the second builds them.
    And I run all adhoc tasks
    And I run all adhoc tasks
    And I navigate to "Development > Assessment data generator" in site administration
    And I should see "Done" in the "fresh" "table_row"
    And generator run "fresh" should have "2" activities on the site
    And I am on course index
    And I should see "Generated courses (fresh)"
    And I navigate to "Development > Assessment data generator" in site administration
    And I click on "Purge" "button" in the "fresh" "table_row"
    And I press "Continue"
    And I run all adhoc tasks
    And I am on course index
    And I should not see "Generated courses (fresh)"
