<?php

/*
 * package   OpenEMR
 * link           https://open-emr.org
 * author      Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c) 2024.  Sherwin Gaddis <sherwingaddis@gmail.com>
 */

namespace Juggernaut\Quest\Module;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use ZipArchive;
use Juggernaut\Quest\Module\Services\ImportCompendiumData;

class QuestGetCommon
{
    private string $tmpDir;

    public function __construct()
    {
        // OpenEMR 8.1+ sets OE_SITE_DIR from the session site; do not use $_SESSION['site_id']
        // (empty under some request paths → sites//documents/temp and download failure).
        $siteDir = $GLOBALS['OE_SITE_DIR'] ?? '';
        if ($siteDir === '' || !is_dir($siteDir)) {
            throw new \RuntimeException('OpenEMR site directory (OE_SITE_DIR) is not available.');
        }
        $this->tmpDir = rtrim($siteDir, '/') . '/documents/temp/';
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0755, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException('Unable to create compendium temp directory: ' . $this->tmpDir);
        }
    }

    final public function getRequestToQuest(
        string $resourceLocation
    ): string {
        $token = new QuestToken();
        $postToken = json_decode($token->getFreshToken(), true);
        $postToken = $postToken['access_token'] ?? '';
        $mode = $token->operationMode() ?? '';

        if (empty($mode) || empty($resourceLocation) || empty($postToken)) {
            error_log('Quest Lab Order: missing credentials or location — ' . $mode . $resourceLocation);
            return '';
        }

        try {
            $client   = new Client();
            $response = $client->get($mode . $resourceLocation, [
                'http_errors' => false,   // return error responses instead of throwing
                'headers'     => [
                    'Authorization' => 'Bearer ' . $postToken,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                error_log('Quest getRequestToQuest non-200 response: HTTP ' . $statusCode . ' — ' . $body);
            }

            // Always return the body so the caller can parse Quest's error JSON
            return $body;
        } catch (GuzzleException $e) {
            error_log('Quest getRequestToQuest Guzzle error: ' . $e->getMessage());
            return json_encode(['exception' => $e->getMessage()]);
        }
    }

    /**
     * Download the compendium zip file, unzip it, import data, then send ACK.
     * The retrieveURILocation must be a relative Quest compendium path starting
     * with /oauth2/compendium/ — the /hub-resource-server prefix is applied here.
     *
     * @param string $fileName           Zip filename (e.g. MET_CDC_FULL_20260506_222349400.zip)
     * @param string $retrieveURILocation Relative retrieve URI from Quest response
     * @param string $ackURILocation      Relative ACK URI from Quest response
     * @return string JSON {success: bool, message: string}
     */
    final public function retrieveCompendium(
        string $fileName,
        string $retrieveURILocation,
        string $ackURILocation = ''
    ): string {
        $client = new Client();

        $token     = new QuestToken();
        $postToken = json_decode($token->getFreshToken(), true);
        $postToken = $postToken['access_token'] ?? '';
        $mode      = $token->operationMode() ?? '';

        $saveTo = $this->tmpDir . $fileName;

        // The Quest retrieveURI is relative (e.g. /oauth2/compendium/...) and requires
        // the /hub-resource-server path segment prepended before the base URL is applied.
        $fullUrl = $mode . '/hub-resource-server' . $retrieveURILocation;

        try {
            $headers = [
                'Authorization'   => 'Bearer ' . $postToken,
                'Accept'          => '*/*',
                'Accept-Encoding' => 'gzip, deflate, br',
            ];

            $response = $client->get($fullUrl, ['headers' => $headers, 'sink' => $saveTo]);

            if ($response->getStatusCode() !== 200) {
                return json_encode([
                    'success' => false,
                    'message' => xlt('Error downloading file. Status code: ') . $response->getStatusCode(),
                ]);
            }

            $unzipResults = $this->unzipCdcFile($fileName);
            if (!$unzipResults) {
                return json_encode([
                    'success' => false,
                    'message' => xlt('File downloaded but could not be unzipped.'),
                ]);
            }

            new ImportCompendiumData();
            unlink($this->tmpDir . $fileName);

            // Acknowledge receipt so Quest knows we received the file
            if (!empty($ackURILocation)) {
                $this->ackCompendium($ackURILocation, $mode, $postToken);
            }

            return json_encode([
                'success' => true,
                'message' => xlt('Compendium imported successfully: ') . $fileName,
            ]);
        } catch (GuzzleException $e) {
            error_log('Quest retrieveCompendium Guzzle error: ' . $e->getMessage());
            return json_encode([
                'success' => false,
                'message' => xlt('HTTP error during download: ') . $e->getMessage(),
            ]);
        }
    }

    /**
     * Send acknowledgement to Quest after a successful compendium download.
     * Failures are logged but do not bubble up — the import is already complete.
     *
     * @param string $ackURILocation Relative ACK URI from Quest response
     * @param string $mode           Base URL (e.g. https://certhubservices.quanum.com)
     * @param string $accessToken    Bearer token
     */
    private function ackCompendium(string $ackURILocation, string $mode, string $accessToken): void
    {
        try {
            $client  = new Client();
            $ackUrl  = $mode . '/hub-resource-server' . $ackURILocation;
            $response = $client->get($ackUrl, [
                'http_errors' => false,
                'headers'     => ['Authorization' => 'Bearer ' . $accessToken],
            ]);

            if ($response->getStatusCode() !== 200) {
                error_log('Quest ackCompendium non-200 response: HTTP ' . $response->getStatusCode());
            }
        } catch (GuzzleException $e) {
            error_log('Quest ackCompendium error: ' . $e->getMessage());
        }
    }

    private function unzipCdcFile(string $fileName): bool
    {
        $zip = new ZipArchive();
        $res = $zip->open($this->tmpDir . $fileName);
        if ($res === true) {
            $zip->extractTo($this->tmpDir);
            $zip->close();
            return true;
        }
        return false;
    }
}
