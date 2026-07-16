# Changelog

All notable changes to this extension are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

When cutting a release, copy the relevant section into the GitHub Release notes —
the `Publish to TER` workflow uses the release body as the TER upload comment.

## [Unreleased]

### Changed

- Heading and landmark checks now analyze the rendered frontend at mobile and desktop sizes, ignore elements hidden from assistive technology in each layout, and label results by viewport. Same-labelled navigation landmarks with identical link sets are accepted; ambiguous same-labelled landmarks are warnings instead of automatic errors. Frontend previews on other site-root domains are supported through short-lived signed iframe analysis requests; no MindfulAPI setup is required. Only the isolated analysis runner executes, so JavaScript-generated structure is not included.
- `<mindfula11y:heading.descendant>` no longer increments an explicitly passed `type` argument by `levels`. An explicit `type` is now used verbatim as the tag name, matching `<mindfula11y:heading>`, `<mindfula11y:heading.sibling>`, and the argument's documented "rendered directly" semantics; `levels` still applies to heading types resolved from an ancestor relation or a database record.
- The structure-analysis error notice now shows a failure-specific description (ticket issuance, preview timeout, frame handshake, HTTP error, runner analysis, response payload, or record-metadata enrichment) instead of one generic message, so editors can tell what actually failed before retrying.

- The module's "General" feature is renamed to **Overview** (menu label, and the `feature` URL/module-data value changes from `general` to `overview`). Stored module states and bookmarked URLs using the old value fall back to the Overview view automatically; only direct links that named `feature=general` explicitly will land on the (identical) default view.
- The extension's frontend middleware identifiers are renamed to carry the structure-analysis feature name (`mindfulmarkup/mindfula11y/structure-analysis-authentication`, `…-disable-cache`, `…-disable-admin-panel`). Sites whose own RequestMiddlewares.php ordered against the old identifiers must update them.
- All accessibility AJAX endpoints now report failures in the same localized, structured form (an error title with a description), so the module surfaces translated messages for every endpoint. Structure-analysis endpoint errors were previously untranslated technical strings. Allowed HTTP methods are now enforced for the scan and alt-text endpoints as well (they were previously declared but not enforced).

### Fixed

- The Missing Alternative Texts module view no longer crashes with a PHP error. Building the record-type menu passed the module's read-only TSconfig state into a by-reference parameter, which PHP rejects; the affected service methods now take the configuration by value.
- Heading types stored on records (the `recordUid`/`recordColumnName` arguments of `<mindfula11y:heading>`, `<mindfula11y:heading.sibling>` and `<mindfula11y:heading.descendant>`) now honour the current workspace when a structure-analysis preview renders inside a workspace. Previously the live heading type was always read, so an editor previewing a workspace could see the published tag instead of their draft change; the record is now overlaid with its workspace version. Empty results are cached per request so unannotated records no longer re-query for every heading on the page.
- A scan-creation or scan-status response with a missing or unrecognized status now surfaces as a loading error instead of silently being treated as a completed scan with no issues.
- Asynchronous structure, scan, and alternative-text errors and save confirmations are now announced from their visible status messages. Non-visible live announcements remain limited to generated summaries and background lifecycle updates, avoiding duplicate screen-reader output.
- Structure-analysis preview ticket URLs are now generated server-side from the authorized page and language. The signed tickets are stateless, expire after 15 seconds, validate their origins before using them in response security headers, and revalidate current module, account, DB-mount, page, workspace, and language access whenever they are used. Their signed workspace remains pinned even when a request carries an invalid or stale backend cookie, and all accessibility feature endpoints inherit the module's access restrictions. Scan-creation demands expire after one hour instead of remaining reusable indefinitely. Any resolved page mismatch is rejected before frontend rendering starts. Non-HTML responses and deployments missing the isolated analysis runner fail closed without exposing preview-only content or executing ordinary frontend scripts.
- The scan module's tab panels no longer mark themselves `aria-busy` on every background poll once a result is showing — only an explicit action (trigger/cancel/refresh) or the panel's first load is announced as busy, so assistive technology stops re-announcing a panel that already has content.
- All three heading ViewHelpers (`<mindfula11y:heading>`, `<mindfula11y:heading.sibling>`, `<mindfula11y:heading.descendant>`) now validate an explicit `type` argument against the allowed heading types instead of passing an unrecognized value straight through to the rendered tag name. A `type` value outside `h1`-`h6`/`p`/`div` now falls back to the default `h2` tag instead of rendering an arbitrary, unintended tag.
- Canceling a scan now clears a stale error notice left over from a previous action instead of leaving it pinned over the reloaded result.
- The accessibility module now validates language access against the requested language itself. Previously, requesting a language the page was not translated into skipped the access check (it collapsed to the default language) while the missing-alt-text queries still filtered by the requested language, so language-restricted editors could list file references of a language they have no access to, and the language selector could disagree with the displayed content.
- The scan card in the overview and the page-module panel no longer offer automatic scan creation to users without edit access to the page; previously the attempt failed with a permission error. Structure-analysis responses now fail closed when a frontend middleware short-circuits around the hardening (e.g. an internal redirect), and redirects are never followed inside the sandboxed analysis frame — they surface as an analysis error for the signed page instead.
- Heading and landmark editing controls (structure analysis) and alternative-text editing now work in offline workspaces: writes create workspace versions exactly like FormEngine edits. Previously an over-strict permission check locked all structure controls and denied alt-text saves for users working in a workspace. Triggering accessibility scans is now explicitly limited to the live workspace (the external scanner cannot fetch workspace previews, and the stored scan id must not create a workspace version of the page); workspace users previously received a misleading permission error instead of the controls simply not being offered.
- On TYPO3 v14, structure-editing metadata now honours backend-layout content-type restrictions (`allowedContentTypes`/`disallowedContentTypes` on backend layout columns): heading and landmark selectors are no longer offered for content elements whose type is not allowed in their column, where saving would have been silently rejected by TYPO3.
- Composer installation no longer fails on current PHP 8.4 releases: the `php` version constraint read `>=8.2 <=8.4`, which only matched PHP 8.4.0 exactly and rejected every 8.4 patch release. It is now `>=8.2 <8.5`.
- AI alt-text generation demands now expire after one hour, matching scan-creation demands; previously a rendered demand remained redeemable indefinitely. Malformed generation requests are rejected before signature validation. Long-open FormEngine forms or module views may need a reload before generating alternative text once the demand has expired.
- Structure-analysis preview tickets are no longer issued for pages where both structure checks are disabled via Page TSconfig (`mod.mindfula11y_accessibility.headingStructure.enable` and `…landmarkStructure.enable`); previously a direct request to the ticket endpoint could obtain a preview capability despite the features being switched off for that part of the page tree. The explicit frontend-cache safeguard for analysis requests also runs again — an inverted check had made it unreachable, though caching was still prevented by the preview aspect.

## [0.12.0] - 2026-07-12

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
- **Accessible server-side form errors.** When a TYPO3 EXT:form submission
  fails server-side validation, the final page title is prefixed with a
  localized `Error:` (`Fehler:` in German) following the GOV.UK validation
  pattern, so assistive technology announces the failure state as the response
  loads. `typo3/cms-form` is an optional dependency; detection is automatic
  with no template, marker or TypoScript integration, and native HTML5
  validation is unaffected. Toggled globally by the
  `enableValidationErrorTitlePrefix` extension configuration (on by default).
- Frontend CI (lint, typecheck, unit tests, build, committed-output
  verification); TER publication is now gated on the same verification of the
  tagged commit.

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

- The form landmark is hidden from content editors by default, alongside the
  other structural landmarks intended to be controlled at template level.
- The **Use header as landmark name** toggle no longer reloads the content
  element form; saving still selects the header or custom landmark name.
- New inline image references are no longer discarded when the decorative-image
  permission guard cannot resolve submitted relation columns. The guard resolves
  the parent file field and rejects only unauthorized decorative-state changes,
  preserving alt text, title, crop and link metadata.
- Landmark analysis no longer treats unnamed forms as landmarks or reports
  matching labels on different landmark roles as duplicates.
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
- Scans pruned by the scanner's retention policy no longer strand the module
  on a loading error: the backend answers 404 for a scan the scanner no
  longer knows, and the module forgets the stored id and creates a fresh
  scan (when auto-create is enabled) instead of showing a permanent error.
- Canceling a scan that finished at the same moment no longer pins a
  "Failed to cancel scan" error over the results — the conflict answer is
  resolved silently by loading the final scan state.
- A single transient poll failure while a scan is running no longer stops
  automatic updates for good: both the scan module and the compact issue-count
  callout now retry on the next interval instead of freezing on a loading
  error until manually refreshed.

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
