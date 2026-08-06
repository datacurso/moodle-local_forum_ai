@local @local_forum_ai
Feature: Visibility of AI replies in a question and answer forum
  In order to keep the Q&A restriction intact
  As a student who has not answered yet
  I must not see the AI reply generated for another student

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activity" exists:
      | activity | forum       |
      | course   | C1          |
      | idnumber | qandaforum  |
      | name     | Q and A forum |
      | type     | qanda       |
    And the following "mod_forum > discussions" exist:
      | user     | forum      | name           | message                  |
      | teacher1 | qandaforum | Big question   | Answer this big question |
    # Paso manual: requiere servicio de IA (paso 1: el estudiante A publica y la IA genera y
    # publica la replica). Aqui la respuesta del estudiante A y la replica "de la IA" publicada
    # por el usuario calificador se siembran como publicaciones normales del foro.
    And the following "mod_forum > posts" exist:
      | user     | discussion   | parentsubject    | subject          | message                    |
      | student1 | Big question | Big question     | Student A answer | This is my answer          |
      | teacher1 | Big question | Student A answer | AI reply         | AI feedback for student A  |

  @MDL-E2E-002
  Scenario: A student who has not posted cannot see the AI reply for another student
    Given I am on the "Q and A forum" "forum activity" page logged in as "student2"
    When I follow "Big question"
    Then I should see "Answer this big question"
    And I should not see "AI feedback for student A"
    And I should not see "This is my answer"
    # Paso manual: verificar que el estudiante B tampoco recibe la replica de la IA por correo
    # (suscripciones): requiere revision del buzon de correo del entorno real.

  @javascript @MDL-E2E-002
  Scenario: After posting their own answer the student can see the AI reply
    Given the following config values are set as admin:
      | maxeditingtime | 1 |
    And I am on the "Q and A forum" "forum activity" page logged in as "student2"
    And I follow "Big question"
    And I should not see "AI feedback for student A"
    When the following "mod_forum > posts" exist:
      | user     | discussion   | parentsubject | subject          | message            |
      | student2 | Big question | Big question  | Student B answer | Here is my attempt |
    And I wait "2" seconds
    And I reload the page
    Then I should see "Student B answer"
    And I should see "AI feedback for student A"
    And I should see "This is my answer"
