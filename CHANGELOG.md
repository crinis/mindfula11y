# Changelog

All notable changes to this extension are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

When cutting a release, copy the relevant section into the GitHub Release notes —
the `Publish to TER` workflow uses the release body as the TER upload comment.

## [Unreleased]

### Added

- New optional tt_content field **Headings inside this element** (`tx_mindfula11y_childheadingtype`): a container element can explicitly set the heading level its child elements render with — the same `h1`–`h6` and paragraph choices as the regular heading-level field — or leave it on the additional "Automatic — next level" default. The field ships unassigned; integrators add it to their container CTypes' showitem (required for both FormEngine and heading-structure-module editing, see the developer documentation). The `div` rendering type remains supported for templates, existing data, and integrator-added TCA options, but is no longer offered to editors by default.
- `<mindfula11y:heading>` gained `childType`/`childTypeColumnName` arguments to publish the configured child heading type with its relation, and a `renderTag` argument (all heading ViewHelpers) to suppress output while still registering the relation — for example for `header_layout` "hidden". When `childType` is omitted, the record column is only consulted (and only offered for module editing) if it is defined in the record table's TCA — existing custom-table integrations without the tt_content-specific column keep rendering unchanged.
- `<mindfula11y:heading.descendant>` gained `relationId` and child-type arguments: descendants can now register themselves so nested descendants derive one further level automatically, composing hierarchies of any depth.
- The headings-inside setting is edited on the container element's own row in the heading-structure module: the select shows the container field's stored setting (with the effective level, e.g. "Automatic — next level: Level 3 (H3)"), and changing it re-levels all of the container's children (and their linked descendants) with one change. A container that renders no heading of its own (empty header, `renderTag="false"`) still appears as a "Hidden container element" row so the field stays reachable. Derived child headings are read-only and link to their container's row via a link-icon jump button instead. Skipped heading levels caused by such a hidden container are reported once on the container's row — the row already shows the unrendered level and the select that fixes the gap — instead of as missing-level placeholders under every affected child.

### Changed

- File-reference alternative-text inputs again use TYPO3's native metadata
  placeholder behavior for every editor allowed to edit the reference field.
  The extension no longer adds a separate `sys_file_metadata` table-read gate
  to the inherited placeholder value.
- Heading ViewHelpers with empty content no longer render an empty heading tag (an accessibility defect); the heading relation is still registered, so a headingless container keeps anchoring its descendants' levels. The shipped `Header/All` partial now renders `<mindfula11y:heading>` unconditionally and relies on this suppression.
- `HeadingRelationRegistry::register()`/`resolve()` now exchange `HeadingRelation` value objects instead of `HeadingType` enums (internal API; third-party code calling the registry directly must adapt).
- The heading-structure module marks findings directly on the affected row — an inline severity chip plus a colored row border — instead of separate full-width notice rows beneath (e.g. every duplicate `<h1>` row carries its "Multiple <h1> headings found" warning itself). Missing-level placeholder rows remain the signal for a skipped level without an editable container row; the skipping heading's select announces the placeholder's message. Mobile-first row cards group the level and title, keep the level value and its relation/lock icon on one line while letting the title wrap as a readable block, give the headings-inside selector its own labelled field group, place status and actions consistently, and reduce deep indentation on narrow screens before progressively restoring it at wider widths. Every visual cue (viewport badges, save spinner, severity colors) carries visible or screen-reader text. The heading structure renders as a single flat list — each row announces its own heading level and is visually indented by it (containers indent one step above their children) — instead of nested lists whose depth could contradict the announced levels around skips and containers. Editable options and read-only badges use the same labels: "Level 1 (H1) — page heading", "Level 2 (H2)" through "Level 6 (H6)", and "Paragraph — not a heading".

- Heading and landmark checks now analyze the rendered frontend at mobile and desktop sizes, ignore elements hidden from assistive technology in each layout, and label results by viewport. Same-labelled navigation landmarks with identical link sets are accepted; ambiguous same-labelled landmarks are warnings instead of automatic errors. Frontend previews on other site-root domains are supported through short-lived signed iframe analysis requests; no MindfulAPI setup is required. Only the isolated analysis runner executes, so JavaScript-generated structure is not included. The signed preview requests expire after 15 seconds, revalidate the editor's current access on every use, and fail closed on redirects, non-HTML responses, or manipulated analysis results; where both structure checks are disabled via Page TSconfig, no preview requests are issued and no structure-editing metadata is served for records on such pages. Analysis failures surface as failure-specific localized error messages in the module.
- `<mindfula11y:heading.descendant>` no longer increments an explicitly passed `type` argument by `levels`. An explicit `type` is now used verbatim as the tag name, matching `<mindfula11y:heading>`, `<mindfula11y:heading.sibling>`, and the argument's documented "rendered directly" semantics; `levels` still applies to heading types resolved from an ancestor relation or a database record.

- The module's "General" feature is renamed to **Overview** (menu label, and the `feature` URL/module-data value changes from `general` to `overview`). Stored module states and bookmarked URLs using the old value fall back to the Overview view automatically; only direct links that named `feature=general` explicitly will land on the (identical) default view.
- The extension's frontend middleware identifiers are renamed to carry the structure-analysis feature name (`mindfulmarkup/mindfula11y/structure-analysis-authentication`, `…-disable-cache`, `…-disable-admin-panel`). Sites whose own RequestMiddlewares.php ordered against the old identifiers must update them.
- Signing and validation of the session-bound demands (scan creation, AI alt-text generation) moved from the demand models into the new `DemandSignatureService` (internal API): the models are pure value objects now, `validateSignature()` and their `JsonSerializable` implementation are gone, and markup must serialize demands through the service. The signing payload is now JSON-encoded (immune to delimiter ambiguity between adjacent scope fields) — a signing-context change: demands rendered by an older extension version fail closed at redemption, so editors with a backend tab open across the upgrade reload the module once. Third-party code that constructed or validated these demands directly must adapt.
- The scan view no longer performs a blocking health check against the external scanner API on every module render (up to five seconds when the API was down) and no longer shows the "API reachable" confirmation message. Unreachability now surfaces through the scan view's own error notices when a scan is created or loaded; `ScanApiService::checkStatus()` was removed (internal API).
- The extension now requires TYPO3 >= 13.4.18 on the v13 line: earlier 13.4 patch levels silently ignore the `inheritAccessFromModule` backend-route option the extension's AJAX routes rely on as their first access gate (every handler additionally enforces module access itself).
- The **Decorative image** toggle (`sys_file_reference.tx_mindfula11y_decorative`) is now governed by TYPO3's standard record permissions alone — reference-table rights, the reference's page permissions, and the field's exclude-field grant — exactly like the adjacent `alternative` and `title` columns. The previous additional server-side requirement of edit access to the parent record and its file field is removed: it was bypassable through those equally powerful core fields and made permission behavior differ between the module and other DataHandler callers. The module UI still only offers the toggle where the parent is editable, and decorative references keep their invariant: alternative text and title are always stored empty (now also correctly following the workspace draft's decorative state, see Fixed).
- All accessibility AJAX endpoints now report failures in the same localized, structured form (an error title with a description), so the module surfaces translated messages for every endpoint. Allowed HTTP methods are now enforced for the scan and alt-text endpoints (they were previously declared but not enforced).

### Fixed

- Structure-analysis editing controls now target the rendered translation and current workspace draft. Landmark changes no longer update the default-language record from a translated preview, draft-specific content types and select options are respected, and stale controls for deleted or foreign-workspace records are withheld.
- Structure analysis now follows TYPO3's native frontend-preview visibility: hidden content elements and content outside its start/end-time window are excluded, while hidden pages and workspace drafts remain previewable. Editors no longer receive findings for content they deliberately keep disabled instead of deleting.
- Outstanding AI alt-text, scan-creation, and structure-preview authorizations now fail closed when their issued target becomes stale. Each authorization pins the complete persisted workspace/language record revision; alt-text demands additionally pin the exact file and file-reference revisions. Moving, translating, editing, replacing or reattaching a target, changing its preview URL, or disabling both structure features requires the editor to reload and obtain a fresh signed request.
- Two screen-reader announcements in quick succession (for example a save confirmation followed by a completed re-analysis) no longer swallow the first message: announcements are now serialized through the live region instead of interleaving their clear/write renders.
- Failed AI alt-text generations are now logged (warning level, with the model name), so silently returning no text is diagnosable; the scan report endpoint additionally answers with a clean "no scan id" error instead of an internal error when the scanId parameter is not a string, and the streamed report carries `Cache-Control: private, no-store`.
- The scan view now validates every part of the scan result it receives (violations, progress counters, AI-audit summary, agent findings) and shows its error notice when the payload is malformed. A malformed response from the external scanner previously crashed the view with an internal error instead of the error message.
- Failed AJAX requests that reject with an empty value no longer lose their original error: the error converter accessed a property on the rejection value unguarded, replacing the real failure with its own internal TypeError in every error notice.
- `<mindfula11y:landmark>` now emits an explicit `role` attribute for the banner and contentinfo landmarks (`<header role="banner">`, `<footer role="contentinfo">`). Without it, HTML exposes these roles only when the element is not nested inside sectioning content — content elements typically render inside `main` or `section`, so the editor-selected landmark never reached assistive technology. Other roles keep their attribute-less native elements.
- The structure module no longer briefly shows the results of a superseded analysis: when a save triggers a re-analysis, a still-running previous analysis could finish afterwards and overwrite the fresh result until the new run completed.
- Editors switched into a workspace get the accessibility module's features back. Table read access was denied for every workspace-capable table (pages, content, file references) while in a workspace without live editing, so the Missing Alternative Texts and scan features disappeared for non-admin workspace users; reading a table never requires the workspace live-edit permission — only writes do, and their check was already correct.
- The Missing Alternative Texts query fails closed when the user has no readable table with file fields: it now returns an empty list and count. Previously the query lost its entire table/page scope in that case and enumerated image references from every table and page tree of the installation (limited only by the user's file mounts) — affecting editors granted the module with file-table permissions only.
- Editing-related module features on page records now also require the user's "Page types" (`pagetypes_select`) grant for the page's type, matching what FormEngine enforces when opening the page. Previously an editor restricted to certain page types could still, for example, trigger accessibility scans (and the scan-state write) for pages of a type they may not edit.
- Saving alternative text on a file reference whose workspace draft is marked decorative now keeps the draft's alternative and title empty. The blanking previously read the live row's decorative state, so a workspace-only decorative flag did not protect the draft from re-acquiring alternative text.
- The Missing Alternative Texts list and count now show the editor's workspace state. File references that have a workspace version disappeared from the module entirely (any draft edit of a content element with images hid its references), and alternative text added or removed only in the draft was ignored — the alternative-text and decorative filters now evaluate the workspace version of each reference instead of its live row.
- A missing or unsynced extension configuration (for example a Composer deployment where `extension:setup` has not run yet) no longer breaks every backend and frontend request: registering the AI alt-text generation button during TCA loading read the configuration unguarded and threw; it now treats a missing configuration as "generation disabled".
- Changing a heading level, child heading type, or landmark role in the structure module no longer moves keyboard focus away from the select while the change is saved. The select was disabled during the save, which blurred it — keyboard and screen-reader users lost their position for the whole save window and, when the save failed, were never returned to it. The select now stays focused and enabled throughout (repeated changes while a save is in flight are ignored and reverted).
- Passing an integer value (such as `{data.uid}`) as `relationId`, `ancestorId`, or `siblingId` to the heading ViewHelpers no longer causes a TypeError on TYPO3 13 — Fluid v2 hands integer variable values to string-typed arguments uncast, while Fluid v4 (TYPO3 14) casts them; the ViewHelpers now cast at the registry boundary on both versions.
- Loading, canceling, and streaming the report of a scan stored on a translated page are now governed by the same Page TSconfig as the default-language page. The `scan.enable` gate previously evaluated TSconfig via the translation record's uid, whose rootline skips the default-language page's own TSconfig column — so a `scan.enable = 0` set on the page itself did not restrict scans stored on its translations.
- Triggering a scan from the page-module overview card now works for translated pages. The card signed its scan request with the translated record's uid while scan creation expects the default-language page id plus the language, so the request failed with "page not found". All scan surfaces now issue their requests through one shared factory, which also keeps the authorization rules for offering scan controls identical everywhere.
- Triggering a scan on a page that has no translation in the module's selected language (for example when the language selection persisted from another page) no longer fails with "page not found". The scan preview already fell back to the default-language page, but the scan request was still signed with the untranslated selected language; the request now targets the language of the page actually previewed.
- Re-running the "Migrate heading type data" upgrade wizard no longer overwrites heading types that were already migrated or set manually since; the wizard now only fills empty target fields, matching its own necessity check.
- The `mindfula11y:cleanupscans` command now requires a value for `--seconds` and rejects non-positive thresholds. Previously, passing the flag without a value silently treated every stored scan ID as old and cleared them all.
- The Missing Alternative Texts view no longer fails on a manipulated `currentPage`/`pageLevels` URL parameter — values outside the offered ranges fall back to their defaults.
- The inline HTML scan report is served with stricter security headers: forms and base-URL changes inside the report are blocked, the report cannot be framed, and subresource requests from within it no longer expose the report URL (which carries the access token) via the Referer header.
- Structure-editing and alt-text controls are no longer offered for records locked via their edit-lock field (e.g. a locked content element on an unlocked page); saving such records was already rejected by TYPO3, so the controls could only fail. Page-level edit locks were already honoured.
- Heading types stored on records (the `recordUid`/`recordColumnName` arguments of `<mindfula11y:heading>`, `<mindfula11y:heading.sibling>` and `<mindfula11y:heading.descendant>`) now honour the current workspace when a structure-analysis preview renders inside a workspace. Previously the live heading type was always read, so an editor previewing a workspace could see the published tag instead of their draft change; the record is now overlaid with its workspace version. Empty results are cached per request so unannotated records no longer re-query for every heading on the page.
- A scan-creation or scan-status response with a missing or unrecognized status now surfaces as a loading error instead of silently being treated as a completed scan with no issues.
- Asynchronous structure, scan, and alternative-text errors and save confirmations are now announced from their visible status messages. Non-visible live announcements remain limited to generated summaries and background lifecycle updates, avoiding duplicate screen-reader output.
- Scan-creation and AI alt-text generation demands now expire after one hour instead of remaining redeemable indefinitely, and malformed generation requests are rejected before signature validation. Long-open module views or FormEngine forms may need a reload before triggering a scan or generating alternative text once the demand has expired.
- The scan module's tab panels no longer mark themselves `aria-busy` on every background poll once a result is showing — only an explicit action (trigger/cancel/refresh) or the panel's first load is announced as busy, so assistive technology stops re-announcing a panel that already has content.
- All three heading ViewHelpers (`<mindfula11y:heading>`, `<mindfula11y:heading.sibling>`, `<mindfula11y:heading.descendant>`) now validate an explicit `type` argument against the allowed heading types instead of passing an unrecognized value straight through to the rendered tag name. A `type` value outside `h1`-`h6`/`p`/`div` now falls back to the default `h2` tag instead of rendering an arbitrary, unintended tag.
- Canceling a scan now clears a stale error notice left over from a previous action instead of leaving it pinned over the reloaded result.
- The accessibility module now validates language access against the requested language itself. Previously, requesting a language the page was not translated into skipped the access check (it collapsed to the default language) while the missing-alt-text queries still filtered by the requested language, so language-restricted editors could list file references of a language they have no access to, and the language selector could disagree with the displayed content.
- The scan card in the overview and the page-module panel no longer offer automatic scan creation to users without edit access to the page; previously the attempt failed with a permission error.
- Heading and landmark editing controls (structure analysis) and alternative-text editing now work in offline workspaces: writes create workspace versions exactly like FormEngine edits. Previously an over-strict permission check locked all structure controls and denied alt-text saves for users working in a workspace. Triggering accessibility scans is now explicitly limited to the live workspace (the external scanner cannot fetch workspace previews, and the stored scan id must not create a workspace version of the page); workspace users previously received a misleading permission error instead of the controls simply not being offered.
- On TYPO3 v14, structure-editing metadata now honours backend-layout content-type restrictions (`allowedContentTypes`/`disallowedContentTypes` on backend layout columns): heading and landmark selectors are no longer offered for content elements whose type is not allowed in their column, where saving would have been silently rejected by TYPO3.
- Composer installation no longer fails on current PHP 8.4 releases: the `php` version constraint read `>=8.2 <=8.4`, which only matched PHP 8.4.0 exactly and rejected every 8.4 patch release. It is now `>=8.2 <8.5`.

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
