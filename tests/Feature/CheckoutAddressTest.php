<?php

namespace Tests\Feature;

use App\Support\CheckoutAddress;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutAddressTest extends TestCase
{
    public function test_country_specific_paypal_values_are_not_generic_abbreviations(): void
    {
        foreach ([
            ['US', 'California', 'CA'],
            ['CA', 'Québec', 'QC'],
            ['AU', 'New South Wales', 'NSW'],
            ['JP', 'Tokyo', 'TOKYO-TO'],
            ['IN', 'Maharashtra', 'Maharashtra'],
            ['IN', 'Delhi', 'Delhi (NCT)'],
            ['MX', 'Quintana Roo', 'Q ROO'],
            ['IT', 'Roma', 'RM'],
            ['CN', 'Beijing', 'CN-BJ'],
            ['CN', '北京市', 'CN-BJ'],
            ['ID', 'Jakarta', 'ID-JK'],
            ['TH', 'Bangkok', 'Bangkok'],
            ['AR', 'Buenos Aires (Provincia)', 'BUENOS AIRES'],
            ['BR', 'São Paulo', 'SP'],
            ['DE', 'Bavaria', 'Bavaria'],
            ['GB', 'Oxfordshire', 'Oxfordshire'],
        ] as [$country, $input, $expected]) {
            $this->assertSame($expected, CheckoutAddress::validatedState($country, $input));
        }
    }

    public function test_every_canonical_value_and_published_name_round_trips(): void
    {
        foreach (CheckoutAddress::stateRules() as $country => $rules) {
            foreach ($rules as $value => $names) {
                foreach ([$value, ...$names] as $name) {
                    $this->assertSame($value, CheckoutAddress::normalizeState($country, $name), "$country: $name");
                }
            }
        }
    }

    public function test_unknown_or_missing_values_cannot_be_used_for_a_country_with_a_table(): void
    {
        foreach (['ZZ', '', null, 'Lazio'] as $state) {
            $this->assertNull(CheckoutAddress::normalizeState('IT', $state));
        }
        $this->assertNull(CheckoutAddress::normalizeState('CA', 'California'));
        $this->expectException(ValidationException::class);
        CheckoutAddress::validatedState('US', 'ZZ');
    }
}
