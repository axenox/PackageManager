# Copilot Instructions for PackageManager

## Testing

Follow the [Core testing instructions](../../../exface/core/.github/instructions/testing.instructions.md)
when implementing or fixing PHP behavior or adding tests. Add or update focused
PHPUnit coverage wherever this does not require mocking Workbench.

Reuse this app's existing unit and integration suites, bootstrap and fixtures.
Keep PHPUnit tests under `Tests/PHPUnit`, alongside other test frameworks' folders.
See [the PackageManager test guide](../Tests/PHPUnit/README.md) for layout and test commands.