<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wedding\AnnouncementAudienceSelection;

final class AnnouncementAudienceSelectionTest extends TestCase
{
    private const ALEX = ['first_name' => 'Alex', 'last_name' => 'Arbor', 'email' => ' Alex@example.com '];
    private const BLAIR = ['first_name' => 'Blair', 'last_name' => null, 'email' => 'blair@example.com'];
    private const GUESTS = [
        self::ALEX,
        ['first_name' => 'Alias', 'last_name' => 'Birch', 'email' => 'alex@EXAMPLE.com'],
        ['first_name' => 'Blank', 'last_name' => 'Email', 'email' => '  '],
        self::BLAIR,
    ];

    public function testPanelsProjectDistinctOrderedRowsWithIndependentCheckStates(): void
    {
        $selection = new AnnouncementAudienceSelection('selected', [
            'selected' => [' BLAIR@EXAMPLE.COM ', 'blair@example.com', null, 42, ['alex@example.com'], ' '],
            'empty' => [],
        ], null, '');

        self::assertSame(['rows' => [
            ['recipient' => self::ALEX, 'checked' => false],
            ['recipient' => self::BLAIR, 'checked' => true],
        ], 'hasExplicitFilter' => true], $selection->guestPanel('selected', self::GUESTS));
        self::assertSame(['rows' => [], 'hasExplicitFilter' => true], $selection->guestPanel('empty', []));
        self::assertSame(['rows' => [
            ['recipient' => self::ALEX, 'checked' => false],
        ], 'hasExplicitFilter' => true], $selection->guestPanel('empty', [self::ALEX]));
        self::assertSame(['rows' => [
            ['recipient' => self::ALEX, 'checked' => true],
        ], 'hasExplicitFilter' => false], $selection->guestPanel('unsubmitted', [self::ALEX]));
        self::assertSame('["blair@example.com"]', $selection->draftFilter());
    }

    /** @return array<string, array{?string, ?string, list<string>}> */
    public static function storedFilters(): array
    {
        return [
            'SQL NULL' => [null, null, [' Alex@example.com ', 'blair@example.com']],
            'invalid JSON' => ['[', null, [' Alex@example.com ', 'blair@example.com']],
            'JSON object' => ['{"0":"blair@example.com"}', null, [' Alex@example.com ', 'blair@example.com']],
            'JSON scalar' => ['true', null, [' Alex@example.com ', 'blair@example.com']],
            'empty list' => ['[]', '[]', []],
            'nonempty list' => ['[" ALEX@EXAMPLE.COM ",17,null,["blair@example.com"]]', '["alex@example.com"]', [' Alex@example.com ']],
        ];
    }

    /** @param list<string> $expectedEmails */
    #[DataProvider('storedFilters')]
    public function testStoredDraftFilterAppliesOnlyToItsAudience(?string $stored, ?string $encoded, array $expectedEmails): void
    {
        $selection = new AnnouncementAudienceSelection('draft', [], $stored, '');
        self::assertSame($encoded, $selection->draftFilter());
        self::assertSame($expectedEmails, array_column($selection->resolveRecipients(self::GUESTS), 'email'));
        self::assertSame(['rows' => [
            ['recipient' => self::BLAIR, 'checked' => true],
        ], 'hasExplicitFilter' => false], $selection->guestPanel('other', [self::BLAIR]));
    }

    public function testRecipientsKeepGuestOrderAndAppendTheFirstValidCustomEntryPerEmail(): void
    {
        $custom = "\"Custom Alex Person\" < ALEX@example.com >,invalid\nOther <Other@example.com>\r\n"
            . "Repeated Name <other@EXAMPLE.com>, plain@example.com";
        $customAlex = ['first_name' => 'Custom', 'last_name' => 'Alex Person', 'email' => 'ALEX@example.com'];
        $other = ['first_name' => 'Other', 'last_name' => '', 'email' => 'Other@example.com'];
        $plain = ['first_name' => '', 'last_name' => '', 'email' => 'plain@example.com'];

        $unrestricted = new AnnouncementAudienceSelection('guests', [], null, $custom);
        self::assertSame([self::ALEX, self::BLAIR, $other, $plain], $unrestricted->resolveRecipients(self::GUESTS));
        self::assertSame(3, $unrestricted->customRecipientCount());
        self::assertSame(3, AnnouncementAudienceSelection::countCustomRecipients($custom));

        $subset = new AnnouncementAudienceSelection('guests', ['guests' => ['blair@example.com']], null, $custom);
        self::assertSame([self::BLAIR, $customAlex, $other, $plain], $subset->resolveRecipients(self::GUESTS));

        $empty = new AnnouncementAudienceSelection('guests', ['guests' => []], null, $custom);
        self::assertSame([$customAlex, $other, $plain], $empty->resolveRecipients(self::GUESTS));
    }
}
