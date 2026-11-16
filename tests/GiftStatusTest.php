<?php

declare(strict_types=1);

namespace Wedding\Tests;

use InvalidArgumentException;
use DateTimeImmutable;
use OutOfBoundsException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wedding\GiftStatus;
use Wedding\Tests\support\LocalMariaDb;

final class GiftStatusTest extends TestCase
{
    private static ?LocalMariaDb $database = null;

    public static function setUpBeforeClass(): void
    {
        self::$database = new LocalMariaDb();
    }

    public static function tearDownAfterClass(): void
    {
        self::$database?->close();
        self::$database = null;
    }

    protected function setUp(): void
    {
        self::database()->reset();
    }

    private static function database(): LocalMariaDb
    {
        return self::$database ?? throw new \LogicException('Database fixture not started');
    }

    public function testRegistryStagePersistsAndPreservesPurchaseOrdering(): void
    {
        $database = self::database();
        $status = new GiftStatus(static fn (): PDO => $database->pdo,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-01-15T04:30:00Z'));
        self::assertSame([
            'source' => 'registry', 'id' => 1, 'field' => 'received', 'active' => true,
            'at' => '2026-01-15 04:30:00', 'at_display' => 'Jan 14, 2026',
        ], $status->toggle('registry', 1, 'received'));
        $row = $this->row('registry_items', 1);
        self::assertSame(1, $row['received']);
        self::assertSame('2026-01-15 04:30:00', $row['received_at']);
        self::assertSame('2021-02-03 00:00:00', $row['updated_at']);
    }

    /** @return list<array{string,string,int,string,string}> */
    public static function stages(): array
    {
        return [
            ['registry', 'registry_items', 1, 'received', 'received'],
            ['registry', 'registry_items', 1, 'written', 'thank_you_written'],
            ['registry', 'registry_items', 1, 'sent', 'thank_you_sent'],
            ['offregistry', 'gifts', 1, 'written', 'thank_you_written'],
            ['offregistry', 'gifts', 1, 'sent', 'thank_you_sent'],
            ['offregistry', 'gifts', 2, 'written', 'thank_you_written'],
            ['offregistry', 'gifts', 2, 'sent', 'thank_you_sent'],
            ['housefund', 'house_fund_contributions', 1, 'written', 'thank_you_written'],
            ['housefund', 'house_fund_contributions', 1, 'sent', 'thank_you_sent'],
            ['honeymoonfund', 'honeymoon_fund_contributions', 1, 'written', 'thank_you_written'],
            ['honeymoonfund', 'honeymoon_fund_contributions', 1, 'sent', 'thank_you_sent'],
        ];
    }

    #[DataProvider('stages')]
    public function testEveryStageTogglesOnlyItsOwnFlagAndTimestamp(
        string $source, string $table, int $id, string $field, string $column,
    ): void {
        $pdo = self::database()->pdo;
        // Populate independent stage history, including sent-only records.
        $otherColumn = $field === 'sent' ? 'thank_you_written' : 'thank_you_sent';
        $pdo->exec("UPDATE $table SET $otherColumn = 1, {$otherColumn}_at = '2023-05-06 07:08:09', "
            . ($table === 'registry_items' || $table === 'gifts' ? "updated_at = '2021-02-03', " : '')
            . "id = id WHERE id = $id");
        $before = $this->snapshot();
        $clockReads = 0;
        $status = new GiftStatus(static fn (): PDO => $pdo,
            static function () use (&$clockReads): DateTimeImmutable {
                $clockReads++;
                return new DateTimeImmutable('2026-07-15T13:30:00+09:00');
            });
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');
        try {
            self::assertSame([
                'source' => $source, 'id' => $id, 'field' => $field, 'active' => true,
                'at' => '2026-07-15 04:30:00', 'at_display' => 'Jul 15, 2026',
            ], $status->toggle($source, $id, $field));
            $enabled = $this->row($table, $id);
            self::assertSame(1, $enabled[$column]);
            self::assertSame('2026-07-15 04:30:00', $enabled[$column . '_at']);
            self::assertSame([
                'source' => $source, 'id' => $id, 'field' => $field, 'active' => false,
                'at' => null, 'at_display' => null,
            ], $status->toggle($source, $id, $field));
            self::assertSame(1, $clockReads, 'Disabling must not read the clock');
            $after = $this->snapshot();
            self::assertSame(0, $after[$table][$id][$column]);
            self::assertNull($after[$table][$id][$column . '_at']);
            // Only manual gift updated_at advances automatically; all other
            // columns and identically numbered records in other sources survive.
            if ($table === 'gifts') {
                self::assertGreaterThan($before[$table][$id]['updated_at'], $enabled['updated_at']);
                $after[$table][$id]['updated_at'] = $before[$table][$id]['updated_at'];
            }
            self::assertSame($before, $after);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testWrittenAndSentAreIndependentWhenTurningWrittenOff(): void
    {
        $pdo = self::database()->pdo;
        $pdo->exec("UPDATE registry_items SET received = 1, received_at = '2022-03-04', "
            . "thank_you_written = 1, thank_you_written_at = '2023-04-05', "
            . "thank_you_sent = 1, thank_you_sent_at = '2024-05-06', updated_at = updated_at WHERE id = 1");
        $status = new GiftStatus(static fn (): PDO => $pdo, static function (): never {
            self::fail('Disabling must not read the clock');
        });
        $status->toggle('registry', 1, 'written');
        $row = $this->row('registry_items', 1);
        self::assertSame(0, $row['thank_you_written']);
        self::assertNull($row['thank_you_written_at']);
        self::assertSame(1, $row['thank_you_sent']);
        self::assertSame('2024-05-06 00:00:00', $row['thank_you_sent_at']);
        self::assertSame(1, $row['received']);
        self::assertSame('2022-03-04 00:00:00', $row['received_at']);
    }

    public function testMissingAndUnpurchasedRecordsDoNotWrite(): void
    {
        $pdo = self::database()->pdo;
        $status = new GiftStatus(static fn (): PDO => $pdo);
        $before = $this->snapshot();
        foreach ([
            ['registry', 999, 'Registry item not found'],
            ['registry', 2, 'Registry item not found'],
            ['offregistry', 999, 'Gift not found'],
            ['housefund', 999, 'Contribution not found'],
            ['honeymoonfund', 999, 'Contribution not found'],
        ] as [$source, $id, $message]) {
            foreach ($source === 'registry' ? ['received', 'written', 'sent'] : ['written', 'sent'] as $field) {
                try {
                    $status->toggle($source, $id, $field);
                    self::fail('Missing/ineligible record was accepted');
                } catch (OutOfBoundsException $error) {
                    self::assertSame($message, $error->getMessage());
                }
            }
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testWriteFailuresPropagateWithoutReturningSuccess(): void
    {
        $pdo = self::database()->pdo;
        $pdo->exec("CREATE TRIGGER refuse_status BEFORE UPDATE ON registry_items FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'");
        $before = $this->snapshot();
        try {
            (new GiftStatus(static fn (): PDO => $pdo))->toggle('registry', 1, 'sent');
            self::fail('Failed persistence returned success');
        } catch (PDOException $error) {
            self::assertStringContainsString('owned write failure', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string,mixed> */
    private function row(string $table, int $id): array
    {
        $query = self::database()->pdo->query("SELECT * FROM $table WHERE id = $id");
        self::assertNotFalse($query);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['registry_items', 'gifts', 'house_fund_contributions', 'honeymoon_fund_contributions'] as $table) {
            foreach ($table === 'registry_items' || $table === 'gifts' ? [1, 2] : [1] as $id) {
                $rows[$table][$id] = $this->row($table, $id);
            }
        }
        return $rows;
    }

    public function testInvalidCommandsFailBeforeAcquiringADatabase(): void
    {
        $status = new GiftStatus(static function (): never {
            self::fail('Invalid commands must not acquire a database');
        });
        foreach ([
            ['unknown', 0, 'unknown', 'Invalid field'],
            ['unknown', 0, 'sent', 'Invalid id'],
            ['registry', -1, 'received', 'Invalid id'],
            ['registry', 1, '', 'Invalid field'],
            ['registry', 1, 'thank_you_sent', 'Invalid field'],
            ['unknown', 1, 'sent', 'Invalid source'],
            ['', 1, 'sent', 'Invalid source'],
            ['cash', 1, 'sent', 'Invalid source'],
            ['offregistry', 1, 'received', 'This source does not track a received flag'],
            ['housefund', 1, 'received', 'This source does not track a received flag'],
            ['honeymoonfund', 1, 'received', 'This source does not track a received flag'],
        ] as [$source, $id, $field, $message]) {
            try {
                $status->toggle($source, $id, $field);
                self::fail('Invalid command was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }
}
