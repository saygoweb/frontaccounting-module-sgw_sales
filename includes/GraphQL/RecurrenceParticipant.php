<?php

namespace SGW_Sales\GraphQL;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Extension\SalesOrderParticipant;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DateConversion;

/**
 * An order's recurring schedule — this module's sales_recurring row — kept in step
 * with the order by the FrontAccounting GraphQL module's SalesOrderService, inside
 * the order's transaction (Release 4 spec §2.4, §3.2).
 *
 * Written with FrontAccounting's db_query(), on FrontAccounting's connection — not
 * with SalesRecurringModel, which writes on Anorm's own PDO connection, outside the
 * order's transaction. That is this module's second write path to the table; the
 * page keeps using the model. The columns mean what sales_order_entry.php makes them
 * mean (:549-576): occur is "MM-DD" for a yearly schedule and the day of the month
 * for a monthly one; dt_next is the generation service's own state, NULL until it
 * computes it (includes/service/RecurrenceSchedule.php).
 *
 * Moved from the GraphQL module's RecurringSchedule (Release 2) with two changes:
 * it no longer asks whether sgw_sales is active — the module only loads this
 * extension for a company where it is — and it keeps what a delete or a close
 * replaced, so the mutation returns the schedule as it was (snapshot()).
 *
 * One per request: always from the request's container.
 */
final class RecurrenceParticipant implements SalesOrderParticipant
{
    private ?bool $upgraded = null;

    /** @var array<int, array<string, mixed>|null> the schedule before this request deleted or closed its order */
    private array $before = [];

    public function isAvailable(): bool
    {
        return $this->isUpgraded();
    }

    /**
     * Active but without update_1.4.sql's table shape is the installation's problem
     * (FA_REJECTED) — activate_extension() applies it from Release 4 on; a company
     * activated earlier must be re-activated.
     */
    public function assertWritable(string $field): void
    {
        if (!$this->isUpgraded()) {
            $message = 'sgw_sales\' table ' . CompanyContext::prefix() . 'sales_recurring is missing or not '
                . 'upgraded: apply modules/sgw_sales/sql/update_1.4.sql.';
            throw new FaRejected($message, [$message]);
        }
    }

    public function validate(array $input): void
    {
        if (!array_key_exists('recurring', $input) || $input['recurring'] === null) {
            return;
        }
        // Refused before anything is written: a table not upgraded, or a bad schedule.
        $this->assertWritable('recurring');
        self::toColumns($input['recurring']);
    }

    /**
     * This module keys its relaxations to its "Recurring Order" box: an order that has
     * a schedule, or is being given one now, keeps its header editable once delivered
     * and has no delivered-quantity floor (sales_order_entry.php :616-617, :827).
     */
    public function isRelaxed(int $orderId, array $input): bool
    {
        return (array_key_exists('recurring', $input) && $input['recurring'] !== null)
            || $this->read($orderId) !== null;
    }

    public function afterCreate(int $orderId, array $input): void
    {
        if (array_key_exists('recurring', $input) && $input['recurring'] !== null) {
            $this->write($orderId, $input['recurring']);
        }
    }

    public function afterUpdate(int $orderId, array $input): void
    {
        if (array_key_exists('recurring', $input) && $input['recurring'] !== null) {
            $this->write($orderId, $input['recurring']);
        }
    }

    public function afterDelete(int $orderId): void
    {
        $this->before[$orderId] = $this->read($orderId);
        $this->delete($orderId);
    }

    public function afterClose(int $orderId): void
    {
        $this->before[$orderId] = $this->read($orderId);
        $this->end($orderId, DateConversion::fromFa(\Today()));
    }

    /**
     * What the `recurring` field shows: the schedule as it was before this request
     * deleted or closed the order (a delete returns the order as it was) — unless a
     * later write in the request replaced it — otherwise the schedule now.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(int $orderNo): ?array
    {
        if (array_key_exists($orderNo, $this->before)) {
            return $this->before[$orderNo];
        }

        return $this->read($orderNo);
    }

    /**
     * @return array<string, mixed>|null a Recurrence, or null: none, or the table not upgraded
     */
    public function read(int $orderNo): ?array
    {
        if (!$this->isUpgraded()) {
            return null;
        }
        $row = db_fetch(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo),
            'could not read the recurring schedule'
        ));

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Set or replace an order's schedule. dt_next is kept unless the rhythm changes.
     *
     * @param array<string, mixed> $recurrence a RecurrenceInput
     */
    public function write(int $orderNo, array $recurrence): void
    {
        $this->assertWritable('recurring');
        $c = self::toColumns($recurrence);
        // A snapshot lasts until the next write to its order in this request
        // (Checkpoint B M-1): after it, the field shows what was written.
        unset($this->before[$orderNo]);
        $existing = db_fetch(db_query(
            'SELECT * FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo) . ' FOR UPDATE',
            'could not read the recurring schedule'
        ));

        if (!$existing) {
            db_query(
                'INSERT INTO ' . TB_PREF . 'sales_recurring'
                . ' (trans_no, dt_start, dt_end, dt_next, auto, every, repeats, occur)'
                . ' VALUES (' . db_escape($orderNo) . ', ' . db_escape($c['dt_start'])
                . ', ' . self::sqlDate($c['dt_end']) . ', NULL, ' . $c['auto'] . ', ' . $c['every']
                . ', ' . db_escape($c['repeats']) . ', ' . db_escape($c['occur']) . ')',
                'could not add the recurring schedule'
            );

            return;
        }

        $rhythmChanged = $existing['dt_start'] !== $c['dt_start']
            || $existing['repeats'] !== $c['repeats']
            || (int) $existing['every'] !== $c['every']
            || (string) $existing['occur'] !== $c['occur'];
        db_query(
            'UPDATE ' . TB_PREF . 'sales_recurring SET dt_start = ' . db_escape($c['dt_start'])
            . ', dt_end = ' . self::sqlDate($c['dt_end'])
            . ', auto = ' . $c['auto'] . ', every = ' . $c['every']
            . ', repeats = ' . db_escape($c['repeats']) . ', occur = ' . db_escape($c['occur'])
            . ($rhythmChanged ? ', dt_next = NULL' : '')
            . ' WHERE trans_no = ' . db_escape($orderNo),
            'could not update the recurring schedule'
        );
    }

    public function delete(int $orderNo): void
    {
        if (!$this->isUpgraded()) {
            return;
        }
        db_query(
            'DELETE FROM ' . TB_PREF . 'sales_recurring WHERE trans_no = ' . db_escape($orderNo),
            'could not delete the recurring schedule'
        );
    }

    /**
     * End a schedule on $isoDate, unless it already ends earlier.
     */
    public function end(int $orderNo, string $isoDate): void
    {
        if (!$this->isUpgraded()) {
            return;
        }
        $date = db_escape(DateConversion::iso($isoDate));
        db_query(
            'UPDATE ' . TB_PREF . "sales_recurring SET dt_end = $date WHERE trans_no = " . db_escape($orderNo)
            . " AND (dt_end IS NULL OR dt_end > $date)",
            'could not end the recurring schedule'
        );
    }

    /**
     * A RecurrenceInput as sales_recurring columns, validated. repeats arrives as the
     * enum's value ('month' | 'year'), which is this module's own.
     *
     * @param array<string, mixed> $r
     * @return array{dt_start: string, dt_end: ?string, auto: int, every: int, repeats: string, occur: string}
     */
    public static function toColumns(array $r): array
    {
        if (!isset($r['start'])) {
            throw new BadInput('A schedule needs a start date.', 'recurring.start');
        }
        $start = DateConversion::iso($r['start'], 'recurring.start');
        $end = isset($r['end']) ? DateConversion::iso($r['end'], 'recurring.end') : null;
        if ($end !== null && $end < $start) {
            throw new BadInput('A schedule cannot end before it starts.', 'recurring.end');
        }
        $repeats = (string) ($r['repeats'] ?? '');
        if (!in_array($repeats, ['month', 'year'], true)) {
            throw new BadInput('A schedule repeats MONTH or YEAR.', 'recurring.repeats');
        }
        // every is tinyint(4) in sales_recurring.
        $every = (int) ($r['every'] ?? 0);
        if ($every < 1 || $every > 127) {
            throw new BadInput('every must be from 1 to 127.', 'recurring.every');
        }

        if ($repeats === 'month') {
            $day = $r['day'] ?? null;
            if ($day === null || (int) $day < 1 || (int) $day > 31) {
                throw new BadInput('A monthly schedule needs its day of the month, from 1 to 31.', 'recurring.day');
            }
            if (isset($r['monthDay'])) {
                throw new BadInput('monthDay is for a yearly schedule; a monthly one takes day.', 'recurring.monthDay');
            }
            $occur = (string) (int) $day;
        } else {
            $monthDay = (string) ($r['monthDay'] ?? '');
            // 2000 is a leap year: 02-29 is a valid yearly date.
            if (
                !preg_match('/^(\d{2})-(\d{2})\z/', $monthDay, $m)
                || !checkdate((int) $m[1], (int) $m[2], 2000)
            ) {
                throw new BadInput('A yearly schedule needs its date as MM-DD.', 'recurring.monthDay');
            }
            if (isset($r['day'])) {
                throw new BadInput('day is for a monthly schedule; a yearly one takes monthDay.', 'recurring.day');
            }
            $occur = $monthDay;
        }

        return [
            'dt_start' => $start,
            'dt_end' => $end,
            'auto' => ($r['auto'] ?? true) ? 1 : 0,
            'every' => $every,
            'repeats' => $repeats,
            'occur' => $occur,
        ];
    }

    /**
     * @param array<string, mixed> $row a sales_recurring row
     * @return array<string, mixed> a Recurrence
     */
    public static function fromRow(array $row): array
    {
        $monthly = $row['repeats'] === 'month';

        return [
            'start' => DateConversion::fromSql($row['dt_start']),
            'end' => DateConversion::fromSql($row['dt_end'] ?? null),
            'next' => DateConversion::fromSql($row['dt_next'] ?? null),
            'repeats' => $row['repeats'],
            'every' => (int) $row['every'],
            'day' => $monthly ? (int) $row['occur'] : null,
            'monthDay' => $monthly ? null : (string) $row['occur'],
            'auto' => (bool) $row['auto'],
        ];
    }

    private static function sqlDate(?string $date): string
    {
        return $date === null ? 'NULL' : db_escape($date);
    }

    /**
     * update_1.4.sql's shape: an AUTO_INCREMENT id and a unique trans_no (one schedule
     * per order). Asked once per request.
     */
    private function isUpgraded(): bool
    {
        if ($this->upgraded === null) {
            $table = db_escape(CompanyContext::prefix() . 'sales_recurring');
            $unique = db_fetch_row(db_query(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = $table AND COLUMN_NAME = 'trans_no' AND NON_UNIQUE = 0",
                'could not inspect sales_recurring'
            ));
            $autoId = db_fetch_row(db_query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . " AND TABLE_NAME = $table AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
                'could not inspect sales_recurring'
            ));
            $this->upgraded = (int) $unique[0] > 0 && (int) $autoId[0] > 0;
        }

        return $this->upgraded;
    }
}
