# Mindful A11y TYPO3 Extension

> **Note:** This extension is in a very early stage of development and is not yet available on Packagist or the TYPO3 Extension Repository (TER). I do not recommend using it in production environments at this time.

Mindful A11y is a TYPO3 extension that integrates accessibility tools directly into the TYPO3 backend, helping editors and integrators improve the accessibility of their content.

## Features

- **Alternative Text Checker**: Backend module that lists all `sys_file_reference` records (e.g., images) without alternative text, making it easy to find and fix missing alt attributes.
- **Heading Structure Overview**: Backend module that visualizes the heading structure of content elements and allows editors to easily review and edit heading levels for records using the custom ViewHelper.
- **AI-Powered Alt Text Generation**: Supports generating alternative texts for images using ChatGPT, streamlining the process of making content accessible.

## Planned Features

- **Automated Accessibility Scanners**: Integration of automated accessibility testing tools to review and report accessibility problems directly in the backend.
- **Landmark Management**: Simple management of ARIA landmarks for improved navigation and structure.
- **Advanced Heading Structure Tools**: Improved ways to set up and manage heading structures for content elements and records.

## Extension Settings

You can configure Mindful A11y in the extension settings (`ext_conf_template.txt`):

- **OpenAI API Key**: Set your OpenAI API key for ChatGPT-powered features.
- **Chat Model**: Choose the OpenAI model for generating alternative text (e.g., `gpt-4o-mini`, `gpt-4o`).
- **Image Detail**: Set the detail level for image analysis (`low` or `high`).
- **Disable Alt Text AI**: Option to disable the AI-powered alt text feature.

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
}
```

- Enable or disable modules.
- Exclude specific columns from the alternative text check.

## Heading ViewHelper Usage

The `HeadingViewHelper` allows you to render headings in TYPO3 with support for editing heading levels in the MindfulA11y backend module.

### Basic Usage

Render a heading with the ability to edit its level from the backend module. This example outputs the default heading field for a `tt_content` record:

```html
<mindfula11y:heading
  recordUid="{data.uid}"
  recordTableName="tt_content"
  recordColumnName="tx_mindfula11y_headinglevel"
  level="{data.tx_mindfula11y_headinglevel}"
  fallbackTag="p"
>
  {data.header}
</mindfula11y:heading>
```

- `recordUid`: The UID of the record to allow editing (optional for static headings).
- `recordTableName`: The database table name (default: `tt_content`).
- `recordColumnName`: The field storing the heading level (default: `tx_mindfula11y_headinglevel`).
- `level`: The heading level to use (required).
- `fallbackTag`: The tag to use if `level` is `-1` (default: `p`).

### Static Heading (No Editing)

Render a heading without edit capability, e.g. for child or dependent headings:

```html
<mindfula11y:heading level="{data.tx_mindfula11y_headinglevel + 1}">
  {data.header}
</mindfula11y:heading>
```

### Notes

- When used in the MindfulA11y backend module and the user has permission, the ViewHelper adds data attributes for frontend editing.
- The ViewHelper checks user permissions and only enables editing if allowed.

## Extending Custom Records with the Heading Level Column

You can add the heading level column provided by this extension to your own custom records. This allows you to reuse the same heading level selection and editing features in your own tables.

### Example: Add to a Custom Table

In your TCA override (e.g. `Configuration/TCA/Overrides/tx_yourextension_domain_model_custom.php`):

```php
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Add the heading level column from tt_content to your custom table
ExtensionManagementUtility::addTCAcolumns(
    'tx_yourextension_domain_model_custom',
    [
        'tx_mindfula11y_headinglevel' => $GLOBALS['TCA']['tt_content']['columns']['tx_mindfula11y_headinglevel'],
    ]
);

ExtensionManagementUtility::addToAllTCAtypes(
    'tx_yourextension_domain_model_custom',
    'tx_mindfula11y_headinglevel',
    '',
    'after:title' // or any field you want
);
```

- This copies the column configuration from `tt_content` to your custom table.
- Adjust the table name and position as needed.
- You can now use the `HeadingViewHelper` with your custom records just like with `tt_content`.

For more details, see the PHPDoc in `Classes/ViewHelpers/HeadingViewHelper.php`.


## License

This project is licensed under the [GNU General Public License v2.0 (GPL-2.0)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html). See the [LICENSE](LICENSE) file for details.
