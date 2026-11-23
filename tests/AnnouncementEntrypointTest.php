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
        $preview = $this->page($this->request(post: http_build_query($posted)));
        $allGuests = ['Alex@example.com', 'blair@example.com', 'casey@example.com'];
        self::assertSame($allGuests, $this->previewEmails($preview, 3));
        self::assertSame($allGuests, $this->checkedEmails($preview, 'all_with_email'));

        $posted['included_recipients_submitted'] = ['all_with_email' => '1'];
        $posted['included_recipients']['all_with_email'] = 'blair@example.com';
        $preview = $this->page($this->request(post: http_build_query($posted)));
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

        $saved = $this->request(post: http_build_query($fields));
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
        $posted = http_build_query(['draft_id' => '1', 'action' => 'preview']);
        $previewWithoutMarker = $this->page($this->request(post: $posted));
        self::assertSame(['Alex@example.com', 'blair@example.com', 'casey@example.com'],
            $this->previewEmails($previewWithoutMarker, 3));
    }

    public function testExplicitFilterSurvivesWhenItsPanelTemporarilyHasNoRows(): void
    {
        self::$database->pdo->exec("UPDATE guests SET ceremony_attending = 'no'");
        $posted = http_build_query(['action' => 'preview', 'audience' => 'attending_ceremony',
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

    /** @return array{status:int,body:string,headers:string} */
    private function request(string $path = '/admin-announcements', ?string $post = null): array
    {
        return LocalHttpRequest::send($this->http->origin . $path, $post === null ? 'GET' : 'POST', $post ?? '',
            ['Cookie: ' . AnnouncementHttp::SESSION_COOKIE]);
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
