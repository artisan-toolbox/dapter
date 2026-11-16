---
name: dapter-implementation
description: Implement or change Dapter public APIs, options, configuration, attributes, commands, or frontend behavior together with functional demos in the central documentation application.
---

# Implement Dapter with working demos

Use this contributor skill with `atb-package-development` and `atb-documentation` when changing Dapter. The canonical implementation plan is the Dapter roadmap in the workspace `docs` application.

Every delivered public option and configuration key needs a fully functional example in `docs` in the same change. Cover equivalent PHP attributes, factory modes, command options, and frontend props too. A proposed snippet, mocked result, or source-code screenshot does not establish working behavior.

Organize guides by reader task and keep each demo focused on one behavior. Use fixed-source examples instead of a selector that combines unrelated configurations. Keep proposed design contracts on specialized design pages and keep roadmap progress separate. Preserve moved links and fragments through the documentation application's owning metadata.

Build each demo with the Composer-installed Dapter public API. Keep backend helpers under `docs/app/Demo/Dapter/` and Vue demos under `docs/resources/js/components/demo/dapter/`. Use the shared `DemoFrame` and public Front Awesome components wherever their supported APIs fit. Record a concrete API limitation before using a separate presentation control. Keep Dapter headless; demo presentation does not add a Front Awesome runtime dependency to Dapter.

Define the example data, prerequisites, reader action, expected records or error, and reset behavior. Start data loading or other active work only after the reader acts. Use fictional, isolated fixtures. Do not change a reader's authentication, roles, or application records for a demo.

Connect each delivered setting to its demo and behavioral verification in the docs Dapter coverage registry. Update the coverage check when adding a new kind of public setting. The check must discover package options and configuration rather than trusting a hand-maintained total. One demo may illustrate several settings when each effect is observable and verified.

Test the actual package behavior and the consuming docs flow. For a CLI setting, execute the documented command in an isolated fixture and verify its generated output. Drive interactive examples in the local browser and run the relevant automated browser checks. A registry entry or a passing content assertion alone is not demo verification.

Keep the package tests, consumer guidance, docs pages, changelogs, and roadmap progress synchronized. Mark only completed work as done. Leave later capabilities explicitly unavailable until their implementation, demo, and tests exist.

This skill is repository contribution guidance. Do not copy these workflow requirements into the Boost skill intended for consuming applications.
