<?php

/**
 * Console command: CSV exporter
 *
 * PHP version 8
 *
 * Copyright (C) AK Bibliothek Wien 2026.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Console
 * @author   Michael Birkner-Tröger <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */

namespace AkSearchConsole\Command\Export;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VuFindSearch\Backend\Solr\Command\RawJsonSearchCommand;
use VuFind\I18n\Translator\TranslatorAwareInterface;
use VuFind\I18n\Translator\TranslatorAwareTrait;
use Laminas\Log\LoggerAwareInterface;
use VuFind\Log\LoggerAwareTrait;
use Laminas\Stdlib\Parameters;

/**
 * Console command: CSV exporter
 *
 * @category VuFind
 * @package  Console
 * @author   Michael Birkner-Tröger <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
#[AsCommand(
    name: 'export/export_csv',
    description: 'CSV exporter'
)]
class ExportCsvCommand extends Command implements TranslatorAwareInterface,
    LoggerAwareInterface
{
    use TranslatorAwareTrait;
    use LoggerAwareTrait;

    /** 
     * Search service for executing search commands
     * 
     * @var \VuFindSearch\Service
     * 
     */
    protected $searchService;

    /**
     * Plugin manager for creating record drivers
     * 
     * @var \VuFind\RecordDriver\PluginManager
     */
    protected $recordFactory;

    /**
     * Results plugin manager
     *
     * @var \VuFind\Search\Results\PluginManager
     */
    protected $resultsManager;

    /**
     * Constructor
     *
     * @param \VuFindSearch\Service $searchService Search service for executing
     * search commands
     * @param \VuFind\RecordDriver\PluginManager $recordFactory Factory for
     * creating record drivers
     * @param \VuFind\Search\Results\PluginManager $resultsManager Results plugin
     * manager
     * @param string|null $name The name of the command (optional)
     */
    public function __construct($searchService, $recordFactory, $resultsManager,
        $name = null
    ) {
        $this->searchService = $searchService;
        $this->recordFactory = $recordFactory;
        $this->resultsManager = $resultsManager;
        parent::__construct($name);
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setHelp('Exports records to CSV file.')
            ->addArgument(
                'jobId',
                InputArgument::REQUIRED,
                'Unique Job ID for status tracking'
            )
            ->addArgument(
                'paramsFile',
                InputArgument::REQUIRED,
                'File containing serialized request params'
            )
            ->addArgument(
                'statusFile',
                InputArgument::REQUIRED,
                'File to write export status'
            )
            ->addArgument(
                'outputFile',
                InputArgument::REQUIRED,
                'Target CSV file path'
            )->addArgument(
                'pidFile',
                InputArgument::OPTIONAL,
                'File to write process id'
            )->addArgument(
                'cancelFile',
                InputArgument::OPTIONAL,
                'File used as cancel marker'
            );
    }

    /**
     * Run the command.
     * 
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
     *
     * @return int 0 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get input arguments
        $jobId = $input->getArgument('jobId');
        $paramsFile = $input->getArgument('paramsFile');
        $statusFile = $input->getArgument('statusFile');
        $outputFile = $input->getArgument('outputFile');
        $pidFile = $input->getArgument('pidFile');
        $cancelFile = $input->getArgument('cancelFile');
        
        if (!empty($pidFile)) {
            @file_put_contents($pidFile, (string)getmypid());
        }

        $cancelled = function () use ($cancelFile) {
            return !empty($cancelFile) && file_exists($cancelFile);
        };

        // Check if params file exists
        if (!file_exists($paramsFile)) {
            $output->writeln("Error: Params file not found.");
            return 1;
        }

        // Unserialize request from params file
        $request = unserialize(file_get_contents($paramsFile));

        // Get the active tab from the request if given (this is needed to
        // determine if we are exporting from sowidok or not, since the metadata
        // is different). This must be the ID of the active tab, e. g.
        // "Solr:sowidok" for Sowidok. The ID can be found in config.ini under
        // [SearchTabs] (to the left of the "=" sign).
        $activeTab = $request['activeTab'] ?? null;
        $isSowidok = ($activeTab == 'Solr:sowidok') ? true : false;

        // Get OS
        $os = $this->getOs();

        // Get CSV separator based on OS
        $sep = $this->getCsvSeparator($os);
        
        // Setup file for exported data
        $file = fopen($outputFile, 'w');

        // Define the headings for the CSV
        $headings = [];
        if ($isSowidok) {
            $headings[] = ['recordId', 'Title', 'Authors', 'Publication_Place',
                'Publisher', 'Date', 'StorageSubject', 'MainSubject',
                'FirstSubSubject'];
        } else {
            $headings[] = ['recordId', 'ACNo', 'Title', 'Authors',
                'Publication_Place', 'Publisher', 'Date', 'Holdings'];
        }
        
        // Translate headings
        $headingsTranslated[] = $this->getCsvTranslation(
            $headings, ($os === 'win') ? true : false
        );

        // Write headings to file
        $this->writeToFile($headingsTranslated, $file, $os, $sep);

        // Fetch records in batches using cursorMark
        $prevCursorMark = '';
        $cursorMark = '*';

        // Build query + backend params via core flow (same logic as GUI search)
        $requestParams = new Parameters(is_array($request) ? $request : []);
        $resultsObj = $this->resultsManager->get('Solr');
        $paramsObj = $resultsObj->getParams();
        $paramsObj->initFromRequest($requestParams);
        $query = $paramsObj->getQuery();
        $baseBackendParams = $paramsObj->getBackendParameters();
        $batchSize = 200;
        $rows = 1073741823;
        $recordsProcessed = 0;
        while ($cursorMark !== $prevCursorMark) {
            if ($cancelled()) {
                fclose($file);
                @unlink($outputFile);
                @file_put_contents($statusFile, json_encode([
                    'status' => 'cancelled',
                    'msg' => $this->translate('export_search_results_cancelled')
                ]));
                @unlink($paramsFile);
                if (!empty($pidFile)) {
                    @unlink($pidFile);
                }
                if (!empty($cancelFile)) {
                    @unlink($cancelFile);
                }
                return 0;
            }

            // Create search params with cursorMark
            $params = clone $baseBackendParams;
            $params->set('sort', 'id asc');   // required for cursorMark
            $params->set('timeAllowed', -1);  // cursorMark compatibility
            $params->set('cursorMark', $cursorMark);

            // Create raw json search command
            $command = new RawJsonSearchCommand(
                'Solr',
                $query,
                0,
                min([$batchSize, $rows]),
                $params
            );

            // Execute search and get results
            $results = $this->searchService->invoke($command)->getResult();

            // Get total number of results
            $total = $results->response->numFound;
            
            // Check if there are no results
            if (empty($results->response->docs)) {
                break;
            }

            // Get the records (docs) from the results
            $records = $results->response->docs;

            // Break if we have too much records
            if (count($records) >= $rows) {
                break;
            }

            // Convert Solr docs to RecordDriver objects
            $solrMarcRecords = [];
            foreach ($results->response->docs as $doc) {
                // Convert stdClass to array if needed
                $data = (array)$doc;
                $driver = $this->recordFactory->get('SolrMarc');
                $driver->setRawData($data);
                $driver->setSourceIdentifiers('Solr');
                $solrMarcRecords[] = $driver;
                $recordsProcessed++;
            }

            // Write metadata to CSV file
            $this->writeToFile($this->getMetadata($isSowidok, $solrMarcRecords),
                $file, $os, $sep);
            
            // Update cursorMark for next iteration
            $prevCursorMark = $cursorMark;
            $cursorMark = $results->nextCursorMark;

            // Update progress in status file for frontend
            file_put_contents($statusFile, json_encode([
                'status' => 'processing',
                'count' => $recordsProcessed,
                'total' => $total,
                'percent' =>
                    ($total > 0) ? round(($recordsProcessed / $total) * 100) : 0
            ]));
            
            set_time_limit(0);
            gc_collect_cycles();

            usleep(100000); // 0.1 second pause to reduce server load
        }

        // Close file
        fclose($file);

        // Final Success Status
        file_put_contents($statusFile, json_encode([
            'status' => 'done',
            'filename' => basename($outputFile),
        ]));

        @unlink($paramsFile);
        if (!empty($pidFile)) {
            @unlink($pidFile);
        }
        if (!empty($cancelFile)) {
            @unlink($cancelFile);
        }

        return 0;
    }

    /**
     * Get metadata from search results that should be exported
     *
     * @param bool $isSowidok Indicates if the data source is Sowidok
     * @param array $records An array of record driver objects
     * 
     * @return array An array of metadata
     */
    protected function getMetadata($isSowidok, $records) {
        $metadata = [];
        foreach ($records as $key => $record) {
            $recordId = $record->getUniqueID();
            $acNoRaw = $record->getAcNo();
            if (is_array($acNoRaw)) {
                $acNo = (!empty($acNoRaw)) ? implode('; ', $acNoRaw) : '';
            } else {
                $acNo = (!empty($acNoRaw)) ? $acNoRaw : '';
            }
            
            $title = $record->getTitle();
            $authorsRaw = $record->getContributors();
            $authors = (!empty($authorsRaw)) ? implode('; ', $authorsRaw) : '';
            $datesPubRaw = $record->getPublicationDates();
            $datesSpanRaw = $record->getDateSpan();
            $dates = (!empty($datesPubRaw))
                ? implode('; ', $datesPubRaw)
                : (
                    (!empty($datesSpanRaw))
                        ? implode('; ', $datesSpanRaw)
                        : []
                );
            $publishersRaw = $record->getPublishers();
            $publishers = (!empty($publishersRaw))
                ? implode('; ', $publishersRaw)
                : '';
            $placesRaw = $record->getPlacesOfPublication();
            $places = (!empty($placesRaw)) ? implode('; ', $placesRaw) : '';

            // Get holding or Sowidok data from the Marc record
            $allHoldingData = [];
            $allHoldingDataStr = null;
            $ablageschlagwort = null;
            $hauptschlagwort = null;
            $nebenschlagwort = null;
            if ($isSowidok) {
                $ablageschlagwort = $record->getSowidokAblageschlagwort();
                $ablageschlagwort = $ablageschlagwort[0] ?? null;
                $hauptschlagwort = $record->getSowidokHauptschlagwort();
                $hauptschlagwort = $hauptschlagwort[0] ?? null;
                $nebenschlagwort = $record->getSowidokNebenschlagwort();
                $nebenschlagwort = $nebenschlagwort[0] ?? null;
            } else {
                $holdingDataRaw = $record->getExportData();
                foreach ($holdingDataRaw as $holPid => $holdingData) {
                    $holdingData = array_filter($holdingData);
                    $location = ($holdingData['location'] ?? false)
                        ? $this->translate('Location').': '
                            .$holdingData['location']
                        : null;
                    $callnumber = ($holdingData['callnumber'] ?? false)
                        ? $this->translate('Call Number').': '
                            .$holdingData['callnumber']
                        : null;
                    $holding = ($holdingData['holding'] ?? false)
                        ? $this->translate('summarizedHoldings').': '
                            .$holdingData['holding']
                        : null;
                    $gaps = ($holdingData['gaps'] ?? false)
                        ? $this->translate('gaps').': '.$holdingData['gaps']
                        : null;
                    $data = implode(', ', array_filter(
                        [$location, $callnumber, $holding, $gaps]
                    ));
                    $allHoldingData[] = $data;                    
                }

                if (!empty($allHoldingData)) {
                    $allHoldingDataStr = implode('; ', $allHoldingData);
                }
            }

            if ($isSowidok) {
                $metadata[] = [
                    'recordId' => $recordId,
                    'title' => $title,
                    'authors' => $authors,
                    'places' => $places,
                    'publishers' => $publishers,
                    'dates' => $dates,
                    'ablageschlagwort' => $ablageschlagwort,
                    'hauptschlagwort' => $hauptschlagwort,
                    'nebenschlagwort' => $nebenschlagwort,
                ];
            } else {
                $metadata[] = [
                    'recordId' => $recordId,
                    'acNo' => $acNo,
                    'title' => $title,
                    'authors' => $authors,
                    'places' => $places,
                    'publishers' => $publishers,
                    'dates' => $dates,
                    'holdings' => $allHoldingDataStr
                ];
            }
        }

        return $metadata;
    }

    /**
     * Write metadata to CSV file
     *
     * @param array  $values The values to write as CSV
     * @param stream $file   The CSV file stream
     * @param string $os     The current OS (win, mac, linux)
     * @param string $sep    Separator for CSV
     * 
     * @return void
     */
    protected function writeToFile($values, $file, $os, $sep) {
        $csvValues = [];
        foreach ($values as $key => $values) {

            foreach ($values as $colName => $value) {
                // If null value is given, set to empty string
                $value = (!empty($value)) ? $value : '';

                // Add (Windows encoded) value to result array
                $csvValues[$key][$colName] = ($os === 'win')
                    ? $this->getWinEncodedText($value)
                    : $value;
            }
        }

        try {
            foreach ($csvValues as $csvValue) {
                fputcsv($file, array_values($csvValue), $sep, '"');
            }
        } catch (\Exception $e) {
            throw new \VuFind\Exception\ILS(
                'Error while exporting search resuls | ' .$e->getMessage()
            );
        }        
    }

    /**
     * Get the current OS (win, mac, linux)
     *
     * @return string The current OS: win, mac or linux
     */
    protected function getOs() {
        // Try to get the users operating system
        $ua = (PHP_SAPI === 'cli') ? 'linux' : $_SERVER['HTTP_USER_AGENT'];
        $os = 'win'; // Default. Most OS are Windows.

        if (
            stripos($ua, 'linux') !== false
            || stripos($ua, 'CrOS') !== false
            || stripos($ua, 'BSD') !== false
            || stripos($ua, 'SunOS') !== false
            || stripos($ua, 'UNIX') !== false
        ) {
            $os = 'linux';
        } else if (stripos($ua, 'mac') !== false) {
            $os = 'mac';
        } else if (stripos($ua, 'windows') !== false) {
            $os = 'win';
        }

        return $os;
    }

    /**
     * Get CSV separator based on OS
     *
     * @param string $os The OS (win, mac or linux)
     * @return string The CSV separator as string
     */
    protected function getCsvSeparator($os) {
        $sep = ',';  // Default. In Excel (Win) we have to use semi-colon ;
        if ($os == 'win') {
            $sep = ';';
        }
        return $sep;
    }

    /**
     * Translate CSV headings and, if neccessary, encode them for Windows.
     *
     * @param  array     $translate   Associative array of headings
     * @param  boolean   $convert     Convert to Windows encoding (UTF-16LE) if 
     *                                true
     * 
     * @return array                  Acssociative array of translated headings
     */
    protected function getCsvTranslation($translate, $convertToWin) {
        $csvHeadings = [];
        $translate = $translate[0];

        if ($convertToWin) {
            foreach ($translate as $key => $text) {
                $csvHeadings[$key] = $this->getWinEncodedText(
                    $this->translate($text)
                );
            }
        } else {
            foreach ($translate as $key => $text) {
                $csvHeadings[$key] = $this->translate($text);
            }
        }

        return $csvHeadings;
    }

    /**
     * Encode from UTF-8 to UTF-16LE. This is for CSV export on Windows.
     *
     * @param string  $text The text to encode from UTF-8 to UTF-16LE
     * 
     * @return string       The UTF-16LE encoded text
     */
    protected function getWinEncodedText($text) {
        return mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    }
}
