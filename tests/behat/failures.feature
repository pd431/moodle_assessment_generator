@tool @tool_assessmentgen
Feature: Runs that fail on a busy database recover cleanly
  In order to trust large parallel runs
  As a site administrator
  I need a failed part to show as failed and never to block a purge

  Background:
    Given the following "courses" exist:
      | fullname           | shortname     |
      | History 101 202526 | HIS101-202526 |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sasha     | Student  |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course        | role           |
      | student1 | HIS101-202526 | student        |
      | student2 | HIS101-202526 | student        |
      | teacher1 | HIS101-202526 | editingteacher |

  Scenario: A failure inside core's module transaction still marks the part failed
    Given a generator run "tx" is queued with 1 part in course "HIS101-202526"
    And the test fault "throw inside add_moduleinfo" is armed
    When I run all adhoc tasks, letting them fail
    Then the part "1" of run "tx" has status "failed"
    And the run "tx" shows the error "fault: addmodule"
    And no database transaction is left open
    And generator run "tx" should have "0" activities on the site

  Scenario: A database write error is retried once
    Given a generator run "retry" is queued with 1 part in course "HIS101-202526"
    And the test fault "dml write error on the first attempt of assessment 1" is armed
    When I run all adhoc tasks
    Then the run "retry" has status "done"
    And generator run "retry" should have "2" activities on the site

  Scenario: A part whose worker died is failed, not waited for
    Given a generator run "dead" has a part marked "running" with no lock held
    When I log in as "admin"
    And I navigate to "Development > Assessment data generator" in site administration
    Then I should see "Failed" in the "dead" "table_row"
    And I should see "worker ended without recording a result" in the "dead" "table_row"
    And I click on "Purge" "button" in the "dead" "table_row"
    And I press "Continue"
    And I run all adhoc tasks
    And I navigate to "Development > Assessment data generator" in site administration
    And I should see "Purged" in the "dead" "table_row"
