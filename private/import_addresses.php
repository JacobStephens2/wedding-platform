<?php
/**
 * Import mailing addresses from CSV into the mailing_addresses table.
 * Reads one address per mailing group, skipping placeholder entries (^, -, N/A).
 *
 * Usage: php private/import_addresses.php path/to/guest-list.csv [--dry-run]
 *
 * Required columns: Mailing Group, Address 1, City, State, ZIP (Address 2 and
 * Country are optional).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$dryRun = in_array('--dry-run', $argv ?? []);

$csvPath = $argv[1] ?? '';
if ($csvPath === '' || $csvPath === '--dry-run' || !is_readable($csvPath)) {
    echo "Usage: php private/import_addresses.php path/to/guest-list.csv [--dry-run]\n";
    exit(1);
}

try {
    $addresses = Wedding\GuestList::fromCsv((string) file_get_contents($csvPath))->addressesByMailingGroup();
} catch (InvalidArgumentException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Found addresses for " . count($addresses) . " mailing groups.\n";

if ($dryRun) {
    echo "\n[DRY RUN] Would insert the following:\n";
    foreach ($addresses as $mg => $addr) {
        $parts = array_filter([$addr['address_1'], $addr['address_2'], $addr['city'], $addr['state'], $addr['zip'], $addr['country']]);
        echo "  Mailing Group $mg: " . implode(', ', $parts) . "\n";
    }
    exit(0);
}

$pdo = getDbConnection();

$stmt = $pdo->prepare("
    INSERT INTO mailing_addresses (mailing_group, address_1, address_2, city, state, zip, country)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        address_1 = VALUES(address_1),
        address_2 = VALUES(address_2),
        city = VALUES(city),
        state = VALUES(state),
        zip = VALUES(zip),
        country = VALUES(country)
");

$inserted = 0;
foreach ($addresses as $mg => $addr) {
    $stmt->execute([
        $mg,
        $addr['address_1'],
        $addr['address_2'],
        $addr['city'],
        $addr['state'],
        $addr['zip'],
        $addr['country'],
    ]);
    $inserted++;
}

echo "Imported $inserted mailing addresses.\n";
