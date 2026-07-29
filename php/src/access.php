<?php
declare(strict_types=1);

require_once __DIR__ . '/theme.php';

function bb_admin_token(): string {
    $fromEnv = $_ENV['ADMIN_TOKEN'] ?? (getenv('ADMIN_TOKEN') ?: null);
    return $fromEnv ?: 'brightbook-admin';
}

function bb_header(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function bb_admin_allowed(): bool {
    $provided = bb_header('X-Admin-Token');
    $expected = bb_admin_token();
    if (!is_string($provided) || strlen($provided) !== strlen($expected)) return false;
    return hash_equals($expected, $provided);
}

function bb_client_token(array $input = []): string {
    $token = bb_header('X-User-Token') ?? ($input['userToken'] ?? 'demo-token');
    return trim((string)$token);
}

function bb_gen_token(string $prefix = 'bb'): string {
    $random = base64_encode(random_bytes(18));
    $random = rtrim(strtr($random, '+/', '-_'), '=');
    return "{$prefix}_{$random}";
}

function bb_user_with_plan_by_token(PDO $db, string $accessToken): ?array {
    $stmt = $db->prepare("
        SELECT users.*, plans.name AS plan_name, plans.monthly_prompt_limit, plans.active AS plan_active
        FROM users JOIN plans ON plans.id = users.plan_id
        WHERE users.access_token = ?
    ");
    $stmt->execute([$accessToken]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bb_user_with_plan_by_email(PDO $db, string $email): ?array {
    $stmt = $db->prepare("
        SELECT users.*, plans.name AS plan_name, plans.monthly_prompt_limit, plans.active AS plan_active
        FROM users JOIN plans ON plans.id = users.plan_id
        WHERE users.email = ?
    ");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Email-only login for WarriorPlus-style delivery: no password or access token
// for the customer to manage. First visit auto-creates a Starter-plan account;
// the seller upgrades or disables the account afterward from the admin panel
// once they match the buyer's email against their WarriorPlus sales.
function bb_login_or_create_user(PDO $db, string $email): array {
    $email = strtolower(trim($email));
    $existing = bb_user_with_plan_by_email($db, $email);
    if ($existing) return $existing;

    $planStmt = $db->prepare("SELECT id FROM plans WHERE name = ? AND active = 1");
    $planStmt->execute(['Starter']);
    $plan = $planStmt->fetch();
    if (!$plan) throw new BBAccessException('No starter plan is configured yet. Please contact support.');

    $db->prepare("INSERT INTO users(email,name,access_token,plan_id,status) VALUES(?,?,?,?,?)")
        ->execute([$email, '', bb_gen_token('bb_user'), $plan['id'], 'active']);

    return bb_user_with_plan_by_email($db, $email);
}

function bb_reset_period_if_needed(PDO $db, array $user): array {
    $started = strtotime(str_replace(' ', 'T', $user['period_started_at']) . 'Z');
    $days = ($started !== false) ? (time() - $started) / 86400 : 0;
    if ($days >= 30) {
        $db->prepare("UPDATE users SET period_started_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$user['id']]);
        return bb_user_with_plan_by_token($db, $user['access_token']) ?? $user;
    }
    return $user;
}

// History/reporting only - not enforced as a quota. See bb_require_user_access().
function bb_usage_for_user(PDO $db, array $user): array {
    $stmt = $db->prepare("SELECT COALESCE(SUM(units),0) AS used FROM usage_events WHERE user_id=? AND created_at >= ?");
    $stmt->execute([$user['id'], $user['period_started_at']]);
    $row = $stmt->fetch();
    $limit = (float)($user['usage_limit_override'] ?: $user['monthly_prompt_limit']);
    $used = (float)($row['used'] ?? 0);
    return ['used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used), 'periodStartedAt' => $user['period_started_at']];
}

function bb_plan_feature_keys(PDO $db, int $planId): array {
    $stmt = $db->prepare("
        SELECT features.feature_key
        FROM plan_features JOIN features ON features.id = plan_features.feature_id
        WHERE plan_features.plan_id=? AND plan_features.enabled=1 AND features.active=1
    ");
    $stmt->execute([$planId]);
    return array_column($stmt->fetchAll(), 'feature_key');
}

function bb_required_feature_keys(array $input): array {
    $keys = ["activity.{$input['activityType']}", "quantity.{$input['pageCount']}"];
    if (!empty($input['theme']) && $input['theme'] !== 'Custom Idea') $keys[] = bb_theme_feature_key($input['theme']);
    if (trim((string)($input['customDirection'] ?? '')) !== '') $keys[] = 'advanced.custom-direction';
    if (trim((string)($input['avoidTerms'] ?? '')) !== '') $keys[] = 'advanced.custom-direction';
    if (trim((string)($input['learningGoal'] ?? '')) !== '') $keys[] = 'advanced.learning-goal';
    if (trim((string)($input['guideCharacter'] ?? '')) !== '') $keys[] = 'advanced.guide-character';
    return $keys;
}

class BBAccessException extends Exception {}

// Deliberately does not check bb_usage_for_user()/monthly_prompt_limit: BrightBook is
// feature-gated (one-time plans), not a credit/quota system. Usage is recorded for
// history and admin reporting only.
function bb_require_user_access(PDO $db, array $input): array {
    $accessToken = bb_client_token($input);
    $user = bb_user_with_plan_by_token($db, $accessToken);
    if (!$user) throw new BBAccessException('Your account token is not valid.');
    $user = bb_reset_period_if_needed($db, $user);
    if ($user['status'] !== 'active') throw new BBAccessException('Your account is not active. Please contact support.');
    if (!$user['plan_active']) throw new BBAccessException('Your current plan is not active. Please contact support.');
    $enabled = bb_plan_feature_keys($db, (int)$user['plan_id']);
    $enabledSet = array_flip($enabled);
    $missing = array_values(array_filter(bb_required_feature_keys($input), fn($k) => !isset($enabledSet[$k])));
    if (count($missing) > 0) {
        throw new BBAccessException('Your plan does not include: ' . implode(', ', $missing) . '.');
    }
    return ['user' => $user, 'features' => $enabled, 'units' => (int)($input['pageCount'] ?? 0)];
}

function bb_record_usage(PDO $db, array $user, int $units, array $metadata = []): array {
    $db->prepare("INSERT INTO usage_events(user_id,units,event_type,metadata_json) VALUES(?,?,?,?)")
        ->execute([$user['id'], $units, 'prompt_generation', json_encode($metadata)]);
    return bb_usage_for_user($db, $user);
}

function bb_public_user(PDO $db, array $user): array {
    $usage = bb_usage_for_user($db, $user);
    return [
        'id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name'],
        'token' => $user['access_token'], 'planId' => (int)$user['plan_id'], 'planName' => $user['plan_name'],
        'status' => $user['status'], 'limit' => $usage['limit'], 'used' => $usage['used'],
        'remaining' => $usage['remaining'], 'periodStartedAt' => $usage['periodStartedAt'],
        'features' => bb_plan_feature_keys($db, (int)$user['plan_id']),
        'usageLimitOverride' => $user['usage_limit_override'] !== null ? (float)$user['usage_limit_override'] : null,
        'createdAt' => $user['created_at'],
    ];
}

function bb_all_users(PDO $db): array {
    $rows = $db->query("
        SELECT users.*, plans.name AS plan_name, plans.monthly_prompt_limit, plans.active AS plan_active
        FROM users JOIN plans ON plans.id = users.plan_id
        ORDER BY users.id DESC
    ")->fetchAll();
    return array_map(fn($row) => bb_public_user($db, $row), $rows);
}
