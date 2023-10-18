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
        $this->location = dirname(__FILE__, 6) . "/sites/" . $_SESSION['site_id'] . "/documents/labs";
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
