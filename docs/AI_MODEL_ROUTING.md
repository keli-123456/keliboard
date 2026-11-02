# Ticket and subscription risk model routing

The connection URL, encrypted API key, private-provider policy and master AI switch
remain shared. Changing a model does not enable automatic replies, change legacy
rules, or grant AI enforcement permissions.

## Suggested starting values

| Workload | Model ID | Effort | Request timeout | Generation budget |
| --- | --- | --- | --- | --- |
| Ticket | `gpt-6.1-sol` | `low` | 60 seconds | 4096 tokens |
| Subscription risk | `gpt-6-luna` | `low` | 30 seconds | 4096 tokens |

These are starting values to test, not measured latency or quality guarantees.
The admin provider settings preset fills these values without changing permissions.
The existing settings editor saves drafts using its normal save/autosave lifecycle.
No preset is applied at deployment or application startup.

`risk_ai_model` is optional: blank retains the ticket model for backward compatibility.
`risk_ai_reasoning_effort`, `risk_ai_max_tokens`, and `risk_ai_timeout` are independent.
Risk timeout is capped at 45 seconds so the automatic risk worker's 60-second
deadline leaves time to record failure. The advisory review retains at most two
attempts, both bounded by the configured generation limit. Automatic risk decisions
retain their single-call behavior and existing cooldown.

## GPT-6 request compatibility

Both selected models support Chat Completions for this JSON/text workflow. Requests
use `max_completion_tokens` (reasoning plus visible output), `reasoning_effort`, and
no sampling parameters. Sol does not accept `none`; stale or unsupported efforts
fall back to `low`. Luna risk settings can select `none`. Older non-reasoning
providers retain their existing `temperature` and `max_tokens` requests; existing
GPT-5 and o-series behavior is unchanged.

The vision probe and screenshot extraction preserve GPT-6's configured combined
budget instead of replacing it with a 128/1024-token visible-output allowance.
Their existing time limits, attachment consent, sanitization and human-review
requirements remain unchanged. A truncated or filtered completion is rejected,
including syntactically complete JSON returned with a `length` finish reason.

## Testing and execution boundary

The ticket connection test is unchanged. The risk test uses the effective risk
profile and a fixed synthetic JSON ping, never real subscription or ticket data.
Both use the existing administrator-only endpoint. Secrets and upstream response
bodies are never returned to the browser. Connection tests are explicit and may
incur provider usage; saving settings does not run them automatically.
The existing manually priced CNY cost estimate is not a mixed-model invoice;
use provider usage records for actual model-specific billing.

Advisor requests, shadow/automatic risk decisions, readiness indicators and the
admission benchmark use the same effective risk profile. Benchmark context includes
model, reasoning effort, timeout, generation budget, credential, endpoint and
provider implementation hashes. A risk profile change invalidates admission.
Changing only the ticket model does not invalidate an explicitly selected risk
model's benchmark. The existing shadow, manual review, cohort, admission and pilot
limits remain in effect. Request compatibility tests are not proof of risk quality
or an authorization to activate enforcement.

Official sources checked on 2026-11-02:

- https://developers.openai.com/api/docs/models/gpt-6.1-sol
- https://developers.openai.com/api/docs/models/gpt-6-luna
- https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create
