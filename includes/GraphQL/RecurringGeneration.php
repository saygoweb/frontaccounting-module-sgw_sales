<?php

namespace SGW_Sales\GraphQL;

use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\IntKey;
use FA\GraphQL\Fa\Service\ServiceCall;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\service\GeneratedInvoice;
use SGW_Sales\service\GenerationRefused;
use SGW_Sales\service\RecurrenceEnded;
use SGW_Sales\service\RecurrenceNotDue;
use SGW_Sales\service\RecurrenceNotFound;
use SGW_Sales\service\RecurringInvoiceService;

/**
 * recurringDueList and recurringGenerate (Release 4 spec §4.2), over the one
 * generation service the page also uses.
 */
final class RecurringGeneration
{
    private ExtensionContext $context;
    private RecurringInvoiceService $service;

    public function __construct(ExtensionContext $context, ?RecurringInvoiceService $service = null)
    {
        $this->context = $context;
        $this->service = $service ?? new RecurringInvoiceService();
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function due(array $args): array
    {
        Guard::require('SA_SALESTRANSVIEW');
        $asOf = $args['asOf'] ?? new \DateTimeImmutable('today');
        // The request's connection: the token's company, read outside any write.
        $pdo = $this->context->container()->get(\PDO::class);

        return array_map([self::class, 'dueRow'], $this->service->due($asOf, false, $pdo));
    }

    /**
     * @return array<string, mixed>
     */
    public static function dueRow(GenerateRecurringModel $model): array
    {
        $monthly = $model->repeats === 'month';

        return [
            'orderId' => (int) $model->orderNo,
            'customerId' => (int) $model->debtorNo,
            'branchId' => (int) $model->branchCode,
            'reference' => $model->reference,
            'customerRef' => $model->customerRef === '' ? null : $model->customerRef,
            // Never generated: due from its start (the due rule), so that is its next.
            'next' => DateConversion::fromSql($model->dtNext ?: $model->dtStart),
            'repeats' => $model->repeats,
            'every' => (int) $model->every,
            'day' => $monthly ? (int) $model->occur : null,
            'monthDay' => $monthly ? null : (string) $model->occur,
            'end' => DateConversion::fromSql($model->dtEnd),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function generate(array $args): array
    {
        Guard::require('SA_SALESDELIVERY');
        Guard::require('SA_SALESINVOICE');
        $items = array_values($args['input']);
        foreach ($items as $item) {
            if (!empty($item['email'])) {
                Guard::require('SA_SALESTRANSVIEW'); // as invoiceEmail
                break;
            }
        }

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->one($item);
        }
        return $results;
    }

    /**
     * One item, on its own: its own transaction under the document lock, then its
     * email after the commit.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function one(array $item): array
    {
        $result = [
            'orderId' => (string) $item['orderId'],
            'invoiceId' => null,
            'deliveryId' => null,
            'next' => null,
            'email' => null,
            'error' => null,
        ];
        try {
            $orderNo = IntKey::parse($item['orderId'], 'orderId');
            $date = $item['date'];
            /** @var GeneratedInvoice $generated */
            $generated = DocumentLock::run(function () use ($orderNo, $date) {
                return ServiceCall::run(function () use ($orderNo, $date) {
                    return $this->service->generate($orderNo, $date);
                });
            });
        } catch (\Throwable $e) {
            $result['error'] = self::error($e);
            return $result;
        }

        $result['orderId'] = (string) $generated->orderNo;
        $result['invoiceId'] = (string) $generated->invoiceNo;
        $result['deliveryId'] = (string) $generated->deliveryNo;
        $result['next'] = DateConversion::fromSql($generated->dtNext);
        if (!empty($item['email'])) {
            $result['email'] = $this->email($generated->invoiceNo);
        }
        return $result;
    }

    /**
     * The invoice is written whatever becomes of the email: a failure is reported,
     * never turned into an item error.
     *
     * @return array<string, mixed>
     */
    private function email(int $invoiceNo): array
    {
        try {
            return $this->context->mailer()->send([$invoiceNo])[0];
        } catch (\Throwable $e) {
            error_log('sgw_sales recurringGenerate: invoice ' . $invoiceNo . ' not emailed: '
                . get_class($e) . ': ' . $e->getMessage());
            return [
                'id' => $invoiceNo,
                'sent' => false,
                'recipient' => null,
                'messages' => [$e instanceof FaRejected || $e instanceof NotFound
                    ? $e->getMessage()
                    : 'The invoice was written but could not be emailed (see the server log).'],
            ];
        }
    }

    /**
     * @return array{code: string, message: string, field: ?string}
     */
    public static function error(\Throwable $e): array
    {
        if ($e instanceof RecurrenceNotFound || $e instanceof NotFound) {
            return ['code' => 'NOT_FOUND', 'message' => $e->getMessage(), 'field' => 'orderId'];
        }
        if ($e instanceof RecurrenceNotDue) {
            return ['code' => 'NOT_DUE', 'message' => $e->getMessage(), 'field' => null];
        }
        if ($e instanceof RecurrenceEnded) {
            return ['code' => 'ENDED', 'message' => $e->getMessage(), 'field' => null];
        }
        if ($e instanceof GenerationRefused) {
            return $e->field() !== null
                ? ['code' => 'BAD_INPUT', 'message' => $e->getMessage(), 'field' => $e->field()]
                : ['code' => 'FA_REJECTED', 'message' => $e->getMessage(), 'field' => null];
        }
        if ($e instanceof BadInput) {
            return ['code' => 'BAD_INPUT', 'message' => $e->getMessage(), 'field' => $e->field()];
        }
        if ($e instanceof FaRejected) {
            return ['code' => 'FA_REJECTED', 'message' => $e->getMessage(), 'field' => null];
        }
        error_log('sgw_sales recurringGenerate: ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['code' => 'INTERNAL', 'message' => 'Internal server error', 'field' => null];
    }
}
