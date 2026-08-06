@local @local_forum_ai
Feature: Full approval flow of an AI generated response
  In order to control what the AI publishes
  As a teacher with approval permission
  I need to edit, approve and publish pending responses

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
    And the following "local_forum_ai > configs" exist:
      | forum     | enabled | require_approval |
      | Forum one | 1       | 1                |
    And the following "mod_forum > discussions" exist:
      | user     | forum  | name         | message            |
      | student1 | forum1 | Discussion A | Student question A |

  @javascript @SYS-E2E-001
  Scenario: The teacher edits the pending response in the modal, approves it and it is published under their name
    # Paso manual: requiere servicio de IA (paso 1-2: el estudiante publica una replica y la IA
    # genera la respuesta pendiente y notifica al profesor). Aqui la pendiente se siembra.
    Given the following "local_forum_ai > pending responses" exist:
      | forum     | discussion   | user     | subject          | message               |
      | Forum one | Discussion A | student1 | Re: Discussion A | Original AI draft one |
    And I am on the "Forum one" "forum activity" page logged in as "teacher1"
    And I navigate to "Pending Forum AI Responses" in current page administration
    And I click on "Details" "button" in the "Re: Discussion A" "table_row"
    And I should see "Discussion Details" in the ".modal-title" "css_element"
    When I set the field with xpath "//textarea[@id='airesponse-edit']" to "Edited AI answer by the teacher"
    And I click on "Save and Approve" "button" in the ".modal-body" "css_element"
    Then I should see "No pending responses for approval."
    And I am on the "Forum one" "forum activity" page
    And I follow "Discussion A"
    And I should see "Re: Discussion A"
    And I should see "Edited AI answer by the teacher"
    And I should see "Teacher One"

  @javascript @SYS-E2E-001
  Scenario: The Save button of the modal saves the edited text without approving or publishing
    Given the following "local_forum_ai > pending responses" exist:
      | forum     | discussion   | user     | subject          | message               |
      | Forum one | Discussion A | student1 | Re: Discussion A | Original AI draft one |
    And I am on the "Forum one" "forum activity" page logged in as "teacher1"
    And I navigate to "Pending Forum AI Responses" in current page administration
    And I click on "Details" "button" in the "Re: Discussion A" "table_row"
    When I set the field with xpath "//textarea[@id='airesponse-edit']" to "Saved but not approved text"
    And I click on "Save" "button" in the ".modal-body" "css_element"
    # The page reloads and the row must still be pending, with the updated text.
    Then I should see "Re: Discussion A"
    And I should see "Saved but not approved text"
    And I am on the "Forum one" "forum activity" page
    And I follow "Discussion A"
    And I should not see "Saved but not approved text"

  @javascript @SYS-E2E-002
  Scenario: An approved response is published in the discussion attributed to the approving user
    # Paso manual: requiere servicio de IA (publicacion automatica atribuida al calificador,
    # revision diferida con tiempo de espera y valoracion automatica de la publicacion del
    # estudiante: dependen de la ida y vuelta real al servicio y de la tarea programada).
    # Aqui se cubre la parte de atribucion sobre una pendiente sembrada.
    Given the following "local_forum_ai > pending responses" exist:
      | forum     | discussion   | user     | subject          | message             |
      | Forum one | Discussion A | student1 | Re: Discussion A | AI queued answer    |
    And I am on the "Forum one" "forum activity" page logged in as "teacher1"
    And I navigate to "Pending Forum AI Responses" in current page administration
    When I click on "Approve" "button" in the "Re: Discussion A" "table_row"
    Then I should not see "AI queued answer"
    And I am on the "Forum one" "forum activity" page
    And I follow "Discussion A"
    And I should see "AI queued answer"
    And I should see "Teacher One"
