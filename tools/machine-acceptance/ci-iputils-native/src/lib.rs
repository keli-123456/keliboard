#![allow(dead_code)]
mod panel {
    pub struct PanelClientOptions {
        pub api_host: String,
        pub machine_id: i64,
    }
}

#[path = "entry_probe.rs"]
mod entry_probe;
