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

        // AK: Set timezone to UTC. If this is not done, the date(...) function below
        // would use the local timezone. This would result in a wrong time. Example:
        // Let's assume that the date of the newest Solr record would be
        // 2021-04-16T13:00:00Z. If our timezone is Europe/Vienna (DST) (= UTC + 2h),
        // the result of the function 
        //   date($this->iso8601, strtotime('2021-04-16T13:00:00Z'))
        // (see below) would be 2021-04-16T15:00:00Z, so the date that would be used
        // in the Solr query that gets the newest records would be 2 hours ahead.
        // That would result in wrong query results which could make a difference
        // when the $lastExecutionDate is close to the wrong date, i. e.:
        //   2021-04-16 13:00:00 >= 2021-04-16 14:00:00
        //   vs.
        //   2021-04-16 15:00:00 >= 2021-04-16 14:00:00
        $origTimeZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
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

            // AK: Reset the timezone to the value before it was set to UTC
            date_default_timezone_set($origTimeZone);

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
            // defined in AkSearch\RecordDriver\SolrDefault. It returns the first
            // value of the sorted array of a multivalued date field, i. e. the
            // newest record date.
            $recDate = date(
                $this->iso8601,
                strtotime($record->getField($dateField, true, 'rsort'))
            );

            if ($recDate < $lastExecutionDate) {
                break;
            }
            $newRecords[] = $record;
        }

        // AK: Reset the timezone to the value before it was set to UTC
        date_default_timezone_set($origTimeZone);

        return $newRecords;
    }

    /**
     * Try to send an email message to a user. Return true on success, false on
     * error.
     * 
     * AK: Use "from" email set for "scheduled_search_email_from" in config.ini.
     *
     * @param \VuFind\Db\Row\User $user    User to email
     * @param string              $message Email message body
     *
     * @return bool
     */
    protected function sendEmail($user, $message)
    {
        $subject = $this->mainConfig->Site->title
            . ': ' . $this->translate('Scheduled Alert Results');

        // AK: Use email address set for "scheduled_search_email_from" 
        $from = $this->mainConfig->Account->scheduled_search_email_from
            ?? $this->mainConfig->Site->email
            ?: $this->mainConfig->Site->email;

        $to = $user->email;
        try {
            $this->mailer->send($to, $from, $subject, $message);
            return true;
        } catch (\Exception $e) {
            $this->msg(
                'Initial email send failed; resetting connection and retrying...'
            );
        }
        // If we got this far, the first attempt threw an exception; let's reset
        // the connection, then try again....
        $this->mailer->resetConnection();
        try {
            $this->mailer->send($to, $from, $subject, $message);
        } catch (\Exception $e) {
            $this->err(
                "Failed to send message to {$user->email}: " . $e->getMessage()
            );
            return false;
        }
        // If we got here, the retry was a success!
        return true;
    }

}
