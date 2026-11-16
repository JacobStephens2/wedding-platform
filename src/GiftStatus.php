<?php

declare(strict_types=1);

namespace Wedding;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

/** Gift status changes shared by the JSON and fallback-link adapters. */
final class GiftStatus
{
    private const SOURCES = [
        'registry' => ['table' => 'registry_items', 'missing' => 'Registry item not found'],
        'offregistry' => ['table' => 'gifts', 'missing' => 'Gift not found'],
        'housefund' => ['table' => 'house_fund_contributions', 'missing' => 'Contribution not found'],
        'honeymoonfund' => ['table' => 'honeymoon_fund_contributions', 'missing' => 'Contribution not found'],
    ];

    /**
     * @param Closure():PDO $connection resolved only after command validation
     * @param null|Closure():DateTimeImmutable $clock read only when enabling a stage
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * Toggle one independent stage. Registry requires a purchased item.
     *
     * InvalidArgumentException rejects field, ID, source, then unsupported received,
     * in that order, before database acquisition. OutOfBoundsException means the
     * record is missing/ineligible; persistence exceptions propagate to the adapter.
     * Sequential read/flip/write, with no concurrency or cross-stage guarantees.
     *
     * @return array{source:string,id:int,field:string,active:bool,at:?string,at_display:?string}
     */
    public function toggle(string $source, int $id, string $field): array
    {
        if (!in_array($field, ['received', 'written', 'sent'], true)) {
            throw new InvalidArgumentException('Invalid field');
        }
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid id');
        }
        if (!isset(self::SOURCES[$source])) {
            throw new InvalidArgumentException('Invalid source');
        }
        if ($source !== 'registry' && $field === 'received') {
            throw new InvalidArgumentException('This source does not track a received flag');
        }

        $sourceData = self::SOURCES[$source];
        $table = $sourceData['table'];
        $column = $field === 'received' ? 'received' : 'thank_you_' . $field;
        $stageColumn = $column . '_at';
        $eligibility = $source === 'registry' ? ' AND purchased = 1' : '';
        $pdo = ($this->connection)();
        $select = $pdo->prepare("SELECT $column FROM $table WHERE id = ?$eligibility");
        $select->execute([$id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new OutOfBoundsException($sourceData['missing']);
        }
        $active = !$row[$column];
        $instant = $active
            ? ($this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->clock)())
            : null;
        $at = $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // Registry updated_at is the purchase-ordering timestamp. Manual gifts
        // retain automatic updated_at behavior; funds have no updated_at column.
        $preservePurchaseTime = $source === 'registry' ? ', updated_at = updated_at' : '';
        $update = $pdo->prepare("UPDATE $table SET $column = ?, $stageColumn = ?$preservePurchaseTime WHERE id = ?");
        $update->execute([(int) $active, $at, $id]);

        return [
            'source' => $source, 'id' => $id, 'field' => $field, 'active' => $active,
            'at' => $at, 'at_display' => $instant?->setTimezone(new DateTimeZone('America/New_York'))->format('M j, Y'),
        ];
    }
}
