@local @local_stackforge
Feature: STACK Forge administration settings
  In order to configure AI question generation
  As an administrator
  I need to reach its configuration page

  Scenario: An administrator can view the STACK Forge settings page
    Given I log in as "admin"
    And I visit "/admin/settings.php?section=local_stackforge"
    Then I should see "Generation service URL"
    And I should see "API token"
