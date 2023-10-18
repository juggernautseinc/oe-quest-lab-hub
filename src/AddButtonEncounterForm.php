<?php
/*
 * package   OpenEMR
 * link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

namespace Juggernaut\Quest\Module;

class AddButtonEncounterForm
{
    public function specimenLabelButton(): string
    {
        return "<a class='btn btn-secondary btn-sm' href='" . $GLOBALS['web_root'] .
            "/interface/modules/custom_modules/oe-quest-lab-hub/public/lab_labels.php?count=3' title='" .
            xla('Print Specimen Label') .
            "' onclick='top.restoreSession()'>" .
            xlt('Specimen Labels') . "</a>";
    }
}
