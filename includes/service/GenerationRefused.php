<?php

namespace SGW_Sales\service;

/**
 * A check refused the generation before anything was written: the date outside the
 * fiscal year, a missing exchange rate, a customer on hold, not enough stock, or
 * FrontAccounting not writing a document.
 */
class GenerationRefused extends \RuntimeException
{
    /** @var string|null */
    private $field;

    /** @var string[] */
    private $messages;

    /**
     * @param string|null $field the input the refusal is about ('date'), if any
     * @param string[] $messages FrontAccounting's messages, if it gave any
     */
    public function __construct(string $message, ?string $field = null, array $messages = [])
    {
        parent::__construct($message);
        $this->field = $field;
        $this->messages = $messages === [] ? [$message] : $messages;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /** @return string[] */
    public function messages(): array
    {
        return $this->messages;
    }
}
