<?php

declare(strict_types=1);

class MealScorer
{
    private PDO $kbPdo;
    private ?PDO $userPdo = null;

    public function __construct(string|PDO|null $kb = null, string|PDO|null $user = null)
    {
        $this->kbPdo = ($kb instanceof PDO) ? $kb : new PDO('sqlite:' . ($kb ?? __DIR__ . '/knowledge_base.db'), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if ($user instanceof PDO) {
            $this->userPdo = $user;
        } else {
            $path = is_string($user) ? $user : (__DIR__ . '/user_state.db');
            if (file_exists($path)) {
                $this->userPdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }
        }
    }

    public function getUserState(): array
    {
        $eq = []; $pantry = []; $key = []; $prepTime = 25;
        if ($this->userPdo) {
            $eq = $this->userPdo->query("SELECT equipment_id FROM user_equipment WHERE is_owned = 1")->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $this->userPdo->query("SELECT ingredient_id, is_owned, is_key FROM user_pantry WHERE is_owned = 1 OR is_key = 1");
            while ($r = $stmt->fetch()) {
                if ($r['is_owned']) $pantry[] = $r['ingredient_id'];
                if ($r['is_key']) $key[] = $r['ingredient_id'];
            }
            $stmt = $this->userPdo->query("SELECT value FROM user_preferences WHERE key = 'preferred_prep_time'");
            if ($row = $stmt->fetch()) $prepTime = (int)$row['value'];
        }
        return [
            'equipment_whitelist' => $eq,
            'ingredient_whitelist' => $pantry,
            'key_ingredients' => $key,
            'preferred_prep_time' => $prepTime,
        ];
    }

    public function scoreAndFilterRecipes(array $options = []): array
    {
        $userState = $this->getUserState();

        $equipmentWhitelistMap = array_flip($options['equipment_whitelist'] ?? $userState['equipment_whitelist']);
        $ingredientWhitelistMap = array_flip($options['ingredient_whitelist'] ?? $userState['ingredient_whitelist']);
        $keyIngredientsMap = array_flip($options['key_ingredients'] ?? $userState['key_ingredients']);
        $preferredPrepTime = isset($options['preferred_prep_time']) ? (int)$options['preferred_prep_time'] : $userState['preferred_prep_time'];
        $filterByPrepTime = $options['filter_by_prep_time'] ?? true;

        // 1. Zamienniki składników
        $subsMap = [];
        $subsStmt = $this->kbPdo->query("SELECT s.ingredient_id, s.substitute_id, i.name as substitute_name FROM ingredient_substitutes s JOIN ingredients i ON s.substitute_id = i.id");
        while ($r = $subsStmt->fetch()) {
            $subsMap[$r['ingredient_id']][] = $r;
        }

        // 2. Wymagany sprzęt
        $eqByRecipe = [];
        $eqStmt = $this->kbPdo->query("
            SELECT re.recipe_id, re.equipment_id, e.name as equipment_name, e.icon as equipment_icon
            FROM recipe_equipment re
            JOIN equipment e ON re.equipment_id = e.id
            ORDER BY e.name
        ");
        while ($r = $eqStmt->fetch()) {
            $eqByRecipe[$r['recipe_id']][] = $r;
        }

        // 2b. Zamienniki sprzętu
        $eqSubsByRecipe = [];
        try {
            $eqSubStmt = $this->kbPdo->query("
                SELECT res.*, se.name as fallback_sub_name, se.icon as sub_eq_icon
                FROM recipe_equipment_substitutes res
                LEFT JOIN equipment se ON res.substitute_equipment_id = se.id
                ORDER BY res.recipe_id, res.equipment_id, res.alt_time_min ASC
            ");
            while ($r = $eqSubStmt->fetch()) {
                $eqSubsByRecipe[$r['recipe_id']][$r['equipment_id']][] = $r;
            }
        } catch (Throwable) {
            $eqSubsByRecipe = [];
        }

        // 3. Składniki
        $ingsByRecipe = [];
        $ingStmt = $this->kbPdo->query("SELECT ri.*, i.name, i.category FROM recipe_ingredients ri JOIN ingredients i ON ri.ingredient_id = i.id ORDER BY ri.is_essential DESC, i.name ASC");
        while ($r = $ingStmt->fetch()) {
            $ingsByRecipe[$r['recipe_id']][] = $r;
        }

        // 4. Instrukcje
        $instructionsByRecipe = [];
        try {
            $instrStmt = $this->kbPdo->query("SELECT recipe_id, step_order, instruction, equipment, ingredient FROM recipe_instructions ORDER BY recipe_id, step_order ASC, id ASC");
            while ($r = $instrStmt->fetch()) {
                $instructionsByRecipe[$r['recipe_id']][] = $r;
            }
        } catch (Throwable) {
            $instructionsByRecipe = [];
        }

        $allRecipes = $this->kbPdo->query("SELECT * FROM recipes ORDER BY name ASC")->fetchAll();
        $scoredRecipes = [];

        foreach ($allRecipes as $recipe) {
            $rid = $recipe['id'];
            $effectivePrepTime = (int)$recipe['prep_time_min'];
            $equipmentDisplayMap = [];
            $equipmentSubstitutions = [];
            $isEquipmentSatisfied = true;

            // Weryfikacja sprzętu
            foreach ($eqByRecipe[$rid] ?? [] as $eqReq) {
                $eqId = $eqReq['equipment_id'];
                if (isset($equipmentWhitelistMap[$eqId])) {
                    $equipmentDisplayMap[$eqId] ??= [
                        'id' => $eqId,
                        'name' => $eqReq['equipment_name'],
                        'original_name' => $eqReq['equipment_name'],
                        'icon' => $eqReq['equipment_icon'],
                        'is_substitute' => false,
                        'alt_time_min' => 0,
                    ];
                    continue;
                }

                // Zamienniki sprzętu
                $candidates = $eqSubsByRecipe[$rid][$eqId] ?? [];
                $chosenSub = null;

                foreach ($candidates as $cand) {
                    $candSubId = $cand['substitute_equipment_id'];
                    $hasEq = $candSubId && isset($equipmentWhitelistMap[$candSubId]);
                    $isManualTool = empty($candSubId) && !empty($cand['substitute_name']);
                    if ($hasEq || $isManualTool) {
                        $chosenSub = $cand;
                        break;
                    }
                }

                if (!$chosenSub) {
                    $isEquipmentSatisfied = false;
                    break;
                }

                $subId = $chosenSub['substitute_equipment_id'];
                $subName = !empty($chosenSub['substitute_name']) ? $chosenSub['substitute_name'] : ($chosenSub['fallback_sub_name'] ?? 'Zamiennik');
                $subIcon = !empty($chosenSub['sub_eq_icon']) ? $chosenSub['sub_eq_icon'] : '🔧';
                $altTime = (int)($chosenSub['alt_time_min'] ?? 0);

                $effectivePrepTime += $altTime;
                $equipmentSubstitutions[] = [
                    'equipment_id' => $eqId,
                    'original_name' => $eqReq['equipment_name'],
                    'substitute_id' => $subId,
                    'substitute_name' => $subName,
                    'alt_time_min' => $altTime,
                    'alt_instructions' => $chosenSub['alt_instructions'],
                ];

                $key = $subId ?: ('tool_' . mb_strtolower(trim($subName)));
                if (isset($equipmentDisplayMap[$key])) {
                    $equipmentDisplayMap[$key]['is_substitute'] = true;
                    $equipmentDisplayMap[$key]['alt_time_min'] = max($equipmentDisplayMap[$key]['alt_time_min'], $altTime);
                } else {
                    $equipmentDisplayMap[$key] = [
                        'id' => $subId ?: $eqId,
                        'name' => $subName,
                        'original_name' => $eqReq['equipment_name'],
                        'icon' => $subIcon,
                        'is_substitute' => true,
                        'alt_time_min' => $altTime,
                    ];
                }
            }

            if (!$isEquipmentSatisfied || ($filterByPrepTime && $effectivePrepTime > $preferredPrepTime)) {
                continue;
            }

            // Ocena składników
            $score = 0.0;
            $keyIngredients = [];
            $ownedIngredients = [];
            $missingIngredients = [];
            $substitutedIngredients = [];
            $isVegetarianVariant = false;

            $prepTimeDifference = abs($effectivePrepTime - $preferredPrepTime);
                if (!$filterByPrepTime) {
                    $score -= $prepTimeDifference;
                }

            foreach ($ingsByRecipe[$rid] ?? [] as $ing) {
                $id = $ing['ingredient_id'];

                // Czy mamy składnik?
                if (isset($ingredientWhitelistMap[$id])) {
                    $isKey = isset($keyIngredientsMap[$id]);
                    $ing['is_key'] = $isKey;
                    $ownedIngredients[] = $ing;
                    if ($isKey) {
                        $keyIngredients[] = $ing;
                        $score += 1.0;
                    }
                    continue;
                }

                // Czy mamy zamiennik składnika?
                $matchedSub = null;
                $matchedSubId = null;
                foreach ($subsMap[$id] ?? [] as $cand) {
                    if (isset($ingredientWhitelistMap[$cand['substitute_id']])) {
                        $matchedSub = $cand['substitute_name'];
                        $matchedSubId = $cand['substitute_id'];
                        break;
                    }
                }

                if ($matchedSub) {
                    $score -= 0.5;
                    $substitutedIngredients[] = [
                        'original_id' => $id,
                        'original_name' => $ing['name'],
                        'substitute_id' => $matchedSubId,
                        'substitute_name' => $matchedSub,
                        'is_vegetarian' => false,
                    ];
                    continue;
                }

                // Czy mamy wariant wegetariański? Czy mięso lub ryby są nieistotne w przepisie?
                if ($ing['category'] === 'Mięso i ryby' && !(int)$ing['is_essential']) {
                    $isVegetarianVariant = true;
                    $score -= 0.5;
                    $substitutedIngredients[] = [
                        'original_id' => $id,
                        'original_name' => $ing['name'],
                        'substitute_name' => 'Wersja wegetariańska (bez ' . $ing['name'] . ')',
                        'is_vegetarian' => true,
                    ];
                    continue;
                }

                // Wszystkie Ify failują, składnik jest brakujący
                $score -= 1.0;
                $missingIngredients[] = $ing;
            }

            // Kroki instrukcji z uwzględnieniem zamienników sprzętu i składników
            $subEqByOriginal = array_column($equipmentSubstitutions, null, 'equipment_id');
            $subIngByOriginal = array_column($substitutedIngredients, null, 'original_id');

            $stepsByOrder = [];
            foreach ($instructionsByRecipe[$rid] ?? [] as $st) {
                $stepsByOrder[(int)$st['step_order']][] = $st;
            }

            $steps = [];
            foreach ($stepsByOrder as $stepOrder => $candidates) {
                $chosen = $candidates[0];
                $isSub = false;
                $isVeg = false;
                $label = null;

                if (count($candidates) > 1) {
                    $origEq = $candidates[0]['equipment'] ?? null;
                    $origIng = $candidates[0]['ingredient'] ?? null;

                    // 1. Sprawdź wersję wegetariańską
                    if ($isVegetarianVariant) {
                        foreach ($candidates as $cand) {
                            if ($cand['ingredient'] === 'vege') {
                                $chosen = $cand;
                                $isSub = true;
                                $isVeg = true;
                                $label = 'Wersja wegetariańska';
                                break;
                            }
                        }
                    }

                    // 2. Sprawdź zamiennik składnika
                    if (!$isVeg && $origIng && isset($subIngByOriginal[$origIng])) {
                        $targetSubId = $subIngByOriginal[$origIng]['substitute_id'] ?? null;
                        foreach ($candidates as $cand) {
                            if ($cand['ingredient'] === $targetSubId) {
                                $chosen = $cand;
                                $isSub = true;
                                $label = 'Zamiast: ' . $subIngByOriginal[$origIng]['original_name'];
                                break;
                            }
                        }
                    }

                    // 3. Sprawdź zamiennik sprzętu
                    if (!$isVeg && !$isSub && $origEq && isset($subEqByOriginal[$origEq])) {
                        $targetSubEq = $subEqByOriginal[$origEq]['substitute_id'] ?? null;
                        foreach ($candidates as $cand) {
                            if ($cand['equipment'] === $targetSubEq) {
                                $chosen = $cand;
                                $isSub = true;
                                $label = 'Zamiast: ' . $subEqByOriginal[$origEq]['original_name'];
                                break;
                            }
                        }
                    }
                }

                $stepData = [
                    'step_order' => $stepOrder,
                    'text' => $chosen['instruction'],
                    'is_substituted' => $isSub,
                    'is_vegetarian' => $isVeg,
                ];
                if ($label) {
                    $stepData['substitute_label'] = $label;
                }
                $steps[] = $stepData;
            }

            if (!$steps) {
                $idx = 1;
                foreach (array_filter(preg_split('/(?=\d+\.\s)/', (string)$recipe['instructions'])) as $rs) {
                    $clean = trim(preg_replace('/^\d+\.\s*/', '', $rs));
                    if ($clean) $steps[] = ['step_order' => $idx++, 'text' => $clean, 'is_substituted' => false];
                }
            }

            $scoredRecipes[] = [
                'id' => $recipe['id'],
                'name' => $recipe['name'],
                'category' => $recipe['category'],
                'prep_time_min' => $effectivePrepTime,
                'base_prep_time_min' => (int)$recipe['prep_time_min'],
                'difficulty' => $recipe['difficulty'],
                'description' => $recipe['description'],
                'instructions' => implode(' ', array_map(fn($s) => $s['step_order'] . '. ' . $s['text'], $steps)),
                'steps' => $steps,
                'instruction_steps' => $steps,
                'score' => $score,
                'key_ingredients' => $keyIngredients,
                'missing_ingredients' => $missingIngredients,
                'substituted_ingredients' => $substitutedIngredients,
                'owned_ingredients' => $ownedIngredients,
                'equipment' => array_values($equipmentDisplayMap),
                'equipment_substitutions' => $equipmentSubstitutions,
                'is_vegetarian_variant' => $isVegetarianVariant,
            ];
        }

        // Sortowanie malejąco wg punktów, alfabetycznie
        usort($scoredRecipes, fn($a, $b) => 
            $b['score'] <=> $a['score']
            ?: strcmp($a['name'], $b['name'])
        );

        return [
            'success' => true,
            'recipes' => $scoredRecipes,
        ];
    }
}
