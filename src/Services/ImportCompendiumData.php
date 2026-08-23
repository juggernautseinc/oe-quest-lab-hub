<?php

/*
 * package   OpenEMR
 * link           https://open-emr.org
 * author      Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c) 2024.  Sherwin Gaddis <sherwingaddis@gmail.com>
 */

namespace Juggernaut\Quest\Module\Services;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class ImportCompendiumData
{
    private string|false $compendiumData;
    private string|false $compendiumAoeData;
    private SystemLogger $logging;
    private int $labId;
    private string $name;
    private string $description;
    private string $code;
    private string $pcode;
    private string $qcode;
    private string $seq;
    private string $options;
    private string $tips;
    private string $qtext;

    public function __construct()
    {
        $this->logging = new SystemLogger();

        $provider = $this->getQuestProvider();
        if (empty($provider['ppid'])) {
            $this->logging->error('Quest provider not found in procedure_providers. Compendium import aborted.');
            return;
        }
        $this->labId = (int) $provider['ppid'];

        // Filenames inside the zip use the BU abbreviation as the suffix:
        // ORDCODE_{BU}.TXT and AOE_{BU}.TXT  (e.g. ORDCODE_MET.TXT, AOE_MET.TXT)
        $buAbbreviation = $provider['recv_fac_id'] ?? '';
        if (empty($buAbbreviation)) {
            $this->logging->error('Quest recv_fac_id (BU abbreviation) is empty. Cannot locate compendium files.');
            return;
        }

        $siteDir = $GLOBALS['OE_SITE_DIR'] ?? '';
        if ($siteDir === '') {
            $this->logging->error('OE_SITE_DIR is empty; cannot locate compendium temp files.');
            return;
        }
        $tmpDir     = rtrim($siteDir, '/') . '/documents/temp/';
        $compendium = $tmpDir . 'ORDCODE_' . $buAbbreviation . '.TXT';
        $aoe        = $tmpDir . 'AOE_'     . $buAbbreviation . '.TXT';

        if (file_exists($compendium)) {
            $this->compendiumData = file_get_contents($compendium);
            $this->importData();
            unlink($compendium);
        } else {
            $this->logging->error('Compendium order file not found: ' . $compendium);
        }

        if (file_exists($aoe)) {
            $this->compendiumAoeData = file_get_contents($aoe);
            $this->importAoeData();
            unlink($aoe);
        } else {
            $this->logging->error('Compendium AOE file not found: ' . $aoe);
        }
    }

    private function importData(): void
    {
        $this->createQuestDatasetGroup();

        $i     = 0;
        $lines = explode("\n", $this->compendiumData);

        foreach ($lines as $line) {
            if ($i === 0) {
                $i++;
                continue;
            }

            $fields            = explode("^", $line);
            $this->name        = $fields[6] ?? '';
            $this->description = $fields[6] ?? '';
            $this->code        = $fields[1] ?? '';

            if ($this->checkIfDataExists()) {
                $i++;
                continue;
            }

            $this->insertData();
            $i++;
        }
    }

    private function importAoeData(): void
    {
        // Delete only Quest's AOE rows — never drop or recreate the shared procedure_questions
        // table, as it may contain AOE data for other labs.
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM procedure_questions WHERE lab_id = ?",
            [$this->labId]
        );

        $i     = 0;
        $lines = explode("\n", $this->compendiumAoeData);

        foreach ($lines as $line) {
            if ($i === 0) {
                $i++;
                continue;
            }

            $fields        = explode("^", $line);
            $this->pcode   = $fields[3] ?? '';
            $this->qcode   = $fields[2] ?? '';
            $this->seq     = $fields[4] ?? '';
            $this->tips    = $fields[11] ?? '';
            $this->options = $fields[9] ?? '';
            $this->qtext   = $fields[5] ?? '';

            if (!empty($this->pcode) && !empty($this->qcode)) {
                $this->insertAoeData();
            }
            $i++;
        }
    }

    private function insertData(): void
    {
        $parent = $this->dataSetGroup();

        if (empty($parent['procedure_type_id'])) {
            $this->logging->error(xlt('Quest dataset group not found'));
            return;
        }

        if (empty($this->name) || empty($this->code) || empty($this->description)) {
            $this->logging->error(xlt('Quest name, code or description not found'));
            return;
        }

        $sql = "INSERT INTO `procedure_type`
            (`procedure_type_id`, `parent`, `name`, `lab_id`, `procedure_code`, `procedure_type`,
             `body_site`, `specimen`, `route_admin`, `laterality`, `description`, `standard_code`,
             `related_code`, `units`, `range`, `seq`, `activity`, `notes`, `transport`, `procedure_type_name`)
            VALUES (NULL, ?, ?, ?, ?, 'ord', '', '', '', '', ?, '', '', '', '', 0, 1, '', NULL, 'laboratory_test')";

        QueryUtils::sqlStatementThrowException(
            $sql,
            [$parent['procedure_type_id'], $this->name, $this->labId, $this->code, $this->description]
        );
    }

    private function insertAoeData(): void
    {
        // INSERT IGNORE skips rows that would violate the PRIMARY KEY constraint
        // (lab_id, procedure_code, question_code). The Quest compendium file can
        // contain duplicate question entries for the same procedure; we keep the
        // first occurrence and discard subsequent ones.
        $sql = "INSERT IGNORE INTO `procedure_questions`
            (`lab_id`, `procedure_code`, `question_code`, `seq`, `question_text`,
             `required`, `maxsize`, `fldtype`, `options`, `tips`, `activity`)
            VALUES (?, ?, ?, ?, ?, '1', '0', 'T', ?, ?, '1')";

        QueryUtils::sqlStatementThrowException(
            $sql,
            [$this->labId, $this->pcode, $this->qcode, $this->seq, $this->qtext, $this->options, $this->tips]
        );
    }

    private function createQuestDatasetGroup(): void
    {
        $dataGroup = $this->dataSetGroup();
        if (!empty($dataGroup['procedure_type_id'])) {
            return;
        }

        $sql = "INSERT INTO `procedure_type`
            (`procedure_type_id`, `parent`, `name`, `lab_id`, `procedure_code`, `procedure_type`,
             `body_site`, `specimen`, `route_admin`, `laterality`, `description`, `standard_code`,
             `related_code`, `units`, `range`, `seq`, `activity`, `notes`, `transport`, `procedure_type_name`)
            VALUES (NULL, 0, 'Quest Clinical Dataset', ?, '', 'grp', '', '', '', '',
                    'Quest Clinical Dataset', '', '', '', '', 0, 1, '', NULL, 'procedure')";

        QueryUtils::sqlStatementThrowException($sql, [$this->labId]);
    }

    public function dataSetGroup(): array|false
    {
        return QueryUtils::querySingleRow(
            "SELECT `procedure_type_id` FROM `procedure_type` WHERE `name` = 'Quest Clinical Dataset'",
            []
        );
    }

    /**
     * Returns the Quest provider row including ppid (lab ID) and recv_fac_id (BU abbreviation).
     * recv_fac_id drives the compendium filename: ORDCODE_{recv_fac_id}.TXT / AOE_{recv_fac_id}.TXT
     *
     * @return array|false
     */
    public function getQuestProvider(): array|false
    {
        return QueryUtils::querySingleRow(
            "SELECT `ppid`, `recv_fac_id` FROM `procedure_providers` WHERE `name` = ?",
            ['Quest']
        );
    }

    /**
     * @deprecated Use getQuestProvider() to also retrieve recv_fac_id.
     */
    public function getQuestProviderId(): array|false
    {
        return $this->getQuestProvider();
    }

    private function checkIfDataExists(): bool
    {
        $data = QueryUtils::querySingleRow(
            "SELECT `procedure_type_id` FROM `procedure_type` WHERE `procedure_code` = ?",
            [$this->code]
        );
        return !empty($data['procedure_type_id']);
    }

}
