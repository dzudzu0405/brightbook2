const fs = require("node:fs");
const path = require("node:path");
const { DatabaseSync } = require("node:sqlite");
const { THEME_GROUPS, themeFeatureKey, PUBLIC_ACTIVITY_TYPES } = require("./theme");

const ROOT = path.join(__dirname, "..");
fs.mkdirSync(path.join(ROOT, "data"), { recursive: true });
const db = new DatabaseSync(path.join(ROOT, "data", "brightbook.db"));
db.exec(`
  CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    settings_json TEXT NOT NULL,
    book_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    monthly_prompt_limit INTEGER NOT NULL,
    price_cents INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  -- monthly_prompt_limit / usage_limit_override are informational only (shown in admin + /api/me).
  -- Access is feature-gated, not quota-gated: requireUserAccess() never checks these.
  CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL DEFAULT '',
    access_token TEXT NOT NULL UNIQUE,
    plan_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    usage_limit_override INTEGER,
    period_started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(plan_id) REFERENCES plans(id)
  );
  CREATE TABLE IF NOT EXISTS usage_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    units INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id)
  );
  CREATE TABLE IF NOT EXISTS features (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feature_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'General',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS plan_features (
    plan_id INTEGER NOT NULL,
    feature_id INTEGER NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY(plan_id, feature_id),
    FOREIGN KEY(plan_id) REFERENCES plans(id),
    FOREIGN KEY(feature_id) REFERENCES features(id)
  )
`);

function seedBilling() {
  const desiredPlans = [
    ["Starter", 500, 2700],
    ["Pro", 2500, 4700],
    ["Publisher", 10000, 6700]
  ];
  const insertPlan = db.prepare("INSERT INTO plans(name,monthly_prompt_limit,price_cents,active) VALUES(?,?,?,1)");
  const updatePlan = db.prepare("UPDATE plans SET monthly_prompt_limit=?,price_cents=?,active=1 WHERE name=?");
  for (const [name,limit,price] of desiredPlans) {
    const existing = db.prepare("SELECT id FROM plans WHERE name=?").get(name);
    if (existing) updatePlan.run(limit,price,name);
    else insertPlan.run(name,limit,price);
  }
  db.prepare("UPDATE plans SET active=0 WHERE name NOT IN ('Starter','Pro','Publisher')").run();
  db.prepare("UPDATE plans SET name='Starter',monthly_prompt_limit=500,price_cents=2700,active=1 WHERE name='Front-End' AND NOT EXISTS (SELECT 1 FROM plans WHERE name='Starter')").run();
  db.prepare("UPDATE plans SET name='Pro',monthly_prompt_limit=2500,price_cents=4700,active=1 WHERE name='Pro OTO' AND NOT EXISTS (SELECT 1 FROM plans WHERE name='Pro')").run();
  db.prepare("UPDATE plans SET name='Publisher',monthly_prompt_limit=10000,price_cents=6700,active=1 WHERE name='Publishing Kit OTO' AND NOT EXISTS (SELECT 1 FROM plans WHERE name='Publisher')").run();
  db.prepare("UPDATE users SET plan_id=(SELECT id FROM plans WHERE name='Starter') WHERE plan_id IN (SELECT id FROM plans WHERE name IN ('Front-End'))").run();
  db.prepare("UPDATE users SET plan_id=(SELECT id FROM plans WHERE name='Pro') WHERE plan_id IN (SELECT id FROM plans WHERE name IN ('Creator','Pro OTO','Activity Expansion OTO'))").run();
  db.prepare("UPDATE users SET plan_id=(SELECT id FROM plans WHERE name='Publisher') WHERE plan_id IN (SELECT id FROM plans WHERE name IN ('Publishing Kit OTO','Agency License'))").run();
  const demoPlan = db.prepare("SELECT id FROM plans WHERE name=?").get("Starter") || db.prepare("SELECT id FROM plans WHERE name=?").get("Front-End") || db.prepare("SELECT id FROM plans ORDER BY id LIMIT 1").get();
  const demo = db.prepare("SELECT id FROM users WHERE email=?").get("demo@brightbook.local");
  if (!demo && demoPlan) {
    db.prepare("INSERT INTO users(email,name,access_token,plan_id,status) VALUES(?,?,?,?,?)")
      .run("demo@brightbook.local", "Demo User", "demo-token", demoPlan.id, "active");
  }
  const features = [
    ["activity.coloring","Coloring Book","Generate coloring book prompt packs.","Activity Types"],
    ["activity.word-search","Word Search Book","Generate word search prompt packs.","Activity Types"],
    ["activity.educational-story","Educational Storybook","Generate connected educational story prompt packs.","Activity Types"],
    ["activity.maze","Maze Book","Generate maze activity prompt packs.","Activity Types"],
    ["activity.tracing","Tracing & Handwriting Book","Generate tracing and handwriting prompt packs.","Activity Types"],
    ["activity.matching","Matching Activity Book","Generate matching activity prompt packs.","Activity Types"],
    ["activity.counting","Counting Book","Generate counting activity prompt packs.","Activity Types"],
    ["activity.simple-math","Math Practice Book","Generate simple math prompt packs.","Activity Types"],
    ["activity.spot-difference","Spot the Difference Book","Generate spot-the-difference prompt packs.","Activity Types"],
    ["activity.puzzle","Children's Puzzle Book","Generate children's puzzle prompt packs.","Activity Types"],
    ["activity.learning-worksheet","Educational Worksheet Pack","Generate educational worksheet prompt packs.","Activity Types"],
    ["quantity.25","25 Prompts Per Generation","Allow 25-prompt generation.","Generation Size"],
    ["quantity.30","30 Prompts Per Generation","Allow 30-prompt generation.","Generation Size"],
    ["advanced.custom-direction","Custom Direction","Allow custom user direction on top of the selected theme.","Advanced Inputs"],
    ["advanced.learning-goal","Custom Learning Goal","Allow custom learning goals.","Advanced Inputs"],
    ["advanced.guide-character","Guide Character","Allow recurring character locks.","Advanced Inputs"],
    ["export.save-project","Save Projects","Allow saving generated projects.","Exports"],
    ["export.json","JSON Export","Allow JSON export in the interface.","Exports"],
    ["export.txt","TXT Export","Allow TXT export in the interface.","Exports"],
    ["kit.listing-assets","Listing Kit","Generate KDP, Etsy, keyword, and A+ content assets.","Publishing Kit"],
    ["kit.quality-check","Quality Checker","Generate a quality score, warnings, and fix suggestions.","Publishing Kit"],
    ["kit.series-builder","Series Builder","Generate follow-up product ideas for catalog building.","Publishing Kit"],
    ["kit.launch-checklist","Launch Checklist","Generate a publishing checklist for marketplaces.","Publishing Kit"]
  ];
  for (const [category,items] of THEME_GROUPS) {
    for (const theme of items) features.push([themeFeatureKey(theme),theme,`Allow the ${theme} theme.`,`Themes · ${category}`]);
  }
  const insertFeature = db.prepare("INSERT OR IGNORE INTO features(feature_key,name,description,category,active) VALUES(?,?,?,?,1)");
  for (const f of features) insertFeature.run(...f);
  const supportedActivityKeys = PUBLIC_ACTIVITY_TYPES.map(type=>`activity.${type}`);
  db.prepare(`
    UPDATE features
    SET active = CASE WHEN feature_key IN (${supportedActivityKeys.map(()=>"?").join(",")}) THEN 1 ELSE 0 END
    WHERE feature_key LIKE 'activity.%'
  `).run(...supportedActivityKeys);

  const allPlans = db.prepare("SELECT id,name FROM plans").all();
  const featureRows = db.prepare("SELECT id,feature_key FROM features").all();
  const byKey = Object.fromEntries(featureRows.map(f => [f.feature_key, f.id]));
  const enable = db.prepare("INSERT OR IGNORE INTO plan_features(plan_id,feature_id,enabled) VALUES(?,?,1)");
  const setPlanFeatures = db.prepare("DELETE FROM plan_features WHERE plan_id=?");
  const starter = ["activity.coloring","activity.word-search","quantity.25","export.txt","export.json"];
  const pro = starter.concat(["activity.tracing","activity.matching","activity.counting","quantity.30","advanced.custom-direction"]);
  const publisher = pro.concat(["activity.learning-worksheet","export.save-project","kit.listing-assets","kit.quality-check","kit.series-builder","kit.launch-checklist"]);
  for (const plan of allPlans) {
    const starterThemes = THEME_GROUPS.slice(0,2).flatMap(([,items])=>items).map(themeFeatureKey);
    const creatorThemes = THEME_GROUPS.slice(0,4).flatMap(([,items])=>items).map(themeFeatureKey);
    const proThemes = THEME_GROUPS.flatMap(([,items])=>items).map(themeFeatureKey);
    const planName = String(plan.name).toLowerCase();
    const isStarter = planName === "starter" || planName === "front-end";
    const isPro = planName === "pro" || planName === "creator" || planName === "pro oto" || planName === "activity expansion oto";
    const isPublisher = planName === "publisher" || planName === "publishing kit oto";
    const keys = isStarter
      ? starter.concat(starterThemes)
      : isPro
        ? pro.concat(creatorThemes)
        : isPublisher
          ? publisher.concat(proThemes)
          : [];
    setPlanFeatures.run(plan.id);
    for (const key of keys) if (byKey[key]) enable.run(plan.id, byKey[key]);
  }
}
seedBilling();

module.exports = { db, ROOT, seedBilling };
