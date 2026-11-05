# Native Deployer integration: rollout TODO

Status: 2026-11-05. This repository provides the native OTA client, attempt-bound
callbacks, explicit native root forwarding and opt-in native push receiver used by
the paired Deployer branch. Legacy downloads remain a separate compatibility path.
These changes do not require the external `deployer/deployer` library.

## Verified

- Native download tests in the paired Deployer suite cover verified TLS, complete
  SHA-256, attempt-derived filenames, exclusive file creation and callback identity.
- Root arguments are literal argv and accepted only for verified native downloads.
- Fixture push tests require a matching private completion receipt, not exit0 alone.
- Windows HTTP audit clients use managed native trust only without explicit CA
  settings; certificate verification remains enabled. Affected scanner tests:
  65 tests / 335 assertions passed.
- Local real saved-build audit persisted 205 links in Deployer; this does not imply
  a vulnerability-free build or complete license evidence.

## Before production rollout

- [ ] Publish/pin compatible Deployer, PackageManager and Core releases; validate a
  clean dependency installation and mixed-version/legacy-client behavior.
- [ ] Run real authenticated HTTPS download/install/callback acceptance with the
  intended target service identities, staging ACLs, OS and PHP versions.
- [ ] Review attempt authorization, replay/concurrent requests, proxy TLS handling,
  bootstrap trust and operator recovery. Checksums are not independent signatures.
- [ ] Configure native push explicitly on a private target-owned staging directory;
  test actual upload limits, deadlines, interrupted requests and retained recovery.
- [ ] Review safe target-side diagnostic logging for push failures without leaking
  installer output or secrets; public errors are deliberately generic.
- [ ] Validate non-Windows trust behavior and supported PHP/cURL combinations.
- [ ] Provide a discoverable Composer >=2.7 where Composer audit is required.
- [ ] Resolve existing `RegistryOutdatedScannerTest::testDefaultScannerRegistration`
  expectation mismatch: it expects outdated scanners in default config, while the
  current default registration omits them. Deployer explicitly selects them.
- [ ] Move/duplicate cross-package OTA regression coverage into a standalone
  PackageManager acceptance suite if releases need independent validation.

No live target installation, service account change or shared root Composer
lock/vendor dependency update is performed by committing these sources.
