use common::crypto::{app_decrypt, customer_password_verify, generate_hmac_sha256};
use common::money::{format_money, round_money};
use rust_decimal::Decimal;
use serde::Deserialize;
use std::fs;
use std::path::Path;
use std::str::FromStr;

#[derive(Deserialize)]
struct HmacVector {
    order_number: String,
    app_key: String,
    hmac_sha256: String,
}

#[allow(dead_code)]
#[derive(Deserialize)]
struct MoneyVector {
    input: Option<f64>,
    currency_symbol: String,
    price_formatted: String,
    number_format_2: Option<String>,
}

#[allow(dead_code)]
#[derive(Deserialize)]
struct CryptoVector {
    plaintext: String,
    app_key: String,
    ciphertext_base64: String,
    decrypted: String,
    matches: bool,
}

#[allow(dead_code)]
#[derive(Deserialize)]
struct PasswordVector {
    password: String,
    customer_hash: String,
    customer_verify_correct: bool,
    customer_verify_wrong: bool,
}

#[allow(dead_code)]
#[derive(Deserialize)]
struct RoundVector {
    input: f64,
    round_2: f64,
    php_half_up_2: f64,
}

fn fixture_path(filename: &str) -> String {
    let p1 = format!("../../../parity-tests/golden/fixtures/{}", filename);
    let p2 = format!("../../parity-tests/golden/fixtures/{}", filename);
    let p3 = format!("parity-tests/golden/fixtures/{}", filename);
    if Path::new(&p1).exists() {
        p1
    } else if Path::new(&p2).exists() {
        p2
    } else {
        p3
    }
}

#[test]
fn test_golden_hmac_tokens() {
    let path = fixture_path("hmac_tokens.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read fixture from {}", path));
    let vectors: Vec<HmacVector> = serde_json::from_str(&content).unwrap();

    for v in vectors {
        let rust_hmac = generate_hmac_sha256(&v.order_number, &v.app_key);
        assert_eq!(
            rust_hmac, v.hmac_sha256,
            "HMAC mismatch for order {}",
            v.order_number
        );
    }
}

#[test]
fn test_golden_money_formatting() {
    let path = fixture_path("money_formatting.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read fixture from {}", path));
    let vectors: Vec<MoneyVector> = serde_json::from_str(&content).unwrap();

    for v in vectors {
        if let Some(num_str) = v.number_format_2 {
            let dec = Decimal::from_str(&num_str).unwrap();
            let formatted = format_money(Some(dec), &v.currency_symbol);
            assert_eq!(
                formatted, v.price_formatted,
                "Price format mismatch for input {:?}",
                v.input
            );
        } else {
            let formatted = format_money(None, &v.currency_symbol);
            assert_eq!(formatted, "—");
        }
    }
}

#[test]
fn test_golden_crypto_aes() {
    let path = fixture_path("crypto_aes.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read fixture from {}", path));
    let vectors: Vec<CryptoVector> = serde_json::from_str(&content).unwrap();

    for v in vectors {
        if v.plaintext.is_empty() {
            continue;
        }
        let decrypted = app_decrypt(&v.ciphertext_base64, &v.app_key);
        assert_eq!(
            decrypted.as_deref(),
            Some(v.plaintext.as_str()),
            "AES decrypt mismatch for plaintext: {}",
            v.plaintext
        );
    }
}

#[test]
fn test_golden_customer_passwords() {
    let path = fixture_path("passwords.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read fixture from {}", path));
    let vectors: Vec<PasswordVector> = serde_json::from_str(&content).unwrap();

    for v in vectors {
        assert!(
            customer_password_verify(&v.password, &v.customer_hash),
            "Customer password verification failed for correct password: {}",
            v.password
        );
        assert!(
            !customer_password_verify(&format!("{}_wrong", v.password), &v.customer_hash),
            "Customer password verification succeeded for wrong password"
        );
    }
}

#[test]
fn test_golden_round_half_up() {
    let path = fixture_path("round.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read fixture from {}", path));
    let vectors: Vec<RoundVector> = serde_json::from_str(&content).unwrap();

    for v in vectors {
        let dec_input = Decimal::from_str(&format!("{:.6}", v.input)).unwrap();
        let rounded = round_money(dec_input);
        let expected = Decimal::from_str(&format!("{:.2}", v.php_half_up_2)).unwrap();
        assert_eq!(
            rounded, expected,
            "Half-up rounding mismatch for input {}",
            v.input
        );
    }
}
