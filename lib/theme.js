const THEME_GROUPS=[
  ["Animals",["Ocean Animals","Farm Animals","Safari Animals","Woodland Animals","Rainforest Animals","Arctic Animals","Dinosaurs","Insects & Butterflies","Birds","Pets"]],
  ["Careers & Community",["Community Helpers","Doctors & Nurses","Firefighters","Police Officers","Teachers & School","Construction Workers","Farmers","Chefs & Bakers","Scientists","Astronauts"]],
  ["Science & Adventure",["Outer Space","Solar System","Weather","Seasons","Human Body","Plants & Gardens","Volcanoes","Oceans & Coral Reefs","Camping Adventure","Treasure Hunt"]],
  ["Learning & Everyday Life",["Alphabet","Numbers 1–20","Shapes","Colors","Opposites","Healthy Habits","Emotions & Feelings","Friendship & Kindness","Safety Rules","Daily Routines"]],
  ["Fantasy, Holidays & Transport",["Unicorns & Rainbows","Dragons & Castles","Fairies & Magical Forests","Pirates","Robots","Cars & Trucks","Trains","Airplanes","Christmas","Halloween"]]
];
const THEME_ALIASES={
  "Ocean Animals":["ocean","sea","marine","underwater","dolphin","turtle","whale","shark","fish","coral"],
  "Farm Animals":["farm","barn","cow","sheep","pig","chicken","horse","duck","goat","rooster","calf","lamb"],
  "Safari Animals":["safari","lion","elephant","giraffe","zebra","rhino","hippo","savanna"],
  "Woodland Animals":["woodland","forest animal","fox","deer","bear","rabbit","squirrel","raccoon"],
  "Rainforest Animals":["rainforest","jungle","monkey","parrot","jaguar","toucan","tropical"],
  "Arctic Animals":["arctic","polar","penguin","seal","walrus","snow animal"],
  "Dinosaurs":["dinosaur","dino","t rex","triceratops","stegosaurus"],
  "Insects & Butterflies":["insect","bug","butterfly","bee","ladybug","dragonfly"],
  "Pets":["pet","dog","cat","puppy","kitten","hamster"],
  "Doctors & Nurses":["doctor","nurse","hospital","clinic","medical"],
  "Firefighters":["firefighter","fire truck","fire station"],
  "Police Officers":["police","officer"],
  "Teachers & School":["teacher","school","classroom","student"],
  "Construction Workers":["construction","builder","crane","bulldozer"],
  "Farmers":["farmer","farming","tractor","harvest"],
  "Chefs & Bakers":["chef","baker","bakery","cooking"],
  "Scientists":["scientist","science lab","experiment","microscope"],
  "Astronauts":["astronaut","space suit","moon explorer"],
  "Outer Space":["outer space","space","rocket","alien","galaxy"],
  "Solar System":["solar system","planet","sun","moon","orbit"],
  "Weather":["weather","rain","storm","cloud","wind","snow"],
  "Plants & Gardens":["plant","garden","flower","seed","tree"],
  "Volcanoes":["volcano","lava","eruption"],
  "Oceans & Coral Reefs":["coral reef","reef","coral","ocean reef"],
  "Camping Adventure":["camping","campfire","tent","hiking"],
  "Treasure Hunt":["treasure","hidden treasure"],
  "Alphabet":["alphabet","letter","abc"],
  "Shapes":["shape","circle","square","triangle"],
  "Colors":["color","colors","rainbow color"],
  "Healthy Habits":["healthy habit","brush teeth","exercise","hygiene"],
  "Emotions & Feelings":["emotion","feeling","happy","sad","angry"],
  "Friendship & Kindness":["friendship","kindness","sharing","friend"],
  "Safety Rules":["safety","rules","crosswalk","helmet"],
  "Daily Routines":["daily routine","morning routine","bedtime"],
  "Unicorns & Rainbows":["unicorn","rainbow"],
  "Dragons & Castles":["dragon","castle","knight"],
  "Fairies & Magical Forests":["fairy","magical forest","magic forest"],
  "Pirates":["pirate","ship","captain"],
  "Robots":["robot","machine"],
  "Cars & Trucks":["car","truck","vehicle","monster truck"],
  "Trains":["train","railway","locomotive"],
  "Airplanes":["airplane","plane","airport"],
  "Christmas":["christmas","santa","reindeer"],
  "Halloween":["halloween","pumpkin","ghost","witch"]
};
const ACTIVITY_TYPES=["word-search","coloring","tracing","matching","counting","learning-worksheet"];
const GENRE_TYPES=[
  "Classic Educational",
  "Cinematic Adventure",
  "Fantasy Storybook",
  "Documentary Style",
  "Whimsical Cartoon",
  "Cozy Storybook",
  "Science Explorer",
  "Magical World",
  "Realistic Classroom",
  "Vintage Workbook"
];
const WORD_SEARCH_MODE_TYPES=[
  "Standard Word Search",
  "Easy Horizontal Only",
  "Challenge Diagonal Mix",
  "Advanced Longer Words"
];
function featureSlug(value=""){
  return String(value).toLowerCase().replace(/&/g," and ").replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");
}
function normText(value=""){
  return String(value).toLowerCase().replace(/&/g," and ").replace(/[^a-z0-9]+/g," ").trim();
}
function textHasTerm(text,term){
  const normalized=normText(term);
  if(!normalized)return false;
  if(normalized.includes(" "))return text.includes(normalized);
  return text.split(/\s+/).includes(normalized);
}
function detectThemeFromIdea(idea,activityType="",genreType=""){
  const text=normText(idea);
  if(!text)return "";
  const genreThemes=genreType&&GENRE_TYPES.includes(genreType)?new Set(compatibleThemesForGenre(genreType)):null;
  const candidates=THEME_GROUPS.flatMap(([,items])=>items).filter(theme=>{
    const activityOk=!activityType||compatibleActivityTypes(theme).includes(activityType);
    const genreOk=!genreThemes||genreThemes.has(theme);
    return activityOk&&genreOk;
  });
  const scored=candidates.map(theme=>{
    const name=normText(theme);
    const tokens=name.split(" ").filter(token=>token.length>2);
    let score=textHasTerm(text,name)?12:0;
    for(const token of tokens)if(textHasTerm(text,token))score+=2;
    for(const alias of THEME_ALIASES[theme]||[]){
      const normalized=normText(alias);
      if(textHasTerm(text,normalized))score+=normalized.includes(" ")?8:5;
    }
    return {theme,score};
  }).sort((a,b)=>b.score-a.score);
  return scored[0]?.score>0?scored[0].theme:"";
}
function themeFeatureKey(theme){return `theme.${featureSlug(theme)}`}
function themeCategory(theme){
  return THEME_GROUPS.find(([,items])=>items.includes(theme))?.[0] || "Custom Themes";
}
function compatibleActivityTypes(theme){
  const category=themeCategory(theme);
  const t=String(theme).toLowerCase();
  let allowed=new Set(ACTIVITY_TYPES);
  if(category==="Learning & Everyday Life"){
    if(/alphabet|numbers|shapes|colors|opposites/.test(t)) allowed=new Set(["word-search","coloring","tracing","matching","counting","learning-worksheet"]);
    if(/emotion|friendship|kindness|healthy|safety|routine/.test(t)) allowed=new Set(["word-search","coloring","tracing","matching","learning-worksheet"]);
  }
  if(/human body/.test(t)) allowed=new Set(["word-search","coloring","matching","learning-worksheet"]);
  if(/volcano/.test(t)) allowed.delete("tracing");
  if(/pirates|treasure hunt|camping adventure/.test(t)) allowed.delete("tracing");
  if(/christmas|halloween/.test(t)) allowed.delete("simple-math");
  return [...allowed];
}
function isCompatible(activityType,theme){
  if(!theme)return true;
  return compatibleActivityTypes(theme).includes(activityType);
}
function themeNamesByCategories(categories){
  return THEME_GROUPS.filter(([category])=>categories.includes(category)).flatMap(([,items])=>items);
}
// compatibleThemesForGenre() and compatibleActivitiesForGenre() are independent, flat,
// per-genre filters. Their cross product is NOT guaranteed valid: a theme can appear in
// compatibleThemesForGenre(genre) while still failing compatibleActivityTypes(theme) for
// some activity in compatibleActivitiesForGenre(genre) (e.g. "Cozy Storybook" lists both
// "Pirates" and "tracing", but Pirates itself excludes tracing). Any caller that combines
// these two lists (including /api/catalog consumers) MUST also check
// compatibleActivityTypes(theme).includes(activityType) before treating a theme+activity
// pair as valid for a genre — see isGenreCompatible(), which is always used alongside
// isCompatible(), never alone.
function compatibleThemesForGenre(genreType){
  const g=String(genreType||"Classic Educational").toLowerCase();
  const all=THEME_GROUPS.flatMap(([,items])=>items);
  if(g==="classic educational"||g==="whimsical cartoon")return all;
  if(g==="cinematic adventure")return all.filter(theme=>!/alphabet|numbers|shapes|colors|opposites|healthy habits|daily routines/i.test(theme));
  if(g==="fantasy storybook")return themeNamesByCategories(["Animals","Science & Adventure","Fantasy, Holidays & Transport"]).filter(theme=>!/human body|weather|seasons|plants|volcanoes|solar system/i.test(theme));
  if(g==="documentary style")return themeNamesByCategories(["Animals","Careers & Community","Science & Adventure"]).filter(theme=>!/treasure hunt|camping adventure/i.test(theme));
  if(g==="cozy storybook")return all.filter(theme=>!/human body|volcanoes|police officers|construction workers|robots|cars|trucks|trains|airplanes/i.test(theme));
  if(g==="science explorer")return themeNamesByCategories(["Animals","Careers & Community","Science & Adventure","Learning & Everyday Life"]).filter(theme=>!/chefs|bakers|police|friendship|kindness|daily routines|opposites/i.test(theme));
  if(g==="magical world")return themeNamesByCategories(["Animals","Science & Adventure","Fantasy, Holidays & Transport"]).filter(theme=>!/human body|weather|seasons|plants|volcanoes|solar system|cars|trucks|trains|airplanes/i.test(theme));
  if(g==="realistic classroom")return themeNamesByCategories(["Careers & Community","Science & Adventure","Learning & Everyday Life"]).filter(theme=>!/pirates|treasure hunt|camping adventure|volcanoes/i.test(theme));
  if(g==="vintage workbook")return themeNamesByCategories(["Animals","Careers & Community","Science & Adventure","Learning & Everyday Life"]).filter(theme=>!/camping adventure|treasure hunt|volcanoes/i.test(theme));
  return all;
}
function compatibleActivitiesForGenre(genreType){
  const g=String(genreType||"Classic Educational").toLowerCase();
  if(g==="cinematic adventure")return ["coloring"];
  if(g==="fantasy storybook")return ["coloring","matching"];
  if(g==="documentary style")return ["word-search","coloring","matching","learning-worksheet"];
  if(g==="cozy storybook")return ["coloring","tracing","matching","counting","learning-worksheet"];
  if(g==="science explorer")return ["word-search","coloring","matching","counting","learning-worksheet"];
  if(g==="magical world")return ["coloring","matching","counting"];
  if(g==="realistic classroom")return ["word-search","tracing","matching","counting","learning-worksheet"];
  if(g==="vintage workbook")return ["word-search","tracing","matching","counting","learning-worksheet"];
  return ACTIVITY_TYPES;
}
// Only checks genre-level activity/theme lists. Does NOT check whether the theme itself
// allows the activity (compatibleActivityTypes(theme)/isCompatible()) — callers must AND
// this with isCompatible(activityType, theme) themselves. server.js's validate() already
// does both; app.js's applyFeatureGates() already re-filters by theme.compatibleActivityTypes
// at every dropdown step for the same reason. Do not remove those checks as "redundant".
function isGenreCompatible(activityType,theme,genreType){
  const activities=compatibleActivitiesForGenre(genreType);
  const themes=compatibleThemesForGenre(genreType);
  return activities.includes(activityType)&&themes.includes(theme);
}
function styleFromGenre(genreType){
  const map={
    "Classic Educational":"clean modern educational workbook illustration",
    "Cinematic Adventure":"cinematic children's adventure illustration with dynamic composition",
    "Fantasy Storybook":"whimsical fantasy storybook illustration",
    "Documentary Style":"clear educational documentary-style illustration",
    "Whimsical Cartoon":"cute whimsical cartoon illustration with bold clean shapes",
    "Cozy Storybook":"soft cozy children's storybook illustration",
    "Science Explorer":"bright science explorer educational illustration",
    "Magical World":"magical child-friendly fantasy illustration",
    "Realistic Classroom":"realistic clean classroom worksheet illustration",
    "Vintage Workbook":"vintage educational workbook illustration"
  };
  return map[genreType]||map["Classic Educational"];
}
const THEME_VISUALS={
  animals:"friendly natural habitat, simple plants and environmental details, warm approachable expressions",
  careers:"clear workplace setting, recognizable tools and safe uniforms, positive community-focused action",
  science:"educational exploration setting, simplified accurate scientific objects, wonder and discovery",
  learning:"clean classroom-friendly visual language, familiar everyday objects, clear concept-focused composition",
  fantasy:"whimsical child-safe fantasy world, playful magical details, friendly non-threatening characters",
  transport:"clear travel environment, recognizable vehicles, safe movement and uncluttered composition",
  holiday:"festive child-friendly setting, recognizable seasonal decorations, warm celebratory mood"
};
function themeVisualDirection(theme=""){
  const t=theme.toLowerCase();
  if(/animal|dinosaur|insect|butterfl|bird|pet|ocean|rainforest|arctic|farm|safari|woodland/.test(t))return THEME_VISUALS.animals;
  if(/helper|doctor|nurse|firefighter|police|teacher|school|worker|farmer|chef|baker|scientist|astronaut/.test(t))return THEME_VISUALS.careers;
  if(/space|solar|weather|season|body|plant|garden|volcano|coral|camping|treasure/.test(t))return THEME_VISUALS.science;
  if(/alphabet|number|shape|color|opposite|habit|emotion|feeling|friendship|kindness|safety|routine/.test(t))return THEME_VISUALS.learning;
  if(/unicorn|dragon|castle|fair|magic|pirate|robot/.test(t))return THEME_VISUALS.fantasy;
  if(/car|truck|train|airplane/.test(t))return THEME_VISUALS.transport;
  return THEME_VISUALS.holiday;
}
function promptSceneTheme(theme=""){
  const t=String(theme||"activity").toLowerCase();
  const map=[
    [/scientists?/, "a friendly science laboratory with microscopes, beakers, plants, safety goggles, blank notebooks, and curious young researchers"],
    [/police officers?/, "a friendly community safety scene with helpful officers, traffic cones, a patrol car, a crosswalk, and neighborhood helpers"],
    [/doctors?|nurses?/, "a cheerful clinic scene with child-safe medical tools, caring helpers, a checkup table, and simple health props"],
    [/firefighters?/, "a friendly fire station scene with safety helmets, hoses, a fire truck, boots, and rescue practice props"],
    [/teachers?|school/, "a classroom activity scene with books, backpacks, art supplies, a globe without labels, and smiling learners"],
    [/astronauts?/, "a space explorer scene with astronauts, rockets, planets without labels, stars, control panels without text, and moon rocks"],
    [/ocean|coral|sea/, "an underwater ocean scene with turtles, dolphins, coral, shells, sea plants, bubbles, and friendly fish"],
    [/farm/, "a cheerful farm scene with barns without signs, fences, crops, farm tools, and friendly animals"],
    [/dinosaur/, "a prehistoric nature scene with friendly dinosaurs, large leaves, rocks, volcano shapes, nests, and footprints"],
    [/pets?/, "a cozy pet-care scene with friendly cats, dogs, bowls without labels, toys, cushions, and simple home details"]
  ];
  return map.find(([pattern])=>pattern.test(t))?.[1] || `a detailed child-friendly ${theme} scene with recognizable theme props, charming characters, and simple background details`;
}
function themeElements(theme=""){
  const t=String(theme||"activity").toLowerCase();
  const packs=[
    [/ocean|coral|sea/,["sea turtle","dolphin","clownfish","octopus","seahorse","crab","starfish","whale"],["coral reef","sandy seabed","kelp forest","tide pool","underwater cave"],["shells","bubbles","sea plants","smooth stones","treasure-free chest","waves"]],
    [/safari/,["lion","elephant","giraffe","zebra","rhino","meerkat","cheetah","hippo"],["savanna grassland","watering hole","acacia grove","safari trail","sunny wildlife park"],["tall grass","binoculars","jeep without logos","rocks","bushes","clouds"]],
    [/woodland/,["deer","fox","owl","rabbit","squirrel","hedgehog","raccoon","songbird"],["quiet forest","mushroom grove","leafy trail","hollow log clearing","acorn meadow"],["acorns","mushrooms","fallen leaves","tree stumps","ferns","berries"]],
    [/rainforest/,["monkey","toucan","jaguar","tree frog","sloth","parrot","butterfly","tapir"],["tropical canopy","vine-covered path","rainforest river","giant leaf garden","waterfall clearing"],["vines","ferns","big leaves","orchids","fruit","raindrops"]],
    [/arctic/,["polar bear","penguin","seal","arctic fox","snowy owl","walrus","orca","reindeer"],["snowy ice field","igloo village without signs","frozen shore","aurora sky","iceberg scene"],["snowflakes","ice blocks","mittens","fish","pine trees","sled tracks"]],
    [/dinosaur/,["triceratops","stegosaurus","brachiosaurus","t-rex","ankylosaurus","parasaurolophus","baby dinosaur","pteranodon"],["prehistoric valley","fern forest","volcano landscape","dinosaur nest","rocky river"],["fossils","giant leaves","eggs","rocks","footprints","clouds"]],
    [/insect|butterfl/,["butterfly","bee","ladybug","dragonfly","caterpillar","ant","beetle","grasshopper"],["flower garden","leafy meadow","bug hotel","pond edge","vegetable patch"],["flowers","leaves","honeycomb","mushrooms","dew drops","stems"]],
    [/bird/,["owl","parrot","sparrow","eagle","duck","flamingo","peacock","robin"],["tree branch","nest scene","bird garden","pond shore","forest clearing"],["feathers","eggs","leaves","berries","clouds","flowers"]],
    [/pet/,["puppy","kitten","hamster","rabbit","goldfish","parakeet","turtle","guinea pig"],["cozy pet room","backyard play area","pet care corner","sunny window spot","garden path"],["toys","blank bowls","cushions","paw prints","brushes","blank tags"]],
    [/community helper/,["mail carrier","librarian","crossing guard","bus driver","sanitation worker","park worker","shop helper","community volunteer"],["friendly neighborhood","library corner","crosswalk","bus stop","park path"],["bags without logos","books without text","cones","benches","trees","recycling bins without labels"]],
    [/doctor|nurse/,["doctor","nurse","patient child","clinic helper","dentist","paramedic","care team","health teacher"],["cheerful clinic","checkup room","health corner","waiting area","medical station"],["stethoscope","bandage","blank chart","toy bear","sink","first-aid box without labels"]],
    [/firefighter/,["firefighter","fire truck","rescue dog","helmeted helper","ladder team","hose team","station crew","safety teacher"],["fire station","training yard","truck bay","safe rescue practice scene","neighborhood safety day"],["hose","helmet","boots","ladder","hydrant","cones"]],
    [/police/,["police officer","crossing guard","community helper","patrol car","bike officer","safety team","friendly officer","traffic helper"],["crosswalk","community park","neighborhood street","school safety zone","traffic safety corner"],["cones","badge shapes without text","walkie-talkie","traffic lights","bicycle","blank notebook"]],
    [/teacher|school/,["teacher","student group","reader child","art student","science student","class helper","music student","librarian"],["classroom","reading corner","art table","school garden","library nook"],["books without text","pencils","backpacks","blank board","globe without labels","crayons"]],
    [/construction/,["builder","crane operator","architect child","toolbox helper","dump truck","bulldozer","bricklayer","safety worker"],["construction site","tool shed","road work zone","building frame","materials yard"],["helmet","cones","bricks","tools","crane","wood planks"]],
    [/farmer/,["farmer","tractor driver","garden helper","barn worker","crop picker","animal caretaker","market helper","watering helper"],["crop field","barnyard","vegetable garden","orchard","farm market table"],["tractor","watering can","baskets","hay","fence","blank crates"]],
    [/chef|baker/,["chef","baker","kitchen helper","pastry maker","soup cook","bread maker","cake decorator","apron child"],["cozy kitchen","bakery counter","mixing table","oven corner","picnic prep table"],["mixing bowl","spoon","rolling pin","bread","cupcakes","blank recipe card"]],
    [/scientist/,["young scientist","microscope explorer","plant researcher","crystal observer","lab helper","telescope student","experiment team","goggle-wearing child"],["science laboratory","classroom lab","plant table","crystal station","observation desk"],["microscope","beakers","blank notebooks","goggles","plant samples","magnifying glass"]],
    [/astronaut|space|solar/,["astronaut","rocket explorer","moon rover","space student","planet observer","satellite helper","alien-free explorer","telescope child"],["moon surface","rocket launch pad","space station room","planet trail","starry sky"],["planets without labels","stars","moon rocks","rocket","control panels without text","helmets"]],
    [/weather|season/,["weather watcher","raincoat child","snow helper","sunny day explorer","windy kite flyer","cloud observer","season tree","umbrella child"],["weather station without labels","park path","seasonal garden","rain puddle scene","snowy yard"],["clouds","raindrops","snowflakes","leaves","sun shapes","umbrellas"]],
    [/human body|healthy|safety|routine|habit/,["healthy child","exercise helper","handwashing child","sleepy bedtime helper","safety watcher","toothbrushing child","snack helper","routine chart without text"],["bathroom sink","playground","kitchen table","bedroom corner","clinic classroom"],["toothbrush","soap bubbles","fruit","water bottle","sneakers","blank checklist"]],
    [/plant|garden/,["gardener child","flower helper","seed planter","watering helper","butterfly visitor","vegetable picker","tree planter","sprout observer"],["flower garden","vegetable patch","greenhouse","orchard","potting table"],["watering can","seed packets without text","leaves","pots","tools","butterflies"]],
    [/volcano/,["young geologist","volcano explorer","rock collector","safety observer","mountain hiker","fossil finder","lava watcher","science guide"],["volcano landscape","rocky trail","geology table","mountain valley","safe observation hill"],["rocks","crystals","steam clouds","lava shapes","backpack","magnifying glass"]],
    [/camping/,["camper child","tent helper","trail explorer","lantern carrier","backpack kid","nature observer","map helper","campfire sitter"],["forest campsite","tent area","lake trail","mountain camp","woodland clearing"],["tent","lantern","backpack","logs","stars","blank map"]],
    [/treasure/,["adventurer child","map explorer","compass helper","island walker","cave explorer","clue finder","bridge crosser","chest opener"],["island path","jungle trail","safe cave","sandy beach","wooden bridge"],["compass","map without letters","coins","chest","vines","rocks"]],
    [/alphabet/,["letter explorer","classroom helper","book friend","pencil character","reading child","library helper","alphabet blocks without letters","teacher owl"],["reading corner","classroom table","library nook","book garden","learning rug"],["books without text","pencils","blank cards","blocks without letters","stars","backpacks"]],
    [/number/,["counting child","number explorer","math helper","block stacker","abacus friend","counting animals","shape counter","market helper"],["classroom table","counting corner","toy shelf","market basket","learning rug"],["blocks without printed numbers","beads","apples","stars","blank cards","counters"]],
    [/shape/,["shape explorer","circle friend","square builder","triangle climber","pattern helper","block child","art student","shape sorter"],["art table","classroom rug","block city","playroom","pattern garden"],["circles","squares","triangles","stars","blank cards","crayons"]],
    [/color/,["paint helper","rainbow friend","art student","crayon kid","palette explorer","flower painter","butterfly painter","studio helper"],["art studio","flower garden","classroom table","rainbow meadow","craft corner"],["paintbrushes","blank palette","crayons","flowers","butterflies","jars without labels"]],
    [/opposite/,["big and small pair","up and down scene","open and closed helper","day and night pair","fast and slow racers","happy and sad faces","near and far scene","full and empty baskets"],["learning rug","playground","classroom corner","storybook scene","park path"],["blank cards","baskets","balls","blocks","doors without signs","clouds"]],
    [/emotion|friendship|kindness/,["smiling friend","sharing child","helping buddy","kindness helper","feeling face character","comforting friend","teamwork pair","thank-you helper"],["playground","classroom rug","park bench","story corner","garden path"],["hearts without text","toys","flowers","blank cards","benches","books without words"]],
    [/unicorn|rainbow/,["unicorn","rainbow pony","cloud friend","star helper","magical foal","flower crown unicorn","moon unicorn","meadow unicorn"],["rainbow meadow","cloud garden","starry hill","magical forest","flower field"],["stars","clouds","flowers","sparkles","mushrooms","crescent moon"]],
    [/dragon|castle/,["friendly dragon","castle guard","young knight","princess explorer","tower helper","shield bearer","baby dragon","bridge walker"],["castle courtyard","tower room","dragon meadow","stone bridge","royal garden"],["shields without symbols","flags without marks","stones","flowers","treasure-free chest","clouds"]],
    [/fair/,["fairy","forest sprite","mushroom friend","flower fairy","butterfly helper","wand holder","tiny gardener","moon fairy"],["magical forest","mushroom village","flower meadow","fairy garden","glowing pond"],["mushrooms","flowers","wings","stars","leaves","sparkles"]],
    [/pirate/,["pirate child","ship helper","parrot friend","island explorer","sailor kid","treasure map holder","captain child","anchor helper"],["pirate ship","island beach","dock scene","jungle trail","safe cave"],["anchor","ship wheel","map without text","coins","palm trees","sails"]],
    [/robot/,["friendly robot","gear helper","inventor child","robot pet","workshop bot","space robot","cleaning robot","builder bot"],["robot workshop","gear room","space lab","invention table","city sidewalk"],["gears","bolts","buttons without labels","wires","tools","blank panels"]],
    [/car|truck/,["race car","pickup truck","fire truck toy","delivery van","mechanic child","monster truck","tow truck","family car"],["garage","road scene","car wash","traffic park","repair shop"],["wheels","cones","tools","blank signs","road lines","clouds"]],
    [/train/,["steam train","conductor child","passenger car","freight train","station helper","toy train","mountain train","subway-style train"],["train station","railroad track","bridge crossing","mountain railway","platform without signs"],["tracks","wheels","clouds","suitcases","signals without text","trees"]],
    [/airplane/,["airplane","pilot child","airport helper","cloud flyer","hangar mechanic","paper plane friend","helicopter","runway crew"],["runway","airport hangar","cloud sky","control tower without text","travel scene"],["clouds","wings","luggage without labels","cones","tools","stars"]],
    [/christmas/,["holiday tree","gift helper","snow child","stocking friend","gingerbread baker","reindeer","snowman","ornament maker"],["cozy living room","snowy yard","holiday kitchen","tree corner","winter street"],["gifts","ornaments","snowflakes","stockings","cookies","stars"]],
    [/halloween/,["pumpkin friend","costume child","friendly ghost","bat buddy","candy helper","black cat","witch hat character","spooky tree"],["pumpkin patch","costume party","friendly haunted yard","moonlit path","candy table"],["pumpkins","bats","candy","leaves","lanterns","stars"]]
  ];
  const found=packs.find(([pattern])=>pattern.test(t));
  if(found)return {subjects:found[1],settings:found[2],props:found[3]};
  return {subjects:[`${theme} explorer`,`${theme} helper`,`${theme} friend`,`${theme} scene`,`${theme} character`],settings:[promptSceneTheme(theme),"activity corner","storybook setting","playful learning scene","outdoor scene"],props:["simple props","background details","decorative shapes","open spaces","friendly objects","nature details"]};
}
function buildScenePoolFromElements(elements){
  return Array.from({length:25},(_,index)=>{
    const subject=elements.subjects[index%elements.subjects.length];
    const setting=elements.settings[Math.floor(index/elements.subjects.length)%elements.settings.length];
    const p1=elements.props[index%elements.props.length];
    const p2=elements.props[(index+2)%elements.props.length];
    const p3=elements.props[(index+4)%elements.props.length];
    return `${subject} in a ${setting} with ${p1}, ${p2}, ${p3}, clear foreground shapes, and child-friendly background details`;
  });
}
function themeScenePool(theme=""){
  const t=String(theme||"activity").toLowerCase();
  if(/farm/.test(t))return [
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
    "a spring farm scene with baby animals, flowers, butterflies, fresh grass, and a welcoming barn gate"
  ];
  return buildScenePoolFromElements(themeElements(theme));
}
function sceneTitle(theme,scene,pageNumber){
  const t=String(theme||"Activity");
  if(/farm/i.test(t)){
    const names=[
      "Cow at the Barn","Sheep in the Pasture","Pig in the Straw Pen","Chicken Coop Friends","Horse at the Stable",
      "Ducks at the Pond","Goat by the Fence","Farm Dog Helper","Cat in the Hay","Donkey on the Farm Path",
      "Tractor and Hay Bales","Farm Market Basket","Baby Calf and Mother","Jumping Lamb","Playful Piglets",
      "Rooster Morning","Pony Paddock","Geese by the Pond","Goat Play Yard","Feeding Time",
      "Inside the Barn","Garden by the Barn","Farmyard Parade","Nighttime Barn","Spring Baby Animals"
    ];
    return `${t}: ${names[(pageNumber-1)%names.length]}`;
  }
  const titleWords=String(scene||"").split(/\s+/).filter(word=>!/^(a|an|the|with|and|in|on|near|beside|inside|around|of|to|for|its|clear|foreground|shapes|child-friendly|background|details)$/i.test(word)).slice(0,7).join(" ");
  return `${t}: ${titleWords.replace(/[^\w\s-]/g,"").replace(/\b\w/g,c=>c.toUpperCase())}`;
}

module.exports = {
  THEME_GROUPS, THEME_ALIASES, ACTIVITY_TYPES,
  GENRE_TYPES, WORD_SEARCH_MODE_TYPES,
  featureSlug, normText, textHasTerm, detectThemeFromIdea, themeFeatureKey, themeCategory,
  compatibleActivityTypes, isCompatible, themeNamesByCategories, compatibleThemesForGenre,
  compatibleActivitiesForGenre, isGenreCompatible, styleFromGenre,
  themeVisualDirection, promptSceneTheme, themeElements, buildScenePoolFromElements,
  themeScenePool, sceneTitle
};
