# WFA Accessibility Toolkit for TYPO3

WFA Accessibility Toolkit is a TYPO3 extension that integrates accessibility tools directly into the TYPO3 backend, helping editors and integrators improve the accessibility of their content.

## Requirements

- TYPO3 13.4.x

## Installation

Install via Composer:

```bash
composer require mindfulmarkup/mindfula11y
```

## Features

- **Alternative Text Checker**: Backend module that lists all `sys_file_reference` records (e.g., images) without alternative text, making it easy to find and fix missing alt attributes.

  ![Screenshot of the accessibility backend module listing images missing an alternative text with an input field, a generate and a save button.](Resources/Public/Images/Screenshots/MissingAltTextModule.png)

- **AI-Powered Alt Text Generation**: Generates alternative text for images using OpenAI (ChatGPT). Supports `gpt-5-mini`, `gpt-5-nano`, `gpt-5.1`, and `gpt-5.2`.

- **ViewHelpers and TCA columns for heading types and landmarks**: ViewHelpers and a new accessibility tab for content elements make it easy for editors to use accessible heading types and define ARIA landmarks.

- **Heading Structure Overview**: Backend module that visualizes the heading structure of the selected page and lets editors identify issues and edit heading types for records using the custom ViewHelper.

  ![Screenshot of the accessibility backend module showing a heading tree with an error shown due to a skipped heading level](Resources/Public/Images/Screenshots/HeadingTreeModule.png)

- **Landmark Structure Overview**: Backend module that displays ARIA landmarks on the selected page. Editors can review landmark structure, identify accessibility issues, and edit landmark roles directly from the module.

  ![Screenshot of the landmark structure of a page in the accessibility module showing an error due to a duplicated "search" landmark sharing the same label.](Resources/Public/Images/Screenshots/LandmarkModule.png)

- **Automated Accessibility Scanner**: Integration with [MindfulAPI](https://github.com/crinis/mindfulapi), a self-hosted accessibility scanner backend powered by [axe-core](https://github.com/dequelabs/axe-core). Runs WCAG-mapped accessibility audits against live page previews and displays violations with impact levels, CSS selectors, and HTML context directly in the backend module. Requires a separate MindfulAPI instance (see [External Scanner Setup](#external-accessibility-scanner-setup) below).

## Extension Settings

Configure Mindful A11y under **Admin Tools → Settings → Extension Configuration**:

| Setting | Description |
|---------|-------------|
| **OpenAI API Key** | API key for ChatGPT-powered alt text generation. |
| **Chat Model** | OpenAI model to use (`gpt-5-mini`, `gpt-5-nano`, `gpt-5.1`, `gpt-5.2`). Default: `gpt-5-mini`. |
| **Image Detail** | Detail level for image analysis (`auto`, `low`, `high`). Default: `auto`. |
| **Disable Alt Text Generation** | Disable AI-powered alt text generation entirely. Also inactive when no API key is set. |
| **Mindful API URL** | Base URL of your MindfulAPI instance (e.g. `https://scanner.example.com`). |
| **Mindful API Token** | Bearer token for MindfulAPI authentication. Leave empty if the API is configured without authentication. |

## Page TSconfig Options

Configure module behavior per page using Page TSconfig. The defaults shipped with the extension are:

```typo3_typoscript
mod {
    mindfula11y_accessibility {
        missingAltText {
            # Enable the missing alt text checker
            enable = 1
            ignoreColumns {
                # Exclude specific columns from the alternative text check
                tt_content = image
            }
            # Also check sys_file_metadata fallback alternative texts
            ignoreFileMetadata = 1
        }
        headingStructure {
            # Enable the heading structure overview
            enable = 1
        }
        landmarkStructure {
            # Enable the landmark structure overview
            enable = 1
        }
        scan {
            # Enable the accessibility scanner panel.
            # Requires a configured MindfulAPI instance (see Extension Settings).
            enable = 0
            # Automatically trigger a new scan when the module loads and the page has changed since the last scan
            autoCreate = 1
        }
    }

    web_layout {
        mindfula11y {
            # Hide mindfula11y scan issue count in the page module
            hideInfo = 0
        }
    }
}

# Restrict which landmark roles editors can assign.
# Structural landmarks (main, banner, contentinfo) are typically set at template level.
TCEFORM.tt_content.tx_mindfula11y_landmark {
    removeItems = main,banner,contentinfo
}
```

## Finding Images with Missing Alternative Text

The Accessibility module provides a list of `sys_file_reference` records that do not have alternative text set. This helps editors identify and fix images that are missing accessibility information.

### Backend Module Integration

The Missing Alt Text checker is integrated into the main Accessibility backend module and provides filtering options based on:

- **Current Page**: Results are shown for the currently selected page in the page tree
- **Record Type**: Filter by specific tables (e.g., `tt_content`, `pages`) or show all record types
- **Language**: Filter results by specific languages
- **Recursive Depth**: Choose how many page levels to scan (1, 5, 10, or 99 levels deep)

### Inline Editing

Each missing alternative text entry provides:

- **Image Preview**: Thumbnail display with link to view the full-size image
- **Alternative Text Input**: Multi-line text area for entering descriptive text
- **AI Generation**: When OpenAI is configured, a "Generate" button automatically creates alternative text
- **Save Functionality**: Individual saving for each image
- **Record Link**: Direct access to edit the original record containing the file reference

### Configuration Options

Control which columns and tables are included via Page TSconfig:

```typo3_typoscript
mod.mindfula11y_accessibility.missingAltText {
    enable = 1
    ignoreColumns {
        # Exclude specific columns from the alternative text check
        tt_content = image,media
        pages = media
    }
    # Set to 0 to ignore sys_file_metadata fallback alternative texts
    ignoreFileMetadata = 1
}
```

## External Accessibility Scanner Setup

The accessibility scanner feature integrates with [MindfulAPI](https://github.com/crinis/mindfulapi), a self-hosted REST API that uses [axe-core](https://github.com/dequelabs/axe-core) and [Playwright](https://playwright.dev/) to run automated WCAG accessibility audits against your live page URLs.

### 1. Deploy MindfulAPI

The easiest way to run MindfulAPI is with Docker Compose. Refer to the [MindfulAPI README](https://github.com/crinis/mindfulapi#quick-start) for full setup instructions. A minimal deployment:

```bash
git clone https://github.com/crinis/mindfulapi.git
cd mindfulapi
cp .env.example .env
# Edit .env — set AUTH_TOKEN to a secure value before exposing publicly
docker compose up -d
```

The API will be available at `http://localhost:3000` by default.

### 2. Configure the Extension

In **Admin Tools → Settings → Extension Configuration**, set:

- **Mindful API URL**: Base URL of your MindfulAPI instance, e.g. `https://scanner.example.com`
- **Mindful API Token**: The `AUTH_TOKEN` you configured in MindfulAPI. Leave empty if running without authentication (trusted network only).

### 3. Enable the Scanner in Page TSconfig

The scanner panel is disabled by default. Enable it per page tree using Page TSconfig:

```typo3_typoscript
mod.mindfula11y_accessibility.scan {
    enable = 1
    # Set to 0 to require editors to trigger scans manually
    autoCreate = 1
}
```

`enable = 1` must be set for the scanner panel to appear. If `autoCreate = 1`, a new scan is triggered automatically when the module loads and the page content has changed since the last scan.

### How It Works

1. When a scan is triggered (manually or automatically), the TYPO3 backend sends the page's **preview URL** to the MindfulAPI `/scans` endpoint.
2. MindfulAPI queues the scan, then Playwright loads the URL in a headless browser and runs axe-core against it.
3. The extension polls `GET /scans/:id` every 5 seconds until the scan reaches `completed` or `failed` status.
4. Violations are displayed in the backend module grouped by axe rule, with impact level (critical / serious / moderate / minor), CSS selector, and the offending HTML snippet.
5. The scan ID is stored on the page record. If the page content changes (based on `SYS_LASTCHANGED`), the next module load treats the stored scan as stale and triggers a fresh one.

> **Note:** The scanned URL must be publicly reachable by the MindfulAPI instance. For local development, use a tunnelling tool or ensure the API container can reach your TYPO3 dev domain.

### CLI: Clean Up Stale Scan IDs

Scan IDs stored on pages become stale when MindfulAPI purges its data (configured via `CLEANUP_RETENTION_DAYS` in MindfulAPI, default 30 days). Run the following command periodically to clear scan IDs older than a given age so the module triggers fresh scans automatically:

```bash
# Clear scan IDs not updated in the last 30 days (default)
vendor/bin/typo3 mindfula11y:cleanup-scan-ids

# Custom threshold — clear IDs older than 7 days
vendor/bin/typo3 mindfula11y:cleanup-scan-ids --seconds=604800
```

Pair this with a TYPO3 Scheduler task or a system cron job matching MindfulAPI's own cleanup schedule.

## Heading Types

The Mindful A11y extension adds a `tx_mindfula11y_headingtype` column to the `tt_content` table, allowing editors (with appropriate permissions) to set the semantic heading type for content elements. This enables precise control over the document's heading hierarchy for better accessibility.

### What are Heading Types?

Heading types define the semantic level and HTML element used to render headings in your content. Proper heading structure is crucial for accessibility, as screen readers and other assistive technologies use headings to navigate and understand the page structure.

### Available Heading Types

The extension provides the following heading type options:

- **H1-H6**: Semantic heading levels (`<h1>` through `<h6>`) for proper document structure
- **Paragraph (p)**: For text that should be rendered as a paragraph rather than a heading (`<p>`)
- **Generic div (div)**: For content that needs custom styling without any semantic meaning (`<div>`)

### Backend Module Integration

Headings are displayed in a tree structure within the Accessibility backend module, providing editors with a clear overview of the page's heading hierarchy. The module identifies accessibility issues such as missing H1 elements or skipped heading levels, and allows for easy editing of heading types directly from the tree view.

### Using the Heading ViewHelpers in Fluid Templates

The Mindful A11y extension provides three ViewHelpers for rendering accessible, semantically correct headings in your Fluid templates:

### ViewHelper Arguments


#### 1. `HeadingViewHelper` (`<mindfula11y:heading>`) – Main/Standalone Headings
**Arguments:**
- `recordUid` (int, optional): The UID of the record with the heading.
- `recordTableName` (string, optional, default: `tt_content`): Database table name of the record with the heading.
- `recordColumnName` (string, optional, default: `tx_mindfula11y_headingtype`): Name of field that stores the heading type.
- `type` (string, optional): The heading type to use (`h1`, `h2`, ..., `h6`, `p`, `div`, etc.). If not provided, the value will be fetched from the database record or set to `"h2"`.
- `relationId` (string, optional): The relation identifier for this heading (used for referencing in sibling/descendant headings).

Renders a heading element (e.g., `<h2>`, `<h3>`, `<p>`) for a content record or static content. The tag is determined by the stored heading type, a provided type, or a default. Optionally, you can provide a `relationId` to allow descendant or sibling headings to reference this heading's type.

**Basic usage for tt_content records:**

```html
<html
	xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
	xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers"
	data-namespace-typo3-fluid="true"
>
<mindfula11y:heading
    recordUid="{data.uid}"
    recordTableName="tt_content"
    recordColumnName="tx_mindfula11y_headingtype"
    type="{data.tx_mindfula11y_headingtype}">
    {data.header}
</mindfula11y:heading>
</html>
```
> **Note:** If the `type` parameter is provided to the viewhelper, it uses this value directly and avoids an additional database lookup. This can improve performance by reducing unnecessary queries.

**Static heading (no editing, no DB lookup):**

```html
<mindfula11y:heading type="h2">Static heading</mindfula11y:heading>
```

#### 2. `DescendantViewHelper` (`<mindfula11y:heading.descendant>`) – Child/Incremented Headings
**Arguments:**
- `ancestorId` (string, required): The `relationId` of the heading ancestor.
- `levels` (int, optional, default: `1`): How many levels to increment the heading type.
- `type` (string, optional): The heading type to use. If provided, overrides the computed tag and adds `levels`.
- `recordUid`, `recordTableName`, `recordColumnName` (optional): These arguments should only be used if the referenced ancestor (by `ancestorId`) appears after this ViewHelper in the template, or if the cache lookup does not work. In normal usage, prefer using `ancestorId`.

Renders a heading as a descendant of a referenced ancestor heading, incrementing the heading level as needed. The ancestor is referenced by `ancestorId` (which must match a `relationId` set on a parent heading). The tag is computed by incrementing the ancestor's heading level by the `levels` argument (default: 1).

**Usage:**

```html
<html
	xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
	xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers"
	data-namespace-typo3-fluid="true"
>
<mindfula11y:heading
    recordUid="{data.uid}"
    recordTableName="tt_content"
    recordColumnName="tx_mindfula11y_headingtype"
    type="{data.tx_mindfula11y_headingtype}"
    relationId="mainHeading">
    Parent heading
</mindfula11y:heading>

<mindfula11y:heading.descendant
    ancestorId="mainHeading"
    levels="1">
    Child heading
</mindfula11y:heading.descendant>
</html>
```

**Behavior notes:**
- If the ancestor is a semantic heading (`h1`–`h6`), the descendant will be the incremented heading level (e.g., `h2` → `h3`). If the increment would exceed `h6`, the descendant becomes a paragraph (`p`).
- You may override the computed tag by supplying a `type` argument to the descendant ViewHelper.
- The ancestor must appear before the descendant in the template for the reference to work. If not, provide the `type` or `recordX` arguments directly.

#### 3. `SiblingViewHelper` (`<mindfula11y:heading.sibling>`) – Sibling Headings at the Same Level
**Arguments:**
- `siblingId` (string, required): The `relationId` of the heading sibling.
- `type` (string, optional): The heading type to use. If provided, overrides the computed tag.
- `recordUid`, `recordTableName`, `recordColumnName` (optional): These arguments should only be used if the referenced sibling (by `siblingId`) appears after this ViewHelper in the template, or if the cache lookup does not work. In normal usage, prefer using `siblingId`.

Renders a heading at the same level as a referenced sibling heading. The sibling is referenced by `siblingId` (which must match a `relationId` set on another heading). The tag is determined by the cached heading type of the sibling.

**Usage:**

```html
<html
	xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
	xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers"
	data-namespace-typo3-fluid="true"
>
<mindfula11y:heading
    recordUid="{data.uid}"
    recordTableName="tt_content"
    recordColumnName="tx_mindfula11y_headingtype"
    type="{data.tx_mindfula11y_headingtype}"
    relationId="mainHeading">
    First heading
</mindfula11y:heading>

<mindfula11y:heading.sibling siblingId="mainHeading">
    Sibling at same level
</mindfula11y:heading.sibling>
</html>
```

**Behavior notes:**
- The referenced sibling must appear before this ViewHelper in the template for the reference to work. If not, provide the `type` or `recordX` arguments.
- You may override the computed tag by supplying a `type` argument to the sibling ViewHelper.
- If the referenced sibling is not a heading, the same tag is used.

### Extending Custom Record Types

To add heading type support to custom record types, follow these steps:

#### 1. Add TCA Column Definition

Create a TCA override file for your custom table (e.g., `Configuration/TCA/Overrides/tx_myext_records.php`):

```php
<?php
declare(strict_types=1);

use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Add the heading type column to your custom table
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

// Add the field to your record type's interface
ExtensionManagementUtility::addToAllTCAtypes(
    'tx_myext_records',
    'headingtype',
    '',
    'after:title'
);
```

#### 2. Update Database Schema

Add the database column to your `ext_tables.sql`:

```sql
CREATE TABLE tx_myext_records (
    headingtype varchar(10) DEFAULT 'h2' NOT NULL
);
```

> **Note:** In TYPO3 13+, database schema updates are handled automatically based on TCA configuration, so manual `ext_tables.sql` definitions are no longer required for TCA-defined fields.

#### 3. Use in Fluid Templates

In your Fluid templates, use the ViewHelper with your custom table:

```html
<html
	xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
	xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers"
	data-namespace-typo3-fluid="true"
>
<mindfula11y:heading 
    recordUid="{record.uid}" 
    recordTableName="tx_myext_records" 
    recordColumnName="headingtype" 
    type="{record.headingtype}">
    {record.title}
</mindfula11y:heading>
</html>
```

This integration allows your custom records to benefit from the same heading structure analysis and inline editing capabilities provided by the Accessibility backend module.

## Landmarks

The Mindful A11y extension adds landmark-related columns to the `tt_content` table, allowing editors to define ARIA landmarks for better page navigation. These landmarks help screen reader users understand the structure and purpose of different page sections.

### What are Landmarks?

ARIA landmarks identify the purpose of different sections of a page, making it easier for assistive technology users to navigate content. They provide semantic meaning to regions of a page and allow users to quickly jump between different sections.

### Available Landmark Types

The extension provides the following landmark options:

- **None**: No landmark role applied
- **Region**: Important Section (only if none of the other options apply)
- **Navigation**: Navigation Menu (`<nav>`)
- **Complementary**: Sidebar / Related Content (`<aside>`)
- **Main**: Main Content Area (`<main>`)
- **Banner**: Site Header (`<header>`)
- **Contentinfo**: Site Footer (`<footer>`)
- **Search**: Search Function (`<search>`)
- **Form**: Form Section (`<form>`)

### Landmark Columns

The extension adds three columns to support landmarks:

- **`tx_mindfula11y_landmark`**: The landmark type/role
- **`tx_mindfula11y_arialabelledby`**: Checkbox to use the content element's header as the landmark name
- **`tx_mindfula11y_arialabel`**: Custom landmark name (when not using the header)

### Backend Module Integration

Landmarks are displayed in a hierarchical layout within the Accessibility backend module, providing editors with a clear overview of the page's landmark structure. The module identifies many accessibility issues and allows for easy editing of landmark roles and names directly from the structure view.

### Using the Landmark ViewHelper

**Arguments:**
- `recordUid` (int, optional): The UID of the record that is being rendered.
- `recordTableName` (string, optional, default: `tt_content`): Database table name of the record being rendered.
- `recordColumnName` (string, optional, default: `tx_mindfula11y_landmark`): Name of field that stores the role.
- `role` (string, optional): The landmark role value.
- `tagName` (string, optional): Override the HTML tag name regardless of the role. The role attribute will still be applied.

To apply landmarks in the frontend, use the provided `LandmarkViewHelper`. The ViewHelper renders the appropriate HTML element based on the landmark type and integrates with the backend module for inline editing capabilities.

The ViewHelper automatically selects semantic HTML elements for each landmark role:

- **navigation** → `<nav>`
- **main** → `<main>`
- **banner** → `<header>`
- **contentinfo** → `<footer>`
- **complementary** → `<aside>`
- **search** → `<search>`
- **form** → `<form>`
- **region** → `<section>`

You can override the automatically selected tag using the optional `tagName` argument. When `tagName` is provided, the ViewHelper will use that element and add the appropriate `role` attribute.

#### Basic Usage for tt_content Records

```html
<html
	xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
	xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers"
	data-namespace-typo3-fluid="true"
>
<!-- If aria-labelledby is active and a header is set prefer over aria-label. Change based on your requirements. -->
<f:if condition="{data.tx_mindfula11y_arialabelledby} && {data.header}">
    <f:then>
        <f:variable name="ariaAttributes" value="{labelledby: 'c{data.uid}-heading'}" />
    </f:then>
    <f:else if="{data.tx_mindfula11y_arialabel">
        <f:variable name="ariaAttributes" value="{label: data.tx_mindfula11y_arialabel}" />
    </f:else>
    <f:else>
        <f:variable name="ariaAttributes" value="{}" />
    </f:else>
</f:if>

<mindfula11y:landmark 
    recordUid="{data.uid}" 
    recordTableName="tt_content" 
    recordColumnName="tx_mindfula11y_landmark" 
    role="{data.tx_mindfula11y_landmark}" 
    aria="{ariaAttributes}">
    {data.bodytext}
</mindfula11y:landmark>
</html>
```

### Extending Custom Record Types

To add landmark support to custom record types, follow these steps:

#### 1. Add TCA Column Definitions

Create a TCA override file for your custom table (e.g., `Configuration/TCA/Overrides/tx_myext_records.php`):

```php
<?php
declare(strict_types=1);

use MindfulMarkup\MindfulA11y\Enum\AriaLandmark;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Add the landmark columns to your custom table
ExtensionManagementUtility::addTCAcolumns(
    'tx_myext_records',
    [
        'landmark' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.landmark',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.landmark.description',
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
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabelledby.description',
            'displayCond' => 'FIELD:landmark:!=:',
            'onChange' => 'reload',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
                'items' => [['label' => '']],
            ],
        ],
        'aria_label' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabel',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabel.description',
            'displayCond' => 'FIELD:landmark:!=:',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
    ]
);

// Add landmark palette
$GLOBALS['TCA']['tx_myext_records']['palettes']['landmarks'] = [
    'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.palettes.landmarks',
    'showitem' => 'landmark, --linebreak--, aria_labelledby, aria_label'
];

// Add the accessibility tab with landmarks palette
ExtensionManagementUtility::addToAllTCAtypes(
    'tx_myext_records',
    '--div--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.tabs.accessibility, --palette--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.palettes.landmarks;landmarks'
);
```

#### 2. Update Database Schema

Add the database columns to your `ext_tables.sql`:

```sql
CREATE TABLE tx_myext_records (
    landmark varchar(15) DEFAULT '' NOT NULL,
    aria_labelledby tinyint(1) DEFAULT 1 NOT NULL,
    aria_label varchar(255) DEFAULT '' NOT NULL
);
```

> **Note:** In TYPO3 13+, database schema updates are handled automatically based on TCA configuration, so manual `ext_tables.sql` definitions are no longer required for TCA-defined fields.

#### 3. Use in Fluid Templates

In your Fluid templates, use the ViewHelper with your custom table:

```html
<html
	xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
	xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers"
	data-namespace-typo3-fluid="true"
>
<!-- If aria-labelledby is active and a header is set prefer over aria-label. Change based on your requirements. -->
<f:if condition="{record.aria_labelledby} && {record.title}">
    <f:then>
        <f:variable name="ariaAttributes" value="{labelledby: 'record-{record.uid}-title'}" />
    </f:then>
    <f:else if="{record.aria_label">
        <f:variable name="ariaAttributes" value="{label: record.aria_label}" />
    </f:else>
    <f:else>
        <f:variable name="ariaAttributes" value="{}" />
    </f:else>
</f:if>

<mindfula11y:landmark 
    recordUid="{record.uid}" 
    recordTableName="tx_myext_records" 
    recordColumnName="landmark" 
    role="{record.landmark}" 
    aria="{ariaAttributes}">
    {record.content}
</mindfula11y:landmark>
</html>
```

This integration allows your custom records to benefit from the same landmark structure analysis and inline editing capabilities provided by the Accessibility backend module.

## License

This project is licensed under the [GNU General Public License v2.0 (GPL-2.0)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html). See the [LICENSE](LICENSE) file for details.
