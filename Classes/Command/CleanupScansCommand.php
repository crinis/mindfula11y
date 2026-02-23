<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the General Public License as published by
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

namespace MindfulMarkup\MindfulA11y\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Command for cleaning up old accessibility scan IDs.
 */
#[AsCommand(
    name: 'mindfula11y:cleanupscans',
    description: 'Clean up old accessibility scan IDs based on a configurable age threshold (default: 30 days).',
)]
class CleanupScansCommand extends Command
{
    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->addOption(
            'seconds',
            's',
            InputOption::VALUE_OPTIONAL,
            LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:command.cleanupScanIds.option.seconds'),
            2592000
        );
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seconds = (int)$input->getOption('seconds');

        $cutoffTimestamp = time() - $seconds;

        $io->info(sprintf(
            LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:command.cleanupScanIds.processing'),
            $seconds,
            date('Y-m-d H:i:s', $cutoffTimestamp)
        ));

        // Perform cleanup in a single UPDATE query
        $updateQuery = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $updateQuery->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $cleanedCount = $updateQuery
            ->update('pages')
            ->set('tx_mindfula11y_scanid', '')
            ->set('tx_mindfula11y_scanupdated', 0)
            ->where(
                $updateQuery->expr()->and(
                    $updateQuery->expr()->isNotNull('tx_mindfula11y_scanid'),
                    $updateQuery->expr()->neq('tx_mindfula11y_scanid', $updateQuery->createNamedParameter('')),
                    $updateQuery->expr()->lt('tx_mindfula11y_scanupdated', $updateQuery->createNamedParameter($cutoffTimestamp))
                )
            )
            ->executeStatement();

        if ($cleanedCount === 0) {
            $io->success(LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:command.cleanupScanIds.noOldScans'));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:command.cleanupScanIds.success'),
            $cleanedCount
        ));

        return Command::SUCCESS;
    }
}