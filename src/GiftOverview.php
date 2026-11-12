<?php

declare(strict_types=1);

namespace Wedding;

/**
 * A read-only gift-tracking snapshot. No database or application configuration is needed.
 *
 * @phpstan-type GiftStatus array{thank_you_written?:int|string|bool|null, thank_you_sent?:int|string|bool|null, ...}
 * @phpstan-type RegistryPurchase array{
 *   id:int|string, title?:?string, purchased_by?:?string, purchase_message?:?string,
 *   admin_note?:?string, price?:int|float|string|null, received?:int|string|bool|null,
 *   received_at?:?string, updated_at?:?string,
 *   thank_you_written?:int|string|bool|null, thank_you_sent?:int|string|bool|null
 * }
 * @phpstan-type ManualGift array{
 *   id:int|string, description?:?string, purchaser_name?:?string, notes?:?string,
 *   value?:int|float|string|null, is_cash?:int|string|bool|null, received_on?:?string,
 *   thank_you_written?:int|string|bool|null, thank_you_sent?:int|string|bool|null
 * }
 * @phpstan-type FundContribution array{
 *   id:int|string, amount?:int|float|string|null, contributor_name?:?string, created_at?:?string,
 *   thank_you_written?:int|string|bool|null, thank_you_sent?:int|string|bool|null
 * }
 * @phpstan-type GiftSummary array{
 *   total_gifts:int, awaiting_delivery:int, completed:int, written:int, sent:int, outstanding:int,
 *   registry_value:float, offregistry_value:float, cash_value:float,
 *   housefund_value:float, honeymoonfund_value:float, total_value:float
 * }
 * @phpstan-type DisplaySource 'registry'|'offregistry'|'cash'|'housefund'|'honeymoonfund'
 * @phpstan-type GiftData array{
 *   source:DisplaySource, id:int, title:string, from:string, details:string, admin_note:string,
 *   price:?float, date_display:string, received:?bool, received_at:?string, received_date:string
 * }
 * @phpstan-type GiftRow array{
 *   source:DisplaySource, id:int, title:string, from:string, details:string, admin_note:string,
 *   price:?float, date_display:string, received:?bool, received_at:?string, received_date:string,
 *   written:bool, sent:bool, completed:bool, no_name:bool, awaiting_delivery:bool,
 *   source_label:string, action_kind:'registry'|'manual'|'fund',
 *   action_source:'registry'|'offregistry'|'housefund'|'honeymoonfund',
 *   toggle_prefix:string, management_url:?string, search_aliases:string, search:string,
 *   row_classes:list<string>, filter:array{received:'yes'|'no', written:'yes'|'no', sent:'yes'|'no'},
 *   sort:array{source:string, status:int, from:string, title:string, price:float, date:int, received:int, written:int, sent:int}
 * }
 */
final class GiftOverview
{
    private const SOURCES = [
        'registry' => ['source_label' => 'Registry', 'action_kind' => 'registry', 'action_source' => 'registry',
            'toggle_prefix' => 'registry', 'management_url' => null, 'search_aliases' => 'registry', 'sort_source' => 'a'],
        'offregistry' => ['source_label' => 'Off-Registry', 'action_kind' => 'manual', 'action_source' => 'offregistry',
            'toggle_prefix' => 'gift', 'management_url' => null, 'search_aliases' => 'off-registry off registry', 'sort_source' => 'b'],
        'cash' => ['source_label' => 'Cash', 'action_kind' => 'manual', 'action_source' => 'offregistry',
            'toggle_prefix' => 'gift', 'management_url' => null, 'search_aliases' => 'cash off-registry off registry', 'sort_source' => 'b'],
        'housefund' => ['source_label' => 'House Fund', 'action_kind' => 'fund', 'action_source' => 'housefund',
            'toggle_prefix' => 'housefund', 'management_url' => '/admin-house-fund', 'search_aliases' => 'house fund housefund', 'sort_source' => 'c'],
        'honeymoonfund' => ['source_label' => 'Honeymoon Fund', 'action_kind' => 'fund', 'action_source' => 'honeymoonfund',
            'toggle_prefix' => 'honeymoonfund', 'management_url' => '/admin-honeymoon-fund', 'search_aliases' => 'honeymoon fund honeymoonfund', 'sort_source' => 'd'],
    ];

    /**
     * @param list<GiftRow> $rows
     * @param GiftSummary $summary
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $summary,
    ) {
    }

    /**
     * @param list<RegistryPurchase> $registryPurchases purchased items only
     * @param list<ManualGift> $manualGifts
     * @param list<FundContribution> $houseFundContributions
     * @param list<FundContribution> $honeymoonFundContributions
     */
    public static function fromSources(
        array $registryPurchases,
        array $manualGifts,
        array $houseFundContributions,
        array $honeymoonFundContributions,
    ): self {
        $rows = [];
        foreach ($registryPurchases as $gift) {
            $receivedAt = $gift['received_at'] ?? null;
            $rows[] = self::projectRow([
                'source' => 'registry', 'id' => (int) $gift['id'], 'title' => $gift['title'] ?? '',
                'from' => trim($gift['purchased_by'] ?? ''), 'details' => $gift['purchase_message'] ?? '',
                'admin_note' => $gift['admin_note'] ?? '', 'price' => self::amount($gift['price'] ?? null),
                'date_display' => !empty($receivedAt)
                    ? self::dateText($receivedAt, 'M j, Y')
                    : self::dateText($gift['updated_at'] ?? null, 'M j, Y g:i A'),
                'received' => !empty($gift['received']), 'received_at' => $receivedAt,
                'received_date' => self::dateText($receivedAt, 'M j, Y'),
            ], $gift);
        }
        foreach ($manualGifts as $gift) {
            $rows[] = self::projectRow([
                'source' => !empty($gift['is_cash']) ? 'cash' : 'offregistry',
                'id' => (int) $gift['id'], 'title' => $gift['description'] ?? '',
                'from' => trim($gift['purchaser_name'] ?? ''), 'details' => $gift['notes'] ?? '',
                'admin_note' => '', 'price' => self::amount($gift['value'] ?? null),
                'date_display' => self::dateText($gift['received_on'] ?? null, 'M j, Y', calendarDate: true),
                'received' => null, 'received_at' => $gift['received_on'] ?? null, 'received_date' => '',
            ], $gift);
        }
        foreach (['housefund' => $houseFundContributions, 'honeymoonfund' => $honeymoonFundContributions] as $source => $contributions) {
            foreach ($contributions as $gift) {
                $rows[] = self::projectRow([
                    'source' => $source, 'id' => (int) $gift['id'],
                    'title' => self::SOURCES[$source]['source_label'] . ' contribution',
                    'from' => trim($gift['contributor_name'] ?? ''), 'details' => '', 'admin_note' => '',
                    'price' => self::amount($gift['amount'] ?? null),
                    'date_display' => self::dateText($gift['created_at'] ?? null, 'M j, Y'),
                    'received' => null, 'received_at' => $gift['created_at'] ?? null, 'received_date' => '',
                ], $gift);
            }
        }

        // PHP's stable sort preserves source/input order when both comparisons tie.
        usort($rows, static function (array $a, array $b): int {
            return ($a['no_name'] <=> $b['no_name'])
                ?: strcasecmp($a['from'], $b['from'])
                ?: strcasecmp($a['title'], $b['title']);
        });

        return new self($rows, self::summarize($rows));
    }

    /**
     * @param GiftData $row
     * @param GiftStatus $status
     * @return GiftRow
     */
    private static function projectRow(array $row, array $status): array
    {
        $source = self::SOURCES[$row['source']];
        $written = !empty($status['thank_you_written']);
        $sent = !empty($status['thank_you_sent']);
        $completed = $written && $sent;
        $noName = $row['from'] === '';
        $awaiting = $row['received'] === false;
        $received = !$awaiting;
        $classes = ['gift-row', 'source-' . $row['source']];
        if ($completed) {
            $classes[] = 'thanked';
        } elseif ($noName) {
            $classes[] = 'noname';
        }
        if ($awaiting) {
            $classes[] = 'awaiting-delivery';
        }
        $search = implode(' ', array_filter([$row['title'], $row['from'], $row['details'], $source['search_aliases']]));

        return $row + [
            'written' => $written, 'sent' => $sent, 'completed' => $completed,
            'no_name' => $noName, 'awaiting_delivery' => $awaiting,
            'source_label' => $source['source_label'], 'action_kind' => $source['action_kind'],
            'action_source' => $source['action_source'], 'toggle_prefix' => $source['toggle_prefix'],
            'management_url' => $source['management_url'], 'search_aliases' => $source['search_aliases'],
            'search' => strtolower(trim((string) preg_replace('/\s+/', ' ', $search))),
            'row_classes' => $classes,
            'filter' => ['received' => $received ? 'yes' : 'no', 'written' => $written ? 'yes' : 'no', 'sent' => $sent ? 'yes' : 'no'],
            'sort' => [
                'source' => $source['sort_source'], 'status' => $completed ? 3 : ($sent ? 2 : ($written ? 1 : 0)),
                'from' => $noName ? '~' : mb_strtolower($row['from']), 'title' => mb_strtolower($row['title']),
                'price' => $row['price'] ?? -1.0, 'date' => self::timestamp($row['received_at']),
                'received' => (int) $received, 'written' => (int) $written, 'sent' => (int) $sent,
            ],
        ];
    }

    /**
     * @param list<GiftRow> $rows
     * @return GiftSummary
     */
    private static function summarize(array $rows): array
    {
        $completed = $written = $sent = $awaiting = 0;
        $values = ['registry' => 0.0, 'offregistry' => 0.0, 'cash' => 0.0, 'housefund' => 0.0, 'honeymoonfund' => 0.0];
        foreach ($rows as $row) {
            $completed += (int) $row['completed'];
            $written += (int) $row['written'];
            $sent += (int) $row['sent'];
            $awaiting += (int) $row['awaiting_delivery'];
            $values[$row['source']] += $row['price'] ?? 0.0;
        }
        return [
            'total_gifts' => count($rows), 'awaiting_delivery' => $awaiting, 'completed' => $completed,
            'written' => $written, 'sent' => $sent, 'outstanding' => count($rows) - $completed,
            'registry_value' => $values['registry'], 'offregistry_value' => $values['offregistry'],
            'cash_value' => $values['cash'], 'housefund_value' => $values['housefund'],
            'honeymoonfund_value' => $values['honeymoonfund'], 'total_value' => array_sum($values),
        ];
    }

    private static function amount(int|float|string|null $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function dateText(?string $raw, string $format, bool $calendarDate = false): string
    {
        $date = self::parseDate($raw);
        if ($date === null) {
            // All snapshot strings are plain text; escaping belongs to the renderer.
            return $raw ?? '';
        }
        if (!$calendarDate) {
            $date = $date->setTimezone(new \DateTimeZone('America/New_York'));
        }
        return $date->format($format);
    }

    private static function timestamp(?string $raw): int
    {
        return self::parseDate($raw)?->getTimestamp() ?? 0;
    }

    private static function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if (!$raw) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors === false ? $date : null;
        } catch (\Exception) {
            return null;
        }
    }
}
