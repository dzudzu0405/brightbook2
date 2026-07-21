const crypto = require("node:crypto");
const { db } = require("./db");
const { themeFeatureKey } = require("./theme");

const ADMIN_TOKEN = process.env.ADMIN_TOKEN || "brightbook-admin";

function adminAllowed(req) {
  const provided = req.headers["x-admin-token"];
  if (typeof provided !== "string" || provided.length !== ADMIN_TOKEN.length) return false;
  return crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(ADMIN_TOKEN));
}
function clientToken(req,input={}) {
  return String(req.headers["x-user-token"] || input.userToken || "demo-token").trim();
}
function userWithPlanByToken(accessToken) {
  return db.prepare(`
    SELECT users.*, plans.name AS plan_name, plans.monthly_prompt_limit, plans.active AS plan_active
    FROM users JOIN plans ON plans.id = users.plan_id
    WHERE users.access_token = ?
  `).get(accessToken);
}
function resetPeriodIfNeeded(user) {
  const started = new Date(String(user.period_started_at).replace(" ", "T") + "Z");
  const days = (Date.now() - started.getTime()) / 86400000;
  if (Number.isFinite(days) && days >= 30) {
    db.prepare("UPDATE users SET period_started_at=CURRENT_TIMESTAMP WHERE id=?").run(user.id);
    return db.prepare(`
      SELECT users.*, plans.name AS plan_name, plans.monthly_prompt_limit, plans.active AS plan_active
      FROM users JOIN plans ON plans.id = users.plan_id
      WHERE users.id = ?
    `).get(user.id);
  }
  return user;
}
// History/reporting only — not enforced as a quota. See requireUserAccess().
function usageForUser(user) {
  const row = db.prepare("SELECT COALESCE(SUM(units),0) AS used FROM usage_events WHERE user_id=? AND created_at >= ?")
    .get(user.id, user.period_started_at);
  const limit = Number(user.usage_limit_override || user.monthly_prompt_limit);
  const used = Number(row.used || 0);
  return { used, limit, remaining: Math.max(0, limit - used), periodStartedAt: user.period_started_at };
}
function planFeatureKeys(planId) {
  return db.prepare(`
    SELECT features.feature_key
    FROM plan_features JOIN features ON features.id = plan_features.feature_id
    WHERE plan_features.plan_id=? AND plan_features.enabled=1 AND features.active=1
  `).all(planId).map(row => row.feature_key);
}
function requiredFeatureKeys(input) {
  const keys = [`activity.${input.activityType}`, `quantity.${input.pageCount}`];
  if (input.theme && input.theme !== "Custom Idea") keys.push(themeFeatureKey(input.theme));
  if (String(input.customDirection || "").trim()) keys.push("advanced.custom-direction");
  if (String(input.avoidTerms || "").trim()) keys.push("advanced.custom-direction");
  if (String(input.learningGoal || "").trim()) keys.push("advanced.learning-goal");
  if (String(input.guideCharacter || "").trim()) keys.push("advanced.guide-character");
  return keys;
}
// Deliberately does not check usageForUser()/monthly_prompt_limit: BrightBook is
// feature-gated (one-time plans), not a credit/quota system. Usage is recorded for
// history and admin reporting only.
function requireUserAccess(req,input) {
  const accessToken = clientToken(req,input);
  let user = userWithPlanByToken(accessToken);
  if (!user) throw new Error("Your account token is not valid.");
  user = resetPeriodIfNeeded(user);
  if (user.status !== "active") throw new Error("Your account is not active. Please contact support.");
  if (!user.plan_active) throw new Error("Your current plan is not active. Please contact support.");
  const enabled = new Set(planFeatureKeys(user.plan_id));
  const missing = requiredFeatureKeys(input).filter(key => !enabled.has(key));
  if (missing.length) {
    throw new Error(`Your plan does not include: ${missing.join(", ")}.`);
  }
  return { user, features:[...enabled], units:Number(input.pageCount || 0) };
}
function recordUsage(user,units,metadata={}) {
  db.prepare("INSERT INTO usage_events(user_id,units,event_type,metadata_json) VALUES(?,?,?,?)")
    .run(user.id, units, "prompt_generation", JSON.stringify(metadata));
  return usageForUser(user);
}
function publicUser(user) {
  const usage = usageForUser(user);
  return {
    id:user.id,email:user.email,name:user.name,token:user.access_token,planId:user.plan_id,planName:user.plan_name,
    status:user.status,limit:usage.limit,used:usage.used,remaining:usage.remaining,periodStartedAt:usage.periodStartedAt,
    features:planFeatureKeys(user.plan_id),
    usageLimitOverride:user.usage_limit_override,createdAt:user.created_at
  };
}
function allUsers() {
  return db.prepare(`
    SELECT users.*, plans.name AS plan_name, plans.monthly_prompt_limit, plans.active AS plan_active
    FROM users JOIN plans ON plans.id = users.plan_id
    ORDER BY users.id DESC
  `).all().map(publicUser);
}

module.exports = {
  ADMIN_TOKEN, adminAllowed, clientToken, userWithPlanByToken, resetPeriodIfNeeded,
  usageForUser, planFeatureKeys, requiredFeatureKeys, requireUserAccess, recordUsage,
  publicUser, allUsers
};
