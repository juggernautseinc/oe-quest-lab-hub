<?php

/*
 * package   OpenEMR
 * link           https://open-emr.org
 * author      Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c) 2024.  Sherwin Gaddis <sherwingaddis@gmail.com>
 */

require_once dirname(__DIR__, 4) . '/globals.php';

use Juggernaut\Quest\Module\QuestGetCommon;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$fileName    = $_POST['fileName']    ?? '';
$retrieveURI = $_POST['retrieveURI'] ?? '';
$ackURI      = $_POST['ackURI']      ?? '';

// Validate fileName: basename only, no path traversal, must be a .zip file
$safeFileName = basename($fileName);
if (empty($safeFileName) || !str_ends_with($safeFileName, '.zip') || $safeFileName !== $fileName) {
    echo json_encode(['success' => false, 'message' => xlt('Invalid file name.')]);
    exit;
}

// Validate retrieveURI: must be a relative Quest compendium path
if (empty($retrieveURI) || !str_starts_with($retrieveURI, '/oauth2/compendium/retrieveCompendium/')) {
    echo json_encode(['success' => false, 'message' => xlt('Invalid retrieve URI.')]);
    exit;
}

// Validate ackURI if provided: must also be a relative Quest compendium path
if (!empty($ackURI) && !str_starts_with($ackURI, '/oauth2/compendium/ackCompendium/')) {
    echo json_encode(['success' => false, 'message' => xlt('Invalid acknowledgement URI.')]);
    exit;
}

$retrieveCompendium = new QuestGetCommon();
echo $retrieveCompendium->retrieveCompendium($safeFileName, $retrieveURI, $ackURI);

