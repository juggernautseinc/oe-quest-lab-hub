<?php

/*
 * package   OpenEMR
 * link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

namespace Juggernaut\Quest\Module;

use OpenEMR\Common\Database\QueryUtils;

class BackgroundServices
{
    public const SERVICE_NAME = 'Quest_Lab_Hub';

    /**
     * Desired active flag for the next changeStatus() call (1 = on, 0 = off).
     */
    public int $status = 0;

    /**
     * Enable or disable the Quest Lab Hub background service.
     * When enabling, next_run is set to NOW so the scheduler will pick it up.
     */
    public function changeStatus(): void
    {
        $active = $this->status === 1 ? 1 : 0;

        if ($active === 1) {
            QueryUtils::sqlStatementThrowException(
                'UPDATE `background_services` SET `active` = 1, `next_run` = NOW() WHERE `name` = ?',
                [self::SERVICE_NAME]
            );
            return;
        }

        QueryUtils::sqlStatementThrowException(
            'UPDATE `background_services` SET `active` = 0 WHERE `name` = ?',
            [self::SERVICE_NAME]
        );
    }

    /**
     * @return array{active: string|int}|false|null
     */
    public function getStatus(): array|false|null
    {
        return QueryUtils::querySingleRow(
            'SELECT `active`, `next_run`, `execute_interval`, `running` FROM `background_services` WHERE `name` = ?',
            [self::SERVICE_NAME]
        );
    }

    public function isActive(): bool
    {
        $row = $this->getStatus();
        if (empty($row) || !is_array($row)) {
            return false;
        }

        return (string) ($row['active'] ?? '0') === '1';
    }
}
