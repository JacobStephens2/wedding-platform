<?php

declare(strict_types=1);

namespace Wedding;

/**
 * The guest-list spreadsheet, exported as CSV.
 *
 * The header row is the first row with a "Mailing Group" cell (a column both
 * views need), so summary rows a spreadsheet puts above it are skipped. Cells
 * are trimmed.
 */
final class GuestList
{
    /**
     * @param array<string,int> $columns header name => column index
     * @param list<list<string>> $rows data rows below the header
     */
    private function __construct(
        private readonly array $columns,
        private readonly array $rows,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $csv = is_readable($path) ? file_get_contents($path) : false;
        if ($csv === false) {
            throw new \RuntimeException("Could not read $path");
        }
        return self::fromCsv($csv);
    }

    public static function fromCsv(string $csv): self
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a memory stream.');
        }
        fwrite($stream, $csv);
        rewind($stream);

        $records = [];
        while (($record = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($record === [null]) {
                continue; // blank line
            }
            $records[] = array_map(static fn (?string $cell): string => trim((string) $cell), $record);
        }
        fclose($stream);

        foreach ($records as $i => $record) {
            if (in_array('Mailing Group', $record, true)) {
                return new self(array_flip($record), array_slice($records, $i + 1));
            }
        }

        return new self([], []);
    }

    /**
     * @return list<array{first_name:string,last_name:string,group_name:string,guest_id:string,mailing_group:?int}>
     */
    public function guests(): array
    {
        $this->requireColumns('First Name', 'Last Name', 'Group', 'id', 'Mailing Group');
        $guests = [];
        foreach ($this->rows as $row) {
            if ($this->cell($row, 'First Name') === '') {
                continue;
            }
            $mailingGroup = $this->cell($row, 'Mailing Group');
            $guests[] = [
                'first_name'    => $this->cell($row, 'First Name'),
                'last_name'     => $this->cell($row, 'Last Name'),
                'group_name'    => $this->cell($row, 'Group'),
                'guest_id'      => $this->cell($row, 'id'),
                'mailing_group' => is_numeric($mailingGroup) ? (int) $mailingGroup : null,
            ];
        }
        return $guests;
    }

    /**
     * One address per mailing group: the first row in the group with a real
     * Address 1. Placeholder addresses ("^" = same as above, "-", "N/A") and
     * rows without a numeric mailing group are skipped. Address 2 and Country
     * are optional columns; blank optional cells are null.
     *
     * @return array<int,array{address_1:string,address_2:?string,city:?string,state:?string,zip:?string,country:?string}>
     */
    public function addressesByMailingGroup(): array
    {
        $this->requireColumns('Mailing Group', 'Address 1', 'City', 'State', 'ZIP');
        $addresses = [];
        foreach ($this->rows as $row) {
            $group = $this->cell($row, 'Mailing Group');
            $address1 = $this->cell($row, 'Address 1');
            if (!is_numeric($group) || isset($addresses[(int) $group])
                || in_array($address1, ['', '^', '-', 'N/A'], true)) {
                continue;
            }
            $addresses[(int) $group] = [
                'address_1' => $address1,
                'address_2' => $this->cell($row, 'Address 2') ?: null,
                'city'      => $this->cell($row, 'City') ?: null,
                'state'     => $this->cell($row, 'State') ?: null,
                'zip'       => $this->cell($row, 'ZIP') ?: null,
                'country'   => $this->cell($row, 'Country') ?: null,
            ];
        }
        return $addresses;
    }

    private function requireColumns(string ...$columns): void
    {
        $missing = array_values(array_filter($columns, fn (string $c): bool => !isset($this->columns[$c])));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing column(s): ' . implode(', ', $missing));
        }
    }

    /** @param list<string> $row */
    private function cell(array $row, string $column): string
    {
        return isset($this->columns[$column]) ? ($row[$this->columns[$column]] ?? '') : '';
    }
}
