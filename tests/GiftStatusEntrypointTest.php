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
        $token = $this->fetchCsrfToken();
        $before = time();
        $response = $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            '{"source":"registry","id":"1.9","field":"received"}',
            $this->authorizedPostHeaders($token),
        );
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

    /** @return array<string,array{string,string,int,string,string}> */
    public static function stages(): array
    {
        return [
            'registry_received' => ['registry', 'registry_items', 1, 'received', 'received'],
            'registry_written' => ['registry', 'registry_items', 1, 'written', 'thank_you_written'],
            'registry_sent' => ['registry', 'registry_items', 1, 'sent', 'thank_you_sent'],
            'offregistry_written' => ['offregistry', 'gifts', 1, 'written', 'thank_you_written'],
            'offregistry_sent' => ['offregistry', 'gifts', 1, 'sent', 'thank_you_sent'],
            'cash_written' => ['offregistry', 'gifts', 2, 'written', 'thank_you_written'],
            'cash_sent' => ['offregistry', 'gifts', 2, 'sent', 'thank_you_sent'],
            'housefund_written' => ['housefund', 'house_fund_contributions', 1, 'written', 'thank_you_written'],
            'housefund_sent' => ['housefund', 'house_fund_contributions', 1, 'sent', 'thank_you_sent'],
            'honeymoonfund_written' => ['honeymoonfund', 'honeymoon_fund_contributions', 1, 'written', 'thank_you_written'],
            'honeymoonfund_sent' => ['honeymoonfund', 'honeymoon_fund_contributions', 1, 'sent', 'thank_you_sent'],
        ];
    }

    public function testJsonAndFallbackPostTogglesHaveIdenticalPersistedResultsIncludingCash(): void
    {
        $token = $this->fetchCsrfToken();
        $jsonHeaders = $this->authorizedPostHeaders($token);
        foreach (self::stages() as [$source, $table, $id, $field, $column]) {
            self::database()->reset();
            $before = $this->row($table, $id);
            $body = json_encode(['source' => $source, 'id' => $id, 'field' => $field], JSON_THROW_ON_ERROR);
            $response = $this->http->request('/api/toggle-gift-status', 'POST', $body, $jsonHeaders);
            self::assertSame(200, $response['status'], $response['body']);
            $on = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($on);
            self::assertTrue($on['active']);
            $enabled = $this->row($table, $id);
            self::assertSame(1, $enabled[$column]);
            self::assertSame($on['at'], $enabled[$column . '_at']);

            $this->assertRedirect($this->postFallback($source, $id, $field, $token));
            $disabled = $this->row($table, $id);
            self::assertSame(0, $disabled[$column]);
            self::assertNull($disabled[$column . '_at']);

            // Enable through the fallback POST form as well, then disable via JSON body token.
            $utcBefore = gmdate('Y-m-d H:i:s');
            $this->assertRedirect($this->postFallback($source, $id, $field, $token));
            $fallbackOn = $this->row($table, $id);
            self::assertSame(1, $fallbackOn[$column]);
            self::assertGreaterThanOrEqual($utcBefore, $fallbackOn[$column . '_at']);
            self::assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $fallbackOn[$column . '_at']);

            $bodyWithToken = json_encode(
                ['source' => $source, 'id' => $id, 'field' => $field, 'csrf_token' => $token],
                JSON_THROW_ON_ERROR,
            );
            $response = $this->http->request('/api/toggle-gift-status', 'POST', $bodyWithToken, $this->authorizedPostHeaders());
            self::assertSame(200, $response['status'], $response['body']);
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

    public function testFallbackNonpositiveIdAndInvalidCommandsRedirectBeforeDatabaseAcquisition(): void
    {
        $token = $this->fetchCsrfToken();
        $this->http->clearAcquisitions();
        $headers = $this->authorizedPostHeaders($token, ['X-Test-Fail-Db: 1']);

        foreach ([
            ['registry', '0', 'received'],
            ['registry', '-2', 'written'],
            ['offregistry', '0.9', 'sent'],
            ['housefund', 'bad', 'written'],
            ['honeymoonfund', '-1', 'sent'],
            ['unknown', '1', 'written'],
            ['registry', '1', 'unknown'],
            ['offregistry', '1', 'received'],
        ] as [$source, $id, $field]) {
            $this->assertRedirect($this->postFallback($source, $id, $field, $token, $headers));
            self::assertFalse($this->http->acquiredDatabase());
        }
    }

    public function testGetAndNonPostRequestsNeverMutateGiftStatus(): void
    {
        $token = $this->fetchCsrfToken();
        $this->http->clearAcquisitions();

        foreach ([
            'toggle_registry_received=1',
            'toggle_registry_written=1',
            'toggle_registry_sent=1',
            'toggle_gift_written=1',
            'toggle_gift_sent=1',
            'toggle_housefund_written=1',
            'toggle_housefund_sent=1',
            'toggle_honeymoonfund_written=1',
            'toggle_honeymoonfund_sent=1',
        ] as $query) {
            $response = $this->http->request('/admin-gifts?' . $query, headers: ['X-Test-Authorized: 1']);
            self::assertSame(200, $response['status']);
            self::assertStringNotContainsString('Location:', $response['headers']);
        }
        self::assertSame(0, $this->row('registry_items', 1)['received']);
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
        self::assertSame(0, $this->row('house_fund_contributions', 1)['thank_you_written']);
        self::assertSame(0, $this->row('honeymoon_fund_contributions', 1)['thank_you_written']);

        $this->http->clearAcquisitions();
        $apiNonPostHeaders = $this->authorizedPostHeaders($token, ['X-Test-Fail-Db: 1']);
        foreach (['GET', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->assertJsonError(405, 'Method not allowed', $this->http->request(
                '/api/toggle-gift-status',
                $method,
                '{"source":"registry","id":1,"field":"received"}',
                $apiNonPostHeaders,
            ));
            self::assertFalse($this->http->acquiredDatabase());
        }

        $fallbackNonPostHeaders = $this->authorizedPostHeaders($token);
        foreach (['GET', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $fallback = $this->http->request(
                '/admin-gifts',
                $method,
                http_build_query([
                    'toggle_gift_status' => '1',
                    'csrf_token' => $token,
                    'source' => 'registry',
                    'id' => '1',
                    'field' => 'received',
                ]),
                $fallbackNonPostHeaders,
            );
            self::assertSame(200, $fallback['status']);
            self::assertStringNotContainsString('Location:', $fallback['headers']);
        }
        self::assertSame(0, $this->row('registry_items', 1)['received']);
    }

    public function testInvalidOrMissingCsrfTokenIsRejectedBeforeDatabaseAcquisition(): void
    {
        // Before visiting GET /admin-gifts, the session has no issued CSRF token.
        $headersBeforeToken = $this->authorizedPostHeaders('guessed-token', ['X-Test-Fail-Db: 1']);
        $this->assertJsonError(403, 'Invalid CSRF token', $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            '{"source":"registry","id":1,"field":"received"}',
            $headersBeforeToken,
        ));
        $fallbackBeforeToken = $this->postFallback('registry', 1, 'received', 'guessed-token', $headersBeforeToken);
        self::assertSame(403, $fallbackBeforeToken['status'], $fallbackBeforeToken['body']);
        self::assertFalse($this->http->acquiredDatabase());

        $token = $this->fetchCsrfToken();
        $this->http->clearAcquisitions();

        foreach (['', 'wrong-token', substr($token, 0, -1) . ($token[-1] === '0' ? '1' : '0')] as $badToken) {
            $jsonResp = $this->http->request(
                '/api/toggle-gift-status',
                'POST',
                json_encode(['source' => 'registry', 'id' => 1, 'field' => 'received', 'csrf_token' => $badToken], JSON_THROW_ON_ERROR),
                $this->authorizedPostHeaders(null, ['X-Test-Fail-Db: 1']),
            );
            $this->assertJsonError(403, 'Invalid CSRF token', $jsonResp);

            $fallbackResp = $this->postFallback(
                'offregistry',
                1,
                'written',
                $badToken,
                $this->authorizedPostHeaders(null, ['X-Test-Fail-Db: 1']),
            );
            self::assertSame(403, $fallbackResp['status'], $fallbackResp['body']);
            self::assertFalse($this->http->acquiredDatabase());
        }

        // An explicit empty X-CSRF-Token header is rejected even when body includes a valid token.
        $emptyHeaderResp = $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            json_encode(['source' => 'registry', 'id' => 1, 'field' => 'received', 'csrf_token' => $token], JSON_THROW_ON_ERROR),
            $this->authorizedPostHeaders(null, ['X-CSRF-Token;', 'X-Test-Fail-Db: 1']),
        );
        $this->assertJsonError(403, 'Invalid CSRF token', $emptyHeaderResp);
        self::assertFalse($this->http->acquiredDatabase());
        self::assertSame(0, $this->row('registry_items', 1)['received']);
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
    }

    public function testInvalidOriginAndSameSiteHeadersAreRejectedBeforeDatabaseAcquisition(): void
    {
        $token = $this->fetchCsrfToken();
        $this->http->clearAcquisitions();
        $origin = $this->http->origin();

        $badHeaderSets = [
            'missing origin signals' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'X-Test-Fail-Db: 1'],
            'evil origin' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Origin: https://evil.example', 'X-Test-Fail-Db: 1'],
            'null origin' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Origin: null', 'X-Test-Fail-Db: 1'],
            'scheme mismatch origin' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Origin: ' . str_replace('http://', 'https://', $origin), 'X-Test-Fail-Db: 1'],
            'cross-site fetch metadata' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Origin: ' . $origin, 'Sec-Fetch-Site: cross-site', 'X-Test-Fail-Db: 1'],
            'same-site fetch metadata' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Sec-Fetch-Site: same-site', 'X-Test-Fail-Db: 1'],
            'none fetch metadata' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Sec-Fetch-Site: none', 'X-Test-Fail-Db: 1'],
            'evil referer' => ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Origin: ' . $origin, 'Referer: https://evil.example/form.html', 'X-Test-Fail-Db: 1'],
        ];

        foreach ($badHeaderSets as $label => $headers) {
            $jsonResp = $this->http->request(
                '/api/toggle-gift-status',
                'POST',
                '{"source":"housefund","id":1,"field":"written"}',
                $headers,
            );
            $this->assertJsonError(403, 'Invalid request origin', $jsonResp);

            $fallbackResp = $this->postFallback('honeymoonfund', 1, 'written', $token, $headers);
            self::assertSame(403, $fallbackResp['status'], $label . ': ' . $fallbackResp['body']);
            self::assertFalse($this->http->acquiredDatabase(), $label);
        }

        // Confirm Referer-only and Sec-Fetch-Site: same-origin-only valid POSTs succeed.
        $this->assertRedirect($this->postFallback('housefund', 1, 'written', $token, [
            'X-Test-Authorized: 1',
            'Referer: ' . $origin . '/admin-gifts',
        ]));
        self::assertSame(1, $this->row('house_fund_contributions', 1)['thank_you_written']);

        $jsonOk = $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            '{"source":"honeymoonfund","id":1,"field":"written"}',
            ['X-Test-Authorized: 1', 'X-CSRF-Token: ' . $token, 'Sec-Fetch-Site: same-origin'],
        );
        self::assertSame(200, $jsonOk['status'], $jsonOk['body']);
        self::assertSame(1, $this->row('honeymoon_fund_contributions', 1)['thank_you_written']);
    }

    public function testMissingRecordsKeepSourceSpecificJsonErrorsAndSilentFallbackRedirects(): void
    {
        $token = $this->fetchCsrfToken();
        $headers = $this->authorizedPostHeaders($token);
        foreach (self::stages() as [$source, $table, $id, $field, $column]) {
            $body = json_encode(['source' => $source, 'id' => 999, 'field' => $field], JSON_THROW_ON_ERROR);
            $this->assertJsonError(404, match ($source) {
                'registry' => 'Registry item not found', 'offregistry' => 'Gift not found',
                default => 'Contribution not found',
            }, $this->http->request('/api/toggle-gift-status', 'POST', $body, $headers));
            $this->assertRedirect($this->postFallback($source, 999, $field, $token));
        }
        foreach (['received', 'written', 'sent'] as $field) {
            $this->assertJsonError(404, 'Registry item not found', $this->http->request(
                '/api/toggle-gift-status',
                'POST',
                json_encode(['source' => 'registry', 'id' => 2, 'field' => $field], JSON_THROW_ON_ERROR),
                $headers,
            ));
            $this->assertRedirect($this->postFallback('registry', 2, $field, $token));
        }
        self::assertSame(0, $this->row('registry_items', 2)['received']);
    }

    public function testMethodAuthorizationAndInvalidCommandsNeverAcquireDatabase(): void
    {
        $this->assertJsonError(405, 'Method not allowed', $this->http->request('/api/toggle-gift-status'));
        $this->assertJsonError(401, 'Admin authentication required', $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            '{"source":"registry","id":1,"field":"sent"}',
            ['X-Test-Fail-Db: 1'],
        ));
        self::assertFalse($this->http->acquiredDatabase());

        $token = $this->fetchCsrfToken();
        $this->http->clearAcquisitions();
        $failureHeaders = $this->authorizedPostHeaders($token, ['X-Test-Fail-Db: 1']);
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
        foreach ([
            ['/admin-gifts?toggle_registry_received=1', 'GET', '', []],
            ['/admin-gifts?sample=1&toggle_registry_received=1', 'GET', '', []],
            ['/admin-gifts', 'POST', http_build_query([
                'toggle_gift_status' => '1', 'source' => 'registry', 'id' => '1', 'field' => 'received',
            ]), ['Origin: ' . $this->http->origin(), 'Sec-Fetch-Site: same-origin']],
            ['/admin-gifts?sample=1', 'POST', http_build_query([
                'toggle_gift_status' => '1', 'source' => 'registry', 'id' => '1', 'field' => 'received',
            ]), ['X-Test-Authorized: 1', 'Origin: ' . $this->http->origin(), 'Sec-Fetch-Site: same-origin']],
        ] as [$path, $method, $body, $extraHeaders]) {
            $headers = array_merge($extraHeaders, ['X-Test-Fail-Db: 1']);
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            $response = $this->http->request($path, $method, $body, $headers);
            self::assertSame(200, $response['status'], $path);
            self::assertStringNotContainsString('Location:', $response['headers']);
            self::assertFalse($this->http->acquiredDatabase(), $path);
        }
        self::assertSame(0, $this->row('registry_items', 1)['received']);
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
    }

    public function testJsonDatabaseErrorsAreGenericAndLogged(): void
    {
        $token = $this->fetchCsrfToken();
        $this->assertJsonError(500, 'Database error', $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            '{"source":"registry","id":1,"field":"sent"}',
            $this->authorizedPostHeaders($token, ['X-Test-Fail-Db: 1']),
        ));
        self::assertStringContainsString('toggle-gift-status error: owned connection failure', $this->http->log());
        self::database()->pdo->exec("CREATE TRIGGER refuse_status BEFORE UPDATE ON gifts FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'");
        $this->assertJsonError(500, 'Database error', $this->http->request(
            '/api/toggle-gift-status',
            'POST',
            '{"source":"offregistry","id":1,"field":"written"}',
            $this->authorizedPostHeaders($token),
        ));
        self::assertStringContainsString('owned write failure', $this->http->log());
        self::assertSame(0, $this->row('gifts', 1)['thank_you_written']);
    }

    public function testFallbackPersistenceErrorsKeepPrefixes(): void
    {
        $token = $this->fetchCsrfToken();
        foreach ([
            ['registry', 'received', 'registry_items', 'Error updating status:'],
            ['offregistry', 'written', 'gifts', 'Error updating thank-you status:'],
            ['housefund', 'written', 'house_fund_contributions', 'Error updating thank-you status:'],
            ['honeymoonfund', 'written', 'honeymoon_fund_contributions', 'Error updating thank-you status:'],
        ] as [$source, $field, $table, $prefix]) {
            self::database()->reset();
            self::database()->pdo->exec("CREATE TRIGGER refuse_status BEFORE UPDATE ON $table FOR EACH ROW "
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owned write failure'");
            $response = $this->postFallback($source, 1, $field, $token);
            self::assertSame(200, $response['status']);
            self::assertStringContainsString($prefix, $response['body']);
            self::assertStringContainsString('owned write failure', $response['body']);
        }
    }

    private function fetchCsrfToken(): string
    {
        $response = $this->http->request('/admin-gifts', headers: ['X-Test-Authorized: 1']);
        self::assertSame(200, $response['status']);
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($response['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//form[contains(@class,"toggle-status-form")]//input[@name="csrf_token"]');
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length);
        $input = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $input);
        $token = $input->getAttribute('value');
        self::assertNotSame('', $token);
        return $token;
    }

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private function authorizedPostHeaders(?string $token = null, array $extra = []): array
    {
        $headers = [
            'X-Test-Authorized: 1',
            'Origin: ' . $this->http->origin(),
            'Sec-Fetch-Site: same-origin',
        ];
        if ($token !== null) {
            $headers[] = 'X-CSRF-Token: ' . $token;
        }
        return array_merge($headers, $extra);
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string,headers:string}
     */
    private function postFallback(string $source, int|string $id, string $field, ?string $token, array $headers = []): array
    {
        $body = http_build_query([
            'toggle_gift_status' => '1',
            'csrf_token' => $token ?? '',
            'source' => $source,
            'id' => (string) $id,
            'field' => $field,
        ]);
        $effectiveHeaders = $headers === [] ? $this->authorizedPostHeaders() : $headers;
        $effectiveHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        return $this->http->request('/admin-gifts', 'POST', $body, $effectiveHeaders);
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
