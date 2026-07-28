<?php
declare(strict_types=1);

// One-time (or repeatable) setup script: run this once after each deploy to
// create/seed plans, features, and the demo user. Not needed on every
// request - db.php only creates tables, it never seeds automatically.
// Usage: curl -H "X-Admin-Token: <token>" https://yourdomain.com/migrate.php

// Optional, gitignored - holds GROQ_API_KEY / GEMINI_API_KEY / ADMIN_TOKEN via putenv()
// on hosts with no other way to set env vars. See config.example.php.
if (is_file(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/theme.php';
require_once __DIR__ . '/../src/access.php';

header('Content-Type: application/json');

if (!bb_admin_allowed()) {
    http_response_code(401);
    echo json_encode(['error' => 'Admin token is required.']);
    exit;
}

$db = bb_db();
bb_seed_billing($db);

$plans = $db->query("SELECT name, monthly_prompt_limit, price_cents, active FROM plans ORDER BY id")->fetchAll();
$featureCount = (int)$db->query("SELECT COUNT(*) AS c FROM features")->fetch()['c'];
$userCount = (int)$db->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];

echo json_encode([
    'ok' => true,
    'plans' => $plans,
    'featureCount' => $featureCount,
    'userCount' => $userCount,
], JSON_PRETTY_PRINT);
