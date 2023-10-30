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
Open composer.json in your project and add the following 

    {
        "require": {
            "juggernautseinc/oe-quest-lab-hub": "dev-main"
        },
        "config": {
            "github-oauth": {
                "github.com": "YOUR_GITHUB_TOKEN"
            }
        }
    }

Navigate to the interface/modules/custom_modules directory and run the following command:

    composer install





# Contributing
If you want to contribute to this module, please get in touch with me at sherwingaddis@gmail.com.

