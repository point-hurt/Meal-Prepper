<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/scorer.php';

try {
    $kbPdo = new PDO('sqlite:' . __DIR__ . '/knowledge_base.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $userPdo = new PDO('sqlite:' . __DIR__ . '/user_state.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $scorer = new MealScorer($kbPdo, $userPdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd połączenia z bazą: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'initial_data';

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_merge($_POST, $decoded) : $_POST;
}

function scoreWithInput(MealScorer $scorer, array $input): array {
    $options = ['filter_by_prep_time' => (bool)($input['filter_by_prep_time'] ?? true)];
    $prefTime = (int)($input['preferred_prep_time'] ?? 0);
    if ($prefTime > 0) $options['preferred_prep_time'] = $prefTime;
    return $scorer->scoreAndFilterRecipes($options);
}

try {
    switch ($action) {
        case 'initial_data':
        case 'bootstrap':
            $allEquipment = $kbPdo->query("SELECT id, name, icon, category FROM equipment ORDER BY category, name")->fetchAll();
            $userEqMap = $userPdo->query("SELECT equipment_id, is_owned FROM user_equipment")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($allEquipment as &$eq) {
                $eq['is_owned'] = (int)($userEqMap[$eq['id']] ?? 0);
            }
            unset($eq);

            $allIngredients = $kbPdo->query("SELECT id, name, icon, category FROM ingredients ORDER BY category, name")->fetchAll();
            $userPantry = $userPdo->query("SELECT ingredient_id, is_owned, is_key FROM user_pantry")->fetchAll();
            $userPantryMap = [];
            foreach ($userPantry as $r) {
                $userPantryMap[$r['ingredient_id']] = $r;
            }
            foreach ($allIngredients as &$ing) {
                $ing['is_owned'] = (int)($userPantryMap[$ing['id']]['is_owned'] ?? 0);
                $ing['is_key'] = (int)($userPantryMap[$ing['id']]['is_key'] ?? 0);
            }
            unset($ing);

            $preferences = $userPdo->query("SELECT key, value FROM user_preferences")->fetchAll(PDO::FETCH_KEY_PAIR);
            $preferences['preferred_prep_time'] ??= "25";

            echo json_encode([
                'success' => true,
                'equipment' => $allEquipment,
                'ingredients' => $allIngredients,
                'preferences' => $preferences,
                'recommendations' => $scorer->scoreAndFilterRecipes([
                    'preferred_prep_time' => (int)$preferences['preferred_prep_time'],
                    'filter_by_prep_time' => true,
                ]),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'toggle_equipment':
            $input = getJsonInput();
            $equipmentId = $input['equipment_id'] ?? null;
            if (!$equipmentId) throw new InvalidArgumentException("Brak equipment_id");

            $isOwned = (int)(bool)($input['is_owned'] ?? 1);
            $userPdo->prepare("
                INSERT INTO user_equipment (equipment_id, is_owned, is_key) VALUES (?, ?, 0)
                ON CONFLICT(equipment_id) DO UPDATE SET is_owned = excluded.is_owned
            ")->execute([$equipmentId, $isOwned]);

            echo json_encode([
                'success' => true,
                'equipment_id' => $equipmentId,
                'is_owned' => $isOwned,
                'recommendations' => scoreWithInput($scorer, $input),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle_all_equipment':
            $input = getJsonInput();
            $owned = (int)(bool)($input['is_owned'] ?? 0);
            $userPdo->prepare("UPDATE user_equipment SET is_owned = ?")->execute([$owned]);

            echo json_encode([
                'success' => true,
                'recommendations' => scoreWithInput($scorer, $input),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle_ingredient':
            $input = getJsonInput();
            $ingredientId = $input['ingredient_id'] ?? null;
            if (!$ingredientId) throw new InvalidArgumentException("Brak ingredient_id");

            $stmt = $userPdo->prepare("SELECT is_owned, is_key FROM user_pantry WHERE ingredient_id = ?");
            $stmt->execute([$ingredientId]);
            $curr = $stmt->fetch() ?: ['is_owned' => 0, 'is_key' => 0];

            $newOwned = isset($input['is_owned']) ? (int)(bool)$input['is_owned'] : (int)$curr['is_owned'];
            $newKey = isset($input['is_key']) ? (int)(bool)$input['is_key'] : (int)$curr['is_key'];
            if ($newOwned === 0) $newKey = 0;
            elseif ($newKey === 1) $newOwned = 1;

            $userPdo->prepare("
                INSERT INTO user_pantry (ingredient_id, is_owned, is_key) VALUES (?, ?, ?)
                ON CONFLICT(ingredient_id) DO UPDATE SET is_owned = excluded.is_owned, is_key = excluded.is_key
            ")->execute([$ingredientId, $newOwned, $newKey]);

            echo json_encode([
                'success' => true,
                'ingredient_id' => $ingredientId,
                'is_owned' => $newOwned,
                'is_key' => $newKey,
                'recommendations' => scoreWithInput($scorer, $input),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'clear_pantry':
            $input = getJsonInput();
            $userPdo->exec("UPDATE user_pantry SET is_owned = 0, is_key = 0");

            echo json_encode([
                'success' => true,
                'recommendations' => scoreWithInput($scorer, $input),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_preference':
            $input = getJsonInput();
            $key = $input['key'] ?? null;
            $val = (string)($input['value'] ?? '');
            if (!$key) throw new InvalidArgumentException("Brak klucza preferencji");

            $userPdo->prepare("
                INSERT INTO user_preferences (key, value) VALUES (?, ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value
            ")->execute([$key, $val]);

            if ($key === 'preferred_prep_time') {
                $input['preferred_prep_time'] = (int)$val;
            }

            echo json_encode([
                'success' => true,
                'key' => $key,
                'value' => $val,
                'recommendations' => scoreWithInput($scorer, $input),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'recommendations':
            $input = getJsonInput();
            echo json_encode(scoreWithInput($scorer, $input), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Nieznana akcja: $action"], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $t) {
    if (isset($userPdo) && $userPdo->inTransaction()) {
        $userPdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $t->getMessage(),
        'file' => $t->getFile(),
        'line' => $t->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
