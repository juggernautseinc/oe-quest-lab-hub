<?php

/*
 * package   OpenEMR
 * link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

namespace Juggernaut\Quest\Module;

class BackgroundServices
{

    public array|bool|null $status;
    public function changeStatus(): void
    {
        if ($this->status) {
            $status = 1;
        } else {
            $status = 0;
        }
        sqlQuery("UPDATE `background_services` SET `active` = ? WHERE `name` = 'Quest_Lab_Hub'", [$status]);
    }

    public function getStatus(): bool|array|null
    {
        return sqlQuery("SELECT `active` FROM `background_services` WHERE `name` = 'Quest_Lab_Hub'");
    }
}
