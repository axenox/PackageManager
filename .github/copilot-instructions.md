# Copilot Instructions for PackageManager

### Coding guidelines

Always follow our [PHP](../../../exface/Core/Docs/developer_docs/code_conventions/PHP/Conventions.md) and [JavaScript](../../../exface/Core/Docs/developer_docs/code_conventions/JavaScript/Conventions.md) coding guidelines when writing code. 

## Testing

Follow the [Core testing instructions](../../../exface/core/.github/instructions/testing.instructions.md)
when implementing or fixing PHP behavior or adding tests. Add or update focused
PHPUnit coverage wherever this does not require mocking Workbench.

Reuse this app's existing unit and integration suites, bootstrap and fixtures.
Keep PHPUnit tests under `Tests/PHPUnit`, alongside other test frameworks' folders.
See [the PackageManager test guide](../Tests/PHPUnit/README.md) for layout and test commands.