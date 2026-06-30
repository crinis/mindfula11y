# Changelog

All notable changes to this extension are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

When cutting a release, copy the relevant section into the GitHub Release notes —
the `Publish to TER` workflow uses the release body as the TER upload comment.

## [Unreleased]

## [0.11.1] - 2026-06-30

Bugfix release. Resolves a fatal error in the heading ViewHelpers when the
heading level is resolved from the database, and clarifies how to reference
translated records from templates.

### Fixed

- Fixed a fatal `TypeError` in `mindfula11y:heading`, `mindfula11y:heading.sibling`
  and `mindfula11y:heading.descendant` when the heading type is resolved from the
  database — i.e. the ViewHelper is used with the record arguments but without an
  explicit `type`. `AbstractHeadingViewHelper::resolveHeadingType()` returned a
  string instead of the declared `HeadingType`, so the frontend rendered a 500
  error whenever the referenced record had a stored heading type. Present since
  v0.5.0.

### Documentation

- Documented resolving the localized record uid for translated content
  (`data._LOCALIZED_UID` in the classic data-array rendering, and
  `record.computedProperties.localizedUid` in the TYPO3 14 `record` / `PAGEVIEW`
  pipeline), including why the Fluid `{… ?: …}` shorthand must not be used for it.

## [0.11.0] - 2026-06-18

TYPO3 14 LTS compatibility release. Mindful A11y now supports TYPO3
14.3 LTS alongside TYPO3 13.4 LTS, with backend module adjustments,
scanner hardening, and permission-aware missing-alt results for editor
workflows.

### Added

- TYPO3 14.3 LTS compatibility alongside TYPO3 13.4 LTS.

### Changed

- Renamed the extension title to "Accessibility Toolkit" and refreshed the
  composer/EM description to cover the full feature set.
- Updated backend module selector handling for TYPO3 14 while retaining
  TYPO3 13.4 compatibility.

### Fixed

- Aligned missing-alt counts and pagination with backend user file-mount
  permissions.
- Adjusted scanner permissions so editors no longer need direct access to
  internal scan-state page fields.

### Security

- Blocked direct backend writes to internal scanner state fields.
- Validated scanner-provided URLs before rendering backend links or images.
- Made demand HMAC input construction unambiguous.

---

This changelog was introduced during 0.10.0 (beta) development. Detailed notes
for earlier releases predate it — see the git tags and GitHub Releases:

- 0.3.0 — 2025-08-19
- 0.2.1 — 2025-08-19
- 0.1.1 — 2025-06-04
