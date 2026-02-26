<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

namespace MindfulMarkup\MindfulA11y\Form\Element;

use Exception;
use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Class InputAltElement.
 *
 * The InputTextElement renders a html input field with the type "text" attribute specifally
 * for alternative text of images. It renders a button to generate the alternative text
 * using the OpenAI API. It is a modification of the InputTextElement class.
 */
class InputAltElement extends AbstractFormElement
{
    /**
     * Default field information enabled for this element.
     *
     * @var array
     */
    protected $defaultFieldInformation = [
        'tcaDescription' => [
            'renderType' => 'tcaDescription',
        ],
    ];

    /**
     * Default field wizards enabled for this element.
     *
     * @var array
     */
    protected $defaultFieldWizard = [
        'localizationStateSelector' => [
            'renderType' => 'localizationStateSelector',
        ],
        'otherLanguageContent' => [
            'renderType' => 'otherLanguageContent',
            'after' => [
                'localizationStateSelector',
            ],
        ],
        'defaultLanguageDifferences' => [
            'renderType' => 'defaultLanguageDifferences',
            'after' => [
                'otherLanguageContent',
            ],
        ],
    ];

    public function __construct(
        protected readonly IconFactory $iconFactory,
        protected readonly OpenAIService $openAIService,
        protected readonly ResourceFactory $resourceFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly PermissionService $permissionService,
    ) {}

    /**
     * This will render a single-line input form field, possibly with various control/validation features
     *
     * @return array As defined in initializeResultArray() of AbstractNode
     */
    public function render(): array
    {
        $table = $this->data['tableName'];
        $row = $this->data['databaseRow'];
        $fieldName = $this->data['fieldName'];
        $parameterArray = $this->data['parameterArray'];
        $resultArray = $this->initializeResultArray();
        $config = $parameterArray['fieldConf']['config'];

        $languageUid = 0;
        if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField']) && !empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
            $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
            $languageUid = (int)((is_array($row[$languageField] ?? null) ? ($row[$languageField][0] ?? 0) : $row[$languageField]) ?? 0);
        }

        $itemValue = $parameterArray['itemFormElValue'];
        $width = $this->formMaxWidth(
            MathUtility::forceIntegerInRange($config['size'] ?? $this->defaultInputWidth, $this->minimumInputWidth, $this->maxInputWidth)
        );
        $fieldId = StringUtility::getUniqueId('formengine-input-');
        $itemName = (string)$parameterArray['itemFormElName'];
        $renderedLabel = $this->renderLabel($fieldId);

        $fieldInformationResult = $this->renderFieldInformation();
        $fieldInformationHtml = $fieldInformationResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldInformationResult, false);

        if ($config['readOnly'] ?? false) {
            $html = [];
            $html[] = $renderedLabel;
            $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
            $html[] =   $fieldInformationHtml;
            $html[] =   '<div class="form-wizards-wrap">';
            $html[] =       '<div class="form-wizards-item-element">';
            $html[] =           '<div class="form-control-wrap" style="max-width: ' . $width . 'px">';
            $html[] =               '<input class="form-control" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($itemName) . '" value="' . htmlspecialchars((string)$itemValue) . '" type="text" disabled>';
            $html[] =           '</div>';
            $html[] =       '</div>';
            $html[] =   '</div>';
            $html[] = '</div>';
            $resultArray['html'] = implode(LF, $html);
            return $resultArray;
        }

        $languageService = $this->getLanguageService();

        // @todo: The whole eval handling is a mess and needs refactoring
        $evalList = GeneralUtility::trimExplode(',', $config['eval'] ?? '', true);
        foreach ($evalList as $func) {
            // @todo: This is ugly: The code should find out on it's own whether an eval definition is a
            // @todo: keyword like "date", or a class reference. The global registration could be dropped then
            // Pair hook to the one in \TYPO3\CMS\Core\DataHandling\DataHandler::checkValue_input_Eval()
            if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tce']['formevals'][$func])) {
                if (class_exists($func)) {
                    $evalObj = GeneralUtility::makeInstance($func);
                    if (method_exists($evalObj, 'deevaluateFieldValue')) {
                        $_params = [
                            'value' => $itemValue,
                        ];
                        $itemValue = $evalObj->deevaluateFieldValue($_params);
                    }
                    $resultArray = $this->resolveJavaScriptEvaluation($resultArray, $func, $evalObj);
                }
            }
        }

        if ($config['nullable'] ?? false) {
            $evalList[] = 'null';
        }

        $formEngineInputParams = [
            'field' => $itemName,
        ];
        // The `is_in` constraint requires two parameters to work: the "eval" setting and a configuration of the
        // actually allowed characters
        if (in_array('is_in', $evalList, true)) {
            if (($config['is_in'] ?? '') !== '') {
                $formEngineInputParams['is_in'] = $config['is_in'];
            } else {
                $evalList = array_diff($evalList, ['is_in']);
            }
        } else {
            unset($config['is_in']);
        }
        if ($evalList !== []) {
            $formEngineInputParams['evalList'] = implode(',', $evalList);
        }

        $attributes = [
            'value' => '',
            'id' => $fieldId,
            'class' => implode(' ', [
                'form-control',
                'form-control-clearable',
                't3js-clearable',
            ]),
            'data-formengine-validation-rules' => $this->getValidationDataAsJsonString($config),
            'data-formengine-input-params' => (string)json_encode($formEngineInputParams, JSON_THROW_ON_ERROR),
            'data-formengine-input-name' => $itemName,
        ];

        $maxLength = (int)($config['max'] ?? 0);
        if ($maxLength > 0) {
            $attributes['maxlength'] = (string)$maxLength;
        }
        $minLength = (int)($config['min'] ?? 0);
        if ($minLength > 0 && ($maxLength === 0 || $minLength <= $maxLength)) {
            $attributes['minlength'] = (string)$minLength;
        }

        if ('sys_file_reference' === $table) {
            $fileUid = isset($row['uid_local'][0]['uid']) ? (int)$row['uid_local'][0]['uid'] : null;
        } elseif ('sys_file_metadata' === $table) {
            $fileUid = isset($row['file'][0]) ? (int)$row['file'][0] : null;
        } else {
            $fileUid = null;
        }

        if ($fileUid !== null) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (Exception $e) {
                $file = null;
            }
        } else {
            $file = null;
        }

        /**
         * In class TcaInputPlaceholders TYPO3 hardcodes the field types that get their placeholder resolved. We get the alternative text otherwise here.
         */
        $canReadPlaceholder = $this->permissionService->checkTableReadAccess('sys_file_metadata')
            && $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative']);
        $config['placeholder'] = $canReadPlaceholder && null !== $file ? $file->getProperty('alternative') : '';

        if (!empty($config['placeholder'])) {
            $attributes['placeholder'] = trim($config['placeholder']);
        }
        if (isset($config['autocomplete'])) {
            $attributes['autocomplete'] = empty($config['autocomplete']) ? 'new-' . $fieldName : 'on';
        }

        $fieldControlResult = $this->renderFieldControl();
        $fieldControlHtml = $fieldControlResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldControlResult, false);

        $fieldWizardResult = $this->renderFieldWizard();
        $fieldWizardHtml = $fieldWizardResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldWizardResult, false);

        $thisAltId = 't3js-form-field-alt-id' . StringUtility::getUniqueId();
        $generateButtonLabel = $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.button');
        $canGenerate = null !== $file && $this->openAIService->isFileExtSupported($file->getExtension()) && $this->openAIService->isEnabledAndConfigured() && $GLOBALS['BE_USER']->check('modules', 'mindfula11y_accessibility');
        $mainFieldHtml = [];
        $mainFieldHtml[] = '<div class="form-control-wrap" style="max-width: ' . $width . 'px" id="' . htmlspecialchars($thisAltId) . '">';
        $mainFieldHtml[] =      '<div class="form-wizards-wrap">';
        $mainFieldHtml[] =          '<div class="form-wizards-item-element">';
        if ($canGenerate) {
            $mainFieldHtml[] =              '<div class="input-group">';
            $mainFieldHtml[] =                  '<input type="text" ' . GeneralUtility::implodeAttributes($attributes, true) . ' />';
            $mainFieldHtml[] =                  '<input type="hidden" name="' . $itemName . '" value="' . htmlspecialchars((string)$itemValue) . '" />';
            $mainFieldHtml[] =                  '<button class="btn btn-default t3js-form-field-alt-generate" type="button" aria-label="' . htmlspecialchars($generateButtonLabel) . '" title="' . htmlspecialchars($generateButtonLabel) . '">';
            $mainFieldHtml[] =                      $this->iconFactory->getIcon('actions-refresh', IconSize::SMALL)->render();
            $mainFieldHtml[] =                  '</button>';
            $mainFieldHtml[] =              '</div>';
        } else {
            $mainFieldHtml[] =              '<input type="text" ' . GeneralUtility::implodeAttributes($attributes, true) . ' />';
            $mainFieldHtml[] =              '<input type="hidden" name="' . $itemName . '" value="' . htmlspecialchars((string)$itemValue) . '" />';
        }
        $mainFieldHtml[] =          '</div>';
        if (!empty($fieldControlHtml)) {
            $mainFieldHtml[] =      '<div class="form-wizards-item-aside form-wizards-item-aside--field-control">';
            $mainFieldHtml[] =          '<div class="btn-group">';
            $mainFieldHtml[] =              $fieldControlHtml;
            $mainFieldHtml[] =          '</div>';
            $mainFieldHtml[] =      '</div>';
        }
        if (!empty($fieldWizardHtml)) {
            $mainFieldHtml[] = '<div class="form-wizards-item-bottom">';
            $mainFieldHtml[] = $fieldWizardHtml;
            $mainFieldHtml[] = '</div>';
        }
        $mainFieldHtml[] =  '</div>';
        $mainFieldHtml[] = '</div>';
        $mainFieldHtml = implode(LF, $mainFieldHtml);

        $nullControlNameEscaped = htmlspecialchars('control[active][' . $table . '][' . $this->data['databaseRow']['uid'] . '][' . $fieldName . ']');

        $fullElement = $mainFieldHtml;
        if ($this->hasNullCheckboxButNoPlaceholder()) {
            $checked = $itemValue !== null ? ' checked="checked"' : '';
            $fullElement = [];
            $fullElement[] = '<div class="t3-form-field-disable"></div>';
            $fullElement[] = '<div class="form-check t3-form-field-eval-null-checkbox">';
            $fullElement[] =     '<input type="hidden" name="' . $nullControlNameEscaped . '" value="0" />';
            $fullElement[] =     '<input type="checkbox" class="form-check-input" name="' . $nullControlNameEscaped . '" id="' . $nullControlNameEscaped . '" value="1"' . $checked . ' />';
            $fullElement[] =     '<label class="form-check-label" for="' . $nullControlNameEscaped . '">';
            $fullElement[] =         $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.nullCheckbox');
            $fullElement[] =     '</label>';
            $fullElement[] = '</div>';
            $fullElement[] = $mainFieldHtml;
            $fullElement = implode(LF, $fullElement);
        } elseif ($this->hasNullCheckboxWithPlaceholder()) {
            $checked = $itemValue !== null ? ' checked="checked"' : '';
            $placeholder = $shortenedPlaceholder = trim((string)($config['placeholder'] ?? ''));
            if ($placeholder !== '') {
                $shortenedPlaceholder = GeneralUtility::fixed_lgd_cs($placeholder, 20);
                if ($placeholder !== $shortenedPlaceholder) {
                    $overrideLabel = sprintf(
                        $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.placeholder.override'),
                        '<span title="' . htmlspecialchars($placeholder) . '">' . htmlspecialchars($shortenedPlaceholder) . '</span>'
                    );
                } else {
                    $overrideLabel = sprintf(
                        $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.placeholder.override'),
                        htmlspecialchars($placeholder)
                    );
                }
            } else {
                $overrideLabel = $languageService->sL(
                    'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.placeholder.override_not_available'
                );
            }
            $fullElement = [];
            $fullElement[] = '<div class="form-check t3js-form-field-eval-null-placeholder-checkbox">';
            $fullElement[] =     '<input type="hidden" name="' . $nullControlNameEscaped . '" value="0" />';
            $fullElement[] =     '<input type="checkbox" class="form-check-input" name="' . $nullControlNameEscaped . '" id="' . $nullControlNameEscaped . '" value="1"' . $checked . ' />';
            $fullElement[] =     '<label class="form-check-label" for="' . $nullControlNameEscaped . '">';
            $fullElement[] =         $overrideLabel;
            $fullElement[] =     '</label>';
            $fullElement[] = '</div>';
            $fullElement[] = '<div class="t3js-formengine-placeholder-placeholder">';
            $fullElement[] =    '<div class="form-control-wrap" style="max-width:' . $width . 'px">';
            $fullElement[] =        '<input type="text" class="form-control" disabled="disabled" value="' . htmlspecialchars($shortenedPlaceholder) . '" />';
            $fullElement[] =    '</div>';
            $fullElement[] = '</div>';
            $fullElement[] = '<div class="t3js-formengine-placeholder-formfield">';
            $fullElement[] =    $mainFieldHtml;
            $fullElement[] = '</div>';
            $fullElement = implode(LF, $fullElement);
        }

        $resultArray['html'] = $renderedLabel . '
            <div class="formengine-field-item t3js-formengine-field-item">
                ' . $fieldInformationHtml . $fullElement . '
            </div>';

        if ($canGenerate) {
            $backendUser = $GLOBALS['BE_USER'];
            $generateAltTextDemand = new GenerateAltTextDemand(
                $backendUser->user['uid'],
                (int)$this->data['effectivePid'],
                $languageUid,
                $backendUser->workspace,
                $table,
                (int)$this->data['databaseRow']['uid'],
                $fileUid ?? 0,
                [$fieldName]
            );

            $this->pageRenderer->addInlineLanguageLabelArray([
                'mindfula11y.altText.generate.button' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.button'),
                'mindfula11y.altText.generate.loading' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.loading'),
                'mindfula11y.altText.generate.success' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.success'),
                'mindfula11y.altText.generate.success.description' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.success.description'),
                'mindfula11y.altText.generate.error.unknown' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.unknown'),
                'mindfula11y.altText.generate.error.unknown.description' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.unknown.description'),
                'mindfula11y.altText.generate.error.openAIConnection' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.openAIConnection'),
                'mindfula11y.altText.generate.error.openAIConnection.description' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.openAIConnection.description'),
            ]);
            $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
                '@mindfulmarkup/mindfula11y/input-alt-element-service.js'
            )->instance('#' . $thisAltId, $generateAltTextDemand->toArray());
        }

        return $resultArray;
    }
}
