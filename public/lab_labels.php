<?php

/**
 * Borrowed from
 * interface/patient_file/addr_appt_label.php
 * Displaying a PDF file of Appointment Labels for printing.
 *
 * Program for displaying Lab Labels
 * from the lab order.
 *
 * The program example supplied with the Avery Label Print
 * Class was used to produce this program
 *
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Terry Hill <terry@lillysystems.com>
 * @copyright 2016 Terry Hill <terry@lillysystems.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once dirname(__FILE__, 5) . "/globals.php";

$count = $_GET['count'];
# This is based on session array.
$pid_list = array();
$pid_list = $_SESSION['pidList'];

#get label type and number of labels on sheet
#

if ($GLOBALS['chart_label_type'] == '1') {
    $pdf = new PDF_Label('5160');
    $last = 30;
} elseif ($GLOBALS['chart_label_type'] == '2') {
    $pdf = new PDF_Label('5161');
    $last = 20;
} elseif ($GLOBALS['chart_label_type'] == '3') {
    $pdf = new PDF_Label('5162');
    $last = 14;
} elseif ($GLOBALS['chart_label_type'] == '4') {
    $pdf = new PDF_Label('5163');
    $last = 14; //not sure about $last from here on down
} elseif ($GLOBALS['chart_label_type'] == '5') {
    $pdf = new PDF_Label('5164');
    $last = 14;
} elseif ($GLOBALS['chart_label_type'] == '6') {
    $pdf = new PDF_Label('8600');
    $last = 14;
} elseif ($GLOBALS['chart_label_type'] == '7') {
    $pdf = new PDF_Label('L7163');
    $last = 14;
} elseif ($GLOBALS['chart_label_type'] == '8') {
    $pdf = new PDF_Label('3422');
    $last = 14;
} else {
    $pdf = new PDF_Label('5160');
    $last = 30;
}
$pdf->AddPage();

#Get the data to place on labels
#and output each label
    $patdata = sqlQuery("SELECT p.fname, p.lname, p.DOB, p.pid FROM `patient_data` AS p WHERE p.pid = ? LIMIT 1", array($_SESSION['pid']));

    $faciltyAccount = sqlQuery("SELECT `send_fac_id` FROM `procedure_providers` WHERE `name` = ?", array('Quest'));

    $dateCollected = sqlQuery("SELECT `date_collected` FROM `procedure_order` WHERE `encounter_id` = ?", array($_SESSION['encounter']));
    $dateCollected = substr($dateCollected['date_collected'], 0, 10);
# sprintf to print data
for($x=1;$x<=$count;$x++) {
    $text = sprintf("  %s %s\n  %s %s\n  %s %s\n ", $patdata['fname'], $patdata['lname'], "MRN#: " . $patdata['pid'], "DOB: " . $patdata['DOB'], "Client#: " .  $faciltyAccount['send_fac_id'], "DOS: " . $dateCollected);
    $pdf->Add_Label($text);
}

$pdf->Output();
