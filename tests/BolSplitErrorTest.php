<?php

namespace Dashed\DashedEcommerceBol\Tests;

use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommerceBol\Classes\Bol;

/**
 * Bol weigert een verzendmelding met regels die bij verschillende (deel)orders
 * horen en noemt in de foutmelding welke regels samen mogen. Kale PHPUnit-klasse:
 * de parser is zuiver en het pakket-testharnas boot niet zonder cms().
 */
class BolSplitErrorTest extends TestCase
{
    private function parse(string $message): ?array
    {
        $method = new ReflectionMethod(Bol::class, 'parseBolSplitError');
        $method->setAccessible(true);

        return $method->invoke(null, $message);
    }

    public function test_de_melding_van_november_2026_met_order_item_id_per_groep(): void
    {
        $groups = $this->parse('Your request has order item id(s) that are not in the same order. Please change the request into separate request(s) with order item id 4017460168. And with order item id 4017460165.');

        $this->assertSame([['4017460168'], ['4017460165']], $groups);
    }

    public function test_de_oudere_melding_met_meerdere_ids_per_groep(): void
    {
        $groups = $this->parse('Your request has order item id(s) that are not in the same shipment. Please split into order item id(s) 111, 222. And order item id(s) 333');

        $this->assertSame([['111', '222'], ['333']], $groups);
    }

    public function test_een_andere_fout_is_geen_splitsing(): void
    {
        $this->assertNull($this->parse('Shipment already exists.'));
        $this->assertNull($this->parse('Your request has order item id(s) that are not in the same order.'));
    }
}
