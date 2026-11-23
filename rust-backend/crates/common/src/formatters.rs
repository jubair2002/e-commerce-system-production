use chrono::{DateTime, NaiveDateTime, TimeZone, Utc};
use chrono_tz::Tz;
use rust_decimal::Decimal;

/// Convert a string to a URL-safe slug matching PHP's slugify()
pub fn slugify(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut last_was_dash = false;

    for c in text.chars() {
        if c.is_alphanumeric() {
            for lc in c.to_lowercase() {
                out.push(lc);
            }
            last_was_dash = false;
        } else if !last_was_dash && !out.is_empty() {
            out.push('-');
            last_was_dash = true;
        }
    }

    let trimmed = out.trim_matches('-');
    if trimmed.is_empty() {
        "n-a".to_string()
    } else {
        trimmed.to_string()
    }
}

/// Format order number like ORD-YYYYMM-0001 (sequence is padded to at least 4 digits)
pub fn format_order_number(prefix: &str, year_month: &str, sequence: u64) -> String {
    format!("{}-{}-{:04}", prefix, year_month, sequence)
}

/// HTML escaping matching PHP htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
pub fn escape_html(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            other => out.push(other),
        }
    }
    out
}

/// Number formatting matching PHP number_format($num, $decimals, $dec_point, $thousands_sep)
pub fn number_format(num: Decimal, decimals: u32, dec_point: &str, thousands_sep: &str) -> String {
    use rust_decimal::RoundingStrategy;
    let rounded = num.round_dp_with_strategy(decimals, RoundingStrategy::MidpointAwayFromZero);
    let s = rounded.to_string();

    let (neg, unsigned) = if let Some(stripped) = s.strip_prefix('-') {
        (true, stripped)
    } else {
        (false, s.as_str())
    };

    let (int_part, frac_part) = match unsigned.split_once('.') {
        Some((i, f)) => (i, Some(f)),
        None => (unsigned, None),
    };

    // Add thousands separators
    let mut formatted_int = String::with_capacity(int_part.len() + int_part.len() / 3);
    let len = int_part.len();
    for (idx, ch) in int_part.chars().enumerate() {
        formatted_int.push(ch);
        let rem = len - 1 - idx;
        if rem > 0 && rem % 3 == 0 {
            formatted_int.push_str(thousands_sep);
        }
    }

    let mut result = String::with_capacity(formatted_int.len() + 10);
    if neg {
        result.push('-');
    }
    result.push_str(&formatted_int);

    if decimals > 0 {
        result.push_str(dec_point);
        let current_frac = frac_part.unwrap_or("");
        result.push_str(current_frac);
        for _ in current_frac.len()..(decimals as usize) {
            result.push('0');
        }
    }

    result
}

/// Format date/time string matching PHP format_date()
pub fn format_date(datetime: Option<&str>, format: Option<&str>, tz: Option<&str>) -> String {
    let dt_str = match datetime {
        Some(s) if !s.trim().is_empty() => s.trim(),
        _ => return "—".to_string(),
    };

    let target_tz: Tz = tz
        .and_then(|t| t.parse::<Tz>().ok())
        .unwrap_or(chrono_tz::Asia::Dhaka);

    // Try parsing as timestamp integer
    let dt: Option<DateTime<Tz>> = if let Ok(ts) = dt_str.parse::<i64>() {
        Utc.timestamp_opt(ts, 0).single().map(|utc| utc.with_timezone(&target_tz))
    } else if let Ok(naive) = NaiveDateTime::parse_from_str(dt_str, "%Y-%m-%d %H:%M:%S") {
        target_tz.from_local_datetime(&naive).single().or_else(|| {
            Some(DateTime::<Tz>::from_naive_utc_and_offset(naive, target_tz.offset_from_utc_datetime(&naive)))
        })
    } else if let Ok(dt_parsed) = DateTime::parse_from_rfc3339(dt_str) {
        Some(dt_parsed.with_timezone(&target_tz))
    } else {
        None
    };

    let dt = match dt {
        Some(d) => d,
        None => return escape_html(dt_str),
    };

    let fmt = format.unwrap_or("M j, Y H:i");

    // Convert PHP date format syntax to Chrono format syntax if needed
    // Common mappings:
    // 'Y-m-d' -> '%Y-%m-%d'
    // 'M j, Y' -> '%b %e, %Y'
    // 'M j, Y H:i' -> '%b %e, %Y %H:%M'
    // 'd M Y' -> '%d %b %Y'
    let chrono_fmt = match fmt {
        "Y-m-d" => "%Y-%m-%d",
        "Y-m-d H:i:s" => "%Y-%m-%d %H:%M:%S",
        "d M Y" => "%d %b %Y",
        "M j, Y H:i" => "%b %e, %Y %H:%M",
        "display_date" | "15 Nov 2023" => "%d %b %Y",
        "display_datetime" | "Nov 15, 2023, 4:13 am" => "%b %d, %Y, %l:%M %P",
        "year_month" | "202311" => "%Y%m",
        other => other,
    };

    dt.format(chrono_fmt).to_string().trim().to_string()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn test_slugify() {
        assert_eq!(slugify("Men Black T-Shirt"), "men-black-t-shirt");
        assert_eq!(
            slugify("Women's Premium Silk Saree - Red & Gold"),
            "women-s-premium-silk-saree-red-gold"
        );
        assert_eq!(
            slugify("Exclusive 100% Cotton Polo Shirt (2026 Edition)"),
            "exclusive-100-cotton-polo-shirt-2026-edition"
        );
        assert_eq!(slugify("Special Discount: 50% Off!"), "special-discount-50-off");
        assert_eq!(slugify("  Extra   Spaces   Around   "), "extra-spaces-around");
    }

    #[test]
    fn test_order_number() {
        assert_eq!(format_order_number("ORD", "202611", 1), "ORD-202611-0001");
        assert_eq!(format_order_number("ORD", "202611", 42), "ORD-202611-0042");
        assert_eq!(format_order_number("ORD", "202611", 1000), "ORD-202611-1000");
    }

    #[test]
    fn test_number_format() {
        assert_eq!(number_format(Decimal::new(123456, 2), 2, ".", ","), "1,234.56");
        assert_eq!(number_format(Decimal::new(1000000, 0), 0, ".", ","), "1,000,000");
        assert_eq!(number_format(Decimal::new(-505, 1), 2, ".", ","), "-50.50");
    }

    #[test]
    fn test_escape_html() {
        assert_eq!(
            escape_html(r#"<script>alert("xss & 'test'");</script>"#),
            "&lt;script&gt;alert(&quot;xss &amp; &#039;test&#039;&quot;);&lt;/script&gt;"
        );
    }
}
