<?php

declare(strict_types=1);

/*
 * A custom heading-bearing table for the heading ViewHelper tests: it has its
 * own heading column but deliberately NO tx_mindfula11y_childheadingtype
 * column — the documented integration shape of third-party tables, which the
 * child-type record fallback must not query.
 */
return [
    'ctrl' => [
        'title' => 'A11y test content',
        'label' => 'headingtype',
    ],
    'columns' => [
        'headingtype' => [
            'label' => 'Heading type',
            'config' => [
                'type' => 'input',
                'max' => 10,
            ],
        ],
    ],
    'types' => [
        ['showitem' => 'headingtype'],
    ],
];
