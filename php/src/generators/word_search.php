<?php
declare(strict_types=1);

require_once __DIR__ . '/../theme.php';

function bb_clean_word(string $value = ''): string {
    $v = strtoupper($value);
    $v = preg_replace('/[^A-Z]/', '', $v);
    return substr($v, 0, 10);
}

function bb_word_bank(string $theme = ''): array {
    $t = strtolower($theme);
    if (preg_match('/farm/', $t)) return ["COW","SHEEP","PIG","HORSE","GOAT","DUCK","CHICKEN","ROOSTER","BARN","TRACTOR","HAY","CALF","LAMB","PONY","FENCE","EGGS","FARMER","STABLE","PASTURE","GARDEN"];
    if (preg_match('/ocean|coral|sea/', $t)) return ["DOLPHIN","TURTLE","WHALE","SHARK","OCTOPUS","CRAB","CORAL","REEF","SHELL","SEAL","FISH","WAVE","KELP","SQUID","LOBSTER","SEAHORSE"];
    if (preg_match('/safari/', $t)) return ["LION","ZEBRA","GIRAFFE","ELEPHANT","RHINO","HIPPO","CHEETAH","GAZELLE","MONKEY","SAVANNA","ACACIA","LEOPARD"];
    if (preg_match('/space|astronaut|solar/', $t)) return ["ROCKET","PLANET","MOON","STAR","COMET","ORBIT","ASTRO","MARS","VENUS","SATURN","GALAXY","METEOR"];
    if (preg_match('/dinosaur/', $t)) return ["DINOSAUR","TREX","RAPTOR","FOSSIL","EGG","JURASSIC","STEGOSAUR","TRICERA","VOLCANO","BONES","TAIL","CLAW"];
    $pieces = bb_theme_elements($theme);
    $raw = array_merge($pieces['subjects'], $pieces['settings'], $pieces['props'], [$theme]);
    $cleaned = array_map('bb_clean_word', $raw);
    return array_values(array_filter($cleaned, fn($w) => strlen($w) >= 3));
}

function bb_build_word_search_puzzle(string $theme, int $pageNumber, array $input = []): array {
    $size = 12;
    $pool = array_values(array_unique(bb_word_bank($theme)));
    $pool = array_values(array_filter($pool, fn($w) => strlen($w) >= 3 && strlen($w) <= 10));
    $poolCount = count($pool);
    $orderedPool = [];
    for ($i = 0; $i < $poolCount; $i++) $orderedPool[] = $pool[($pageNumber + $i - 1) % $poolCount];
    usort($orderedPool, fn($a, $b) => strlen($b) - strlen($a));

    $grid = array_fill(0, $size, array_fill(0, $size, ''));
    $placements = [];

    $mode = in_array($input['wordSearchMode'] ?? null, WORD_SEARCH_MODE_TYPES, true)
        ? $input['wordSearchMode'] : 'Standard Word Search';

    $directions = [
        'H' => ['code' => 'H', 'dr' => 0, 'dc' => 1],
        'V' => ['code' => 'V', 'dr' => 1, 'dc' => 0],
        'D' => ['code' => 'D', 'dr' => 1, 'dc' => 1],
    ];

    $slotPlans = [
        'Easy Horizontal Only' => [
            ['code'=>'H','row'=>0,'col'=>0],['code'=>'H','row'=>1,'col'=>1],['code'=>'H','row'=>2,'col'=>0],['code'=>'H','row'=>3,'col'=>1],['code'=>'H','row'=>4,'col'=>0],
            ['code'=>'H','row'=>6,'col'=>0],['code'=>'H','row'=>7,'col'=>1],['code'=>'H','row'=>8,'col'=>0],['code'=>'H','row'=>10,'col'=>0],['code'=>'H','row'=>11,'col'=>1],
        ],
        'Challenge Diagonal Mix' => [
            ['code'=>'D','row'=>0,'col'=>0],['code'=>'D','row'=>0,'col'=>3],['code'=>'D','row'=>1,'col'=>0],['code'=>'D','row'=>2,'col'=>2],
            ['code'=>'V','row'=>0,'col'=>11],['code'=>'V','row'=>3,'col'=>10],['code'=>'V','row'=>5,'col'=>8],
            ['code'=>'H','row'=>10,'col'=>0],['code'=>'H','row'=>11,'col'=>1],['code'=>'H','row'=>8,'col'=>0],
        ],
        'Advanced Longer Words' => [
            ['code'=>'D','row'=>0,'col'=>0],['code'=>'D','row'=>0,'col'=>3],['code'=>'D','row'=>1,'col'=>0],['code'=>'D','row'=>2,'col'=>2],
            ['code'=>'V','row'=>0,'col'=>11],['code'=>'V','row'=>2,'col'=>10],['code'=>'V','row'=>4,'col'=>8],
            ['code'=>'H','row'=>9,'col'=>0],['code'=>'H','row'=>10,'col'=>0],['code'=>'H','row'=>11,'col'=>1],
        ],
        'Standard Word Search' => [
            ['code'=>'D','row'=>0,'col'=>0],['code'=>'D','row'=>0,'col'=>4],['code'=>'D','row'=>2,'col'=>0],
            ['code'=>'V','row'=>0,'col'=>11],['code'=>'V','row'=>3,'col'=>10],['code'=>'V','row'=>5,'col'=>8],
            ['code'=>'H','row'=>10,'col'=>0],['code'=>'H','row'=>11,'col'=>1],['code'=>'H','row'=>8,'col'=>0],['code'=>'H','row'=>6,'col'=>0],
        ],
    ];
    $slotPlan = $slotPlans[$mode] ?? $slotPlans['Standard Word Search'];
    $slotPlanCount = count($slotPlan);

    $canPlace = function (string $word, int $row, int $col, array $dir) use (&$grid, $size): bool {
        $letters = str_split($word);
        foreach ($letters as $index => $letter) {
            $r = $row + $dir['dr'] * $index;
            $c = $col + $dir['dc'] * $index;
            if ($r >= $size || $c >= $size) return false;
            if ($grid[$r][$c] !== '' && $grid[$r][$c] !== $letter) return false;
        }
        return true;
    };

    $placeWord = function (string $word, int $index, array $slot) use (&$grid, &$placements, $canPlace, $directions, $size, $pageNumber): bool {
        $preferred = $directions[$slot['code']] ?? $directions['H'];
        $attempts = [['dir' => $preferred, 'row' => $slot['row'], 'col' => $slot['col']]];
        for ($attempt = 0; $attempt < 144; $attempt++) {
            $attempts[] = ['dir' => $preferred, 'attempt' => $attempt];
        }
        foreach ($attempts as $option) {
            $dir = $option['dir'];
            if (array_key_exists('row', $option) && $option['row'] !== null && array_key_exists('col', $option) && $option['col'] !== null) {
                if (!$canPlace($word, $option['row'], $option['col'], $dir)) continue;
                foreach (str_split($word) as $i => $letter) {
                    $grid[$option['row'] + $dir['dr'] * $i][$option['col'] + $dir['dc'] * $i] = $letter;
                }
                $placements[] = ['word' => $word, 'answer' => "{$word}: row " . ($option['row'] + 1) . ", col " . ($option['col'] + 1) . ", direction {$dir['code']}"];
                return true;
            }
            $attempt = $option['attempt'];
            $wordLen = strlen($word);
            $maxRow = $size - ($dir['dr'] ? $wordLen : 1);
            $maxCol = $size - ($dir['dc'] ? $wordLen : 1);
            $row = ($index * 3 + $attempt * 2 + $pageNumber) % max(1, $maxRow + 1);
            $col = ($index * 5 + $attempt + $pageNumber) % max(1, $maxCol + 1);
            if (!$canPlace($word, $row, $col, $dir)) continue;
            foreach (str_split($word) as $i => $letter) {
                $grid[$row + $dir['dr'] * $i][$col + $dir['dc'] * $i] = $letter;
            }
            $placements[] = ['word' => $word, 'answer' => "{$word}: row " . ($row + 1) . ", col " . ($col + 1) . ", direction {$dir['code']}"];
            return true;
        }
        return false;
    };

    for ($slot = 0; $slot < 10; $slot++) {
        $candidates = $orderedPool;
        if ($slotPlan[$slot]['code'] === 'D') {
            $candidates = $orderedPool;
            usort($candidates, fn($a, $b) => strlen($a) - strlen($b));
        }
        foreach ($candidates as $word) {
            $alreadyPlaced = false;
            foreach ($placements as $item) if ($item['word'] === $word) { $alreadyPlaced = true; break; }
            if ($alreadyPlaced) continue;
            if ($placeWord($word, $slot, $slotPlan[$slot])) break;
        }
    }

    $fillerIndex = 1;
    while (count($placements) < 10) {
        $word = 'WORD' . $fillerIndex++;
        $placeWord($word, count($placements), $slotPlan[count($placements) % $slotPlanCount]);
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($grid[$r][$c] === '') $grid[$r][$c] = $alphabet[($r * 7 + $c * 11 + $pageNumber) % strlen($alphabet)];
        }
    }

    return [
        'mode' => $mode,
        'words' => array_map(fn($p) => $p['word'], $placements),
        'rows' => array_map(fn($row) => implode(' ', $row), $grid),
        'answers' => array_map(fn($p) => $p['answer'], $placements),
    ];
}
