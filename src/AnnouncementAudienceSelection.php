<?php

declare(strict_types=1);

namespace Wedding;

/**
 * A composer's selection for one request. Guest rows are supplied in audience query order.
 *
 * @phpstan-type Recipient array{first_name:string, last_name:?string, email:string}
 * @phpstan-type GuestPanel array{rows:list<array{recipient:Recipient, checked:bool}>, hasExplicitFilter:bool}
 */
final class AnnouncementAudienceSelection
{
    /** @var array<string, list<string>> */
    private array $filters = [];

    /** @var list<Recipient> */
    private readonly array $customRecipients;

    /**
     * @param array<string, array<mixed>> $submittedFilters Explicitly submitted guest panels on POST
     * @param ?string $storedDraftFilter The selected audience's saved filter on a draft GET
     */
    public function __construct(
        private readonly string $audience,
        array $submittedFilters,
        ?string $storedDraftFilter,
        string $customRecipientText,
    ) {
        $storedFilter = $storedDraftFilter === null ? null : json_decode($storedDraftFilter);
        if (is_array($storedFilter)) {
            $this->filters[$audience] = self::normalizeFilter($storedFilter);
        }
        foreach ($submittedFilters as $key => $values) {
            $this->filters[$key] = self::normalizeFilter($values);
        }
        $this->customRecipients = self::parseCustomRecipients($customRecipientText);
    }

    public function draftFilter(): ?string
    {
        $filter = $this->filters[$this->audience] ?? null;
        return $filter === null ? null : json_encode($filter, JSON_THROW_ON_ERROR);
    }

    /** @param list<Recipient> $orderedGuests
     *  @return GuestPanel
     */
    public function guestPanel(string $audience, array $orderedGuests): array
    {
        $filter = $this->filters[$audience] ?? null;
        $included = $filter === null ? null : array_fill_keys($filter, true);
        $rows = [];
        $seen = [];
        foreach ($orderedGuests as $guest) {
            $key = self::emailKey($guest['email']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['recipient' => $guest, 'checked' => $included === null || isset($included[$key])];
        }
        return ['rows' => $rows, 'hasExplicitFilter' => $filter !== null];
    }

    /** @param list<Recipient> $orderedGuests Rows for the selected audience
     *  @return list<Recipient>
     */
    public function resolveRecipients(array $orderedGuests): array
    {
        $recipients = [];
        foreach ($this->guestPanel($this->audience, $orderedGuests)['rows'] as $row) {
            if ($row['checked']) {
                $recipient = $row['recipient'];
                $recipients[self::emailKey($recipient['email'])] = $recipient;
            }
        }
        foreach ($this->customRecipients as $recipient) {
            $recipients[self::emailKey($recipient['email'])] ??= $recipient;
        }
        return array_values($recipients);
    }

    public function customRecipientCount(): int
    {
        return count($this->customRecipients);
    }

    public static function countCustomRecipients(string $text): int
    {
        return count(self::parseCustomRecipients($text));
    }

    private static function emailKey(string $email): string
    {
        return strtolower(trim($email));
    }

    /** @param array<mixed> $values
     *  @return list<string>
     */
    private static function normalizeFilter(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            if (is_string($value) && ($key = self::emailKey($value)) !== '') {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    /** @return list<Recipient> */
    private static function parseCustomRecipients(string $text): array
    {
        $tokens = preg_split('/[\r\n,]+/', $text) ?: [];
        $recipients = [];
        foreach ($tokens as $token) {
            $email = trim($token);
            $first = '';
            $last = '';
            if (preg_match('/^(.*?)\s*<\s*([^>]+?)\s*>\s*$/', $email, $match)) {
                $name = trim($match[1], " \t\"'");
                $email = trim($match[2]);
                $parts = preg_split('/\s+/', $name, 2);
                $first = $parts[0] ?? '';
                $last = $parts[1] ?? '';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[self::emailKey($email)] ??= ['first_name' => $first, 'last_name' => $last, 'email' => $email];
            }
        }
        return array_values($recipients);
    }
}
