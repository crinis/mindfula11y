# Mindful A11y TYPO3 Extension

Mindful A11y is a TYPO3 extension that integrates accessibility tools directly into the TYPO3 backend, helping editors and integrators improve the accessibility of their content.

> **Note:** This extension is in an early stage of development. I do not recommend using it in production environments at this time.

## Installation

Install via Composer:

```bash
composer require mindfulmarkup/mindfula11y
```

## Features

- **Alternative Text Checker**: Backend module that lists all `sys_file_reference` records (e.g., images) without alternative text, making it easy to find and fix missing alt attributes.
- **Heading Structure Overview**: Backend module that visualizes the heading structure of content elements and allows editors to easily review and edit heading types for records using the custom ViewHelper.
- **Landmark Structure Overview**: Backend module that displays ARIA landmarks on the page in a visual, hierarchical layout. Editors can review landmark structure, identify accessibility issues, and edit landmark roles directly from the backend to improve page navigation and semantic structure.
- **AI-Powered Alt Text Generation**: Supports generating alternative texts for images using ChatGPT.

## Planned Features

- **Automated Accessibility Scanners**: Integration of automated accessibility testing tools to review and report accessibility problems directly in the backend.

## Extension Settings

You can configure Mindful A11y in the extension settings:

- **OpenAI API Key**: Set your OpenAI API key for ChatGPT-powered features.
- **Chat Model**: Choose the OpenAI model for generating alternative text (e.g., `gpt-4o-mini`, `gpt-4o`).
- **Image Detail**: Set the detail level for image analysis (`low` or `high`).
- **Disable Alt Text Generation**: Option to disable the AI-powered alternative text generation. Also inactive if no OpenAI API key is set.

## Page TSconfig Options

Configure module behavior per page using Page TSconfig (`Configuration/page.tsconfig`):

```
mod {
    mindfula11y_missingalttext {
        enable = 1
        ignoreColumns {
            tt_content = image
        }
    }
    mindfula11y_headingstructure {
        enable = 1
    }
    mindfula11y_landmarkstructure {
        enable = 1
    }
}
```

- Enable or disable modules.
- Exclude specific columns from the alternative text check.

## Heading ViewHelper Usage

The `HeadingViewHelper` allows you to render headings in TYPO3 with support for editing heading types in the MindfulA11y backend module.

### Basic Usage

Render a heading with the ability to edit its level from the backend module. This example outputs the default heading field for a `tt_content` record:

```html
## Heading ViewHelper Usage

The HeadingViewHelper renders semantic headings with backend editing support.

```xml
<!-- Basic usage with content element -->
<mindfula11y:heading 
    recordUid="{data.uid}" 
    recordTableName="tt_content" 
    recordColumnName="tx_mindfula11y_headingtype" 
    type="{data.tx_mindfula11y_headingtype}">
    {data.header}
</mindfula11y:heading>

<!-- Direct heading type specification -->
<mindfula11y:heading type="h2">Page Title</mindfula11y:heading>

<!-- Using HeadingType enum values -->
<mindfula11y:heading type="{headingType.value}">Dynamic Heading</mindfula11y:heading>
```
```

- `recordUid`: The UID of the record to allow editing (optional for static headings).
- `recordTableName`: The database table name (default: `tt_content`).
- `recordColumnName`: The field storing the heading type (default: `tx_mindfula11y_headingtype`).
- `type`: The heading type to use (required). Accepts HTML tag names like 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', etc.

### Static Heading (No Editing)

Render a heading without edit capability, e.g. for child or dependent headings:

```html
<mindfula11y:heading type="{data.tx_mindfula11y_headingtype}">
  {data.header}
</mindfula11y:heading>
```

### Notes

- When used in the MindfulA11y backend module and the user has permission, the ViewHelper adds data attributes for frontend editing.
- The ViewHelper checks user permissions and only enables editing if allowed.

## Extending Custom Records with the Heading Type Column

You can add the heading type column provided by this extension to your own custom records. This allows you to reuse the same heading type selection and editing features in your own tables.

### Example: Add to a Custom Table

In your TCA override (e.g. `Configuration/TCA/Overrides/tx_yourextension_domain_model_custom.php`):

```php
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Add the heading type column from tt_content to your custom table
ExtensionManagementUtility::addTCAcolumns(
    'tx_yourextension_domain_model_custom',
    [
        'tx_mindfula11y_headingtype' => $GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_headingtype'],
    ]
);

ExtensionManagementUtility::addToAllTCAtypes(
    'tx_yourextension_domain_model_custom',
    'tx_mindfula11y_headingtype',
    '',
    'after:title' // or any field you want
);
```

- This copies the column configuration from `tt_content` to your custom table.
- Adjust the table name and position as needed.
- You can now use the `HeadingViewHelper` with your custom records just like with `tt_content`.

For more details, see the PHPDoc in `Classes/ViewHelpers/HeadingViewHelper.php`.

## ARIA Landmarks and Accessibility

### What are ARIA Landmarks?

ARIA landmarks provide a way to identify sections of a page and allow assistive technology users to navigate directly to specific content areas. They are essential for screen reader users who rely on landmarks to quickly understand page structure and navigate efficiently.

### Landmark Structure Backend Module

The Landmark Structure backend module provides:

- **Visual Overview**: Displays all landmarks on the current page in a hierarchical, color-coded layout
- **Accessibility Validation**: Identifies common landmark issues such as:
  - Missing main landmark
  - Duplicate main landmarks (main)
  - Unlabeled landmarks that share a role
- **Direct Editing**: Edit landmark roles directly from the backend with real-time validation
- **Nested Relationship Display**: Shows parent-child relationships between landmarks clearly

## Landmark ViewHelper Usage

The `LandmarkViewHelper` renders semantic HTML elements with ARIA landmark roles, providing both accessibility benefits and backend editing capabilities through the MindfulA11y module.

### Key Features

- **Semantic HTML Output**: Automatically uses appropriate semantic elements (`<main>`, `<nav>`, `<aside>`, etc.)
- **ARIA Role Support**: Adds explicit ARIA roles when needed for clarity
- **Backend Integration**: Enables editing landmark roles directly from the MindfulA11y backend module
- **Accessibility Validation**: Built-in validation for proper landmark usage
- **Flexible Configuration**: Supports both database-driven and static landmark definitions

### Basic Usage (Database-Driven Landmarks)

Use the ViewHelper when you need to get landmark roles from database fields and enable backend editing:

```html
<!-- For content elements with landmark roles stored in the database -->
<mindfula11y:landmark
  recordUid="{f:if(condition: data._LOCALIZED_UID, then: data._LOCALIZED_UID, else: data.uid)}"
  recordTableName="tt_content"
  recordColumnName="tx_mindfula11y_landmark"
  role="{data.tx_mindfula11y_landmark}"
  aria="{label: data.tx_mindfula11y_arialabel, labelledby: data.tx_mindfula11y_arialabelledby}"
>
  <!-- Content element content -->
  <f:format.html>{data.bodytext}</f:format.html>
</mindfula11y:landmark>
```

The ViewHelper automatically:
- Chooses the correct HTML element (`<main>`, `<nav>`, `<aside>`, `<section>`, etc.) based on the role
- Adds backend editing capabilities when accessed through the MindfulA11y module
- Falls back to a `<div>` when no landmark role is set

### Static Template-Level Landmarks

For static landmarks defined at the template level, use semantic HTML directly **without** the ViewHelper:

```html
<!-- Site header -->
<header>
  <f:render partial="Header" />
</header>

<!-- Main navigation -->
<nav aria-label="Main navigation">
  <f:render partial="Navigation" />
</nav>

<!-- Main content area -->
<main>
  <f:render partial="Content" />
</main>

<!-- Sidebar -->
<aside aria-label="Related content">
  <f:render partial="Sidebar" />
</aside>

<!-- Site footer -->
<footer>
  <f:render partial="Footer" />
</footer>
```

### ViewHelper Parameters

#### Required Parameters
- **`role`**: The ARIA landmark role (string). See available roles below.

#### Optional Parameters (for Backend Editing)
- **`recordUid`**: Database record UID for backend editing capability
- **`recordTableName`**: Database table name (default: `tt_content`)
- **`recordColumnName`**: Field storing the landmark role (default: `tx_mindfula11y_landmark`)

#### ARIA Configuration
- **`aria`**: Array of ARIA attributes:
  - `label`: Accessible name for the landmark (string) - used when `arialabelledby` is disabled
  - `labelledby`: Reference to element(s) that label the landmark (string) - automatically set when `arialabelledby` is enabled

### Available Landmark Roles

| Role | Purpose | HTML Element | Uniqueness | Accessible Name |
|------|---------|--------------|------------|-----------------|
| `""` (empty) | No landmark role | `<div>` | - | - |
| `main` | Primary content area | `<main>` | **Unique per page** | Optional |
| `navigation` | Navigation sections | `<nav>` | Multiple allowed | Recommended |
| `complementary` | Supporting content, sidebars | `<aside>` | Multiple allowed | **Required** |
| `banner` | Site header/masthead | `<header>` | **Unique per page** | Optional |
| `contentinfo` | Site footer/metadata | `<footer>` | **Unique per page** | Optional |
| `region` | Generic content sections | `<section>` | Multiple allowed | **Required** |
| `search` | Search functionality | `<div>` | Multiple allowed | Recommended |
| `form` | Form sections | `<form>` | Multiple allowed | Recommended |

### When to Use the ViewHelper vs. Semantic HTML

**Use the LandmarkViewHelper when:**
- Working with content elements that have database-stored landmark roles
- Need backend editing capabilities through the MindfulA11y module
- Content editors should be able to change landmark roles without touching templates
- Working with dynamic content where landmark roles may vary

**Use semantic HTML directly when:**
- Creating static template-level landmarks (header, nav, main, aside, footer)
- The landmark role is fixed and won't change
- No backend editing is needed
- Working with page layout structure rather than content elements

### Content Element Integration

To enable landmark functionality for content elements, configure your content element types:

```php
// In your extension's TCA configuration
$GLOBALS['TCA']['tt_content']['types']['your_content_type']['showitem'] .= ',
  --div--;LLL:EXT:mindfula11y/Resources/Private/Language/locallang_db.xlf:tabs.accessibility,
    tx_mindfula11y_landmark,
    tx_mindfula11y_arialabel,
    tx_mindfula11y_arialabelledby,
';
```

Then use in your Fluid template:

```html
<mindfula11y:landmark
  recordUid="{data.uid}"
  recordTableName="tt_content"
  recordColumnName="tx_mindfula11y_landmark"
  role="{data.tx_mindfula11y_landmark}"
  aria="{label: data.tx_mindfula11y_arialabel, labelledby: data.tx_mindfula11y_arialabelledby}"
>
  <f:render partial="YourContentElement" arguments="{data: data}" />
</mindfula11y:landmark>
```

### Accessibility Best Practices

#### Landmark Hierarchy
```html
<!-- Good: Proper landmark hierarchy using semantic HTML -->
<header> <!-- role="banner" -->
  <nav aria-label="Main menu"> <!-- role="navigation" -->
    <!-- Main navigation -->
  </nav>
</header>

<main> <!-- role="main" -->
  <section aria-label="Article content"> <!-- role="region" -->
    <!-- Article content -->
  </section>
  
  <aside aria-label="Related articles"> <!-- role="complementary" -->
    <!-- Sidebar content -->
  </aside>
</main>
```

#### Required Accessible Names
Always provide accessible names for `region` and `complementary` landmarks:

```html
<!-- Good: Region with accessible name -->
<section aria-label="User comments"> <!-- role="region" -->
  <!-- Comments section -->
</section>

<!-- Good: Complementary with labelledby -->
<aside aria-labelledby="sidebar-title"> <!-- role="complementary" -->
  <h2 id="sidebar-title">Related Articles</h2>
  <!-- Sidebar content -->
</aside>
```

#### Unique Landmarks
Ensure `main`, `banner`, and `contentinfo` appear only once per page:

```html
<!-- Good: Single main landmark -->
<main> <!-- role="main" -->
  <!-- All primary page content -->
</main>

<!-- Avoid: Multiple main landmarks -->
<!-- This would be flagged as an error in the backend module -->
```

### Backend Module Integration

When the ViewHelper is used with record parameters (`recordUid`, `recordTableName`, `recordColumnName`), it integrates with the Landmark Structure backend module:

1. **Data Attributes**: Adds editing metadata to HTML for backend recognition
2. **Permission Checks**: Only enables editing for users with appropriate permissions
3. **Real-time Updates**: Changes made in the backend immediately reflect on the frontend
4. **Validation Feedback**: Shows accessibility issues directly in the module interface

## Extending Custom Records with Landmark Columns

You can add the landmark columns provided by this extension to your own custom records, enabling the same editing capabilities available for `tt_content` records.

### Database Fields

The extension provides three database fields for landmark functionality:

- **`tx_mindfula11y_landmark`**: Stores the landmark role (select field)
- **`tx_mindfula11y_arialabel`**: Stores the `aria-label` value (text field)
- **`tx_mindfula11y_arialabelledby`**: Checkbox that determines whether to use the content element's header as `aria-labelledby` (when enabled) or use a custom `aria-label` (when disabled)

### Adding to Custom Tables

In your TCA override file (e.g., `Configuration/TCA/Overrides/tx_yourextension_domain_model_custom.php`):

```php
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Add landmark columns from tt_content to your custom table
ExtensionManagementUtility::addTCAcolumns(
    'tx_yourextension_domain_model_custom',
    [
        'tx_mindfula11y_landmark' => $GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_landmark'],
        'tx_mindfula11y_arialabel' => $GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_arialabel'],
        'tx_mindfula11y_arialabelledby' => $GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_arialabelledby'],
    ]
);

// Add fields to the backend form
ExtensionManagementUtility::addToAllTCAtypes(
    'tx_yourextension_domain_model_custom',
    '--div--;Accessibility,tx_mindfula11y_landmark,tx_mindfula11y_arialabelledby,tx_mindfula11y_arialabel',
    '',
    'after:title'
);
```

### Using with Custom Records

Once the fields are added, use the ViewHelper with your custom records:

```html
<mindfula11y:landmark
  recordUid="{customRecord.uid}"
  recordTableName="tx_yourextension_domain_model_custom"
  recordColumnName="tx_mindfula11y_landmark"
  role="{customRecord.txYourextensionLandmark}"
  aria="{
    label: customRecord.txYourextensionArialabel,
    labelledby: customRecord.txYourextensionArialabelledby
  }"
>
  <f:render partial="CustomRecord" arguments="{record: customRecord}" />
</mindfula11y:landmark>
```


---

**For detailed technical documentation, see the PHPDoc in `Classes/ViewHelpers/LandmarkViewHelper.php`.**


## License

This project is licensed under the [GNU General Public License v2.0 (GPL-2.0)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html). See the [LICENSE](LICENSE) file for details.
