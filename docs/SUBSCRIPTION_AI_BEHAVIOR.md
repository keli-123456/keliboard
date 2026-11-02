# Independent Subscription AI

This is a versioned, bounded decision layer, not a self-training model and not
permission for a model to edit code, disable existing rules, or expand its tools.

## Modes

- `shadow` (default): collect independent behavior summaries and save proposals.
  Never notify, throttle, suspend, or rotate a token, even when a proposal asks.
- `independent`: use the same behavior evidence for authorized subscription-only
  actions. Sparse history cannot authorize execution. Existing rules still apply.
- `legacy`: compatibility for old settings and regression tests. Production AI
  execution through this path is blocked; it cannot bypass admission.

The master switch is off by default. Applying migrations does not enable it.
An explicit `ai_auto_site_ids` list restricts capture, scheduling, analysis and
execution to those account site IDs (null account site IDs map to 0). An empty
list permits no sites; null retains legacy all-site scope. Saving from an older
client that omits this field preserves the stored scope, never widens it.
Independent observation requires both subscription-AI migrations, the enabled
SubscriptionControl plugin, the existing `redis_ai:risk_ai` worker, and scheduler.
Buffers, quotas and reservation locks explicitly use the shared `redis` cache
store, even when the application's default cache is file-based. All web/worker
instances must share that store and application key.
No node upgrade is needed. Subscription requests never invoke a model.

## Evidence And Resource Bounds

Normal and blocked authenticated subscription requests are observed independently
of legacy alarms. Only ordinary, non-banned accounts participate. Source IPs use
the existing trusted-proxy resolver. Network identities are account/credential-
scoped keyed hashes; raw IPs, raw user agents, credentials and emails are not sent
to the model. Client categories are parsed locally, not treated as rule hits.

Redis buffers use nonblocking per-account locks, five-minute buckets, a ten-minute
TTL, and at most 16 network/client dimensions each. Admission is capped at 120 new
buffers per minute globally. Persistence is capped at one snapshot per account
per minute and 60 writes per minute globally. Contention, capacity limits and
unflushed tails lose observations. Counts are **lower bounds**, neither full
population coverage nor exact traffic/billing accounting. Sampling is not random
or representative; do not infer prevalence or calibrated risk probabilities.
The scheduler deletes up to 2,000 behavior buckets older than seven days per run.

Reference evidence is isolated by account, site and credential epoch. It excludes
the most recent hour, buckets with observed blocks/errors, and manually confirmed
abuse windows. Missing tails can hide outcomes; this is not a trusted clean set.
Execution eligibility needs at least eight reference buckets spanning 24 hours;
this is a minimum-data safeguard, not a fraud classification or proof of safety.
Unreviewed allowed history is explicitly not verified legitimate history. A new
attack could contaminate it; no poisoning-resistance guarantee has been measured.

## Decisions, Review And Rollout

The model receives numeric measurements, reference summaries and selected manual
review counts for the same policy version. It chooses from bounded actions; no
fixed IP/region/UA penalty thresholds or legacy risk scores are supplied to the
independent path. Claims of confidence are not calibrated probabilities.
Analysis has its own hourly budget (default 10, maximum 30), so this is not
continuous model analysis of every account or a complete-coverage guarantee.

Every policy save creates an immutable revision. Actions freeze evidence,
authorization revision, model name and engine version. A stale page cannot
overwrite newer authorization. Model/provider implementations can change behind
the same model name: this metadata is not proof of reproducibility.

Manual reviews label a decision confirmed, false positive, or uncertain. False
positives revoke only that action's remaining temporary protection; they cannot
undo token rotation or lift independent manual/rule bans. Review counts describe
selected reviewed cases, not population accuracy. Reviews do not fine-tune the
model or automatically rewrite policies. Quiet after a restriction is not proof
of recovery. Baseline snapshots are evidence, not a permanently trusted profile.

Start with shadow mode and review normal network changes, legitimate multi-client
usage, cold starts, suspicious sharing, and provider/queue failures. Real-model
validation, representative comparisons and a versioned rule-retirement workflow
remain prerequisites for replacing old rules.
This implementation does not automatically retire them. No production enablement,
provider calls, deployment or node changes are part of local automated tests.

## Paired Acceptance And Execution Admission

Migration `2026_11_02_000003` adds a separate comparison ledger and admission lock.
No benchmark means no production AI execution, including through legacy AI mode.
The old deterministic plugin rules remain independent and are not disabled.

An administrator starts a site-specific run while AI is in shadow mode. Model
endpoint/key/configuration, source code, effective short-action limits and old
rule configuration are fingerprinted without storing provider secrets. Teaching
is frozen before the run: fixed rule intents plus up to four independently
labeled learning cases. This is in-context guidance, not weight training. The
model receives no current legacy outcome, action-review verdict, holdout answer,
or holdout feedback count. Historical features can still be causally affected by
old rules; hiding answers does not eliminate all observational bias.

Accounts are permanently split by a keyed account/site hash: one fifth learning,
four fifths holdout. A run freezes its first 600 distinct holdout accounts,
without choosing based on labels or successful predictions, and stops collecting
at 600 holdouts or 1,000 total cases. Previously examined holdout accounts cannot
enter a later run. An unchanged experiment with existing cases cannot simply be
cleared and retried. Case enrollment precedes inference; failures are retained.

The reference is whether legacy rules restricted the **same persisted sampled
window**, not a counterfactual replay of complete traffic. AI predictions reflect
executor eligibility: sparse history would not restrict. Notifications alone do
not count as a restriction. Errors or AI-influenced windows have no valid legacy
comparator and cannot pass. Sampling limitations remain in force.

The independent review queue hides both predictions, but exposes the account ID
so the administrator can investigate independent facts. `benign`, `abuse`, and
`uncertain` are separate from the existing AI-correctness labels. A human must
attest independent review; the software cannot prove they avoided other pages
or that their factual judgment is correct. Every correction appends history and
revokes existing pilot grants for that run.

Protocol `paired-shadow-v1` requires all 600 fixed cases completed, paired and
independently labeled without uncertainty, including at least 200 benign and
200 abusive accounts. It checks:

- Strictly fewer observed benign restrictions, plus a one-sided exact sign test
  on discordant benign pairs with p <= 0.025.
- No more observed abuse misses, plus a one-sided nominal 97.5% Clopper-Pearson
  upper bound <= 0.02 on *additional* misses, without offsetting them by wins.
- Known usage and latency on every case, evidence no older than 300 seconds when
  enrolled, queue-plus-model P95 <= 120 seconds, and <= 8,000 reported tokens per
  fixed case on average. Unknown usage is not treated as zero cost.

These are predeclared limited-pilot criteria, not proof of universal superiority,
random sampling, independent real-world subjects, zero added misses, or overall
95% coverage across adaptive repeated experiments. Correlated accounts and
operator labeling bias remain risks. Queue/model time is not a client speed test
or a complete head-to-head detection-latency measurement. CPU, RSS and monetary
cost are not measured by this comparison and require separate human signoff.

Passing does not activate AI. A human can approve at most ten ordinary accounts
at that site for one hour, after explicit operational signoff. The saved execution
policy must cap protection at 120 seconds and prohibit token rotation and longer
suspension. Only notifications, throttling and short subscription protection are
available. Both policy saves and runtime execution check admission; other accounts
continue under old rules only. Label corrections, cancellation, expiry, a newer
run, or a mismatching configuration fail closed. Emergency stop revokes all pilot
grants. An opaque provider model alias changing its weights cannot be detected;
an identical fingerprint is not proof of model reproducibility.
