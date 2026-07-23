const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const crypto = require("node:crypto");

const ROOT = __dirname;
const PORT = Number(process.env.PORT || 4180);
const OLLAMA_URL = process.env.OLLAMA_URL || "http://127.0.0.1:11434";
const MODEL = process.env.OLLAMA_MODEL || "gemma3:4b";
const USE_OLLAMA_GENERATION = process.env.USE_OLLAMA_GENERATION !== "0";

const { db } = require("./lib/db");
const {
  THEME_GROUPS, GENRE_TYPES, ACTIVITY_TYPES, WORD_SEARCH_MODE_TYPES,
  detectThemeFromIdea, themeFeatureKey, compatibleActivityTypes, isCompatible,
  compatibleThemesForGenre, compatibleActivitiesForGenre, isGenreCompatible, styleFromGenre,
  themeVisualDirection, promptSceneTheme, themeElements, themeScenePool, sceneTitle
} = require("./lib/theme");
const { buildWordSearchPuzzle, wordBank } = require("./lib/generators/word-search");
const {
  ADMIN_TOKEN, adminAllowed, clientToken, userWithPlanByToken, resetPeriodIfNeeded,
  usageForUser, planFeatureKeys, requiredFeatureKeys, requireUserAccess, recordUsage,
  publicUser, allUsers
} = require("./lib/access");

function token(prefix="bb") {
  return `${prefix}_${crypto.randomBytes(18).toString("base64url")}`;
}

function json(res, status, data) {
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" });
  res.end(JSON.stringify(data));
}
function abortError(message="Generation was stopped.") {
  const err = new Error(message);
  err.name = "AbortError";
  return err;
}
async function body(req) {
  const chunks=[]; let size=0;
  for await(const chunk of req){size+=chunk.length;if(size>1e6)throw new Error("The request is too large.");chunks.push(chunk)}
  return chunks.length?JSON.parse(Buffer.concat(chunks).toString("utf8")):{};
}
async function ollamaReady() {
  try {
    const r = await fetch(`${OLLAMA_URL}/api/tags`, { signal: AbortSignal.timeout(2500) });
    if (!r.ok) return false;
    const data = await r.json();
    return data.models?.some(x => x.name === MODEL || x.model === MODEL) || false;
  } catch { return false; }
}
function schema(count) {
  return {
    type:"object",additionalProperties:false,
    required:["book_title","subtitle","description","cover_prompt","keywords","pages"],
    properties:{
      book_title:{type:"string"},subtitle:{type:"string"},description:{type:"string"},
      cover_prompt:{type:"string"},
      keywords:{type:"array",minItems:5,maxItems:8,items:{type:"string"}},
      pages:{type:"array",minItems:count,maxItems:count,items:{
        type:"object",additionalProperties:false,
        required:["page_number","activity_type","title","instruction","learning_goal","content_items","image_prompt","answer"],
        properties:{
          page_number:{type:"integer"},activity_type:{type:"string"},title:{type:"string"},
          instruction:{type:"string"},learning_goal:{type:"string"},
          content_items:{type:"array",minItems:1,maxItems:24,items:{type:"string"}},
          image_prompt:{type:"string"},answer:{type:"string"}
        }
      }}
    }
  };
}
const PRODUCT_RULES={
  "coloring":`COLORING BOOK CONTRACT
- One clear focal scene per page with 1-4 large subjects.
- Use bold black outlines, large closed shapes, generous white space, and low detail appropriate to the age group.
- The image prompt must explicitly request black-and-white line art only and prohibit color, gray fill, shading, text, borders, and cropped subjects.`,
  "word-search":`WORD SEARCH CONTRACT
- Each page is a real printable word-search puzzle, not an illustration prompt pretending to be a puzzle.
- Each page needs one unique theme subtopic and exactly 10 age-appropriate uppercase words, 3-10 letters each, no spaces, no punctuation.
- content_items must include "WORD LIST: ..." and exactly 12 "GRID ROW NN: ..." entries. Every grid row must be 12 uppercase letters with spaces between letters.
- The answer must list every hidden word with row, column, and direction using H, V, or D. Example: COW: row 2, col 4, direction H.
- Every puzzle must include a mix of directions: at least 3 horizontal, at least 3 vertical, and at least 2 diagonal words.
- The image_prompt must NOT ask an image model to draw the word grid, letters, words, typography, or answer key. It should only describe a printable worksheet frame: small themed border decorations, title-safe area, and one large blank central rectangle where the generated grid will be placed later by layout software.`,
  "educational-story":`EDUCATIONAL STORY CONTRACT
- Build one connected story arc across all prompts: introduction, small challenge, attempts, resolution, and takeaway.
- Keep the same recurring character design, clothing, colors, and personality on every page.
- Each page is one concrete scene, advances the story, and teaches one gentle age-appropriate lesson.
- The image prompt must restate the complete character lock whenever the recurring character appears.`,
  "tracing":`TRACING & HANDWRITING CONTRACT
- State the exact strokes, letters, numbers, or words to trace.
- Progress gradually from guided examples to independent practice.
- Request thick dotted tracing guides, clear baselines, large spacing, and minimal decoration.`,
  "matching":`MATCHING CONTRACT
- Include 4-8 exact pairs, with left and right columns deliberately shuffled.
- content_items must define every pair and the displayed order. The answer must repeat all correct matches.
- Keep the center area open for children to draw connecting lines.`,
  "counting":`COUNTING CONTRACT
- State exact object quantities in content_items and keep every object fully visible and easy to distinguish.
- Use age-appropriate number ranges and vary the scene without creating ambiguous overlaps.
- The answer must give the exact count.`,
  "simple-math":`MATH PRACTICE CONTRACT
- Include exact operands, operation symbols, and one unambiguous answer for every problem.
- Match number size and operation difficulty to the age group.
- Use visual manipulatives only when their quantities are explicitly defined.`,
  "spot-difference":`SPOT THE DIFFERENCE CONTRACT
- Define two nearly identical scenes and exactly 5-8 concrete visual differences.
- content_items must list every difference precisely; the answer must repeat the complete list.
- Keep composition, character placement, and camera angle identical between the two panels.`,
  "puzzle":`CHILDREN'S PUZZLE CONTRACT
- Name the exact puzzle mechanic and fully specify all clues, choices, and solution.
- Use only one puzzle mechanic per prompt.
- Avoid puzzles that depend on details not defined in content_items.`,
  "learning-worksheet":`EDUCATIONAL WORKSHEET CONTRACT
- Each page focuses on one measurable learning objective and one clear task.
- Include all questions, choices, examples, and answers explicitly.
- Use a clean classroom worksheet layout with strong visual hierarchy and ample writing space.`
};
const THEME_VISUALS={
  animals:"friendly natural habitat, simple plants and environmental details, warm approachable expressions",
  careers:"clear workplace setting, recognizable tools and safe uniforms, positive community-focused action",
  science:"educational exploration setting, simplified accurate scientific objects, wonder and discovery",
  learning:"clean classroom-friendly visual language, familiar everyday objects, clear concept-focused composition",
  fantasy:"whimsical child-safe fantasy world, playful magical details, friendly non-threatening characters",
  transport:"clear travel environment, recognizable vehicles, safe movement and uncluttered composition",
  holiday:"festive child-friendly setting, recognizable seasonal decorations, warm celebratory mood"
};
function productRules(type){return PRODUCT_RULES[type]||PRODUCT_RULES["learning-worksheet"]}
function visualContract(input){
  input.size = "A4";
  const avoid=String(input.avoidTerms||"").trim();
  const characterLock=input.guideCharacter
    ? `Recurring character lock: ${input.guideCharacter}; keep the same species/person, age, face, body proportions, clothing, colors, accessories, and personality across every prompt`
    : "Character consistency lock: whenever a character recurs, repeat the same species/person, age, face, body proportions, clothing, colors, and accessories";
  const userAvoid=avoid ? `, avoid these user-specified elements: ${avoid}` : "";
  return {
    styleAnchor:`${input.style}, consistent child-friendly visual language`,
    themeDirection:themeVisualDirection(input.theme||input.topic),
    characterLock,
    layoutLock:`one standalone A4 portrait printable page, clear focal hierarchy, clean margins, safe trim area, no cropped important objects`,
    negativeLock:input.activityType==="coloring"
      ? `black-and-white line art only, no color, no grayscale, no shading, no gradients, no shadows, no textures, no title, no words, no letters, no numbers, no labels, no captions, no signage, no watermark, no logo, no border, no photorealism, no 3D render${userAvoid}`
      : `no watermark, no logo, no brand characters, no photorealism, no 3D render, no malformed anatomy, no clutter, no cropped important objects, no illegible embedded text${userAvoid}`
  };
}
function lockImagePrompt(prompt,input){
  const c=visualContract(input);
  let scene=String(prompt||"").trim().replace(/[.\s]+$/,"");
  if(input.activityType==="word-search"){
    return `Create a clean printable word-search worksheet frame for children, vertical A4 portrait composition.

Scene decoration: ${scene}. Use only small ${input.theme || input.topic} themed border illustrations in the corners and margins, with a large blank central rectangle reserved for a word-search grid that will be added later by layout software.

Layout requirements: clear title-safe area at the top, word-list area below or beside the blank grid space, generous margins, simple child-friendly decorative icons, balanced worksheet composition, no busy background behind the puzzle area.

Critical text rule: do not render any letters, words, puzzle grid, answer key, labels, captions, signage, typography, watermark, logo, or random symbols anywhere in the image.

Negative prompt: letters, words, text, typography, alphabet, numbers, grid letters, word search grid, answer key, labels, captions, signs, watermark, logo, clutter, cropped layout, photorealism, 3D render.`;
  }
  if(input.activityType==="coloring"){
    scene=scene
      .replace(/\bvibrant\b/gi,"lively")
      .replace(/\bcolorful\b/gi,"varied")
      .replace(/\bfull[- ]color\b/gi,"black-and-white")
      .replace(/\bbrightly colored\b/gi,"clearly differentiated");
  }
  const opening=input.activityType==="coloring"
    ? "Create a detailed black-and-white coloring book page for children, vertical A4 portrait composition."
    : `Create a detailed ${input.genreType || "children's educational"} image prompt for children, vertical A4 portrait composition.`;
  return `${opening}\n\nScene: ${scene}.\n\nComposition and details: include clear foreground, middle ground, and background; expressive child-friendly characters or objects; readable silhouettes; balanced full-page layout; rich theme-specific props and decorative details; ${c.themeDirection}.\n\nStyle: ${c.styleAnchor}; ${c.layoutLock}; ${c.characterLock}.\n\nNegative prompt: ${c.negativeLock}.`;
}
function lockCoverPrompt(prompt,input){
  const c=visualContract(input);
  let scene=String(prompt||"").trim()
    .replace(/[.\s]+$/,"")
    .replace(/\bblack-and-white\b/gi,"full-color")
    .replace(/\bblack and white\b/gi,"full-color")
    .replace(/\bline art only\b/gi,"polished full-color illustration")
    .replace(/\bno color\b/gi,"rich color")
    .replace(/\bno shading\b/gi,"soft professional shading")
    .replace(/\bno grayscale\b/gi,"full-color palette");
  const palette=input.activityType==="coloring"
    ? "bright cheerful children's book cover palette, warm inviting colors, colorful title-safe background"
    : "rich professional color palette matched to the selected genre";
  return `Create a premium full-color children's book cover, vertical 2:3 composition.\n\nScene: ${scene}.\n\nCover design: clear central focal character or object, strong readable silhouette, polished publishing layout, title-safe space in the upper-middle, subtitle-safe space below the title, author-name safe space at the bottom, balanced foreground and background, ornate but readable framing, rich theme-specific props and decorative details, ${c.themeDirection}.\n\nColor and mood: ${palette}, cinematic lighting where appropriate, soft depth, magical but child-friendly atmosphere, professional illustrated book cover finish.\n\nStyle: ${input.style}, consistent child-friendly visual language, premium cover art, high-resolution, no cropped important objects.\n\nNegative prompt: no watermark, no logo, no brand characters, no photorealism, no 3D render, no malformed anatomy, no cluttered typography, no illegible random text.`;
}
function titleCase(text=""){
  return String(text).toLowerCase().replace(/\b\w/g,char=>char.toUpperCase()).replace(/\s+/g," ").trim();
}
function listingNiche(input){
  const idea=String(input.bookIdea||"").replace(/^(a|an|the)\s+/i,"").replace(/\s+/g," ").trim();
  const theme=input.theme==="Custom Idea" ? String(input.topic||"Activity").trim() : String(input.theme||input.topic||"Activity").trim();
  return titleCase(idea||theme||"Activity");
}
function activityBookLabel(type){
  return ({
    "coloring":"Coloring Book",
    "word-search":"Word Search Book",
    "tracing":"Tracing Practice Book",
    "matching":"Matching Activity Book",
    "counting":"Number Practice Book",
    "learning-worksheet":"Activity Worksheet Pack"
  })[type]||"Activity Book";
}
function ageLabel(age=""){
  const match=String(age).match(/\d+\s*[–-]\s*\d+/);
  return match?`Ages ${match[0].replace(/\s+/g,"")}`:String(age||"Kids").replace(/\byears?\b/i,"").trim();
}
function etsyTitleFor(input,{niche,theme,activityLabel,keywords}){
  const mode=String(input.displayGenre||input.genreType||"").replace(/Classic Educational/i,"").trim();
  const style=input.activityType==="coloring"
    ? "Bold and Easy"
    : input.activityType==="tracing"
      ? "Handwriting Practice"
      : "Printable Kids Workbook";
  const base=`${niche} ${activityLabel}`;
  const keywordText=keywords
    .map(item=>titleCase(item))
    .filter(item=>item&&!base.toLowerCase().includes(item.toLowerCase()))
    .slice(0,3)
    .join(", ");
  return [`${base} PDF`,keywordText||`${theme} Activity Pages`,mode||style,"Printable Kids Pages","Digital Download"]
    .filter(Boolean).join(", ").replace(/\s+/g," ").slice(0,140);
}
function ensurePublishingKit(book,input){
  const theme=String(input.theme||input.topic||"Activity Book");
  const activity=String(input.activityType||"activity").replace(/-/g," ");
  const niche=listingNiche(input);
  const activityLabel=activityBookLabel(input.activityType);
  const age=ageLabel(input.age);
  const title=String(book.book_title||`${niche} ${activityLabel}`).replace(/\s+Kit$/i,"").slice(0,70);
  const subtitle=String(book.subtitle||`${activityLabel} pages for ${input.age}`).replace(/\s+kit\b/ig,"").slice(0,120);
  const kdpTitle=`${niche} ${activityLabel} for Kids ${age}`.replace(/\s+/g," ").slice(0,180);
  const kdpSubtitle=`Fun ${theme} activity pages for ${input.age} with clear prompts, answer guidance, and publishing-ready planning`.slice(0,200);
  const keywords=(Array.isArray(book.keywords)&&book.keywords.length?book.keywords:[theme,`${theme} activity book`,`${activity} book`,`${input.age} activities`]).slice(0,8);
  const etsyTitle=etsyTitleFor(input,{niche,theme,activityLabel,keywords});
  if(/\bkit$/i.test(String(book.book_title||"")))book.book_title=title;
  const listingDefaults={
    kdp_title:kdpTitle,
    kdp_subtitle:kdpSubtitle,
    kdp_description:`${kdpTitle} is a themed ${activityLabel.toLowerCase()} for ${input.age}. It includes structured page ideas, clear instructions, answer guidance where needed, cover direction, keywords, and launch planning notes to help sellers prepare a polished activity book for KDP, Etsy, Gumroad, or classroom marketplaces. Review the pages, create the final artwork, verify print settings, and customize the listing before publishing.`,
    backend_keywords:Array.from({length:7},(_,i)=>keywords[i]||`${theme} printable activity ${i+1}`),
    etsy_title:etsyTitle,
    etsy_tags:[theme,"activity book","printable kids","kids worksheet","kdp interior","etsy printable",activity,"homeschool","classroom","coloring pages","busy book","learning fun","digital download"].slice(0,13),
    short_blurb:`A ${theme} ${activity} kit with page prompts, answer keys, cover direction, and launch-ready marketplace assets.`,
    a_plus_sections:[
      `Show the ${theme} theme and age range at a glance.`,
      "Highlight sample interior pages and the learning benefits.",
      "Explain what buyers receive and how the printable can be used.",
      "Show bundle or series options for repeat buyers."
    ]
  };
  book.listing_assets={...listingDefaults,...(book.listing_assets||{}),kdp_title:kdpTitle,kdp_subtitle:kdpSubtitle};
  if(/\bkit\b/i.test(String(book.listing_assets.kdp_description||"")))book.listing_assets.kdp_description=listingDefaults.kdp_description;
  if(/\bkit\b/i.test(String(book.listing_assets.etsy_title||"")))book.listing_assets.etsy_title=listingDefaults.etsy_title;
  if(!book.quality_check){
    const warnings=[];
    if(!book.cover_prompt)warnings.push("Add or review the cover prompt before publishing.");
    if(!Array.isArray(book.pages)||book.pages.length!==input.pageCount)warnings.push("Page count does not match the selected generation size.");
    if(input.activityType==="coloring"&&book.pages?.some(p=>/\bfull[- ]color\b|\btitle-safe\b|\btypography\b/i.test(String(p.image_prompt||"").split(/Critical text rule:|Negative prompt:/i)[0])))warnings.push("Some coloring page prompts may mention color or typography; review before image generation.");
    book.quality_check={
      score:Math.max(70,100-(warnings.length*8)),
      passed_checks:["Product title and subtitle are present.","Page instructions are structured.","Answer guidance is included where relevant.","Cover direction is included.","Marketplace keywords are available."],
      warnings,
      fix_suggestions:["Review every page before creating final artwork.","Customize the listing copy to match your marketplace and brand.","Check KDP/Etsy trim size, margins, and commercial-use requirements before upload."]
    };
  }
  if(!Array.isArray(book.series_ideas)||!book.series_ideas.length){
    book.series_ideas=[
      `${theme} Beginner Edition for younger learners`,
      `${theme} Advanced Edition with harder ${activity} tasks`,
      `${theme} Holiday Special Edition`,
      `${theme} Large Print Edition`,
      `${theme} Classroom Worksheet Bundle`,
      `${theme} Activity Book Series Volume 2`
    ];
  }
  if(!Array.isArray(book.publishing_checklist)||!book.publishing_checklist.length){
    book.publishing_checklist=[
      "Review every generated page for accuracy and age fit.",
      "Create final artwork from each image prompt.",
      "Check page size, margins, bleed, and gutter before export.",
      "Create or refine the cover with title-safe space.",
      "Verify answer keys and remove ambiguous tasks.",
      "Customize KDP title, subtitle, description, and backend keywords.",
      "Create Etsy tags, preview images, and mockups if selling digitally.",
      "Export final interior as a print-ready PDF only after visual QA.",
      "Publish one product first, then expand into the suggested series."
    ];
  }
  return book;
}
function removePageCountWarnings(book){
  if(!book?.quality_check?.warnings)return book;
  book.quality_check.warnings=book.quality_check.warnings.filter(warning=>!/page count does not match/i.test(String(warning)));
  return book;
}
function buildPrompt(input, startPage, batchCount, previousTitles=[], previousPages=[]) {
  const pagePlan = Array.from({ length: batchCount }, (_, index) => {
    const activityType = input.activityType;
    return `- Prompt ${startPage + index}: activity_type must be exactly "${activityType}"`;
  }).join("\n");
  return `You are an expert educational activity book designer for children.
Create exactly ${batchCount} unique printable activity concepts for prompts ${startPage} through ${startPage + batchCount - 1}.

USER SETTINGS
- Main topic: ${input.topic}
- Selected theme: ${input.theme || input.topic}
- User book idea / niche: ${input.bookIdea || "not provided; infer a strong marketplace-friendly angle from the selected theme"}
- Special direction: ${input.customDirection || "not provided"}
- Exclude / avoid: ${input.avoidTerms || "not provided"}
- Age group: ${input.age}
- Content language: ${input.language}
- Product/activity type: ${input.activityType}
- Type / genre direction: ${input.displayGenre || input.genreType || input.difficulty || "Classic Educational"}
- Word search mode: ${input.activityType==="word-search" ? input.wordSearchMode : "not applicable"}
- Page size: A4 portrait
- Illustration style: ${input.style}
- Learning goal: ${input.learningGoal || "age-appropriate cognitive skills, vocabulary, observation, and problem solving"}
- Guide character: ${input.guideCharacter || "none required"}
- Titles already used in earlier batches: ${previousTitles.length ? previousTitles.join(" | ") : "none"}
- Previous story/page continuity: ${previousPages.length ? previousPages.slice(-3).map(page=>`${page.page_number}. ${page.title}: ${page.instruction}`).join(" | ") : "this is the first batch"}
- Theme visual direction: ${themeVisualDirection(input.theme || input.topic)}

REQUIRED PAGE PLAN
${pagePlan}

PRODUCT-SPECIFIC RULES
${productRules(input.activityType)}

MASTER VISUAL PROMPT CONTRACT
- Write image_prompt like a professional AI image prompt, similar to a Midjourney / Ideogram / ChatGPT image prompt.
- Each image_prompt scene body must be 90-170 words before the app adds final style and negative prompt sections.
- Use this structure inside the scene body: main scene, exact subjects, character actions, facial expressions, clothing/costumes, props, background, decorative elements, composition, and printable layout.
- For coloring pages, image_prompt must specify black-and-white line art subjects and many fun decorative elements, but avoid color words.
- For covers, cover_prompt must be full-color even when the product is a coloring book. It must be premium and book-cover-like: vertical 2:3 cover composition, rich color palette, title-safe space, ornate framing, clear central character or object, professional publishing design.
- If cover_prompt includes typography, describe the text layout area clearly, but do not invent unreadable random text.

RULES
1. All visible titles, instructions, content items, answers, description, and keywords must be in ${input.language}.
2. Write natural, fluent, grammatically correct ${input.language}. Never truncate a title or sentence. Keep the book title under 55 characters.
3. Every image_prompt and cover_prompt must be written in English for an image generation model.
4. Follow the REQUIRED PAGE PLAN exactly. This is a single-format product: every page must use the selected activity type. Do not combine, rename, replace, or invent activity types.
5. Set page_number to the exact prompt number shown in the REQUIRED PAGE PLAN.
6. Activities must be safe, factual, age-appropriate, internally consistent, and realistically printable on A4 portrait pages.
7. Give concrete content_items that fully define the page. Do not rely on information that is not included in content_items or image_prompt.
8. Answers must be exact and unambiguous. Never write "depends on the image", "depending on the task", or similar uncertainty.
9. For counting and math, state exact quantities in content_items and give the exact numeric answer.
10. For matching, list each exact pair in content_items and repeat the correct pairs in answer.
11. For word searches, provide the complete word list in content_items and repeat the exact list in the answer.
12. For coloring or creative pages, the answer should say that multiple valid color choices are accepted while noting any learning requirement.
13. For educational-story pages, create one connected story across the book. Each page must contain a short scene, an age-appropriate lesson, a concrete illustration prompt, and a simple reflection answer or takeaway.
14. For tracing pages, specify the exact letters, words, or strokes to trace. For puzzle pages, fully define the puzzle and its exact solution.
15. Respect the user book idea as a niche direction, but keep every page anchored to the selected theme and activity type.
16. Respect special direction and exclude/avoid constraints unless they conflict with child safety or printable quality.
17. Translate the requested illustration style into English inside image prompts. Do not put non-English style phrases in image prompts.
18. Every image prompt must explicitly describe subjects, action, expression, clothing/costumes if relevant, props, background, composition, printable A4 portrait layout, and the selected type/genre direction.
19. Do not use copyrighted characters, brands, logos, or trademarks.
20. Do not claim that generated images are automatically KDP-ready.
21. Every title and concept must be different from the titles already used in earlier batches.
22. Return only JSON matching the supplied schema.`;
}
async function generateBatch(input,startPage,batchCount,previousTitles,previousPages,abortSignal) {
  if(abortSignal?.aborted)throw abortError();
  const controller = new AbortController();
  const abortHandler=()=>controller.abort();
  abortSignal?.addEventListener("abort",abortHandler,{once:true});
  const timeout = setTimeout(() => controller.abort(), 45000);
  try {
    const response = await fetch(`${OLLAMA_URL}/api/generate`, {
      method:"POST",signal:controller.signal,
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({
        model:MODEL,prompt:buildPrompt(input,startPage,batchCount,previousTitles,previousPages),stream:false,think:false,format:schema(batchCount),
        keep_alive:"15m",options:{temperature:.55,num_ctx:8192,num_predict:7000}
      })
    });
    if(!response.ok)throw new Error(`Ollama ${response.status}: ${(await response.text()).slice(0,300)}`);
    const result=await response.json();
    const book=JSON.parse(result.response);
    book.pages=book.pages.slice(0,batchCount).map((page,index)=>{
      const pageNumber=startPage+index;
      return {
        ...page,
        page_number:pageNumber,
        activity_type:input.activityType,
        image_prompt:lockImagePrompt(page.image_prompt,input)
      };
    });
    book.cover_prompt=lockCoverPrompt(book.cover_prompt,input);
    ensurePublishingKit(book,input);
    if(book.pages.length!==batchCount)throw new Error("The content engine did not create every requested prompt. Please try again.");
    return {book,metrics:{totalDuration:result.total_duration,evalCount:result.eval_count}};
  } finally {
    clearTimeout(timeout);
    abortSignal?.removeEventListener("abort",abortHandler);
  }
}
function fallbackPage(input,pageNumber){
  const theme=input.theme==="Custom Idea" ? (input.topic||input.bookIdea||"Custom Idea") : (input.theme||input.topic||"Activity");
  const activity=String(input.activityType||"activity").replace(/-/g," ");
  const isCustomIdea=input.theme==="Custom Idea";
  const scenePool=isCustomIdea
    ? Array.from({length:25},(_,index)=>`${theme} scene ${index+1} with a clear main subject, niche-specific props, child-friendly details, printable white space, and a fresh page concept`)
    : themeScenePool(theme);
  const baseScene=scenePool[(pageNumber-1)%scenePool.length];
  const idea=String(input.bookIdea||"").trim();
  const direction=String(input.customDirection||"").trim();
  const avoid=String(input.avoidTerms||"").trim();
  const avoidLine=avoid?` Also avoid these user-specified elements: ${avoid}.`:"";
  const sceneSeed=[baseScene,idea?`niche angle: ${idea}`:"",direction?`special direction: ${direction}`:""].filter(Boolean).join("; ");
  const title=sceneTitle(theme,baseScene,pageNumber);
  const coloringPrompt=`Create a premium black-and-white coloring book illustration for children, vertical A4 portrait composition.\n\nScene: ${sceneSeed}. Make the page feel like a polished commercial coloring book interior, not a worksheet and not a poster. Use one clear focal scene with balanced composition, charming child-safe characters or objects, expressive faces where relevant, recognizable props, and plenty of fun details for coloring.${avoidLine}\n\nLine art requirements: crisp clean black outlines, smooth confident strokes, closed shapes, large colorable areas, moderate detail, uncluttered spacing, white background, no filled black areas except tiny pupils if needed, no gray shading, no crosshatching, no gradients, no textures, no screen tones.\n\nCritical text rule: do not include any title, heading, caption, label, signage, alphabet letters, numbers, speech bubbles, random symbols, or readable/unreadable text anywhere in the image.\n\nNegative prompt: text, words, letters, numbers, typography, title, subtitle, captions, labels, signs, watermark, logo, border, frame, color, grayscale, shading, gradients, shadows, photorealism, 3D render, messy anatomy, extra fingers, cropped subjects, clutter${avoid?`, ${avoid}`:""}.`;
  const commonPrompt=input.activityType==="coloring"
    ? coloringPrompt
    : `Create a clean ${input.style || "children's educational workbook illustration"} page for children, vertical A4 portrait composition. Scene: ${theme} ${activity} page ${pageNumber}; ${sceneSeed}. Include clear child-friendly subjects, balanced spacing, safe margins, readable silhouettes, and printable layout. Include theme-specific props and simple visual hierarchy. Avoid random text, fake labels, watermarks, logos, clutter, cropped important objects${avoid?`, ${avoid}`:""}.`;
  const base={page_number:pageNumber,activity_type:input.activityType,title,instruction:`Color the ${theme.toLowerCase()} scene with care and notice the small details.`,learning_goal:"Observation, vocabulary, focus, and age-appropriate problem solving.",content_items:[sceneSeed,`${activity} task`,`${input.age} friendly layout`],image_prompt:commonPrompt,answer:"Answers may vary when the page is creative; review the finished artwork for clarity."};
  if(input.activityType==="word-search"){
    const puzzle=buildWordSearchPuzzle(theme,pageNumber,input);
    const imagePrompt=`Create a clean printable word-search worksheet frame for children, vertical A4 portrait composition. Use small ${theme} themed border decorations in the corners and margins, with a large blank central rectangle reserved for a 12 by 12 word-search grid that will be added later by layout software. Include a small blank word-list area below the grid, generous white space, simple child-friendly icons, and a polished workbook feel. Do not render any letters, words, puzzle grid, answer key, labels, captions, signage, typography, watermark, logo, or random symbols anywhere in the image.`;
    return {
      ...base,
      title:`${theme}: ${puzzle.mode} ${pageNumber}`,
      instruction:puzzle.mode==="Easy Horizontal Only"
        ? `Find the 10 hidden ${theme.toLowerCase()} words in the 12 by 12 grid. Words go across only.`
        : `Find the 10 hidden ${theme.toLowerCase()} words in the 12 by 12 grid. Words may go across, down, or diagonal.`,
      learning_goal:"Theme vocabulary, visual scanning, spelling, and focus.",
      content_items:[`WORD SEARCH MODE: ${puzzle.mode}`,`WORD LIST: ${puzzle.words.join(", ")}`,...puzzle.rows.map((row,index)=>`GRID ROW ${String(index+1).padStart(2,"0")}: ${row}`)],
      image_prompt:imagePrompt,
      answer:`ANSWER KEY: ${puzzle.answers.join("; ")}.`
    };
  }
  if(input.activityType==="matching"){
    const words=[...new Set(wordBank(theme))].slice(pageNumber%5,pageNumber%5+6);
    const pairs=words.map(word=>`${word} -> ${word.toLowerCase()} picture`);
    const right=[...words].reverse().map(word=>`${word.toLowerCase()} picture`);
    return {...base,title:`${theme}: Matching Set ${pageNumber}`,instruction:`Draw a line from each ${theme.toLowerCase()} word to its matching picture.`,learning_goal:"Theme vocabulary, visual discrimination, and matching skills.",content_items:[`LEFT COLUMN: ${words.join(", ")}`,`RIGHT COLUMN DISPLAY ORDER: ${right.join(", ")}`,`PAIRS: ${pairs.join("; ")}`],image_prompt:`Create a clean printable matching worksheet frame for children, vertical A4 portrait composition. Use small ${theme} themed decorative icons around the margins and leave two large blank columns for text and picture cards that will be added later by layout software. Keep the center open for connecting lines. Do not render words, letters, labels, numbers, answer keys, watermark, logo, or random symbols.`,answer:`Correct matches: ${pairs.join("; ")}.`};
  }
  if(input.activityType==="counting"){
    const words=[...new Set(wordBank(theme))];
    const item=words[(pageNumber-1)%words.length]||"OBJECT";
    const qty=3+((pageNumber-1)%8);
    return {...base,title:`${theme}: Count ${item} ${pageNumber}`,instruction:`Count the ${item.toLowerCase()} objects and write the number.`,learning_goal:"Counting accuracy, one-to-one correspondence, and theme vocabulary.",content_items:[`COUNTING OBJECT: ${item}`,`EXACT QUANTITY: ${qty}`,`DISPLAY RULE: show ${qty} separate, fully visible ${item.toLowerCase()} objects with no overlaps`],image_prompt:`Create a clean printable counting worksheet scene for children, vertical A4 portrait composition. Show exactly ${qty} separate, fully visible ${item.toLowerCase()} objects in a simple ${theme} setting, with generous spacing and one blank answer box. Use child-friendly line art or workbook illustration styling. Do not render numerals, written labels, captions, watermark, logo, or random text.`,answer:`Answer: ${qty}.`};
  }
  if(input.activityType==="simple-math"){
    const a=2+((pageNumber*2)%9),b=1+(pageNumber%7);
    const op=pageNumber%3===0?"-":"+";
    const left=op==="-"?Math.max(a,b):a;
    const right=op==="-"?Math.min(a,b):b;
    const result=op==="+"?left+right:left-right;
    return {...base,title:`${theme}: Math Practice ${pageNumber}`,instruction:`Solve the ${theme.toLowerCase()} math problem, then check your answer.`,learning_goal:"Basic arithmetic, number sense, and problem solving.",content_items:[`PROBLEM: ${left} ${op} ${right} = ____`,`VISUAL MANIPULATIVES: ${left} ${theme.toLowerCase()} counters and ${right} more/removed counters`,`OPERATION: ${op==="+"?"addition":"subtraction"}`],image_prompt:`Create a clean printable math worksheet frame for children, vertical A4 portrait composition. Use small ${theme} themed counters and simple decorative margin elements, with a large blank problem area and answer box that will be filled by layout software. Do not render arithmetic symbols, numerals, letters, labels, captions, watermark, logo, or random text.`,answer:`Answer: ${result}.`};
  }
  if(input.activityType==="spot-difference"){
    const differences=["one extra cloud","missing small flower","different tail position","one object turned sideways","extra pebble near the path","different window shape"];
    return {...base,title:`${theme}: Spot Differences ${pageNumber}`,instruction:`Look at the two ${theme.toLowerCase()} scenes and find all 6 differences.`,learning_goal:"Observation, attention to detail, comparison, and visual memory.",content_items:[`PANEL A: ${baseScene}`,`PANEL B: same scene with exactly these differences`,...differences.map((item,index)=>`DIFFERENCE ${index+1}: ${item}`)],image_prompt:`Create a printable spot-the-difference worksheet layout for children, vertical A4 portrait composition. Show two side-by-side ${theme} scene panels with identical camera angle, matching character placement, and clear simple details. Include exactly these visual changes between panels: ${differences.join(", ")}. Do not render labels, captions, letters, numbers, watermark, logo, or random text.`,answer:`Differences: ${differences.join("; ")}.`};
  }
  if(input.activityType==="puzzle"){
    const words=[...new Set(wordBank(theme))].slice(0,4);
    const oddChoices=["PENCIL","SHOE","CHAIR","BUTTON","UMBRELLA"];
    const odd=oddChoices[(pageNumber-1)%oddChoices.length];
    return {...base,title:`${theme}: Odd One Out ${pageNumber}`,instruction:`Circle the item that does not belong, then explain why.`,learning_goal:"Classification, reasoning, theme vocabulary, and critical thinking.",content_items:[`PUZZLE MECHANIC: Odd one out`,`CHOICES: ${words.join(", ")}, ${odd}`,`CORRECT ANSWER: ${odd}`,`REASON: the other choices are ${theme.toLowerCase()} vocabulary items, while ${odd.toLowerCase()} is not part of this theme set`],image_prompt:`Create a clean printable children's puzzle worksheet frame, vertical A4 portrait composition. Use small ${theme} themed border decorations and leave four blank choice cards plus one answer circle area for layout software. Keep the composition simple and uncluttered. Do not render words, letters, numbers, labels, captions, watermark, logo, or random symbols.`,answer:`Answer: ${odd} is the odd one out.`};
  }
  if(input.activityType==="learning-worksheet"){
    const words=[...new Set(wordBank(theme))].slice(0,3);
    return {...base,title:`${theme}: Worksheet ${pageNumber}`,instruction:`Complete the ${theme.toLowerCase()} vocabulary activities.`,learning_goal:"Vocabulary recognition, categorization, early writing, and comprehension.",content_items:[`TASK 1: Circle the ${words[0]} picture`,`TASK 2: Match ${words[1]} to its picture`,`TASK 3: Draw one ${words[2]} in the blank box`,`ANSWER 1: ${words[0]}`,`ANSWER 2: ${words[1]} matches its picture`,`ANSWER 3: drawing should clearly show ${words[2]}`],image_prompt:`Create a clean printable educational worksheet frame for children, vertical A4 portrait composition. Use small ${theme} themed decorations around the margins, three clear blank task sections, one drawing box, and generous writing space. Do not render exact words, letters, answers, labels, captions, watermark, logo, or random text.`,answer:`Answers: ${words[0]}; ${words[1]} matches its picture; drawing should show ${words[2]}.`};
  }
  if(input.activityType==="educational-story"){
    return {...base,title:`${theme}: Story Scene ${pageNumber}`,instruction:`Read the short scene and discuss the gentle lesson.`,learning_goal:"Reading comprehension, sequencing, empathy, and theme vocabulary.",content_items:[`STORY SCENE: A friendly guide explores ${baseScene}`,`PLOT ROLE: ${pageNumber===1?"opening":pageNumber<input.pageCount?"middle adventure":"gentle conclusion"}`,`TAKEAWAY: notice details, ask questions, and help a friend`],image_prompt:`Create a warm children's storybook illustration, vertical A4 portrait composition. Scene: a friendly recurring child guide explores ${baseScene}. Keep expressions gentle, composition clear, and details age-appropriate. Include rich ${theme} atmosphere, but do not render readable text, labels, signage, watermark, logo, or random symbols.`,answer:"Takeaway: notice details, ask kind questions, and help when a friend needs support."};
  }
  if(input.activityType==="tracing"){
    const words=[...new Set(wordBank(theme))].slice(0,3);
    return {...base,title:`${theme}: Trace Set ${pageNumber}`,instruction:`Trace the ${theme.toLowerCase()} vocabulary words, then write each word once on your own.`,learning_goal:"Letter formation, handwriting confidence, and theme vocabulary.",content_items:[`TRACE WORD 1: ${words[0]}`,`TRACE WORD 2: ${words[1]}`,`TRACE WORD 3: ${words[2]}`,`WRITING SPACE: one blank line after each word`],image_prompt:`Create a clean printable handwriting worksheet frame for children, vertical A4 portrait composition. Use small ${theme} themed decorations around the margins and leave three wide blank tracing rows plus independent writing lines for layout software. Do not render letters, dotted words, labels, captions, watermark, logo, or random text.`,answer:"Tracing is complete when each word is followed on the dotted guide and rewritten clearly on the blank line."};
  }
  return base;
}
function generateFallbackBook(input,reason=""){
  const theme=input.theme==="Custom Idea" ? (input.topic||input.bookIdea||"Custom Idea") : (input.theme||input.topic||"Activity");
  const activity=String(input.activityType||"activity").replace(/-/g," ");
  const idea=String(input.bookIdea||"").trim();
  const pages=Array.from({length:input.pageCount},(_,index)=>fallbackPage(input,index+1));
  const book={
    book_title:`${idea ? idea.replace(/\b\w/g,c=>c.toUpperCase()).slice(0,55) : `${theme} ${activity.replace(/\b\w/g,c=>c.toUpperCase())}`} Kit`,
    subtitle:`Printable ${activity} pages for ${input.age}`,
    description:`A quick product kit for ${idea||`${theme} ${activity} pages`} with instructions, answer guidance, cover direction, listing assets, and a launch checklist.`,
    cover_prompt:lockCoverPrompt(`A polished ${idea||`${theme} ${activity} activity book`} cover with friendly child-safe visuals, clear title-safe space, and marketplace-ready composition`,input),
    keywords:[theme,idea,`${theme} ${activity}`,`${activity} book`,"printable activity","kids workbook","KDP interior","Etsy printable","learning pages"].filter(Boolean),
    pages
  };
  ensurePublishingKit(book,input);
  const promptTexts=pages.map(p=>`${p.title} ${p.instruction} ${p.content_items?.join(" ")} ${p.image_prompt}`.toLowerCase());
  const themeKey=String(input.theme||"").toLowerCase();
  if(/farm/.test(themeKey)){
    const farmTerms=/farm|barn|cow|sheep|pig|chicken|horse|duck|goat|tractor|hay|pasture|stable|coop|pond|fence|calf|lamb|rooster|geese|vegetable|animal/;
    const weakPages=promptTexts.map((text,index)=>farmTerms.test(text)?null:index+1).filter(Boolean);
    if(weakPages.length)book.quality_check.warnings.unshift(`Theme coverage warning: pages ${weakPages.join(", ")} may not clearly reference farm animals.`);
  }
  const duplicateTitles=pages.map(p=>p.title).filter((title,index,arr)=>arr.indexOf(title)!==index);
  if(duplicateTitles.length)book.quality_check.warnings.unshift("Some generated page titles are duplicated; review the series before publishing.");
  const fastMode = /fast product kit mode/i.test(reason);
  book.quality_check.warnings.unshift(fastMode?"Generated with Fast Product Kit mode for immediate output. Enable USE_OLLAMA_GENERATION=1 for slower local AI drafting.":reason?`Generated with the quick fallback because the local model was slow or unavailable: ${reason}`:"Generated with the quick fallback workflow.");
  book.quality_check.score=Math.min(book.quality_check.score,82);
  return {book,metrics:{totalDuration:0,evalCount:0,batches:0,fallback:true,reason}};
}
async function generateBook(input,abortSignal) {
  if(abortSignal?.aborted)throw abortError();
  if(!USE_OLLAMA_GENERATION) return generateFallbackBook(input,"Fast product kit mode is enabled.");
  try {
  const batchSize=5,pages=[],titles=[];let metadata=null,totalDuration=0,evalCount=0;
  for(let startPage=1;startPage<=input.pageCount;startPage+=batchSize){
    if(abortSignal?.aborted)throw abortError();
    const batchCount=Math.min(batchSize,input.pageCount-startPage+1);
    let result,attempt=0;
    while(attempt<3){
      if(abortSignal?.aborted)throw abortError();
      result=await generateBatch(input,startPage,batchCount,titles,pages,abortSignal);
      const known=new Set(pages.map(promptSignature));
      const signatures=result.book.pages.map(promptSignature);
      const uniqueBatch=new Set(signatures);
      const overlaps=signatures.some(signature=>known.has(signature));
      if(!overlaps&&uniqueBatch.size===signatures.length)break;
      attempt++;
    }
    if(attempt===3)throw new Error("A prompt batch repeated the same content. Please generate the pack again.");
    if(!metadata)metadata=result.book;
    for(const page of result.book.pages){
      let title=page.title;
      if(titles.map(normalizeTitle).includes(normalizeTitle(title)))title=`${title} — Prompt ${page.page_number}`;
      page.title=title;
      pages.push(page);
    }
    titles.push(...result.book.pages.map(page=>page.title));
    totalDuration+=Number(result.metrics.totalDuration||0);
    evalCount+=Number(result.metrics.evalCount||0);
  }
  if(pages.length!==input.pageCount)throw new Error(`The content engine created ${pages.length}/${input.pageCount} prompts. Please generate again.`);
  const book=removePageCountWarnings(ensurePublishingKit({...metadata,pages},input));
  return {book,metrics:{totalDuration,evalCount,batches:Math.ceil(input.pageCount/batchSize)}};
  } catch(e) {
    if(abortSignal?.aborted)throw abortError();
    console.warn("Using fallback product kit generator:", e.message);
    return generateFallbackBook(input,e.name==="AbortError"?"The content engine took too long to respond.":e.message);
  }
}
function normalizeTitle(title=""){
  return String(title).toLowerCase().replace(/[^a-z0-9]+/g," ").trim();
}
function promptSignature(page={}){
  return normalizeTitle(`${page.title} ${page.instruction} ${(page.content_items||[]).join(" ")}`);
}
function validate(input){
  if(!input.activityType)input.activityType=Array.isArray(input.activityTypes)?input.activityTypes[0]:"";
  if(!input.activityType)throw new Error("Please select an activity type.");
  input.genreType=String(input.genreType||input.difficulty||"Classic Educational").trim();
  input.displayGenre=String(input.displayGenre||"").trim();
  if(input.activityType==="word-search"){
    const selectedWordSearchMode=WORD_SEARCH_MODE_TYPES.includes(input.displayGenre)?input.displayGenre:(WORD_SEARCH_MODE_TYPES.includes(input.genreType)?input.genreType:input.wordSearchMode);
    input.wordSearchMode=String(selectedWordSearchMode||"Standard Word Search").trim();
    input.displayGenre=input.wordSearchMode;
    input.genreType="Classic Educational";
  }
  if(!GENRE_TYPES.includes(input.genreType))throw new Error("Please select a valid type / genre.");
  input.bookIdea=String(input.bookIdea||"").replace(/\s+/g," ").trim().slice(0,180);
  const detectedTheme=detectThemeFromIdea(input.bookIdea,input.activityType,input.genreType);
  if(detectedTheme){
    input.theme=detectedTheme;
    input.topic=detectedTheme;
  } else if(input.bookIdea){
    input.theme="Custom Idea";
    input.topic=input.bookIdea;
  }
  if(!input.theme)input.theme=String(input.topic||"").trim();
  if(!input.topic)input.topic=input.theme;
  if(!input.topic||input.topic.length<3)throw new Error("Please enter a book idea so BrightBook can detect a theme.");
  if(!input.theme)input.theme="Custom Idea";
  // Both checks are required: isGenreCompatible() only looks at genre-level theme/activity
  // lists and can say "yes" for combinations the theme itself rejects (see the guardrail
  // comment on isGenreCompatible() in lib/theme.js). Do not drop either check.
  if(input.theme!=="Custom Idea"&&!isCompatible(input.activityType,input.theme))throw new Error(`The detected theme is not a good fit for ${input.activityType}. Please adjust your book idea.`);
  if(input.theme!=="Custom Idea"&&!isGenreCompatible(input.activityType,input.theme,input.genreType))throw new Error(`The selected type / genre is not a good fit for ${input.activityType} with ${input.theme}. Please choose another combination.`);
  input.wordSearchMode=String(input.wordSearchMode||"Standard Word Search").trim();
  if(input.activityType==="word-search"&&!WORD_SEARCH_MODE_TYPES.includes(input.wordSearchMode))throw new Error("Please select a valid word search type / genre.");
  if(input.activityType!=="word-search")input.wordSearchMode="";
  input.difficulty=input.genreType;
  input.style=String(input.style||styleFromGenre(input.genreType)).trim();
  input.customDirection=String(input.customDirection||"").replace(/\s+/g," ").trim().slice(0,500);
  input.avoidTerms=String(input.avoidTerms||"").replace(/\s+/g," ").trim().slice(0,350);
  input.learningGoal=String(input.learningGoal||"").replace(/\s+/g," ").trim().slice(0,240);
  input.guideCharacter=String(input.guideCharacter||"").replace(/\s+/g," ").trim().slice(0,240);
  input.size="A4";
  input.pageCount=Number(input.pageCount);
  if(![25,30].includes(input.pageCount))throw new Error("Please select 25 or 30 prompts.");
  return input;
}
async function adminApi(req,res,pathname) {
  if (!adminAllowed(req)) return json(res,401,{error:"Admin token is required."});

  if (pathname==="/api/admin/plans" && req.method==="GET") {
    const rows = db.prepare("SELECT * FROM plans WHERE active=1 ORDER BY monthly_prompt_limit ASC").all();
    return json(res,200,{items:rows.map(r=>({id:r.id,name:r.name,monthlyPromptLimit:r.monthly_prompt_limit,priceCents:r.price_cents,active:!!r.active,features:planFeatureKeys(r.id),createdAt:r.created_at}))});
  }
  if (pathname==="/api/admin/plans" && req.method==="POST") {
    const input = await body(req);
    const name = String(input.name||"").trim();
    const limit = Number(input.monthlyPromptLimit);
    if (!name || !Number.isInteger(limit) || limit < 1) return json(res,400,{error:"Plan name and monthly prompt limit are required."});
    const r = db.prepare("INSERT INTO plans(name,monthly_prompt_limit,price_cents,active) VALUES(?,?,?,?)")
      .run(name, limit, Number(input.priceCents||0), input.active===false?0:1);
    return json(res,201,{id:Number(r.lastInsertRowid)});
  }
  const planMatch = pathname.match(/^\/api\/admin\/plans\/(\d+)$/);
  if (planMatch && req.method==="PATCH") {
    const id = Number(planMatch[1]);
    const current = db.prepare("SELECT * FROM plans WHERE id=?").get(id);
    if (!current) return json(res,404,{error:"Plan not found."});
    const input = await body(req);
    try {
      db.prepare("UPDATE plans SET name=?,monthly_prompt_limit=?,price_cents=?,active=? WHERE id=?")
        .run(
          input.name==null?current.name:String(input.name).trim(),
          input.monthlyPromptLimit==null?current.monthly_prompt_limit:Number(input.monthlyPromptLimit),
          input.priceCents==null?current.price_cents:Number(input.priceCents),
          input.active==null?current.active:(input.active?1:0),
          id
        );
    } catch(e) {
      return json(res,400,{error:e.message.includes("UNIQUE")?"A plan with that name already exists.":e.message});
    }
    return json(res,200,{ok:true});
  }

  if (pathname==="/api/admin/features" && req.method==="GET") {
    const rows = db.prepare("SELECT * FROM features WHERE active=1 ORDER BY category,name").all();
    return json(res,200,{items:rows.map(r=>({id:r.id,key:r.feature_key,name:r.name,description:r.description,category:r.category,active:!!r.active,createdAt:r.created_at}))});
  }
  if (pathname==="/api/admin/features" && req.method==="POST") {
    const input = await body(req);
    const featureKey = String(input.key||"").trim().toLowerCase();
    const name = String(input.name||"").trim();
    if (!/^[a-z0-9][a-z0-9._-]{2,80}$/.test(featureKey) || !name) return json(res,400,{error:"Feature key and name are required. Use keys like activity.coloring or export.pdf."});
    try {
      const r = db.prepare("INSERT INTO features(feature_key,name,description,category,active) VALUES(?,?,?,?,?)")
        .run(featureKey,name,String(input.description||""),String(input.category||"General"),input.active===false?0:1);
      return json(res,201,{id:Number(r.lastInsertRowid)});
    } catch(e) {
      return json(res,400,{error:e.message});
    }
  }
  if (pathname==="/api/admin/plan-features" && req.method==="POST") {
    const input = await body(req);
    const planId = Number(input.planId);
    const featureIds = Array.isArray(input.featureIds) ? input.featureIds.map(Number).filter(Boolean) : [];
    if (!planId) return json(res,400,{error:"Plan is required."});
    db.prepare("DELETE FROM plan_features WHERE plan_id=?").run(planId);
    const insert = db.prepare("INSERT INTO plan_features(plan_id,feature_id,enabled) VALUES(?,?,1)");
    for (const featureId of featureIds) insert.run(planId,featureId);
    return json(res,200,{ok:true});
  }

  if (pathname==="/api/admin/users" && req.method==="GET") return json(res,200,{items:allUsers()});
  if (pathname==="/api/admin/users" && req.method==="POST") {
    const input = await body(req);
    const email = String(input.email||"").trim().toLowerCase();
    const name = String(input.name||"").trim();
    const planId = Number(input.planId);
    if (!email || !planId) return json(res,400,{error:"Email and plan are required."});
    const accessToken = String(input.token||token("bb_user")).trim();
    try {
      const r = db.prepare("INSERT INTO users(email,name,access_token,plan_id,status,usage_limit_override) VALUES(?,?,?,?,?,?)")
        .run(email, name, accessToken, planId, input.status||"active", input.usageLimitOverride==null?null:Number(input.usageLimitOverride));
      return json(res,201,{id:Number(r.lastInsertRowid),token:accessToken});
    } catch(e) {
      return json(res,400,{error:e.message});
    }
  }

  const userMatch = pathname.match(/^\/api\/admin\/users\/(\d+)$/);
  if (userMatch && req.method==="PATCH") {
    const id = Number(userMatch[1]);
    const input = await body(req);
    const current = db.prepare("SELECT * FROM users WHERE id=?").get(id);
    if (!current) return json(res,404,{error:"User not found."});
    const next = {
      email: input.email==null?current.email:String(input.email).trim().toLowerCase(),
      name: input.name==null?current.name:String(input.name).trim(),
      planId: input.planId==null?current.plan_id:Number(input.planId),
      status: input.status==null?current.status:String(input.status),
      usageLimitOverride: input.usageLimitOverride===undefined?current.usage_limit_override:(input.usageLimitOverride===null?null:Number(input.usageLimitOverride)),
      token: input.token==null?current.access_token:String(input.token).trim()
    };
    try {
      db.prepare("UPDATE users SET email=?,name=?,plan_id=?,status=?,usage_limit_override=?,access_token=? WHERE id=?")
        .run(next.email,next.name,next.planId,next.status,next.usageLimitOverride,next.token,id);
    } catch(e) {
      return json(res,400,{error:e.message.includes("UNIQUE")?"Another user already uses that email or token.":e.message});
    }
    return json(res,200,{ok:true});
  }

  if (pathname==="/api/admin/usage" && req.method==="GET") {
    const rows = db.prepare(`
      SELECT usage_events.*, users.email
      FROM usage_events JOIN users ON users.id = usage_events.user_id
      ORDER BY usage_events.id DESC LIMIT 200
    `).all();
    return json(res,200,{items:rows.map(r=>({id:r.id,userId:r.user_id,email:r.email,units:r.units,eventType:r.event_type,metadata:JSON.parse(r.metadata_json||"{}"),createdAt:r.created_at}))});
  }

  return json(res,404,{error:"Admin endpoint not found."});
}
async function api(req,res,pathname){
  if(pathname.startsWith("/api/admin/")) return adminApi(req,res,pathname);
  if(pathname==="/api/health"&&req.method==="GET"){
    const ready=await ollamaReady();return json(res,200,{ok:true,ollama:ready,model:MODEL,billing:true});
  }
  if(pathname==="/api/catalog"&&req.method==="GET"){
    return json(res,200,{
      activities:ACTIVITY_TYPES.map(type=>({type,featureKey:`activity.${type}`})),
      themes:THEME_GROUPS.flatMap(([category,items])=>items.map(name=>({name,category,featureKey:themeFeatureKey(name),compatibleActivityTypes:compatibleActivityTypes(name)}))),
      genres:GENRE_TYPES.map(name=>({name,compatibleActivityTypes:compatibleActivitiesForGenre(name),compatibleThemes:compatibleThemesForGenre(name)})),
      wordSearchModes:WORD_SEARCH_MODE_TYPES
    });
  }
  if(pathname==="/api/me"&&req.method==="GET"){
    const user=userWithPlanByToken(clientToken(req));
    if(!user)return json(res,401,{error:"Your account token is not valid."});
    return json(res,200,{user:publicUser(resetPeriodIfNeeded(user))});
  }
  if(pathname==="/api/generate"&&req.method==="POST"){
    let input;try{input=validate(await body(req))}catch(e){return json(res,400,{error:e.message})}
    let access;try{access=requireUserAccess(req,input)}catch(e){return json(res,403,{error:e.message})}
    // No ollamaReady() gate here on purpose: generateBook() already falls back to the
    // template-based generator when Ollama is unreachable or errors out, so generation
    // must keep working whether or not Ollama is running.
    const clientAbort=new AbortController();
    res.on("close",()=>{if(!res.writableEnded)clientAbort.abort()});
    try{
      const result=await generateBook(input,clientAbort.signal);
      if(clientAbort.signal.aborted)return;
      const usage=recordUsage(access.user,access.units,{activityType:input.activityType,theme:input.theme,mode:"ai",features:requiredFeatureKeys(input)});
      if(clientAbort.signal.aborted)return;
      return json(res,201,{...result,usage,features:access.features});
    }
    catch(e){
      if(clientAbort.signal.aborted||e.name==="AbortError")return;
      console.error(e);return json(res,502,{error:e.message});
    }
  }
  if(pathname==="/api/projects"&&req.method==="GET"){
    const rows=db.prepare("SELECT * FROM projects ORDER BY id DESC LIMIT 50").all();
    return json(res,200,{items:rows.map(r=>({id:r.id,title:r.title,settings:JSON.parse(r.settings_json),book:JSON.parse(r.book_json),createdAt:r.created_at}))});
  }
  if(pathname==="/api/projects"&&req.method==="POST"){
    const input=await body(req);if(!input.book?.book_title)return json(res,400,{error:"The project data is not valid."});
    const r=db.prepare("INSERT INTO projects(title,settings_json,book_json) VALUES(?,?,?)").run(input.book.book_title,JSON.stringify(input.settings||{}),JSON.stringify(input.book));
    return json(res,201,{id:Number(r.lastInsertRowid)});
  }
  return json(res,404,{error:"Endpoint not found."});
}
const mime={".html":"text/html; charset=utf-8",".css":"text/css; charset=utf-8",".js":"application/javascript; charset=utf-8",".json":"application/json; charset=utf-8"};
function staticFile(res,pathname){const rel=pathname==="/"?"index.html":pathname.slice(1),abs=path.resolve(ROOT,rel);if(!abs.startsWith(ROOT)||!fs.existsSync(abs)||fs.statSync(abs).isDirectory()){res.writeHead(404);return res.end("Not found")}res.writeHead(200,{"Content-Type":mime[path.extname(abs)]||"application/octet-stream","Cache-Control":"no-cache"});fs.createReadStream(abs).pipe(res)}
http.createServer(async(req,res)=>{try{const u=new URL(req.url,`http://${req.headers.host}`);if(u.pathname.startsWith("/api/"))return await api(req,res,u.pathname);staticFile(res,u.pathname)}catch(e){console.error(e);json(res,500,{error:e.message})}}).listen(PORT,"127.0.0.1",()=>console.log(`BrightBook http://127.0.0.1:${PORT} · ${MODEL}`));
