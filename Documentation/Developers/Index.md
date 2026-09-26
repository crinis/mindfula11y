# Developers

This guide shows how to use Mindful A11y in templates and custom record types.

What to integrate, in order of importance:

1. **Render headings through the [heading ViewHelpers](#heading-viewhelpers).** They output the
   level editors choose and derive levels for nested and related content. They also link each
   heading to its record, so editors can fix it from the heading-structure check.
2. **Render landmark containers through the [landmark ViewHelper](#landmark-viewhelper)**, with
   the editor-managed role and accessible name.
3. **Give custom records the same fields** if they render their own headings or landmarks
   ([Extending TCA for custom records](#extending-tca-for-custom-records)).

Decorative images and the error prefix in the page title need no template work. See
[Built-in behavior](#built-in-behavior-no-template-changes-needed).

What editors get when headings are rendered through the ViewHelpers — a level select and an edit
link on every heading in the backend's structure tree:

![Heading tree in the Accessibility module: each heading rendered through the ViewHelpers has a level select and an edit link, and a skipped heading level is flagged on the affected row](../Images/editors-heading-landmark-checks.png)

## Fluid namespace

```html
<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">
```

## Heading ViewHelpers

Every element has a *logical* heading level, even when no heading is visible. So render
`<mindfula11y:heading>` unconditionally:

- With empty content or `renderTag="false"` (e.g. `header_layout` 100 "hidden"), it outputs
  nothing. An empty heading tag would be an accessibility defect.
- It still registers its relation, so descendant and sibling headings keep deriving their level
  from it.

All heading ViewHelpers validate the heading type. An unknown value is never written into the
markup; the default `h2` is rendered instead.

Relations exist only within one render pass: `ancestorId` and `siblingId` resolve against
headings registered earlier in the same request. An uncached plugin (`USER_INT`) on a cached
page runs without the cached content being rendered again, so a `heading.descendant` inside it
cannot see the container's relation — pass an explicit `type` or the record arguments there.
`relationId` values share one namespace across all tables, so a content element and a custom
record with the same uid collide; when records of more than one table register relations on a
page, prefix the ids with the table (`relationId="tx_myext_records-{record.uid}"`).

### 1) Main heading: `<mindfula11y:heading>`

Use this for the primary heading of a record.

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

| Argument              | Type   | Default                           | Purpose                                                                      |
| --------------------- | ------ | --------------------------------- | ---------------------------------------------------------------------------- |
| `type`                | string | –                                 | Heading type to render (`h1`–`h6`, `p`, `div`). Used as given.               |
| `recordUid`           | int    | –                                 | Uid of the rendered record. Enables editing in the backend.                  |
| `recordTableName`     | string | `tt_content`                      | Table of the record.                                                         |
| `recordColumnName`    | string | `tx_mindfula11y_headingtype`      | Column that stores the heading type.                                         |
| `relationId`          | string | –                                 | Registers this heading so descendant and sibling headings can reference it.  |
| `childType`           | string | –                                 | Type for descendant headings. Empty means automatic (own level + 1).         |
| `childTypeColumnName` | string | `tx_mindfula11y_childheadingtype` | Column read for the child type when `childType` is omitted.                  |
| `renderTag`           | bool   | `true`                            | `false` outputs nothing but still registers the relation.                    |

Standard tag attributes (`id`, `class`, `aria`, `data`, `additionalAttributes`) work as on any
tag-based ViewHelper.

**How the type is resolved:**

1. `type`, if set.
2. Otherwise the record's `recordColumnName` value, read from the database. This needs
   `recordUid`.
3. Otherwise `h2`.

Pass `type` and `childType` from template data as shown. Each saves a database query.

**Child heading type (`childType`):**

- It sets the type of all descendant headings that reference this heading.
- Values: `h1`–`h6`, `p` or `div`. An empty value means automatic: one level below this heading.
- If you omit the argument, the ViewHelper reads the record's `childTypeColumnName` column. It
  does so only when that column exists in the table's TCA. Tables without it resolve to
  automatic, so custom tables need no child-type column.
- The default editor selectors offer `h1`–`h6` and `p` only. `div` stays available to templates
  and project TCA (see [Container elements](#container-elements-configuring-headings-inside)).

**Translated content:** `recordUid` must be the uid of the *localized* record. Otherwise editing
targets the default-language record.

- Classic `data` array: `data._LOCALIZED_UID`, falling back to `data.uid` (as above).
- TYPO3 14 `record` / `PAGEVIEW` pipeline: `{record.computedProperties.localizedUid}`.
  `data._LOCALIZED_UID` is not populated there.
- Use `f:if` as shown. Do **not** use the shorthand `{data._LOCALIZED_UID ?: data.uid}`. Fluid
  returns the literal text `data._LOCALIZED_UID` when the variable is undefined, which is the
  case for every default-language record.

Static heading (no record context):

```html
<mindfula11y:heading type="h2">Section title</mindfula11y:heading>
```

### 2) Descendant heading: `<mindfula11y:heading.descendant>`

Use this when a heading's level derives from an ancestor heading rendered earlier.

```html
<mindfula11y:heading relationId="mainHeading" type="h2">
    Main heading
</mindfula11y:heading>

<mindfula11y:heading.descendant ancestorId="mainHeading" levels="1">
    Child heading
</mindfula11y:heading.descendant>
```

Accepts all arguments of `<mindfula11y:heading>`, plus:

| Argument     | Type   | Default  | Purpose                                                  |
| ------------ | ------ | -------- | -------------------------------------------------------- |
| `ancestorId` | string | required | `relationId` of the ancestor heading.                    |
| `levels`     | int    | `1`      | How many levels below the ancestor this heading renders. |

**How the type is resolved:**

1. `type`, if set. It is used as given, not incremented.
2. Otherwise the ancestor's level plus `levels`. The ancestor must be rendered **before** this
   heading.
3. Otherwise the record's stored type plus `levels` (needs the record arguments).
4. Otherwise `h2`.

Rules:

- Beyond `h6`, the output becomes `p`.
- If the ancestor has a configured child type, the descendant uses it *verbatim* at the default
  `levels="1"`. Higher `levels` continue from it (`childType + levels − 1`). Configured `p` and
  `div` stay unchanged.
- A configured child type is the only way a descendant renders as `h1`. Plain incrementing starts
  at `h2`.
- `childType` on a descendant configures *its own* descendants.
- Give a descendant its own `relationId` to nest further. Each nesting level steps down one level:

```html
<mindfula11y:heading.descendant ancestorId="container" relationId="child">
    Child heading
</mindfula11y:heading.descendant>

<mindfula11y:heading.descendant ancestorId="child">
    Grandchild heading, one level deeper
</mindfula11y:heading.descendant>
```

### 3) Sibling heading: `<mindfula11y:heading.sibling>`

Use this when two headings share the same level.

```html
<mindfula11y:heading relationId="mainHeading" type="h3">
    First heading
</mindfula11y:heading>

<mindfula11y:heading.sibling siblingId="mainHeading">
    Second heading on same level
</mindfula11y:heading.sibling>
```

Accepts `type`, the record arguments and `renderTag` of `<mindfula11y:heading>`. It has no
`relationId`, `childType` or `childTypeColumnName`. Additionally:

| Argument    | Type   | Default  | Purpose                              |
| ----------- | ------ | -------- | ------------------------------------ |
| `siblingId` | string | required | `relationId` of the sibling heading. |

- The referenced heading must be rendered **before** the sibling.
- If it is not, pass `type` or the record arguments instead.
- A sibling copies the referenced heading's own level, not its child type.

### Container elements: configuring headings inside

The tt_content column `tx_mindfula11y_childheadingtype` lets editors set the level of all
headings inside a container element. Options are `h1`–`h6`, `p`, or "Automatic — next level"
(the default: one level below the container's heading).

The column ships **unassigned**. Add it to your container CTypes:

```php
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    'tx_mindfula11y_childheadingtype',
    'my_container_ctype',
    'after:header'
);
```

The field **must be in the CType's showitem**. FormEngine and the heading-structure module both
use the processed TCA. Without it, editors cannot change the child type anywhere.

**Render the container** heading unconditionally, with `relationId` and `childType` (see the
[main-heading example](#1-main-heading-mindfula11yheading)).

**Render the children** with `ancestorId` pointing to the container. With `b13/container`,
children carry the container uid in `tx_container_parent`:

```html
<mindfula11y:heading.descendant
    ancestorId="{data.tx_container_parent}"
    relationId="{data.uid}">
    {data.header}
</mindfula11y:heading.descendant>
```

**Containers without a visible heading** (empty header or `renderTag="false"`) still anchor
their children:

- With a configured child type, children render exactly that type.
- On "Automatic — next level", the container registers no level. Children fall back to their
  own heading-type field.

In the heading-structure module, editors change the child type on the container's own row, and
all its children shift together. For a container without a heading, the ViewHelper emits a hidden
marker only on validated structure-analysis requests; normal frontend output is unchanged.

**Offering `div`:** the ViewHelpers and the `HeadingType` enum support `div`, but the default
editor selectors leave it out on purpose. If your project needs it, add it in a project TCA
override to one or both fields:

```php
use MindfulMarkup\MindfulA11y\Enum\HeadingType;

$divItem = [
    'label' => HeadingType::DIV->getLabelKey(),
    'value' => HeadingType::DIV->value,
];
$GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_headingtype']['config']['items'][] = $divItem;
$GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_childheadingtype']['config']['items'][] = $divItem;
```

## Landmark ViewHelper

`<mindfula11y:landmark>` renders a landmark container from editor-managed fields. Pass the role
from the record: unlike the heading ViewHelpers, it never reads the role from the database.
Without a landmark role it renders a plain `div`.

The accessible name comes from two editor fields: `tx_mindfula11y_arialabelledby` ("Use heading
as landmark name") and `tx_mindfula11y_arialabel` ("Custom landmark name"). Wire them into the
`aria` argument and give the heading a matching `id`:

```html
<mindfula11y:landmark
    recordUid="{f:if(condition: data._LOCALIZED_UID, then: data._LOCALIZED_UID, else: data.uid)}"
    role="{data.tx_mindfula11y_landmark}"
    aria="{f:if(condition: '{data.tx_mindfula11y_arialabelledby} && {data.header}', then: '{labelledby: \'c{data.uid}-heading\'}', else: '{label: data.tx_mindfula11y_arialabel}')}">
    <mindfula11y:heading
        recordUid="{f:if(condition: data._LOCALIZED_UID, then: data._LOCALIZED_UID, else: data.uid)}"
        type="{data.tx_mindfula11y_headingtype}"
        relationId="{data.uid}"
        id="c{data.uid}-heading">
        {data.header}
    </mindfula11y:heading>
    {data.bodytext}
</mindfula11y:landmark>
```

`recordUid` follows the same [localization rule](#1-main-heading-mindfula11yheading) as
`<mindfula11y:heading>`.

| Argument           | Type   | Default                   | Purpose                                                               |
| ------------------ | ------ | ------------------------- | --------------------------------------------------------------------- |
| `role`             | string | `""`                      | Landmark role. Picks the element (e.g. `navigation` → `nav`).         |
| `tagName`          | string | `""`                      | Overrides the element. A landmark `role` is still applied.            |
| `recordUid`        | int    | –                         | Uid of the rendered record. Enables editing in the backend.           |
| `recordTableName`  | string | `tt_content`              | Table of the record.                                                  |
| `recordColumnName` | string | `tx_mindfula11y_landmark` | Column that stores the role. Annotation only, no database lookup.     |
| `aria`             | array  | –                         | Standard tag attribute; use `label` or `labelledby` for the name.     |

Role to element: `region` → `section`, `navigation` → `nav`, `complementary` → `aside`,
`main` → `main`, `banner` → `header`, `contentinfo` → `footer`, `search` → `search`,
`form` → `form`. `banner` and `contentinfo` also get an explicit `role` attribute, so they stay
landmarks when nested.

Optional tag override:

```html
<mindfula11y:landmark role="navigation" tagName="div">
    ...
</mindfula11y:landmark>
```

Rules:

- `tagName` is lower-cased and otherwise written as given.
- Landmark elements (`aside`, `footer`, `header`, `main`, `nav`, `search`) keep their landmark
  semantics. `section` and `form` become landmarks only with an accessible name. A generic
  container or custom element does not.
- `role` is validated. Unknown values are dropped, so a stale record value cannot render
  `role="presentation"` and hide the element from the accessibility tree.
- The accessible name is kept only if the element is a landmark. With a generic `tagName` and no
  role, or a non-landmark `role`, `aria-label` and `aria-labelledby` are dropped.
- Empty `aria-label` and `aria-labelledby` values are never rendered.

The **Accessibility** tab with the landmark fields is added to `tt_content` with
`addToAllTCAtypes()`, which only reaches content types registered before this extension's
`Configuration/TCA/Overrides/tt_content.php` runs. Content types of extensions that load later
must add the tab themselves, for example
`ExtensionManagementUtility::addToAllTCAtypes('tt_content', '--div--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.tabs.accessibility, --palette--;;landmarks', 'my_ctype');`.
The heading type field sits in the core `headers` palette and reaches every type that uses it.

## Extending TCA for custom records

To let editors manage headings and landmarks of custom records, add equivalent fields and render
them with the same ViewHelpers. Pass `recordTableName` and `recordColumnName` so the backend
edits the right column.

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
            // Reload so the name fields below appear/disappear with the role.
            'onChange' => 'reload',
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

> TYPO3 13 and 14 derive the database schema from these TCA definitions, so you usually need no
> manual SQL.

## Built-in behavior (no template changes needed)

These features work with TYPO3's own rendering. They are listed so templates do not work against
them.

### Decorative file references and native image ViewHelpers

Editors set the **Decorative image** checkbox per `sys_file_reference`. Mindful A11y then stores
an explicit empty alternative text and title on the reference, so TYPO3 does not fall back to
file metadata. Native image rendering needs no custom ViewHelper:

```html
<f:image image="{fileReference}" />
<f:media file="{fileReference}" />
```

- `f:image` and the image fallback of `f:media` render `alt=""` for a decorative reference.
- They emit no `title` attribute from file metadata.
- The reference description stays available to templates as an optional visible caption.
- Explicit `alt` and `title` arguments keep TYPO3's normal precedence and override the reference.
  Do not pass a non-empty `alt` if the template should honor the editor's choice.

### Server-side validation-error titles

After failed TYPO3 EXT:form validation, Mindful A11y prefixes the page title with a localized
`Error:`. EXT:form is optional, and no template integration is needed.

- Enable it with `enableValidationErrorTitlePrefix` in Extension Configuration. It is off by
  default.
- Detection uses EXT:form's rendering lifecycle: the `beforeRendering` hook on TYPO3 13 and
  `BeforeRenderableIsRenderedEvent` on TYPO3 14.
- A frontend middleware applies the prefix to the finished response. On TYPO3 13, uncached
  (USER_INT) form errors render after the cached page title. For the same reason, a title provider
  called from a form template does not work on both versions.
- Client-side HTML5 validation needs no handling, because it causes no page load.
