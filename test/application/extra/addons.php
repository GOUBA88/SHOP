<?php

return [
    'autoload' => false,
    'hooks' => [
        'wipecache_after' => [
            'tinymce',
        ],
        'set_tinymce' => [
            'tinymce',
        ],
        'upgrade' => [
            'wanlshop',
        ],
        'app_init' => [
            'wanlshop',
        ],
        'user_sidenav_after' => [
            'wanlshop',
        ],
    ],
    'route' => [],
    'priority' => [],
    'domain' => '',
];
