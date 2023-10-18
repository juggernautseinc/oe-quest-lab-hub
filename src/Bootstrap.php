<?php

    /**
     *
     * @package   OpenEMR
     * @link      http://www.open-emr.org
     *
     * @author    Stephen Nielson <stephen@nielson.org>
     * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
     * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
     * @copyright Copyright (c) 2023 Sherwin Gaddis <sherwingaddis@gmail.com>
     * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
     */

    namespace Juggernaut\Quest\Module;

    /**
     * Note the below use statements are importing classes from the OpenEMR core codebase
     */
    use OpenEMR\Common\Logging\SystemLogger;
    use OpenEMR\Core\Kernel;
    use OpenEMR\Events\Encounter\EncounterButtonEvent;
    use OpenEMR\Events\Globals\GlobalsInitializedEvent;
    use OpenEMR\Events\Services\LabTransmitEvent;
    use OpenEMR\Services\Globals\GlobalSetting;
    use OpenEMR\Menu\MenuEvent;
    use Symfony\Component\EventDispatcher\EventDispatcherInterface;

    class Bootstrap
    {
        const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
        const MODULE_NAME = "oe-module-quest-lab-hub";

        const HUB_RESOURCE_TESTING_URL = "https://certhubservices.quanum.com";
        const HUB_RESOURCE_PRODUCTION_URL = "https://hubservices.quanum.com";
        /**
         * @var EventDispatcherInterface The object responsible for sending and subscribing to events through the OpenEMR system
         */
        private EventDispatcherInterface $eventDispatcher;

        /**
         * @var QuestGlobalConfig Holds our module global configuration values that can be used throughout the module.
         */
        private QuestGlobalConfig $globalsConfig;

        /**
         * @var string The folder name of the module.  Set dynamically from searching the filesystem.
         */
        private $moduleDirectoryName;

        public string $requisitionFormName;

        /**
         * @var SystemLogger
         */
        private $logger;

        public function __construct(EventDispatcherInterface $eventDispatcher)
        {
            global $GLOBALS;

            if (empty($kernel)) {
                $kernel = new Kernel();
            }

            // we inject our globals value.
            $this->moduleDirectoryName = basename(dirname(__DIR__));
            $this->eventDispatcher = $eventDispatcher;

            $this->globalsConfig = new QuestGlobalConfig($GLOBALS);
            $this->logger = new SystemLogger();
        }

        public static function requisitionFormPath(): string
        {
            return dirname(__DIR__, 5) . "/sites/" . $_SESSION['site_id'] . "/documents/labs/";
        }

        public function subscribeToEvents(): void
        {
            $this->addGlobalSettings();
            // we only add the rest of our event listeners and configuration if we have been fully setup and configured
            if ($this->globalsConfig->isQuestConfigured()) {
                $this->registerMenuItems();
                $this->subscribeToLabTransmissionEvents();
                $this->subscribeToEncounterFormEvents();
            }
        }

        public function subscribeToLabTransmissionEvents(): void
        {
            $this->eventDispatcher->addListener(LabTransmitEvent::EVENT_LAB_TRANSMIT, [$this, 'sendOrderToQuestLab']);
            $this->eventDispatcher->addListener(LabTransmitEvent::EVENT_LAB_POST_ORDER_LOAD, [$this, 'downloadPdfToDesktop']);
        }

        public function subscribeToEncounterFormEvents(): void
        {
            $this->eventDispatcher->addListener(EncounterButtonEvent::BUTTON_RENDER, [$this, 'encounterButtonRender']);
        }

        public function encounterButtonRender(EncounterButtonEvent $event): void
        {
            $addButtonEncounterForm = new AddButtonEncounterForm();
            $event->setButton($addButtonEncounterForm->specimenLabelButton());
        }

        public function sendOrderToQuestLab(LabTransmitEvent $event): void
        {
            $order = $event->getOrder(); // get the order from the event
            $requisitionOrder = $order;
            new ProcessLabOrder($order); // create a new process lab order

            //call to get the requisition document from QuestLab
            if ($GLOBALS['oe_quest_download_requisition']) { // the requisition form is optional and can be turned off
                $pdf = new ProcessRequisitionDocument($requisitionOrder);
                $this->requisitionFormName = $pdf->sendRequest(); //send request for requisition
            }
        }

        public function downloadPdfToDesktop(): void
        {
            $sendDownload = new DownloadRequisition();
            if (!empty($this->requisitionFormName)) {
                error_log('File name ' . $this->requisitionFormName);
                $sendDownload->downloadLabPdfRequisition($this->requisitionFormName);
            }
        }

        /**
         * @return QuestGlobalConfig
         */
        public function getGlobalConfig(): QuestGlobalConfig
        {
            return $this->globalsConfig;
        }

        public function addGlobalSettings(): void
        {
            $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, [$this, 'addGlobalQuestSettingsSection']);
        }

        public function addGlobalQuestSettingsSection(GlobalsInitializedEvent $event): void
        {
            global $GLOBALS;
            $service = $event->getGlobalsService();
            $section = xlt("Quest Lab");
            $service->createSection($section, 'Portal');

            $settings = $this->globalsConfig->getGlobalSettingSectionConfiguration();

            foreach ($settings as $key => $config) {
                $value = $GLOBALS[$key] ?? $config['default'];
                $service->appendToSection(
                    $section,
                    $key,
                    new GlobalSetting(
                        xlt($config['title']),
                        $config['type'],
                        $value,
                        xlt($config['description']),
                        true
                    )
                );
            }
        }

        public function registerMenuItems()
        {
            if ($this->getGlobalConfig()->getGlobalSetting(QuestGlobalConfig::CONFIG_ENABLE_QUEST)) {
                /**
                 * @var EventDispatcherInterface $eventDispatcher
                 * @var array $module
                 * @global                       $eventDispatcher @see ModulesApplication::loadCustomModule
                 * @global                       $module @see ModulesApplication::loadCustomModule
                 */
                $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, [$this, 'addCustomModuleMenuItem']);
            }
        }

        public function addCustomModuleMenuItem(MenuEvent $event): MenuEvent
        {
            $menu = $event->getMenu();

            $menuItem = new \stdClass();
            $menuItem->requirement = 0;
            $menuItem->target = 'mod';
            $menuItem->menu_id = 'mod0';
            $menuItem->label = xlt("Quest Lab Hub");
            // TODO: pull the install location into a constant into the codebase so if OpenEMR changes this location it
            // doesn't break any modules.
            $menuItem->url = "/interface/modules/custom_modules/oe-quest-lab-hub/public/index.php";
            $menuItem->children = [];

            /**
             * This defines the Access Control List properties that are required to use this module.
             * Several examples are provided
             */
            $menuItem->acl_req = [];

            /**
             * If you would like to restrict this menu to only logged in users who have access to see all user data
             */
            $menuItem->acl_req = ["admin", "users"];

            /**
             * If you would like to restrict this menu to logged in users who can access patient demographic information
             */
            //$menuItem->acl_req = ["users", "demo"];


            /**
             * This menu flag takes a boolean property defined in the $GLOBALS array that OpenEMR populates.
             * It allows a menu item to display if the property is true, and be hidden if the property is false
             */
            $menuItem->global_req = ["custom_quest_module_enable"];

            /**
             * If you want your menu item to allows be shown then leave this property blank.
             */
            $menuItem->global_req = [];

            foreach ($menu as $item) {
                if ($item->menu_id == 'modimg') {
                    $item->children[] = $menuItem;
                    break;
                }
            }

            $event->setMenu($menu);

            return $event;
        }


        private function getPublicPath()
        {
            return self::MODULE_INSTALLATION_PATH . ($this->moduleDirectoryName ?? '') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
        }

        private function getAssetPath()
        {
            return $this->getPublicPath() . 'assets' . DIRECTORY_SEPARATOR;
        }
    }
