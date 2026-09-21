<?php

namespace SGW_Sales\service;

use SGW_Sales\db\SalesRecurringModel;

/**
 * When a recurrence falls due, and the period an invoice for it covers.
 *
 * Dates in, dates out: nothing here touches the database or FrontAccounting.
 * $model is anything carrying dtStart, dtNext, repeats, every and occur - a
 * SalesRecurringModel or a GenerateRecurringModel.
 */
class RecurrenceSchedule
{
    /**
     * The latest occurrence on or before $date.
     * @param object $model
     * @param \DateTime $date
     * @return \DateTime
     */
    public static function dateBefore($model, $date)
    {
        $result = clone $date;
        switch ($model->repeats) {
            case SalesRecurringModel::REPEAT_YEARLY:
                $occurParts = explode('-', $model->occur);
                $result->setDate($date->format('Y'), $occurParts[0], $occurParts[1]);
                while ($result > $date) {
                    $result->sub(new \DateInterval("P1Y"));
                }
                break;
            case SalesRecurringModel::REPEAT_MONTHLY:
                $result->setDate($date->format('Y'), $date->format('m'), $model->occur);
                while ($result > $date) {
                    $result->sub(new \DateInterval("P1M"));
                }
                break;
        }
        return $result;
    }

    /**
     * The earliest occurrence on or after $date, without using 'every'.
     * @param object $model
     * @param \DateTime $date
     * @return \DateTime
     */
    public static function dateAfter($model, $date)
    {
        $result = clone $date;
        switch ($model->repeats) {
            case SalesRecurringModel::REPEAT_YEARLY:
                $occurParts = explode('-', $model->occur);
                $result->setDate($date->format('Y'), $occurParts[0], $occurParts[1]);
                while ($result < $date) {
                    $result->add(new \DateInterval("P1Y"));
                }
                break;
            case SalesRecurringModel::REPEAT_MONTHLY:
                $result->setDate($date->format('Y'), $date->format('m'), $model->occur);
                while ($result < $date) {
                    $result->add(new \DateInterval("P1M"));
                }
                break;
        }
        return $result;
    }

    /**
     * The next occurrence after $date, 'every' periods on from the one before it.
     * @param object $model
     * @param \DateTime $date
     * @return \DateTime
     */
    public static function nextDateAfter($model, $date)
    {
        $result = self::dateBefore($model, $date);
        switch ($model->repeats) {
            case SalesRecurringModel::REPEAT_YEARLY:
                $result->add(new \DateInterval("P" . $model->every . "Y"));
                break;
            case SalesRecurringModel::REPEAT_MONTHLY:
                $result->add(new \DateInterval("P" . $model->every . "M"));
                break;
        }
        return $result;
    }

    /**
     * @param object $model
     * @return \DateTime
     */
    public static function nextDate($model)
    {
        if (!$model->dtNext) {
            return self::dateBefore($model, new \DateTime($model->dtStart));
        }
        return self::nextDateAfter($model, new \DateTime($model->dtNext));
    }

    /**
     * The comment on an invoice generated on $today: the period it covers.
     * @param object $model
     * @param \DateTime $today
     * @return string
     */
    public static function comment($model, $today)
    {
        $startDate = new \DateTime();
        switch ($model->repeats) {
            case SalesRecurringModel::REPEAT_YEARLY:
                $parts = explode('-', $model->dtStart);
                $startDate->setDate($today->format('Y'), $parts[1], $parts[2]);
                $interval = $today->diff($startDate, true); // absolute so always positive
                if ($interval->days > 365 / 2) {
                    $startDate->add(new \DateInterval("P1Y"));
                }
                $endDate = clone $startDate;
                $endDate->add(new \DateInterval("P" . $model->every . "Y"));
                $endDate->sub(new \DateInterval("P1D"));
                break;
            case SalesRecurringModel::REPEAT_MONTHLY:
                $parts = explode('-', $model->dtStart);
                $startDate->setDate($today->format('Y'), $today->format('m'), $parts[2]);
                // The below is likely wrong, but currently unused.
                while ($startDate > $today) {
                    $startDate->sub(new \DateInterval("P1M"));
                }
                $endDate = clone $startDate;
                $endDate->add(new \DateInterval("P" . $model->every . "M"));
                $endDate->sub(new \DateInterval("P1D"));
                break;
            default:
                throw new \DomainException("Unknown recurrence '" . $model->repeats . "'");
        }
        $dateFormat = 'j F Y';
        return _("Invoice for period ") . $startDate->format($dateFormat) . _(' to ') . $endDate->format($dateFormat);
    }
}
