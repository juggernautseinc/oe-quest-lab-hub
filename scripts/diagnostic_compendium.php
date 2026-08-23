<?php

/*
 * Quest Compendium Diagnostic Script
 *
 * Run from CLI only:
 *   php diagnostic_compendium.php [site_id]
 *
 * Examples:
 *   php diagnostic_compendium.php default
 *   php diagnostic_compendium.php primevascular
 *
 * Logs full token response and compendium endpoint response so you can
 * see exactly what Quest is returning at each step.
 */

// ── CLI bootstrap ─────────────────────────────────────────────────────────────
// globals.php requires these server variables even in CLI context
$siteId = $argv[1] ?? null;
if (empty($siteId)) {
    echo 'Usage: php diagnostic_compendium.php <site_id>' . PHP_EOL;
    echo 'Example: php diagnostic_compendium.php default' . PHP_EOL;
    exit(1);
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/';
$_SERVER['DOCUMENT_ROOT']  = dirname(__DIR__, 4);
$_GET['site']              = $siteId;   // globals.php reads site_id from here

$ignoreAuth        = true;
$sessionAllowWrite = true;

require_once dirname(__DIR__, 4) . '/globals.php';

// Confirm site_id was accepted
if (empty($_SESSION['site_id'])) {
    $_SESSION['site_id'] = $siteId;
}
echo 'Using site_id: ' . $_SESSION['site_id'] . PHP_EOL;

use Juggernaut\Quest\Module\Bootstrap;
use Juggernaut\Quest\Module\QuestToken;

// ── 1. Check credentials ──────────────────────────────────────────────────────────────────────────────────
$b           = new Bootstrap($GLOBALS['kernel']->getEventDispatcher());
$credentials = $b->getGlobalConfig();
$clientId     = $credentials->getTextOption();
$clientSecret = $credentials->getEncryptedOption();

echo "\n=== Quest Credentials Check ===" . PHP_EOL;
echo "Client ID    : " . (empty($clientId)     ? '*** EMPTY ***' : substr($clientId, 0, 6) . '***') . PHP_EOL;
echo "Client Secret: " . (empty($clientSecret) ? '*** EMPTY ***' : '*** present (redacted) ***') . PHP_EOL;

$token = new QuestToken();
$mode  = $token->operationMode();
echo "Mode URL     : " . $mode . PHP_EOL;

// ── 2. Fetch provider BU IDs from procedure_providers ───────────────────────────────────────────────────
echo "\n=== Provider Configuration ===" . PHP_EOL;
$provider = sqlQuery("SELECT send_fac_id, recv_fac_id FROM procedure_providers WHERE name = 'Quest'");
if (empty($provider['recv_fac_id'])) {
    echo "ERROR: Quest recv_fac_id (BU abbreviation, e.g. MET) is empty in procedure_providers." . PHP_EOL;
    exit(1);
}
// recv_fac_id is the Quest BU code (e.g. "MET") used as the ?BU= API parameter AND filename prefix.
// send_fac_id is the numeric client account number used in HL7 ordering, NOT in the compendium API.
$buAbbreviation = $provider['recv_fac_id'];
echo "BU Abbreviation (recv_fac_id): " . $buAbbreviation . PHP_EOL;
echo "Client Account  (send_fac_id): " . ($provider['send_fac_id'] ?? 'n/a') . " (used in HL7, not compendium API)" . PHP_EOL;

// ── 3. Token request ────────────────────────────────────────────────────────────────────────────────────────────
echo "\n=== Token Request ===" . PHP_EOL;
$rawTokenResponse = $token->getFreshToken();
$tokenDecoded     = json_decode($rawTokenResponse, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON Error: Token response is not valid JSON — " . json_last_error_msg() . PHP_EOL;
    exit(1);
}

if (empty($tokenDecoded['access_token'])) {
    echo "ERROR: No access_token in response." . PHP_EOL;
    echo print_r($tokenDecoded, true) . PHP_EOL;
    exit(1);
}

$accessToken = $tokenDecoded['access_token'];
echo "Token obtained (first 20 chars): " . substr($accessToken, 0, 20) . "..." . PHP_EOL;

// ── 4. Compendium list endpoint (mirrors LoadCompendium exactly) ─────────────────────────────────────────
echo "\n=== Compendium List Request ===" . PHP_EOL;
$compendiumEndpoint = $mode . '/hub-resource-server/oauth2/compendium/requestCompendiums/CDC?BU=' . $buAbbreviation;
echo "Endpoint: " . $compendiumEndpoint . PHP_EOL;

$ch2 = curl_init();
curl_setopt_array($ch2, [
    CURLOPT_URL            => $compendiumEndpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
]);

$rawCompendiumResponse = curl_exec($ch2);
$compendiumHttpStatus  = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$curlError2            = curl_error($ch2);
curl_close($ch2);

echo "HTTP Status  : " . $compendiumHttpStatus . PHP_EOL;

if (!empty($curlError2)) {
    echo "cURL Error   : " . $curlError2 . PHP_EOL;
}

echo "Raw Response : " . $rawCompendiumResponse . PHP_EOL;

$compendiumDecoded = json_decode($rawCompendiumResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON Error: Response is not valid JSON — " . json_last_error_msg() . PHP_EOL;
    exit(1);
}

// ── 5. List all fullFileLinks entries ──────────────────────────────────────────────────────────────────────────────────
echo "\n=== fullFileLinks Entries ===" . PHP_EOL;
$fullFileLinks = $compendiumDecoded['fullFileLinks'] ?? [];
if (empty($fullFileLinks)) {
    echo "No fullFileLinks in response. Full decoded response:" . PHP_EOL;
    echo print_r($compendiumDecoded, true) . PHP_EOL;
} else {
    foreach ($fullFileLinks as $i => $link) {
        echo "[" . $i . "] fileName   : " . ($link['fileName']   ?? 'N/A') . PHP_EOL;
        echo "[" . $i . "] retrieveURI: " . ($link['retrieveURI'] ?? 'N/A') . PHP_EOL;
        echo "[" . $i . "] ackURI     : " . ($link['ackURI']      ?? 'N/A') . PHP_EOL;
        echo PHP_EOL;
    }
}

// ── 6. Verify BU_CDC_FULL pattern match ───────────────────────────────────────────────────────────────────────────────
echo "=== Pattern Match Check ===" . PHP_EOL;
$pattern = '/' . preg_quote($buAbbreviation, '/') . '_CDC_FULL/';
echo "Pattern: " . $pattern . PHP_EOL;
$found = false;
foreach ($fullFileLinks as $link) {
    if (!empty($link['fileName']) && preg_match($pattern, $link['fileName'])) {
        echo "MATCH: " . $link['fileName'] . PHP_EOL;
        echo "  retrieveURI: " . $link['retrieveURI'] . PHP_EOL;
        echo "  ackURI     : " . $link['ackURI'] . PHP_EOL;
        $found = true;
    }
}
if (!$found) {
    echo "No match for pattern '" . $pattern . "' in fullFileLinks." . PHP_EOL;
}

echo "\nDone." . PHP_EOL;
