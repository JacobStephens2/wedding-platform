<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;
use Wedding\GuestList;

final class GuestListTest extends TestCase
{
    public function testReadsGuestsBelowSpreadsheetSummaryRows(): void
    {
        $csv = <<<'CSV'
            Total invited,3,,,
            Attending,2,,,
            First Name,Last Name,Group,id,Mailing Group
            Ada, Lovelace ,Family,g1,7
            Charles,Babbage,Friends,g2,
            CSV;

        $guests = GuestList::fromCsv($csv)->guests();

        $this->assertSame([
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'group_name' => 'Family', 'guest_id' => 'g1', 'mailing_group' => 7],
            ['first_name' => 'Charles', 'last_name' => 'Babbage', 'group_name' => 'Friends', 'guest_id' => 'g2', 'mailing_group' => null],
        ], $guests);
    }

    public function testRowsWithoutAFirstNameAreNotGuests(): void
    {
        $csv = <<<'CSV'
            First Name,Last Name,Group,id,Mailing Group
            ,Spacer,,,
            Ada,Lovelace,Family,g1,7
            CSV;

        $names = array_column(GuestList::fromCsv($csv)->guests(), 'first_name');

        $this->assertSame(['Ada'], $names);
    }

    public function testGuestsNamesTheMissingColumns(): void
    {
        $list = GuestList::fromCsv("First Name,Last Name,Mailing Group\nAda,Lovelace,7\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing column(s): Group, id');

        $list->guests();
    }

    public function testFirstRealAddressInEachMailingGroupWins(): void
    {
        $csv = <<<'CSV'
            First Name,Mailing Group,Address 1,Address 2,City,State,ZIP,Country
            Ada,7,1 Analytical Way,Apt 2,London,LDN,E1,UK
            William,7,^,,,,,
            Charles,8,-,,,,,
            Mary,8,N/A,,,,,
            Grace,8,9 Compiler Ct,,Arlington,VA,22201,
            Ghost,,5 Nowhere,,,,,
            CSV;

        $addresses = GuestList::fromCsv($csv)->addressesByMailingGroup();

        $this->assertSame([
            7 => ['address_1' => '1 Analytical Way', 'address_2' => 'Apt 2', 'city' => 'London', 'state' => 'LDN', 'zip' => 'E1', 'country' => 'UK'],
            8 => ['address_1' => '9 Compiler Ct', 'address_2' => null, 'city' => 'Arlington', 'state' => 'VA', 'zip' => '22201', 'country' => null],
        ], $addresses);
    }

    public function testFindsTheHeaderOfAnAddressOnlyExport(): void
    {
        $csv = <<<'CSV'
            Mailing list,,,,
            Mailing Group,Address 1,City,State,ZIP
            3,"12 Elm St, Unit 4",Springfield,ST,00000
            CSV;

        $addresses = GuestList::fromCsv($csv)->addressesByMailingGroup();

        $this->assertSame('12 Elm St, Unit 4', $addresses[3]['address_1']);
    }

    public function testQuotedCellsMaySpanLines(): void
    {
        $csv = "Mailing Group,Address 1,City,State,ZIP\n3,\"12 Elm St\nRear door\",Springfield,ST,00000\n";

        $addresses = GuestList::fromCsv($csv)->addressesByMailingGroup();

        $this->assertSame("12 Elm St\nRear door", $addresses[3]['address_1']);
        $this->assertSame('Springfield', $addresses[3]['city']);
    }
}
