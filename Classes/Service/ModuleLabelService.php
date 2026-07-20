<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Service;

use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Provides the localized labels the module's JavaScript reads via lll().
 *
 * Every inline label is registered as `mindfula11y.<id>` resolving the label
 * `<id>` from the module language file — keep LABEL_IDS in sync with the keys
 * the frontend components actually consume.
 */
final readonly class ModuleLabelService
{
    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
        private BackendUserProvider $backendUserProvider,
    ) {}

    public const LANGUAGE_FILE = 'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:';
    private const DATABASE_LANGUAGE_FILE = 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:';

    // 'div' is not offered to editors by default but remains a renderable type:
    // a div-demoted row's chip still needs its label.
    private const HEADING_TYPE_LABEL_IDS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'];

    private const LABEL_IDS = [
        'structureErrors',
        'structure',
        'structure.analyzing',
        'structure.analyzed',
        'structure.updated',
        'structure.retry',
        'structure.error.rendering',
        'structure.error.rendering.description',
        'structure.error.rendering.ticket',
        'structure.error.rendering.timeout',
        'structure.error.rendering.framing',
        'structure.error.rendering.auth',
        'structure.error.rendering.http',
        'structure.error.rendering.analysis',
        'structure.error.rendering.payload',
        'structure.error.rendering.enrich',
        'structure.error.rendering.openPage',
        'structure.viewport.mobile',
        'structure.viewport.desktop',
        'structure.viewports',
        'structure.saving',
        'structure.headings',
        'structure.headings.relation.jump',
        'structure.headings.error.missingH1',
        'structure.headings.error.missingH1.description',
        'structure.headings.error.multipleH1',
        'structure.headings.error.multipleH1.description',
        'structure.headings.error.emptyHeadings',
        'structure.headings.error.emptyHeadings.description',
        'structure.headings.error.skippedLevel',
        'structure.headings.error.skippedLevel.description',
        'structure.headings.error.skippedLevel.inline',
        'general.error.loading',
        'general.error.loading.description',
        'structure.headings.noHeadings',
        'structure.headings.noHeadings.description',
        'structure.headings.unlabeled',
        'structure.headings.container',
        'structure.headings.container.empty',
        'structure.headings.notInStructure',
        'structure.headings.edit',
        'structure.headings.edit.locked',
        'structure.headings.type',
        'structure.headings.childType',
        'structure.headings.childType.applies',
        'structure.headings.relation.descendant',
        'structure.headings.relation.sibling',
        'structure.headings.relation.notFound',
        'structure.headings.relation.notFound.description',
        'structure.headings.error.store',
        'structure.headings.error.store.description',
        'structure.landmarks',
        'structure.landmarks.nested',
        'structure.landmarks.noLandmarks',
        'structure.landmarks.noLandmarks.description',
        'structure.landmarks.error.missingMain',
        'structure.landmarks.error.missingMain.description',
        'structure.landmarks.error.duplicateMain',
        'structure.landmarks.error.duplicateMain.description',
        'structure.landmarks.error.duplicateSameLabel',
        'structure.landmarks.error.duplicateSameLabel.description',
        'structure.landmarks.error.multipleUnlabeledLandmarks',
        'structure.landmarks.error.multipleUnlabeledLandmarks.description',
        'structure.landmarks.error.duplicateBanner',
        'structure.landmarks.error.duplicateBanner.description',
        'structure.landmarks.error.duplicateContentinfo',
        'structure.landmarks.error.duplicateContentinfo.description',
        'structure.landmarks.error.mainNotTopLevel',
        'structure.landmarks.error.mainNotTopLevel.description',
        'structure.landmarks.error.bannerNotTopLevel',
        'structure.landmarks.error.bannerNotTopLevel.description',
        'structure.landmarks.error.contentinfoNotTopLevel',
        'structure.landmarks.error.contentinfoNotTopLevel.description',
        'structure.landmarks.unlabelledLandmark',
        'structure.landmarks.edit',
        'structure.landmarks.edit.locked',
        'structure.landmarks.role',
        'structure.landmarks.role.none',
        'structure.landmarks.role.banner',
        'structure.landmarks.role.main',
        'structure.landmarks.role.navigation',
        'structure.landmarks.role.complementary',
        'structure.landmarks.role.contentinfo',
        'structure.landmarks.role.region',
        'structure.landmarks.role.search',
        'structure.landmarks.role.form',
        'structure.landmarks.error.roleSelect',
        'structure.landmarks.error.store',
        'structure.landmarks.error.store.description',
        'altText.generate.loading',
        'altText.generate.success',
        'altText.generate.success.description',
        'altText.generate.error.unknown',
        'altText.generate.error.unknown.description',
        'altText.generate.button',
        'altText.altLabel',
        'altText.decorative.label',
        'altText.decorative.description',
        'altText.save',
        'altText.imagePreview',
        'altText.altPlaceholder',
        'altText.fallbackAltLabel',
        'altText.save.success',
        'altText.save.error',
        'altText.save.error.description',
        'altText.opensNewTab',
        'scan.loading',
        'scan.error.loading',
        'scan.status.pending',
        'scan.status.running',
        'scan.status.failed',
        'scan.status.failed.description',
        'scan.issuesFound',
        'scan.noIssues',
        'scan.issueCount',
        'scan.issuesCount',
        'scan.summary.jumpHint',
        'scan',
        'scan.processing',
        'scan.refresh',
        'scan.issueContext',
        'scan.start',
        'scan.selector',
        'scan.context',
        'scan.pageUrl',
        'scan.updatedAt',
        'scan.crawl.start',
        'scan.crawl.refresh',
        'scan.tab.scan',
        'scan.tab.crawl',
        'scan.tab.scan.description',
        'scan.tab.crawl.description',
        'scan.multiPage.manualScan',
        'scan.multiPage.manualScan.description',
        'scan.scopeExpanded',
        'scan.scopeExpanded.description',
        'scan.crawl.idle.title',
        'scan.crawl.idle.description',
        'scan.report.html',
        'scan.report.pdf',
        'scan.status.analyzing',
        'scan.status.canceled',
        'scan.status.canceled.description',
        'scan.cancel',
        'scan.error.cancelFailed',
        'scan.error.cancelFailed.description',
        'scan.error.createFailed',
        'scan.error.createFailed.description',
        'scan.error.getFailed.description',
        'scan.progress.discovering',
        'scan.progress.pages',
        'scan.progress.pagesFailed',
        'scan.helpUrl',
        'scan.opensNewTab',
        'scan.announce.started',
        'scan.announce.completed',
        'scan.announce.canceled',
        'scan.announce.failed',
        'scan.aiAudit.toggle',
        'scan.aiAudit.toggle.description',
        'scan.aiAudit.section',
        'scan.aiAudit.disclaimer.title',
        'scan.aiAudit.disclaimer.description',
        'scan.aiAudit.status.pending',
        'scan.aiAudit.status.running',
        'scan.aiAudit.tasksFailed',
        'scan.aiAudit.appropriateCount',
        'scan.aiAudit.noFindings',
        'scan.aiAudit.findingCount',
        'scan.aiAudit.findingsCount',
        'scan.aiAudit.skill.image_alt_text',
        'scan.aiAudit.skill.heading_structure',
        'scan.aiAudit.skill.link_purpose',
        'scan.aiAudit.skill.form_labels',
        'scan.aiAudit.skill.page_title',
        'scan.aiAudit.confidence',
        'scan.aiAudit.needsHumanReview',
        'scan.aiAudit.suggestion',
        'scan.aiAudit.wcag',
        'scan.aiAudit.model',
        'general.viewDetails',
        'severity.error',
        'severity.warning',
        'severity.info',
        'severity.critical',
        'severity.serious',
        'severity.moderate',
        'severity.minor',
    ];

    /**
     * Returns all inline language labels used in the accessibility module.
     *
     * @return array<string, string>
     */
    public function getInlineLanguageLabels(): array
    {
        // Labels follow the requesting editor's backend language, matching
        // what the surrounding module markup renders with.
        $languageService = $this->languageServiceFactory->createFromUserPreferences(
            $this->backendUserProvider->get(),
        );
        $labels = [];
        foreach (self::LABEL_IDS as $labelId) {
            $labels['mindfula11y.' . $labelId] = $languageService->sL(self::LANGUAGE_FILE . $labelId);
        }
        foreach (self::HEADING_TYPE_LABEL_IDS as $type) {
            $labels['mindfula11y.structure.headings.level.' . $type] = $languageService->sL(
                self::DATABASE_LANGUAGE_FILE . 'ttContent.columns.mindfula11y.headingType.items.' . $type,
            );
        }

        return $labels;
    }
}
