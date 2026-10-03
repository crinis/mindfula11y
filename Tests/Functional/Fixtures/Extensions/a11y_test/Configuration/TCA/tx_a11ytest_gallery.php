<?php

declare(strict_types=1);

/*
 * A third-party table with a file field but deliberately NO language field:
 * the Missing Alternative Texts list counts its "All languages" (-1) file
 * references for every language, and the Generate action must agree.
 */
return [
    'ctrl' => [
        'title' => 'A11y test gallery',
        'label' => 'title',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
            ],
        ],
        'images' => [
            'label' => 'Images',
            'config' => [
                'type' => 'file',
                'allowed' => 'common-image-types',
            ],
        ],
    ],
    'types' => [
        ['showitem' => 'title, images'],
    ],
];
