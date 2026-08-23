<?php

    /**
     * Bootstrap file for the Quest Lab Hub Module
     *
     * This file serves as the main entry point for the Quest Lab Hub module,
     * responsible for initializing the module, setting up event listeners,
     * managing global configurations, and handling lab order transmission.
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
    use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
    use OpenEMR\Events\Services\QuestLabTransmitEvent;
    use OpenEMR\Services\Globals\GlobalSetting;
    use OpenEMR\Menu\MenuEvent;
    use Juggernaut\Quest\Module\RestControllers\QuestOrderRestController;
    use Juggernaut\Quest\Module\Services\QuestDocumentService;
    use RestConfig;
    use Symfony\Component\EventDispatcher\EventDispatcherInterface;

    /**
     * Class Bootstrap
     *
     * The Bootstrap class serves as the main initialization point for the Quest Lab Hub module.
     * It manages the integration between OpenEMR and Quest Diagnostics' Quantum Hub system,
     * handling lab order transmission, requisition form generation, and results retrieval.
     * This class configures all necessary event listeners, global settings, and menu items.
     *
     * @package Juggernaut\Quest\Module
     */
    class Bootstrap
    {
        /**
         * Path to the OpenEMR custom modules directory
         * @var string
         */
        const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";

        /**
         * Module name identifier
         * @var string
         */
        const MODULE_NAME = "oe-module-quest-lab-hub";

        /**
         * URL for Quest Hub testing environment
         * @var string
         */
        const HUB_RESOURCE_TESTING_URL = "https://certhubservices.quanum.com";

        /**
         * URL for Quest Hub production environment
         * @var string
         */
        const HUB_RESOURCE_PRODUCTION_URL = "https://hubservices.quanum.com";

        /**
         * The object responsible for sending and subscribing to events through the OpenEMR system
         * @var EventDispatcherInterface
         */
        private EventDispatcherInterface $eventDispatcher;

        /**
         * Holds our module global configuration values that can be used throughout the module
         * @var QuestGlobalConfig
         */
        private QuestGlobalConfig $globalsConfig;

        /**
         * The folder name of the module, set dynamically from searching the filesystem
         * @var string
         */
        private $moduleDirectoryName;

        /**
         * The name of the requisition form file, used to track downloaded documents
         * @var string
         */
        public string $requisitionFormName;

        /**
         * Logger instance for recording system events
         * @var SystemLogger
         */
        private $logger;

        /**
         * Constructor for the Bootstrap class
         *
         * Initializes the module, sets up the directory structure, global configuration,
         * and prepares the logger.
         *
         * @param EventDispatcherInterface $eventDispatcher The OpenEMR event dispatcher
         */
        public function __construct(EventDispatcherInterface $eventDispatcher)
        {

            if (empty($kernel)) {
                $kernel = new Kernel();
            }

            // we inject our globals value.
            $this->moduleDirectoryName = basename(dirname(__DIR__));
            $this->eventDispatcher = $eventDispatcher;

            $this->globalsConfig = new QuestGlobalConfig($GLOBALS);
            $this->logger = new SystemLogger();
        }

        /**
         * Returns the path where requisition forms are stored
         *
         * @return string Absolute path to the requisition forms directory
         */
        public static function requisitionFormPath(): string
        {
            $siteDir = $GLOBALS['OE_SITE_DIR'] ?? '';
            if ($siteDir === '') {
                throw new \RuntimeException('OpenEMR site directory (OE_SITE_DIR) is not available.');
            }
            return rtrim($siteDir, '/') . '/documents/labs/';
        }

        /**
         * Subscribes to all necessary events for module functionality
         *
         * Sets up global settings and, if the module is fully configured,
         * registers menu items and subscribes to lab transmission and
         * encounter form events.
         *
         * @return void
         */
        public function subscribeToEvents(): void
        {
            $this->addGlobalSettings();
            // Register headless API routes — always available when module is active
            $this->subscribeToApiRoutes();
            // we only add the rest of our event listeners and configuration if we have been fully setup and configured
            if ($this->globalsConfig->isQuestConfigured()) {
                $this->registerMenuItems();
                $this->subscribeToLabTransmissionEvents();
                $this->subscribeToEncounterFormEvents();
            }
        }

        /**
         * Registers custom REST API routes for headless Quest order submission.
         *
         * Uses the RestApiCreateEvent hook so routes inherit OAuth2 Bearer
         * token authentication from the OpenEMR REST dispatcher.
         *
         * @return void
         */
        public function subscribeToApiRoutes(): void
        {
            $this->eventDispatcher->addListener(RestApiCreateEvent::EVENT_HANDLE, [$this, 'addQuestApiRoutes']);
        }

        /**
         * Event handler that registers Quest API routes into the OpenEMR route map.
         *
         * @param RestApiCreateEvent $event
         * @return void
         */
        public function addQuestApiRoutes(RestApiCreateEvent $event): void
        {
            // POST /api/quest/order — Create and transmit a procedure order to Quest
            $event->addToRouteMap("POST /api/quest/order", function () {
                RestConfig::authorization_check("admin", "users");
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $controller = new QuestOrderRestController();
                $response = $controller->submitOrder($data);
                http_response_code($response['http_status']);
                return $response['body'];
            });

            // GET /api/quest/order/:orderId/documents — Retrieve order documents
            $event->addToRouteMap("GET /api/quest/order/:orderId/documents", function ($orderId) {
                RestConfig::authorization_check("admin", "users");
                $controller = new QuestOrderRestController();
                $response = $controller->getOrderDocuments((int) $orderId);
                http_response_code($response['http_status']);
                return $response['body'];
            });
        }

        /**
         * Subscribes to lab transmission events for order processing
         *
         * Sets up event listeners for lab order transmission and post-order processing
         *
         * @return void
         */
        public function subscribeToLabTransmissionEvents(): void
        {
            $this->eventDispatcher->addListener(QuestLabTransmitEvent::EVENT_LAB_TRANSMIT, [$this, 'sendOrderToQuestLab']);
            $this->eventDispatcher->addListener(QuestLabTransmitEvent::EVENT_LAB_POST_ORDER_LOAD, [$this, 'downloadPdfToDesktop']);
        }

        /**
         * Subscribes to encounter form events for UI integration
         *
         * Sets up event listeners for the encounter form to add specimen label buttons
         *
         * @return void
         */
        public function subscribeToEncounterFormEvents(): void
        {
            $this->eventDispatcher->addListener(EncounterButtonEvent::BUTTON_RENDER, [$this, 'encounterButtonRender']);
        }

        /**
         * Adds specimen label button to encounter forms
         *
         * Event handler that adds a specimen label button to the encounter form
         *
         * @param EncounterButtonEvent $event The encounter button event
         * @return void
         */
        public function encounterButtonRender(EncounterButtonEvent $event): void
        {
            $addButtonEncounterForm = new AddButtonEncounterForm();
            $event->setButton($addButtonEncounterForm->specimenLabelButton());
        }

        /**
         * Processes and sends a lab order to Quest Diagnostics
         *
         * Handles the lab order transmission event, processes the order data,
         * and optionally generates a requisition form if configured.
         *
         * @param QuestLabTransmitEvent $event The lab transmit event containing order data
         * @return void
         */
        public function sendOrderToQuestLab(QuestLabTransmitEvent $event): void
        {
            $order = $event->getOrder(); // get the order HL7 from the event
            $orderId = $event->getOrderId(); // get the order ID from the event
            $requisitionOrder = $order;
            $result = new ProcessLabOrder($order); // create a new process lab order We might want to return errors here
            //This logging is for debugging purposes

            $location = self::requisitionFormPath();
            if (!is_dir($location) && !mkdir($location, 0755, true) && !is_dir($location)) {
                $this->logger->error('Unable to create labs directory for debug output', ['path' => $location]);
            } else {
                file_put_contents($location . 'labOrderResultDebug.txt', print_r($result, true) . PHP_EOL, FILE_APPEND);
            }

            //call to get the requisition document from QuestLab
            if ($GLOBALS['oe_quest_download_requisition']) { // the requisition form is optional and can be turned off
                // Pass both HL7 and order ID for conditional document requests
                $pdf = new ProcessRequisitionDocument($requisitionOrder, $orderId);
                $this->logger->info('Requisition form requested from Quest');
                $this->requisitionFormName = $pdf->sendRequest(); //send request for requisition
                $this->logger->info('Requisition form received, storing in documents table');

                // Store the PDF in the documents table so it is permanently linked
                // to the patient and order (visible in Order Documents UI and FHIR DocumentReference)
                if (!empty($this->requisitionFormName) && !empty($orderId)) {
                    $orderRow = sqlQuery(
                        "SELECT patient_id FROM procedure_order WHERE procedure_order_id = ?",
                        [$orderId]
                    );
                    if (!empty($orderRow['patient_id'])) {
                        $docService = new QuestDocumentService();
                        $docService->storeFromDiskFile(
                            (int) $orderRow['patient_id'],
                            $orderId,
                            $this->requisitionFormName
                        );
                    }
                }
            }
        }

        /**
         * Downloads the requisition PDF form to the user's desktop
         *
         * Initiates the download of the requisition PDF form that was generated
         * during the lab order processing.
         *
         * @return void
         */
        public function downloadPdfToDesktop(): void
        {
            $sendDownload = new DownloadRequisition();
            if (!empty($this->requisitionFormName)) {
                error_log('File name ' . $this->requisitionFormName);
                $sendDownload->downloadLabPdfRequisition($this->requisitionFormName);
            }
        }

        /**
         * Returns the module's global configuration
         *
         * @return QuestGlobalConfig The module's global configuration
         */
        public function getGlobalConfig(): QuestGlobalConfig
        {
            return $this->globalsConfig;
        }

        /**
         * Sets up global settings for the module
         *
         * Registers an event listener to initialize global settings
         * when OpenEMR loads globals.
         *
         * @return void
         */
        public function addGlobalSettings(): void
        {
            $this->eventDispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, [$this, 'addGlobalQuestSettingsSection']);
        }

        /**
         * Creates and configures the Quest Lab global settings section
         *
         * Event handler that adds Quest Lab-specific settings to the
         * OpenEMR global configuration.
         *
         * @param GlobalsInitializedEvent $event The globals initialized event
         * @return void
         */
        public function addGlobalQuestSettingsSection(GlobalsInitializedEvent $event): void
        {
            $service = $event->getGlobalsService();
            $section = xlt("Quest Lab");
            // createSection throws if the tab already exists (e.g. listener runs more than once
            // during globals bootstrap on OpenEMR 8.1+). Match GlobalsService::addUserSpecificTab.
            $metadata = $service->getGlobalsMetadata();
            if (!isset($metadata[$section])) {
                $service->createSection($section, 'Portal');
            }

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

        /**
         * Registers menu items for the module
         *
         * If the module is enabled in the global configuration,
         * adds the menu item event listener.
         *
         * @return void
         */
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

        /**
         * Adds the module's menu item to the OpenEMR menu system
         *
         * Creates and configures the Quest Lab Hub menu item
         * with appropriate permissions and settings.
         *
         * @param MenuEvent $event The menu event
         * @return MenuEvent The modified menu event
         */
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


        /**
         * Gets the public path for the module
         *
         * @return string Path to the module's public directory
         */
        private function getPublicPath()
        {
            return self::MODULE_INSTALLATION_PATH . ($this->moduleDirectoryName ?? '') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
        }

        /**
         * Gets the asset path for the module
         *
         * @return string Path to the module's asset directory
         */
        private function getAssetPath()
        {
            return $this->getPublicPath() . 'assets' . DIRECTORY_SEPARATOR;
        }
    }
