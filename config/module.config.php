<?php
namespace AkSearchConsole\Module\Configuration;

$config = [
    'vufind' => [
        'plugin_managers' => [
            'command' => [
                'factories' => [
                    'AkSearchConsole\Command\ScheduledSearch\NotifyCommand' => 'VuFindConsole\Command\ScheduledSearch\NotifyCommandFactory'
                ],
                'aliases' => [
                    'VuFindConsole\Command\ScheduledSearch\NotifyCommand' => 'AkSearchConsole\Command\ScheduledSearch\NotifyCommand'
                ]
            ]
        ]
    ]    
];

return $config;
