<?php
/**
 * Rehearsal dinner seating chart API endpoint.
 * Requires admin or rehearsal-seating authentication.
 *
 * POST /api/rehearsal-seating
 * Body (JSON): { "action": "...", ... }
 *
 * Actions:
 *   move_guest     - { guest_id, table_id }  (table_id=null to unseat)
 *   update_table   - { table_id, name?, notes?, capacity? }
 *   add_table      - { table_name }
 *   delete_table   - { table_id }
 *   save_positions - { positions: [{table_id, pos_x, pos_y}, ...] }
 *   reorder_seats  - { table_id, seat_entries[] or guest_ids[] }
 *   reassign_number - { table_id, new_number }
 *   set_plus_one_seat_before - { guest_id, before }
 */

require_once __DIR__ . '/../../private/config.php';
require_once __DIR__ . '/../../private/db.php';
require_once __DIR__ . '/../../private/admin_auth.php';

header('Content-Type: application/json');

if (!isAdminAuthenticated() && !isRehearsalSeatingAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action.']);
    exit;
}

try {
    $pdo = getDbConnection();
    $action = $input['action'];

    switch ($action) {

        case 'move_guest':
            $guestId = intval($input['guest_id'] ?? 0);
            $tableId = isset($input['table_id']) && $input['table_id'] !== null
                ? intval($input['table_id'])
                : null;

            if (!$guestId) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid guest_id.']);
                exit;
            }

            // Verify guest exists and is rehearsal-invited
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, rehearsal_invited FROM guests WHERE id = ?");
            $stmt->execute([$guestId]);
            $guest = $stmt->fetch();
            if (!$guest) {
                http_response_code(404);
                echo json_encode(['error' => 'Guest not found.']);
                exit;
            }

            // Verify table exists if provided
            if ($tableId !== null) {
                $stmt = $pdo->prepare("SELECT id, table_name FROM rehearsal_seating_tables WHERE id = ?");
                $stmt->execute([$tableId]);
                $table = $stmt->fetch();
                if (!$table) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Table not found.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("UPDATE guests SET rehearsal_table_id = ?, rehearsal_seat_number = NULL, rehearsal_plus_one_seat_number = NULL WHERE id = ?");
            $stmt->execute([$tableId, $guestId]);

            $msg = $tableId === null
                ? "{$guest['first_name']} {$guest['last_name']} unseated."
                : "{$guest['first_name']} {$guest['last_name']} moved to {$table['table_name']}.";

            echo json_encode(['success' => true, 'message' => $msg]);
            break;

        case 'update_table':
            $tableId = intval($input['table_id'] ?? 0);
            if (!$tableId) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid table_id.']);
                exit;
            }

            $fields = [];
            $params = [];

            if (isset($input['name'])) {
                $fields[] = 'table_name = ?';
                $params[] = trim($input['name']);
            }
            if (isset($input['notes'])) {
                $fields[] = 'notes = ?';
                $params[] = trim($input['notes']);
            }
            if (isset($input['capacity'])) {
                $fields[] = 'capacity = ?';
                $params[] = max(1, intval($input['capacity']));
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update.']);
                exit;
            }

            $params[] = $tableId;
            $pdo->prepare("UPDATE rehearsal_seating_tables SET " . implode(', ', $fields) . " WHERE id = ?")
                ->execute($params);

            echo json_encode(['success' => true, 'message' => 'Table updated.']);
            break;

        case 'add_table':
            $tableName = trim($input['table_name'] ?? '');
            if (!$tableName) {
                http_response_code(400);
                echo json_encode(['error' => 'Table name required.']);
                exit;
            }

            // Check for duplicate name
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM rehearsal_seating_tables WHERE table_name = ?");
            $stmt->execute([$tableName]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'A table with that name already exists.']);
                exit;
            }

            // Get next table number
            $stmt = $pdo->query("SELECT COALESCE(MAX(table_number), 0) + 1 FROM rehearsal_seating_tables");
            $nextNum = $stmt->fetchColumn();

            $posX = 50;
            $posY = 85;

            $capacity = max(1, intval($input['capacity'] ?? 10));

            $stmt = $pdo->prepare("INSERT INTO rehearsal_seating_tables (table_number, table_name, capacity, pos_x, pos_y) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nextNum, $tableName, $capacity, $posX, $posY]);
            $newId = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => "Table $nextNum created.",
                'table_id' => intval($newId),
                'table_number' => $nextNum,
            ]);
            break;

        case 'delete_table':
            $tableId = intval($input['table_id'] ?? 0);
            if (!$tableId) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid table_id.']);
                exit;
            }

            // Check no guests are seated
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM guests WHERE rehearsal_table_id = ?");
            $stmt->execute([$tableId]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete a table that has guests. Unseat all guests first.']);
                exit;
            }

            $pdo->prepare("DELETE FROM rehearsal_seating_tables WHERE id = ?")->execute([$tableId]);

            echo json_encode(['success' => true, 'message' => 'Table deleted.']);
            break;

        case 'reassign_number':
            $tableId = intval($input['table_id'] ?? 0);
            $newNumber = intval($input['new_number'] ?? 0);
            if (!$tableId || $newNumber < 1) {
                http_response_code(400);
                echo json_encode(['error' => 'Valid table_id and new_number (>= 1) required.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT table_number FROM rehearsal_seating_tables WHERE id = ?");
            $stmt->execute([$tableId]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Table not found.']);
                exit;
            }
            $oldNumber = (int) $row['table_number'];

            if ($oldNumber !== $newNumber) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE rehearsal_seating_tables SET table_number = 0 WHERE id = ?")->execute([$tableId]);
                $pdo->prepare("UPDATE rehearsal_seating_tables SET table_number = table_number + 1 WHERE table_number >= ? ORDER BY table_number DESC")->execute([$newNumber]);
                $pdo->prepare("UPDATE rehearsal_seating_tables SET table_number = ? WHERE id = ?")->execute([$newNumber, $tableId]);
                $stmt = $pdo->query("SELECT id FROM rehearsal_seating_tables ORDER BY table_number ASC, id ASC");
                $seq = 1;
                $renumber = $pdo->prepare("UPDATE rehearsal_seating_tables SET table_number = ? WHERE id = ?");
                foreach ($stmt->fetchAll() as $t) {
                    $renumber->execute([$seq++, $t['id']]);
                }
                $pdo->commit();
            }

            $stmt = $pdo->query("SELECT id, table_number FROM rehearsal_seating_tables ORDER BY table_number");
            $mapping = [];
            foreach ($stmt->fetchAll() as $t) {
                $mapping[] = ['id' => (int) $t['id'], 'number' => (int) $t['table_number']];
            }

            echo json_encode(['success' => true, 'message' => 'Table number reassigned.', 'tables' => $mapping]);
            break;

        case 'reorder_seats':
            $tableId = intval($input['table_id'] ?? 0);
            $seatEntries = $input['seat_entries'] ?? null;
            $guestIds = $input['guest_ids'] ?? null;

            if (!$tableId || (empty($seatEntries) && empty($guestIds))) {
                http_response_code(400);
                echo json_encode(['error' => 'table_id and seat_entries (or guest_ids) required.']);
                exit;
            }

            if ($seatEntries) {
                $guestPositions = [];
                $plusOnePositions = [];
                foreach ($seatEntries as $i => $entry) {
                    $pos = $i + 1;
                    if (is_string($entry) && str_starts_with($entry, 'p')) {
                        $plusOnePositions[intval(substr($entry, 1))] = $pos;
                    } else {
                        $guestPositions[intval($entry)] = $pos;
                    }
                }
                $stmtGuest = $pdo->prepare("UPDATE guests SET rehearsal_seat_number = ? WHERE id = ? AND rehearsal_table_id = ?");
                foreach ($guestPositions as $gid => $pos) {
                    $stmtGuest->execute([$pos, $gid, $tableId]);
                }
                $stmtPlusOne = $pdo->prepare("UPDATE guests SET rehearsal_plus_one_seat_number = ?, rehearsal_plus_one_seat_before = ? WHERE id = ? AND rehearsal_table_id = ?");
                foreach ($plusOnePositions as $gid => $poPos) {
                    $guestPos = $guestPositions[$gid] ?? PHP_INT_MAX;
                    $stmtPlusOne->execute([$poPos, $poPos < $guestPos ? 1 : 0, $gid, $tableId]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE guests SET rehearsal_seat_number = ? WHERE id = ? AND rehearsal_table_id = ?");
                foreach ($guestIds as $i => $gid) {
                    $stmt->execute([$i + 1, intval($gid), $tableId]);
                }
            }
            echo json_encode(['success' => true, 'message' => 'Seat order saved.']);
            break;

        case 'set_plus_one_seat_before':
            $guestId = intval($input['guest_id'] ?? 0);
            $before = intval($input['before'] ?? 0);
            if (!$guestId) {
                http_response_code(400);
                echo json_encode(['error' => 'guest_id required.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE guests SET rehearsal_plus_one_seat_before = ? WHERE id = ?");
            $stmt->execute([$before ? 1 : 0, $guestId]);
            echo json_encode(['success' => true, 'message' => 'Plus-one seat position updated.']);
            break;

        case 'save_positions':
            $positions = $input['positions'] ?? [];
            if (empty($positions)) {
                http_response_code(400);
                echo json_encode(['error' => 'No positions provided.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE rehearsal_seating_tables SET pos_x = ?, pos_y = ? WHERE id = ?");
            foreach ($positions as $pos) {
                $stmt->execute([floatval($pos['pos_x']), floatval($pos['pos_y']), intval($pos['table_id'])]);
            }

            echo json_encode(['success' => true, 'message' => 'Positions saved.']);
            break;

        case 'set_rehearsal_invited':
            $guestId = intval($input['guest_id'] ?? 0);
            $invited = intval($input['invited'] ?? 0);
            $plusOne = isset($input['plus_one']) ? intval($input['plus_one']) : null;

            if (!$guestId) {
                http_response_code(400);
                echo json_encode(['error' => 'guest_id required.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, first_name, last_name, has_plus_one, plus_one_name,
                                          group_name, dietary, message, is_child, is_infant,
                                          plus_one_dietary, plus_one_is_child, plus_one_is_infant,
                                          plus_one_rehearsal_invited,
                                          rehearsal_table_id
                                   FROM guests WHERE id = ?");
            $stmt->execute([$guestId]);
            $guest = $stmt->fetch();
            if (!$guest) {
                http_response_code(404);
                echo json_encode(['error' => 'Guest not found.']);
                exit;
            }

            // If uninviting, also unseat the guest
            if (!$invited && $guest['rehearsal_table_id']) {
                $pdo->prepare("UPDATE guests SET rehearsal_table_id = NULL, rehearsal_seat_number = NULL, rehearsal_plus_one_seat_number = NULL WHERE id = ?")
                    ->execute([$guestId]);
            }

            $pdo->prepare("UPDATE guests SET rehearsal_invited = ? WHERE id = ?")
                ->execute([$invited ? 1 : 0, $guestId]);

            // Optionally set plus-one status too
            if ($plusOne !== null) {
                $pdo->prepare("UPDATE guests SET plus_one_rehearsal_invited = ? WHERE id = ?")
                    ->execute([$plusOne ? 1 : 0, $guestId]);
            }

            // If uninviting plus-one but guest stays, clear plus-one seating
            if ($plusOne === 0) {
                $pdo->prepare("UPDATE guests SET rehearsal_plus_one_seat_number = NULL, rehearsal_plus_one_seat_before = 0 WHERE id = ?")
                    ->execute([$guestId]);
            }

            // Return guest info for UI update
            $name = $guest['first_name'] . ' ' . $guest['last_name'];
            $msg = $invited ? "$name added to rehearsal dinner." : "$name removed from rehearsal dinner.";

            $guestInfo = [
                'id' => (int) $guest['id'],
                'name' => $name,
                'group' => $guest['group_name'],
                'dietary' => $guest['dietary'] ?? '',
                'message' => $guest['message'] ?? '',
                'is_child' => (bool) $guest['is_child'],
                'is_infant' => (bool) $guest['is_infant'],
                'has_plus_one' => (bool) ($guest['has_plus_one'] && ($plusOne ?? $guest['plus_one_rehearsal_invited'])),
                'plus_one_name' => $guest['plus_one_name'] ?: 'Guest of ' . $guest['first_name'],
                'plus_one_dietary' => $guest['plus_one_dietary'] ?? '',
                'plus_one_is_child' => (bool) $guest['plus_one_is_child'],
                'plus_one_is_infant' => (bool) $guest['plus_one_is_infant'],
                'plus_one_seat_before' => false,
                'has_plus_one_available' => (bool) $guest['has_plus_one'],
                'plus_one_name_raw' => $guest['plus_one_name'] ?: '',
            ];

            echo json_encode(['success' => true, 'message' => $msg, 'guest' => $guestInfo]);
            break;

        case 'search_guests':
            $query = trim($input['query'] ?? '');
            if (strlen($query) < 2) {
                echo json_encode(['success' => true, 'guests' => []]);
                exit;
            }

            $like = '%' . $query . '%';
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name, group_name, rehearsal_invited,
                       has_plus_one, plus_one_name, plus_one_rehearsal_invited,
                       dietary, is_child, is_infant,
                       plus_one_dietary, plus_one_is_child, plus_one_is_infant
                FROM guests
                WHERE (CONCAT(first_name, ' ', last_name) LIKE ?
                       OR first_name LIKE ?
                       OR last_name LIKE ?
                       OR group_name LIKE ?)
                ORDER BY last_name, first_name
                LIMIT 20
            ");
            $stmt->execute([$like, $like, $like, $like]);
            $results = [];
            foreach ($stmt->fetchAll() as $r) {
                $results[] = [
                    'id' => (int) $r['id'],
                    'first_name' => $r['first_name'],
                    'last_name' => $r['last_name'],
                    'name' => $r['first_name'] . ' ' . $r['last_name'],
                    'group' => $r['group_name'],
                    'rehearsal_invited' => (bool) $r['rehearsal_invited'],
                    'has_plus_one' => (bool) $r['has_plus_one'],
                    'plus_one_name' => $r['plus_one_name'] ?: '',
                    'plus_one_rehearsal_invited' => (bool) $r['plus_one_rehearsal_invited'],
                ];
            }
            echo json_encode(['success' => true, 'guests' => $results]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }

} catch (Exception $e) {
    error_log("Rehearsal Seating API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error. Please try again.']);
}
