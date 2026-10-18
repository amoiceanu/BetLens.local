Feature: Manage my generated tickets
  As a BetLens user
  I want the ticket history and one save action to reflect my changes

  Scenario: A generated ticket appears in the history
    Given a ticket exists with reference "BL-BDD-HISTORY"
    When I open the ticket history
    Then the response is successful
    And I see "BL-BDD-HISTORY" in the response

  Scenario: Save reference, operator and match interval together
    Given a ticket exists with reference "BL-BDD-EDIT"
    When I save the ticket with reference "PERSONAL-BDD-1", operator "Winbet", first match "2026-10-12 18:00" and last match "2026-10-13 21:00"
    Then the ticket has reference "PERSONAL-BDD-1" and status "placed" on operator "Winbet"
    And the ticket match interval is "2026-10-12 18:00" to "2026-10-13 21:00"

  Scenario: Reject an interval whose last match is before its first
    Given a ticket exists with reference "BL-BDD-INVALID"
    When I save the ticket with reference "BL-BDD-INVALID", operator "Winbet", first match "2026-10-13 21:00" and last match "2026-10-12 18:00"
    Then the ticket still has reference "BL-BDD-INVALID" and status "pending"
