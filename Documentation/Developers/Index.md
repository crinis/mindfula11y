# Developers

This guide focuses on implementing Mindful A11y features in templates and custom record types.

## Fluid namespace

```html
<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">
```

## Heading ViewHelpers

### 1) Main heading: `<mindfula11y:heading>`

Use this for the primary heading output of a record.

```html
<mindfula11y:heading
    recordUid="{data.uid}"
    type="{data.tx_mindfula11y_headingtype}"
    relationId="{data.uid}">
    {data.header}
</mindfula11y:heading>
```

Edge cases:

- If `type` is set, that value is rendered directly.
- If `type` is not set, the ViewHelper resolves the heading type from the configured record field.
- If neither is available, it falls back to `h2`.
- Use `relationId` if you want to reference this heading from descendant/sibling headings.

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
    recordUid="{data.uid}"
    role="{data.tx_mindfula11y_landmark}">
    {data.bodytext}
</mindfula11y:landmark>
```

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
