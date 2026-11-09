<?php

namespace Juggernaut\Quest\Module\Exceptions;

/**
 * A Quest Hub request that did not return a usable response. Referenced by PostOrder,
 * ProcessRequisitionDocument, QuestToken and HeadlessOrderService; the class was missing,
 * so every throw site was a fatal "class not found" instead of a reportable error.
 */
class QuestHttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
