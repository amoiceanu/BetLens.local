Feature: Inspect data sources
  As a BetLens user
  I want to see real providers and their imported data

  Scenario: The data sources page lists the configured providers
    When I open the data sources page
    Then the response is successful
    And I see "API-Football" in the response
    And I see "Sportmonks Football API" in the response
    And I see "Open-Meteo API" in the response
