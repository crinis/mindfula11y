---
name: css-review
description: >
  Review the mindfula11y shadow-DOM component CSS for the problems a linter
  cannot catch — cascade and cross-file redundancy (properties already set by
  the shared reset/base/tokens/utilities foundation adopted into every shadow
  root, or inherited), intra-file duplication and dead rules, and
  near-duplicate / strongly-repeated patterns that should be centralized
  (declaration clusters, value drift, repeated magic numbers) with concrete
  consolidation moves (`:is()` grouping, shared pattern module in `styles/`,
  component token). Also checks the project's *semantic* conventions that no
  linter can judge: role-based naming, selector order matching the Lit
  template, token-bridge and component-token architecture, attribute-driven
  state, the shared `.notice` pattern for status surfaces, and the WCAG AAA
  contrast recipe. Then runs the project's own linters (Biome + Stylelint) on
  the changed files and folds their output in, instructing fixes. Does NOT
  restate or re-check individual lint rules — the linters are the source of
  truth for everything mechanical. Returns compact one-line-per-finding
  output. Invoke after authoring or modifying stylesheets.
argument-hint: "<paths-to-changed-css...>  (defaults to git-detected changes)"
---

# CSS Review (mindfula11y)

You have two jobs, and they do not overlap:

1. **Judgment the tools can't do.** Cascade / cross-file **redundancy**, **duplication &
   consolidation** opportunities, and the project's **semantic conventions** — naming,
   selector order versus the Lit template, token/component architecture, attribute-driven
   state, AAA contrast recipes. A linter cannot reason about the cascade across adopted
   stylesheets, spot a missing abstraction, or know whether a class name communicates the
   right thing.
2. **Run the installed linters.** Execute the project's own Biome + Stylelint on the changed
   files (§7), and fold their findings into your report so the caller fixes them in one pass.

**Do not hand-check, restate, or argue with anything a linter already enforces** — `@layer`
placement, logical properties, token-prefix-only custom properties, banned color functions
and units, specificity/nesting caps, `:focus-visible`, mobile-first query direction,
container-query units, `var()` fallback depth, z-index values, and so on. The linter is the
source of truth for those; re-deriving them by hand wastes effort and drifts out of date.
**Never name individual lint rules in your report** — when the linter flags something, cite
the linter's own message; when you flag a convention, describe the issue, not a rule id.

You are frequently invoked as a subagent: your final output IS the return value handed back
to the calling agent, so it must be the compact report defined in §8 — no preamble, no
trailing summary, no prose padding.

This skill is **self-contained and independent**: the CSS conventions it reviews against are
written here — in §5 and the §9 reference — and nowhere else. Do **not** read, defer to, or
cite any external project rule file for them. The installed linters (§7) own every
mechanical rule; this skill owns the cascade, duplication, and semantic judgment a linter
cannot make, measured against the conventions below.

---

## 1. Scope & inputs

- **Files under review** = the paths passed as arguments. If none were passed, detect
  changes yourself (run from the extension root, `packages/mindfula11y/`):
  ```bash
  git diff --name-only --diff-filter=d HEAD 2>/dev/null | grep -E '\.css$'
  git status --porcelain 2>/dev/null | grep -E '\.css$'
  ```
  Reviewable CSS lives **only** in `Resources/Private/Source/` (`element/<name>/<name>.css`,
  `styles/*.css`). Everything under `Resources/Public/JavaScript/` is generated build output
  (`.css.js` CSSResult modules) — never review or lint it (§6). Review only the changed
  source files unless the user explicitly asked for a full audit.
- **The conventions you judge against live in this skill** (§5 + the §9 reference). Do not
  read or defer to any external project rule file — judge the reviewed files against the
  rules written here.
- To judge redundancy you MUST **also read** the foundation stylesheets adopted into every
  shadow root (§2) even when they are not under review — you cannot detect cross-file
  duplication without them.
- **Never modify files.** This skill reports findings; the calling agent decides what to
  change. Do not run linters in `--fix`/autofix mode unless the user explicitly asked the
  review to also fix.
- **Never touch or report on legacy files as if they were new code** — see §6.

## 2. Build a cascade-aware context

Every component is a Lit element rendering into its own **shadow root**. Document styles
never reach it; only *inherited* properties (color, font-*, `color-scheme`) and custom
properties cross the boundary. So the full set of CSS applied before a component stylesheet
runs is small and exactly knowable — read it:

- `Resources/Private/Source/styles/reset.css` — per-shadow-root reset (`box-sizing`,
  `* { margin: 0 }`, `img/svg` display+max-inline-size, `button/input/select/textarea
  { font: inherit; color: inherit }`, `ul/ol { padding: 0; list-style: none }`, global
  reduced-motion guard).
- `styles/tokens.css` — the `--mindfula11y-*` ← `--typo3-*` token bridge on `:host`.
- `styles/base.css` — `:host` display/color, core font family/line-height, the project's
  `sm` text size, `:host([hidden])`, the shared `:focus-visible` ring.
- `styles/utilities.css` — single-purpose helpers (`.sr-only`).
- Shared pattern modules in `styles/` (e.g. `styles/notice.css`) adopted by components that
  use the pattern.

Adoption order per component (`static styles = [...baseStyles, <patterns>, componentStyles]`):
layer statement → reset → tokens → base → utilities → pattern modules → component stylesheet.
The fixed layer order is `@layer reset, base, component, utilities` — **`utilities` beats
`component`**; within one layer, later-adopted sheets win at equal specificity. Component
and pattern rules both live in `@layer component`, so a component stylesheet can override a
pattern module (it is adopted later).

For each element/selector touched by the reviewed CSS, model which foundation rules apply to
it (element selectors in reset/base, inheritance from `:host`, adopted patterns).

## 3. Redundancy detection (primary job — not lintable)

Flag a property as redundant when it is one of:

- **Cross-file duplication** — a property+value already set by a foundation/pattern sheet
  affecting the same element (e.g. `list-style: none` / `padding: 0` on a `ul`/`ol`,
  `margin: 0` on anything, `font: inherit` effects on form controls, `color` equal to the
  `:host` text color). **Always cite the exact source file + selector.**
- **Intra-file duplication** — the same property declared twice in one rule, or a duplicated
  selector block.
- **Inherited-value redundancy** — re-declaring an inherited property (`color`,
  `font-family`, `font-size`, `line-height`, …) at the identical value it already inherits
  through the shadow root from `:host` (base.css pins host typography — `font-family:
  inherit` on a button whose `font` is already `inherit` from reset is the classic case).
- **Default-value redundancy** — setting a property to its initial / browser-default value
  with no overriding reason.
- **Shorthand/longhand overlap** — longhands fully covered by a shorthand already in the
  cascade, or vice-versa.
- **Dead / unreachable rules** — selectors entirely overridden by a higher-specificity (or
  later, same-specificity) rule in the same context, including `@layer utilities` beating
  `component`.

For each finding, state the **concrete removal** and a **one-line cascade reason**.

### False-positive guard (critical)

- Only flag **cross-file** redundancy when you can point to the exact file, selector, AND
  property that makes it redundant — and you have confirmed that selector actually matches
  the element inside *that component's* shadow root (a foundation sheet only applies where
  it is adopted; adoption order and layer order decide the winner).
- Remember `font: inherit` (reset) resets the *whole* font shorthand — an explicit
  `font-weight`/`font-size` after it is NOT redundant unless it equals the inherited value.
- When in doubt, downgrade to a NOTE ("possible redundancy, verify cascade") rather than
  asserting a removal.

## 4. Duplication & consolidation (centralization — not lintable)

Distinct from §3: redundancy is a declaration with **no effect**; duplication is CSS that
**does** take effect but **repeats**, signalling a missing abstraction.

**Scope:** compare the reviewed files against *themselves* and the sibling component CSS
under `Resources/Private/Source/element/` (read those siblings). Stay scoped — don't audit
the whole codebase. Respect the legacy guard (§6): you may *note* duplication against legacy
CSS, but the fix always lives in the new code.

Flag these patterns:

- **Exact / near-exact rule duplication** — selectors with identical (or nearly identical)
  declaration blocks. → merge into one grouped / `:is()` selector list *within one file*, or
  — when the repetition spans components — a shared pattern module in `styles/` (a
  `.css` file compiled to a CSSResult and adopted alongside `baseStyles`). Cite all the
  selectors and the shared declarations. `:is()` cannot cross shadow roots; cross-component
  sharing goes through a pattern module, never copy-paste.
- **Repeated declaration clusters** — the same meaningful group of declarations recurring in
  3+ rules (a flex-centering trio; the status-surface recipe of tint background + state
  border + neutral text). → extract a pattern module class or component token.
- **Parallel selector families** — variant sets that each re-declare the full base. → one
  base rule + `data-*`-driven variant overrides that re-point internal custom properties
  (the established pattern: one internal token pair re-pointed per `data-state`).
- **Value drift / near-duplicates (the subtle one)** — rules *meant* to match but differing
  by small, almost-certainly-unintentional amounts: adjacent space-scale steps on elements
  of the same family (`--mindfula11y-space-2xs` vs `-xs` on sibling cards); a rem step next
  to its `-fixed-` px twin on the same kind of edge; two near-identical `color-mix()`
  percentages; inconsistent radii (`--mindfula11y-radius` vs `--mindfula11y-input-radius`
  vs `999em`) on elements of the same family; drifting durations. → propose one canonical
  value.
- **Magic-number repetition** — the same literal (em size, percentage, duration) hardcoded
  in 3+ places. → promote to a component token or pattern-module token.
- **Repeated container-query blocks** — the same selector retuned under the same threshold
  in several spots in one file. → consolidate into one block.

For each finding, name the **consolidation move** and the **canonical value** to keep.

### Judgment / false-positive guard

Not all repetition is a defect — forcing centralization can couple unrelated components.
Only flag when the duplicated cluster is **substantial** (≥3 declarations or a semantically
meaningful group) **and** repeats 2–3+ times, or the drift is **almost certainly
unintentional**. Two *separate* components repeating a couple of layout declarations is
correct, not duplication to merge. Never create a pass-through middleman token. When a
consolidation would couple unrelated concerns, downgrade it to a `NOTES` line. Lead with the
highest-leverage consolidations.

## 5. Semantic conventions (what the linter can't judge)

The linters (§7) own every mechanical rule. This pass checks only the conventions that need
human judgment; the rules are defined once in **§9** — verify the reviewed files against
each, and leave anything machine-checked to the linter:

- **Naming** — role-based (says what an element *is*), no BEM, no scope-mirroring prefixes
  (the shadow root is the scope); names encode visual role, not JS behaviour.
- **Selector order** matches the component template's DOM order (a linter can't see the Lit
  template — you can; read the co-located `<name>.ts`).
- **State via attributes** (`aria-*`/native/`data-*`), not modifier classes.
- **Token / component architecture** — bridge-only `--typo3-*` access; component tokens earn
  their place (3+ uses, theming point, or JS runtime value); parent-overridable tokens
  consumed via `var(--x, default)` and never declared on `:host`; no semantic misuse of
  bridge tokens (§9).
- **Status surfaces** use the shared `.notice` pattern; **AAA contrast** via the
  neutral-on-tint recipe — flag one-off re-derivations or solid state surfaces under text (§9).
- **Component granularity** — one component per `element/<name>/` folder. Flag a stylesheet
  styling several independent widgets. BUT: a whole tree/list hierarchy rendered in ONE
  shadow root (heading tree, landmark schematic) is a deliberate, load-bearing decision —
  nested `ol`/`li` keep native list semantics and `aria-describedby` stays in-root. Never
  propose per-node child components for those.
- **Units & scaling** — each value's unit matches its scaling intent (§9 table); everything
  visible must scale with the module document's root font size (backend modules render in an
  iframe; `rem` resolves against that frame's root).
- **Consolidation honours meaning, not coincidence** (cross-check §4).

## 6. Legacy guard (strict)

- **Legacy** = the flat `.js` files directly in `Resources/Public/JavaScript/`
  (pre-rewrite modules with `css``` blocks, e.g. the alt-text cluster).
- Do **not** flag legacy files as if they were new code, do **not** rewrite them, and never
  use them as a reference pattern for new components.
- **Never run the linters on legacy files or on build output** — the strict config would
  emit a flood of irrelevant violations, and output findings would be fixed in the wrong
  place (the source is under `Resources/Private/Source/`).
- Report legacy files you skipped on a single `NOTES` line: `skipped (legacy): <files>`.
- The redundancy check (§3) does NOT apply against legacy CSS — legacy styles are light-DOM
  and never reach a shadow root. The cascade context for components is exactly the §2 set.

## 7. Linter discovery & execution (mandatory)

Running the project's own linters is not optional — it is half the review. The linters own
every mechanical rule; your manual passes (§3–§5) own everything they can't.

1. The toolchain lives at the **extension root** (`packages/mindfula11y/package.json`); the
   configs are `biome.json` and `stylelint.config.mjs` (which loads the vendored
   `mindfula11y/*` plugins from `Resources/Private/Build/stylelint/`). Verify they exist; if
   the layout changed, re-discover via `find . -name package.json -not -path '*/node_modules/*'`.
2. Run both linters from the extension root, **targeting only the specific changed
   file(s)** — never a directory or glob. Biome first, then Stylelint:
   ```bash
   npx biome check Resources/Private/Source/element/<name>/<name>.css
   npx stylelint Resources/Private/Source/element/<name>/<name>.css
   ```
   Do **not** pass `--fix`/`--write` unless the user asked the review to also fix.
3. **Fold every linter finding into the report** (§8 `LINTER` section) and instruct the
   caller to fix them. Cite the linter's own rule id + message verbatim; do not paraphrase
   into your own rule names.
4. **Skip legacy files and build output entirely** (§6). If the linters are missing
   (`node_modules` absent), say so in `NOTES` (suggest `npm ci`) and rely on the manual
   §3/§4/§5 analysis alone.

## 8. Output contract (compact — this IS your return value)

One line per finding. Omit any empty section. Lead with the highest-impact findings
(redundancies and consolidations first). No prose padding. If there are zero findings, say
so in one line.

```
CSS REVIEW — <files reviewed>

REDUNDANT
- <file>:<line> `<selector> { <prop> }` ⇐ already set by <source-file> `<selector>`. Remove.

DUPLICATION
- <fileA>:<lines>, <fileB>:<lines> N selectors share { <declarations> } — consolidate via :is()/pattern module.
- <fileA>:<line> vs <fileB>:<line> near-duplicate: <prop> <valA> vs <valB> (should match) — align to <canonical>/token.

CONVENTIONS (not lintable)
- <file>:<line> <semantic issue — naming / selector order vs template / state modelling / token architecture / notice pattern / contrast / units> — <fix>

LINTER (<tool> via packages/mindfula11y/package.json)
- <file>:<line> <linter's rule-id> <linter's message>

NOTES
- <skipped (legacy): … / linters unavailable / possible-but-unverified — only when relevant>
```

## 9. Convention reference (the project CSS rules — authoritative for §2–§5)

These are the conventions this skill judges against, self-contained here. The linters own
everything mechanical (`@layer` placement, logical properties, token-prefixed custom
properties, banned color functions/units, specificity ≤ 0,3,0, nesting ≤ 3, no `& ` for
descendants, `:focus-visible`, mobile-first `>=` queries, rem-only thresholds, `var()`
fallback depth ≤ 2, z-index whitelist); this section is only what needs human judgment.

**Rendering model (for §2/§3).** Shadow DOM per component; the §2 foundation is the entire
pre-existing cascade. Layer order `reset, base, component, utilities` — utilities wins over
component. No `@scope` anywhere — the shadow root is the scope. Dark mode is free: every
`--mindfula11y-*` alias resolves through core's `light-dark()` against the inherited
`color-scheme`. Flag any component rule that keys off `prefers-color-scheme` or otherwise
re-implements scheme switching.

**Naming — role-based, no BEM.**
- A class names what an element *is* (`.status`, `.tree`, `.level`, `.issue`), never where
  it lives or how JS uses it. No `__`/`--` separators, no component-name prefixes inside the
  component's own stylesheet.
- Classes are styling-only. JS selects via `data-*`/`aria-*` attributes or Lit refs, never
  by class. Don't add a `.is-busy` class when a `data-*`/native attribute carries the state.
- **Shared pattern classes are the one sanctioned multi-class case:** an element may combine
  a pattern-module class with its role class (`class="notice issue"`). Component-local
  elements otherwise carry exactly one role class.

**State & variants via attributes, not modifier classes.** Native attributes first
(`disabled`, `hidden`, `open`, `aria-expanded`, `aria-selected`, `aria-current`), else
`data-*` (`data-state="warning"`, `data-variant="pill"`, boolean `data-locked`). Style them
with attribute selectors on the base class. Variant blocks re-point the component's internal
custom properties rather than re-declaring the full recipe.

**Selector order matches the template.** Rules appear in the same order as the elements in
the component's Lit template (`element/<name>/<name>.ts`). A linter can't see the template —
you can. Keyframes go last.

**Token / component architecture.**
- `styles/tokens.css` is the ONLY file referencing `--typo3-*` (each alias with a hardcoded
  light-mode fallback) and the only file with raw colors / `light-dark()`. Components
  consume only `--mindfula11y-*` aliases; the one allowed color function in components is
  `color-mix()` over token vars. (All linted — do not re-check; judge *semantic* misuse.)
- Semantic misuse to flag: a **state token** (`--mindfula11y-state-*`) decorating something
  with no severity meaning; a **landmark role accent** (`--mindfula11y-role-*`) signalling
  severity (they are categorical, decorative-only reinforcement — the role name must always
  be printed beside them); an **ON-color** (`--mindfula11y-state-*-color`, core's near-white
  on-colors for its solid state surfaces) used on anything other than that exact solid
  surface — on a tint or transparent background it is a contrast bug.
- Component tokens `--mindfula11y-<component>-*` earn their place: a value used 3+ times, an
  intentional parent-overridable theming point, or a JS-runtime value. A component variable
  that just forwards a global used 1–2× is over-tokenising — reference the global directly.
  Internal tokens are declared on the component's root element or the pattern's base class;
  **parent-overridable tokens are consumed via `var(--x, default)` and never declared on
  `:host`** (a declared default sits closer than any ancestor override and silently blocks
  it). Never a pass-through middleman token.

**Status surfaces — the `.notice` pattern and the AAA recipe.**
- This extension holds its own UI to **WCAG AAA text contrast (≥ 7:1) in both schemes**.
  Core's solid state surfaces (`state-*-bg` under `state-*-color`) cap near 5:1 — text never
  sits directly on a solid state background. The sanctioned recipe is **neutral-on-tint**:
  `color: var(--mindfula11y-text)` on
  `color-mix(in srgb, var(--mindfula11y-state-<x>-bg) 15%, var(--mindfula11y-component-bg))`
  with the saturated `--mindfula11y-state-<x>-border`. 15% is the canonical tint.
- Every notice-like surface (status callouts, inline issues, findings chips, count badges,
  success pills) builds on the shared `.notice` class from `styles/notice.css` —
  `data-state="info|success|warning|danger"` picks the palette by re-pointing the pattern's
  internal tokens, `data-variant` adjusts the shape. The variant-less default is the block
  callout (thick state accent bar) and is rendered only through the `<mindfula11y-notice>`
  element — flag hand-written `class="notice"` without a `data-variant`, any one-off
  re-derivation of the tint recipe, or a hand-rolled severity surface bypassing the pattern.
- Landmark role accents follow the same tint idea at their own documented percentages
  (7% card fill / 30% border mix, 14%/45% for the role pill) — accent percentages are the
  component's own; don't force them to 15%, but flag drift *within* the same family.
- Subtle text is `--mindfula11y-text-subtle` (pre-strengthened in the bridge to clear 7:1).
  Flag any opacity/alpha applied to text, any extra `color-mix` that dilutes a text color,
  and `opacity` < 1 on containers holding text (except transient busy states).

**Units & scaling — decide by scaling intent (there is no automatic default).** Backend
modules render in an iframe; `rem` resolves against that frame's root, `vi` against that
frame's viewport, and everything must survive 200% root font-size and 320px reflow. All
spacing comes from the fluid Utopia scales in `styles/tokens.css` (generated; `s` anchors
core's 1rem rhythm, fluid via `vi` to 1440px): `--mindfula11y-space-{3xs…3xl}` (rem) and
`--mindfula11y-space-fixed-{3xs…3xl}` (px) — same value curve, split ONLY by text-zoom
behaviour. Pick the unit by what the value should track:

| Case | Unit | Why |
|---|---|---|
| Text size, by role | mbase fixed scale: `--mindfula11y-font-size-base` (reading copy) / `-sm` (compact UI, `:host` default) / `-xs` (small supporting text) | exact rem, non-fluid — precise text-only zoom (WCAG 1.4.4); project-owned, deliberately larger than core's 12px; **never `px`** (linted) |
| Headings / titles | `--mindfula11y-font-size-display-{lg,xl}` | fluid Utopia display steps — display text only, never body/UI text |
| Layout gap with text/content on both sides that should scale together | `--mindfula11y-space-*` (rem) | grows with text zoom — keeps the typographic relationship (gaps, padding around text, margins, indent rails) |
| Layout gap that must hold its size — container-edge padding, structural gap between non-text boxes | `--mindfula11y-space-fixed-*` (px) | fixed vs text zoom — a growing gap forces reflow overflow (WCAG 1.4.10) |
| Touch-target minimums, chip geometry | `--mindfula11y-control-padding-block/-inline`, `--mindfula11y-control-min-size` (`em`-valued, resolve at use site) | tracks the control's own label size (≥ 24px targets at any zoom); flag hand-written `0.375em`/`0.75em`/`1.5em` control geometry |
| Hairlines / border widths, accent bars | `px` (or a small `rem`/`em` for deliberate emphasis) | must not drift with zoom |
| Radii | `--mindfula11y-radius` (containers) / `--mindfula11y-input-radius` (controls/chips) / `999em` (pills) | consistent families |
| Container/media thresholds | `rem` | linted |
| Text measure | `max-inline-size: 70ch` on any block of copy | WCAG AAA ≤ 80 chars |

The linter bans raw `px`/`rem` on margin/padding/gap/inset — don't re-check that. Judge the
*intent*: flag a rem step where the gap sits on a container edge (should be the `-fixed-`
twin) and vice-versa a `-fixed-` step flanked by text; a scale step whose size fights its
neighbours (adjacent-step drift, §4); `em` spacing that isn't tracking a control label;
the wrong radius family on a control vs container; text blocks without a measure;
`line-height` below 1.5 for body-size text; any new hand-written `clamp()` outside
tokens.css (fluid values live in the generated scale, nowhere else).

**Accessibility hooks in CSS.** The shared `:focus-visible` ring comes from base.css — flag
a component redefining focus styles without reason. Reduced-motion is globally guarded in
reset.css — flag animations/transitions that bypass it (e.g. an animation re-triggered via
JS that ignores the guard) and any `scroll-behavior: smooth` outside a reduced-motion check.
Never style `[hidden]` back to visible. Decorative color is never the sole conveyor —
severity/role must also be text or an icon in the template.

## Operating principles

- The linter owns mechanical rules; you own redundancy, duplication, and the §5/§9 semantic
  conventions — never duplicate the linter's checks, never skip running it.
- Evidence over assumption: read the actual foundation files and the component template;
  never guess their contents. Be conservative on cross-file redundancy and consolidation
  claims (the §3/§4 guards) — don't force-couple unrelated components.
- Every finding states file, location, the authoritative source, and the concrete fix.
- Ask for clarification only if the CSS structure is genuinely ambiguous and blocks the review.
