<?php

declare(strict_types=1);

namespace Wedding\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wedding\Tests\support\AnnouncementHttp;
use Wedding\Tests\support\LocalHttpRequest;
use Wedding\Tests\support\LocalMariaDb;

final class AnnouncementEntrypointTest extends TestCase
{
    private static LocalMariaDb $database;
    private AnnouncementHttp $http;
    private ?string $csrfToken = null;
    private bool $csrfTokenLoaded = false;

    public static function setUpBeforeClass(): void
    {
        LocalHttpRequest::requireCurl();
        self::$database = new LocalMariaDb();
        try {
            AnnouncementHttp::createSchema(self::$database);
        } catch (\Throwable $error) {
            self::$database->close();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$database)) {
            self::$database->close();
        }
    }

    protected function setUp(): void
    {
        AnnouncementHttp::seed(self::$database);
        $this->http = new AnnouncementHttp(self::$database);
    }

    protected function tearDown(): void
    {
        if (isset($this->http)) {
            $this->http->close();
        }
    }

    public function testMarkerOnlyPreviewKeepsGuestsUncheckedWhenSendingReturnedForm(): void
    {
        $form = $this->page($this->request());
        // Simulate unchecking every guest in two panels and keeping one guest checked in a third.
        foreach ($this->elements($form, '//input[@type="checkbox"][@name]') as $checkbox) {
            if (in_array($checkbox->getAttribute('data-audience'), ['all_with_email', 'attending_ceremony'], true)
                || ($checkbox->getAttribute('data-audience') === 'rsvp_yes'
                    && $checkbox->getAttribute('value') !== 'blair@example.com')) {
                $checkbox->removeAttribute('checked');
            }
        }
        $this->element($form, '//textarea[@name="custom_recipients"]')->textContent = 'Custom Alex <alex@EXAMPLE.com>';
        $this->element($form, '//input[@name="subject"]')->setAttribute('value', 'Hello {{first_name}}');

        $preview = $this->page($this->submit($form, 'preview'));
        self::assertSame(['alex@EXAMPLE.com'], $this->previewEmails($preview, 1));
        self::assertSame([], $this->checkedEmails($preview, 'all_with_email'), 'Preview must keep all guests unchecked');
        self::assertSame([], $this->checkedEmails($preview, 'attending_ceremony'));
        self::assertSame(['blair@example.com'], $this->checkedEmails($preview, 'rsvp_yes'));
        self::assertSame('', $this->http->sentMessages());

        $sent = $this->submit($preview, 'send_blast');
        self::assertSame(200, $sent['status'], $this->http->log());
        self::assertStringContainsString('Sent to all 1 recipients.', $sent['body']);
        self::assertSame("alex@EXAMPLE.com\tHello Custom\n", $this->http->sentMessages());
    }

    public function testPreviewTreatsOnlyMarkedPanelsAsSubmitted(): void
    {
        $posted = ['action' => 'preview', 'included_recipients' => ['all_with_email' => ['blair@example.com']]];
        $preview = $this->page($this->request(post: $this->verifiedQuery($posted)));
        $allGuests = ['Alex@example.com', 'blair@example.com', 'casey@example.com'];
        self::assertSame($allGuests, $this->previewEmails($preview, 3));
        self::assertSame($allGuests, $this->checkedEmails($preview, 'all_with_email'));

        $posted['included_recipients_submitted'] = ['all_with_email' => '1'];
        $posted['included_recipients']['all_with_email'] = 'blair@example.com';
        $preview = $this->page($this->request(post: $this->verifiedQuery($posted)));
        self::assertSame([], $this->previewEmails($preview, 0));
        self::assertSame([], $this->checkedEmails($preview, 'all_with_email'));
    }

    /** @return array<string, array{?list<string>, ?string, list<string>}> */
    public static function draftFilters(): array
    {
        return [
            'unrestricted' => [null, null, ['Alex@example.com', 'blair@example.com', 'casey@example.com']],
            'empty' => [[], '[]', []],
            'one explicit address' => [[' BLAIR@EXAMPLE.COM ', 'blair@example.com'], '["blair@example.com"]', ['blair@example.com']],
        ];
    }

    /**
     * @param ?list<string> $submitted
     * @param list<string> $expectedGuestEmails
     */
    #[DataProvider('draftFilters')]
    public function testDraftFilterRoundTripsAndSubmitsAgain(?array $submitted, ?string $storedFilter, array $expectedGuestEmails): void
    {
        $fields = ['action' => 'save_draft', 'subject' => 'A saved announcement',
            'body' => 'Sample message', 'custom_recipients' => 'custom@example.com',
            'included_recipients_submitted' => ['rsvp_yes' => '1']];
        if ($submitted !== null) {
            $fields['included_recipients_submitted']['all_with_email'] = '1';
            $fields['included_recipients'] = ['all_with_email' => $submitted];
        }

        $saved = $this->request(post: $this->verifiedQuery($fields));
        self::assertSame(302, $saved['status'], $this->http->log());
        self::assertMatchesRegularExpression('~^Location: /admin-announcements\?draft=1&draft_saved=1\r?$~m', $saved['headers']);
        $query = self::$database->pdo->query('SELECT included_emails FROM announcement_drafts WHERE id = 1');
        self::assertNotFalse($query);
        self::assertSame($storedFilter, $query->fetchColumn());

        $draft = $this->page($this->request('/admin-announcements?draft=1&draft_saved=1'));
        self::assertSame($expectedGuestEmails, $this->checkedEmails($draft, 'all_with_email'));
        self::assertSame(['Alex@example.com', 'blair@example.com'], $this->checkedEmails($draft, 'rsvp_yes'));
        $expectedPreviewEmails = [...$expectedGuestEmails, 'custom@example.com'];
        $preview = $this->page($this->submit($draft, 'preview'));
        self::assertSame($expectedPreviewEmails, $this->previewEmails($preview, count($expectedPreviewEmails)));

        // A new POST without the selected panel's marker takes precedence over the stored draft.
        $posted = $this->verifiedQuery(['draft_id' => '1', 'action' => 'preview']);
        $previewWithoutMarker = $this->page($this->request(post: $posted));
        self::assertSame(['Alex@example.com', 'blair@example.com', 'casey@example.com'],
            $this->previewEmails($previewWithoutMarker, 3));
    }

    public function testExplicitFilterSurvivesWhenItsPanelTemporarilyHasNoRows(): void
    {
        self::$database->pdo->exec("UPDATE guests SET ceremony_attending = 'no'");
        $posted = $this->verifiedQuery(['action' => 'preview', 'audience' => 'attending_ceremony',
            'included_recipients_submitted' => ['attending_ceremony' => '1']]);
        $preview = $this->page($this->request(post: $posted));
        self::assertSame([], $this->previewEmails($preview, 0));
        $this->element($preview, '//input[@name="included_recipients_submitted[attending_ceremony]"]');

        self::$database->pdo->exec("UPDATE guests SET ceremony_attending = 'yes' WHERE first_name = 'Alex'");
        $preview = $this->page($this->submit($preview, 'preview'));
        self::assertSame([], $this->previewEmails($preview, 0));
        self::assertSame([], $this->checkedEmails($preview, 'attending_ceremony'));
    }

    public function testSavedAudienceUsesEditableCustomTextAndCounts(): void
    {
        $form = $this->page($this->request('/admin-announcements?audience=custom_1'));
        $savedBadge = '//label[input[@name="audience"][@value="custom_1"]]/span[@class="count-badge"]';
        $recognizedCount = '//*[@id="recipients-helper-custom"]/../strong';
        $textarea = '//textarea[@name="custom_recipients"]';
        self::assertSame('2', $this->element($form, $savedBadge)->textContent);
        self::assertSame('2', $this->element($form, $recognizedCount)->textContent);
        self::assertSame('Saved Recipient <Saved@example.com>, invalid, saved@EXAMPLE.com, extra@example.com',
            $this->element($form, $textarea)->textContent);

        $this->element($form, $textarea)->textContent = '';
        $cleared = $this->page($this->submit($form, 'preview'));
        self::assertSame([], $this->previewEmails($cleared, 0));
        self::assertSame('', $this->element($cleared, $textarea)->textContent);
        self::assertSame([], $this->elements($cleared, $recognizedCount));

        $this->element($cleared, $textarea)->textContent = 'Edited Guest <Edited@example.com>, edited@EXAMPLE.com';
        $this->element($cleared, '//input[@name="subject"]')->setAttribute('value', 'Hello {{first_name}}');
        $edited = $this->page($this->submit($cleared, 'preview'));
        self::assertSame('2', $this->element($edited, $savedBadge)->textContent);
        self::assertSame('1', $this->element($edited, $recognizedCount)->textContent);
        self::assertSame(['Edited@example.com'], $this->previewEmails($edited, 1));
        self::assertSame(200, $this->submit($edited, 'send_blast')['status'], $this->http->log());
        self::assertSame("Edited@example.com\tHello Edited\n", $this->http->sentMessages());
    }

    public function testComposerIncludesTheGiftStatusRequestVerification(): void
    {
        $page = $this->page($this->request());
        $token = $this->element($page, '//form[@id="blast-form"]//input[@name="csrf_token"]')->getAttribute('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        $again = $this->page($this->request());
        self::assertSame($token, $this->element($again, '//form[@id="blast-form"]//input[@name="csrf_token"]')->getAttribute('value'));

        $saved = $this->request(post: $this->verifiedQuery([
            'action' => 'save_draft',
            'audience' => 'all_with_email',
            'subject' => 'Token draft',
            'body' => 'Body',
        ]));
        self::assertSame(302, $saved['status'], $saved['body'] . $this->http->log());
        $draft = $this->page($this->request('/admin-announcements?draft=1&draft_saved=1'));
        self::assertSame(
            $token,
            $this->element($draft, '//form[contains(@class,"draft-delete-form")]//input[@name="csrf_token"]')->getAttribute('value'),
        );
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function unverifiedAnnouncementChanges(): array
    {
        return [
            'save draft' => [[
                'action' => 'save_draft',
                'audience' => 'all_with_email',
                'subject' => 'Blocked draft',
                'body' => 'Do not save',
            ], 'draft-absent', '/admin-announcements'],
            'delete draft' => [[
                'action' => 'delete_draft',
                'draft_id' => '1',
                'audience' => 'all_with_email',
            ], 'draft-remains', '/admin-announcements'],
            'save audience' => [[
                'action' => 'save_audience',
                'audience' => 'custom',
                'audience_name' => 'Blocked audience',
                'custom_recipients' => 'blocked@example.com',
            ], 'audience-absent', '/admin-announcements'],
            'delete audience' => [[
                'delete_audience_id' => '1',
            ], 'audience-remains', '/admin-announcements'],
            'preview' => [[
                'action' => 'preview',
                'audience' => 'all_with_email',
            ], 'no-preview', '/admin-announcements'],
            'test send' => [[
                'action' => 'test_send',
                'audience' => 'custom',
                'custom_recipients' => 'victim@example.com',
                'subject' => 'Hello {{first_name}}',
                'body' => 'Body',
                'test_email' => 'tester@example.com',
            ], 'no-mail', '/admin-announcements'],
            'send' => [[
                'action' => 'send_blast',
                'audience' => 'custom',
                'custom_recipients' => 'Guest <send@example.com>',
                'subject' => 'Hello {{first_name}}',
                'body' => 'Body',
            ], 'no-mail', '/admin-announcements'],
            'wrong token' => [[
                'action' => 'save_draft',
                'audience' => 'all_with_email',
                'subject' => 'Blocked draft',
                'body' => 'Do not save',
                'csrf_token' => '0000000000000000000000000000000000000000000000000000000000000000',
            ], 'draft-absent', '/admin-announcements'],
            'sample preview' => [[
                'action' => 'preview',
                'audience' => 'all_with_email',
            ], 'no-preview', '/admin-announcements?sample=1'],
            'sample send' => [[
                'action' => 'send_blast',
                'audience' => 'custom',
                'custom_recipients' => 'Guest <send@example.com>',
                'subject' => 'Hello {{first_name}}',
                'body' => 'Body',
            ], 'no-mail', '/admin-announcements?sample=1'],
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('unverifiedAnnouncementChanges')]
    public function testUnverifiedAnnouncementChangeIsRejected(array $fields, string $effect, string $path): void
    {
        if ($effect === 'draft-remains') {
            $saved = $this->request(post: $this->verifiedQuery([
                'action' => 'save_draft',
                'audience' => 'all_with_email',
                'subject' => 'Keep this draft',
                'body' => 'Stay',
            ]));
            self::assertSame(302, $saved['status'], $saved['body'] . $this->http->log());
        }

        $response = $this->request($path, http_build_query($fields), $this->sameOriginHeaders());
        self::assertSame(403, $response['status'], $response['body'] . $this->http->log());
        self::assertStringContainsString('Invalid CSRF token', $response['body']);
        $this->assertAnnouncementChangeDidNotHappen($effect, $response['body']);
    }

    public function testForgedOriginAnnouncementChangeIsRejected(): void
    {
        $page = $this->page($this->request());
        $token = $this->element($page, '//form[@id="blast-form"]//input[@name="csrf_token"]')->getAttribute('value');
        $fields = [
            'action' => 'send_blast',
            'audience' => 'custom',
            'custom_recipients' => 'Guest <send@example.com>',
            'subject' => 'Hello',
            'body' => 'Body',
            'csrf_token' => $token,
        ];
        foreach ([
            'cross-site' => ['Origin: https://evil.example', 'Sec-Fetch-Site: cross-site'],
            'no metadata' => [],
        ] as $label => $headers) {
            $response = $this->request(post: http_build_query($fields), extraHeaders: $headers);
            self::assertSame(403, $response['status'], $label . "\n" . $response['body']);
            self::assertStringContainsString('Invalid request origin', $response['body'], $label);
            self::assertStringNotContainsString('send@example.com', $response['body'], $label);
        }
        self::assertSame('', $this->http->sentMessages());
    }

    public function testVerifiedRequestCanSaveAndDeleteADraftAndSavedAudienceAndSendATest(): void
    {
        $savedAudience = $this->request(post: $this->verifiedQuery([
            'action' => 'save_audience',
            'audience' => 'custom',
            'audience_name' => 'Family',
            'custom_recipients' => 'Family Guest <family@example.com>',
        ]));
        self::assertSame(302, $savedAudience['status'], $savedAudience['body'] . $this->http->log());
        self::assertMatchesRegularExpression(
            '~^Location: /admin-announcements\?audience=custom_2&audience_saved=1\r?$~m',
            $savedAudience['headers'],
        );
        $audience = $this->page($this->request('/admin-announcements?audience=custom_2&audience_saved=1'));
        self::assertSame('Audience saved.', $this->element($audience, '//*[@class="alert alert-success"]/p')->textContent);
        self::assertSame('Family Guest <family@example.com>', $this->element($audience, '//textarea[@name="custom_recipients"]')->textContent);

        $deletedAudience = $this->request(post: $this->verifiedQuery(['delete_audience_id' => '2']));
        self::assertSame(302, $deletedAudience['status'], $deletedAudience['body'] . $this->http->log());
        self::assertMatchesRegularExpression(
            '~^Location: /admin-announcements\?audience_deleted=1\r?$~m',
            $deletedAudience['headers'],
        );
        $afterAudienceDelete = $this->page($this->request('/admin-announcements?audience_deleted=1'));
        self::assertSame('Audience deleted.', $this->element($afterAudienceDelete, '//*[@class="alert alert-success"]/p')->textContent);
        self::assertSame([], $this->elements($afterAudienceDelete, '//span[text()="Family"]'));
        self::assertSame(
            'Saved Recipient <Saved@example.com>, invalid, saved@EXAMPLE.com, extra@example.com',
            $this->element($this->page($this->request('/admin-announcements?audience=custom_1')), '//textarea[@name="custom_recipients"]')->textContent,
        );

        $savedDraft = $this->request(post: $this->verifiedQuery([
            'action' => 'save_draft',
            'audience' => 'all_with_email',
            'subject' => 'Disposable draft',
            'body' => 'Body',
        ]));
        self::assertSame(302, $savedDraft['status'], $savedDraft['body'] . $this->http->log());
        self::assertMatchesRegularExpression(
            '~^Location: /admin-announcements\?draft=1&draft_saved=1\r?$~m',
            $savedDraft['headers'],
        );

        $deletedDraft = $this->request(post: $this->verifiedQuery([
            'action' => 'delete_draft',
            'draft_id' => '1',
            'audience' => 'all_with_email',
        ]));
        self::assertSame(302, $deletedDraft['status'], $deletedDraft['body'] . $this->http->log());
        self::assertMatchesRegularExpression(
            '~^Location: /admin-announcements\?draft_deleted=1\r?$~m',
            $deletedDraft['headers'],
        );
        $afterDraftDelete = $this->page($this->request('/admin-announcements?draft_deleted=1'));
        self::assertSame('Draft deleted.', $this->element($afterDraftDelete, '//*[@class="alert alert-success"]/p')->textContent);
        self::assertSame([], $this->elements($afterDraftDelete, '//h2[text()="Drafts"]'));

        $tested = $this->request(post: $this->verifiedQuery([
            'action' => 'test_send',
            'audience' => 'custom',
            'custom_recipients' => 'person@example.com',
            'subject' => 'Hello {{first_name}}',
            'body' => 'Hi',
            'test_email' => 'tester@example.com',
        ]));
        self::assertSame(200, $tested['status'], $tested['body'] . $this->http->log());
        self::assertSame('Test email sent to tester@example.com.', $this->element($this->page($tested), '//*[@class="alert alert-success"]/p')->textContent);
        self::assertSame("tester@example.com\t[TEST] Hello Test\n", $this->http->sentMessages());

        $sampleSave = $this->request('/admin-announcements?sample=1', $this->verifiedQuery([
            'action' => 'save_draft',
            'audience' => 'all_with_email',
            'subject' => 'Sample draft',
            'body' => 'No',
        ]));
        self::assertSame(200, $sampleSave['status'], $sampleSave['body'] . $this->http->log());
        $afterSampleSave = $this->page($this->request());
        self::assertSame([], $this->elements($afterSampleSave, '//h2[text()="Drafts"]'));
        self::assertSame([], $this->elements($afterSampleSave, '//*[text()="Sample draft"]'));
        $sampleSend = $this->request('/admin-announcements?sample=1', $this->verifiedQuery([
            'action' => 'send_blast',
            'audience' => 'custom',
            'custom_recipients' => 'Guest <sample@example.com>',
            'subject' => 'Hello',
            'body' => 'Body',
        ]));
        self::assertSame(200, $sampleSend['status'], $sampleSend['body'] . $this->http->log());
        self::assertStringContainsString('Sample mode — no emails actually sent.', $sampleSend['body']);
        self::assertSame("tester@example.com\t[TEST] Hello Test\n", $this->http->sentMessages());
    }

    /**
     * @param array{status:int,body:string,headers:string} $response
     */
    private function page(array $response): DOMXPath
    {
        self::assertSame(200, $response['status'], $this->http->log());
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($response['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $this->element($xpath, '//form[@id="blast-form"]');
        return $xpath;
    }

    /** @return list<string> */
    private function checkedEmails(DOMXPath $page, string $audience): array
    {
        return array_map(
            static fn (DOMElement $input): string => $input->getAttribute('value'),
            $this->elements($page, '//input[@data-audience="' . $audience . '"][@type="checkbox"][@name][@checked]'),
        );
    }

    /** @return list<string> */
    private function previewEmails(DOMXPath $page, int $count): array
    {
        $heading = 'Preview: ' . $count . ($count === 1 ? ' recipient' : ' recipients');
        $this->element($page, '//h2[text()="' . $heading . '"]');
        return array_map(
            static fn (DOMElement $cell): string => $cell->textContent,
            $this->elements($page, '//div[h2[text()="' . $heading . '"]]//tbody/tr/td[2]'),
        );
    }

    /** @return array{status:int,body:string,headers:string} */
    private function submit(DOMXPath $page, string $action): array
    {
        $formPath = '//form[@id="blast-form"]';
        $form = $this->element($page, $formPath);
        $submitter = $this->element($page, $formPath . '//button[@type="submit"][@value="' . $action . '"]');
        $query = $formPath . '//input[@name][not(@type="checkbox" or @type="radio") or @checked]'
            . ' | ' . $formPath . '//textarea[@name]';
        $fields = [urlencode($submitter->getAttribute('name')) . '=' . urlencode($submitter->getAttribute('value'))];
        foreach ($this->elements($page, $query) as $field) {
            $value = $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
            $fields[] = urlencode($field->getAttribute('name')) . '=' . urlencode($value);
        }
        return $this->request($form->getAttribute('action'), implode('&', $fields));
    }

    /**
     * @param ?list<string> $extraHeaders null uses same-origin metadata for a POST
     * @return array{status:int,body:string,headers:string}
     */
    private function request(string $path = '/admin-announcements', ?string $post = null, ?array $extraHeaders = null): array
    {
        $headers = $extraHeaders ?? ($post === null ? [] : $this->sameOriginHeaders());
        $headers[] = 'Cookie: ' . AnnouncementHttp::SESSION_COOKIE;
        return LocalHttpRequest::send($this->http->origin . $path, $post === null ? 'GET' : 'POST', $post ?? '', $headers);
    }

    /** @return list<string> */
    private function sameOriginHeaders(): array
    {
        return [
            'Origin: ' . $this->http->origin,
            'Sec-Fetch-Site: same-origin',
        ];
    }

    /** @param array<string, mixed> $fields */
    private function verifiedQuery(array $fields): string
    {
        $token = $this->issuedCsrfToken();
        if ($token !== null) {
            $fields['csrf_token'] = $token;
        }
        return http_build_query($fields);
    }

    private function issuedCsrfToken(): ?string
    {
        if ($this->csrfTokenLoaded) {
            return $this->csrfToken;
        }
        $this->csrfTokenLoaded = true;
        $page = $this->page($this->request());
        $nodes = $page->query('//form[@id="blast-form"]//input[@name="csrf_token"]');
        self::assertNotFalse($nodes);
        if ($nodes->length === 0) {
            return null;
        }
        self::assertSame(1, $nodes->length);
        $input = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $input);
        $token = $input->getAttribute('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->csrfToken = $token;
        return $token;
    }

    private function assertAnnouncementChangeDidNotHappen(string $effect, string $body): void
    {
        if ($effect === 'draft-absent') {
            $page = $this->page($this->request());
            self::assertSame([], $this->elements($page, '//h2[text()="Drafts"]'));
            self::assertSame([], $this->elements($page, '//*[text()="Blocked draft"]'));
            return;
        }
        if ($effect === 'draft-remains') {
            $page = $this->page($this->request());
            $this->element($page, '//a[contains(@class,"draft-link")]//span[contains(@class,"draft-subject")][contains(., "Keep this draft")]');
            return;
        }
        if ($effect === 'audience-absent') {
            $page = $this->page($this->request());
            self::assertSame([], $this->elements($page, '//span[text()="Blocked audience"]'));
            $this->element($page, '//span[text()="Sample list"]');
            return;
        }
        if ($effect === 'audience-remains') {
            $page = $this->page($this->request('/admin-announcements?audience=custom_1'));
            self::assertSame(
                'Saved Recipient <Saved@example.com>, invalid, saved@EXAMPLE.com, extra@example.com',
                $this->element($page, '//textarea[@name="custom_recipients"]')->textContent,
            );
            return;
        }
        if ($effect === 'no-preview') {
            self::assertStringNotContainsString('Preview:', $body);
            self::assertStringNotContainsString('Alex@example.com', $body);
            self::assertSame('', $this->http->sentMessages());
            return;
        }
        if ($effect === 'no-mail') {
            self::assertSame('', $this->http->sentMessages());
            $history = $this->request();
            self::assertSame(200, $history['status'], $history['body']);
            self::assertStringContainsString('No emails sent yet.', $history['body']);
            return;
        }
        self::fail('Unknown announcement effect: ' . $effect);
    }

    private function element(DOMXPath $page, string $query): DOMElement
    {
        $elements = $this->elements($page, $query);
        self::assertCount(1, $elements, $query);
        return $elements[0];
    }

    /** @return list<DOMElement> */
    private function elements(DOMXPath $page, string $query): array
    {
        $nodes = $page->query($query);
        self::assertNotFalse($nodes, $query);
        $elements = [];
        foreach ($nodes as $node) {
            self::assertInstanceOf(DOMElement::class, $node, $query);
            $elements[] = $node;
        }
        return $elements;
    }
}
