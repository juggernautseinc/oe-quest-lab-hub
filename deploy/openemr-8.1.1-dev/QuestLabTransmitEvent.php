<?php

/**
 * @package openemr
 * @link      https://www.open-emr.org
 * @author    Juggernaut Systems Express, <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2023 Juggernaut Systems Express, <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Events\Services;

use Symfony\Contracts\EventDispatcher\Event;

class QuestLabTransmitEvent extends Event
{
    /**
     * This event is triggered when a lab is to be transmitted
     */
    const EVENT_LAB_TRANSMIT = 'lab.transmit';

    /**
     * This event is triggered when a lab requisition form is returned from Quest
     * Requisition form has to be enabled in the globals
     */
    const EVENT_LAB_POST_ORDER_LOAD = 'lab.post_order_load';

    private string $order;
    private ?int $orderId = null;

    /**
     * Constructor for QuestLabTransmitEvent
     *
     * @param string|int $hl7 HL7 order string or patient ID
     * @param int|null $orderId Optional order ID (procedure_order_id)
     */
    public function __construct($hl7, ?int $orderId = null)
    {
        if (is_string($hl7)) {
            $this->order = $hl7;
        } elseif (is_int($hl7)) {
            // For backwards compatibility with pid-only events
            $this->order = (string)$hl7;
        }
        $this->orderId = $orderId;
    }

    /**
     * Get the order HL7 string or patient ID
     *
     * @return string
     */
    public function getOrder(): string
    {
        return $this->order;
    }

    /**
     * Get the order ID (procedure_order_id)
     *
     * @return int|null
     */
    public function getOrderId(): ?int
    {
        return $this->orderId;
    }
}
