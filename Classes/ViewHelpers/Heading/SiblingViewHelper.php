<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\ViewHelpers\Heading;

use MindfulMarkup\MindfulA11y\ViewHelpers\AbstractHeadingViewHelper;

/**
 * Renders a heading tag at the same level as a referenced sibling heading.
 *
 * This ViewHelper uses the `siblingId` argument to fetch the heading type previously cached by a mindfula11y:heading viewhelper with a matching `relationId` argument. This only works if the sibling
 * (referenced by `siblingId`) appears before this viewhelper in the template. If the sibling comes after, the heading type cannot be determined
 * from the cache, and you must provide the `type` argument or the record arguments (`recordUid`, `recordTableName`, `recordColumnName`).
 *
 * The tag can be overridden with the `type` argument. If the request has the Mindfula11y-Structure-Analysis header set and the backend user is logged in,
 * a data attribute with the siblingId is added to the tag for analysis purposes.
 *
 * Usage example:
 * <mindfula11y:heading.sibling siblingId="{relationId}">Content</mindfula11y:heading.sibling>
 */
class SiblingViewHelper extends AbstractHeadingViewHelper
{
    /**
     * Registers all arguments for the SiblingViewHelper, including sibling reference and record information.
     *
     * @return void
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('siblingId', 'string', 'The relationId of the sibling heading.', true);
        $this->registerCommonHeadingArguments();
    }

    /**
     * Initializes the tag name for the sibling heading based on the sibling's type.
     *
     * Logic:
     * - If the `type` argument is provided, it is used directly as the tag name.
     * - Otherwise, if the `siblingId` argument refers to a sibling that appears before this ViewHelper in the template,
     *   the heading type is fetched from the runtime cache and used as the tag name.
     * - If neither of the above applies, but record arguments are provided, the heading type is resolved from the database and used as the tag name.
     * - If none of these sources are available, the default tag is used.
     *
     * Note: The cache lookup for `siblingId` only works if the referenced sibling appears before this ViewHelper in the template.
     * If the sibling comes after, you must provide the `type` or record arguments to ensure the correct tag is used.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        if (!empty($this->arguments['type'])) {
            $this->tag->setTagName($this->arguments['type']);
        } else if ($this->runtimeCache->has('mindfula11y_heading_type_' . $this->arguments['siblingId'])) {
            $this->tag->setTagName($this->runtimeCache->get('mindfula11y_heading_type_' . $this->arguments['siblingId']));
        } else if ($this->hasRecordInformation()) {
            $headingType = $this->resolveHeadingType(
                $this->arguments['recordUid'],
                $this->arguments['recordTableName'],
                $this->arguments['recordColumnName']
            );

            if (null !== $headingType) {
                $this->tag->setTagName($headingType->value);
            }
        }
    }

    /**
     * Renders the sibling heading tag, optionally adding a data attribute for structure analysis.
     *
     * If the Mindfula11y-Structure-Analysis header is set and the backend user is logged in,
     * adds a data-mindfula11y-sibling-id attribute to the tag for analysis purposes.
     *
     * @return string The rendered HTML for the sibling heading tag.
     */
    public function render(): string
    {
        if ($this->isStructureAnalysisRequest()) {
            $this->tag->addAttribute('data-mindfula11y-sibling-id', $this->arguments['siblingId']);
        }
        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }
}
