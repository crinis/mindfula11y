# Changelog

All notable changes to this extension are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

Releases are cut through the `Release` workflow (Actions → Release): rename
`[Unreleased]` to `[x.y.z] - date`, bump the `ext_emconf.php` version, push,
then dispatch the workflow with the version. It runs the full CI verification
BEFORE creating the `vx.y.z` tag (which Packagist publishes immediately), and
turns this file's version section into the GitHub release notes and the TER
upload comment. Manual `v*` tags are blocked by a repository ruleset.

## [Unreleased]

### Added

- **Interactive label detection** (module feature "Link and Button labels"): a new check flags button labels that are likely too vague to understand out of context — generic action words ("weiter"/"continue"), deictic references ("hier"/"here"), generic phrases ("mehr erfahren"/"learn more"), symbol-only labels (an arrow or other punctuation with no text), the same generic label repeated multiple times on a page, and the same generic label pointing to different destinations. Built-in German and English term lists ship with the extension; findings map to WCAG 2.4.4 (Link Purpose) and/or 2.4.6 (Headings and Labels) depending on applicability. Findings are editable inline (value plus Save, through the same DataHandler-backed flow as other inline-editable checks) and appear with an issue-count badge in the Overview tab and the page-module info panel, consistent with the other accessibility checks. Which table/field combinations are scanned is configured per project via `interactiveLabels.fields` in Page TSconfig; the feature itself is on by default (`interactiveLabels.enable`).
- Interactive labels' vague-term detection can be tuned per project through Page TSconfig: `interactiveLabels.additionalVagueLabels` flags extra project-specific terms alongside the built-in per-locale list, `interactiveLabels.ignoredLabels` exempts built-in (or added) terms, and `interactiveLabels.repeatedLabelThreshold` changes the occurrence count (default `2`) at which a repeated generic label is flagged.
- Interactive labels findings can optionally get an AI second opinion: an "Assess with AI" button asks OpenAI (the same direct integration as alt-text generation — no MindfulAPI involved) whether an already-flagged label is likely understandable given its context, and suggests a better one when it probably is not. Opt-in per click (never automatic), gated behind `interactiveLabels.aiReview.enable` (off by default) and a configured `openAIApiKey`. The response is always shown as an AI opinion (`likely_clear` / `likely_unclear` / `uncertain`, plus a reason) with an explicit disclaimer, never as a WCAG conformance verdict, and a suggested label only fills the input — saving still requires the normal Save action.
- **Headings inside this element** (`tt_content.tx_mindfula11y_childheadingtype`): a container element can set the heading level its children render with, or leave it on the new "Automatic — next level" default. The field ships unassigned — add it to your container CTypes' `showitem` (see the developer documentation). It is editable on the container's own row in the heading-structure module, where changing it re-levels every child at once. `<mindfula11y:heading>` gained `childType`/`childTypeColumnName` to publish the setting, `<mindfula11y:heading.descendant>` gained `relationId` plus child-type arguments so hierarchies compose to any depth, and all heading ViewHelpers gained `renderTag` to suppress output while still anchoring descendants (for example `header_layout` "hidden"). The `div` rendering type still works for templates and existing data but is no longer offered to editors.
- Elements demoted to "Paragraph" and containers without a heading of their own stay listed in the heading-structure module instead of disappearing, so a demotion can be reverted where it was made. Such rows carry a "Not part of the heading structure." note and take part in no heading checks.
- The Missing Alternative Text view's Filter menu can optionally include references marked decorative and references that already have their own alternative text — useful for finding images misclassified as decorative. Both filters are off by default.
- The heading check advises when the heading structure opens deeper than `<h2>` — for example an `<h3>` labelling the navigation before the `<h1>`, with no `<h2>` anywhere before it (minor). Region labels preceding the `<h1>` remain accepted at `<h2>`, the W3C-recommended pattern, and produce no finding. When the deep heading derives from a hidden container, the advisory appears once on the container's row.
- The landmark check detects five more axe-core problems (all moderate): duplicate banner or contentinfo landmarks, and a main, banner or contentinfo landmark nested inside another landmark. Native `<header>`/`<footer>` inside sectioning content carry no landmark role there and are never flagged.
- Scanner HTTP Basic Auth credentials can be configured per site through the site settings `mindfula11y.scan.basicAuth.username` / `mindfula11y.scan.basicAuth.password` in `config/sites/<identifier>/settings.yaml`. The password may use TYPO3's `%env(...)%` syntax, keeping the secret out of committed configuration. Site settings take precedence over the deprecated Page TSconfig keys.

### Changed

- **Requires TYPO3 >= 13.4.18** on the v13 line: earlier 13.4 patch levels silently ignore the `inheritAccessFromModule` backend-route option the extension's AJAX routes rely on as their first access gate.
- Heading and landmark checks analyze the rendered frontend at mobile and desktop sizes, label results by viewport, and ignore elements hidden from assistive technology in each layout. Previews on other site-root domains work through short-lived signed iframe requests — no MindfulAPI setup required. Analysis failures surface as specific localized messages; a page behind HTTP authentication is reported as requiring sign-in and offers an "Open page in new tab" link plus Retry.
- Structure findings are rated on the axe-core impact scale instead of the previous error/warning split: skipped heading levels, a missing `<h1>`, a missing or duplicate main landmark and ambiguous landmark labels are **moderate**; empty headings and multiple `<h1>` headings are **minor**. None of these maps to a WCAG failure, so structure findings no longer use red styling — red is reserved for real failures such as the analysis itself failing.
- The heading-structure module marks findings on the affected row rather than in separate notice rows beneath it, and the findings overview moved into its own tab above the structure. The structure renders as one flat, framed list joined by a dotted tree line, each row announcing and indenting by its own level; rows are mobile-first and every visual cue carries visible or screen-reader text.
- The structure result is reported as a status row in the style of the alternative-text and scan rows of the accessibility info box. In the page module the structure folds away behind that row, so the info box no longer pushes content elements down the page; the unfolded state is remembered for the pages visited next. The Accessibility module's Overview keeps showing the full structure.
- The **Decorative image** toggle (`sys_file_reference.tx_mindfula11y_decorative`) is governed by TYPO3's standard record permissions alone — table rights, page permissions and the field's exclude-field grant — like the adjacent `alternative` and `title` columns. The previous extra requirement of edit access to the parent record and its file field is gone: it was bypassable through those equally powerful columns. Switching the flag **on** additionally needs the `alternative` and `title` exclude-field grants, because that save also empties those fields; without them the save is rejected with an error instead of being half-applied, and the module does not offer the toggle. Switching it **off** needs only the decorative grant.
- The module's "General" feature is renamed to **Overview**; the `feature` URL and module-data value changes from `general` to `overview`. Stored states and bookmarks fall back to the Overview view automatically.
- The extension's frontend middleware identifiers now carry the structure-analysis feature name (`mindfulmarkup/mindfula11y/structure-analysis-authentication`, `…-disable-cache`, `…-disable-admin-panel`). Sites whose own `RequestMiddlewares.php` ordered against the old identifiers must update them.
- The page-title `Error:` prefix after failed server-side EXT:form validation is disabled by default; new installations opt in through the `enableValidationErrorTitlePrefix` extension configuration. Installations that already synced their configuration keep their stored value.
- `<mindfula11y:heading.descendant>` no longer increments an explicitly passed `type` by `levels`. An explicit `type` is used verbatim as the tag name, matching the other heading ViewHelpers and the argument's documented semantics; `levels` still applies to types resolved from an ancestor relation or a database record.
- Heading ViewHelpers with empty content no longer render an empty heading tag (an accessibility defect). The heading relation is still registered, so a headingless container keeps anchoring its descendants' levels; the shipped `Header/All` partial now renders `<mindfula11y:heading>` unconditionally.
- File-reference alternative-text inputs again use TYPO3's native metadata placeholder behavior for every editor allowed to edit the reference field; the extension no longer adds its own `sys_file_metadata` read gate to the inherited placeholder.
- The scan view no longer blocks module rendering on a health check against the external scanner API (up to five seconds when it was down) and drops the "API reachable" message. Unreachability now surfaces in the scan view's own error notices. `ScanApiService::checkStatus()` was removed (internal API).
- Signing of the session-bound demands (scan creation, AI alt-text generation) moved into the new `DemandSignatureService`, and the signing payload is now JSON-encoded. Demands rendered by an older version fail closed at redemption, so editors with a backend tab open across the upgrade reload the module once. Demands also expire after one hour instead of remaining redeemable indefinitely. Third-party code constructing or validating these demands directly must adapt, as must callers of `HeadingRelationRegistry::register()`/`resolve()`, which now exchange `HeadingRelation` value objects instead of `HeadingType` enums (internal API).
- Findings hidden in an inactive tab — the scan view's other mode and the heading/landmark structures — are reachable with the browser's find-in-page search: a match reveals the panel and activates its tab. Browsers without `hidden="until-found"` support keep the previous fully-hidden behavior.
- All accessibility AJAX endpoints report failures in the same localized, structured form, and the declared HTTP methods are now actually enforced for the scan and alt-text endpoints.
- The `tt_content.tx_mindfula11y_headingtype` column is defined as `varchar(10)` instead of the auto-generated `longtext`. Existing installations get this proposed as a regular (safe) database schema update; skipping it changes nothing functionally.
- The backend module scripts assume an evergreen-browser baseline from mid-2024 (roughly Chrome/Edge 117+, Firefox 124+, Safari 17.4+). The scan and structure views no longer run on older engines.

### Deprecated

- The Page TSconfig keys `mod.mindfula11y_accessibility.scan.basicAuthUsername` / `basicAuthPassword`: use the site settings `mindfula11y.scan.basicAuth.username` / `…password` instead (see Added). The TSconfig keys keep working as a fallback but will be removed in a future release; they cannot reference environment variables and apply per page tree rather than per site.

### Fixed

- The Missing Alternative Texts list and count now reflect the editor's workspace. File references with a workspace version disappeared entirely (any draft edit of a content element with images hid its references), alternative text added or removed only in a draft was ignored, and a row could advertise live metadata alternative text as inherited when the draft had cleared it. Metadata drafts no longer bleed into other workspaces.
- Structure-editing and alt-text controls now target the rendered translation and the current workspace draft, and heading types stored on records honour the previewed workspace. Landmark changes no longer update the default-language record from a translated preview, and stale controls for deleted or foreign-workspace records are withheld.
- Heading and landmark editing controls and alternative-text saving now work in offline workspaces; an over-strict permission check previously locked them for every workspace user. Editors switched into a workspace also get the module's features back — table *read* access was wrongly denied for every workspace-capable table. Scans remain limited to the live workspace and are simply not offered elsewhere, instead of failing with a misleading permission error.
- The Missing Alternative Texts query fails closed when the user has no readable table with file fields. It previously lost its entire table and page scope in that case and enumerated image references from every table and page tree of the installation.
- Editing-related module features on page records now require the user's "Page types" (`pagetypes_select`) grant, matching FormEngine. Records locked through their edit-lock field no longer receive structure or alt-text controls, and on TYPO3 v14 backend-layout content-type restrictions are honoured — in all three cases saving was already rejected, so the controls could only fail.
- The accessibility module validates language access against the requested language itself. Requesting a language a page is not translated into previously skipped the check while the queries still filtered by it, so language-restricted editors could list file references of a language they may not access.
- Scans on translated pages work again: loading, cancelling and streaming a report are governed by the default-language page's TSconfig, the page-module card signs its request with the default-language page id plus the language, and a page with no translation in the selected language falls back to the previewed language instead of failing with "page not found".
- `<mindfula11y:landmark>` no longer renders a self-closing tag when its content is empty — `<main />` is not valid HTML, so the browser treated it as an unclosed start tag and nested the rest of the page inside it, visibly breaking the layout.
- `<mindfula11y:landmark>` emits an explicit `role="banner"`/`role="contentinfo"` for the header and footer landmarks. HTML exposes those roles only outside sectioning content, and content elements typically render inside `main` or `section`, so the editor's choice never reached assistive technology.
- `<mindfula11y:landmark>` ignores a `role` that is not a landmark role instead of writing it into the markup, and drops `aria-label`/`aria-labelledby` whenever the rendered element carries no landmark role. The heading ViewHelpers validate an explicit `type` the same way, falling back to `h2`. TYPO3 does not check a select value against its declared items when saving, so a stale record value such as `presentation` could previously remove the element from the accessibility tree.
- Passing an integer (such as `{data.uid}`) as `relationId`, `ancestorId` or `siblingId` to the heading ViewHelpers no longer causes a TypeError on TYPO3 13.
- Structure analysis follows TYPO3's native frontend-preview visibility: hidden content elements and content outside its start/end-time window are excluded, while hidden pages and workspace drafts stay previewable.
- Outstanding AI alt-text, scan-creation and structure-preview authorizations fail closed when their target becomes stale. Moving, translating, editing, replacing or reattaching a target, or changing its preview URL, now requires a reload to obtain a fresh signed request.
- Keyboard focus is no longer lost during asynchronous actions: buttons that generate or save alternative text and trigger or cancel scans stay focusable while the request runs, and structure-module selects stay focused and enabled while a change saves. Screen-reader output was corrected throughout — two announcements in quick succession no longer swallow the first, background polls no longer re-announce a panel that already has content, retry buttons moved out of live regions, the two "View details" links are individually named, and scan-result code excerpts are focusable and named.
- The module survives malformed and hostile input instead of failing with an internal error: malformed scanner payloads, a scan status that is missing or unrecognized, out-of-range or manipulated `currentPage`/`pageLevels` parameters, a non-string `scanId`, and AJAX rejections with an empty value are all handled. `mindfula11y:cleanupscans` now requires a value for `--seconds` and rejects non-positive thresholds — passing the flag without a value previously cleared every stored scan ID.
- A missing or unsynced extension configuration (for example a Composer deployment before `extension:setup` has run) no longer breaks every backend and frontend request; it now reads as "generation disabled".
- The Missing Alternative Texts view shows references from only the selected page by default, adds an explicit **This page** scope option, describes wider choices in terms of included subpages, and no longer fails when the list spans multiple pages (its pagination links targeted a module route that no longer exists). AI generation is withheld for file types the OpenAI vision input cannot consume, such as SVG.
- Composer installation no longer fails on current PHP 8.4 releases: the `php` constraint read `>=8.2 <=8.4`, matching only 8.4.0 exactly. It is now `>=8.2 <8.5`.
- Re-running the "Migrate heading type data" upgrade wizard no longer overwrites heading types migrated or set manually since; it now only fills empty target fields.
- Editors with a reduced-motion preference keep the jump highlight marking the row a findings-overview jump landed on. The highlight is a pure colour fade with no motion, but the global reduced-motion rule finished it instantly, removing the cue for exactly the users who rely on it.
- The accessibility module and the page-module info box no longer flash unstyled notice text while their scripts load; should the scripts fail, the server-rendered content appears after a short timeout instead of staying lost.

### Security

- Error messages from the accessibility scanner are no longer forwarded to editors verbatim. The scanner's explanation is still shown, but any value this installation sent with the request — the scanner API token and the site's Basic Authentication credentials — is redacted and the length capped. A scanner echoing a rejected request back in its error would otherwise have disclosed those credentials to any editor able to trigger the error. The unredacted message is still written to the TYPO3 log.
- The Missing Alternative Texts view no longer escapes the record-type restriction. Tables whose type select declares `authMode` but populates its options only at render time (for example via `itemsProcFunc`) previously contributed no filter at all, and a reference whose parent record was deleted or belongs to another workspace was listed without the restriction the parent carries. The filter now mirrors `checkAuthMode()`, administrators are exempt from it entirely, and orphaned references are gone from the listing and count.
- Marking a file reference decorative no longer empties its alternative text and title when the editor may not set the decorative flag itself. The two halves of that change were judged separately, so the fields were silently wiped while the flag stayed off; the change is now applied whole or not at all, in either direction.
- Alternative-text generation skips images larger than 20 MB instead of base64-encoding them into memory first, only for the OpenAI API to reject them for exceeding its own limit.
- The inline HTML scan report is served with stricter security headers: forms and base-URL changes are blocked, the report cannot be framed, and subresource requests no longer leak the report URL — which carries the access token — through the Referer header.

### Documentation

- The integrator documentation shows how to keep `openAIApiKey` and `scannerApiToken` out of the versioned `config/system/settings.php` using environment variables in `config/system/additional.php`, explains how HTTP Basic Authentication interacts with the structure analysis (browser sign-in) as opposed to the scanner (site-settings credentials), and documents the shipped `aiAudit` defaults.

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
