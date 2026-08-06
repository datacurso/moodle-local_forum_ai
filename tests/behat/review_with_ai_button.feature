@local @local_forum_ai
Feature: Visibility of the Review with AI button in the forum grading interface
  In order to grade forum participation with AI assistance
  As a teacher with the AI review permission
  I need the Review with AI button to appear only where it applies

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "scales" exist:
      | name       | scale                          |
      | Test Scale | Disappointing, Good, Excellent |
    And the following "activities" exist:
      | activity | course | idnumber | name         | grade_forum |
      | forum    | C1     | gforum   | Graded forum | 10          |
      | forum    | C1     | pforum   | Plain forum  | 0           |
    And the following "mod_forum > discussions" exist:
      | user     | forum  | name         | message               |
      | student1 | gforum | Discussion G | Student participation |

  @javascript @MDL-E2E-001 @SYS-E2E-004
  Scenario: The button appears for a teacher in the grading panel of a point graded forum
    Given I am on the "Graded forum" "forum activity" page logged in as "teacher1"
    When I press "Grade users"
    Then "#forum-ai-review-btn" "css_element" should be visible
    And I should see "Review with AI"
    # Paso manual: requiere servicio de IA (SYS-E2E-004 pasos 2-5: estado de carga al pulsar,
    # inyeccion de la nota en el formulario sin guardarse, notificacion de exito y notificacion
    # persistente de error del servicio).

  @javascript @MDL-E2E-001
  Scenario: The button does not exist for a student without the AI review permission
    Given I am on the "Graded forum" "forum activity" page logged in as "student1"
    Then "#forum-ai-review-btn" "css_element" should not exist
    And "Grade users" "button" should not exist

  @javascript @MDL-E2E-001
  Scenario: The button is not visible when the whole forum grading type is None
    Given I am on the "Plain forum" "forum activity" page logged in as "teacher1"
    Then "Grade users" "button" should not exist
    And "#forum-ai-review-btn" "css_element" should not be visible
    # Paso manual: requiere servicio de IA (MDL-E2E-001 pasos 3-4: reposicionamiento del boton
    # al cambiar de estudiante con limpieza de notificaciones previas y bloqueo de una segunda
    # solicitud durante una evaluacion en curso).

  @javascript @MDL-E2E-001 @SYS-E2E-006
  Scenario: The button appears next to the scale selector in a scale graded forum
    Given I am on the "Graded forum" "forum activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I set the field "Whole forum grading > Type" to "Scale"
    And I set the field "Whole forum grading > Scale" to "Test Scale"
    And I press "Save and display"
    When I press "Grade users"
    Then "select[name='grade']" "css_element" should exist
    And "#forum-ai-review-btn" "css_element" should be visible
    And I should see "Review with AI"
    # Paso manual: requiere servicio de IA (SYS-E2E-006 pasos 2-3: el servicio recibe las
    # opciones de la escala y el resultado se inyecta en el selector con la opcion correcta).
    # Paso manual: requiere servicio de IA (SYS-E2E-005 completo: rubrica y guia de evaluacion
    # completadas por la IA con criterios acentuados; solo es verificable con el servicio real).
