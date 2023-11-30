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

class ProcessLabOrder
{
    private string|bool $orderHl7;
    public function __construct($orderHl7)
    {
        $hl7Order = new PostOrder();
        //encode the order for transmission
        $encodedOrder = base64_encode($orderHl7);
        //transmit the order
        $this->orderHl7 = $hl7Order->sendOrder($encodedOrder);
        return $this->orderHl7;
    }
}
