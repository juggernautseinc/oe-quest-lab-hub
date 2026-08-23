<?php

/*
 * package   OpenEMR
 * link           https://open-emr.org
 * author      Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c) 2024.  Sherwin Gaddis <sherwingaddis@gmail.com>
 */

namespace Juggernaut\Quest\Module;

use OpenEMR\Common\Database\QueryUtils;
use Juggernaut\Quest\Module\Exceptions\QuestConfigException;

class LoadCompendium
{
    /**
     * Request the list of available compendium files from Quest.
     * The BU abbreviation (recv_fac_id, e.g. "MET") is used as the ?BU= query parameter.
     *
     * @return string JSON response from Quest
     * @throws QuestConfigException If the Quest provider is not configured
     */
    final public function requestCompendiumFileList(): string
    {
        $buAbbreviation   = $this->pullBuAbbreviation();
        $resourceLocation = '/hub-resource-server/oauth2/compendium/requestCompendiums/CDC?BU=' . $buAbbreviation;
        $response         = new QuestGetCommon();

        return $response->getRequestToQuest($resourceLocation);
    }

    /**
     * Returns the BU abbreviation stored in recv_fac_id (e.g. "MET", "TMP").
     *
     * @return string BU abbreviation
     * @throws QuestConfigException If the Quest provider is not configured
     */
    public function getBuAbbreviation(): string
    {
        return $this->pullBuAbbreviation();
    }

    /**
     * Fetches recv_fac_id from the Quest procedure_provider row.
     *
     * @return string BU abbreviation
     * @throws QuestConfigException
     */
    private function pullBuAbbreviation(): string
    {
        $provider = QueryUtils::querySingleRow(
            "SELECT recv_fac_id FROM procedure_providers WHERE name = ?",
            ['Quest']
        );

        if (empty($provider['recv_fac_id'])) {
            throw new QuestConfigException(
                'Quest recv_fac_id (BU abbreviation, e.g. "MET") is not configured in the provider record.',
                0,
                'recv_fac_id'
            );
        }

        return $provider['recv_fac_id'];
    }
}
