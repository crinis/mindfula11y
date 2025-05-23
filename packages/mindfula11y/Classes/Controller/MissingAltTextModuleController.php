<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;


/**
 * Class MissingAltTextModuleController.
 *
 * This controller handles the backend module for listing records with missing alternative text.
 */
#[AsController]
class MissingAltTextModuleController extends AbstractModuleController
{
    /**
     * The amount of page levels to draw records from.
     * 
     * @var int
     */
    protected int $pageLevels = 1;

    /**
     * The selected table name to check for missing alternative text.
     * 
     * @var string
     */
    protected string $tableName = 'tt_content';

    /**
     * Constructor.
     * 
     * @param AltTextFinderService $altTextFinderService
     */
    public function __construct(
        protected readonly AltTextFinderService $altTextFinderService,
    ) {}

    /**
     * Main action to list records with missing alternative text.
     * 
     * This action retrieves and lists all records with file references that are missing alternative text.
     * 
     * @param ServerRequestInterface $request
     * 
     * @return ResponseInterface
     * 
     * @throws MethodNotAllowedException If wrong HTTP method is used.
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->initializeAction($request, $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:mlang_tabs_tab'))) {
            return $this->view->renderResponse('Backend/Info')
                ->withStatus(403);
        }

        $this->pageLevels = (int)$this->moduleData->get('pageLevels', 1);

        $pageLevelsMenu = $this->buildPageLevelsMenu();
        $this->view->getDocHeaderComponent()->getMenuRegistry()->addMenu($pageLevelsMenu);

        $this->view->assignMultiple([
            'pageId' => $this->pageId,
            'languageId' => $this->languageId,
            'tables' => $this->altTextFinderService->getTablesWithFiles($this->pageTsConfig),
            'pageLevels' => $this->pageLevels,
        ]);

        return $this->view->renderResponse('Backend/MissingAltText');
    }

    /**
     * Build table menu for the module.
     *
     * @return Menu
     */
    protected function buildTableMenu(): Menu
    {
        $tables = $this->altTextFinderService->getTablesWithFiles($this->pageTsConfig);

        $menu = $this->view->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yMissingAltTextTable')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.tables'
            );
        foreach ($tables as $tableName => $columns) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getTableTitle($tableName)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'tableName' => $tableName,
                    ]
                )
            )->setActive($tableName === $this->tableName);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build a page level menu for the module.
     * 
     * @return Menu
     */
    protected function buildPageLevelsMenu(): Menu
    {
        $menu = $this->view->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yMissingAltTextPageLevels')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.pageLevels'
            );

        foreach ([1, 5, 10, 99] as $pageLevels) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.pageLevels.' . $pageLevels)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'pageLevels' => $pageLevels,
                    ]
                )
            )->setActive($pageLevels === $this->pageLevels);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Get the URI for a menu item as a string.
     * 
     * @param array $changedParams The changed parameters.
     * 
     * @return string
     */
    protected function getMenuItemUri(array $changedParams): string
    {
        $params = array_replace([
            'id' => $this->pageId,
            'languageId' => $this->languageId,
            'pageLevels' => $this->pageLevels,
            'tableName' => $this->tableName,
        ], $changedParams);

        return (string)$this->uriBuilder->buildUriFromRoute(
            'mindfula11y_missingalttext',
            $params
        );
    }

        /**
     * Get title of a table from TCA.
     * 
     * @param string $tableName The name of the table.
     * 
     * @return string
     */
    protected function getTableTitle(string $tableName): string
    {
        if (isset($GLOBALS['TCA'][$tableName]['ctrl']['title'])) {
            return $this->getLanguageService()->sL($GLOBALS['TCA'][$tableName]['ctrl']['title']);
        }
        return $tableName;
    }
}
