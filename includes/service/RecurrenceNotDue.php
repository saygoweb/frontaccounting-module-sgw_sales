<?php

namespace SGW_Sales\service;

/** The recurrence is not due on the date asked: nothing to invoice yet (or any more). */
class RecurrenceNotDue extends \DomainException
{
}
