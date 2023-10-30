# Quest Quantum Hub Lab Module for OpenEMR

The Quest Quantum Hub module will allow for a bi-directional or HL7 results-only interface
with Quest Quantum Hub.
The module is a seamless integration with the existing interface.
The current interface will be used as usual. The module talks with Quest through a
series of API calls to their hub. The Quest Hub will auto-generate a requisition
form for each order. The requisition feature can be disabled in the config if not desired.
PDF results will be retrieved manually through the Quest user portal.

# Getting Started
The use of this module does require that Quest is contacted before enabling this module. Quest will
contact our office to schedule the turn-up of this module.

# Installation

Using composer:
Contact us for a access token.
Open interface/modules/custom_modules and run this command:

     git clone https://github.com/juggernautseinc/oe-quest-lab-hub.git

You should be prompted for a password. Use the access token provided by us.


# Contributing
If you want to contribute to this module, please get in touch with me at sherwingaddis@gmail.com.

