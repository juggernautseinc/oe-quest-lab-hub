<?php

/**
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *  author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c)
 *  All rights reserved
 *
 */

namespace Juggernaut\Quest\Module;

    use OpenEMR\Common\Crypto\CryptoGen;
    use OpenEMR\Services\Globals\GlobalSetting;
    class QuestGlobalConfig
    {
        const CONFIG_OPTION_CLIENT_ID = 'oe_quest_config_option_text';
        const CONFIG_OPTION_CLIENT_PWD = 'oe_quest_config_option_encrypted';
        const CONFIG_ENABLE_QUEST = "oe_quest_add_menu_button";
        const CONFIG_OPTION_REQUISITION = 'oe_quest_download_requisition';
        const CONFIG_OPTION_PRODUCTION = 'oe_quest_production';


        private $globalsArray;

        /**
         * @var CryptoGen
         */
        private $cryptoGen;

        public function __construct(array $globalsArray)
        {
            $this->globalsArray = $globalsArray;
            $this->cryptoGen = new CryptoGen();
        }

        /**
         * Returns true if all of the settings have been configured.  Otherwise it returns false.
         * @return bool
         */
        public function isQuestConfigured(): bool
        {
//            $config = $this->getGlobalSettingSectionConfiguration();
//            $keys = array_keys($config);
//            foreach ($keys as $key) {
//                if ($key == $this->isOptionalSetting($key)) {
//                    continue;
//                }
//                $value = $this->getGlobalSetting($key);
//
//                if (empty($value)) {
//                    return false;
//                }
//            }
            return true;
        }

        public function getTextOption()
        {
            return $this->getGlobalSetting(self::CONFIG_OPTION_CLIENT_ID);
        }

        /**
         * Returns our decrypted value if we have one, or false if the value could not be decrypted or is empty.
         * @return bool|string
         */
        public function getEncryptedOption(): bool|string
        {
            $encryptedValue = $this->getGlobalSetting(self::CONFIG_OPTION_CLIENT_PWD);
            return $this->cryptoGen->decryptStandard($encryptedValue);
        }

        public function getGlobalSetting($settingKey)
        {
            // don't like this as php 8.1 requires this but OpenEMR works with globals and this is annoying.
            return $GLOBALS[$settingKey] ?? null;
        }

        public function getGlobalSettingSectionConfiguration(): array
        {
            return [
                self::CONFIG_OPTION_CLIENT_ID => [
                    'title' => 'Quest Hub Client ID'
                    ,'description' => 'Quest Hub Client ID'
                    ,'type' => GlobalSetting::DATA_TYPE_TEXT
                    ,'default' => ''
                ]
                ,self::CONFIG_OPTION_CLIENT_PWD => [
                    'title' => 'Quest Hub Client Secret'
                    ,'description' => 'Quest Hub Client Secret'
                    ,'type' => GlobalSetting::DATA_TYPE_ENCRYPTED
                    ,'default' => ''
                ]
                ,self::CONFIG_ENABLE_QUEST => [
                    'title' => 'Enable Quest Hub Service'
                    ,'description' => 'Enable Quest Hub Service to electronically send lab orders'
                    ,'type' => GlobalSetting::DATA_TYPE_BOOL
                    ,'default' => ''
                ]
                ,self::CONFIG_OPTION_REQUISITION => [
                    'title' => 'Enable Requisition Download'
                    ,'description' => 'Upon ordering a printable requisition form will be displayed.'
                    ,'type' => GlobalSetting::DATA_TYPE_BOOL
                    ,'default' => ''
                ]
                ,self::CONFIG_OPTION_PRODUCTION => [
                    'title' => 'Enable Production Mode'
                    ,'description' => 'The system will be set to send orders to the production site.'
                    ,'type' => GlobalSetting::DATA_TYPE_BOOL
                    ,'default' => ''
                ]
            ];
        }
    }
