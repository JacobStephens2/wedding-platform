<?php
/**
 * Import guests from the guest-list CSV export into the guests table.
 * Run from CLI: php private/import_guests.php path/to/guest-list.csv
 *
 * Required columns: First Name, Last Name, Group, id, Mailing Group. Rows above
 * the header (spreadsheet summary rows) and rows without a first name are skipped.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$csvPath = $argv[1] ?? '';
if ($csvPath === '') {
    echo "Usage: php private/import_guests.php path/to/guest-list.csv\n";
    exit(1);
}

try {
    $guests = Wedding\GuestList::fromFile($csvPath)->guests();
} catch (InvalidArgumentException | RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$pdo = getDbConnection();

// Check if guests already exist
$count = (int) $pdo->query("SELECT COUNT(*) FROM guests")->fetchColumn();
if ($count > 0) {
    echo "WARNING: guests table already has $count records.\n";
    echo "Do you want to clear existing data and re-import? (y/N): ";
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
    $pdo->exec("DELETE FROM guests");
    $pdo->exec("ALTER TABLE guests AUTO_INCREMENT = 1");
    echo "Cleared existing records.\n";
}

$stmt = $pdo->prepare("
    INSERT INTO guests (first_name, last_name, group_name, guest_id, mailing_group)
    VALUES (:first_name, :last_name, :group_name, :guest_id, :mailing_group)
");

$imported = 0;
$skipped = 0;

foreach ($guests as $guest) {
    try {
        $stmt->execute([
            ':first_name' => $guest['first_name'],
            ':last_name' => $guest['last_name'],
            ':group_name' => $guest['group_name'],
            ':guest_id' => $guest['guest_id'],
            ':mailing_group' => $guest['mailing_group'],
        ]);
        $imported++;
    } catch (Exception $e) {
        echo "  ERROR ({$guest['first_name']} {$guest['last_name']}): " . $e->getMessage() . "\n";
        $skipped++;
    }
}

echo "\nImport complete!\n";
echo "  Imported: $imported guests\n";
echo "  Skipped:  $skipped rows\n";

// Show summary by mailing group
$groups = $pdo->query("
    SELECT mailing_group, COUNT(*) as cnt 
    FROM guests 
    WHERE mailing_group IS NOT NULL 
    GROUP BY mailing_group 
    ORDER BY mailing_group
")->fetchAll();

echo "\nMailing groups: " . count($groups) . "\n";
echo "Total guests in groups: " . array_sum(array_column($groups, 'cnt')) . "\n";

$ungrouped = (int) $pdo->query("SELECT COUNT(*) FROM guests WHERE mailing_group IS NULL")->fetchColumn();
echo "Ungrouped guests: $ungrouped\n";
