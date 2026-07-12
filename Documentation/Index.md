# Mindful A11y

Mindful A11y brings accessibility checks and remediation workflows directly into the TYPO3 backend.

## What you can do with this extension

- Review and improve missing image alternative text
- Check heading and landmark structure in backend workflows
- Run optional automated page accessibility scans
- Use TYPO3 fields and Fluid ViewHelpers for accessible output
- Prefix the page title after failed server-side EXT:form validation so assistive technology announces the error state on load

## Read by role

- [Editors](Editors/Index.md): work inside backend modules to find and fix issues
- [Integrators](Integrators/Index.md): install, configure, and roll out module features
- [Developers](Developers/Index.md): implement feature usage in templates and custom records

## Backend module areas

- **General**: overview and direct links to structure and scan checks
- **Missing alternative text**: list, filter, generate, and save alt text
- **Scanner** (optional): run scans, review results, export reports

## Scanner requirements (optional feature)

Scanner features work only when both conditions are met:

- Your project runs the external [MindfulAPI](https://github.com/crinis/mindfulapi) scanner service, **v0.7.0 or later** (required for the `/v1` API routes and the AI agent audit)
- Page TSconfig enables scanner: `mod.mindfula11y_accessibility.scan.enable = 1`

## Requirements

- TYPO3 `13.4.x LTS` or `14.3.x LTS`
- PHP `8.2` to `8.4`

`typo3/cms-form` is optional. When installed, Mindful A11y automatically adds the localized
validation-error title prefix; no form-template integration is required.
