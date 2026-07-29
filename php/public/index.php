<?php
declare(strict_types=1);

// Optional, gitignored - holds GROQ_API_KEY / GEMINI_API_KEY / ADMIN_TOKEN via putenv()
// on hosts with no other way to set env vars. See config.example.php.
if (is_file(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/theme.php';
require_once __DIR__ . '/../src/access.php';
require_once __DIR__ . '/../src/book.php';

function bb_json_response(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bb_read_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bb_admin_api(PDO $db, string $pathname, string $method, array $body): void {
    if (!bb_admin_allowed()) bb_json_response(401, ['error' => 'Admin token is required.']);

    if ($pathname === '/api/admin/plans' && $method === 'GET') {
        $rows = $db->query("SELECT * FROM plans WHERE active=1 ORDER BY monthly_prompt_limit ASC")->fetchAll();
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'monthlyPromptLimit' => (int)$r['monthly_prompt_limit'],
            'priceCents' => (int)$r['price_cents'], 'active' => (bool)$r['active'],
            'features' => bb_plan_feature_keys($db, (int)$r['id']), 'createdAt' => $r['created_at'],
        ], $rows);
        bb_json_response(200, ['items' => $items]);
    }

    if ($pathname === '/api/admin/plans' && $method === 'POST') {
        $name = trim((string)($body['name'] ?? ''));
        $limit = (int)($body['monthlyPromptLimit'] ?? 0);
        if ($name === '' || $limit < 1) bb_json_response(400, ['error' => 'Plan name and monthly prompt limit are required.']);
        $stmt = $db->prepare("INSERT INTO plans(name,monthly_prompt_limit,price_cents,active) VALUES(?,?,?,?)");
        $stmt->execute([$name, $limit, (int)($body['priceCents'] ?? 0), ($body['active'] ?? true) === false ? 0 : 1]);
        bb_json_response(201, ['id' => (int)$db->lastInsertId()]);
    }

    if (preg_match('#^/api/admin/plans/(\d+)$#', $pathname, $m) && $method === 'PATCH') {
        $id = (int)$m[1];
        $stmt = $db->prepare("SELECT * FROM plans WHERE id=?");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if (!$current) bb_json_response(404, ['error' => 'Plan not found.']);
        try {
            $db->prepare("UPDATE plans SET name=?,monthly_prompt_limit=?,price_cents=?,active=? WHERE id=?")->execute([
                array_key_exists('name', $body) && $body['name'] !== null ? trim((string)$body['name']) : $current['name'],
                array_key_exists('monthlyPromptLimit', $body) && $body['monthlyPromptLimit'] !== null ? (int)$body['monthlyPromptLimit'] : $current['monthly_prompt_limit'],
                array_key_exists('priceCents', $body) && $body['priceCents'] !== null ? (int)$body['priceCents'] : $current['price_cents'],
                array_key_exists('active', $body) && $body['active'] !== null ? ($body['active'] ? 1 : 0) : $current['active'],
                $id,
            ]);
        } catch (PDOException $e) {
            bb_json_response(400, ['error' => str_contains($e->getMessage(), 'UNIQUE') ? 'A plan with that name already exists.' : $e->getMessage()]);
        }
        bb_json_response(200, ['ok' => true]);
    }

    if ($pathname === '/api/admin/features' && $method === 'GET') {
        $rows = $db->query("SELECT * FROM features WHERE active=1 ORDER BY category,name")->fetchAll();
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'], 'key' => $r['feature_key'], 'name' => $r['name'], 'description' => $r['description'],
            'category' => $r['category'], 'active' => (bool)$r['active'], 'createdAt' => $r['created_at'],
        ], $rows);
        bb_json_response(200, ['items' => $items]);
    }

    if ($pathname === '/api/admin/features' && $method === 'POST') {
        $featureKey = strtolower(trim((string)($body['key'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/', $featureKey) || $name === '') {
            bb_json_response(400, ['error' => 'Feature key and name are required. Use keys like activity.coloring or export.pdf.']);
        }
        try {
            $stmt = $db->prepare("INSERT INTO features(feature_key,name,description,category,active) VALUES(?,?,?,?,?)");
            $stmt->execute([$featureKey, $name, (string)($body['description'] ?? ''), (string)($body['category'] ?? 'General'), ($body['active'] ?? true) === false ? 0 : 1]);
            bb_json_response(201, ['id' => (int)$db->lastInsertId()]);
        } catch (PDOException $e) {
            bb_json_response(400, ['error' => $e->getMessage()]);
        }
    }

    if ($pathname === '/api/admin/plan-features' && $method === 'POST') {
        $planId = (int)($body['planId'] ?? 0);
        $featureIds = is_array($body['featureIds'] ?? null) ? array_values(array_filter(array_map('intval', $body['featureIds']))) : [];
        if (!$planId) bb_json_response(400, ['error' => 'Plan is required.']);
        $db->prepare("DELETE FROM plan_features WHERE plan_id=?")->execute([$planId]);
        $insert = $db->prepare("INSERT INTO plan_features(plan_id,feature_id,enabled) VALUES(?,?,1)");
        foreach ($featureIds as $featureId) $insert->execute([$planId, $featureId]);
        bb_json_response(200, ['ok' => true]);
    }

    if ($pathname === '/api/admin/users' && $method === 'GET') bb_json_response(200, ['items' => bb_all_users($db)]);

    if ($pathname === '/api/admin/users' && $method === 'POST') {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));
        $planId = (int)($body['planId'] ?? 0);
        if ($email === '' || !$planId) bb_json_response(400, ['error' => 'Email and plan are required.']);
        $accessToken = trim((string)($body['token'] ?? bb_gen_token('bb_user')));
        try {
            $stmt = $db->prepare("INSERT INTO users(email,name,access_token,plan_id,status,usage_limit_override) VALUES(?,?,?,?,?,?)");
            $stmt->execute([$email, $name, $accessToken, $planId, $body['status'] ?? 'active', $body['usageLimitOverride'] ?? null]);
            bb_json_response(201, ['id' => (int)$db->lastInsertId(), 'token' => $accessToken]);
        } catch (PDOException $e) {
            bb_json_response(400, ['error' => $e->getMessage()]);
        }
    }

    if (preg_match('#^/api/admin/users/(\d+)$#', $pathname, $m) && $method === 'PATCH') {
        $id = (int)$m[1];
        $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if (!$current) bb_json_response(404, ['error' => 'User not found.']);
        $next = [
            'email' => array_key_exists('email', $body) && $body['email'] !== null ? strtolower(trim((string)$body['email'])) : $current['email'],
            'name' => array_key_exists('name', $body) && $body['name'] !== null ? trim((string)$body['name']) : $current['name'],
            'planId' => array_key_exists('planId', $body) && $body['planId'] !== null ? (int)$body['planId'] : $current['plan_id'],
            'status' => array_key_exists('status', $body) && $body['status'] !== null ? (string)$body['status'] : $current['status'],
            'usageLimitOverride' => !array_key_exists('usageLimitOverride', $body) ? $current['usage_limit_override'] : ($body['usageLimitOverride'] === null ? null : (float)$body['usageLimitOverride']),
            'token' => array_key_exists('token', $body) && $body['token'] !== null ? trim((string)$body['token']) : $current['access_token'],
        ];
        try {
            $db->prepare("UPDATE users SET email=?,name=?,plan_id=?,status=?,usage_limit_override=?,access_token=? WHERE id=?")
                ->execute([$next['email'], $next['name'], $next['planId'], $next['status'], $next['usageLimitOverride'], $next['token'], $id]);
        } catch (PDOException $e) {
            bb_json_response(400, ['error' => str_contains($e->getMessage(), 'UNIQUE') ? 'Another user already uses that email or token.' : $e->getMessage()]);
        }
        bb_json_response(200, ['ok' => true]);
    }

    if ($pathname === '/api/admin/usage' && $method === 'GET') {
        $rows = $db->query("
            SELECT usage_events.*, users.email
            FROM usage_events JOIN users ON users.id = usage_events.user_id
            ORDER BY usage_events.id DESC LIMIT 200
        ")->fetchAll();
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'], 'userId' => (int)$r['user_id'], 'email' => $r['email'], 'units' => (int)$r['units'],
            'eventType' => $r['event_type'], 'metadata' => json_decode($r['metadata_json'] ?: '{}', true), 'createdAt' => $r['created_at'],
        ], $rows);
        bb_json_response(200, ['items' => $items]);
    }

    bb_json_response(404, ['error' => 'Admin endpoint not found.']);
}

// --- Router ---

$db = bb_db();
$pathname = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];
$body = in_array($method, ['POST', 'PATCH', 'PUT'], true) ? bb_read_body() : [];

if (str_starts_with($pathname, '/api/admin/')) {
    bb_admin_api($db, $pathname, $method, $body);
}

if ($pathname === '/api/health' && $method === 'GET') {
    bb_json_response(200, [
        'ok' => true,
        'groq' => (getenv('GROQ_API_KEY') ?: '') !== '',
        'gemini' => (getenv('GEMINI_API_KEY') ?: '') !== '',
        'groqModel' => getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile',
        'geminiModel' => getenv('GEMINI_MODEL') ?: 'gemini-flash-latest',
        'billing' => true,
    ]);
}

if ($pathname === '/api/catalog' && $method === 'GET') {
    $activities = array_map(fn($t) => ['type' => $t, 'featureKey' => "activity.{$t}"], ACTIVITY_TYPES);
    $themes = [];
    foreach (THEME_GROUPS as [$category, $items]) {
        foreach ($items as $name) {
            $themes[] = ['name' => $name, 'category' => $category, 'featureKey' => bb_theme_feature_key($name), 'compatibleActivityTypes' => bb_compatible_activity_types($name)];
        }
    }
    $genres = array_map(fn($name) => ['name' => $name, 'compatibleActivityTypes' => bb_compatible_activities_for_genre($name), 'compatibleThemes' => bb_compatible_themes_for_genre($name)], GENRE_TYPES);
    bb_json_response(200, ['activities' => $activities, 'themes' => $themes, 'genres' => $genres, 'wordSearchModes' => WORD_SEARCH_MODE_TYPES]);
}

if ($pathname === '/api/me' && $method === 'GET') {
    $user = bb_user_with_plan_by_token($db, bb_client_token());
    if (!$user) bb_json_response(401, ['error' => 'Your account token is not valid.']);
    bb_json_response(200, ['user' => bb_public_user($db, bb_reset_period_if_needed($db, $user))]);
}

// Email-only entry point for customers: no password or token to manage. First
// visit auto-creates a Starter-plan account; upgrades/disables happen later
// from the admin panel once the seller matches the email against a sale.
if ($pathname === '/api/login' && $method === 'POST') {
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bb_json_response(400, ['error' => 'Please enter a valid email address.']);
    }
    try {
        $user = bb_login_or_create_user($db, $email);
    } catch (Throwable $e) {
        bb_json_response(500, ['error' => $e->getMessage()]);
    }
    bb_json_response(200, ['user' => bb_public_user($db, bb_reset_period_if_needed($db, $user))]);
}

if ($pathname === '/api/generate' && $method === 'POST') {
    try {
        $input = bb_validate($body);
    } catch (Throwable $e) {
        bb_json_response(400, ['error' => $e->getMessage()]);
    }
    try {
        $access = bb_require_user_access($db, $input);
    } catch (Throwable $e) {
        bb_json_response(403, ['error' => $e->getMessage()]);
    }
    // PHP's request-per-execution model has no equivalent to Node's client-disconnect
    // abort signal, so unlike server.js this always runs generation to completion.
    try {
        $result = bb_generate_book($input);
        $usage = bb_record_usage($db, $access['user'], $access['units'], [
            'activityType' => $input['activityType'], 'theme' => $input['theme'], 'mode' => 'ai',
            'features' => bb_required_feature_keys($input),
        ]);
        bb_json_response(201, array_merge($result, ['usage' => $usage, 'features' => $access['features']]));
    } catch (Throwable $e) {
        error_log((string)$e);
        bb_json_response(502, ['error' => $e->getMessage()]);
    }
}

if ($pathname === '/api/projects' && $method === 'GET') {
    $rows = $db->query("SELECT * FROM projects ORDER BY id DESC LIMIT 50")->fetchAll();
    $items = array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'settings' => json_decode($r['settings_json'], true),
        'book' => json_decode($r['book_json'], true), 'createdAt' => $r['created_at'],
    ], $rows);
    bb_json_response(200, ['items' => $items]);
}

if ($pathname === '/api/projects' && $method === 'POST') {
    if (empty($body['book']['book_title'])) bb_json_response(400, ['error' => 'The project data is not valid.']);
    $stmt = $db->prepare("INSERT INTO projects(title,settings_json,book_json) VALUES(?,?,?)");
    $stmt->execute([$body['book']['book_title'], json_encode($body['settings'] ?? []), json_encode($body['book'])]);
    bb_json_response(201, ['id' => (int)$db->lastInsertId()]);
}

bb_json_response(404, ['error' => 'Endpoint not found.']);
