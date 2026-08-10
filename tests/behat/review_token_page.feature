@local @local_forum_ai
Feature: Review an AI response through the token review page
  In order to review AI responses from the notification link
  As a teacher with approval permission
  I need to approve or reject pending responses on the token review page

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
    And the following "activity" exists:
      | activity | forum     |
      | course   | C1        |
      | idnumber | forum1    |
      | name     | Forum one |
    And the following "mod_forum > discussions" exist:
      | user     | forum  | name         | message            |
      | student1 | forum1 | Discussion A | Student question A |
    # Paso manual: requiere servicio de IA (la pendiente se genera aqui con datos sembrados
    # en lugar de una respuesta real del servicio).
    And the following "local_forum_ai > pending responses" exist:
      | forum     | discussion   | user     | subject          | message             | approval_token                   |
      | Forum one | Discussion A | student1 | Re: Discussion A | AI draft answer one | behattoken0000000000000000000001 |

  @MDL-INT-022
  Scenario: The review page shows the context, the response and the action buttons
    When I am on the "behattoken0000000000000000000001" "local_forum_ai > review" page logged in as "teacher1"
    Then I should see "Discussion information"
    And I should see "Course 1"
    And I should see "Forum one"
    And I should see "Discussion A"
    And I should see "Original message"
    And I should see "Student question A"
    And I should see "Proposed AI Response"
    And I should see "AI draft answer one"
    And "Approve" "button" should exist
    And "Reject" "button" should exist
    And "Back to discussion" "link" should exist

  @javascript @MDL-INT-022 @SYS-E2E-003
  Scenario: Approving from the review page publishes the response and invalidates the token
    Given I am on the "behattoken0000000000000000000001" "local_forum_ai > review" page logged in as "teacher1"
    When I click on "Approve" "button"
    # El JS publica la respuesta y redirige a la discusion; la publicacion queda
    # atribuida al profesor que aprueba.
    Then I should see "Teacher One"
    And I should see "AI draft answer one"
    And I should see "Discussion A"
    # Reabrir el mismo enlace: el token gestionado queda inutilizado.
    # A custom step is required: the invalid/used-token branch of review.php exits
    # before the footer and calls set_title() without a page context, so it triggers a
    # debugging() message and never completes the pending-JS setup. Core navigation
    # steps therefore fail on that page (JS-not-ready timeout / debugging detection).
    Then the review page for token "behattoken0000000000000000000001" should show the already submitted notice
    # Paso manual: requiere servicio de IA (verificar que la notificacion original del profesor
    # contiene este mismo enlace de revision generado por el flujo real).

  @MDL-INT-022
  Scenario: A non existing token shows the informative message with a continue button
    # A custom step is required: the invalid-token branch of review.php calls
    # set_title() without a page context, which emits a debugging() message that makes
    # any core navigation step fail on that page (Behat fails scenarios on debugging).
    Given I log in as "teacher1"
    Then the review page for token "doesnotexist0000000000000000000x" should show the already submitted notice

  @MDL-INT-022 @SYS-E2E-003
  Scenario: A student opening the review URL is denied access
    Given I log in as "student1"
    Then the review page for token "behattoken0000000000000000000001" should deny access
    And I am on the "Forum one" "forum activity" page
    And "Pending Forum AI Responses" "link" should not exist in current page administration
    And "Forum AI Response History" "link" should not exist in current page administration
