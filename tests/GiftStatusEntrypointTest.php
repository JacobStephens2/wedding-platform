<?php

declare(strict_types=1);

namespace Wedding\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Wedding\Tests\support\GiftStatusHttp;
use Wedding\Tests\support\LocalMariaDb;

final class GiftStatusEntrypointTest extends TestCase
{
    private static ?LocalMariaDb $database = null;
    private GiftStatusHttp $http;

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
        $this->http = new GiftStatusHttp(self::database());
    }

    protected function tearDown(): void
    {
        if (isset($this->http)) {
            $this->http->close();
        }
    }

    private static function database(): LocalMariaDb
    {
        return self::$database ?? throw new \LogicException('Database fixture not started');
    }

    public function testJsonTogglePersistsUtcAndReturnsEasternDateWithExistingTypes(): void
    {
        $before = time();
        $response = $this->http->request('/api/toggle-gift-status', 'POST',
            '{"source":"registry","id":"1.9","field":"received"}', ['X-Test-Authorized: 1']);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('Content-Type: application/json', $response['headers']);
        $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame(['success', 'source', 'id', 'field', 'active', 'at', 'at_display'], array_keys($result));
        self::assertTrue($result['success']);
        self::assertSame('registry', $result['source']);
        self::assertSame(1, $result['id']);
        self::assertSame('received', $result['field']);
        self::assertTrue($result['active']);
        self::assertIsString($result['at']);
        $instant = new DateTimeImmutable($result['at'], new DateTimeZone('UTC'));
        self::assertGreaterThanOrEqual($before, $instant->getTimestamp(), 'Stage timestamps must be UTC regardless of server timezone');
        self::assertLessThanOrEqual(time(), $instant->getTimestamp());
        self::assertSame($instant->setTimezone(new DateTimeZone('America/New_York'))->format('M j, Y'), $result['at_display']);
        $query = self::database()->pdo->query('SELECT received_at FROM registry_items WHERE id = 1');
        self::assertNotFalse($query);
        self::assertSame($result['at'], $query->fetchColumn());
    }

    public function testFallbackNonpositiveIdRedirectsBeforeDatabaseAcquisition(): void
    {
        foreach (array_keys(self::links()) as $param) {
            foreach (['0', '-2', '0.9'] as $id) {
                $this->assertRedirect($this->http->request('/admin-gifts?' . $param . '=' . $id, headers: [
                    'X-Test-Authorized: 1', 'X-Test-Fail-Db: 1',
                ]));
                self::assertFalse($this->http->acquiredDatabase());
            }
        }
    }

    /** @return array<string,array{string,string,int,string,string}> */
    public static function links(): array
    {
        return [
            'toggle_registry_received' => ['registry', 'registry_items', 1, 'received', 'received'],
            'toggle_registry_written' => ['registry', 'registry_items', 1, 'written', 'thank_you_written'],
            'toggle_registry_sent' => ['registry', 'registry_items', 1, 'sent', 'thank_you_sent'],
            'toggle_gift_written' => ['offregistry', 'gifts', 1, 'written', 'thank_you_written'],
            'toggle_gift_sent' => ['offregistry', 'gifts', 1, 'sent', 'thank_you_sent'],
            'toggle_housefund_written' => ['housefund', 'house_fund_contributions', 1, 'written', 'thank_you_written'],
            'toggle_housefund_sent' => ['housefund', 'house_fund_contributions', 1, 'sent', 'thank_you_sent'],
            'toggle_honeymoonfund_written' => ['honeymoonfund', 'honeymoon_fund_contributions', 1, 'written', 'thank_you_written'],
            'toggle_honeymoonfund_sent' => ['honeymoonfund', 'honeymoon_fund_contributions', 1, 'sent', 'thank_you_sent'],
        ];
    }

    public function testJsonAndAllFallbackLinksHaveIdenticalPersistedResultsIncludingCash(): void
    {
        $cases = self::links();
        $cases['cash_written'] = ['offregistry', 'gifts', 2, 'written', 'thank_you_written'];
        $cases['cash_sent'] = ['offregistry', 'gifts', 2, 'sent', 'thank_you_sent'];
        foreach ($cases as $param => [$source, $table, $id, $field, $column]) {
            self::database()->reset();
            $param = str_replace('cash_', 'toggle_gift_', $param);
            $before = $this->row($table, $id);
            $body = json_encode(['source' => $source, 'id' => $id, 'field' => $field], JSON_THROW_ON_ERROR);
            $response = $this->http->request('/api/toggle-gift-status', 'POST', $body, ['X-Test-Authorized: 1']);
            self::assertSame(200, $response['status'], $response['body']);
            $on = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($on);
            self::assertTrue($on['active']);
            $enabled = $this->row($table, $id);
            self::assertSame(1, $enabled[$column]);
            self::assertSame($on['at'], $enabled[$column . '_at']);
            $this->assertRedirect($this->http->request('/admin-gifts?' . $param . '=' . $id,
                headers: ['X-Test-Authorized: 1']));
            $disabled = $this->row($table, $id);
            self::assertSame(0, $disabled[$column]);
            self::assertNull($disabled[$column . '_at']);
            // Enable through the fallback as well, then disable through JSON.
            $utcBefore = gmdate('Y-m-d H:i:s');
            $this->assertRedirect($this->http->request('/admin-gifts?' . $param . '=' . $id,
                headers: ['X-Test-Authorized: 1']));
            $fallbackOn = $this->row($table, $id);
            self::assertSame(1, $fallbackOn[$column]);
            self::assertGreaterThanOrEqual($utcBefore, $fallbackOn[$column . '_at']);
            self::assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $fallbackOn[$column . '_at']);
            $response = $this->http->request('/api/toggle-gift-status', 'POST', $body, ['X-Test-Authorized: 1']);
            self::assertSame(200, $response['status']);
            $off = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($off);
            self::assertFalse($off['active']);
            self::assertNull($off['at']);
            self::assertNull($off['at_display']);
            $after = $this->row($table, $id);
            if ($table === 'gifts') {
                self::assertGreaterThan($before['updated_at'], $after['updated_at']);
                $after['updated_at'] = $before['updated_at'];
            }
            self::assertSame($before, $after);
        }
    }

    public function testFallbackPriorityAndNumericRecognitionRemainCompatible(): void
    {
        $links = self::links();
        while ($links !== []) {
            self::database()->reset();
            $query = array_fill_keys(array_keys($links), '1e0');
            // Reverse query order to prove recognition order is adapter-defined.
            $this->assertRedirect($this->http->request('/admin-gifts?' . http_build_query(array_reverse($query, true)),
                headers: ['X-Test-Authorized: 1']));
            $first = true;
            foreach ($links as [$source, $table, $id, $field, $column]) {
                self::assertSame($first ? 1 : 0, $this->row($table, $id)[$column]);
                $first = false;
            }
            array_shift($links);
        }
        self::database()->reset();
        $this->assertRedirect($this->http->request('/admin-gifts?toggle_registry_received=bad&toggle_gift_written=1.9',
            headers: ['X-Test-Authorized: 1']));
        self::assertSame(0, $this->row('registry_items', 1)['received']);
        self::assertSame(1, $this->row('gifts', 1)['thank_you_written']);
    }

    public function testMissingRecordsKeepSourceSpecificJsonErrorsAndSilentFallbackRedirects(): void
    {
        foreach (self::links() as $param => [$source, $table, $id, $field, $column]) {
            $body = json_encode(['source' => $source, 'id' => 999, 'field' => $field], JSON_THROW_ON_ERROR);
            $this->assertJsonError(404, match ($source) {
                'registry' => 'Registry item not found', 'offregistry' => 'Gift not found',
                default => 'Contribution not found',
            }, $this->http->request('/api/toggle-gift-status', 'POST', $body, ['X-Test-Authorized: 1']));
            $this->assertRedirect($this->http->request('/admin-gifts?' . $param . '=999', headers: ['X-Test-Authorized: 1']));
        }
        foreach (['received', 'written', 'sent'] as $field) {
            $this->assertJsonError(404, 'Registry item not found', $this->http->request('/api/toggle-gift-status', 'POST',
                json_encode(['source' => 'registry', 'id' => 2, 'field' => $field], JSON_THROW_ON_ERROR), ['X-Test-Authorized: 1']));
            $this->assertRedirect($this->http->request('/admin-gifts?toggle_registry_' . $field . '=2', headers: ['X-Test-Authorized: 1']));
        }
        self::assertSame(0, $this->row('registry_items', 2)['received']);
    }

    public function testMethodAuthorizationAndInvalidCommandsNeverAcquireDatabase(): void
    {
        $failureHeaders = ['X-Test-Authorized: 1', 'X-Test-Fail-Db: 1'];
        $this->assertJsonError(405, 'Method not allowed', $this->http->request('/api/toggle-gift-status'));
        $this->assertJsonError(401, 'Admin authentication required', $this->http->request('/api/toggle-gift-status', 'POST',
            '{"source":"registry","id":1,"field":"sent"}', ['X-Test-Fail-Db: 1']));
        foreach ([
            ['{}', 'Invalid field'], ['{broken', 'Invalid field'],
            ['{"source":"unknown","id":0,"field":"bad"}', 'Invalid field'],
            ['{"source":"unknown","id":0,"field":"sent"}', 'Invalid id'],
            ['{"source":"registry","id":-1,"field":"sent"}', 'Invalid id'],
            ['{"source":"cash","id":1,"field":"sent"}', 'Invalid source'],
            ['{"source":"offregistry","id":1,"field":"received"}', 'This source does not track a received flag'],
            ['{"source":"housefund","id":1,"field":"received"}', 'This source does not track a received flag'],
            ['{"source":"honeymoonfund","id":1,"field":"received"}', 'This source does not track a received flag'],
        ] as [$body, $error]) {
            $this->assertJsonError(400, $error, $this->http->request('/api/toggle-gift-status', 'POST', $body, $failureHeaders));
        }
        self::assertFalse($this->http->acquiredDatabase());
    }

    public function testUnauthenticatedAndSampleFallbackRequestsDoNotAcquireDatabase(): void
    {
        $query = http_build_query(array_fill_keys(array_keys(self::links()), 1));
        foreach (['/admin-gifts?' . $query, '/admin-gifts?sample=1&' . $query] as $path) {
            $response = $this->http->request($path, headers: ['X-Test-Fail-Db: 1']);
            self::assertSame(200, $response['status']);
            self::assertStringNotContainsString('Location:', $response['headers']);
            self::assertFalse($this->http->acquiredDatabase());
        }
        self::assertSame(0, $this->row('registry_items', 1)['received']);
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
    }

    public function testJsonDatabaseErrorsAreGenericAndLogged(): void
    {
        $this->assertJsonError(500, 'Database error', $this->http->request('/api/toggle-gift-status', 'POST',
            '{"source":"registry","id":1,"field":"sent"}', ['X-Test-Authorized: 1', 'X-Test-Fail-Db: 1']));
        self::assertStringContainsString('toggle-gift-status error: owned connection failure', $this->http->log());
        self::database()->pdo->exec("CREATE TRIGGER refuse_status BEFORE UPDATE ON gifts FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'");
        $this->assertJsonError(500, 'Database error', $this->http->request('/api/toggle-gift-status', 'POST',
            '{"source":"offregistry","id":1,"field":"written"}', ['X-Test-Authorized: 1']));
        self::assertStringContainsString('owned write failure', $this->http->log());
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
    }

    public function testFallbackPersistenceErrorsKeepPrefixesAndContinueScanning(): void
    {
        foreach (['toggle_registry_received' => ['registry_items', 'Error updating status:'],
            'toggle_gift_written' => ['gifts', 'Error updating thank-you status:'],
            'toggle_housefund_written' => ['house_fund_contributions', 'Error updating thank-you status:'],
            'toggle_honeymoonfund_written' => ['honeymoon_fund_contributions', 'Error updating thank-you status:'],
        ] as $param => [$table, $prefix]) {
            self::database()->reset();
            self::database()->pdo->exec("CREATE TRIGGER refuse_status BEFORE UPDATE ON $table FOR EACH ROW "
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'");
            $response = $this->http->request('/admin-gifts?' . $param . '=1', headers: ['X-Test-Authorized: 1']);
            self::assertSame(200, $response['status']);
            self::assertStringContainsString($prefix, $response['body']);
            self::assertStringContainsString('owned write failure', $response['body']);
        }
        self::database()->reset();
        // Only the received write fails; the later registry written parameter
        // must still run, ahead of the supplied manual gift parameter.
        self::database()->pdo->exec("CREATE TRIGGER refuse_received BEFORE UPDATE ON registry_items FOR EACH ROW "
            . "BEGIN IF NEW.received <> OLD.received THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'; END IF; END");
        $this->assertRedirect($this->http->request('/admin-gifts?toggle_registry_received=1&toggle_registry_written=1&toggle_gift_written=1',
            headers: ['X-Test-Authorized: 1']));
        self::assertSame(0, $this->row('registry_items', 1)['received']);
        self::assertSame(1, $this->row('registry_items', 1)['thank_you_written']);
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
        self::database()->reset();
        self::database()->pdo->exec("CREATE TRIGGER refuse_gift BEFORE UPDATE ON gifts FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'");
        $this->assertRedirect($this->http->request('/admin-gifts?toggle_gift_written=1&toggle_housefund_sent=1',
            headers: ['X-Test-Authorized: 1']));
        self::assertSame(1, $this->row('house_fund_contributions', 1)['thank_you_sent']);
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

    /** @param array{status:int,body:string,headers:string} $response */
    private function assertRedirect(array $response): void
    {
        self::assertSame(302, $response['status'], $response['body']);
        self::assertStringContainsString('Location: /admin-gifts#gifts-table', $response['headers']);
    }

    /** @param array{status:int,body:string,headers:string} $response */
    private function assertJsonError(int $status, string $message, array $response): void
    {
        self::assertSame($status, $response['status'], $response['body']);
        self::assertSame(['success' => false, 'error' => $message], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
    }
}
