<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;
use Wedding\GiftOverview;

final class GiftOverviewTest extends TestCase
{
    public function testEmptyOverviewHasNoRowsOrOutstandingGifts(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [], manualGifts: [], houseFundContributions: [], honeymoonFundContributions: [],
        );

        $this->assertSame([], $overview->rows);
        $this->assertSame([
            'total_gifts' => 0, 'awaiting_delivery' => 0, 'completed' => 0,
            'written' => 0, 'sent' => 0, 'outstanding' => 0,
            'registry_value' => 0.0, 'offregistry_value' => 0.0, 'cash_value' => 0.0,
            'housefund_value' => 0.0, 'honeymoonfund_value' => 0.0, 'total_value' => 0.0,
        ], $overview->summary);
    }

    public function testCommittedSampleRowsAndSummaryDescribeTheSameGifts(): void
    {
        require_once __DIR__ . '/../private/admin_sample.php';
        $overview = GiftOverview::fromSources(
            registryPurchases: array_values(array_filter(getSampleRegistryItems(), static fn (array $item): bool => !empty($item['purchased']))),
            manualGifts: getSampleGifts(),
            houseFundContributions: getSampleHouseFundContributions(),
            honeymoonFundContributions: getSampleHoneymoonFundContributions(),
        );

        $this->assertCount(12, $overview->rows);
        $this->assertSame([
            'total_gifts' => 12, 'awaiting_delivery' => 1, 'completed' => 3,
            'written' => 6, 'sent' => 3, 'outstanding' => 9,
            'registry_value' => 311.0, 'offregistry_value' => 0.0, 'cash_value' => 200.0,
            'housefund_value' => 450.0, 'honeymoonfund_value' => 495.0, 'total_value' => 1456.0,
        ], $overview->summary);
        $this->assertSame('Weekend Cabin Gift Card', $overview->rows[0]['title']);
        $this->assertSame('Indigo Dinnerware Set', $overview->rows[11]['title']);
        $cash = array_values(array_filter($overview->rows, static fn (array $row): bool => $row['source'] === 'cash'))[0];
        $this->assertSame('Cash', $cash['source_label']);
        $this->assertSame('manual', $cash['action_kind']);
        $this->assertSame('offregistry', $cash['action_source']);
        $this->assertSame('gift', $cash['toggle_prefix']);
        $this->assertSame('yes', $cash['filter']['received']);
        $this->assertNull($cash['received']);
        $this->assertSame('b', $cash['sort']['source']);
        $this->assertSame(['gift-row', 'source-cash', 'thanked'], $cash['row_classes']);
    }

    public function testAllDisplaySourcesContributeOnceAndMatchingIdsRemainDistinct(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [['id' => '7', 'price' => '10.50']],
            manualGifts: [['id' => 7, 'value' => 20], ['id' => 8, 'is_cash' => '1', 'value' => '30.25']],
            houseFundContributions: [['id' => 7, 'amount' => 40]],
            honeymoonFundContributions: [['id' => 7, 'amount' => '50']],
        );

        $this->assertCount(5, $overview->rows);
        $this->assertSame(10.5, $overview->summary['registry_value']);
        $this->assertSame(20.0, $overview->summary['offregistry_value']);
        $this->assertSame(30.25, $overview->summary['cash_value']);
        $this->assertSame(40.0, $overview->summary['housefund_value']);
        $this->assertSame(50.0, $overview->summary['honeymoonfund_value']);
        $this->assertSame(150.75, $overview->summary['total_value']);
        $this->assertCount(4, array_filter($overview->rows, static fn (array $row): bool => $row['id'] === 7));
        $bySource = array_column($overview->rows, null, 'source');
        foreach (['offregistry', 'cash', 'housefund', 'honeymoonfund'] as $source) {
            $this->assertSame('yes', $bySource[$source]['filter']['received']);
            $this->assertNull($bySource[$source]['received']);
            $this->assertFalse($bySource[$source]['awaiting_delivery']);
        }
        $this->assertSame('/admin-house-fund', $bySource['housefund']['management_url']);
        $this->assertSame('/admin-honeymoon-fund', $bySource['honeymoonfund']['management_url']);
        $this->assertSame('housefund', $bySource['housefund']['toggle_prefix']);
        $this->assertSame('honeymoonfund', $bySource['honeymoonfund']['action_source']);
    }

    public function testZeroAmountsArePresentWhileNullBlankAndMissingAmountsAreAbsent(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [['id' => 1, 'price' => '0.00'], ['id' => 2, 'price' => '']],
            manualGifts: [['id' => 3, 'value' => 0], ['id' => 4, 'value' => null], ['id' => 5, 'value' => '']],
            houseFundContributions: [['id' => 6, 'amount' => '0']],
            honeymoonFundContributions: [['id' => 7]],
        );
        $byId = array_column($overview->rows, null, 'id');

        foreach ([1, 3, 6] as $id) {
            $this->assertSame(0.0, $byId[$id]['price']);
            $this->assertSame(0.0, $byId[$id]['sort']['price']);
        }
        foreach ([2, 4, 5, 7] as $id) {
            $this->assertNull($byId[$id]['price']);
            $this->assertSame(-1.0, $byId[$id]['sort']['price']);
        }
        $this->assertSame(0.0, $overview->summary['total_value']);
    }

    public function testWrittenAndSentCountIndependentlyAndOnlyRegistryCanAwaitDelivery(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [
                ['id' => 1, 'received' => 0],
                ['id' => 2, 'received' => '1', 'thank_you_written' => '1'],
            ],
            manualGifts: [
                ['id' => 3, 'thank_you_sent' => 1],
                ['id' => 4, 'thank_you_written' => true, 'thank_you_sent' => true],
            ],
            houseFundContributions: [], honeymoonFundContributions: [],
        );
        $byId = array_column($overview->rows, null, 'id');

        $this->assertSame(1, $overview->summary['awaiting_delivery']);
        $this->assertSame(2, $overview->summary['written']);
        $this->assertSame(2, $overview->summary['sent']);
        $this->assertSame(1, $overview->summary['completed']);
        $this->assertSame(3, $overview->summary['outstanding']);
        $this->assertSame([0, 1, 2, 3], [
            $byId[1]['sort']['status'], $byId[2]['sort']['status'], $byId[3]['sort']['status'], $byId[4]['sort']['status'],
        ]);
        $this->assertSame(['gift-row', 'source-registry', 'noname', 'awaiting-delivery'], $byId[1]['row_classes']);
        $this->assertSame(['gift-row', 'source-offregistry', 'thanked'], $byId[4]['row_classes']);
        $this->assertFalse($byId[3]['completed']);
        $this->assertSame(['received' => 'yes', 'written' => 'no', 'sent' => 'yes'], $byId[3]['filter']);
    }

    public function testOrderingTrimsNamesIgnoresCaseAndKeepsSourceInputOrderForExactTies(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [
                ['id' => 1, 'purchased_by' => ' Alice ', 'title' => 'alpha'],
                ['id' => 2, 'purchased_by' => 'alice', 'title' => 'ALPHA'],
                ['id' => 3, 'title' => 'Zebra'],
            ],
            manualGifts: [
                ['id' => 4, 'purchaser_name' => 'ALICE', 'description' => 'Alpha'],
                ['id' => 5, 'purchaser_name' => 'alice', 'description' => 'beta'],
                ['id' => 6, 'purchaser_name' => 'Bob', 'description' => 'A'],
                ['id' => 7, 'purchaser_name' => '  ', 'description' => 'apple'],
            ], houseFundContributions: [], honeymoonFundContributions: [],
        );

        $this->assertSame([1, 2, 4, 5, 6, 7, 3], array_column($overview->rows, 'id'));
        $this->assertSame('Alice', $overview->rows[0]['from']);
        $this->assertSame('alice', $overview->rows[0]['sort']['from']);
        $this->assertSame('alpha', $overview->rows[0]['sort']['title']);
        $this->assertSame('~', $overview->rows[5]['sort']['from']);
        $this->assertTrue($overview->rows[5]['no_name']);
    }

    public function testSearchKeepsSourceAliasesAndMessagesButExcludesRegistryAdminNotes(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [[
                'id' => 1, 'title' => ' A <Bowl> ', 'purchased_by' => ' Alex & Jo ',
                'purchase_message' => "Enjoy\n  dinner", 'admin_note' => 'Private reminder',
            ]], manualGifts: [['id' => 2, 'is_cash' => 1]],
            houseFundContributions: [['id' => 3]], honeymoonFundContributions: [['id' => 4]],
        );
        $byId = array_column($overview->rows, null, 'id');

        $this->assertSame('a <bowl> alex & jo enjoy dinner registry', $byId[1]['search']);
        $this->assertSame('Private reminder', $byId[1]['admin_note']);
        $this->assertSame('registry', $byId[1]['action_kind']);
        $this->assertSame('registry', $byId[1]['action_source']);
        $this->assertSame('registry', $byId[1]['toggle_prefix']);
        $this->assertSame('cash off-registry off registry', $byId[2]['search']);
        $this->assertSame('house fund housefund', $byId[3]['search_aliases']);
        $this->assertSame('honeymoon fund honeymoonfund', $byId[4]['search_aliases']);
    }

    public function testTimestampDatesUseEasternAcrossDaylightSavingWhileManualDatesStayCalendarDates(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [
                ['id' => 1, 'received_at' => '2026-01-15 04:30:00', 'updated_at' => '2026-02-01 12:00:00'],
                ['id' => 2, 'updated_at' => '2026-01-15 04:30:00'],
                ['id' => 3, 'updated_at' => '2026-07-15 04:30:00'],
                ['id' => 4],
            ], manualGifts: [['id' => 5, 'received_on' => '2026-01-15']],
            houseFundContributions: [['id' => 6, 'created_at' => '2026-01-15 04:30:00']],
            honeymoonFundContributions: [['id' => 7, 'created_at' => '2026-07-15 04:30:00']],
        );
        $byId = array_column($overview->rows, null, 'id');

        $this->assertSame('Jan 14, 2026', $byId[1]['date_display']);
        $this->assertSame('Jan 14, 2026', $byId[1]['received_date']);
        $this->assertSame('Jan 14, 2026 11:30 PM', $byId[2]['date_display']);
        $this->assertSame('Jul 15, 2026 12:30 AM', $byId[3]['date_display']);
        $this->assertSame('', $byId[4]['date_display']);
        $this->assertSame('', $byId[4]['received_date']);
        $this->assertSame(0, $byId[4]['sort']['date']);
        $this->assertSame(0, $byId[2]['sort']['date']);
        $this->assertSame('Jan 15, 2026', $byId[5]['date_display']);
        $this->assertSame('Jan 14, 2026', $byId[6]['date_display']);
        $this->assertSame('Jul 15, 2026', $byId[7]['date_display']);
        $this->assertSame(1768451400, $byId[1]['sort']['date']);
    }

    public function testMalformedDatesRemainUnescapedPlainText(): void
    {
        $overview = GiftOverview::fromSources(
            registryPurchases: [['id' => 1, 'received_at' => 'bad <date> & time']],
            manualGifts: [['id' => 2, 'received_on' => '2026-02-30']],
            houseFundContributions: [['id' => 3, 'created_at' => '2026-02-30 12:00:00']],
            honeymoonFundContributions: [],
        );
        $byId = array_column($overview->rows, null, 'id');

        $this->assertSame('bad <date> & time', $byId[1]['date_display']);
        $this->assertSame('bad <date> & time', $byId[1]['received_date']);
        $this->assertSame('2026-02-30', $byId[2]['date_display']);
        $this->assertSame('2026-02-30 12:00:00', $byId[3]['date_display']);
        $this->assertSame(0, $byId[3]['sort']['date']);
    }
}
