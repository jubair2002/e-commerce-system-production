use rust_decimal::{Decimal, RoundingStrategy};
use std::fmt::Write;

pub const MONEY_ROUNDING: RoundingStrategy = RoundingStrategy::MidpointAwayFromZero;

/// Round decimal to 2 decimal places with MidpointAwayFromZero (half-up)
pub fn round_money(amount: Decimal) -> Decimal {
    amount.round_dp_with_strategy(2, MONEY_ROUNDING)
}

/// Format decimal money with currency symbol and thousands separators (matching PHP number_format($val, 2))
pub fn format_money(amount: Option<Decimal>, symbol: &str) -> String {
    let amt = match amount {
        Some(a) => round_money(a),
        None => return "—".to_string(),
    };

    let is_negative = amt.is_sign_negative();
    let abs_amt = amt.abs();
    let integer_part = abs_amt.trunc().to_string();
    let fractional_part = format!("{:02}", (abs_amt.fract() * Decimal::from(100)).trunc());

    // Insert thousands commas into integer part
    let mut formatted_int = String::with_capacity(integer_part.len() + integer_part.len() / 3);
    let bytes = integer_part.as_bytes();
    let len = bytes.len();
    for (i, &b) in bytes.iter().enumerate() {
        if i > 0 && (len - i) % 3 == 0 {
            formatted_int.push(',');
        }
        formatted_int.push(b as char);
    }

    let mut out = String::new();
    out.push_str(symbol);
    if is_negative {
        out.push('-');
    }
    let _ = write!(out, "{}.{}", formatted_int, fractional_part);
    out
}

/// Parse string or numeric into Decimal safely
pub fn parse_money(val: &str) -> Option<Decimal> {
    val.trim().parse::<Decimal>().ok().map(round_money)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::str::FromStr;

    #[test]
    fn test_money_half_up_rounding() {
        let d = |s: &str| Decimal::from_str(s).unwrap();
        // Half-up: 0.005 -> 0.01, 0.004 -> 0.00, 0.015 -> 0.02
        assert_eq!(round_money(d("10.005")), d("10.01"));
        assert_eq!(round_money(d("10.004")), d("10.00"));
        assert_eq!(round_money(d("10.015")), d("10.02"));
        assert_eq!(round_money(d("10.025")), d("10.03"));
        assert_eq!(round_money(d("-10.005")), d("-10.01"));
    }

    #[test]
    fn test_format_money() {
        let d = |s: &str| Decimal::from_str(s).unwrap();
        assert_eq!(format_money(Some(d("1234.5")), "৳"), "৳1,234.50");
        assert_eq!(format_money(Some(d("1000000")), "$"), "$1,000,000.00");
        assert_eq!(format_money(Some(d("9.99")), "৳"), "৳9.99");
        assert_eq!(format_money(None, "৳"), "—");
    }
}
