use aes::cipher::{block_padding::Pkcs7, BlockDecryptMut, BlockEncryptMut, KeyIvInit};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use rand::RngCore;
use sha2::{Digest, Sha256};

type Aes256CbcEnc = cbc::Encryptor<aes::Aes256>;
type Aes256CbcDec = cbc::Decryptor<aes::Aes256>;

/// Derive 32-byte AES-256 key matching PHP's hash('sha256', APP_KEY, true)
pub fn app_crypto_key(app_key: &str) -> [u8; 32] {
    let mut hasher = Sha256::new();
    hasher.update(app_key.as_bytes());
    let result = hasher.finalize();
    let mut key = [0u8; 32];
    key.copy_from_slice(&result);
    key
}

/// Encrypt plaintext using AES-256-CBC with PKCS#7 padding matching PHP's app_encrypt()
pub fn app_encrypt(plain: &str, app_key: &str) -> String {
    let key = app_crypto_key(app_key);
    let mut iv = [0u8; 16];
    rand::thread_rng().fill_bytes(&mut iv);

    let encryptor = Aes256CbcEnc::new(&key.into(), &iv.into());
    let plain_bytes = plain.as_bytes();
    let pt_len = plain_bytes.len();
    let mut buf = vec![0u8; pt_len + 16];
    buf[..pt_len].copy_from_slice(plain_bytes);

    let ct = match encryptor.encrypt_padded_mut::<Pkcs7>(&mut buf, pt_len) {
        Ok(res) => res,
        Err(_) => return String::new(),
    };

    let mut combined = Vec::with_capacity(iv.len() + ct.len());
    combined.extend_from_slice(&iv);
    combined.extend_from_slice(ct);

    BASE64.encode(combined)
}

/// Decrypt ciphertext using AES-256-CBC with PKCS#7 padding matching PHP's app_decrypt()
pub fn app_decrypt(cipher: &str, app_key: &str) -> Option<String> {
    let data = match BASE64.decode(cipher.trim()) {
        Ok(d) if d.len() > 16 => d,
        _ => return None,
    };

    let key = app_crypto_key(app_key);
    let iv = &data[..16];
    let ciphertext = &data[16..];

    let decryptor = match Aes256CbcDec::new_from_slices(&key, iv) {
        Ok(d) => d,
        Err(_) => return None,
    };

    let mut buf = ciphertext.to_vec();
    let decrypted = match decryptor.decrypt_padded_mut::<Pkcs7>(&mut buf) {
        Ok(res) => res,
        Err(_) => return None,
    };

    String::from_utf8(decrypted.to_vec()).ok()
}

/// Customer pre-hash: base64(sha256(password, raw_bytes))
pub fn customer_pre_hash(pw: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(pw.as_bytes());
    let raw = hasher.finalize();
    BASE64.encode(raw)
}

/// Customer password hash: bcrypt(customer_pre_hash(pw))
pub fn customer_password_hash(pw: &str) -> Result<String, bcrypt::BcryptError> {
    let pre_hashed = customer_pre_hash(pw);
    bcrypt::hash(pre_hashed, 12)
}

/// Customer password verify: verifies bcrypt against customer_pre_hash(pw)
pub fn customer_password_verify(pw: &str, hash: &str) -> bool {
    let pre_hashed = customer_pre_hash(pw);
    // Support PHP $2y$ prefix by converting to $2b$ for the bcrypt crate if needed
    let normalized_hash = if let Some(rest) = hash.strip_prefix("$2y$") {
        format!("$2b${}", rest)
    } else {
        hash.to_string()
    };
    bcrypt::verify(pre_hashed, &normalized_hash).unwrap_or(false)
}

/// Admin password hash: direct bcrypt(pw)
pub fn admin_password_hash(pw: &str) -> Result<String, bcrypt::BcryptError> {
    bcrypt::hash(pw, 12)
}

/// Admin password verify: direct bcrypt_verify(pw)
pub fn admin_password_verify(pw: &str, hash: &str) -> bool {
    let normalized_hash = if let Some(rest) = hash.strip_prefix("$2y$") {
        format!("$2b${}", rest)
    } else {
        hash.to_string()
    };
    bcrypt::verify(pw, &normalized_hash).unwrap_or(false)
}

/// Generate 64-char hex CSRF token (32 random bytes)
pub fn generate_csrf_token() -> String {
    let mut bytes = [0u8; 32];
    rand::thread_rng().fill_bytes(&mut bytes);
    hex::encode(bytes)
}

/// Constant-time comparison for CSRF tokens
pub fn verify_csrf_token(token: &str, expected: &str) -> bool {
    if token.is_empty() || expected.is_empty() || token.len() != expected.len() {
        return false;
    }
    let mut diff = 0u8;
    for (a, b) in token.bytes().zip(expected.bytes()) {
        diff |= a ^ b;
    }
    diff == 0
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_php_crypto_interop() {
        let app_key = "CHANGE_ME_TO_64_RANDOM_CHARS";
        let php_cipher = "aoi6hMCotfPH8wWm+zFvqdDtdSyqfuSHKPySAfojdwNjRvpIMHHDJcGY2/hdnDfw";
        let decrypted = app_decrypt(php_cipher, app_key).expect("Decryption should succeed");
        assert_eq!(decrypted, "super_secret_smtp_pass_123");

        let plain = "test_rust_secret_456";
        let enc = app_encrypt(plain, app_key);
        let roundtrip = app_decrypt(&enc, app_key).expect("Roundtrip should succeed");
        assert_eq!(roundtrip, plain);
    }

    #[test]
    fn test_customer_password_verify() {
        let pw = "SecretP@ssw0rd123";
        let php_hash = "$2y$12$qQ3mcMrRw0WyHa7MzWNqyui03qHQHs94XzUOwgYzqNoYUWgqLvvTO";
        assert!(customer_password_verify(pw, php_hash));
        assert!(!customer_password_verify("WrongPassword", php_hash));
    }
}
