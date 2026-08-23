<?php

/**
 * Tests for QuestOrderValidationException
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Tests\Unit\Exceptions;

use Juggernaut\Quest\Module\Exceptions\QuestOrderValidationException;
use PHPUnit\Framework\TestCase;

class QuestOrderValidationExceptionTest extends TestCase
{
    /**
     * Test that the exception stores and returns validation errors
     */
    public function testGetValidationErrors(): void
    {
        $errors = ['Field A is required', 'Field B is invalid'];
        $exception = new QuestOrderValidationException($errors);

        $this->assertSame($errors, $exception->getValidationErrors());
    }

    /**
     * Test that getMessage formats the errors into a readable string
     */
    public function testGetMessageFormatsErrors(): void
    {
        $errors = ['Patient ID is required', 'Lab ID is required'];
        $exception = new QuestOrderValidationException($errors);

        $this->assertStringContainsString('Patient ID is required', $exception->getMessage());
        $this->assertStringContainsString('Lab ID is required', $exception->getMessage());
        $this->assertStringContainsString('Order validation failed', $exception->getMessage());
    }

    /**
     * Test with a single error
     */
    public function testSingleError(): void
    {
        $exception = new QuestOrderValidationException(['Only one problem']);

        $this->assertCount(1, $exception->getValidationErrors());
        $this->assertSame('Only one problem', $exception->getValidationErrors()[0]);
    }

    /**
     * Test with an empty error array
     */
    public function testEmptyErrors(): void
    {
        $exception = new QuestOrderValidationException([]);

        $this->assertSame([], $exception->getValidationErrors());
        $this->assertStringContainsString('Order validation failed', $exception->getMessage());
    }

    /**
     * Test that the exception extends the base Exception class
     */
    public function testExtendsException(): void
    {
        $exception = new QuestOrderValidationException(['test']);

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    /**
     * Test that exception chaining works
     */
    public function testPreviousException(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new QuestOrderValidationException(['test'], 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
