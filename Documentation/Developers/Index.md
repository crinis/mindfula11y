# Developers

This guide focuses on implementing Mindful A11y features in templates and custom record types.

## Server-side validation-error titles

Mindful A11y automatically detects failed TYPO3 EXT:form validation and prefixes the final page
title with a localized `Error:`. EXT:form is optional and no template integration is required.

Detection uses EXT:form's post-validation rendering lifecycle: the `beforeRendering` hook on
TYPO3 13 and `BeforeRenderableIsRenderedEvent` on TYPO3 14. A frontend middleware applies the
prefix to the completed response because TYPO3 13 renders uncached USER_INT form errors after the
cached page shell and title have already been generated. This also means a title provider called
from a form template is not a cross-version solution.

The behavior is controlled globally by `enableValidationErrorTitlePrefix` in Extension
Configuration and is enabled by default. Client-side HTML5 validation needs no handling because it
does not cause a page load.

## Fluid namespace

```html
<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">
```

## Decorative file references and native image ViewHelpers

The **Decorative image** checkbox is stored per `sys_file_reference`. When enabled, Mindful A11y
stores explicit empty reference alternatives and titles so TYPO3 does not fall back to file
metadata. Native image rendering therefore needs no custom ViewHelper:

```html
<f:image image="{fileReference}" />
<f:media file="{fileReference}" />
```

`f:image` and the image fallback in `f:media` both render `alt=""` for the decorative reference.
They do not emit a metadata-derived `title` attribute. The reference description remains available
to templates as an optional visible caption.
Do not pass a non-empty explicit `alt` argument when the template should honor the editor's choice;
explicit `alt` and `title` arguments retain TYPO3's normal precedence and override reference data.

## Heading ViewHelpers

Every element has a *logical* heading level, whether or not a heading is visible: render
`<mindfula11y:heading>` unconditionally in templates. When its content is empty or
`renderTag="false"` (e.g. `header_layout` 100 "hidden"), it outputs nothing — an empty
heading tag is an accessibility defect — but still registers its relation, so descendant
and sibling headings keep deriving their levels from it.

### 1) Main heading: `<mindfula11y:heading>`

Use this for the primary heading output of a record.

```html
<mindfula11y:heading
    recordUid="{f:if(condition: data._LOCALIZED_UID, then: data._LOCALIZED_UID, else: data.uid)}"
    type="{data.tx_mindfula11y_headingtype}"
    childType="{data.tx_mindfula11y_childheadingtype}"
    relationId="{data.uid}"
    renderTag="{f:if(condition: '{data.header_layout} == 100', then: '0', else: '1')}">
    {data.header}
</mindfula11y:heading>
```

Edge cases:

- If `type` is set, that value is rendered directly.
- If `type` is not set, the ViewHelper resolves the heading type from the configured record field.
- If neither is available, it falls back to `h2`.
- Use `relationId` if you want to reference this heading from descendant/sibling headings.
- `childType` explicitly configures the level of descendant headings (see below). Pass it from
  template data as shown to save a database query; when the argument is omitted entirely, the
  record's `tx_mindfula11y_childheadingtype` column (override with `childTypeColumnName`) is
  consulted — but only when that column is defined in the record table's TCA. Custom tables
  without the column simply resolve to "automatic"; they need no child-type column of their own.
  An empty value means "automatic": descendants use this heading's own level plus one.
  In the heading structure module, the child heading type is edited on the **container
  element's row**. A container that renders no heading of its own (empty header,
  `renderTag="false"`) still appears as a "Hidden container element" row during analysis —
  the ViewHelper emits a hidden marker for validated structure-analysis requests only; normal
  frontend output is unchanged. Derived child headings are read-only in the module and link to
  their container's row.
- **Translated content:** `recordUid` must be the *localized* record's uid, otherwise editing
  targets the default-language record. With the classic `data` array (above) that is
  `data._LOCALIZED_UID` falling back to `data.uid`; in the TYPO3 14 `record` / `PAGEVIEW`
  pipeline use `{record.computedProperties.localizedUid}` instead, because `data._LOCALIZED_UID`
  is not populated there. Resolve it with `f:if` as shown — do **not** use the
  `{data._LOCALIZED_UID ?: data.uid}` shorthand: Fluid's ternary returns the literal text
  `data._LOCALIZED_UID` when the variable is undefined (every default-language record), so it
  silently breaks the fallback.

Static heading (no record context):

```html
<mindfula11y:heading type="h2">Section title</mindfula11y:heading>
```

### 2) Descendant heading: `<mindfula11y:heading.descendant>`

Use this when a heading level should be derived from a previously rendered ancestor.

```html
<mindfula11y:heading relationId="mainHeading" type="h2">
    Main heading
</mindfula11y:heading>

<mindfula11y:heading.descendant ancestorId="mainHeading" levels="1">
    Child heading
</mindfula11y:heading.descendant>
```

Edge cases:

- `ancestorId` only resolves when the referenced heading is rendered earlier in output.
- If the referenced heading is not available yet, set `type` directly or provide record arguments.
- If level increment would exceed `h6`, output becomes `p`.
- When the ancestor carries a configured **child heading type** (its `childType` argument or
  `tx_mindfula11y_childheadingtype` column), the descendant uses that level *verbatim* with the
  default `levels` of 1 — this is the only way a descendant can render as `h1`, since plain
  incrementing always starts at `h2`. Deeper `levels` continue from it
  (`childType + levels − 1`).
- Give a descendant its own `relationId` to let *its* descendants derive from it — each nesting
  level steps down one further level automatically:

```html
<mindfula11y:heading.descendant ancestorId="container" relationId="child">
    Child heading
</mindfula11y:heading.descendant>

<mindfula11y:heading.descendant ancestorId="child">
    Grandchild heading, one level deeper
</mindfula11y:heading.descendant>
```

### Container elements: configuring child heading levels

The optional tt_content column `tx_mindfula11y_childheadingtype` lets editors set the heading
level for all children of a container element (including `h1`), or leave it on "Automatic"
(one level below the container's own heading). The column ships **unassigned** — add it to your
container CTypes:

```php
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    'tx_mindfula11y_childheadingtype',
    'my_container_ctype',
    'after:header'
);
```

The field **must be part of the CType's showitem** (as above): FormEngine only shows fields from
showitem, and the heading-structure module's enrichment uses the same processed TCA — without it,
the container's row offers no child-type select in the module either.

A container template renders its heading unconditionally with `relationId` and `childType`
(see the main-heading example). Child elements reference it via `ancestorId`; with
`b13/container`, children carry the container uid in `tx_container_parent`:

```html
<mindfula11y:heading.descendant
    ancestorId="{data.tx_container_parent}"
    relationId="{data.uid}">
    {data.header}
</mindfula11y:heading.descendant>
```

Because the container registers even when it renders no heading (empty header or
`renderTag="false"`), a headingless container still anchors its children: with a configured
child type they render exactly that level; on "Automatic" a headingless container registers no
own level either, and children fall back to their own heading-type field.

In the heading-structure backend module, the child-type select lives on the **container's own
row**, not on the derived children: one change writes the shared column, so **all** children of
that container shift together, and their own descendants (linked via `relationId`/"Automatic")
re-derive with them. A container that renders no heading of its own still gets a row — labeled
"Hidden container element" — so the field stays reachable and its children keep a jump target;
this row is a module-only construct built from the hidden marker `renderContainerMarker()` emits
for validated structure-analysis requests and never appears in normal frontend output. Derived
child rows are always read-only in the module; each links back to its container's row instead.

### 3) Sibling heading: `<mindfula11y:heading.sibling>`

Use this when two headings should share the same semantic level.

```html
<mindfula11y:heading relationId="mainHeading" type="h3">
    First heading
</mindfula11y:heading>

<mindfula11y:heading.sibling siblingId="mainHeading">
    Second heading on same level
</mindfula11y:heading.sibling>
```

Edge cases:

- `siblingId` works only if the referenced heading is rendered before the sibling.
- If not, use explicit `type` or pass record arguments as fallback.

## Landmark ViewHelper

Use `<mindfula11y:landmark>` to render semantic landmark containers from editor-managed fields.

```html
<mindfula11y:landmark
    recordUid="{f:if(condition: data._LOCALIZED_UID, then: data._LOCALIZED_UID, else: data.uid)}"
    role="{data.tx_mindfula11y_landmark}">
    {data.bodytext}
</mindfula11y:landmark>
```

`recordUid` follows the same localization rule as `<mindfula11y:heading>` above.

Optional tag override:

```html
<mindfula11y:landmark role="navigation" tagName="div">
    ...
</mindfula11y:landmark>
```

## Extending TCA for custom records

If you want custom tables to participate in the same editorial accessibility workflow, add equivalent fields and use the same ViewHelpers.

### Add heading type field

```php
<?php
declare(strict_types=1);

use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns(
    'tx_myext_records',
    [
        'headingtype' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.headingType',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => HeadingType::H2->value,
                'items' => [
                    ['label' => HeadingType::H1->getLabelKey(), 'value' => HeadingType::H1->value],
                    ['label' => HeadingType::H2->getLabelKey(), 'value' => HeadingType::H2->value],
                    ['label' => HeadingType::H3->getLabelKey(), 'value' => HeadingType::H3->value],
                    ['label' => HeadingType::H4->getLabelKey(), 'value' => HeadingType::H4->value],
                    ['label' => HeadingType::H5->getLabelKey(), 'value' => HeadingType::H5->value],
                    ['label' => HeadingType::H6->getLabelKey(), 'value' => HeadingType::H6->value],
                    ['label' => HeadingType::P->getLabelKey(), 'value' => HeadingType::P->value],
                    ['label' => HeadingType::DIV->getLabelKey(), 'value' => HeadingType::DIV->value],
                ],
            ],
        ],
    ]
);

ExtensionManagementUtility::addToAllTCAtypes('tx_myext_records', 'headingtype', '', 'after:title');
```

### Add landmark fields and accessibility palette

```php
<?php
declare(strict_types=1);

use MindfulMarkup\MindfulA11y\Enum\AriaLandmark;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns(
    'tx_myext_records',
    [
        'landmark' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.landmark',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => AriaLandmark::NONE->value,
                'items' => [
                    ['label' => AriaLandmark::NONE->getLabelKey(), 'value' => AriaLandmark::NONE->value],
                    ['label' => AriaLandmark::REGION->getLabelKey(), 'value' => AriaLandmark::REGION->value],
                    ['label' => AriaLandmark::NAVIGATION->getLabelKey(), 'value' => AriaLandmark::NAVIGATION->value],
                    ['label' => AriaLandmark::COMPLEMENTARY->getLabelKey(), 'value' => AriaLandmark::COMPLEMENTARY->value],
                    ['label' => AriaLandmark::MAIN->getLabelKey(), 'value' => AriaLandmark::MAIN->value],
                    ['label' => AriaLandmark::BANNER->getLabelKey(), 'value' => AriaLandmark::BANNER->value],
                    ['label' => AriaLandmark::CONTENTINFO->getLabelKey(), 'value' => AriaLandmark::CONTENTINFO->value],
                    ['label' => AriaLandmark::SEARCH->getLabelKey(), 'value' => AriaLandmark::SEARCH->value],
                    ['label' => AriaLandmark::FORM->getLabelKey(), 'value' => AriaLandmark::FORM->value],
                ],
            ],
        ],
        'aria_labelledby' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabelledby',
            'displayCond' => 'FIELD:landmark:!=:',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'aria_label' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabel',
            'displayCond' => 'FIELD:landmark:!=:',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
    ]
);

$GLOBALS['TCA']['tx_myext_records']['palettes']['landmarks'] = [
    'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.palettes.landmarks',
    'showitem' => 'landmark, --linebreak--, aria_labelledby, aria_label',
];

ExtensionManagementUtility::addToAllTCAtypes(
    'tx_myext_records',
    '--div--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.tabs.accessibility, --palette--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.palettes.landmarks;landmarks'
);
```

### Use custom table fields in Fluid

```html
<mindfula11y:heading
    recordUid="{record.uid}"
    recordTableName="tx_myext_records"
    recordColumnName="headingtype"
    type="{record.headingtype}">
    {record.title}
</mindfula11y:heading>

<mindfula11y:landmark
    recordUid="{record.uid}"
    recordTableName="tx_myext_records"
    recordColumnName="landmark"
    role="{record.landmark}">
    {record.content}
</mindfula11y:landmark>
```

> TYPO3 13 can derive database schema from TCA definitions, so manual SQL additions are usually unnecessary.
