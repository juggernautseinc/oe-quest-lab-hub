<?php

/*
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *  author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c)
 *  All rights reserved
 *
 */

namespace Juggernaut\Quest\Module;

class DownloadRequisition
{
    private string $pdf;

    public function __construct()
    {
        $this->pdf = Bootstrap::requisitionFormPath();
    }
    public function downloadLabPdfRequisition($name): void
    {
        if (filesize($this->pdf . $name) == 0) {
            error_log('Lab requisition form empty file');
            return;
        }
        error_log('Downloading lab pdf ' . $name);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename=LabRequisition.pdf');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($this->pdf . $name));
        header('Accept-Ranges: bytes');
        readfile($this->pdf . $name);
    }
}
