<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4:
+----------------------------------------------------------------------+
| PHP Documentation Site Source Code                                   |
+----------------------------------------------------------------------+
| Copyright (c) 1997-2011 The PHP Group                                |
+----------------------------------------------------------------------+
| This source file is subject to version 3.01 of the PHP license,      |
| that is bundled with this package in the file LICENSE, and is        |
| available through the world-wide-web at the following url:           |
| http://www.php.net/license/3_01.txt.                                  |
| If you did not receive a copy of the PHP license and are unable to   |
| obtain it through the world-wide-web, please send a note to          |
| license@php.net so we can mail you a copy immediately.               |
+----------------------------------------------------------------------+
| Author:        Philip Olson <philip@php.net>                         |
+----------------------------------------------------------------------+
$Id$

Notes: 
	- This emails the documentation list each # days (7, via cron) with 
	  PHP documentation activity information.
	- These are only numbers/statistics, so there are no winners or 
	  losers except for the documentation.
Todo:
	- Add SVN lines changed/added/deleted instead of # commits
	- Add other bug activities? So, not only bug->closed?
	- Determine if posting statistics is wise (good or bad)
*/

// build-ops.php is generated by web/doc/trunk/ setup
require '../build-ops.php';

date_default_timezone_set('UTC');

define('DEBUG_MODE', FALSE); // Enable to not send emails.
define('DAYS_LOOKUP', 7); // Number of days, in the past, to search/use for the report

$svn_modules = array('phpdoc', 'phd', 'web/doc-editor');
$time_past   = date('Y-m-d', strtotime('-'. DAYS_LOOKUP . ' days'));
$time_future = date('Y-m-d', strtotime('+'. DAYS_LOOKUP . ' days'));
$time_now    = date('Y-m-d');

if (!function_exists('sqlite_open')) {
	echo 'Fail. I require ext/sqlite to work.', PHP_EOL;
	exit;
}
if (!function_exists('simplexml_load_string')) {
	echo 'Fail. I require ext/simplexml to work.', PHP_EOL;
	exit;
}	

$email_text = <<<TEMPLATE

Hello!

This lists some of the activity found within the PHP documentation over at php.net. Of course numbers mean nothing alone, but they do show general activity around the PHP documentation. Dates of activity include: DATES_ACTIVITY

Those who made SVN commits:
-----------------------------------------------
  (php.net svn modules: SVN_MODULES_LIST)

SVN_COMMIT_COUNTS

Those who closed documentation bugs:
-----------------------------------------------
  (bug categories: problem, translation, phd, editor)

BUGS_CLOSED

Those who handled user notes:
-----------------------------------------------
  (actions: delete, reject, edit)

NOTES_HANDLED

---
See also: 
 - Edit the documentation online: https://edit.php.net/
 - Documentation HOWTO: https://doc.php.net/dochowto/

TEMPLATE;

/****************************************************************************/
/**** Weekly commits ********************************************************/
/****************************************************************************/

$counts = array();
$text   = '';
foreach ($svn_modules as $svn_module) {
	
	$command = "svn log http://svn.php.net/repository/$svn_module --revision \{$time_past}:\{$time_future} --non-interactive --xml";
	$results = shell_exec($command);
	
	// Elementless XML file has strlen of 35
	if (!$results || strlen($results) < 35) {
		continue;
	}

	$xml = new SimpleXMLElement($results);

	if (empty($xml->logentry)) {
		continue;
	}

	foreach ($xml as $info) {
		@$counts[ (string) $info->author ]++;
	}
}

if ($counts && !empty($counts)) {

	arsort($counts);

	$text = '';
	foreach ($counts as $name => $count) {
		$text .= sprintf("%20s %5s\n", $name, $count);
	}

} else {
	$text = 'No commits made last week. So sad. :(';
}

$email_text = str_replace('SVN_COMMIT_COUNTS', $text, $email_text);

/****************************************************************************/
/**** Weekly closed bugs ****************************************************/
/****************************************************************************/

$rawbuginfo = file_get_contents('http://bugs.php.net/api.php?type=docs&action=closed&interval=' . DAYS_LOOKUP);

$text = '';
if (!empty($rawbuginfo)) {
	
	$buginfo = unserialize($rawbuginfo);
	
	if (!is_array($buginfo)) {
		$text = 'Incorrect bugs information gathered.';
	} else {
		if (count($buginfo) > 0) {
			foreach ($buginfo as $info) {
				$text .= sprintf("%20s %5s\n", $info['reporter_name'], $info['count']);
			}
		} else {
			$text = 'No closed bugs last week. So sad. :(';
		}
	}
} else {
	$text = 'Bug information could not be gathered.';
}

$email_text = str_replace('BUGS_CLOSED', $text, $email_text);

/****************************************************************************/
/**** Weekly notes stats ****************************************************/
/****************************************************************************/

// Note: notes_stats.sqlite is generated via web/doc/trunk/scripts/notes*.php
// It's used for other note related activities, but we're using it for this too.
$dbfile = SQLITE_DIR . 'notes_stats.sqlite';
$text   = '';
if (is_readable($dbfile) && $db = sqlite_open($dbfile, 0666)) {

	$seconds = 86400*DAYS_LOOKUP;

	$sql = "SELECT who, count(*) as count FROM notes WHERE time > (strftime('%s', 'now')-{$seconds}) GROUP BY who ORDER BY count DESC";

	$res = sqlite_query($db, $sql);
	if ($res) {

		if (sqlite_num_fields($res) > 0) {
	
			$rows = sqlite_fetch_all($res, SQLITE_ASSOC);
		
			foreach ($rows as $row) {
				$text .= sprintf("%20s %5s\n", $row['who'], $row['count']);
			}
		} else {
			$text = 'No notes were edited last week. So sad. :(';
		}
	} else {
		$text = 'Unable to query the notes database';
	}

} else {
	$text = 'The notes data cannot be found';
}

$email_text = str_replace('NOTES_HANDLED', $text, $email_text);

/**** Misc ******************************************************************/
$email_text = str_replace('SVN_MODULES_LIST', implode($svn_modules, ', '), $email_text);
$email_text = str_replace('DATES_ACTIVITY', "$time_past to $time_now", $email_text);

if (!DEBUG_MODE) {
	mail('phpdoc@lists.php.net', 'The PHP documentation activity report', $email_text, 'From: noreply@php.net', '-fnoreply@php.net');
} else {
	echo $email_text;
}
