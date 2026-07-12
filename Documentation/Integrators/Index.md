# Integrators

This section is for TYPO3 integrators and administrators configuring the extension.

## Installation

```bash
composer require mindfulmarkup/mindfula11y
```

Install/activate the extension in TYPO3 as usual.

## Extension configuration

Configure in **Admin Tools > Settings > Extension Configuration**.

![TYPO3 extension configuration screen for Mindful A11y showing OpenAI settings and scanner API URL/token settings](../Images/integrators-extension-settings.png)

| Setting | Purpose |
| --- | --- |
| `openAIApiKey` | API key used for AI alt text generation. |
| `openAIChatModel` | Model used for generation (`gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-5-mini`, `gpt-5-nano`, `gpt-5.1`, `gpt-5.2`). |
| `openAIChatImageDetail` | Image analysis depth (`auto`, `low`, `high`) for quality/cost tuning. |
| `disableAltTextGeneration` | Turn off AI generation globally while keeping manual alt editing. |
| `scannerApiUrl` | Base URL of your scanner service. Use full format with protocol and optional port, without path. Example: `http://localhost:3000` (MindfulAPI Docker default) or `https://scanner.example.com`. |
| `scannerApiToken` | Bearer token used to authenticate TYPO3 against the scanner API (if enabled there). |

## Feature activation checklist

1. Enable or disable module features in Page TSconfig.
2. Configure OpenAI only when AI alt text generation should be available.
3. Configure scanner only after MindfulAPI is reachable from TYPO3.
4. Grant backend module, table, and file permissions to target editor groups.

## Page TSconfig

Default options shipped by the extension:

```typoscript
mod {
    mindfula11y_accessibility {
        missingAltText {
            enable = 1
            # ignoreColumns {
            #     <table> = <column>,<column>
            # }
            ignoreFileMetadata = 1
        }
        headingStructure {
            enable = 1
        }
        landmarkStructure {
            enable = 1
        }
        scan {
            enable = 0
            autoCreate = 1
            # basicAuthUsername =
            # basicAuthPassword =
        }
    }

    web_layout {
        mindfula11y {
            hideInfo = 0
        }
    }
}

TCEFORM.tt_content.tx_mindfula11y_landmark {
    removeItems = main,banner,contentinfo
}
```

### TSconfig options explained

| Option | Used for |
| --- | --- |
| `mod.mindfula11y_accessibility.missingAltText.enable` | Shows/hides Missing alternative text feature. |
| `mod.mindfula11y_accessibility.missingAltText.ignoreColumns` | Excludes specific file fields from missing-alt checks, per table: `ignoreColumns { <table> = <column>,<column> }`. |
| `mod.mindfula11y_accessibility.missingAltText.ignoreFileMetadata` | With `1` (default), file references without their own alternative text are listed even when the file's metadata provides a fallback (the fallback is shown on the item). Set `0` to treat metadata alt text as sufficient — covered references are then hidden and editors get a filter toggle to show them anyway. |
| `mod.mindfula11y_accessibility.headingStructure.enable` | Enables heading structure checks in module. |
| `mod.mindfula11y_accessibility.landmarkStructure.enable` | Enables landmark structure checks in module. |
| `mod.mindfula11y_accessibility.scan.enable` | Enables scanner feature in module. |
| `mod.mindfula11y_accessibility.scan.autoCreate` | Auto-starts new scan on module load when content changed. |
| `mod.mindfula11y_accessibility.scan.basicAuthUsername` | Username used only when target frontend is protected by HTTP Basic Auth. |
| `mod.mindfula11y_accessibility.scan.basicAuthPassword` | Password used only when target frontend is protected by HTTP Basic Auth. |
| `mod.web_layout.mindfula11y.hideInfo` | Hides Mindful A11y info box in page module header area. |

## Scanner integration

Before enabling scanner features, you must set up an external scanner service.

Scanner functionality stays disabled until you explicitly set `mod.mindfula11y_accessibility.scan.enable = 1` in Page TSconfig.

**Required:** [MindfulAPI](https://github.com/crinis/mindfulapi) **version 0.7.0 or later** running via Docker. This extension talks to the versioned `/v1` API routes and consumes the AI agent audit fields (audit status, agent findings), both introduced in MindfulAPI v0.7.0 — older MindfulAPI releases are not supported.

Minimal setup:

```bash
git clone https://github.com/crinis/mindfulapi.git
cd mindfulapi
cp .env.example .env
docker compose up -d
```

Then configure `scannerApiUrl` (and `scannerApiToken` if enabled in MindfulAPI).

Mindful A11y scanner features use this external project to run **automated technical scans with axe-core in a real browser (headless) environment**.

Common setup:

1. Ensure MindfulAPI is running via Docker.
2. Set `scannerApiUrl` and optional `scannerApiToken`.
3. Enable scanner in TSconfig: `mod.mindfula11y_accessibility.scan.enable = 1`.

Without both a reachable MindfulAPI service and `mod.mindfula11y_accessibility.scan.enable = 1`, scanner UI/actions remain unavailable.

### Scan modes: Targeted Scan vs Full Site Crawl

- **Targeted Scan** scans the current page URL, or a defined child-page scope from the scan scope menu (`0/1/5/10/99` levels).
- **Full Site Crawl** starts from the selected page URL and discovers linked pages automatically within the selected site/language URL space.
- Full Site Crawl is available **only** for site root pages (`is_siteroot = 1`).
- On non-root pages, editors only get Targeted Scan.

![Scanner panel in TYPO3 backend after successful MindfulAPI integration, showing scan controls and status](../Images/integrators-scanner-enabled.png)

### Scanner quality and limitations

- These automated checks are reliable for many technical accessibility violations.
- They cannot detect every accessibility problem (for example content meaning, context, editorial quality, or all UX issues).
- Treat scanner output as a strong technical baseline and combine it with editorial/manual accessibility reviews.

### Scanning pages behind HTTP Basic Authentication

If your frontend is protected by HTTP Basic Auth, set both TSconfig keys:

- `mod.mindfula11y_accessibility.scan.basicAuthUsername`
- `mod.mindfula11y_accessibility.scan.basicAuthPassword`

The extension sends these credentials server-side to the scanner so protected pages can still be scanned.

Both values are required; if one is missing, no credentials are sent.

> Security note: keep scanner credentials scoped to a low-privilege account. Page TSconfig is backend-managed configuration and should be treated as sensitive project configuration.

### AI review (agent audit)

MindfulAPI (v0.7.0 or later, see above) can optionally run an **AI audit** alongside the axe-core scan: a language model reviews content quality aspects (image alternative text, heading structure, link purpose, form labels, page title) and reports findings with severity, confidence and suggestions. AI findings are shown in a separate "AI review" section and always need human judgement.

Requirements and configuration:

1. Enable the agent feature in MindfulAPI (`AGENT_ENABLED=true` plus provider/model/API key — see the MindfulAPI README; `AGENT_SKILLS` whitelists the allowed skills).
2. Enable the toggle per page tree via Page TSconfig:

```
mod.mindfula11y_accessibility.scan.aiAudit {
    # Offer the "Include AI review" toggle in the scan module.
    enable = 1
    # Pre-select the toggle for new scans (editors can still switch it off per scan).
    default = 0
    # Skills requested from the scanner. Align with the server's AGENT_SKILLS whitelist —
    # requesting a skill the server does not allow fails the scan creation.
    skills = image_alt_text,heading_structure,link_purpose,form_labels,page_title
}
```

Notes:

- The AI audit runs **only** when an editor starts a scan with the toggle checked. Automatically created scans (page module info panel, General tab) never request it, so no LLM cost is incurred by simply browsing the backend.
- Each audit consumes LLM tokens on the MindfulAPI side; use `default = 1` deliberately.
- If the server has the feature disabled or a requested skill is not whitelisted, scan creation fails with the API's explanation shown to the editor.

### Scanner troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Scanner area not visible in the module | `scan.enable` is still `0` | Set `mod.mindfula11y_accessibility.scan.enable = 1` in Page TSconfig. |
| Connection refused / timeout when scanning | `scannerApiUrl` points at the wrong host/port, or MindfulAPI is not running | Confirm MindfulAPI is up (`docker compose ps`) and reachable **from the TYPO3 container**; check protocol and port, and omit any path. |
| `401` / `403` from the scanner | `scannerApiToken` missing or not matching MindfulAPI | Align the token in Extension Configuration with the token configured in MindfulAPI. |
| Scans fail or return nothing for a protected site | Frontend behind HTTP Basic Auth, credentials not set | Set **both** `scan.basicAuthUsername` and `scan.basicAuthPassword` (both are required). |
| Full Site Crawl option is missing | Selected page is not a site root | Crawl is only offered on `is_siteroot = 1` pages; use Targeted Scan elsewhere. |
| Stale results after MindfulAPI retention cleanup | An outdated `scanid` is still referenced on the page | Run `mindfula11y:cleanupscans` (schedule it to match your retention policy). |

## Permissions checklist

Grant the relevant editor groups the permissions below. Missing permissions
typically surface as partial module functionality or disabled actions rather
than hard errors.

| Permission | Needed for | What breaks without it |
| --- | --- | --- |
| Backend module `mindfula11y_accessibility` | Opening the module | Module is not listed under **Web**. |
| Page read access (`PAGE_SHOW`) in relevant trees | Selecting pages to check | "No page selected" / empty results. |
| Allowed languages for the page language contexts | Per-language checks | Language menu is missing or empty. |
| File mount read access | Listing media in alt text workflows | Missing-alt images are not listed. |
| `sys_file_reference` write + field access to `alternative` | Manual alt text edits | Alt field is read-only or saving fails. |
| `sys_file_metadata` access to `alternative` | Inherited alt context / filtering | Inherited alt text is not considered. |
| File metadata edit (file mount `editMeta`) | Metadata-level AI generation | Generation is disabled for file metadata. |
| Page content edit permission | Triggering new scans | Scan actions are disabled. |

Scanner state fields on `pages` are internal. Editors do not need direct field
access to `pages.tx_mindfula11y_scanid` or `pages.tx_mindfula11y_scanupdated`.

## Maintenance command

The extension provides:

```bash
vendor/bin/typo3 mindfula11y:cleanupscans
vendor/bin/typo3 mindfula11y:cleanupscans --seconds=604800
```

Purpose of this command:

- removes outdated `tx_mindfula11y_scanid` values from page records
- prevents editors from seeing stale scan references after scanner-side retention cleanup
- ensures the next scan run creates fresh scan data instead of reusing invalid IDs

Use Scheduler/cron to keep TYPO3 scan references aligned with your MindfulAPI retention policy.

![TYPO3 scheduler task configuration for periodic mindfula11y scan ID cleanup command](../Images/integrators-scheduler-cleanup-task.png)
