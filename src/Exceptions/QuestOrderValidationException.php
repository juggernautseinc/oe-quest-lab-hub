<?php

/**
 * QuestOrderValidationException
 *
 * Custom exception for order validation errors in the Quest Lab Hub module.
 * Carries an array of validation error messages for the API response.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Exceptions;

class QuestOrderValidationException extends \Exception
{
    /**
     * @var string[]
     */
    private array $validationErrors;

    /**
     * @param string[] $validationErrors Array of human-readable validation error messages
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        array $validationErrors,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = 'Order validation failed: ' . implode('; ', $validationErrors);
        parent::__construct($message, $code, $previous);
        $this->validationErrors = $validationErrors;
    }

    /**
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}
