<?php
namespace AkSearchConsole\Module\Configuration;

$config = [
    'controllers' => [
        'factories' => [
            'AkSearchConsole\Controller\ScheduledSearchController' => 'VuFind\Controller\AbstractBaseFactory'
        ],
        'aliases' => [
            'scheduledsearch' => 'AkSearchConsole\Controller\ScheduledSearchController'
        ]
    ]
];

return $config;
