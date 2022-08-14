<?php

return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'phpArray',
                'base_dir' => PROJECT_DIR.'/resources/languages/',
                'pattern' => '%s/logistics.php',
                'text_domain' => 'newwebpay',
            ],
        ],
    ],
];
