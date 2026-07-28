<?php
declare(strict_types=1);

const THEME_GROUPS = [
    ['Animals', ['Ocean Animals','Farm Animals','Safari Animals','Woodland Animals','Rainforest Animals','Arctic Animals','Dinosaurs','Insects & Butterflies','Birds','Pets']],
    ['Careers & Community', ['Community Helpers','Doctors & Nurses','Firefighters','Police Officers','Teachers & School','Construction Workers','Farmers','Chefs & Bakers','Scientists','Astronauts']],
    ['Science & Adventure', ['Outer Space','Solar System','Weather','Seasons','Human Body','Plants & Gardens','Volcanoes','Oceans & Coral Reefs','Camping Adventure','Treasure Hunt']],
    ['Learning & Everyday Life', ['Alphabet','Numbers 1–20','Shapes','Colors','Opposites','Healthy Habits','Emotions & Feelings','Friendship & Kindness','Safety Rules','Daily Routines']],
    ['Fantasy, Holidays & Transport', ['Unicorns & Rainbows','Dragons & Castles','Fairies & Magical Forests','Pirates','Robots','Cars & Trucks','Trains','Airplanes','Christmas','Halloween']],
];

const THEME_ALIASES = [
    'Ocean Animals' => ['ocean','sea','marine','underwater','dolphin','turtle','whale','shark','fish','coral'],
    'Farm Animals' => ['farm','barn','cow','sheep','pig','chicken','horse','duck','goat','rooster','calf','lamb'],
    'Safari Animals' => ['safari','lion','elephant','giraffe','zebra','rhino','hippo','savanna'],
    'Woodland Animals' => ['woodland','forest animal','fox','deer','bear','rabbit','squirrel','raccoon'],
    'Rainforest Animals' => ['rainforest','jungle','monkey','parrot','jaguar','toucan','tropical'],
    'Arctic Animals' => ['arctic','polar','penguin','seal','walrus','snow animal'],
    'Dinosaurs' => ['dinosaur','dino','t rex','triceratops','stegosaurus'],
    'Insects & Butterflies' => ['insect','bug','butterfly','bee','ladybug','dragonfly'],
    'Pets' => ['pet','dog','cat','puppy','kitten','hamster'],
    'Doctors & Nurses' => ['doctor','nurse','hospital','clinic','medical'],
    'Firefighters' => ['firefighter','fire truck','fire station'],
    'Police Officers' => ['police','officer'],
    'Teachers & School' => ['teacher','school','classroom','student'],
    'Construction Workers' => ['construction','builder','crane','bulldozer'],
    'Farmers' => ['farmer','farming','tractor','harvest'],
    'Chefs & Bakers' => ['chef','baker','bakery','cooking'],
    'Scientists' => ['scientist','science lab','experiment','microscope'],
    'Astronauts' => ['astronaut','space suit','moon explorer'],
    'Outer Space' => ['outer space','space','rocket','alien','galaxy'],
    'Solar System' => ['solar system','planet','sun','moon','orbit'],
    'Weather' => ['weather','rain','storm','cloud','wind','snow'],
    'Plants & Gardens' => ['plant','garden','flower','seed','tree'],
    'Volcanoes' => ['volcano','lava','eruption'],
    'Oceans & Coral Reefs' => ['coral reef','reef','coral','ocean reef'],
    'Camping Adventure' => ['camping','campfire','tent','hiking'],
    'Treasure Hunt' => ['treasure','hidden treasure'],
    'Alphabet' => ['alphabet','letter','abc'],
    'Shapes' => ['shape','circle','square','triangle'],
    'Colors' => ['color','colors','rainbow color'],
    'Healthy Habits' => ['healthy habit','brush teeth','exercise','hygiene'],
    'Emotions & Feelings' => ['emotion','feeling','happy','sad','angry'],
    'Friendship & Kindness' => ['friendship','kindness','sharing','friend'],
    'Safety Rules' => ['safety','rules','crosswalk','helmet'],
    'Daily Routines' => ['daily routine','morning routine','bedtime'],
    'Unicorns & Rainbows' => ['unicorn','rainbow'],
    'Dragons & Castles' => ['dragon','castle','knight'],
    'Fairies & Magical Forests' => ['fairy','magical forest','magic forest'],
    'Pirates' => ['pirate','ship','captain'],
    'Robots' => ['robot','machine'],
    'Cars & Trucks' => ['car','truck','vehicle','monster truck'],
    'Trains' => ['train','railway','locomotive'],
    'Airplanes' => ['airplane','plane','airport'],
    'Christmas' => ['christmas','santa','reindeer'],
    'Halloween' => ['halloween','pumpkin','ghost','witch'],
];

const ACTIVITY_TYPES = ['word-search','coloring','tracing','matching','counting','learning-worksheet'];

const GENRE_TYPES = [
    'Classic Educational','Cinematic Adventure','Fantasy Storybook','Documentary Style','Whimsical Cartoon',
    'Cozy Storybook','Science Explorer','Magical World','Realistic Classroom','Vintage Workbook',
];

const WORD_SEARCH_MODE_TYPES = ['Standard Word Search','Easy Horizontal Only','Challenge Diagonal Mix','Advanced Longer Words'];

function bb_feature_slug(string $value = ''): string {
    $v = strtolower($value);
    $v = str_replace('&', ' and ', $v);
    $v = preg_replace('/[^a-z0-9]+/', '-', $v);
    return trim($v, '-');
}
function bb_norm_text(string $value = ''): string {
    $v = strtolower($value);
    $v = str_replace('&', ' and ', $v);
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
    return trim($v);
}
function bb_text_has_term(string $text, string $term): bool {
    $normalized = bb_norm_text($term);
    if ($normalized === '') return false;
    if (str_contains($normalized, ' ')) return str_contains($text, $normalized);
    return in_array($normalized, preg_split('/\s+/', $text), true);
}
function bb_theme_feature_key(string $theme): string { return 'theme.' . bb_feature_slug($theme); }

function bb_theme_category(string $theme): string {
    foreach (THEME_GROUPS as [$category, $items]) {
        if (in_array($theme, $items, true)) return $category;
    }
    return 'Custom Themes';
}

function bb_compatible_activity_types(string $theme): array {
    $category = bb_theme_category($theme);
    $t = strtolower($theme);
    $allowed = ACTIVITY_TYPES;
    if ($category === 'Learning & Everyday Life') {
        if (preg_match('/alphabet|numbers|shapes|colors|opposites/', $t)) {
            $allowed = ['word-search','coloring','tracing','matching','counting','learning-worksheet'];
        }
        if (preg_match('/emotion|friendship|kindness|healthy|safety|routine/', $t)) {
            $allowed = ['word-search','coloring','tracing','matching','learning-worksheet'];
        }
    }
    if (preg_match('/human body/', $t)) $allowed = ['word-search','coloring','matching','learning-worksheet'];
    if (preg_match('/volcano/', $t)) $allowed = array_values(array_diff($allowed, ['tracing']));
    if (preg_match('/pirates|treasure hunt|camping adventure/', $t)) $allowed = array_values(array_diff($allowed, ['tracing']));
    if (preg_match('/christmas|halloween/', $t)) $allowed = array_values(array_diff($allowed, ['simple-math']));
    return $allowed;
}

function bb_is_compatible(string $activityType, string $theme): bool {
    if ($theme === '') return true;
    return in_array($activityType, bb_compatible_activity_types($theme), true);
}

function bb_theme_names_by_categories(array $categories): array {
    $out = [];
    foreach (THEME_GROUPS as [$category, $items]) {
        if (in_array($category, $categories, true)) $out = array_merge($out, $items);
    }
    return $out;
}

function bb_all_themes(): array {
    $out = [];
    foreach (THEME_GROUPS as [, $items]) $out = array_merge($out, $items);
    return $out;
}

// compatibleThemesForGenre() and compatibleActivitiesForGenre() are independent, flat,
// per-genre filters. Their cross product is NOT guaranteed valid: a theme can appear in
// compatibleThemesForGenre(genre) while still failing compatibleActivityTypes(theme) for
// some activity in compatibleActivitiesForGenre(genre) (e.g. "Cozy Storybook" lists both
// "Pirates" and "tracing", but Pirates itself excludes tracing). Any caller that combines
// these two lists (including /api/catalog consumers) MUST also check
// compatibleActivityTypes(theme) before treating a theme+activity pair as valid for a
// genre - see bb_is_genre_compatible(), which is always used alongside bb_is_compatible(),
// never alone.
function bb_compatible_themes_for_genre(string $genreType): array {
    $g = strtolower($genreType ?: 'Classic Educational');
    $all = bb_all_themes();
    $exclude = function (array $themes, string $pattern) {
        return array_values(array_filter($themes, fn($t) => !preg_match($pattern, $t)));
    };
    if ($g === 'classic educational' || $g === 'whimsical cartoon') return $all;
    if ($g === 'cinematic adventure') return $exclude($all, '/alphabet|numbers|shapes|colors|opposites|healthy habits|daily routines/i');
    if ($g === 'fantasy storybook') return $exclude(bb_theme_names_by_categories(['Animals','Science & Adventure','Fantasy, Holidays & Transport']), '/human body|weather|seasons|plants|volcanoes|solar system/i');
    if ($g === 'documentary style') return $exclude(bb_theme_names_by_categories(['Animals','Careers & Community','Science & Adventure']), '/treasure hunt|camping adventure/i');
    if ($g === 'cozy storybook') return $exclude($all, '/human body|volcanoes|police officers|construction workers|robots|cars|trucks|trains|airplanes/i');
    if ($g === 'science explorer') return $exclude(bb_theme_names_by_categories(['Animals','Careers & Community','Science & Adventure','Learning & Everyday Life']), '/chefs|bakers|police|friendship|kindness|daily routines|opposites/i');
    if ($g === 'magical world') return $exclude(bb_theme_names_by_categories(['Animals','Science & Adventure','Fantasy, Holidays & Transport']), '/human body|weather|seasons|plants|volcanoes|solar system|cars|trucks|trains|airplanes/i');
    if ($g === 'realistic classroom') return $exclude(bb_theme_names_by_categories(['Careers & Community','Science & Adventure','Learning & Everyday Life']), '/pirates|treasure hunt|camping adventure|volcanoes/i');
    if ($g === 'vintage workbook') return $exclude(bb_theme_names_by_categories(['Animals','Careers & Community','Science & Adventure','Learning & Everyday Life']), '/camping adventure|treasure hunt|volcanoes/i');
    return $all;
}

function bb_compatible_activities_for_genre(string $genreType): array {
    $g = strtolower($genreType ?: 'Classic Educational');
    if ($g === 'cinematic adventure') return ['coloring'];
    if ($g === 'fantasy storybook') return ['coloring','matching'];
    if ($g === 'documentary style') return ['word-search','coloring','matching','learning-worksheet'];
    if ($g === 'cozy storybook') return ['coloring','tracing','matching','counting','learning-worksheet'];
    if ($g === 'science explorer') return ['word-search','coloring','matching','counting','learning-worksheet'];
    if ($g === 'magical world') return ['coloring','matching','counting'];
    if ($g === 'realistic classroom') return ['word-search','tracing','matching','counting','learning-worksheet'];
    if ($g === 'vintage workbook') return ['word-search','tracing','matching','counting','learning-worksheet'];
    return ACTIVITY_TYPES;
}

// Only checks genre-level activity/theme lists. Does NOT check whether the theme itself
// allows the activity - callers must AND this with bb_is_compatible() themselves.
function bb_is_genre_compatible(string $activityType, string $theme, string $genreType): bool {
    return in_array($activityType, bb_compatible_activities_for_genre($genreType), true)
        && in_array($theme, bb_compatible_themes_for_genre($genreType), true);
}

function bb_style_from_genre(string $genreType): string {
    $map = [
        'Classic Educational' => 'clean modern educational workbook illustration',
        'Cinematic Adventure' => "cinematic children's adventure illustration with dynamic composition",
        'Fantasy Storybook' => 'whimsical fantasy storybook illustration',
        'Documentary Style' => 'clear educational documentary-style illustration',
        'Whimsical Cartoon' => 'cute whimsical cartoon illustration with bold clean shapes',
        'Cozy Storybook' => 'soft cozy children\'s storybook illustration',
        'Science Explorer' => 'bright science explorer educational illustration',
        'Magical World' => 'magical child-friendly fantasy illustration',
        'Realistic Classroom' => 'realistic clean classroom worksheet illustration',
        'Vintage Workbook' => 'vintage educational workbook illustration',
    ];
    return $map[$genreType] ?? $map['Classic Educational'];
}

const THEME_VISUALS = [
    'animals' => 'friendly natural habitat, simple plants and environmental details, warm approachable expressions',
    'careers' => 'clear workplace setting, recognizable tools and safe uniforms, positive community-focused action',
    'science' => 'educational exploration setting, simplified accurate scientific objects, wonder and discovery',
    'learning' => 'clean classroom-friendly visual language, familiar everyday objects, clear concept-focused composition',
    'fantasy' => 'whimsical child-safe fantasy world, playful magical details, friendly non-threatening characters',
    'transport' => 'clear travel environment, recognizable vehicles, safe movement and uncluttered composition',
    'holiday' => 'festive child-friendly setting, recognizable seasonal decorations, warm celebratory mood',
];

function bb_theme_visual_direction(string $theme = ''): string {
    $t = strtolower($theme);
    if (preg_match('/animal|dinosaur|insect|butterfl|bird|pet|ocean|rainforest|arctic|farm|safari|woodland/', $t)) return THEME_VISUALS['animals'];
    if (preg_match('/helper|doctor|nurse|firefighter|police|teacher|school|worker|farmer|chef|baker|scientist|astronaut/', $t)) return THEME_VISUALS['careers'];
    if (preg_match('/space|solar|weather|season|body|plant|garden|volcano|coral|camping|treasure/', $t)) return THEME_VISUALS['science'];
    if (preg_match('/alphabet|number|shape|color|opposite|habit|emotion|feeling|friendship|kindness|safety|routine/', $t)) return THEME_VISUALS['learning'];
    if (preg_match('/unicorn|dragon|castle|fair|magic|pirate|robot/', $t)) return THEME_VISUALS['fantasy'];
    if (preg_match('/car|truck|train|airplane/', $t)) return THEME_VISUALS['transport'];
    return THEME_VISUALS['holiday'];
}

function bb_prompt_scene_theme(string $theme = ''): string {
    $t = strtolower($theme ?: 'activity');
    $map = [
        ['/scientists?/', 'a friendly science laboratory with microscopes, beakers, plants, safety goggles, blank notebooks, and curious young researchers'],
        ['/police officers?/', 'a friendly community safety scene with helpful officers, traffic cones, a patrol car, a crosswalk, and neighborhood helpers'],
        ['/doctors?|nurses?/', 'a cheerful clinic scene with child-safe medical tools, caring helpers, a checkup table, and simple health props'],
        ['/firefighters?/', 'a friendly fire station scene with safety helmets, hoses, a fire truck, boots, and rescue practice props'],
        ['/teachers?|school/', 'a classroom activity scene with books, backpacks, art supplies, a globe without labels, and smiling learners'],
        ['/astronauts?/', 'a space explorer scene with astronauts, rockets, planets without labels, stars, control panels without text, and moon rocks'],
        ['/ocean|coral|sea/', 'an underwater ocean scene with turtles, dolphins, coral, shells, sea plants, bubbles, and friendly fish'],
        ['/farm/', 'a cheerful farm scene with barns without signs, fences, crops, farm tools, and friendly animals'],
        ['/dinosaur/', 'a prehistoric nature scene with friendly dinosaurs, large leaves, rocks, volcano shapes, nests, and footprints'],
        ['/pets?/', 'a cozy pet-care scene with friendly cats, dogs, bowls without labels, toys, cushions, and simple home details'],
    ];
    foreach ($map as [$pattern, $result]) {
        if (preg_match($pattern, $t)) return $result;
    }
    return "a detailed child-friendly {$theme} scene with recognizable theme props, charming characters, and simple background details";
}

function bb_theme_elements(string $theme = ''): array {
    $t = strtolower($theme ?: 'activity');
    $packs = [
        ['/ocean|coral|sea/', ['sea turtle','dolphin','clownfish','octopus','seahorse','crab','starfish','whale'], ['coral reef','sandy seabed','kelp forest','tide pool','underwater cave'], ['shells','bubbles','sea plants','smooth stones','treasure-free chest','waves']],
        ['/safari/', ['lion','elephant','giraffe','zebra','rhino','meerkat','cheetah','hippo'], ['savanna grassland','watering hole','acacia grove','safari trail','sunny wildlife park'], ['tall grass','binoculars','jeep without logos','rocks','bushes','clouds']],
        ['/woodland/', ['deer','fox','owl','rabbit','squirrel','hedgehog','raccoon','songbird'], ['quiet forest','mushroom grove','leafy trail','hollow log clearing','acorn meadow'], ['acorns','mushrooms','fallen leaves','tree stumps','ferns','berries']],
        ['/rainforest/', ['monkey','toucan','jaguar','tree frog','sloth','parrot','butterfly','tapir'], ['tropical canopy','vine-covered path','rainforest river','giant leaf garden','waterfall clearing'], ['vines','ferns','big leaves','orchids','fruit','raindrops']],
        ['/arctic/', ['polar bear','penguin','seal','arctic fox','snowy owl','walrus','orca','reindeer'], ['snowy ice field','igloo village without signs','frozen shore','aurora sky','iceberg scene'], ['snowflakes','ice blocks','mittens','fish','pine trees','sled tracks']],
        ['/dinosaur/', ['triceratops','stegosaurus','brachiosaurus','t-rex','ankylosaurus','parasaurolophus','baby dinosaur','pteranodon'], ['prehistoric valley','fern forest','volcano landscape','dinosaur nest','rocky river'], ['fossils','giant leaves','eggs','rocks','footprints','clouds']],
        ['/insect|butterfl/', ['butterfly','bee','ladybug','dragonfly','caterpillar','ant','beetle','grasshopper'], ['flower garden','leafy meadow','bug hotel','pond edge','vegetable patch'], ['flowers','leaves','honeycomb','mushrooms','dew drops','stems']],
        ['/bird/', ['owl','parrot','sparrow','eagle','duck','flamingo','peacock','robin'], ['tree branch','nest scene','bird garden','pond shore','forest clearing'], ['feathers','eggs','leaves','berries','clouds','flowers']],
        ['/pet/', ['puppy','kitten','hamster','rabbit','goldfish','parakeet','turtle','guinea pig'], ['cozy pet room','backyard play area','pet care corner','sunny window spot','garden path'], ['toys','blank bowls','cushions','paw prints','brushes','blank tags']],
        ['/community helper/', ['mail carrier','librarian','crossing guard','bus driver','sanitation worker','park worker','shop helper','community volunteer'], ['friendly neighborhood','library corner','crosswalk','bus stop','park path'], ['bags without logos','books without text','cones','benches','trees','recycling bins without labels']],
        ['/doctor|nurse/', ['doctor','nurse','patient child','clinic helper','dentist','paramedic','care team','health teacher'], ['cheerful clinic','checkup room','health corner','waiting area','medical station'], ['stethoscope','bandage','blank chart','toy bear','sink','first-aid box without labels']],
        ['/firefighter/', ['firefighter','fire truck','rescue dog','helmeted helper','ladder team','hose team','station crew','safety teacher'], ['fire station','training yard','truck bay','safe rescue practice scene','neighborhood safety day'], ['hose','helmet','boots','ladder','hydrant','cones']],
        ['/police/', ['police officer','crossing guard','community helper','patrol car','bike officer','safety team','friendly officer','traffic helper'], ['crosswalk','community park','neighborhood street','school safety zone','traffic safety corner'], ['cones','badge shapes without text','walkie-talkie','traffic lights','bicycle','blank notebook']],
        ['/teacher|school/', ['teacher','student group','reader child','art student','science student','class helper','music student','librarian'], ['classroom','reading corner','art table','school garden','library nook'], ['books without text','pencils','backpacks','blank board','globe without labels','crayons']],
        ['/construction/', ['builder','crane operator','architect child','toolbox helper','dump truck','bulldozer','bricklayer','safety worker'], ['construction site','tool shed','road work zone','building frame','materials yard'], ['helmet','cones','bricks','tools','crane','wood planks']],
        ['/farmer/', ['farmer','tractor driver','garden helper','barn worker','crop picker','animal caretaker','market helper','watering helper'], ['crop field','barnyard','vegetable garden','orchard','farm market table'], ['tractor','watering can','baskets','hay','fence','blank crates']],
        ['/chef|baker/', ['chef','baker','kitchen helper','pastry maker','soup cook','bread maker','cake decorator','apron child'], ['cozy kitchen','bakery counter','mixing table','oven corner','picnic prep table'], ['mixing bowl','spoon','rolling pin','bread','cupcakes','blank recipe card']],
        ['/scientist/', ['young scientist','microscope explorer','plant researcher','crystal observer','lab helper','telescope student','experiment team','goggle-wearing child'], ['science laboratory','classroom lab','plant table','crystal station','observation desk'], ['microscope','beakers','blank notebooks','goggles','plant samples','magnifying glass']],
        ['/astronaut|space|solar/', ['astronaut','rocket explorer','moon rover','space student','planet observer','satellite helper','alien-free explorer','telescope child'], ['moon surface','rocket launch pad','space station room','planet trail','starry sky'], ['planets without labels','stars','moon rocks','rocket','control panels without text','helmets']],
        ['/weather|season/', ['weather watcher','raincoat child','snow helper','sunny day explorer','windy kite flyer','cloud observer','season tree','umbrella child'], ['weather station without labels','park path','seasonal garden','rain puddle scene','snowy yard'], ['clouds','raindrops','snowflakes','leaves','sun shapes','umbrellas']],
        ['/human body|healthy|safety|routine|habit/', ['healthy child','exercise helper','handwashing child','sleepy bedtime helper','safety watcher','toothbrushing child','snack helper','routine chart without text'], ['bathroom sink','playground','kitchen table','bedroom corner','clinic classroom'], ['toothbrush','soap bubbles','fruit','water bottle','sneakers','blank checklist']],
        ['/plant|garden/', ['gardener child','flower helper','seed planter','watering helper','butterfly visitor','vegetable picker','tree planter','sprout observer'], ['flower garden','vegetable patch','greenhouse','orchard','potting table'], ['watering can','seed packets without text','leaves','pots','tools','butterflies']],
        ['/volcano/', ['young geologist','volcano explorer','rock collector','safety observer','mountain hiker','fossil finder','lava watcher','science guide'], ['volcano landscape','rocky trail','geology table','mountain valley','safe observation hill'], ['rocks','crystals','steam clouds','lava shapes','backpack','magnifying glass']],
        ['/camping/', ['camper child','tent helper','trail explorer','lantern carrier','backpack kid','nature observer','map helper','campfire sitter'], ['forest campsite','tent area','lake trail','mountain camp','woodland clearing'], ['tent','lantern','backpack','logs','stars','blank map']],
        ['/treasure/', ['adventurer child','map explorer','compass helper','island walker','cave explorer','clue finder','bridge crosser','chest opener'], ['island path','jungle trail','safe cave','sandy beach','wooden bridge'], ['compass','map without letters','coins','chest','vines','rocks']],
        ['/alphabet/', ['letter explorer','classroom helper','book friend','pencil character','reading child','library helper','alphabet blocks without letters','teacher owl'], ['reading corner','classroom table','library nook','book garden','learning rug'], ['books without text','pencils','blank cards','blocks without letters','stars','backpacks']],
        ['/number/', ['counting child','number explorer','math helper','block stacker','abacus friend','counting animals','shape counter','market helper'], ['classroom table','counting corner','toy shelf','market basket','learning rug'], ['blocks without printed numbers','beads','apples','stars','blank cards','counters']],
        ['/shape/', ['shape explorer','circle friend','square builder','triangle climber','pattern helper','block child','art student','shape sorter'], ['art table','classroom rug','block city','playroom','pattern garden'], ['circles','squares','triangles','stars','blank cards','crayons']],
        ['/color/', ['paint helper','rainbow friend','art student','crayon kid','palette explorer','flower painter','butterfly painter','studio helper'], ['art studio','flower garden','classroom table','rainbow meadow','craft corner'], ['paintbrushes','blank palette','crayons','flowers','butterflies','jars without labels']],
        ['/opposite/', ['big and small pair','up and down scene','open and closed helper','day and night pair','fast and slow racers','happy and sad faces','near and far scene','full and empty baskets'], ['learning rug','playground','classroom corner','storybook scene','park path'], ['blank cards','baskets','balls','blocks','doors without signs','clouds']],
        ['/emotion|friendship|kindness/', ['smiling friend','sharing child','helping buddy','kindness helper','feeling face character','comforting friend','teamwork pair','thank-you helper'], ['playground','classroom rug','park bench','story corner','garden path'], ['hearts without text','toys','flowers','blank cards','benches','books without words']],
        ['/unicorn|rainbow/', ['unicorn','rainbow pony','cloud friend','star helper','magical foal','flower crown unicorn','moon unicorn','meadow unicorn'], ['rainbow meadow','cloud garden','starry hill','magical forest','flower field'], ['stars','clouds','flowers','sparkles','mushrooms','crescent moon']],
        ['/dragon|castle/', ['friendly dragon','castle guard','young knight','princess explorer','tower helper','shield bearer','baby dragon','bridge walker'], ['castle courtyard','tower room','dragon meadow','stone bridge','royal garden'], ['shields without symbols','flags without marks','stones','flowers','treasure-free chest','clouds']],
        ['/fair/', ['fairy','forest sprite','mushroom friend','flower fairy','butterfly helper','wand holder','tiny gardener','moon fairy'], ['magical forest','mushroom village','flower meadow','fairy garden','glowing pond'], ['mushrooms','flowers','wings','stars','leaves','sparkles']],
        ['/pirate/', ['pirate child','ship helper','parrot friend','island explorer','sailor kid','treasure map holder','captain child','anchor helper'], ['pirate ship','island beach','dock scene','jungle trail','safe cave'], ['anchor','ship wheel','map without text','coins','palm trees','sails']],
        ['/robot/', ['friendly robot','gear helper','inventor child','robot pet','workshop bot','space robot','cleaning robot','builder bot'], ['robot workshop','gear room','space lab','invention table','city sidewalk'], ['gears','bolts','buttons without labels','wires','tools','blank panels']],
        ['/car|truck/', ['race car','pickup truck','fire truck toy','delivery van','mechanic child','monster truck','tow truck','family car'], ['garage','road scene','car wash','traffic park','repair shop'], ['wheels','cones','tools','blank signs','road lines','clouds']],
        ['/train/', ['steam train','conductor child','passenger car','freight train','station helper','toy train','mountain train','subway-style train'], ['train station','railroad track','bridge crossing','mountain railway','platform without signs'], ['tracks','wheels','clouds','suitcases','signals without text','trees']],
        ['/airplane/', ['airplane','pilot child','airport helper','cloud flyer','hangar mechanic','paper plane friend','helicopter','runway crew'], ['runway','airport hangar','cloud sky','control tower without text','travel scene'], ['clouds','wings','luggage without labels','cones','tools','stars']],
        ['/christmas/', ['holiday tree','gift helper','snow child','stocking friend','gingerbread baker','reindeer','snowman','ornament maker'], ['cozy living room','snowy yard','holiday kitchen','tree corner','winter street'], ['gifts','ornaments','snowflakes','stockings','cookies','stars']],
        ['/halloween/', ['pumpkin friend','costume child','friendly ghost','bat buddy','candy helper','black cat','witch hat character','spooky tree'], ['pumpkin patch','costume party','friendly haunted yard','moonlit path','candy table'], ['pumpkins','bats','candy','leaves','lanterns','stars']],
    ];
    foreach ($packs as [$pattern, $subjects, $settings, $props]) {
        if (preg_match($pattern, $t)) return ['subjects' => $subjects, 'settings' => $settings, 'props' => $props];
    }
    return [
        'subjects' => ["{$theme} explorer", "{$theme} helper", "{$theme} friend", "{$theme} scene", "{$theme} character"],
        'settings' => [bb_prompt_scene_theme($theme), 'activity corner', 'storybook setting', 'playful learning scene', 'outdoor scene'],
        'props' => ['simple props', 'background details', 'decorative shapes', 'open spaces', 'friendly objects', 'nature details'],
    ];
}

function bb_build_scene_pool_from_elements(array $elements): array {
    $pool = [];
    for ($index = 0; $index < 25; $index++) {
        $subject = $elements['subjects'][$index % count($elements['subjects'])];
        $setting = $elements['settings'][intdiv($index, count($elements['subjects'])) % count($elements['settings'])];
        $p1 = $elements['props'][$index % count($elements['props'])];
        $p2 = $elements['props'][($index + 2) % count($elements['props'])];
        $p3 = $elements['props'][($index + 4) % count($elements['props'])];
        $pool[] = "{$subject} in a {$setting} with {$p1}, {$p2}, {$p3}, clear foreground shapes, and child-friendly background details";
    }
    return $pool;
}

function bb_theme_scene_pool(string $theme = ''): array {
    $t = strtolower($theme ?: 'activity');
    if (preg_match('/farm/', $t)) {
        return [
            "a gentle cow standing beside a wooden barn, hay bales, a milk pail, fence posts, grass tufts, and a sunny farmyard",
            "three fluffy sheep grazing in a pasture with rolling hills, a small gate, wildflowers, clouds, and a distant barn",
            "a cheerful pig in a clean straw pen with a trough, mud puddle shapes, fence rails, apples, and farm buckets",
            "a chicken coop scene with hens, chicks, nesting boxes, corn kernels, a water dish, and a rooster on a fence",
            "a friendly horse looking over a stable door with horseshoes, hay bundles, saddle blankets, carrots, and barn beams",
            "ducks swimming in a small farm pond with reeds, lily pads, ducklings, stones, and a wooden footbridge",
            "a curious goat standing near a fence with tin cans, grass, a small shed, climbing rocks, and leafy branches",
            "a farm dog watching over animals near a gate with paw prints, a feed sack without labels, and a wagon wheel",
            "a farm cat sleeping on hay beside a lantern, pumpkins, baskets, barn planks, and tiny mice peeking out",
            "a donkey carrying flower baskets along a farm path with fence rails, shrubs, and a farmhouse in the distance",
            "a tractor parked beside hay bales with chickens nearby, tire tracks, crates, farm tools, and open sky",
            "a market basket scene with eggs, carrots, apples, corn, a watering can, and small farm animals around it",
            "a baby calf nuzzling its mother near a barn door with straw, buckets, butterflies, and soft pasture details",
            "a lamb jumping over a small log in a meadow with daisies, fence posts, clouds, and a woolly sheep family",
            "piglets playing around a clean wooden trough with straw piles, round stones, simple flowers, and a low fence",
            "a rooster greeting the morning beside a chicken coop with hens, chicks, corn stalks, and sunrise shapes",
            "a pony in a paddock with a brush, apple basket, fence, stable window, horseshoe decoration, and grass patches",
            "geese walking in a line near a pond with reeds, footprints, a small bridge, and farmyard plants",
            "goats climbing on wooden platforms inside a safe farm play yard with buckets, leaves, and a small shelter",
            "a farmer child feeding animals with a bucket, surrounded by cow, sheep, chicken, and goat in a tidy barnyard",
            "a barn interior with animal stalls, hayloft ladder, feed buckets, friendly animals peeking out, and clean open spaces",
            "a vegetable garden beside the animal barn with rabbits, chickens, watering can, carrots, leafy plants, and fence rails",
            "a farmyard parade with cow, horse, sheep, pig, duck, and chicken walking along a dirt path",
            "a cozy nighttime barn scene with sleeping animals, moon visible through a window, hay piles, and quiet farm details",
            "a spring farm scene with baby animals, flowers, butterflies, fresh grass, and a welcoming barn gate",
        ];
    }
    return bb_build_scene_pool_from_elements(bb_theme_elements($theme));
}

function bb_scene_title(string $theme, string $scene, int $pageNumber): string {
    $t = $theme ?: 'Activity';
    if (preg_match('/farm/i', $t)) {
        $names = [
            "Cow at the Barn","Sheep in the Pasture","Pig in the Straw Pen","Chicken Coop Friends","Horse at the Stable",
            "Ducks at the Pond","Goat by the Fence","Farm Dog Helper","Cat in the Hay","Donkey on the Farm Path",
            "Tractor and Hay Bales","Farm Market Basket","Baby Calf and Mother","Jumping Lamb","Playful Piglets",
            "Rooster Morning","Pony Paddock","Geese by the Pond","Goat Play Yard","Feeding Time",
            "Inside the Barn","Garden by the Barn","Farmyard Parade","Nighttime Barn","Spring Baby Animals",
        ];
        return "{$t}: " . $names[($pageNumber - 1) % count($names)];
    }
    $words = preg_split('/\s+/', $scene ?: '');
    $stop = '/^(a|an|the|with|and|in|on|near|beside|inside|around|of|to|for|its|clear|foreground|shapes|child-friendly|background|details)$/i';
    $filtered = array_values(array_filter($words, fn($w) => !preg_match($stop, $w)));
    $titleWords = implode(' ', array_slice($filtered, 0, 7));
    $titleWords = preg_replace('/[^\w\s-]/', '', $titleWords);
    $titleWords = preg_replace_callback('/\b\w/', fn($m) => strtoupper($m[0]), $titleWords);
    return "{$t}: {$titleWords}";
}

function bb_detect_theme_from_idea(string $idea, string $activityType = '', string $genreType = ''): string {
    $text = bb_norm_text($idea);
    if ($text === '') return '';
    $genreThemes = ($genreType !== '' && in_array($genreType, GENRE_TYPES, true))
        ? array_flip(bb_compatible_themes_for_genre($genreType))
        : null;
    $candidates = array_values(array_filter(bb_all_themes(), function ($theme) use ($activityType, $genreThemes) {
        $activityOk = $activityType === '' || in_array($activityType, bb_compatible_activity_types($theme), true);
        $genreOk = $genreThemes === null || isset($genreThemes[$theme]);
        return $activityOk && $genreOk;
    }));

    $best = null;
    $bestScore = 0;
    foreach ($candidates as $theme) {
        $name = bb_norm_text($theme);
        $tokens = array_filter(explode(' ', $name), fn($tok) => strlen($tok) > 2);
        $score = bb_text_has_term($text, $name) ? 12 : 0;
        foreach ($tokens as $token) if (bb_text_has_term($text, $token)) $score += 2;
        foreach ((THEME_ALIASES[$theme] ?? []) as $alias) {
            $normalized = bb_norm_text($alias);
            if (bb_text_has_term($text, $normalized)) $score += str_contains($normalized, ' ') ? 8 : 5;
        }
        if ($best === null || $score > $bestScore) { $best = $theme; $bestScore = $score; }
    }
    return ($best !== null && $bestScore > 0) ? $best : '';
}
