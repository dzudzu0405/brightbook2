<?php
declare(strict_types=1);

require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/generators/word_search.php';

const PRODUCT_RULES = [
    'coloring' => "COLORING BOOK CONTRACT\n- One clear focal scene per page with 1-4 large subjects.\n- Use bold black outlines, large closed shapes, generous white space, and low detail appropriate to the age group.\n- The image prompt must explicitly request black-and-white line art only and prohibit color, gray fill, shading, text, borders, and cropped subjects.",
    'word-search' => "WORD SEARCH CONTRACT\n- Each page is a real printable word-search puzzle, not an illustration prompt pretending to be a puzzle.\n- Each page needs one unique theme subtopic and exactly 10 age-appropriate uppercase words, 3-10 letters each, no spaces, no punctuation.\n- content_items must include \"WORD LIST: ...\" and exactly 12 \"GRID ROW NN: ...\" entries. Every grid row must be 12 uppercase letters with spaces between letters.\n- The answer must list every hidden word with row, column, and direction using H, V, or D. Example: COW: row 2, col 4, direction H.\n- Every puzzle must include a mix of directions: at least 3 horizontal, at least 3 vertical, and at least 2 diagonal words.\n- The image_prompt must NOT ask an image model to draw the word grid, letters, words, typography, or answer key. It should only describe a printable worksheet frame: small themed border decorations, title-safe area, and one large blank central rectangle where the generated grid will be placed later by layout software.",
    'educational-story' => "EDUCATIONAL STORY CONTRACT\n- Build one connected story arc across all prompts: introduction, small challenge, attempts, resolution, and takeaway.\n- Keep the same recurring character design, clothing, colors, and personality on every page.\n- Each page is one concrete scene, advances the story, and teaches one gentle age-appropriate lesson.\n- The image prompt must restate the complete character lock whenever the recurring character appears.",
    'tracing' => "TRACING & HANDWRITING CONTRACT\n- State the exact strokes, letters, numbers, or words to trace.\n- Progress gradually from guided examples to independent practice.\n- Request thick dotted tracing guides, clear baselines, large spacing, and minimal decoration.",
    'matching' => "MATCHING CONTRACT\n- Include 4-8 exact pairs, with left and right columns deliberately shuffled.\n- content_items must define every pair and the displayed order. The answer must repeat all correct matches.\n- Keep the center area open for children to draw connecting lines.",
    'counting' => "COUNTING CONTRACT\n- State exact object quantities in content_items and keep every object fully visible and easy to distinguish.\n- Use age-appropriate number ranges and vary the scene without creating ambiguous overlaps.\n- The answer must give the exact count.",
    'simple-math' => "MATH PRACTICE CONTRACT\n- Include exact operands, operation symbols, and one unambiguous answer for every problem.\n- Match number size and operation difficulty to the age group.\n- Use visual manipulatives only when their quantities are explicitly defined.",
    'spot-difference' => "SPOT THE DIFFERENCE CONTRACT\n- Define two nearly identical scenes and exactly 5-8 concrete visual differences.\n- content_items must list every difference precisely; the answer must repeat the complete list.\n- Keep composition, character placement, and camera angle identical between the two panels.",
    'puzzle' => "CHILDREN'S PUZZLE CONTRACT\n- Name the exact puzzle mechanic and fully specify all clues, choices, and solution.\n- Use only one puzzle mechanic per prompt.\n- Avoid puzzles that depend on details not defined in content_items.",
    'learning-worksheet' => "EDUCATIONAL WORKSHEET CONTRACT\n- Each page focuses on one measurable learning objective and one clear task.\n- Include all questions, choices, examples, and answers explicitly.\n- Use a clean classroom worksheet layout with strong visual hierarchy and ample writing space.",
];

function bb_product_rules(string $type): string {
    return PRODUCT_RULES[$type] ?? PRODUCT_RULES['learning-worksheet'];
}

function bb_visual_contract(array &$input): array {
    $input['size'] = 'A4';
    $avoid = trim((string)($input['avoidTerms'] ?? ''));
    $characterLock = !empty($input['guideCharacter'])
        ? "Recurring character lock: {$input['guideCharacter']}; keep the same species/person, age, face, body proportions, clothing, colors, accessories, and personality across every prompt"
        : "Character consistency lock: whenever a character recurs, repeat the same species/person, age, face, body proportions, clothing, colors, and accessories";
    $userAvoid = $avoid !== '' ? ", avoid these user-specified elements: {$avoid}" : '';
    $negativeLock = ($input['activityType'] ?? '') === 'coloring'
        ? "black-and-white line art only, no color, no grayscale, no shading, no gradients, no shadows, no textures, no title, no words, no letters, no numbers, no labels, no captions, no signage, no watermark, no logo, no border, no photorealism, no 3D render{$userAvoid}"
        : "no watermark, no logo, no brand characters, no photorealism, no 3D render, no malformed anatomy, no clutter, no cropped important objects, no illegible embedded text{$userAvoid}";
    return [
        'styleAnchor' => "{$input['style']}, consistent child-friendly visual language",
        'themeDirection' => bb_theme_visual_direction($input['theme'] ?? ($input['topic'] ?? '')),
        'characterLock' => $characterLock,
        'layoutLock' => "one standalone A4 portrait printable page, clear focal hierarchy, clean margins, safe trim area, no cropped important objects",
        'negativeLock' => $negativeLock,
    ];
}

const WORKSHEET_FRAME_LABELS = [
    'matching' => 'matching worksheet frame with two large blank columns reserved for text and picture cards, keeping the center area open for connecting lines',
    'simple-math' => 'math worksheet frame with small themed counters in the margins and one large blank problem area plus answer box',
    'puzzle' => "children's puzzle worksheet frame with four blank choice cards and one answer circle area",
    'learning-worksheet' => 'educational worksheet frame with three clear blank task sections, one drawing box, and generous writing space',
    'tracing' => 'handwriting worksheet frame with three wide blank tracing rows plus independent writing lines',
];

function bb_lock_image_prompt(string $prompt, array &$input): string {
    $c = bb_visual_contract($input);
    $scene = preg_replace('/[.\s]+$/', '', trim($prompt));
    $activityType = $input['activityType'] ?? '';
    $themeOrTopic = $input['theme'] ?: ($input['topic'] ?? '');

    if ($activityType === 'word-search') {
        return "Create a clean printable word-search worksheet frame for children, vertical A4 portrait composition.\n\n"
            . "Scene decoration: {$scene}. Use only small {$themeOrTopic} themed border illustrations in the corners and margins, with a large blank central rectangle reserved for a word-search grid that will be added later by layout software.\n\n"
            . "Layout requirements: clear title-safe area at the top, word-list area below or beside the blank grid space, generous margins, simple child-friendly decorative icons, balanced worksheet composition, no busy background behind the puzzle area.\n\n"
            . "Critical text rule: do not render any letters, words, puzzle grid, answer key, labels, captions, signage, typography, watermark, logo, or random symbols anywhere in the image.\n\n"
            . "Negative prompt: letters, words, text, typography, alphabet, numbers, grid letters, word search grid, answer key, labels, captions, signs, watermark, logo, clutter, cropped layout, photorealism, 3D render.";
    }

    // These activity types are laid out by external tooling after generation (blank
    // columns/rows/cards for the real content), so the image itself must stay a mostly
    // empty decorative frame - matches the hand-written fallback templates in
    // bb_fallback_page(), which the AI-generation path must stay consistent with.
    if (isset(WORKSHEET_FRAME_LABELS[$activityType])) {
        $frameLabel = WORKSHEET_FRAME_LABELS[$activityType];
        return "Create a clean printable {$frameLabel} for children, vertical A4 portrait composition.\n\n"
            . "Scene decoration: {$scene}. Use only small {$themeOrTopic} themed decorative icons around the margins; keep the working area open and mostly blank for content that will be added later by layout software.\n\n"
            . "Layout requirements: generous white space, simple child-friendly decorative elements, balanced worksheet composition, clean margins, no busy background behind the working area.\n\n"
            . "Critical text rule: do not render any words, letters, numbers, labels, captions, answer keys, signage, typography, watermark, logo, or random symbols anywhere in the image.\n\n"
            . "Negative prompt: letters, words, text, typography, numbers, labels, captions, answer key, watermark, logo, clutter, cropped layout, photorealism, 3D render.";
    }

    if ($activityType === 'counting') {
        return "Create a clean printable counting worksheet scene for children, vertical A4 portrait composition.\n\n"
            . "Scene: {$scene}. Show every counted object fully visible, clearly separated, and easy to distinguish, with generous spacing and one blank answer box reserved for layout software.\n\n"
            . "Style: {$c['styleAnchor']}; {$c['layoutLock']}; {$c['themeDirection']}.\n\n"
            . "Critical text rule: do not render numerals, written labels, captions, answer keys, watermark, logo, or random text anywhere in the image.\n\n"
            . "Negative prompt: numerals, numbers, letters, words, text, labels, captions, answer key, watermark, logo, cropped subjects, overlapping objects, photorealism, 3D render.";
    }

    if ($activityType === 'spot-difference') {
        return "Create a printable spot-the-difference worksheet layout for children, vertical A4 portrait composition.\n\n"
            . "Scene: two side-by-side panels of {$scene}, with identical camera angle, matching character placement, and clear simple details so only the intended differences stand out.\n\n"
            . "Style: {$c['styleAnchor']}; {$c['themeDirection']}.\n\n"
            . "Critical text rule: do not render labels, captions, letters, numbers, watermark, logo, or random text anywhere in the image.\n\n"
            . "Negative prompt: labels, captions, letters, numbers, text, watermark, logo, mismatched camera angles, cropped subjects, photorealism, 3D render.";
    }

    if ($activityType === 'coloring') {
        $scene = preg_replace('/\bvibrant\b/i', 'lively', $scene);
        $scene = preg_replace('/\bcolorful\b/i', 'varied', $scene);
        $scene = preg_replace('/\bfull[- ]color\b/i', 'black-and-white', $scene);
        $scene = preg_replace('/\bbrightly colored\b/i', 'clearly differentiated', $scene);
    }
    $opening = ($input['activityType'] ?? '') === 'coloring'
        ? "Create a detailed black-and-white coloring book page for children, vertical A4 portrait composition."
        : "Create a detailed " . ($input['genreType'] ?: "children's educational") . " image prompt for children, vertical A4 portrait composition.";
    return "{$opening}\n\nScene: {$scene}.\n\nComposition and details: include clear foreground, middle ground, and background; expressive child-friendly characters or objects; readable silhouettes; balanced full-page layout; rich theme-specific props and decorative details; {$c['themeDirection']}.\n\nStyle: {$c['styleAnchor']}; {$c['layoutLock']}; {$c['characterLock']}.\n\nNegative prompt: {$c['negativeLock']}.";
}

function bb_lock_cover_prompt(string $prompt, array &$input): string {
    $c = bb_visual_contract($input);
    $scene = trim($prompt);
    $scene = preg_replace('/[.\s]+$/', '', $scene);
    $scene = preg_replace('/\bblack-and-white\b/i', 'full-color', $scene);
    $scene = preg_replace('/\bblack and white\b/i', 'full-color', $scene);
    $scene = preg_replace('/\bline art only\b/i', 'polished full-color illustration', $scene);
    $scene = preg_replace('/\bno color\b/i', 'rich color', $scene);
    $scene = preg_replace('/\bno shading\b/i', 'soft professional shading', $scene);
    $scene = preg_replace('/\bno grayscale\b/i', 'full-color palette', $scene);
    $palette = ($input['activityType'] ?? '') === 'coloring'
        ? "bright cheerful children's book cover palette, warm inviting colors, colorful title-safe background"
        : "rich professional color palette matched to the selected genre";
    return "Create a premium full-color children's book cover, vertical 2:3 composition.\n\nScene: {$scene}.\n\nCover design: clear central focal character or object, strong readable silhouette, polished publishing layout, title-safe space in the upper-middle, subtitle-safe space below the title, author-name safe space at the bottom, balanced foreground and background, ornate but readable framing, rich theme-specific props and decorative details, {$c['themeDirection']}.\n\nColor and mood: {$palette}, cinematic lighting where appropriate, soft depth, magical but child-friendly atmosphere, professional illustrated book cover finish.\n\nStyle: {$input['style']}, consistent child-friendly visual language, premium cover art, high-resolution, no cropped important objects.\n\nNegative prompt: no watermark, no logo, no brand characters, no photorealism, no 3D render, no malformed anatomy, no cluttered typography, no illegible random text.";
}

function bb_title_case(string $text = ''): string {
    $t = strtolower($text);
    $t = preg_replace_callback('/\b\w/', fn($m) => strtoupper($m[0]), $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

function bb_listing_niche(array $input): string {
    $idea = preg_replace('/^(a|an|the)\s+/i', '', trim((string)($input['bookIdea'] ?? '')));
    $idea = trim(preg_replace('/\s+/', ' ', $idea));
    $theme = ($input['theme'] ?? '') === 'Custom Idea'
        ? trim((string)($input['topic'] ?? 'Activity'))
        : trim((string)(($input['theme'] ?? '') ?: ($input['topic'] ?? 'Activity')));
    return bb_title_case($idea ?: ($theme ?: 'Activity'));
}

function bb_activity_book_label(string $type): string {
    $map = [
        'coloring' => 'Coloring Book',
        'word-search' => 'Word Search Book',
        'tracing' => 'Tracing Practice Book',
        'matching' => 'Matching Activity Book',
        'counting' => 'Number Practice Book',
        'learning-worksheet' => 'Activity Worksheet Pack',
    ];
    return $map[$type] ?? 'Activity Book';
}

function bb_age_label(string $age = ''): string {
    if (preg_match('/\d+\s*[–-]\s*\d+/', $age, $m)) {
        return 'Ages ' . preg_replace('/\s+/', '', $m[0]);
    }
    $label = preg_replace('/\byears?\b/i', '', $age ?: 'Kids');
    return trim($label);
}

function bb_etsy_title_for(array $input, string $niche, string $theme, string $activityLabel, array $keywords): string {
    $mode = trim(preg_replace('/Classic Educational/i', '', (string)($input['displayGenre'] ?: ($input['genreType'] ?? ''))));
    $style = ($input['activityType'] ?? '') === 'coloring'
        ? 'Bold and Easy'
        : (($input['activityType'] ?? '') === 'tracing' ? 'Handwriting Practice' : 'Printable Kids Workbook');
    $base = "{$niche} {$activityLabel}";
    $baseLower = strtolower($base);
    $keywordItems = [];
    foreach ($keywords as $item) {
        $tc = bb_title_case((string)$item);
        if ($tc !== '' && !str_contains($baseLower, strtolower($tc))) $keywordItems[] = $tc;
        if (count($keywordItems) >= 3) break;
    }
    $keywordText = implode(', ', $keywordItems);
    $parts = array_filter(
        ["{$base} PDF", $keywordText ?: "{$theme} Activity Pages", $mode ?: $style, 'Printable Kids Pages', 'Digital Download'],
        fn($v) => $v !== '' && $v !== null
    );
    $result = implode(', ', $parts);
    $result = preg_replace('/\s+/', ' ', $result);
    return substr($result, 0, 140);
}

function bb_ensure_publishing_kit(array $book, array $input): array {
    $theme = (string)(($input['theme'] ?? '') ?: (($input['topic'] ?? '') ?: 'Activity Book'));
    $activity = str_replace('-', ' ', (string)($input['activityType'] ?? 'activity'));
    $niche = bb_listing_niche($input);
    $activityLabel = bb_activity_book_label($input['activityType'] ?? '');
    $age = bb_age_label($input['age'] ?? '');
    $title = substr(preg_replace('/\s+Kit$/i', '', (string)($book['book_title'] ?? "{$niche} {$activityLabel}")), 0, 70);
    $subtitle = substr(preg_replace('/\s+kit\b/i', '', (string)($book['subtitle'] ?? "{$activityLabel} pages for {$input['age']}")), 0, 120);
    $kdpTitle = substr(preg_replace('/\s+/', ' ', "{$niche} {$activityLabel} for Kids {$age}"), 0, 180);
    $kdpSubtitle = substr("Fun {$theme} activity pages for {$input['age']} with clear prompts, answer guidance, and publishing-ready planning", 0, 200);
    $keywords = (!empty($book['keywords']) && is_array($book['keywords']))
        ? array_slice($book['keywords'], 0, 8)
        : array_slice([$theme, "{$theme} activity book", "{$activity} book", "{$input['age']} activities"], 0, 8);
    $etsyTitle = bb_etsy_title_for($input, $niche, $theme, $activityLabel, $keywords);

    if (preg_match('/\bkit$/i', (string)($book['book_title'] ?? ''))) $book['book_title'] = $title;

    $backendKeywords = [];
    for ($i = 0; $i < 7; $i++) $backendKeywords[] = $keywords[$i] ?? "{$theme} printable activity " . ($i + 1);

    $listingDefaults = [
        'kdp_title' => $kdpTitle,
        'kdp_subtitle' => $kdpSubtitle,
        'kdp_description' => "{$kdpTitle} is a themed " . strtolower($activityLabel) . " for {$input['age']}. It includes structured page ideas, clear instructions, answer guidance where needed, cover direction, keywords, and launch planning notes to help sellers prepare a polished activity book for KDP, Etsy, Gumroad, or classroom marketplaces. Review the pages, create the final artwork, verify print settings, and customize the listing before publishing.",
        'backend_keywords' => $backendKeywords,
        'etsy_title' => $etsyTitle,
        'etsy_tags' => array_slice([$theme, 'activity book', 'printable kids', 'kids worksheet', 'kdp interior', 'etsy printable', $activity, 'homeschool', 'classroom', 'coloring pages', 'busy book', 'learning fun', 'digital download'], 0, 13),
        'short_blurb' => "A {$theme} {$activity} kit with page prompts, answer keys, cover direction, and launch-ready marketplace assets.",
        'a_plus_sections' => [
            "Show the {$theme} theme and age range at a glance.",
            "Highlight sample interior pages and the learning benefits.",
            "Explain what buyers receive and how the printable can be used.",
            "Show bundle or series options for repeat buyers.",
        ],
    ];

    $book['listing_assets'] = array_merge($listingDefaults, $book['listing_assets'] ?? [], ['kdp_title' => $kdpTitle, 'kdp_subtitle' => $kdpSubtitle]);
    if (preg_match('/\bkit\b/i', (string)($book['listing_assets']['kdp_description'] ?? ''))) $book['listing_assets']['kdp_description'] = $listingDefaults['kdp_description'];
    if (preg_match('/\bkit\b/i', (string)($book['listing_assets']['etsy_title'] ?? ''))) $book['listing_assets']['etsy_title'] = $listingDefaults['etsy_title'];

    if (empty($book['quality_check'])) {
        $warnings = [];
        if (empty($book['cover_prompt'])) $warnings[] = 'Add or review the cover prompt before publishing.';
        if (!isset($book['pages']) || !is_array($book['pages']) || count($book['pages']) !== $input['pageCount']) $warnings[] = 'Page count does not match the selected generation size.';
        if (($input['activityType'] ?? '') === 'coloring' && !empty($book['pages'])) {
            foreach ($book['pages'] as $p) {
                $head = preg_split('/Critical text rule:|Negative prompt:/i', (string)($p['image_prompt'] ?? ''))[0];
                if (preg_match('/\bfull[- ]color\b|\btitle-safe\b|\btypography\b/i', $head)) {
                    $warnings[] = 'Some coloring page prompts may mention color or typography; review before image generation.';
                    break;
                }
            }
        }
        $book['quality_check'] = [
            'score' => max(70, 100 - (count($warnings) * 8)),
            'passed_checks' => ['Product title and subtitle are present.', 'Page instructions are structured.', 'Answer guidance is included where relevant.', 'Cover direction is included.', 'Marketplace keywords are available.'],
            'warnings' => $warnings,
            'fix_suggestions' => ['Review every page before creating final artwork.', 'Customize the listing copy to match your marketplace and brand.', 'Check KDP/Etsy trim size, margins, and commercial-use requirements before upload.'],
        ];
    }

    if (empty($book['series_ideas']) || !is_array($book['series_ideas'])) {
        $book['series_ideas'] = [
            "{$theme} Beginner Edition for younger learners",
            "{$theme} Advanced Edition with harder {$activity} tasks",
            "{$theme} Holiday Special Edition",
            "{$theme} Large Print Edition",
            "{$theme} Classroom Worksheet Bundle",
            "{$theme} Activity Book Series Volume 2",
        ];
    }

    if (empty($book['publishing_checklist']) || !is_array($book['publishing_checklist'])) {
        $book['publishing_checklist'] = [
            'Review every generated page for accuracy and age fit.',
            'Create final artwork from each image prompt.',
            'Check page size, margins, bleed, and gutter before export.',
            'Create or refine the cover with title-safe space.',
            'Verify answer keys and remove ambiguous tasks.',
            'Customize KDP title, subtitle, description, and backend keywords.',
            'Create Etsy tags, preview images, and mockups if selling digitally.',
            'Export final interior as a print-ready PDF only after visual QA.',
            'Publish one product first, then expand into the suggested series.',
        ];
    }

    return $book;
}

function bb_remove_page_count_warnings(array $book): array {
    if (empty($book['quality_check']['warnings'])) return $book;
    $book['quality_check']['warnings'] = array_values(array_filter(
        $book['quality_check']['warnings'],
        fn($w) => !preg_match('/page count does not match/i', (string)$w)
    ));
    return $book;
}

function bb_build_prompt(array $input, int $startPage, int $batchCount, array $previousTitles = [], array $previousPages = []): string {
    $pagePlanLines = [];
    for ($index = 0; $index < $batchCount; $index++) {
        $pagePlanLines[] = "- Prompt " . ($startPage + $index) . ": activity_type must be exactly \"{$input['activityType']}\"";
    }
    $pagePlan = implode("\n", $pagePlanLines);

    $prevPagesText = 'this is the first batch';
    if (count($previousPages) > 0) {
        $tail = array_slice($previousPages, -3);
        $prevPagesText = implode(' | ', array_map(fn($p) => "{$p['page_number']}. {$p['title']}: {$p['instruction']}", $tail));
    }

    $themeOrTopic = $input['theme'] ?: ($input['topic'] ?? '');

    return "You are an expert educational activity book designer for children.\n"
        . "Create exactly {$batchCount} unique printable activity concepts for prompts {$startPage} through " . ($startPage + $batchCount - 1) . ".\n\n"
        . "USER SETTINGS\n"
        . "- Main topic: {$input['topic']}\n"
        . "- Selected theme: {$themeOrTopic}\n"
        . '- User book idea / niche: ' . ($input['bookIdea'] ?: 'not provided; infer a strong marketplace-friendly angle from the selected theme') . "\n"
        . '- Special direction: ' . ($input['customDirection'] ?: 'not provided') . "\n"
        . '- Exclude / avoid: ' . ($input['avoidTerms'] ?: 'not provided') . "\n"
        . "- Age group: {$input['age']}\n"
        . "- Content language: {$input['language']}\n"
        . "- Product/activity type: {$input['activityType']}\n"
        . '- Type / genre direction: ' . ($input['displayGenre'] ?: ($input['genreType'] ?: ($input['difficulty'] ?? 'Classic Educational'))) . "\n"
        . '- Word search mode: ' . ($input['activityType'] === 'word-search' ? $input['wordSearchMode'] : 'not applicable') . "\n"
        . "- Page size: A4 portrait\n"
        . "- Illustration style: {$input['style']}\n"
        . '- Learning goal: ' . ($input['learningGoal'] ?: 'age-appropriate cognitive skills, vocabulary, observation, and problem solving') . "\n"
        . '- Guide character: ' . ($input['guideCharacter'] ?: 'none required') . "\n"
        . '- Titles already used in earlier batches: ' . (count($previousTitles) ? implode(' | ', $previousTitles) : 'none') . "\n"
        . "- Previous story/page continuity: {$prevPagesText}\n"
        . '- Theme visual direction: ' . bb_theme_visual_direction($themeOrTopic) . "\n\n"
        . "REQUIRED PAGE PLAN\n{$pagePlan}\n\n"
        . "REQUIRED JSON OUTPUT FORMAT\n"
        . "Return a single JSON object, no markdown fences, no commentary before or after it, with exactly this shape:\n"
        . "{\n"
        . "  \"book_title\": string,\n"
        . "  \"subtitle\": string,\n"
        . "  \"description\": string,\n"
        . "  \"cover_prompt\": string,\n"
        . "  \"keywords\": array of 5 to 8 strings,\n"
        . "  \"pages\": array of exactly {$batchCount} objects, each shaped as:\n"
        . "    {\n"
        . "      \"page_number\": integer,\n"
        . "      \"activity_type\": string,\n"
        . "      \"title\": string,\n"
        . "      \"instruction\": string,\n"
        . "      \"learning_goal\": string,\n"
        . "      \"content_items\": array of 1 to 24 strings,\n"
        . "      \"image_prompt\": string,\n"
        . "      \"answer\": string\n"
        . "    }\n"
        . "}\n\n"
        . "PRODUCT-SPECIFIC RULES\n" . bb_product_rules($input['activityType']) . "\n\n"
        . "MASTER VISUAL PROMPT CONTRACT\n"
        . "- Write image_prompt like a professional AI image prompt, similar to a Midjourney / Ideogram / ChatGPT image prompt.\n"
        . (in_array($input['activityType'], ['word-search', 'matching', 'simple-math', 'puzzle', 'learning-worksheet', 'tracing'], true)
            ? "- This activity type is laid out by separate software after generation: the image itself must stay a mostly EMPTY decorative frame, not an illustrated scene.\n"
              . "- The image_prompt scene body must be 20-40 words describing ONLY small margin/border decorations (simple theme-related icons or motifs). Do NOT describe any character, person, animal performing an action, facial expression, clothing/costume, or props being used - those belong in content_items/answer text, never in the image.\n"
              . "- Explicitly state in the scene body that the central/working area of the page must stay blank/empty for content added later by layout software.\n\n"
            : "- Each image_prompt scene body must be 90-170 words before the app adds final style and negative prompt sections.\n"
              . "- Use this structure inside the scene body: main scene, exact subjects, character actions, facial expressions, clothing/costumes, props, background, decorative elements, composition, and printable layout.\n"
              . "- For coloring pages, image_prompt must specify black-and-white line art subjects and many fun decorative elements, but avoid color words.\n")
        . "- For covers, cover_prompt must be full-color even when the product is a coloring book. It must be premium and book-cover-like: vertical 2:3 cover composition, rich color palette, title-safe space, ornate framing, clear central character or object, professional publishing design.\n"
        . "- If cover_prompt includes typography, describe the text layout area clearly, but do not invent unreadable random text.\n\n"
        . "RULES\n"
        . "1. All visible titles, instructions, content items, answers, description, and keywords must be in {$input['language']}.\n"
        . "2. Write natural, fluent, grammatically correct {$input['language']}. Never truncate a title or sentence. Keep the book title under 55 characters.\n"
        . "3. Every image_prompt and cover_prompt must be written in English for an image generation model.\n"
        . "4. Follow the REQUIRED PAGE PLAN exactly. This is a single-format product: every page must use the selected activity type. Do not combine, rename, replace, or invent activity types.\n"
        . "5. Set page_number to the exact prompt number shown in the REQUIRED PAGE PLAN.\n"
        . "6. Activities must be safe, factual, age-appropriate, internally consistent, and realistically printable on A4 portrait pages.\n"
        . "7. Give concrete content_items that fully define the page. Do not rely on information that is not included in content_items or image_prompt.\n"
        . "8. Answers must be exact and unambiguous. Never write \"depends on the image\", \"depending on the task\", or similar uncertainty.\n"
        . "9. For counting and math, state exact quantities in content_items and give the exact numeric answer.\n"
        . "10. For matching, list each exact pair in content_items and repeat the correct pairs in answer.\n"
        . "11. For word searches, provide the complete word list in content_items and repeat the exact list in the answer.\n"
        . "12. For coloring or creative pages, the answer should say that multiple valid color choices are accepted while noting any learning requirement.\n"
        . "13. For educational-story pages, create one connected story across the book. Each page must contain a short scene, an age-appropriate lesson, a concrete illustration prompt, and a simple reflection answer or takeaway.\n"
        . "14. For tracing pages, specify the exact letters, words, or strokes to trace. For puzzle pages, fully define the puzzle and its exact solution.\n"
        . "15. Respect the user book idea as a niche direction, but keep every page anchored to the selected theme and activity type.\n"
        . "16. Respect special direction and exclude/avoid constraints unless they conflict with child safety or printable quality.\n"
        . "17. Translate the requested illustration style into English inside image prompts. Do not put non-English style phrases in image prompts.\n"
        . "18. For coloring, counting, spot-difference, and educational-story pages, every image prompt must explicitly describe subjects, action, expression, clothing/costumes if relevant, props, background, composition, printable A4 portrait layout, and the selected type/genre direction. For word-search, matching, simple-math, puzzle, learning-worksheet, and tracing pages, the image prompt must describe ONLY a blank decorative frame (small border icons, no characters or props performing anything) exactly as required in the MASTER VISUAL PROMPT CONTRACT above.\n"
        . "19. Do not use copyrighted characters, brands, logos, or trademarks.\n"
        . "20. Do not claim that generated images are automatically KDP-ready.\n"
        . "21. Every title and concept must be different from the titles already used in earlier batches.\n"
        . "22. Return only the JSON object described in REQUIRED JSON OUTPUT FORMAT above, no markdown fences, no extra keys.";
}

class BBGenerationException extends Exception {}

function bb_http_post_json(string $url, array $headers, array $body, int $timeoutSeconds): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $responseText = curl_exec($ch);
    if ($responseText === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new BBGenerationException("Request failed: {$error}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'text' => $responseText];
}

// PHP's request-per-execution model has no persistent process or AbortController
// equivalent, so unlike the Node version there is no mid-request cancellation here -
// each provider call simply runs to completion or times out via CURLOPT_TIMEOUT.
function bb_call_groq(string $prompt): array {
    $apiKey = getenv('GROQ_API_KEY') ?: '';
    if ($apiKey === '') throw new BBGenerationException('GROQ_API_KEY is not configured.');
    $model = getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile';
    $result = bb_http_post_json(
        'https://api.groq.com/openai/v1/chat/completions',
        ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"],
        ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'response_format' => ['type' => 'json_object'], 'temperature' => 0.55],
        45
    );
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new BBGenerationException("Groq {$result['status']}: " . substr($result['text'], 0, 300));
    }
    $parsed = json_decode($result['text'], true);
    $content = $parsed['choices'][0]['message']['content'] ?? null;
    if (!$content) throw new BBGenerationException('Groq returned an empty response.');
    $book = json_decode($content, true);
    if (!is_array($book)) throw new BBGenerationException('Groq returned invalid JSON.');
    return ['book' => $book, 'metrics' => ['totalDuration' => 0, 'evalCount' => $parsed['usage']['total_tokens'] ?? 0]];
}

function bb_call_gemini(string $prompt): array {
    $apiKey = getenv('GEMINI_API_KEY') ?: '';
    if ($apiKey === '') throw new BBGenerationException('GEMINI_API_KEY is not configured.');
    $model = getenv('GEMINI_MODEL') ?: 'gemini-flash-latest';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
    // 90s vs Groq's 45s: current Gemini flash models spend time "thinking" before answering.
    $result = bb_http_post_json(
        $url,
        ['Content-Type: application/json'],
        ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.55]],
        90
    );
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new BBGenerationException("Gemini {$result['status']}: " . substr($result['text'], 0, 300));
    }
    $parsed = json_decode($result['text'], true);
    $content = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$content) throw new BBGenerationException('Gemini returned an empty response.');
    $book = json_decode($content, true);
    if (!is_array($book)) throw new BBGenerationException('Gemini returned invalid JSON.');
    return ['book' => $book, 'metrics' => ['totalDuration' => 0, 'evalCount' => $parsed['usageMetadata']['totalTokenCount'] ?? 0]];
}

function bb_generate_batch(array &$input, int $startPage, int $batchCount, array $previousTitles, array $previousPages): array {
    $prompt = bb_build_prompt($input, $startPage, $batchCount, $previousTitles, $previousPages);
    try {
        $result = bb_call_groq($prompt);
    } catch (BBGenerationException $groqError) {
        error_log('Groq generation failed, trying Gemini: ' . $groqError->getMessage());
        try {
            $result = bb_call_gemini($prompt);
        } catch (BBGenerationException $geminiError) {
            throw new BBGenerationException("Groq: {$groqError->getMessage()} | Gemini: {$geminiError->getMessage()}");
        }
    }
    $book = $result['book'];
    $pages = array_slice($book['pages'] ?? [], 0, $batchCount);
    $book['pages'] = array_values(array_map(function ($page, $index) use ($startPage, &$input) {
        $page['page_number'] = $startPage + $index;
        $page['activity_type'] = $input['activityType'];
        $page['image_prompt'] = bb_lock_image_prompt($page['image_prompt'] ?? '', $input);
        return $page;
    }, $pages, array_keys($pages)));
    $book['cover_prompt'] = bb_lock_cover_prompt($book['cover_prompt'] ?? '', $input);
    $book = bb_ensure_publishing_kit($book, $input);
    if (count($book['pages']) !== $batchCount) {
        throw new BBGenerationException('The content engine did not create every requested prompt. Please try again.');
    }
    return ['book' => $book, 'metrics' => $result['metrics']];
}

function bb_fallback_page(array $input, int $pageNumber): array {
    $theme = $input['theme'] === 'Custom Idea' ? (($input['topic'] ?: $input['bookIdea']) ?: 'Custom Idea') : (($input['theme'] ?: $input['topic']) ?: 'Activity');
    $activity = str_replace('-', ' ', (string)($input['activityType'] ?: 'activity'));
    $isCustomIdea = $input['theme'] === 'Custom Idea';
    if ($isCustomIdea) {
        $scenePool = [];
        for ($index = 0; $index < 25; $index++) {
            $scenePool[] = "{$theme} scene " . ($index + 1) . " with a clear main subject, niche-specific props, child-friendly details, printable white space, and a fresh page concept";
        }
    } else {
        $scenePool = bb_theme_scene_pool($theme);
    }
    $baseScene = $scenePool[($pageNumber - 1) % count($scenePool)];
    $idea = trim((string)($input['bookIdea'] ?? ''));
    $direction = trim((string)($input['customDirection'] ?? ''));
    $avoid = trim((string)($input['avoidTerms'] ?? ''));
    $avoidLine = $avoid !== '' ? " Also avoid these user-specified elements: {$avoid}." : '';
    $sceneSeedParts = array_filter([$baseScene, $idea !== '' ? "niche angle: {$idea}" : '', $direction !== '' ? "special direction: {$direction}" : ''], fn($v) => $v !== '');
    $sceneSeed = implode('; ', $sceneSeedParts);
    $title = bb_scene_title($theme, $baseScene, $pageNumber);

    $coloringPrompt = "Create a premium black-and-white coloring book illustration for children, vertical A4 portrait composition.\n\n"
        . "Scene: {$sceneSeed}. Make the page feel like a polished commercial coloring book interior, not a worksheet and not a poster. Use one clear focal scene with balanced composition, charming child-safe characters or objects, expressive faces where relevant, recognizable props, and plenty of fun details for coloring.{$avoidLine}\n\n"
        . "Line art requirements: crisp clean black outlines, smooth confident strokes, closed shapes, large colorable areas, moderate detail, uncluttered spacing, white background, no filled black areas except tiny pupils if needed, no gray shading, no crosshatching, no gradients, no textures, no screen tones.\n\n"
        . "Critical text rule: do not include any title, heading, caption, label, signage, alphabet letters, numbers, speech bubbles, random symbols, or readable/unreadable text anywhere in the image.\n\n"
        . "Negative prompt: text, words, letters, numbers, typography, title, subtitle, captions, labels, signs, watermark, logo, border, frame, color, grayscale, shading, gradients, shadows, photorealism, 3D render, messy anatomy, extra fingers, cropped subjects, clutter" . ($avoid !== '' ? ", {$avoid}" : '') . ".";

    $commonPrompt = ($input['activityType'] ?? '') === 'coloring'
        ? $coloringPrompt
        : "Create a clean " . ($input['style'] ?: "children's educational workbook illustration") . " page for children, vertical A4 portrait composition. Scene: {$theme} {$activity} page {$pageNumber}; {$sceneSeed}. Include clear child-friendly subjects, balanced spacing, safe margins, readable silhouettes, and printable layout. Include theme-specific props and simple visual hierarchy. Avoid random text, fake labels, watermarks, logos, clutter, cropped important objects" . ($avoid !== '' ? ", {$avoid}" : '') . ".";

    $base = [
        'page_number' => $pageNumber, 'activity_type' => $input['activityType'], 'title' => $title,
        'instruction' => "Color the " . strtolower($theme) . " scene with care and notice the small details.",
        'learning_goal' => 'Observation, vocabulary, focus, and age-appropriate problem solving.',
        'content_items' => [$sceneSeed, "{$activity} task", "{$input['age']} friendly layout"],
        'image_prompt' => $commonPrompt,
        'answer' => 'Answers may vary when the page is creative; review the finished artwork for clarity.',
    ];

    $activityType = $input['activityType'] ?? '';

    if ($activityType === 'word-search') {
        $puzzle = bb_build_word_search_puzzle($theme, $pageNumber, $input);
        $imagePrompt = "Create a clean printable word-search worksheet frame for children, vertical A4 portrait composition. Use small {$theme} themed border decorations in the corners and margins, with a large blank central rectangle reserved for a 12 by 12 word-search grid that will be added later by layout software. Include a small blank word-list area below the grid, generous white space, simple child-friendly icons, and a polished workbook feel. Do not render any letters, words, puzzle grid, answer key, labels, captions, signage, typography, watermark, logo, or random symbols anywhere in the image.";
        $contentItems = ["WORD SEARCH MODE: {$puzzle['mode']}", 'WORD LIST: ' . implode(', ', $puzzle['words'])];
        foreach ($puzzle['rows'] as $index => $row) $contentItems[] = 'GRID ROW ' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . ": {$row}";
        return array_merge($base, [
            'title' => "{$theme}: {$puzzle['mode']} {$pageNumber}",
            'instruction' => $puzzle['mode'] === 'Easy Horizontal Only'
                ? "Find the 10 hidden " . strtolower($theme) . " words in the 12 by 12 grid. Words go across only."
                : "Find the 10 hidden " . strtolower($theme) . " words in the 12 by 12 grid. Words may go across, down, or diagonal.",
            'learning_goal' => 'Theme vocabulary, visual scanning, spelling, and focus.',
            'content_items' => $contentItems,
            'image_prompt' => $imagePrompt,
            'answer' => 'ANSWER KEY: ' . implode('; ', $puzzle['answers']) . '.',
        ]);
    }

    if ($activityType === 'matching') {
        $allWords = array_values(array_unique(bb_word_bank($theme)));
        $start = $pageNumber % 5;
        $words = array_slice($allWords, $start, 6);
        $pairs = array_map(fn($w) => "{$w} -> " . strtolower($w) . ' picture', $words);
        $right = array_map(fn($w) => strtolower($w) . ' picture', array_reverse($words));
        return array_merge($base, [
            'title' => "{$theme}: Matching Set {$pageNumber}",
            'instruction' => "Draw a line from each " . strtolower($theme) . " word to its matching picture.",
            'learning_goal' => 'Theme vocabulary, visual discrimination, and matching skills.',
            'content_items' => ['LEFT COLUMN: ' . implode(', ', $words), 'RIGHT COLUMN DISPLAY ORDER: ' . implode(', ', $right), 'PAIRS: ' . implode('; ', $pairs)],
            'image_prompt' => "Create a clean printable matching worksheet frame for children, vertical A4 portrait composition. Use small {$theme} themed decorative icons around the margins and leave two large blank columns for text and picture cards that will be added later by layout software. Keep the center open for connecting lines. Do not render words, letters, labels, numbers, answer keys, watermark, logo, or random symbols.",
            'answer' => 'Correct matches: ' . implode('; ', $pairs) . '.',
        ]);
    }

    if ($activityType === 'counting') {
        $words = array_values(array_unique(bb_word_bank($theme)));
        $item = $words[($pageNumber - 1) % count($words)] ?? 'OBJECT';
        $qty = 3 + (($pageNumber - 1) % 8);
        return array_merge($base, [
            'title' => "{$theme}: Count {$item} {$pageNumber}",
            'instruction' => "Count the " . strtolower($item) . " objects and write the number.",
            'learning_goal' => 'Counting accuracy, one-to-one correspondence, and theme vocabulary.',
            'content_items' => ["COUNTING OBJECT: {$item}", "EXACT QUANTITY: {$qty}", "DISPLAY RULE: show {$qty} separate, fully visible " . strtolower($item) . " objects with no overlaps"],
            'image_prompt' => "Create a clean printable counting worksheet scene for children, vertical A4 portrait composition. Show exactly {$qty} separate, fully visible " . strtolower($item) . " objects in a simple {$theme} setting, with generous spacing and one blank answer box. Use child-friendly line art or workbook illustration styling. Do not render numerals, written labels, captions, watermark, logo, or random text.",
            'answer' => "Answer: {$qty}.",
        ]);
    }

    if ($activityType === 'simple-math') {
        $a = 2 + (($pageNumber * 2) % 9);
        $b = 1 + ($pageNumber % 7);
        $op = $pageNumber % 3 === 0 ? '-' : '+';
        $left = $op === '-' ? max($a, $b) : $a;
        $right = $op === '-' ? min($a, $b) : $b;
        $result = $op === '+' ? $left + $right : $left - $right;
        return array_merge($base, [
            'title' => "{$theme}: Math Practice {$pageNumber}",
            'instruction' => "Solve the " . strtolower($theme) . " math problem, then check your answer.",
            'learning_goal' => 'Basic arithmetic, number sense, and problem solving.',
            'content_items' => ["PROBLEM: {$left} {$op} {$right} = ____", "VISUAL MANIPULATIVES: {$left} " . strtolower($theme) . " counters and {$right} more/removed counters", 'OPERATION: ' . ($op === '+' ? 'addition' : 'subtraction')],
            'image_prompt' => "Create a clean printable math worksheet frame for children, vertical A4 portrait composition. Use small {$theme} themed counters and simple decorative margin elements, with a large blank problem area and answer box that will be filled by layout software. Do not render arithmetic symbols, numerals, letters, labels, captions, watermark, logo, or random text.",
            'answer' => "Answer: {$result}.",
        ]);
    }

    if ($activityType === 'spot-difference') {
        $differences = ['one extra cloud', 'missing small flower', 'different tail position', 'one object turned sideways', 'extra pebble near the path', 'different window shape'];
        $diffItems = [];
        foreach ($differences as $index => $item) $diffItems[] = 'DIFFERENCE ' . ($index + 1) . ": {$item}";
        return array_merge($base, [
            'title' => "{$theme}: Spot Differences {$pageNumber}",
            'instruction' => "Look at the two " . strtolower($theme) . " scenes and find all 6 differences.",
            'learning_goal' => 'Observation, attention to detail, comparison, and visual memory.',
            'content_items' => array_merge(["PANEL A: {$baseScene}", 'PANEL B: same scene with exactly these differences'], $diffItems),
            'image_prompt' => "Create a printable spot-the-difference worksheet layout for children, vertical A4 portrait composition. Show two side-by-side {$theme} scene panels with identical camera angle, matching character placement, and clear simple details. Include exactly these visual changes between panels: " . implode(', ', $differences) . ". Do not render labels, captions, letters, numbers, watermark, logo, or random text.",
            'answer' => 'Differences: ' . implode('; ', $differences) . '.',
        ]);
    }

    if ($activityType === 'puzzle') {
        $words = array_slice(array_values(array_unique(bb_word_bank($theme))), 0, 4);
        $oddChoices = ['PENCIL', 'SHOE', 'CHAIR', 'BUTTON', 'UMBRELLA'];
        $odd = $oddChoices[($pageNumber - 1) % count($oddChoices)];
        return array_merge($base, [
            'title' => "{$theme}: Odd One Out {$pageNumber}",
            'instruction' => 'Circle the item that does not belong, then explain why.',
            'learning_goal' => 'Classification, reasoning, theme vocabulary, and critical thinking.',
            'content_items' => ['PUZZLE MECHANIC: Odd one out', 'CHOICES: ' . implode(', ', $words) . ", {$odd}", "CORRECT ANSWER: {$odd}", 'REASON: the other choices are ' . strtolower($theme) . ' vocabulary items, while ' . strtolower($odd) . ' is not part of this theme set'],
            'image_prompt' => "Create a clean printable children's puzzle worksheet frame, vertical A4 portrait composition. Use small {$theme} themed border decorations and leave four blank choice cards plus one answer circle area for layout software. Keep the composition simple and uncluttered. Do not render words, letters, numbers, labels, captions, watermark, logo, or random symbols.",
            'answer' => "Answer: {$odd} is the odd one out.",
        ]);
    }

    if ($activityType === 'learning-worksheet') {
        $words = array_slice(array_values(array_unique(bb_word_bank($theme))), 0, 3);
        return array_merge($base, [
            'title' => "{$theme}: Worksheet {$pageNumber}",
            'instruction' => "Complete the " . strtolower($theme) . " vocabulary activities.",
            'learning_goal' => 'Vocabulary recognition, categorization, early writing, and comprehension.',
            'content_items' => ["TASK 1: Circle the {$words[0]} picture", "TASK 2: Match {$words[1]} to its picture", "TASK 3: Draw one {$words[2]} in the blank box", "ANSWER 1: {$words[0]}", "ANSWER 2: {$words[1]} matches its picture", "ANSWER 3: drawing should clearly show {$words[2]}"],
            'image_prompt' => "Create a clean printable educational worksheet frame for children, vertical A4 portrait composition. Use small {$theme} themed decorations around the margins, three clear blank task sections, one drawing box, and generous writing space. Do not render exact words, letters, answers, labels, captions, watermark, logo, or random text.",
            'answer' => "Answers: {$words[0]}; {$words[1]} matches its picture; drawing should show {$words[2]}.",
        ]);
    }

    if ($activityType === 'educational-story') {
        $plotRole = $pageNumber === 1 ? 'opening' : ($pageNumber < $input['pageCount'] ? 'middle adventure' : 'gentle conclusion');
        return array_merge($base, [
            'title' => "{$theme}: Story Scene {$pageNumber}",
            'instruction' => 'Read the short scene and discuss the gentle lesson.',
            'learning_goal' => 'Reading comprehension, sequencing, empathy, and theme vocabulary.',
            'content_items' => ["STORY SCENE: A friendly guide explores {$baseScene}", "PLOT ROLE: {$plotRole}", 'TAKEAWAY: notice details, ask questions, and help a friend'],
            'image_prompt' => "Create a warm children's storybook illustration, vertical A4 portrait composition. Scene: a friendly recurring child guide explores {$baseScene}. Keep expressions gentle, composition clear, and details age-appropriate. Include rich {$theme} atmosphere, but do not render readable text, labels, signage, watermark, logo, or random symbols.",
            'answer' => 'Takeaway: notice details, ask kind questions, and help when a friend needs support.',
        ]);
    }

    if ($activityType === 'tracing') {
        $words = array_slice(array_values(array_unique(bb_word_bank($theme))), 0, 3);
        return array_merge($base, [
            'title' => "{$theme}: Trace Set {$pageNumber}",
            'instruction' => "Trace the " . strtolower($theme) . " vocabulary words, then write each word once on your own.",
            'learning_goal' => 'Letter formation, handwriting confidence, and theme vocabulary.',
            'content_items' => ["TRACE WORD 1: {$words[0]}", "TRACE WORD 2: {$words[1]}", "TRACE WORD 3: {$words[2]}", 'WRITING SPACE: one blank line after each word'],
            'image_prompt' => "Create a clean printable handwriting worksheet frame for children, vertical A4 portrait composition. Use small {$theme} themed decorations around the margins and leave three wide blank tracing rows plus independent writing lines for layout software. Do not render letters, dotted words, labels, captions, watermark, logo, or random text.",
            'answer' => 'Tracing is complete when each word is followed on the dotted guide and rewritten clearly on the blank line.',
        ]);
    }

    return $base;
}

function bb_generate_fallback_book(array $input, string $reason = ''): array {
    $theme = $input['theme'] === 'Custom Idea' ? (($input['topic'] ?: $input['bookIdea']) ?: 'Custom Idea') : (($input['theme'] ?: $input['topic']) ?: 'Activity');
    $activity = str_replace('-', ' ', (string)($input['activityType'] ?: 'activity'));
    $idea = trim((string)($input['bookIdea'] ?? ''));
    $pages = [];
    for ($index = 0; $index < $input['pageCount']; $index++) $pages[] = bb_fallback_page($input, $index + 1);

    $titleCaseAll = fn($s) => preg_replace_callback('/\b\w/', fn($m) => strtoupper($m[0]), $s);
    $book = [
        'book_title' => ($idea !== '' ? substr($titleCaseAll($idea), 0, 55) : "{$theme} " . $titleCaseAll($activity)) . ' Kit',
        'subtitle' => "Printable {$activity} pages for {$input['age']}",
        'description' => 'A quick product kit for ' . ($idea ?: "{$theme} {$activity} pages") . ' with instructions, answer guidance, cover direction, listing assets, and a launch checklist.',
        'cover_prompt' => bb_lock_cover_prompt('A polished ' . ($idea ?: "{$theme} {$activity} activity book") . ' cover with friendly child-safe visuals, clear title-safe space, and marketplace-ready composition', $input),
        'keywords' => array_values(array_filter([$theme, $idea, "{$theme} {$activity}", "{$activity} book", 'printable activity', 'kids workbook', 'KDP interior', 'Etsy printable', 'learning pages'], fn($v) => $v !== '' && $v !== null)),
        'pages' => $pages,
    ];
    $book = bb_ensure_publishing_kit($book, $input);

    $promptTexts = array_map(fn($p) => strtolower("{$p['title']} {$p['instruction']} " . implode(' ', $p['content_items'] ?? []) . " {$p['image_prompt']}"), $pages);
    $themeKey = strtolower((string)($input['theme'] ?? ''));
    if (preg_match('/farm/', $themeKey)) {
        $farmTerms = '/farm|barn|cow|sheep|pig|chicken|horse|duck|goat|tractor|hay|pasture|stable|coop|pond|fence|calf|lamb|rooster|geese|vegetable|animal/';
        $weakPages = [];
        foreach ($promptTexts as $index => $text) if (!preg_match($farmTerms, $text)) $weakPages[] = $index + 1;
        if (count($weakPages) > 0) array_unshift($book['quality_check']['warnings'], 'Theme coverage warning: pages ' . implode(', ', $weakPages) . ' may not clearly reference farm animals.');
    }

    $titles = array_map(fn($p) => $p['title'], $pages);
    $duplicateTitles = array_filter($titles, fn($title, $index) => array_search($title, $titles, true) !== $index, ARRAY_FILTER_USE_BOTH);
    if (count($duplicateTitles) > 0) array_unshift($book['quality_check']['warnings'], 'Some generated page titles are duplicated; review the series before publishing.');

    $fastMode = (bool)preg_match('/fast product kit mode/i', $reason);
    if ($fastMode) {
        $message = 'Generated with Fast Product Kit mode for immediate output. Enable USE_AI_GENERATION=1 (and configure GROQ_API_KEY/GEMINI_API_KEY) for AI-drafted content.';
    } elseif ($reason !== '') {
        $message = "Generated with the quick fallback because the AI content engine was slow or unavailable: {$reason}";
    } else {
        $message = 'Generated with the quick fallback workflow.';
    }
    array_unshift($book['quality_check']['warnings'], $message);
    $book['quality_check']['score'] = min($book['quality_check']['score'], 82);

    return ['book' => $book, 'metrics' => ['totalDuration' => 0, 'evalCount' => 0, 'batches' => 0, 'fallback' => true, 'reason' => $reason]];
}

function bb_normalize_title(string $title = ''): string {
    $t = strtolower($title);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    return trim($t);
}

function bb_prompt_signature(array $page = []): string {
    return bb_normalize_title(($page['title'] ?? '') . ' ' . ($page['instruction'] ?? '') . ' ' . implode(' ', $page['content_items'] ?? []));
}

function bb_generate_book(array $input): array {
    // PHP treats the string "0" as falsy, so `getenv(...) ?: '1'` would silently ignore
    // USE_AI_GENERATION=0. Compare the raw value directly instead (matches the original
    // Node check: process.env.USE_AI_GENERATION !== "0").
    $useAiGeneration = getenv('USE_AI_GENERATION') !== '0';
    if (!$useAiGeneration) return bb_generate_fallback_book($input, 'Fast product kit mode is enabled.');

    try {
        $batchSize = 5;
        $pages = [];
        $titles = [];
        $metadata = null;
        $totalDuration = 0;
        $evalCount = 0;

        for ($startPage = 1; $startPage <= $input['pageCount']; $startPage += $batchSize) {
            $batchCount = min($batchSize, $input['pageCount'] - $startPage + 1);
            $result = null;
            $attempt = 0;
            while ($attempt < 3) {
                $result = bb_generate_batch($input, $startPage, $batchCount, $titles, $pages);
                $known = array_flip(array_map('bb_prompt_signature', $pages));
                $signatures = array_map('bb_prompt_signature', $result['book']['pages']);
                $uniqueBatch = array_unique($signatures);
                $overlaps = false;
                foreach ($signatures as $sig) if (isset($known[$sig])) { $overlaps = true; break; }
                if (!$overlaps && count($uniqueBatch) === count($signatures)) break;
                $attempt++;
            }
            if ($attempt === 3) throw new BBGenerationException('A prompt batch repeated the same content. Please generate the pack again.');
            if ($metadata === null) $metadata = $result['book'];
            foreach ($result['book']['pages'] as $page) {
                $title = $page['title'];
                if (in_array(bb_normalize_title($title), array_map('bb_normalize_title', $titles), true)) {
                    $title = "{$title} — Prompt {$page['page_number']}";
                }
                $page['title'] = $title;
                $pages[] = $page;
            }
            foreach ($result['book']['pages'] as $page) $titles[] = $page['title'];
            $totalDuration += (float)($result['metrics']['totalDuration'] ?? 0);
            $evalCount += (float)($result['metrics']['evalCount'] ?? 0);
        }

        if (count($pages) !== $input['pageCount']) {
            throw new BBGenerationException("The content engine created " . count($pages) . "/{$input['pageCount']} prompts. Please generate again.");
        }
        $book = $metadata;
        $book['pages'] = $pages;
        $book = bb_remove_page_count_warnings(bb_ensure_publishing_kit($book, $input));
        return ['book' => $book, 'metrics' => ['totalDuration' => $totalDuration, 'evalCount' => $evalCount, 'batches' => (int)ceil($input['pageCount'] / $batchSize)]];
    } catch (Throwable $e) {
        error_log('Using fallback product kit generator: ' . $e->getMessage());
        return bb_generate_fallback_book($input, $e->getMessage());
    }
}

function bb_validate(array $input): array {
    if (empty($input['activityType'])) {
        $input['activityType'] = !empty($input['activityTypes']) && is_array($input['activityTypes']) ? $input['activityTypes'][0] : '';
    }
    if (empty($input['activityType'])) throw new BBAccessException('Please select an activity type.');

    $input['genreType'] = trim((string)(($input['genreType'] ?? '') ?: ($input['difficulty'] ?? 'Classic Educational')));
    $input['displayGenre'] = trim((string)($input['displayGenre'] ?? ''));

    if ($input['activityType'] === 'word-search') {
        $selectedMode = in_array($input['displayGenre'], WORD_SEARCH_MODE_TYPES, true)
            ? $input['displayGenre']
            : (in_array($input['genreType'], WORD_SEARCH_MODE_TYPES, true) ? $input['genreType'] : ($input['wordSearchMode'] ?? ''));
        $input['wordSearchMode'] = trim((string)($selectedMode ?: 'Standard Word Search'));
        $input['displayGenre'] = $input['wordSearchMode'];
        $input['genreType'] = 'Classic Educational';
    }

    if (!in_array($input['genreType'], GENRE_TYPES, true)) throw new BBAccessException('Please select a valid type / genre.');

    $input['bookIdea'] = substr(trim(preg_replace('/\s+/', ' ', (string)($input['bookIdea'] ?? ''))), 0, 180);
    $detectedTheme = bb_detect_theme_from_idea($input['bookIdea'], $input['activityType'], $input['genreType']);
    if ($detectedTheme !== '') {
        $input['theme'] = $detectedTheme;
        $input['topic'] = $detectedTheme;
    } elseif ($input['bookIdea'] !== '') {
        $input['theme'] = 'Custom Idea';
        $input['topic'] = $input['bookIdea'];
    }
    if (empty($input['theme'])) $input['theme'] = trim((string)($input['topic'] ?? ''));
    if (empty($input['topic'])) $input['topic'] = $input['theme'];
    if (empty($input['topic']) || strlen($input['topic']) < 3) throw new BBAccessException('Please enter a book idea so BrightBook can detect a theme.');
    if (empty($input['theme'])) $input['theme'] = 'Custom Idea';

    // Both checks are required: bb_is_genre_compatible() only looks at genre-level
    // theme/activity lists and can say "yes" for combinations the theme itself rejects.
    if ($input['theme'] !== 'Custom Idea' && !bb_is_compatible($input['activityType'], $input['theme'])) {
        throw new BBAccessException("The detected theme is not a good fit for {$input['activityType']}. Please adjust your book idea.");
    }
    if ($input['theme'] !== 'Custom Idea' && !bb_is_genre_compatible($input['activityType'], $input['theme'], $input['genreType'])) {
        throw new BBAccessException("The selected type / genre is not a good fit for {$input['activityType']} with {$input['theme']}. Please choose another combination.");
    }

    $input['wordSearchMode'] = trim((string)($input['wordSearchMode'] ?? 'Standard Word Search')) ?: 'Standard Word Search';
    if ($input['activityType'] === 'word-search' && !in_array($input['wordSearchMode'], WORD_SEARCH_MODE_TYPES, true)) {
        throw new BBAccessException('Please select a valid word search type / genre.');
    }
    if ($input['activityType'] !== 'word-search') $input['wordSearchMode'] = '';

    $input['difficulty'] = $input['genreType'];
    $input['style'] = trim((string)(($input['style'] ?? '') ?: bb_style_from_genre($input['genreType'])));
    $input['customDirection'] = substr(trim(preg_replace('/\s+/', ' ', (string)($input['customDirection'] ?? ''))), 0, 500);
    $input['avoidTerms'] = substr(trim(preg_replace('/\s+/', ' ', (string)($input['avoidTerms'] ?? ''))), 0, 350);
    $input['learningGoal'] = substr(trim(preg_replace('/\s+/', ' ', (string)($input['learningGoal'] ?? ''))), 0, 240);
    $input['guideCharacter'] = substr(trim(preg_replace('/\s+/', ' ', (string)($input['guideCharacter'] ?? ''))), 0, 240);
    $input['size'] = 'A4';
    $input['pageCount'] = (int)($input['pageCount'] ?? 0);
    if (!in_array($input['pageCount'], [25, 30], true)) throw new BBAccessException('Please select 25 or 30 prompts.');

    return $input;
}
