<?php

namespace SGW_Sales\Tests\GraphQL\Integration;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use SGW_Sales\Tests\GraphQL\ExtensionTestCase;

/**
 * Spec §3.3: with sgw_sales active, `recurring` is exactly Release 2's — names,
 * types, nullability, defaults, descriptions. The fixture was captured from the
 * GraphQL module's own schema before recurrence moved here
 * (CAPTURE_RECURRENCE_SNAPSHOT=1 while the module still served it).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurrenceSchemaSnapshotTest extends ExtensionTestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/recurrence-schema.json';

    private const QUERY = <<<'GQL'
fragment T on __Type { kind name ofType { kind name ofType { kind name ofType { kind name } } } }
{
  recurrence: __type(name: "Recurrence") { name description fields { name description type { ...T } } }
  recurrenceInput: __type(name: "RecurrenceInput") {
    name description inputFields { name description defaultValue type { ...T } }
  }
  repeats: __type(name: "RecurrenceRepeats") { name description enumValues { name description } }
  order: __type(name: "SalesOrderType") { fields { name description type { ...T } } }
  create: __type(name: "SalesOrderCreateInput") { inputFields { name description defaultValue type { ...T } } }
  update: __type(name: "SalesOrderUpdateInput") { inputFields { name description defaultValue type { ...T } } }
}
GQL;

    public function testTheRecurrenceSchemaIsRelease2s(): void
    {
        if (getenv('CAPTURE_RECURRENCE_SNAPSHOT') === '1') {
            $this->capture();

            return;
        }
        $this->requireExtensionServesRecurrence();

        $this->assertSame(
            json_decode((string) file_get_contents(self::FIXTURE), true),
            $this->recurrenceSchema()
        );
    }

    /**
     * Once, before Task 3: writes the fixture from the module's own recurrence.
     */
    private function capture(): void
    {
        if ($this->extensionLoaded()) {
            $this->fail('Capture from the module as it was: the sgw_sales extension already serves recurring.');
        }
        if (!is_dir(dirname(self::FIXTURE))) {
            mkdir(dirname(self::FIXTURE), 0777, true);
        }
        file_put_contents(
            self::FIXTURE,
            json_encode($this->recurrenceSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function recurrenceSchema(): array
    {
        $result = GraphQL::executeQuery($this->container->get(Schema::class), self::QUERY)->toArray();
        $this->assertArrayNotHasKey('errors', $result);
        $data = $result['data'];
        $pick = function (?array $fields): array {
            $picked = array_values(array_filter($fields ?? [], function (array $field): bool {
                return $field['name'] === 'recurring';
            }));

            return $picked;
        };

        return [
            'Recurrence' => $data['recurrence'],
            'RecurrenceInput' => $data['recurrenceInput'],
            'RecurrenceRepeats' => $data['repeats'],
            'SalesOrderType.recurring' => $pick($data['order']['fields'] ?? null),
            'SalesOrderCreateInput.recurring' => $pick($data['create']['inputFields'] ?? null),
            'SalesOrderUpdateInput.recurring' => $pick($data['update']['inputFields'] ?? null),
        ];
    }
}
