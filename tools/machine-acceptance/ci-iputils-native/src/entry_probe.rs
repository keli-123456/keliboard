//! Opt-in, leased outbound ICMP checks. Not an inbound service/censorship test.
use std::collections::{BTreeMap, BTreeSet};
use std::future::Future;
use std::net::Ipv4Addr;
use std::sync::{
    atomic::{AtomicBool, Ordering},
    Mutex, OnceLock,
};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use futures_util::{stream, StreamExt};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};

use crate::panel::PanelClientOptions;

#[derive(Clone, Debug, Deserialize, PartialEq, Eq)]
pub struct Config {
    pub schema: u8,
    pub enabled: bool,
    pub revision: String,
    pub interval_seconds: u64,
    pub timeout_seconds: u64,
    pub targets: Vec<Target>,
}

#[derive(Clone, Debug, Deserialize, PartialEq, Eq)]
pub struct Target {
    pub id: String,
    pub ip: String,
}

#[derive(Clone, Copy, Debug, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum Outcome {
    Reachable,
    Unreachable,
    Error,
}

fn exit_outcome(code: Option<i32>) -> Outcome {
    match code {
        Some(0) => Outcome::Reachable,
        Some(1) => Outcome::Unreachable,
        _ => Outcome::Error,
    }
}

#[derive(Clone, Debug, Serialize)]
struct TargetResult {
    id: String,
    status: Outcome,
    #[serde(skip_serializing_if = "Option::is_none")]
    source_ip: Option<Ipv4Addr>,
}

#[derive(Clone, Copy)]
struct Measurement(Outcome, Option<Ipv4Addr>);

impl From<Outcome> for Measurement {
    fn from(outcome: Outcome) -> Self {
        Self(outcome, None)
    }
}

struct Entry {
    generation: u64,
    config: Config,
    lease_until: Instant,
    next_due: Instant,
    report: Option<Value>,
    preferred: Option<String>,
    consecutive_failures: u8,
    source_failures: BTreeMap<Ipv4Addr, u8>,
}

impl Entry {
    fn complete(&mut self, rows: Vec<TargetResult>, now: Instant, checked_at: u64) {
        if let Some(row) = rows.iter().find(|row| row.status == Outcome::Reachable) {
            self.preferred = Some(row.id.clone());
        }
        let failed = rows.len() == self.config.targets.len()
            && rows.iter().all(|row| row.status == Outcome::Unreachable);
        self.consecutive_failures = if failed {
            self.consecutive_failures.saturating_add(1).min(2)
        } else {
            0
        };
        let mut source_failures = BTreeMap::new();
        for source in rows.iter().filter_map(|row| row.source_ip) {
            if self.config.targets.iter().all(|target| {
                rows.iter().any(|row| {
                    row.id == target.id
                        && row.source_ip == Some(source)
                        && row.status == Outcome::Unreachable
                })
            }) {
                source_failures.insert(
                    source,
                    self.source_failures
                        .get(&source)
                        .copied()
                        .unwrap_or(0)
                        .saturating_add(1)
                        .min(2),
                );
            }
        }
        self.source_failures = source_failures;
        self.report = Some(json!({"schema":1,"revision":self.config.revision,
            "checked_at":checked_at,"state":"ready","targets":rows,
            "consecutive_failures":self.consecutive_failures,"source_failures":self.source_failures}));
        // Wait after completion: slow rounds never queue catch-up work.
        self.next_due = now + Duration::from_secs(self.config.interval_seconds);
    }

    fn snapshot(&self, now: Instant) -> Value {
        if self.lease_until <= now {
            return json!({"schema":1,"revision":self.config.revision,"checked_at":0,"state":"expired","targets":[]});
        }
        self.report.clone().unwrap_or_else(|| json!({"schema":1,"revision":self.config.revision,"checked_at":0,"state":"pending","targets":[]}))
    }
}

#[derive(Default)]
struct State {
    entries: BTreeMap<String, Entry>,
    generation: u64,
}

static STATE: OnceLock<Mutex<State>> = OnceLock::new();
static NOTIFY: OnceLock<tokio::sync::Notify> = OnceLock::new();
static RUNNING: AtomicBool = AtomicBool::new(false);
const LEASE: Duration = Duration::from_secs(600);

fn key(options: &PanelClientOptions) -> String {
    format!(
        "{:x}",
        Sha256::digest(format!(
            "{}\n{}",
            options.api_host.trim_end_matches('/'),
            options.machine_id
        ))
    )
}

fn public_ipv4(value: &str) -> Option<Ipv4Addr> {
    value.parse::<Ipv4Addr>().ok().filter(|ip| {
        let [a, b, c, _] = ip.octets();
        !(ip.is_private()
            || ip.is_loopback()
            || ip.is_link_local()
            || ip.is_unspecified()
            || ip.is_multicast()
            || ip.is_broadcast()
            || ip.is_documentation()
            || a == 0
            || a >= 224
            || (a == 100 && (64..=127).contains(&b))
            || (a == 192 && b == 0 && c == 0)
            || (a == 198 && (b == 18 || b == 19)))
    })
}

fn valid(config: &Config) -> bool {
    let hex = |s: &str, len| {
        s.len() == len
            && s.bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
    };
    let mut ids = BTreeSet::new();
    let mut ips = BTreeSet::new();
    config.schema == 1
        && hex(&config.revision, 64)
        && (5..=600).contains(&config.interval_seconds)
        && (1..=3).contains(&config.timeout_seconds)
        && config.targets.len() <= 8
        && (!config.enabled || !config.targets.is_empty())
        && config.targets.iter().all(|t| {
            hex(&t.id, 16)
                && ids.insert(t.id.clone())
                && public_ipv4(&t.ip).is_some_and(|ip| ips.insert(ip))
        })
}

impl State {
    fn update(&mut self, key: String, config: Option<Config>, now: Instant) {
        let Some(config) = config.filter(|c| c.enabled && valid(c)) else {
            self.entries.remove(&key);
            return;
        };
        self.entries.retain(|_, value| value.lease_until > now);
        if let Some(entry) = self.entries.get_mut(&key) {
            if entry.config == config {
                entry.lease_until = now + LEASE;
                return;
            }
        }
        if self.entries.len() >= 8 && !self.entries.contains_key(&key) {
            return;
        }
        self.generation = self.generation.wrapping_add(1);
        self.entries.insert(
            key,
            Entry {
                generation: self.generation,
                config,
                lease_until: now + LEASE,
                next_due: now,
                report: None,
                preferred: None,
                consecutive_failures: 0,
                source_failures: BTreeMap::new(),
            },
        );
    }
}

/// Called only after an authenticated successful panel response. No ping is awaited here.
pub fn update(options: &PanelClientOptions, value: Option<&Value>) {
    let config = value.and_then(|value| serde_json::from_value::<Config>(value.clone()).ok());
    let state = STATE.get_or_init(Default::default);
    let enabled = if let Ok(mut state) = state.lock() {
        state.update(key(options), config, Instant::now());
        !state.entries.is_empty()
    } else {
        false
    };
    NOTIFY.get_or_init(Default::default).notify_one();
    if enabled && !RUNNING.swap(true, Ordering::AcqRel) {
        if let Ok(runtime) = tokio::runtime::Handle::try_current() {
            runtime.spawn(worker());
        } else {
            RUNNING.store(false, Ordering::Release);
        }
    }
}

pub fn snapshot(options: &PanelClientOptions) -> Value {
    let Some(state) = STATE.get() else {
        return Value::Null;
    };
    let Ok(state) = state.lock() else {
        return Value::Null;
    };
    let Some(entry) = state.entries.get(&key(options)) else {
        return Value::Null;
    };
    entry.snapshot(Instant::now())
}

fn current(key: &str, revision: &str, generation: u64) -> bool {
    STATE
        .get()
        .and_then(|s| s.lock().ok())
        .and_then(|s| {
            s.entries.get(key).map(|e| {
                e.lease_until > Instant::now()
                    && e.config.revision == revision
                    && e.generation == generation
            })
        })
        .unwrap_or(false)
}

async fn probe_round<F, Fut>(
    targets: &[Target],
    preferred: Option<&str>,
    mut prober: F,
) -> Vec<TargetResult>
where
    F: FnMut(Target) -> Fut,
    Fut: Future,
    Fut::Output: Into<Measurement>,
{
    let mut targets = targets.to_vec();
    if let Some(index) = targets
        .iter()
        .position(|target| Some(target.id.as_str()) == preferred)
    {
        targets.swap(0, index);
    }
    let mut rows = Vec::with_capacity(targets.len());
    let mut targets = targets.into_iter();
    if let Some(first) = targets.next() {
        let Measurement(status, source_ip) = prober(first.clone()).await.into();
        rows.push(TargetResult {
            id: first.id,
            status,
            source_ip,
        });
        if status == Outcome::Reachable {
            return rows;
        }
    }
    let mut pending = stream::iter(targets)
        .map(|target| {
            let future = prober(target.clone());
            async move {
                let Measurement(status, source_ip) = future.await.into();
                TargetResult {
                    id: target.id,
                    status,
                    source_ip,
                }
            }
        })
        .buffer_unordered(2);
    while let Some(row) = pending.next().await {
        let reachable = row.status == Outcome::Reachable;
        rows.push(row);
        if reachable {
            break;
        }
    }
    rows
}

async fn worker() {
    loop {
        let now = Instant::now();
        let (work, delay) = if let Some(state) = STATE.get().and_then(|s| s.lock().ok()) {
            let next = state
                .entries
                .iter()
                .filter(|(_, e)| e.lease_until > now)
                .min_by_key(|(_, e)| e.next_due)
                .map(|(k, e)| (k.clone(), e.next_due));
            if let Some((key, _)) = next.clone().filter(|(_, due)| *due <= now) {
                let entry = state.entries.get(&key).expect("entry selected under lock");
                (
                    Some((
                        key,
                        entry.config.clone(),
                        entry.preferred.clone(),
                        entry.generation,
                    )),
                    Duration::ZERO,
                )
            } else {
                (
                    None,
                    next.map(|(_, due)| due.saturating_duration_since(now))
                        .unwrap_or(Duration::from_secs(3600)),
                )
            }
        } else {
            RUNNING.store(false, Ordering::Release);
            return;
        };
        if let Some((key, config, preferred, generation)) = work {
            // One round at a time across profiles; no more than two child processes.
            let run = |target: Target| {
                let key = &key;
                let config = &config;
                async move {
                    if current(key, &config.revision, generation) {
                        ping(&target.ip, config.timeout_seconds).await
                    } else {
                        Outcome::Error.into()
                    }
                }
            };
            let rows = probe_round(&config.targets, preferred.as_deref(), run).await;
            let checked_at = SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .map_or(0, |d| d.as_secs());
            if let Some(mut state) = STATE.get().and_then(|s| s.lock().ok()) {
                if let Some(entry) = state.entries.get_mut(&key).filter(|e| {
                    e.config == config
                        && e.generation == generation
                        && e.lease_until > Instant::now()
                }) {
                    entry.complete(rows, Instant::now(), checked_at);
                }
            }
            continue;
        }
        if delay == Duration::from_secs(3600) {
            NOTIFY.get_or_init(Default::default).notified().await;
        } else {
            tokio::select! { _ = NOTIFY.get_or_init(Default::default).notified() => {}, _ = tokio::time::sleep(delay) => {} }
        }
    }
}

#[cfg(target_os = "linux")]
async fn ping_program() -> Option<std::path::PathBuf> {
    static CACHE: OnceLock<tokio::sync::Mutex<Option<(Instant, Option<std::path::PathBuf>)>>> =
        OnceLock::new();
    let mut cache = CACHE.get_or_init(Default::default).lock().await;
    if let Some((at, path)) = cache
        .as_ref()
        .filter(|(at, _)| at.elapsed() < Duration::from_secs(300))
    {
        let _ = at;
        return path.clone();
    }
    let path = ["/usr/bin/ping", "/bin/ping"]
        .into_iter()
        .map(std::path::PathBuf::from)
        .find(|p| p.is_file());
    let path = if let Some(path) = path {
        let mut cmd = tokio::process::Command::new(&path);
        cmd.arg("-V").kill_on_drop(true);
        match tokio::time::timeout(Duration::from_secs(1), cmd.output()).await {
            Ok(Ok(output))
                if output.status.success() && output.stdout.windows(7).any(|v| v == b"iputils") =>
            {
                Some(path)
            }
            _ => None,
        }
    } else {
        None
    };
    *cache = Some((Instant::now(), path.clone()));
    path
}

#[cfg(any(target_os = "linux", test))]
fn ping_command(
    program: impl AsRef<std::ffi::OsStr>,
    ip: &str,
    timeout: u64,
    source: Option<Ipv4Addr>,
) -> tokio::process::Command {
    let mut cmd = tokio::process::Command::new(program);
    if let Some(source) = source {
        cmd.arg("-I").arg(source.to_string());
    }
    // With -w, iputils can keep transmitting until the deadline despite -c 1.
    cmd.args(["-4", "-n", "-c", "1", "-W", &timeout.to_string(), "--", ip])
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .kill_on_drop(true);
    cmd
}

#[cfg(target_os = "linux")]
async fn ping(ip: &str, timeout: u64) -> Measurement {
    let Some(target) = public_ipv4(ip) else {
        return Outcome::Error.into();
    };
    let Some(program) = ping_program().await else {
        return Outcome::Error.into();
    };
    let source = route_source(target).await;
    let mut cmd = ping_command(program, ip, timeout, source);
    let outcome = match tokio::time::timeout(Duration::from_secs(timeout + 2), cmd.status()).await {
        Ok(Ok(status)) => exit_outcome(status.code()),
        _ => Outcome::Error,
    };
    Measurement(outcome, source)
}

#[cfg(target_os = "linux")]
async fn route_source(target: Ipv4Addr) -> Option<Ipv4Addr> {
    // UDP connect selects a route without sending a packet. Only explicit public bindings are attributed.
    let socket = tokio::net::UdpSocket::bind("0.0.0.0:0").await.ok()?;
    socket.connect((target, 33434)).await.ok()?;
    let ip = socket.local_addr().ok()?.ip();
    public_ipv4(&ip.to_string())
}

#[cfg(not(target_os = "linux"))]
async fn ping(_: &str, _: u64) -> Measurement {
    exit_outcome(None).into()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn single_packet_command_has_no_deadline_or_interval_override() {
        for timeout in [1, 2] {
            let cmd = ping_command(
                "/usr/bin/ping",
                "8.8.8.8",
                timeout,
                Some("1.0.0.1".parse().unwrap()),
            );
            let args: Vec<_> = cmd
                .as_std()
                .get_args()
                .map(|v| v.to_str().unwrap())
                .collect();
            assert_eq!(
                args,
                [
                    "-I",
                    "1.0.0.1",
                    "-4",
                    "-n",
                    "-c",
                    "1",
                    "-W",
                    &timeout.to_string(),
                    "--",
                    "8.8.8.8"
                ]
            );
            assert!(!args.contains(&"-w"));
            assert!(!args.contains(&"-i"));
        }
    }

    #[cfg(target_os = "linux")]
    #[tokio::test]
    #[ignore = "requires a dedicated loopback-only network namespace with controlled echo policy"]
    async fn real_iputils_in_isolated_namespace() {
        assert_eq!(std::env::var("KELI_IPUTILS_ISOLATED").as_deref(), Ok("1"));
        let mode = std::env::var("KELI_IPUTILS_MODE").unwrap();
        assert!(mode == "echo" || mode == "drop");
        let mut cmd = ping_command(
            "/usr/bin/ping",
            "127.0.0.2",
            2,
            Some("127.0.0.1".parse().unwrap()),
        );
        let status = tokio::time::timeout(Duration::from_secs(4), cmd.status())
            .await
            .unwrap()
            .unwrap();
        assert_eq!(status.code(), Some(if mode == "echo" { 0 } else { 1 }));
    }
    fn config() -> Config {
        Config {
            schema: 1,
            enabled: true,
            revision: "a".repeat(64),
            interval_seconds: 120,
            timeout_seconds: 2,
            targets: vec![Target {
                id: "b".repeat(16),
                ip: "8.8.8.8".into(),
            }],
        }
    }
    #[test]
    fn public_literals_only_no_internal_targets_or_shell_arguments() {
        for ip in [
            "127.0.0.1",
            "10.0.0.1",
            "169.254.169.254",
            "100.64.0.1",
            "192.0.2.1",
            "224.0.0.1",
            "::1",
            "::ffff:8.8.8.8",
            "64:ff9b::a00:1",
            "2002:7f00:1::",
            "2001:db8::1",
            "fe80::1",
            "fc00::1",
            "2606:4700:4700::1111",
            "8.8.8.8;echo bad",
            "example.com",
        ] {
            assert!(public_ipv4(ip).is_none(), "{ip}");
        }
        for ip in ["8.8.8.8", "223.5.5.5"] {
            assert!(public_ipv4(ip).is_some());
        }
    }
    #[test]
    fn configuration_is_bounded_and_duplicate_or_invalid_ids_are_rejected() {
        assert!(valid(&config()));
        for seconds in [5, 600] {
            let mut c = config();
            c.interval_seconds = seconds;
            assert!(valid(&c));
        }
        for seconds in [0, 4, 601] {
            let mut c = config();
            c.interval_seconds = seconds;
            assert!(!valid(&c));
        }
        let mut c = config();
        c.targets.push(c.targets[0].clone());
        assert!(!valid(&c));
        let mut c = config();
        c.interval_seconds = 1;
        assert!(!valid(&c));
        let mut c = config();
        c.timeout_seconds = 10;
        assert!(!valid(&c));
        let mut c = config();
        c.revision = "invalid".into();
        assert!(!valid(&c));
        let mut c = config();
        c.targets.clear();
        assert!(!valid(&c));
    }
    #[test]
    fn renewals_do_not_schedule_more_probes_and_disable_or_invalid_config_clears_state() {
        let now = Instant::now();
        let mut state = State::default();
        let c = config();
        state.update("panel".into(), Some(c.clone()), now);
        state.entries.get_mut("panel").unwrap().next_due = now + Duration::from_secs(120);
        state.update(
            "panel".into(),
            Some(c.clone()),
            now + Duration::from_secs(60),
        );
        assert_eq!(
            state.entries["panel"].next_due,
            now + Duration::from_secs(120)
        );
        assert_eq!(
            state.entries["panel"].lease_until,
            now + Duration::from_secs(660)
        );
        let mut disabled = c.clone();
        disabled.enabled = false;
        state.update("panel".into(), Some(disabled), now);
        assert!(state.entries.is_empty());
        state.update("panel".into(), Some(c), now);
        state.update("panel".into(), None, now);
        assert!(state.entries.is_empty());
    }
    #[test]
    fn profiles_are_bounded_and_new_revisions_discard_old_results() {
        let now = Instant::now();
        let mut state = State::default();
        for i in 0..20 {
            state.update(i.to_string(), Some(config()), now);
        }
        assert_eq!(state.entries.len(), 8);
        state.entries.get_mut("0").unwrap().report = Some(json!({"state":"old"}));
        let mut c = config();
        c.revision = "c".repeat(64);
        state.update("0".into(), Some(c), now);
        assert!(state.entries["0"].report.is_none());
        state.update("new".into(), Some(config()), now + Duration::from_secs(601));
        assert_eq!(state.entries.len(), 1);
    }

    #[test]
    fn reenabled_identical_configuration_cannot_accept_a_previous_round() {
        let now = Instant::now();
        let mut state = State::default();
        state.update("panel".into(), Some(config()), now);
        let generation = state.entries["panel"].generation;
        state.update("panel".into(), Some(config()), now);
        assert_eq!(state.entries["panel"].generation, generation);
        state.update("panel".into(), None, now);
        state.update("panel".into(), Some(config()), now);
        assert_ne!(state.entries["panel"].generation, generation);
        assert!(state.entries["panel"].preferred.is_none());
        assert_eq!(state.entries["panel"].consecutive_failures, 0);
    }

    #[test]
    fn lease_expiry_hides_old_results_and_report_encoding_has_no_address_or_output() {
        let now = Instant::now();
        let mut state = State::default();
        state.update("panel".into(), Some(config()), now);
        let entry = state.entries.get_mut("panel").unwrap();
        assert_eq!(entry.snapshot(now)["state"], "pending");
        entry.report = Some(
            json!({"schema":1,"revision":entry.config.revision,"checked_at":1000,"state":"ready",
            "targets":[TargetResult { id: "b".repeat(16), status: Outcome::Reachable, source_ip: None }]}),
        );
        assert_eq!(entry.snapshot(now)["targets"][0]["status"], "reachable");
        assert!(!entry.snapshot(now).to_string().contains("8.8.8.8"));
        let expired = entry.snapshot(now + LEASE);
        assert_eq!(expired["state"], "expired");
        assert_eq!(expired["targets"], json!([]));
    }

    #[test]
    fn only_iputils_no_reply_is_unreachable_other_process_errors_are_unknown() {
        assert_eq!(exit_outcome(Some(0)), Outcome::Reachable);
        assert_eq!(exit_outcome(Some(1)), Outcome::Unreachable);
        for code in [None, Some(2), Some(126), Some(127), Some(-1)] {
            assert_eq!(exit_outcome(code), Outcome::Error);
        }
        assert_eq!(
            serde_json::to_value(Outcome::Unreachable).unwrap(),
            "unreachable"
        );
        assert_eq!(serde_json::to_value(Outcome::Error).unwrap(), "error");
    }

    fn targets() -> Vec<Target> {
        ["8.8.8.8", "223.5.5.5", "119.29.29.29"]
            .iter()
            .enumerate()
            .map(|(i, ip)| Target {
                id: format!("{i:016x}"),
                ip: ip.to_string(),
            })
            .collect()
    }

    #[tokio::test]
    async fn recent_success_is_first_and_short_circuits_all_remaining_targets() {
        let targets = targets();
        let visited = std::sync::Arc::new(Mutex::new(Vec::new()));
        let rows = probe_round(&targets, Some(&targets[2].id), |target| {
            visited.lock().unwrap().push(target.id.clone());
            std::future::ready(Outcome::Reachable)
        })
        .await;
        assert_eq!(*visited.lock().unwrap(), vec![targets[2].id.clone()]);
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].status, Outcome::Reachable);
    }

    #[tokio::test]
    async fn all_failures_are_collected_but_errors_never_become_unreachable() {
        let targets = targets();
        let rows = probe_round(&targets, None, |target| {
            std::future::ready(if target.ip == "223.5.5.5" {
                Outcome::Error
            } else {
                Outcome::Unreachable
            })
        })
        .await;
        assert_eq!(rows.len(), 3);
        assert_eq!(
            rows.iter()
                .filter(|row| row.status == Outcome::Error)
                .count(),
            1
        );
    }

    #[tokio::test]
    async fn fallback_concurrency_is_bounded_and_success_drops_unfinished_work() {
        use std::sync::atomic::AtomicUsize;
        struct Active(std::sync::Arc<AtomicUsize>);
        impl Drop for Active {
            fn drop(&mut self) {
                self.0.fetch_sub(1, Ordering::SeqCst);
            }
        }
        let active = std::sync::Arc::new(AtomicUsize::new(0));
        let peak = std::sync::Arc::new(AtomicUsize::new(0));
        let rows = probe_round(&targets(), None, |target| {
            let active = active.clone();
            let peak = peak.clone();
            async move {
                let count = active.fetch_add(1, Ordering::SeqCst) + 1;
                peak.fetch_max(count, Ordering::SeqCst);
                let _guard = Active(active);
                match target.ip.as_str() {
                    "8.8.8.8" => Outcome::Unreachable,
                    "223.5.5.5" => {
                        tokio::task::yield_now().await;
                        Outcome::Reachable
                    }
                    _ => std::future::pending().await,
                }
            }
        })
        .await;
        assert!(rows.iter().any(|row| row.status == Outcome::Reachable));
        assert_eq!(peak.load(Ordering::SeqCst), 2);
        assert_eq!(active.load(Ordering::SeqCst), 0);
    }

    #[test]
    fn completed_rounds_wait_and_confirmation_resets_on_error_or_success() {
        let now = Instant::now();
        let mut state = State::default();
        let mut c = config();
        c.interval_seconds = 5;
        c.targets = targets();
        state.update("panel".into(), Some(c), now);
        let entry = state.entries.get_mut("panel").unwrap();
        let rows = || {
            targets()
                .into_iter()
                .map(|t| TargetResult {
                    id: t.id,
                    status: Outcome::Unreachable,
                    source_ip: None,
                })
                .collect()
        };
        entry.complete(rows(), now + Duration::from_secs(20), 1000);
        assert_eq!(entry.next_due, now + Duration::from_secs(25));
        assert_eq!(entry.consecutive_failures, 1);
        entry.complete(rows(), now + Duration::from_secs(30), 1010);
        assert_eq!(entry.consecutive_failures, 2);
        entry.complete(
            vec![TargetResult {
                id: targets()[0].id.clone(),
                status: Outcome::Error,
                source_ip: None,
            }],
            now,
            1015,
        );
        assert_eq!(entry.consecutive_failures, 0);
        entry.complete(rows(), now, 1020);
        assert_eq!(entry.consecutive_failures, 1);
        entry.complete(
            vec![TargetResult {
                id: targets()[2].id.clone(),
                status: Outcome::Reachable,
                source_ip: None,
            }],
            now,
            1025,
        );
        assert_eq!(entry.preferred.as_deref(), Some(targets()[2].id.as_str()));
        assert_eq!(entry.consecutive_failures, 0);
        let report = entry.snapshot(now).to_string();
        assert!(!report.contains("119.29.29.29"));
    }

    #[test]
    fn ipv6_targets_cannot_schedule_a_round() {
        let now = Instant::now();
        let mut state = State::default();
        state.update("panel".into(), Some(config()), now);
        assert_eq!(state.entries.len(), 1);
        let mut mixed = config();
        mixed.targets.push(Target {
            id: "c".repeat(16),
            ip: "2606:4700:4700::1111".into(),
        });
        state.update("panel".into(), Some(mixed), now);
        assert!(state.entries.is_empty());
    }

    #[test]
    fn failed_ipv4_rounds_are_attributed_to_the_exact_source() {
        let now = Instant::now();
        let mut state = State::default();
        state.update("panel".into(), Some(config()), now);
        let entry = state.entries.get_mut("panel").unwrap();
        let round = vec![TargetResult {
            id: entry.config.targets[0].id.clone(),
            status: Outcome::Unreachable,
            source_ip: Some("1.0.0.1".parse().unwrap()),
        }];
        entry.complete(round.clone(), now, 1000);
        entry.complete(round.clone(), now, 1005);
        assert_eq!(entry.snapshot(now)["source_failures"]["1.0.0.1"], 2);
        let mut switched = round;
        switched[0].source_ip = Some("1.1.1.1".parse().unwrap());
        entry.complete(switched, now, 1010);
        assert_eq!(entry.snapshot(now)["source_failures"], json!({"1.1.1.1":1}));
    }
}
