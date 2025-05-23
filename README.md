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

## Using the Custom Heading ViewHelper

To enable heading structure editing, use the provided `HeadingViewHelper` in your Fluid templates. This ViewHelper renders heading tags with additional data attributes, allowing the backend module to visualize and edit heading levels for each record.

**Example usage:**

```html
<mindfula11y:heading recordTableName="tt_content" recordColumnName="tx_mindfula11y_headinglevel" recordUid="{record.uid}" level="2">
    {record.header}
</mindfula11y:heading>
```

- If this view helper is used editors can then use the backend module to review and adjust heading levels more easily.

## License

This project is licensed under the [GNU General Public License v2.0 (GPL-2.0)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html). See the [LICENSE](LICENSE) file for details.
