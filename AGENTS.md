# Mindful A11y — Frontend Agent Guidelines (TypeScript · Lit · CSS)

Conventions for all frontend code in this TYPO3 backend extension. The PHP side follows TYPO3
extension conventions and is not covered here. The reference implementation for everything below
is the vertical slice `Resources/Private/Source/element/scan-issue-count/` (+
`service/scan-service.ts`, `lib/types.ts`, `styles/`).

## 1. Golden Rules

1. **Shadow DOM by default.** Every Lit component renders into its default shadow root.
   `createRenderRoot() { return this; }` is banned except for the two documented exceptions (§3.B).
2. **Every CSS rule lives inside a named `@layer`** (`reset`, `base`, `component`, `utilities`).
   Component styles go in `@layer component`.
3. **Only `--mindfula11y-*` tokens in component CSS.** TYPO3's `--typo3-*` variables are internal
   API and may only be referenced (with a hardcoded fallback) in `styles/tokens.css` — the token
   bridge (§4.B).
4. **No BEM.** Role-based class names (`.status`, `.text`, `.details`); state and variants via
   native/`data-*` attributes, never modifier classes (§4.D).
5. **Classes are styling-only.** JS selects via `data-*`/`aria-*` attributes or Lit refs, never by
   class name.
6. **Custom events are `mindfula11y:<domain>:<action>`** and always dispatched with
   `{ bubbles: true, composed: true }`; every detail payload is typed in `lib/types.ts` (§3.E).
7. **Labels via `lll()`** from `@typo3/core/lit-helper.js` — never read `TYPO3.lang` directly,
   never hand-roll `%d`/`%s` substitution (§3.F).
8. **Relative imports always end in `.js`** (`import { ScanService } from '../service/scan-service.js'`);
   TypeScript resolves them to `.ts`, the browser to the transpiled output.
9. **Never bundle or npm-install runtime copies of `lit`, `@lit/*`, or `@typo3/*`** — the TYPO3
   core importmap provides them. The npm devDependencies exist for types only and `lit` stays
   pinned to the minor version core ships.
10. **Run the linters; don't memorize rules.** `npm run lint` (Biome + Stylelint + tsc) is the
    source of truth. Fix what it reports; never disable a rule inline without a comment saying why.

## 2. Source Layout & Build

Shippable sources live under `Resources/Private/Source/`, build tooling under
`Resources/Private/Build/` (both web-protected in every install mode — never create a top-level
`Build/` directory). Never mix the two: everything in `Source/` is compiled and shipped,
everything in `Build/` is tooling. `npm run build` (or `npm run watch`) transpiles sources 1:1
into `Resources/Public/JavaScript/` — `.ts` → `.js`, `.css` → `.css.js` CSSResult modules; how
and why is documented in `Build/build.mjs`. The output is **committed**: rebuild and commit it
with every source change (CI fails on stale output).

| Path                                        | Contents                                                     |
| ------------------------------------------- | ------------------------------------------------------------ |
| `Resources/Private/Source/element/<name>/`   | One folder per Lit component: `<name>.ts` + co-located `<name>.css` (+ future tests) |
| `Resources/Private/Source/service/`          | Non-component classes: AJAX services, registries, FormEngine wiring |
| `Resources/Private/Source/lib/`              | Shared modules: `types.ts` (domain types + event map)          |
| `Resources/Private/Source/styles/`           | `tokens.css` (token bridge), `reset.css`, `base.css`, `utilities.css`, `base-styles.ts` |
| `Resources/Private/Build/`                   | `build.mjs` (the build script)                                 |
| `Resources/Private/Build/types/`             | Ambient decls: `typo3.d.ts` (@typo3/* modules), `css-modules.d.ts` |
| `Resources/Private/Build/stylelint/`         | Vendored Stylelint plugins (from mbase) + `token-prefix`       |
| `Resources/Private/Build/skills/`            | Agent skills — canonical `css-review` (symlinked into the dev project's `.claude/skills/`) |
| `Resources/Public/JavaScript/`               | Build output (+ legacy flat `.js` files until rewritten)       |

Development files never reach dist artefacts: Composer/Packagist installs are trimmed by the
`export-ignore` list in `.gitattributes`, TER uploads by `.github/ExcludeFromPackaging.php`
(tailor). When adding a root-level tool config or a new dev directory, add it to **both** lists.

**Transition rule:** the flat legacy files in `Resources/Public/JavaScript/*.js` stay untouched
until each is rewritten. New root-level source files must not emit an output name that collides
with a legacy file (this is why shared types live in `lib/`, not at the Source root). When a
legacy module is rewritten, the **same change** must switch every PHP `loadJavaScriptModule()`
call (and Fluid attribute names) to the new module and delete the legacy file — two loaded
modules must never define the same custom-element tag. The build prunes stale output inside the
directories it owns (`element/`, `lib/`, `service/`, `styles/`); the legacy flat files are safe.

New modules need no PHP registration — `Configuration/JavaScriptModules.php` maps the whole
namespace as a directory. Load entry modules from PHP via
`PageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/<name>/<name>.js')` and
place the custom element tag in Fluid.

## 3. Web Components (Lit)

### A. Component skeleton

```ts
// element/my-widget/my-widget.ts
import type { CSSResult, TemplateResult } from 'lit';
import { LitElement, html } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { baseStyles } from '../../styles/base-styles.js';
import componentStyles from './my-widget.css.js';

@customElement('mindfula11y-my-widget')
export class MyWidget extends LitElement {
    static override styles: CSSResult[] = [...baseStyles, componentStyles];

    @property({ attribute: 'record-uid', type: Number }) recordUid: number = 0;
    @state() private busy: boolean = false;

    override render(): TemplateResult { /* … */ }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-my-widget': MyWidget;
    }
}
```

- Tag prefix `mindfula11y-`; one component per folder `element/<name>/<name>.ts` where `<name>`
  is the tag minus the prefix, kebab-case. Component-private helpers may live in the same folder;
  anything shared moves to `lib/`/`service/`.
- TS experimental decorators (`experimentalDecorators: true`, `useDefineForClassFields: false` —
  the combination TYPO3 core and Lit 3 use). `@customElement`/`@property`/`@state` from
  `lit/decorators.js`.
- Multi-word properties get an explicit kebab-case attribute name (`@property({ attribute: 'scan-uri' })`)
  — Lit only lowercases by default.
- **Boolean properties default to `false`** and are enabled by attribute presence (HTML
  boolean-attribute semantics — Lit treats *any* present attribute value, even `"0"` or `""`, as
  true, so a `true` default can never be switched off from markup). In Fluid, render the attribute
  conditionally: `{f:if(condition: autoCreateScan, then: 'auto-create-scan')}`.
- Object/Array properties: Lit's JSON converter yields `null` (not your default) for a
  missing or malformed attribute value — normalize at the point of use (`this.items ?? []`).
- Always register the tag in `HTMLElementTagNameMap`.
- **Elements that are both imported by other components and loaded directly via PageRenderer**
  (for server-rendered Fluid usage, e.g. `element/notice/notice.js`) must NOT use
  `@customElement`: PageRenderer appends a cache-bust query, so the same file evaluates as two
  module instances and the second unguarded `define()` throws — silently killing the whole module
  graph of every importer. Guard instead:
  `if (customElements.get(tag) === undefined) { customElements.define(tag, Class); }`.

### B. Shadow DOM policy

Default shadow root, always. The two sanctioned light-DOM exceptions:

1. **FormEngine wiring** (`service/input-alt-element-service` when rewritten): not a component —
   it augments core-rendered FormEngine markup and must stay in the document tree.
2. A component that must own ARIA relationships with core-rendered markup or receive core
   Bootstrap classes. **Requires a justification comment in the file.** Its styling goes into a
   small *unlayered* stylesheet scoped under the element tag (layered rules would lose to core's
   unlayered `backend.css`).

This is a load-bearing decision, not a preference: core's `backend.css` is unlayered, so the
whole `@layer` architecture (§4) only works inside shadow roots — don't "fix" a component to
light DOM to gain access to backend styles.

What you get for free inside a shadow root (never re-implement):

- **Dark mode** — token aliases resolve through `light-dark()` against the inherited scheme.
  Never query `prefers-color-scheme` or listen to `typo3:color-scheme:update` for styling.
- **Typography** — `base.css` sets `:host` to core's font family/line-height and the
  project-owned mbase text size (`--mindfula11y-font-size-sm`).
- **Focus rings** — `base.css` provides the core-style `:focus-visible` outline.
- **Icons** — render `<typo3-backend-icon identifier="…" size="small">` (or
  `<typo3-backend-spinner>`) directly in templates; side-effect-import their modules.

### C. Async state: `@lit/task`

AJAX-backed rendering uses `Task` from `@lit/task` — no hand-rolled `_isFetching`/`_status` flag
machinery. Render through `task.render({ pending, complete, error })`. For polling, re-run the
task on a timer and clear the timer in `disconnectedCallback()` (see the reference component).

### D. Core module reuse

| Need                | Use                                                    |
| ------------------- | ------------------------------------------------------- |
| AJAX                | `AjaxRequest` from `@typo3/core/ajax/ajax-request.js`    |
| Toasts              | `Notification` from `@typo3/backend/notification.js` (renders in the top document — works from shadow roots) |
| DataHandler ops     | `@typo3/backend/ajax-data-handler.js`                    |
| Labels              | `lll()` from `@typo3/core/lit-helper.js`                 |
| DOM-ready / delegation | `document-service.js` / `regular-event.js` — light-DOM services only; inside Lit templates use `@event` bindings |

Type declarations for these modules live in `Resources/Private/Build/types/typo3.d.ts` — extend
that file (verifying against `vendor/typo3/…`) when using a new core module. Core's `~labels/…`
virtual imports are `@internal` (see `vendor/typo3/cms-backend/Configuration/JavaScriptModules.php`)
— do not use them.

### E. Events

- Names: `mindfula11y:<domain>:<action>` (e.g. `mindfula11y:scan:completed`).
- Always `new CustomEvent(name, { bubbles: true, composed: true, detail })` — `composed` is
  mandatory now that shadow boundaries exist.
- Every event is declared in `Mindfula11yEventMap` (`lib/types.ts`), which augments
  `HTMLElementEventMap` so listeners are fully typed.

### F. Localization & data passing

- PHP loads labels via `PageRenderer->addInlineLanguageLabelArray()` (unchanged); components read
  them exclusively with `lll('mindfula11y.scan.issuesFound', count)` — it handles top-frame
  fallback and `%s`/`%d`/`%f` substitution.
- Per-instance data (URIs, scan demands) is passed as attributes/JSON from Fluid, not fetched.

## 4. CSS

### A. Layers and the shared foundation

Fixed per-shadow-root layer order, declared once in `styles/base-styles.ts`:

```css
@layer reset, base, component, utilities;
```

| Layer       | Owns                                                            |
| ----------- | ---------------------------------------------------------------- |
| `reset`     | Box-sizing, margin zeroing, media defaults (`styles/reset.css`)   |
| `base`      | Token bridge on `:host`, host typography, focus ring, reduced-motion guard |
| `component` | Everything in the component's own stylesheet                      |
| `utilities` | Single-purpose helpers (`.sr-only`)                               |

Every component adopts the foundation: `static override styles = [...baseStyles, componentStyles]`.
Component stylesheets wrap all rules in `@layer component { … }`. `css``/CSSResult` modules are
constructable stylesheets — shared by reference across shadow roots, so this costs nothing.

**Shared pattern modules:** CSS that repeats across components lives in `styles/` as an extra
CSSResult module adopted between the foundation and the component stylesheet
(`[...baseStyles, noticeStyles, componentStyles]` — component rules win within `@layer component`
by adoption order). The element combines the pattern class with its role class
(`class="notice issue"`). `styles/notice.css` is the single implementation of every status
surface (callouts, inline issues, chips, badges): `data-state="info|success|warning|danger"`
picks the palette, `data-variant="inline|pill"` the shape — never re-derive its tint recipe in a
component. The variant-less default is a block callout (thick state accent bar, echoing core's
`.callout`); it is never written by hand — render `<mindfula11y-notice state="…">` instead, which
adds the state icon automatically (replaceable via `slot="icon"`, e.g. with a spinner) and
standardizes slotted action links. It works both inside other components' shadow roots and from
server-rendered Fluid (load `element/notice/notice.js` via PageRenderer there).
`styles/structure-view.css` owns the chrome shared by the two structure views.

### B. Tokens — the `--typo3-*` bridge

TYPO3 declares its backend CSS framework **internal API with no stability guarantee**
(changelog Feature-108240). Therefore:

- `styles/tokens.css` is the **only** file that references `--typo3-*` variables, each aliased to
  a `--mindfula11y-*` name **with a hardcoded light-mode fallback**:
  `--mindfula11y-surface: var(--typo3-surface-base, #f5f5f5);`
- Component CSS consumes only `--mindfula11y-*` aliases (enforced by the
  `mindfula11y/token-prefix` Stylelint rule). A core rename is then a one-file fix.
- Missing an alias? Add it to `tokens.css` (grep `vendor/typo3/cms-backend/Resources/Public/Css/backend.css`
  for the semantic `--typo3-*` token; never use the primitive `--token-color-*` palette).
- `tokens.css` also owns the mindfula11y-authored values that deliberately do NOT track core:
  the landmark role accents, the fluid Utopia space/display scales (§4.F) and the mbase fixed
  text scale (`--mindfula11y-font-size-{base,sm,xs}` = 1.125/1/0.875rem — core's 12px UI text is
  too small for this extension's reading-heavy content). Generated blocks are never hand-edited —
  retune the inputs and regenerate (the command is documented beside each block).
- Raw colors are banned in component CSS (hex/`rgb()`/`oklch()`/`light-dark()`…). The one allowed
  color function is `color-mix()` over token vars (core's own pattern, e.g. the focus ring).

### C. Component-owned tokens

Introduce `--mindfula11y-<component>-*` custom properties only for: (a) a value used 3+ times,
(b) an intentional parent-overridable theming point, or (c) a JS-runtime value.
Internal tokens are declared on the component's root element/`:host`; **parent-overridable tokens
are consumed via `var(--x, default)` and never declared on `:host`** (a declared default would
block ancestor overrides). `var()` fallback depth ≤ 2.

### D. Naming, state, and selectors

- Role-based class names describing what an element **is** (`.status`, `.text`, `.details`).
  No BEM (`__`/`--`), no scope-mirroring prefixes — the shadow root is the scope.
- **State/variants via attributes, never modifier classes:** native attributes first (`disabled`,
  `hidden`, `open`, `aria-expanded`, `aria-current`), else `data-*` (`data-state="warning"`,
  `data-variant="primary"`, `data-size="large"`). Style them with attribute selectors.
- Max specificity `0,3,0`; no ID selectors; no bare type selectors outside `reset.css`/`base.css`;
  use `:where()` to keep shared selectors at zero specificity.
- `:focus-visible` exclusively for focus styling — never bare `:focus`.
- Selector order in a stylesheet follows the component template's DOM order.

### E. Nesting

Native CSS nesting: own declarations first, then nested rules; max depth 3.
**No `&` for descendants** (`.parent { .child { } }`), **`&` required** for pseudo-classes,
pseudo-elements, and same-element attribute/`:has()`/`:not()` selectors (`&:hover`,
`&[data-state="success"]`). Nest `@container`/`@media` inside the selector they modify.

### F. Layout & units

- **Logical properties only** (`inline-size`, `margin-block-start`, `padding-inline`, `inset-*`) —
  physical variants are lint errors.
- `margin-block-start` is the default spacing direction; `gap` for all flex/grid internal spacing.
- **Fluid Utopia scales** (adopted from mbase; generated blocks in `styles/tokens.css`, regenerate
  with mbase's `mbase-tokens` — the inputs are documented next to each block): spacing comes
  exclusively from the t-shirt steps `3xs …3xl` (`s` anchors core's 1rem rhythm, fluid to 18px at
  1440px module width via `vi`); display text sizes from `--mindfula11y-font-size-display-*`.
  Never hand-`calc()` spacing from a base value — Stylelint bans raw `px`/`rem` on
  margin/padding/gap/inset.
- **Unit choice — decide by scaling intent (there is no automatic default).** Pick the unit by
  what the value should track:

  | Case | Unit | Tracks / why |
  |---|---|---|
  | Text size, by role | mbase fixed scale: `--mindfula11y-font-size-base` (reading copy) / `-sm` (compact UI — the `:host` default) / `-xs` (small supporting text) | exact rem, non-fluid — text-only zoom stays precise (WCAG 1.4.4). **Never `px`.** |
  | Headings / titles | `--mindfula11y-font-size-display-{lg,xl}` | fluid Utopia display step — display text only, never body/UI text |
  | Layout gap with text/content on both sides that should scale together | `--mindfula11y-space-*` (rem) | grows with text zoom — keeps the typographic relationship (gaps, padding around text, margins, indent rails) |
  | Layout gap that must hold its size — container-edge padding, structural gap between non-text boxes | `--mindfula11y-space-fixed-*` (px) | fixed vs text zoom — a growing gap forces reflow overflow (WCAG 1.4.10); still viewport-fluid |
  | Touch-target padding & control minima (chips, tabs, buttons) | `--mindfula11y-control-padding-block/-inline`, `--mindfula11y-control-min-size` (`em`-valued) | tracks the control's own label — ≥ 24 px targets at every zoom (WCAG 2.5.x); `em` resolves at the use site |
  | Text measure (`max-inline-size`) | `ch` (≤ `70ch`) | characters per line (WCAG 1.4.8) |
  | Hairlines / border widths | `px` | must not drift with zoom |
  | Media/container thresholds | `rem` | root preference. **Never `px`/`em`.** |

  Don't default to `rem` for margins/paddings — name the scaling intent first, then pick. The two
  space scales share one value curve and differ ONLY in text-zoom behaviour; mixing them on the
  same edge (rem gap next to a fixed gap of the same step) is drift, not variety.
- **Mobile-first only**: ascending thresholds (`width >= 30rem`), never `max-width`.
- Component responsiveness uses `@container` queries (thresholds in `rem`, never `em`); `@media`
  is reserved for preference queries (`prefers-reduced-motion`, `prefers-contrast`). Note `:host`
  cannot query itself — put `container-type` on an inner wrapper.
- No `transition: all`; `z-index` only from tokens or `-1`/`0`/`1`.

## 5. HTML & Accessibility

This is an accessibility extension — its own UI is held to the standard it audits.

- Semantic elements first: `button` for actions, `a` for navigation, real `ul`/`ol` for lists,
  `fieldset`+`legend` for grouped inputs.
- **ARIA relationships never cross a shadow boundary.** `aria-labelledby`/`aria-describedby`/`for`
  must reference nodes in the same shadow root — keep label + control + description together.
  Relationships among slotted (light-DOM) children are fine.
- **Live regions are pre-rendered:** render the `role="status"`/`aria-live` container in the first
  render and only swap its content (announcements are unreliable when the region itself is
  inserted). See the reference component.
- `delegatesFocus` stays off; enable per component only with a comment explaining why.
- Interactive targets ≥ 24×24 CSS px (44×44 for primary touch actions) — use `em` padding.
- Respect `prefers-reduced-motion` (globally handled in `reset.css`; don't add animations that
  bypass it).
- `.sr-only` (from `utilities`) for visually-hidden-but-announced text.
- UI must survive 200 % root font-size and 320 px-wide reflow without loss of function. Note that
  backend modules render in an iframe with its own document root — `rem` resolves against *that*
  root, so test scaling by changing the module document's root font size (or the browser's font
  preference / zoom, which reach every frame), not the top document's.
- **Text contrast ≥ 7:1 (WCAG AAA) in both schemes.** Never put text directly on core's solid
  state surfaces (`--typo3-state-*-bg` + `--typo3-state-*-color` cap near 5:1; the ON-colors are
  deliberately not bridged) — status surfaces build on the shared `.notice` pattern
  (`styles/notice.css`), which implements the neutral-on-tint recipe once:
  `color: var(--mindfula11y-text)` on
  `color-mix(in srgb, var(--mindfula11y-state-*-bg) 15%, var(--mindfula11y-component-bg))` with the
  saturated `--mindfula11y-state-*-border`. `--mindfula11y-text-subtle` is already strengthened in
  the token bridge to clear 7:1; don't reduce it or apply extra opacity to text.
- Blocks of text stay ≤ 80 characters per line (`max-inline-size: 70ch`), line-height ≥ 1.5 (the
  token default), never justified.
- Everything keyboard-operable; visible focus via the shared `:focus-visible` ring.

## 6. Tooling & Definition of Done

`npm run lint` runs Biome, Stylelint (with the vendored plugins), and `tsc --noEmit`; `npm run
lint:fix` auto-fixes; a pre-commit hook formats staged files. The configs (`biome.json`,
`stylelint.config.mjs`, `tsconfig.json`) are the source of truth — don't weaken tsconfig
strictness, remove lint rules, or add per-file overrides to make a finding go away.

**Changelog (applies to PHP changes too, despite this file's frontend scope):** every
user-facing change — new features, behavior changes, bug fixes, removals/renames, requirement
changes — gets an entry in the `[Unreleased]` section of `CHANGELOG.md` **in the same change
set**, under the matching Keep a Changelog category (`Added`/`Changed`/`Fixed`/`Removed`/
`Documentation`). Write for editors and integrators, not as a commit log: name the observable
behavior and the option that controls it (TSconfig path, TCA field, ViewHelper argument), and
give a migration hint for anything removed or renamed. Purely internal work (refactors without
behavior change, build tooling, CI, dev-only assets) needs no entry — when unsure, add one.
The section becomes the GitHub release notes and the TER upload comment at release time, so
every entry must read correctly in that context.

**Definition of Done for any frontend change:**

1. `npm run lint` passes (includes typecheck).
2. `npm run build` ran and the updated `Resources/Public/JavaScript` output is committed.
3. New `--typo3-*` needs went through `styles/tokens.css` with fallbacks.
4. New events are typed in `Mindfula11yEventMap`; new `@typo3/*` imports are declared in
   `types/typo3.d.ts`.
5. Component verified in the TYPO3 14 backend in **both color schemes** (User Settings →
   Appearance, or set `data-color-scheme` on the document element) and keyboard-navigated once.
6. No new light-DOM component without a justification comment; no name collision with the
   remaining legacy flat files in `Resources/Public/JavaScript/`.
7. Stylesheet changes were reviewed with the `css-review` skill
   (`Resources/Private/Build/skills/css-review/`) and its findings addressed.
8. User-facing changes have a `CHANGELOG.md` entry under `[Unreleased]` (see the changelog
   note above).
