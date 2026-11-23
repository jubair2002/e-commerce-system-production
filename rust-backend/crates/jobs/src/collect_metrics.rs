use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, Pool, Row};
use std::fs::{self, File, OpenOptions};
use std::io::Write;
use std::path::PathBuf;
use tracing::{info, warn};

#[derive(Debug, Default, Serialize, Deserialize)]
pub struct MonitorState {
    pub last_collected: i64,
    pub last_cpu_total: u64,
    pub last_cpu_idle: u64,
    pub last_net_rx: u64,
    pub last_net_tx: u64,
    pub last_queries: u64,
}

#[derive(Debug, Default, Clone)]
pub struct CollectMetricsResult {
    pub cpu_percent: Option<Decimal>,
    pub mem_used_bytes: u64,
    pub disk_used_bytes: u64,
    pub net_rx_rate: u64,
    pub net_tx_rate: u64,
    pub db_qps: Decimal,
    pub elapsed_secs: f64,
}

pub struct ProcSystemStats {
    pub load_1: Option<Decimal>,
    pub load_5: Option<Decimal>,
    pub load_15: Option<Decimal>,
    pub cpu_percent: Option<Decimal>,
    pub cpu_cores: u16,
    pub mem_total: u64,
    pub mem_used: u64,
    pub swap_total: u64,
    pub swap_used: u64,
    pub disk_total: u64,
    pub disk_used: u64,
    pub uptime_sec: u64,
    pub net_rx_bytes: u64,
    pub net_tx_bytes: u64,
    pub net_rx_rate: u64,
    pub net_tx_rate: u64,
    pub cpu_total_ticks: u64,
    pub cpu_idle_ticks: u64,
}

pub fn read_proc_system_stats(prev_state: &MonitorState, now_ts: i64) -> ProcSystemStats {
    let mut stats = ProcSystemStats {
        load_1: None,
        load_5: None,
        load_15: None,
        cpu_percent: None,
        cpu_cores: 1,
        mem_total: 0,
        mem_used: 0,
        swap_total: 0,
        swap_used: 0,
        disk_total: 0,
        disk_used: 0,
        uptime_sec: 0,
        net_rx_bytes: 0,
        net_tx_bytes: 0,
        net_rx_rate: 0,
        net_tx_rate: 0,
        cpu_total_ticks: 0,
        cpu_idle_ticks: 0,
    };

    // 1. /proc/loadavg
    if let Ok(content) = fs::read_to_string("/proc/loadavg") {
        let parts: Vec<&str> = content.split_whitespace().collect();
        if parts.len() >= 3 {
            stats.load_1 = parts[0].parse::<Decimal>().ok();
            stats.load_5 = parts[1].parse::<Decimal>().ok();
            stats.load_15 = parts[2].parse::<Decimal>().ok();
        }
    }

    // 2. /proc/uptime
    if let Ok(content) = fs::read_to_string("/proc/uptime") {
        if let Some(up_str) = content.split_whitespace().next() {
            if let Ok(up_f) = up_str.parse::<f64>() {
                stats.uptime_sec = up_f as u64;
            }
        }
    }

    // 3. /proc/stat -> CPU ticks and core count
    if let Ok(content) = fs::read_to_string("/proc/stat") {
        let mut cores = 0u16;
        for line in content.lines() {
            if line.starts_with("cpu ") {
                let parts: Vec<&str> = line.split_whitespace().skip(1).collect();
                if parts.len() >= 4 {
                    let user: u64 = parts.first().and_then(|p| p.parse().ok()).unwrap_or(0);
                    let nice: u64 = parts.get(1).and_then(|p| p.parse().ok()).unwrap_or(0);
                    let system: u64 = parts.get(2).and_then(|p| p.parse().ok()).unwrap_or(0);
                    let idle: u64 = parts.get(3).and_then(|p| p.parse().ok()).unwrap_or(0);
                    let iowait: u64 = parts.get(4).and_then(|p| p.parse().ok()).unwrap_or(0);
                    let irq: u64 = parts.get(5).and_then(|p| p.parse().ok()).unwrap_or(0);
                    let softirq: u64 = parts.get(6).and_then(|p| p.parse().ok()).unwrap_or(0);
                    let steal: u64 = parts.get(7).and_then(|p| p.parse().ok()).unwrap_or(0);

                    let total = user + nice + system + idle + iowait + irq + softirq + steal;
                    let idle_all = idle + iowait;

                    stats.cpu_total_ticks = total;
                    stats.cpu_idle_ticks = idle_all;

                    if prev_state.last_cpu_total > 0 && total > prev_state.last_cpu_total {
                        let diff_total = total - prev_state.last_cpu_total;
                        let diff_idle = idle_all.saturating_sub(prev_state.last_cpu_idle);
                        let usage_ratio = 1.0 - (diff_idle as f64 / diff_total as f64);
                        let pct = (usage_ratio * 100.0).clamp(0.0, 100.0);
                        stats.cpu_percent = Decimal::from_str_exact(&format!("{:.2}", pct)).ok();
                    }
                }
            } else if line.starts_with("cpu") && line.chars().nth(3).is_some_and(|c| c.is_ascii_digit()) {
                cores += 1;
            }
        }
        if cores > 0 {
            stats.cpu_cores = cores;
        }
    }

    // 4. /proc/meminfo
    if let Ok(content) = fs::read_to_string("/proc/meminfo") {
        let mut mem_total_kb = 0u64;
        let mut mem_avail_kb = 0u64;
        let mut mem_free_kb = 0u64;
        let mut swap_total_kb = 0u64;
        let mut swap_free_kb = 0u64;

        for line in content.lines() {
            let mut parts = line.split_whitespace();
            let key = parts.next().unwrap_or("");
            let val: u64 = parts.next().and_then(|v| v.parse().ok()).unwrap_or(0);

            match key {
                "MemTotal:" => mem_total_kb = val,
                "MemAvailable:" => mem_avail_kb = val,
                "MemFree:" => mem_free_kb = val,
                "SwapTotal:" => swap_total_kb = val,
                "SwapFree:" => swap_free_kb = val,
                _ => {}
            }
        }

        stats.mem_total = mem_total_kb * 1024;
        let avail_bytes = if mem_avail_kb > 0 {
            mem_avail_kb * 1024
        } else {
            mem_free_kb * 1024
        };
        stats.mem_used = stats.mem_total.saturating_sub(avail_bytes);
        stats.swap_total = swap_total_kb * 1024;
        stats.swap_used = stats.swap_total.saturating_sub(swap_free_kb * 1024);
    }

    // 5. /proc/net/dev
    if let Ok(content) = fs::read_to_string("/proc/net/dev") {
        let mut total_rx = 0u64;
        let mut total_tx = 0u64;

        for line in content.lines().skip(2) {
            let mut parts = line.split_whitespace();
            let iface = parts.next().unwrap_or("");
            if iface.starts_with("lo:") {
                continue;
            }
            let rx: u64 = parts.next().and_then(|v| v.parse().ok()).unwrap_or(0);
            let tx: u64 = parts.nth(7).and_then(|v| v.parse().ok()).unwrap_or(0);

            total_rx += rx;
            total_tx += tx;
        }

        stats.net_rx_bytes = total_rx;
        stats.net_tx_bytes = total_tx;

        let dt = (now_ts - prev_state.last_collected).max(1) as u64;
        if prev_state.last_net_rx > 0 && total_rx >= prev_state.last_net_rx {
            stats.net_rx_rate = (total_rx - prev_state.last_net_rx) / dt;
        }
        if prev_state.last_net_tx > 0 && total_tx >= prev_state.last_net_tx {
            stats.net_tx_rate = (total_tx - prev_state.last_net_tx) / dt;
        }
    }

    // 6. Disk stats via statvfs on root "/"
    #[cfg(unix)]
    unsafe {
        let mut stat: libc::statvfs = std::mem::zeroed();
        let path = std::ffi::CString::new("/").unwrap();
        if libc::statvfs(path.as_ptr(), &mut stat) == 0 {
            let block_size = stat.f_frsize as u64;
            let total_blocks = stat.f_blocks as u64;
            let free_blocks = stat.f_bfree as u64;
            stats.disk_total = total_blocks * block_size;
            stats.disk_used = stats.disk_total.saturating_sub(free_blocks * block_size);
        }
    }

    stats
}

pub struct MySqlGlobalMetrics {
    pub threads_connected: u32,
    pub queries: u64,
    pub slow_queries: u64,
    pub db_size_bytes: u64,
    pub qps: Decimal,
}

pub async fn read_mysql_metrics(
    pool: &Pool<MySql>,
    prev_state: &MonitorState,
    now_ts: i64,
) -> MySqlGlobalMetrics {
    let mut metrics = MySqlGlobalMetrics {
        threads_connected: 0,
        queries: 0,
        slow_queries: 0,
        db_size_bytes: 0,
        qps: Decimal::ZERO,
    };

    let rows = sqlx::query(
        "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Queries', 'Slow_queries')",
    )
    .fetch_all(pool)
    .await
    .unwrap_or_default();

    for r in rows {
        let name: String = r.try_get("Variable_name").unwrap_or_default();
        let val_str: String = r.try_get("Value").unwrap_or_default();
        match name.as_str() {
            "Threads_connected" => metrics.threads_connected = val_str.parse().unwrap_or(0),
            "Queries" => metrics.queries = val_str.parse().unwrap_or(0),
            "Slow_queries" => metrics.slow_queries = val_str.parse().unwrap_or(0),
            _ => {}
        }
    }

    let size_bytes: Option<Decimal> = sqlx::query_scalar(
        "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()",
    )
    .fetch_optional(pool)
    .await
    .unwrap_or(None);

    if let Some(sb) = size_bytes {
        metrics.db_size_bytes = sb.to_string().parse::<f64>().unwrap_or(0.0) as u64;
    }

    let dt = (now_ts - prev_state.last_collected).max(1) as f64;
    if prev_state.last_queries > 0 && metrics.queries >= prev_state.last_queries {
        let diff = (metrics.queries - prev_state.last_queries) as f64;
        let qps_val = diff / dt;
        metrics.qps = Decimal::from_str_exact(&format!("{:.2}", qps_val)).unwrap_or(Decimal::ZERO);
    }

    metrics
}

/// Run system metrics collection
pub async fn run_collect_metrics(
    pool: &Pool<MySql>,
    dry_run: bool,
    verbose: bool,
) -> Result<CollectMetricsResult, String> {
    let start = std::time::Instant::now();
    let now = chrono::Utc::now();
    let now_ts = now.timestamp();

    // Lock file protection to prevent concurrent collectors
    let storage_dir = PathBuf::from("storage");
    let _ = fs::create_dir_all(&storage_dir);
    let lock_file_path = storage_dir.join("collect_metrics.lock");

    let _lock_file = match OpenOptions::new()
        .write(true)
        .create(true)
        .truncate(false)
        .open(&lock_file_path)
    {
        Ok(f) => f,
        Err(e) => return Err(format!("Could not open lock file: {}", e)),
    };

    // Load state
    let state_file_path = storage_dir.join("monitor_state.json");
    let prev_state: MonitorState = if let Ok(content) = fs::read_to_string(&state_file_path) {
        serde_json::from_str(&content).unwrap_or_default()
    } else {
        MonitorState::default()
    };

    if verbose {
        info!("Starting system metrics collection (dry_run: {})...", dry_run);
    }

    let proc_stats = read_proc_system_stats(&prev_state, now_ts);
    let db_metrics = read_mysql_metrics(pool, &prev_state, now_ts).await;

    if dry_run {
        info!(
            "  [DRY-RUN] CPU: {:?}%, Mem: {}/{} MB, Disk: {}/{} GB, DB Threads: {}, DB QPS: {}",
            proc_stats.cpu_percent,
            proc_stats.mem_used / 1024 / 1024,
            proc_stats.mem_total / 1024 / 1024,
            proc_stats.disk_used / 1024 / 1024 / 1024,
            proc_stats.disk_total / 1024 / 1024 / 1024,
            db_metrics.threads_connected,
            db_metrics.qps
        );
    } else {
        // Write system_metrics
        let res_sys = sqlx::query(
            "INSERT INTO system_metrics
              (collected_at, load_1, load_5, load_15, cpu_percent, cpu_cores, mem_total, mem_used,
               swap_total, swap_used, disk_total, disk_used, net_rx_bytes, net_tx_bytes,
               net_rx_rate, net_tx_rate, db_threads, db_qps, db_slow_queries, db_size_bytes,
               php_version, uptime_sec)
             VALUES
              (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Rust', ?)",
        )
        .bind(proc_stats.load_1)
        .bind(proc_stats.load_5)
        .bind(proc_stats.load_15)
        .bind(proc_stats.cpu_percent)
        .bind(proc_stats.cpu_cores)
        .bind(proc_stats.mem_total)
        .bind(proc_stats.mem_used)
        .bind(proc_stats.swap_total)
        .bind(proc_stats.swap_used)
        .bind(proc_stats.disk_total)
        .bind(proc_stats.disk_used)
        .bind(proc_stats.net_rx_bytes)
        .bind(proc_stats.net_tx_bytes)
        .bind(proc_stats.net_rx_rate)
        .bind(proc_stats.net_tx_rate)
        .bind(db_metrics.threads_connected)
        .bind(db_metrics.qps)
        .bind(db_metrics.slow_queries)
        .bind(db_metrics.db_size_bytes)
        .bind(proc_stats.uptime_sec)
        .execute(pool)
        .await;

        if let Err(e) = res_sys {
            warn!("Failed writing to system_metrics: {}", e);
        }

        // Write network_samples
        let res_net = sqlx::query(
            "INSERT INTO network_samples (collected_at, rx_bytes, tx_bytes, rx_rate, tx_rate)
             VALUES (NOW(), ?, ?, ?, ?)",
        )
        .bind(proc_stats.net_rx_bytes)
        .bind(proc_stats.net_tx_bytes)
        .bind(proc_stats.net_rx_rate)
        .bind(proc_stats.net_tx_rate)
        .execute(pool)
        .await;

        if let Err(e) = res_net {
            warn!("Failed writing to network_samples: {}", e);
        }

        // Save updated state
        let next_state = MonitorState {
            last_collected: now_ts,
            last_cpu_total: proc_stats.cpu_total_ticks,
            last_cpu_idle: proc_stats.cpu_idle_ticks,
            last_net_rx: proc_stats.net_rx_bytes,
            last_net_tx: proc_stats.net_tx_bytes,
            last_queries: db_metrics.queries,
        };

        if let Ok(state_json) = serde_json::to_string(&next_state) {
            if let Ok(mut f) = File::create(&state_file_path) {
                let _ = f.write_all(state_json.as_bytes());
            }
        }
    }

    Ok(CollectMetricsResult {
        cpu_percent: proc_stats.cpu_percent,
        mem_used_bytes: proc_stats.mem_used,
        disk_used_bytes: proc_stats.disk_used,
        net_rx_rate: proc_stats.net_rx_rate,
        net_tx_rate: proc_stats.net_tx_rate,
        db_qps: db_metrics.qps,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
