<?php
namespace AkSearchConsole\Module\Configuration;

$config = [
    'vufind' => [
        'plugin_managers' => [
            'command' => [
                'factories' => [
                    'AkSearchConsole\Command\Export\ExportCsvCommand' => 'AkSearchConsole\Command\Export\ExportCsvCommandFactory',
                    'AkSearchConsole\Command\ScheduledSearch\NotifyCommand' => 'VuFindConsole\Command\ScheduledSearch\NotifyCommandFactory'
                ],
                'aliases' => [
                    'export/export_csv' => 'AkSearchConsole\Command\Export\ExportCsvCommand',
                    'VuFindConsole\Command\ScheduledSearch\NotifyCommand' => 'AkSearchConsole\Command\ScheduledSearch\NotifyCommand'
                ]
            ]
        ]
    ]    
];

return $config;
