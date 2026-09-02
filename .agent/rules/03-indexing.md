---
trigger: always_on
---

# Indexing Priority
description: Ensures the agent has the correct dependency context.

instructions:
  - **Context Initialization:** At the start of every new task, verify the contents of `composer.json` and `package.json`.
  - **Dependency Awareness:** If you are asked to write a feature, check these files first to ensure you use the installed versions of Laravel, Inertia, or React.
  - **Change Detection:** If the user runs `composer update` or `npm install`, immediately re-scan these files to update your internal project map.
