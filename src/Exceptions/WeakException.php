<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Exceptions;

use Throwable;

/**
 * A weak exception is a low-importance error: it is still logged on the
 * monitor, but flagged so it does not trigger a Slack notification.
 */
class WeakException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        if ($previous) {
            $message = $message !== '' ? "{$message}: {$previous->getMessage()}" : $previous->getMessage();
            $code = $code ?: (int) $previous->getCode();
        }

        parent::__construct($message, $code, $previous);
    }
}
