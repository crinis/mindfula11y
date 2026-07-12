# Changelog

All notable changes to this extension are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

When cutting a release, copy the relevant section into the GitHub Release notes —
the `Publish to TER` workflow uses the release body as the TER upload comment.

## [Unreleased]

Frontend platform release. The entire backend frontend is rewritten as
TypeScript/Lit shadow-DOM web components, scans gain an optional AI review
via MindfulAPI's agent audit, and the heading structure check now validates
the actual rendered outline with axe-core-aligned semantics.

**Requires [MindfulAPI](https://github.com/crinis/mindfulapi) v0.7.0 or
later for scanner features** — the extension now talks to the versioned
`/v1` API routes and consumes the AI agent audit fields introduced there.

### Added

- Per-reference **Decorative image** control for image references. Decorative references store an
  explicit empty alternative and title, are omitted from missing-alt counts and render as
  `alt=""` without a `title` attribute through native `f:image` and image-mode `f:media`;
  templates must not override them with non-empty explicit `alt` or `title` arguments. The
  description remains available as a visible caption.
- Optional **AI review (agent audit)** for scans: MindfulAPI (v0.7.0+) can run
  a language-model audit alongside the axe-core scan, covering image alt text,
  heading structure, link purpose, form labels and page title. Editors opt in
  per scan via an "Include AI review" toggle; findings render in a dedicated
  AI review section with severity, confidence, WCAG reference and suggestion.
  Configured via `mod.mindfula11y_accessibility.scan.aiAudit.*` Page TSconfig
  (`enable`, `default`) — automatically created scans never request
  an audit, so no LLM cost is incurred by simply browsing the backend.
- Frontend CI (lint, typecheck, build, committed-output verification); TER
  publication is now gated on the same verification of the tagged commit.

### Changed

- AI review requests now run every skill enabled by MindfulAPI's `AGENT_SKILLS`
  setting when Page TSconfig omits `aiAudit.skills`. Integrators can still
  configure a smaller subset or an empty list; TYPO3 forwards explicit lists
  unchanged so MindfulAPI remains the single validation and whitelist authority.
- **Frontend rewritten as TypeScript/Lit shadow-DOM web components.** Typed
  sources under `Resources/Private/Source/` are compiled to the shipped ES
  modules; every component renders into its own shadow root with layered CSS
  over a token bridge onto TYPO3's backend variables. The module UI follows
  the backend color scheme (including dark mode), holds its own text to WCAG
  AAA contrast, announces status changes through pre-rendered live regions,
  and is hardened for keyboard use, 200 % zoom and 320 px reflow. Scan
  polling now also resumes when a running scan's element is re-inserted into
  the DOM (e.g. after tab switches).
- **The heading structure check validates the rendered outline.** All
  `h1`–`h6` of the analyzed page are included — also headings rendered
  without record binding (or without the ViewHelper), which were previously
  invisible to the check. Missing `<h1>`, multiple `<h1>` and empty headings
  are now flagged for those as well; record-bound headings remain editable
  from the module.
- **Skipped-level detection follows axe-core's `heading-order` semantics.**
  Only an increase of more than one level against the nearest shallower
  preceding heading is an error. Headings before the first `<h1>` (e.g.
  navigation or sidebar region labels — a W3C WAI-recommended pattern) and
  decreases in level are no longer flagged.
- Alt-text generation in FormEngine moved from a custom renderType to a TCA
  `fieldControl` on core's own input element: the Generate button is now an
  icon control next to the field, and core's placeholder/override-checkbox
  behavior applies unmodified to `sys_file_reference.alternative`.
- Missing-alt cards were rebuilt with inline save/generate feedback and a
  callout showing the file-metadata fallback label where one exists.
- Development-only site sets, the visual-editor demo resources and the
  rendered `Documentation` are excluded from both dist channels
  (Packagist git archives and TER packages).

### Fixed

- New inline image references are no longer discarded when the decorative-image
  permission guard cannot resolve submitted relation columns. The guard resolves
  the parent file field and rejects only unauthorized decorative-state changes,
  preserving alt text, title, crop and link metadata.
- The decorative-image visibility condition now composes with existing TCA
  `displayCond` rules for file-reference alternative text and titles.
- File-metadata alternative placeholders are shown only to backend users who
  may read `sys_file_metadata.alternative`.
- Decorative image changes now enforce edit access to the reference's parent record and file
  field, preventing direct DataHandler requests from bypassing the module's permissions.
- `mod.mindfula11y_accessibility.missingAltText.ignoreColumns` is now
  actually read (it was documented but never consumed); the undocumented
  legacy path `mod.mindfula11y_missingalttext.<table>` keeps working for
  existing installs. The shipped `tt_content = image` default was removed —
  it had always been inert, and activating it would have hidden the standard
  image/textpic content element references from the check.
- `mod.mindfula11y_accessibility.missingAltText.ignoreFileMetadata` is now
  implemented without changing existing missing-alt results: with `0`
  (default), the metadata fallback counts as sufficient and editors get a
  filter toggle to show covered references anyway. Set it to `1` to require
  alternative text directly on every file reference.
- Scan status polling no longer floods screen readers: the interim loading
  view and unchanged statuses stay out of the live region, which now only
  announces actual status transitions.

### Removed

- The `mindfula11yAltText` FormEngine renderType (`InputAltElement`). TCA
  overrides referencing it must switch to the `mindfula11yGenerateAltText`
  fieldControl; the extension's own overrides for `sys_file_reference` and
  `sys_file_metadata` are migrated.
- The flat legacy modules directly under `Resources/Public/JavaScript/`.
  Modules now live under `element/`, `service/` and `lib/` paths, and
  `@mindfulmarkup/mindfula11y/` maps the whole directory — imports of the
  old flat module names must be updated.

### Documentation

- Documented the MindfulAPI v0.7.0 minimum requirement in the README, the
  docs landing page and the integrators chapter, and clarified the
  `ignoreColumns` / `ignoreFileMetadata` TSconfig semantics.

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
