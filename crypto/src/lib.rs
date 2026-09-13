use aes_gcm::{
    aead::{AeadInPlace, KeyInit},
    Aes256Gcm, Nonce, Tag,
};
use argon2::{Algorithm, Argon2, Params, Version};
use wasm_bindgen::prelude::*;
use zeroize::{Zeroize, Zeroizing};

pub const CHUNK_SIZE: usize = 1024 * 1024;
pub const HEADER_SIZE: usize = 64;
const MAGIC: &[u8; 8] = b"WBENC001";
const FAILURE: &str = "Incorrect password or damaged encrypted file.";
const MAX_SIZE: u64 = 1 << 40;

fn derive(password: &[u8], salt: &[u8]) -> Result<Aes256Gcm, &'static str> {
    if password.is_empty() || password.len() > 1024 {
        return Err("Password must contain 1 to 1024 UTF-8 bytes.");
    }
    let mut key = Zeroizing::new([0u8; 32]);
    let params = Params::new(65536, 3, 1, Some(32)).map_err(|_| FAILURE)?;
    Argon2::new(Algorithm::Argon2id, Version::V0x13, params)
        .hash_password_into(password, salt, key.as_mut())
        .map_err(|_| FAILURE)?;
    Ok(Aes256Gcm::new_from_slice(key.as_ref()).map_err(|_| FAILURE)?)
}

fn nonce(header: &[u8], index: u32) -> [u8; 12] {
    let mut nonce = [0; 12];
    nonce[..8].copy_from_slice(&header[24..32]);
    nonce[8..].copy_from_slice(&index.to_le_bytes());
    nonce
}

fn parse(header: &[u8], size: u64) -> Result<u64, &'static str> {
    if header.len() != HEADER_SIZE || &header[..8] != MAGIC || header[40..48] != [0; 8] {
        return Err(FAILURE);
    }
    let plain = u64::from_le_bytes(header[32..40].try_into().map_err(|_| FAILURE)?);
    if plain > MAX_SIZE
        || size != HEADER_SIZE as u64 + plain + plain.div_ceil(CHUNK_SIZE as u64) * 16
    {
        return Err(FAILURE);
    }
    Ok(plain)
}

#[wasm_bindgen]
pub struct FileCipher {
    cipher: Aes256Gcm,
    header: Vec<u8>,
    remaining: u64,
    index: u32,
    decrypting: bool,
    failed: bool,
}

impl FileCipher {
    fn create(password: &[u8], size: u64) -> Result<Self, &'static str> {
        if size > MAX_SIZE {
            return Err("File exceeds the encrypted format limit (1 TiB).");
        }
        let mut header = vec![0; HEADER_SIZE];
        header[..8].copy_from_slice(MAGIC);
        getrandom::getrandom(&mut header[8..32]).map_err(|_| "Secure randomness unavailable.")?;
        header[32..40].copy_from_slice(&size.to_le_bytes());
        let cipher = derive(password, &header[8..24])?;
        let tag = cipher
            .encrypt_in_place_detached(
                Nonce::from_slice(&nonce(&header, 0)),
                &header[..48],
                &mut [],
            )
            .map_err(|_| FAILURE)?;
        header[48..].copy_from_slice(&tag);
        Ok(Self {
            cipher,
            header,
            remaining: size,
            index: 1,
            decrypting: false,
            failed: false,
        })
    }

    fn open(password: &[u8], header: &[u8], size: u64) -> Result<Self, &'static str> {
        let remaining = parse(header, size)?;
        let cipher = derive(password, &header[8..24])?;
        cipher
            .decrypt_in_place_detached(
                Nonce::from_slice(&nonce(header, 0)),
                &header[..48],
                &mut [],
                Tag::from_slice(&header[48..]),
            )
            .map_err(|_| FAILURE)?;
        Ok(Self {
            cipher,
            header: header.to_vec(),
            remaining,
            index: 1,
            decrypting: true,
            failed: false,
        })
    }

    fn transform_owned(&mut self, input: Vec<u8>) -> Result<Vec<u8>, &'static str> {
        let mut buffer = Zeroizing::new(input);
        if self.failed || self.remaining == 0 {
            return Err(FAILURE);
        }
        self.failed = true;
        let size = self.remaining.min(CHUNK_SIZE as u64) as usize;
        if buffer.len() != size + if self.decrypting { 16 } else { 0 } {
            return Err(FAILURE);
        }
        let iv = nonce(&self.header, self.index);
        if self.decrypting {
            let tag = Tag::clone_from_slice(&buffer[size..]);
            buffer.truncate(size);
            self.cipher
                .decrypt_in_place_detached(Nonce::from_slice(&iv), &self.header, &mut buffer, &tag)
                .map_err(|_| FAILURE)?;
        } else {
            let tag = self
                .cipher
                .encrypt_in_place_detached(Nonce::from_slice(&iv), &self.header, &mut buffer)
                .map_err(|_| FAILURE)?;
            buffer.extend_from_slice(&tag);
        }
        self.remaining -= size as u64;
        self.index += 1;
        self.failed = false;
        Ok(std::mem::take(&mut *buffer))
    }

    #[cfg(test)]
    fn transform(&mut self, input: &[u8]) -> Result<Vec<u8>, &'static str> {
        self.transform_owned(input.to_vec())
    }
}

#[wasm_bindgen]
impl FileCipher {
    pub fn encrypt(mut password: Vec<u8>, size: u64) -> Result<FileCipher, JsError> {
        let result = Self::create(&password, size);
        password.zeroize();
        result.map_err(JsError::new)
    }

    pub fn decrypt(mut password: Vec<u8>, header: &[u8], size: u64) -> Result<FileCipher, JsError> {
        let result = Self::open(&password, header, size);
        password.zeroize();
        result.map_err(JsError::new)
    }

    pub fn header(&self) -> Vec<u8> {
        self.header.clone()
    }

    pub fn chunk(&mut self, input: Vec<u8>) -> Result<Vec<u8>, JsError> {
        self.transform_owned(input).map_err(JsError::new)
    }

    pub fn finish(&self) -> Result<(), JsError> {
        if self.failed || self.remaining != 0 {
            Err(JsError::new(FAILURE))
        } else {
            Ok(())
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    const PASSWORD: &[u8] = b"test-only long password";

    #[test]
    fn round_trip_boundaries_and_randomness() {
        for size in [0, 1, CHUNK_SIZE, CHUNK_SIZE + 17] {
            let plain = vec![0x72; size];
            let mut enc = FileCipher::create(PASSWORD, size as u64).unwrap();
            let encrypted: Vec<_> = plain
                .chunks(CHUNK_SIZE)
                .map(|chunk| enc.transform(chunk).unwrap())
                .collect();
            let total = 64 + encrypted.iter().map(Vec::len).sum::<usize>();
            let mut dec = FileCipher::open(PASSWORD, &enc.header, total as u64).unwrap();
            let output: Vec<_> = encrypted
                .iter()
                .flat_map(|chunk| dec.transform(chunk).unwrap())
                .collect();
            assert_eq!(output, plain);
            assert_eq!(dec.remaining, 0);
            assert_ne!(
                enc.header,
                FileCipher::create(PASSWORD, size as u64).unwrap().header
            );
        }
    }

    #[test]
    fn rejects_password_header_length_and_payload_tampering() {
        let mut enc = FileCipher::create(PASSWORD, 10).unwrap();
        let chunk = enc.transform(&[5; 10]).unwrap();
        assert!(FileCipher::open(b"wrong", &enc.header, 90).is_err());
        assert!(FileCipher::open(PASSWORD, &enc.header, 89).is_err());
        assert!(FileCipher::open(PASSWORD, &enc.header, 91).is_err());
        for offset in [0, 8, 24, 32, 40, 48, 63] {
            let mut header = enc.header.clone();
            header[offset] ^= 1;
            assert!(FileCipher::open(PASSWORD, &header, 90).is_err());
        }
        let mut dec = FileCipher::open(PASSWORD, &enc.header, 90).unwrap();
        let mut damaged = chunk.clone();
        damaged[0] ^= 1;
        assert!(dec.transform(&damaged).is_err());
        assert!(dec.transform(&chunk).is_err());
    }

    #[test]
    fn rejects_reordered_duplicated_and_missing_chunks() {
        let mut enc = FileCipher::create(PASSWORD, (CHUNK_SIZE * 2) as u64).unwrap();
        let first = enc.transform(&vec![1; CHUNK_SIZE]).unwrap();
        let second = enc.transform(&vec![2; CHUNK_SIZE]).unwrap();
        let size = (64 + first.len() + second.len()) as u64;
        let mut dec = FileCipher::open(PASSWORD, &enc.header, size).unwrap();
        assert!(dec.transform(&second).is_err());
        let mut dec = FileCipher::open(PASSWORD, &enc.header, size).unwrap();
        dec.transform(&first).unwrap();
        assert_ne!(dec.remaining, 0);
        assert!(dec.transform(&first).is_err());
    }
}
