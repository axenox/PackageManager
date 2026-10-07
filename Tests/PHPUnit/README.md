# PHPUnit Tests

PHPUnit is a development dependency of this app. Tests use a real Workbench where
needed; they do not mock Workbench, connect to a database, install scanner tools,
or contact advisory services. PHPUnit must be installed with development dependencies,
either in the app checkout or in the containing ExFace installation (for example via BDT).
Composer does not install a dependency package's `require-dev` entries in the host project.

From a standalone app checkout with its own development dependencies installed:

```console
php vendor/bin/phpunit
```

From an ExFace installation root:

```console
php vendor/bin/phpunit -c vendor/axenox/packagemanager/phpunit.xml.dist
php vendor/bin/phpunit -c vendor/axenox/packagemanager/phpunit.xml.dist --testsuite unit
php vendor/bin/phpunit -c vendor/axenox/packagemanager/phpunit.xml.dist --testsuite integration
php vendor/bin/phpunit -c vendor/axenox/packagemanager/phpunit.xml.dist --filter testSharedPublicIdDeduplicatesNativeIds
```

## Layout

All PHPUnit suites, support files and this guide live under `Tests/PHPUnit/`.
Other frameworks use sibling folders such as `Tests/Behat/` and `Tests/Bruno/`.
The app-root `phpunit.xml.dist` discovers only the PHPUnit suites below.

- `Unit/`: named, independent scenarios for parsing, aggregation, reporting and scanner
  orchestration. HTTP responses and Composer commands are controlled by test fixtures.
  Some scenarios use temporary local files, but none spawn processes.
- `Integration/`: real local PHP processes for Composer PHAR resolution, isolation,
  environment propagation, argument boundaries and exit-code handling. No real Composer
  installation or Trivy executable is required.
- `Support/`: shared fixture responses, temporary-directory cleanup and small scanner
  doubles. The synchronous action test substitutes result-sheet creation and bypasses
  constructors of unused database-bound collaborators, not Workbench.
- `bootstrap.php`: loads Composer from either a standalone checkout or the host
  installation, then loads test support explicitly. Tests are excluded from the production classmap.

## Adding Tests

Name files and classes `*Test` and give each scenario its own `test*` method. Use PHPUnit
assertions and expected exceptions rather than custom assertion functions. Keep scenarios
independent of execution order; construct fresh fixtures in setup and register temporary
folders for teardown before running the behavior under test.

Use real Workbench instances only for behavior that does not require a persisted metamodel
or database. Scenarios requiring the full application lifecycle belong in application-level
tests, not a mocked Workbench unit test. The action checks here exercise local `perform`
behavior, not public authorization or persistence.

Use PHPUnit process isolation when a scenario changes immutable global state. The
Apache PHP executable regression defines a namespaced `PHP_BINARY` constant and therefore
runs in a separate process with global-state preservation disabled. Other tests do not
inherit that constant.