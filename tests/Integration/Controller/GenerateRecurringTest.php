<?php

namespace SGW_Sales\Tests\Integration\Controller;

use FA\GraphQL\Fa\FaMessages;
use SGW_Sales\controller\GenerateRecurring;
use SGW_Sales\db\GenerateRecurringModel;
use SGW_Sales\service\RecurringInvoiceService;
use SGW_Sales\Tests\Integration\FaTestCase;

/**
 * The Generate Recurring Invoices page's controller, its view a mock, on the real
 * service (emailInvoice() aside: tests/Http/GenerateInvoiceTest emails through
 * the page).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GenerateRecurringTest extends FaTestCase
{
    private const VIEW = ['viewList', 'tableRow', 'generatedInvoice'];

    /**
     * @return RecurringInvoiceService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function service(): RecurringInvoiceService
    {
        return $this->getMockBuilder(RecurringInvoiceService::class)->onlyMethods(['emailInvoice'])->getMock();
    }

    /**
     * The order numbers table() passes to the view's tableRow().
     *
     * @return int[]
     */
    private function tabled(GenerateRecurring $controller): array
    {
        $listed = [];
        $view = $this->view(self::VIEW);
        $view->method('tableRow')->willReturnCallback(function (GenerateRecurringModel $model) use (&$listed) {
            $listed[] = (int) $model->orderNo;
        });
        $property = new \ReflectionProperty(GenerateRecurring::class, '_view');
        $property->setAccessible(true);
        $property->setValue($controller, $view);
        $controller->table();

        return $listed;
    }

    public function testWithNothingPostedItListsTheDueOrders(): void
    {
        $due = $this->unrecurredOrder();
        $this->monthly($due);
        $view = $this->view(self::VIEW);
        $view->expects($this->once())->method('viewList');
        $view->expects($this->never())->method('generatedInvoice');
        $controller = new GenerateRecurring($view, $this->service());

        $controller->run();

        $this->assertContains($due, $this->tabled($controller));
    }

    public function testTheListLeavesOutWhatIsNotDueUntilShowAllIsTicked(): void
    {
        $later = $this->unrecurredOrder();
        $this->monthly($later, (new \DateTime('first day of next month'))->format('Y-m-d'));
        $controller = new GenerateRecurring($this->view(self::VIEW), $this->service());
        $controller->run();
        $this->assertNotContains($later, $this->tabled($controller));

        $_POST['_show_all_update'] = 1;
        $_POST['show_all'] = 1;
        $controller = new GenerateRecurring($this->view(self::VIEW), $this->service());
        $controller->run();

        $this->assertContains($later, $this->tabled($controller));
    }

    public function testSelectAllTicksEveryListedOrderAndClearingItUnticksThem(): void
    {
        $due = $this->unrecurredOrder();
        $this->monthly($due);

        $_POST['_select_all_update'] = 1;
        $_POST['select_all'] = 1;
        $controller = new GenerateRecurring($this->view(self::VIEW), $this->service());
        $controller->run();
        $this->tabled($controller);
        $this->assertSame(GenerateRecurring::FORCE_CHECK, $_POST['s_' . $due]);

        unset($_POST['select_all']);
        $controller = new GenerateRecurring($this->view(self::VIEW), $this->service());
        $controller->run();
        $this->tabled($controller);
        $this->assertSame(GenerateRecurring::FORCE_CLEAR, $_POST['s_' . $due]);
    }

    public function testWithoutSelectAllTheTicksAreLeftAsPosted(): void
    {
        $due = $this->unrecurredOrder();
        $this->monthly($due);
        $_POST['s_' . $due] = 'as posted';
        $controller = new GenerateRecurring($this->view(self::VIEW), $this->service());
        $controller->run();

        $this->tabled($controller);

        $this->assertSame('as posted', $_POST['s_' . $due]);
    }

    public function testGenerateInvoicesInvoicesTheTickedOrderEmailsItAndMovesItOn(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo);
        $service = $this->service();
        $service->expects($this->once())->method('emailInvoice');
        $view = $this->view(self::VIEW);
        $view->expects($this->once())->method('generatedInvoice')->with($orderNo);
        $view->expects($this->never())->method('viewList');
        $_POST = ['GenerateInvoices' => 1, 's_' . $orderNo => 1, 's_999999' => 0];
        $invoices = $this->documentCount(ST_SALESINVOICE, $orderNo);
        $deliveries = $this->documentCount(ST_CUSTDELIVERY, $orderNo);

        (new GenerateRecurring($view, $service))->run();

        $this->assertSame([], FaMessages::errors());
        $this->assertSame($invoices + 1, $this->documentCount(ST_SALESINVOICE, $orderNo));
        $this->assertSame($deliveries + 1, $this->documentCount(ST_CUSTDELIVERY, $orderNo));
        $this->assertGreaterThan(self::today(), $this->recurrenceRow($orderNo)['dt_next']);
    }

    public function testAnOrderNotDueIsReportedAndNotInvoiced(): void
    {
        $orderNo = $this->unrecurredOrder();
        $this->monthly($orderNo, (new \DateTime('first day of next month'))->format('Y-m-d'));
        $service = $this->service();
        $service->expects($this->never())->method('emailInvoice');
        $view = $this->view(self::VIEW);
        $view->expects($this->never())->method('generatedInvoice');
        $_POST = ['GenerateInvoices' => 1, 's_' . $orderNo => 1];
        $invoices = $this->documentCount(ST_SALESINVOICE, $orderNo);

        (new GenerateRecurring($view, $service))->run();

        $errors = implode("\n", FaMessages::errors());
        $this->assertStringContainsString("Sales order $orderNo was not invoiced", $errors);
        $this->assertSame($invoices, $this->documentCount(ST_SALESINVOICE, $orderNo));
    }

    public function testWithShowAllTickedAnOrderNotYetDueIsInvoicedEarly(): void
    {
        $orderNo = $this->unrecurredOrder();
        $next = (new \DateTime('first day of next month'))->format('Y-m-d');
        $this->monthly($orderNo, $next);
        $view = $this->view(self::VIEW);
        $view->expects($this->once())->method('generatedInvoice')->with($orderNo);
        $_POST = ['GenerateInvoices' => 1, 'show_all' => 1, 's_' . $orderNo => 1];
        $invoices = $this->documentCount(ST_SALESINVOICE, $orderNo);

        (new GenerateRecurring($view, $this->service()))->run();

        $this->assertSame([], FaMessages::errors());
        $this->assertSame($invoices + 1, $this->documentCount(ST_SALESINVOICE, $orderNo));
        $this->assertGreaterThan($next, $this->recurrenceRow($orderNo)['dt_next']);
    }
}
