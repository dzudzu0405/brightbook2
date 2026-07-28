<?php
declare(strict_types=1);

// PHP has no persistent process like the Node version (which re-seeds on every
// server start). Schema creation is cheap and safe to run on every request
// (CREATE TABLE IF NOT EXISTS). seed_billing() is NOT auto-run here since it
// does real writes on every call - run it once after each deploy via
// public/migrate.php instead.

function bb_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);

    $db = new PDO('sqlite:' . $dataDir . '/brightbook.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec("
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
        -- Access is feature-gated, not quota-gated: bb_require_user_access() never checks these.
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
        );
    ");

    return $db;
}

function bb_seed_billing(PDO $db): void {
    require_once __DIR__ . '/theme.php';

    $desiredPlans = [
        ['Starter', 500, 2700],
        ['Pro', 2500, 4700],
        ['Publisher', 10000, 6700],
    ];
    $insertPlan = $db->prepare("INSERT INTO plans(name,monthly_prompt_limit,price_cents,active) VALUES(?,?,?,1)");
    $updatePlan = $db->prepare("UPDATE plans SET monthly_prompt_limit=?,price_cents=?,active=1 WHERE name=?");
    foreach ($desiredPlans as [$name, $limit, $price]) {
        $existing = $db->prepare("SELECT id FROM plans WHERE name=?");
        $existing->execute([$name]);
        if ($existing->fetch()) {
            $updatePlan->execute([$limit, $price, $name]);
        } else {
            $insertPlan->execute([$name, $limit, $price]);
        }
    }
    $db->exec("UPDATE plans SET active=0 WHERE name NOT IN ('Starter','Pro','Publisher')");
    $db->exec("UPDATE plans SET name='Starter',monthly_prompt_limit=500,price_cents=2700,active=1 WHERE name='Front-End' AND NOT EXISTS (SELECT 1 FROM plans WHERE name='Starter')");
    $db->exec("UPDATE plans SET name='Pro',monthly_prompt_limit=2500,price_cents=4700,active=1 WHERE name='Pro OTO' AND NOT EXISTS (SELECT 1 FROM plans WHERE name='Pro')");
    $db->exec("UPDATE plans SET name='Publisher',monthly_prompt_limit=10000,price_cents=6700,active=1 WHERE name='Publishing Kit OTO' AND NOT EXISTS (SELECT 1 FROM plans WHERE name='Publisher')");
    $db->exec("UPDATE users SET plan_id=(SELECT id FROM plans WHERE name='Starter') WHERE plan_id IN (SELECT id FROM plans WHERE name IN ('Front-End'))");
    $db->exec("UPDATE users SET plan_id=(SELECT id FROM plans WHERE name='Pro') WHERE plan_id IN (SELECT id FROM plans WHERE name IN ('Creator','Pro OTO','Activity Expansion OTO'))");
    $db->exec("UPDATE users SET plan_id=(SELECT id FROM plans WHERE name='Publisher') WHERE plan_id IN (SELECT id FROM plans WHERE name IN ('Publishing Kit OTO','Agency License'))");

    $demoPlanStmt = $db->prepare("SELECT id FROM plans WHERE name=?");
    $demoPlanStmt->execute(['Starter']);
    $demoPlan = $demoPlanStmt->fetch();
    if (!$demoPlan) {
        $demoPlanStmt->execute(['Front-End']);
        $demoPlan = $demoPlanStmt->fetch();
    }
    if (!$demoPlan) {
        $demoPlan = $db->query("SELECT id FROM plans ORDER BY id LIMIT 1")->fetch();
    }
    $demoStmt = $db->prepare("SELECT id FROM users WHERE email=?");
    $demoStmt->execute(['demo@brightbook.local']);
    if (!$demoStmt->fetch() && $demoPlan) {
        $db->prepare("INSERT INTO users(email,name,access_token,plan_id,status) VALUES(?,?,?,?,?)")
            ->execute(['demo@brightbook.local', 'Demo User', 'demo-token', $demoPlan['id'], 'active']);
    }

    $features = [
        ['activity.coloring', 'Coloring Book', 'Generate coloring book prompt packs.', 'Activity Types'],
        ['activity.word-search', 'Word Search Book', 'Generate word search prompt packs.', 'Activity Types'],
        ['activity.educational-story', 'Educational Storybook', 'Generate connected educational story prompt packs.', 'Activity Types'],
        ['activity.tracing', 'Tracing & Handwriting Book', 'Generate tracing and handwriting prompt packs.', 'Activity Types'],
        ['activity.matching', 'Matching Activity Book', 'Generate matching activity prompt packs.', 'Activity Types'],
        ['activity.counting', 'Counting Book', 'Generate counting activity prompt packs.', 'Activity Types'],
        ['activity.simple-math', 'Math Practice Book', 'Generate simple math prompt packs.', 'Activity Types'],
        ['activity.spot-difference', 'Spot the Difference Book', 'Generate spot-the-difference prompt packs.', 'Activity Types'],
        ['activity.puzzle', "Children's Puzzle Book", "Generate children's puzzle prompt packs.", 'Activity Types'],
        ['activity.learning-worksheet', 'Educational Worksheet Pack', 'Generate educational worksheet prompt packs.', 'Activity Types'],
        ['quantity.25', '25 Prompts Per Generation', 'Allow 25-prompt generation.', 'Generation Size'],
        ['quantity.30', '30 Prompts Per Generation', 'Allow 30-prompt generation.', 'Generation Size'],
        ['advanced.custom-direction', 'Custom Direction', 'Allow custom user direction on top of the selected theme.', 'Advanced Inputs'],
        ['advanced.learning-goal', 'Custom Learning Goal', 'Allow custom learning goals.', 'Advanced Inputs'],
        ['advanced.guide-character', 'Guide Character', 'Allow recurring character locks.', 'Advanced Inputs'],
        ['export.save-project', 'Save Projects', 'Allow saving generated projects.', 'Exports'],
        ['export.json', 'JSON Export', 'Allow JSON export in the interface.', 'Exports'],
        ['export.txt', 'TXT Export', 'Allow TXT export in the interface.', 'Exports'],
        ['kit.listing-assets', 'Listing Kit', 'Generate KDP, Etsy, keyword, and A+ content assets.', 'Publishing Kit'],
        ['kit.quality-check', 'Quality Checker', 'Generate a quality score, warnings, and fix suggestions.', 'Publishing Kit'],
        ['kit.series-builder', 'Series Builder', 'Generate follow-up product ideas for catalog building.', 'Publishing Kit'],
        ['kit.launch-checklist', 'Launch Checklist', 'Generate a publishing checklist for marketplaces.', 'Publishing Kit'],
    ];
    foreach (THEME_GROUPS as [$category, $items]) {
        foreach ($items as $theme) {
            $features[] = [bb_theme_feature_key($theme), $theme, "Allow the {$theme} theme.", "Themes · {$category}"];
        }
    }
    $insertFeature = $db->prepare("INSERT OR IGNORE INTO features(feature_key,name,description,category,active) VALUES(?,?,?,?,1)");
    foreach ($features as $f) $insertFeature->execute($f);

    $supportedActivityKeys = array_map(fn($t) => "activity.$t", ACTIVITY_TYPES);
    $placeholders = implode(',', array_fill(0, count($supportedActivityKeys), '?'));
    $db->prepare("
        UPDATE features
        SET active = CASE WHEN feature_key IN ($placeholders) THEN 1 ELSE 0 END
        WHERE feature_key LIKE 'activity.%'
    ")->execute($supportedActivityKeys);

    $allPlans = $db->query("SELECT id,name FROM plans")->fetchAll();
    $featureRows = $db->query("SELECT id,feature_key FROM features")->fetchAll();
    $byKey = [];
    foreach ($featureRows as $f) $byKey[$f['feature_key']] = $f['id'];
    $enable = $db->prepare("INSERT OR IGNORE INTO plan_features(plan_id,feature_id,enabled) VALUES(?,?,1)");
    $setPlanFeatures = $db->prepare("DELETE FROM plan_features WHERE plan_id=?");

    $starter = ['activity.coloring', 'activity.word-search', 'quantity.25', 'export.txt', 'export.json'];
    $pro = array_merge($starter, ['activity.tracing', 'activity.matching', 'activity.counting', 'quantity.30', 'advanced.custom-direction']);
    $publisher = array_merge($pro, ['activity.learning-worksheet', 'export.save-project', 'kit.listing-assets', 'kit.quality-check', 'kit.series-builder', 'kit.launch-checklist']);

    $themeFeatureKeys = fn(array $groups) => array_map('bb_theme_feature_key', array_merge(...array_map(fn($g) => $g[1], $groups)));
    $starterThemes = $themeFeatureKeys(array_slice(THEME_GROUPS, 0, 2));
    $creatorThemes = $themeFeatureKeys(array_slice(THEME_GROUPS, 0, 4));
    $proThemes = $themeFeatureKeys(THEME_GROUPS);

    foreach ($allPlans as $plan) {
        $planName = strtolower($plan['name']);
        $isStarter = $planName === 'starter' || $planName === 'front-end';
        $isPro = in_array($planName, ['pro', 'creator', 'pro oto', 'activity expansion oto'], true);
        $isPublisher = $planName === 'publisher' || $planName === 'publishing kit oto';
        $keys = $isStarter ? array_merge($starter, $starterThemes)
            : ($isPro ? array_merge($pro, $creatorThemes)
            : ($isPublisher ? array_merge($publisher, $proThemes) : []));
        $setPlanFeatures->execute([$plan['id']]);
        foreach ($keys as $key) {
            if (isset($byKey[$key])) $enable->execute([$plan['id'], $byKey[$key]]);
        }
    }
}
