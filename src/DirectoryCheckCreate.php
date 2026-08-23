<?php

/*
 * package   OpenEMR
 * link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

namespace Juggernaut\Quest\Module;

class DirectoryCheckCreate
{
    private string $location;
    private $status;
    public function __construct()
    {
        $dirExists = $this->doesDirectoryExist();
        if (!$dirExists) {
            $this->status = $this->createDirectory();
        }
    }
    public function doesDirectoryExist(): bool
    {
        $siteDir = $GLOBALS['OE_SITE_DIR'] ?? '';
        if ($siteDir === '') {
            throw new \RuntimeException('OpenEMR site directory (OE_SITE_DIR) is not available.');
        }
        $this->location = rtrim($siteDir, '/') . '/documents/labs';
        return file_exists($this->location);
    }
    public function directoryStatus()
    {
        return $this->status;
    }

    private function createDirectory(): bool
    {
        if (!mkdir($this->location, 0777, true) && !is_dir($this->location)) {
            throw new \RuntimeException("Unable to create directory: " . $this->location);
        }
        return true;
    }
}
