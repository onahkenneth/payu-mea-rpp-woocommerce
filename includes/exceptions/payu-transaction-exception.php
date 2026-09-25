<?php

declare(strict_types=1);

/**
 * Copyright © 2024 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Thrown when PayU declines a transaction request.
 *
 * The exception message is PayU's displayMessage, which is safe to show to the customer.
 * The resultCode and resultMessage carry the merchant-facing reason and are for logs only.
 */
class PayUTransactionException extends Exception
{
    private string $result_code;

    private string $result_message;

    public function __construct(string $display_message, string $result_code = '', string $result_message = '')
    {
        parent::__construct($display_message);

        $this->result_code = $result_code;
        $this->result_message = $result_message;
    }

    public function get_result_code(): string
    {
        return $this->result_code;
    }

    public function get_result_message(): string
    {
        return $this->result_message;
    }
}
