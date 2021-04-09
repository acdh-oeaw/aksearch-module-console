<?php
/**
 * AK: Extended console command: notify users of scheduled searches.
 *
 * PHP version 7
 *
 * Copyright (C) AK Bibliothek Wien 2021.
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
 * @category AKsearch
 * @package  Console
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
namespace AkSearchConsole\Command\ScheduledSearch;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * AK: Extending console command: notify users of scheduled searches.
 *
 * @category AKsearch
 * @package  Console
 * @author   Michael Birkner <michael.birkner@akwien.at>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class NotifyCommand extends \VuFindConsole\Command\ScheduledSearch\NotifyCommand
{
    /**
     * The name of the command (the part after "public/index.php")
     *
     * AK: Don't remove this! Also when it is already set (to the same value ) in the
     * parent class
     *
     * @var string
     */
    protected static $defaultName = 'scheduledsearch/notify';

    /**
     * Run the command.
     * 
     * AK: Check if scheduled searches are activated.
     *
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
     *
     * @return int 0 for success
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // AK: Check if scheduled searches is turned on or off and display an
        // appropriate message
        if (!filter_var($this->mainConfig->Account->schedule_searches,
            FILTER_VALIDATE_BOOLEAN)) {
            // AK: Set output - if not, we won't see the warn message below
            $this->output = $output;

            // AK: Output message if [Account]->schedule_searches is set to false
            $this->warn('Config "[Account] -> schedule_searches" in config.ini is '
            .'set to "false". Set to "true" for using the email alert system.');
            return;
        } else {
            // AK: If scheduled searches is turned on, execute the default action.
            parent::execute($input, $output);
        }
    }

    /**
     * Given a search results object, fetch records that have changed since the last
     * search. Return false on error.
     * 
     * AK: Use custom date field. Default is still "first_indexed".
     * 
     * TODO: Could be interesting for VuFind main code.
     *
     * @param \VuFind\Search\Base\Results $searchObject Search results object
     * @param \DateTime                   $lastTime     Last notification time
     *
     * @return array|bool
     */
    protected function getNewRecords($searchObject, $lastTime)
    {
        // AK: Get date field. Set to default if not configured
        $dateField = $this->mainConfig->Account->scheduled_search_date_field
            ?? 'first_indexed' ?: 'first_indexed';
        
        // Prepare query
        $params = $searchObject->getParams();
        $params->setLimit($this->limit);
        // AK: Use custom date field for setSort
        $params->setSort($dateField.' desc', true);

        $searchId = $searchObject->getSearchId();
        try {
            $records = $searchObject->getResults();
        } catch (\Exception $e) {
            $this->err("Error processing search $searchId: " . $e->getMessage());
            return false;
        }
        if (empty($records)) {
            $this->msg(
                "  No results found for search $searchId"
            );
            return false;
        }

        // AK: Use custom date field set in config.ini at scheduled_search_date_field
        // in [Account] section. We get this with the "getField" function defined in
        // AkSearch\RecordDriver\SolrDefault.
        $newestRecordDate = date(
            $this->iso8601,
            strtotime($records[0]->getField($dateField, true, 'rsort'))
        );

        $lastExecutionDate = $lastTime->format($this->iso8601);
        if ($newestRecordDate < $lastExecutionDate) {
            $this->msg(
                "  No new results for search ($searchId): "
                . "$newestRecordDate < $lastExecutionDate"
            );
            return false;
        }
        $this->msg(
            "  New results for search ($searchId): "
            . "$newestRecordDate >= $lastExecutionDate"
        );

        // AK: Add a hidden range filter to the search params. This ensures that the
        // link to the results that is sent in the email to the user only shows
        // records that are new since the last time the email was sent.
        $range = $dateField . ':[' . $lastExecutionDate . ' TO ' . $newestRecordDate
            . ']';
        $params->addHiddenFilter($range);

        // Collect records that have been indexed (for the first time)
        // after previous scheduled alert run
        $newRecords = [];
        foreach ($records as $record) {
            // AK: Use custom date field set in config.ini in [Account] section at
            // scheduled_search_date_field. We get this with the "getField" function
            // defined in AkSearch\RecordDriver\SolrDefault.
            $recDate = date(
                $this->iso8601,
                strtotime($record->getField($dateField, true, 'rsort'))
            );

            if ($recDate < $lastExecutionDate) {
                break;
            }
            $newRecords[] = $record;
        }
        return $newRecords;
    }

}
