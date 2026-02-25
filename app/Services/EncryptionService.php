<?php

namespace App\Services;

use RuntimeException;

/**
 * AES-256-GCM authenticated encryption for PII at rest.
 * Used for account numbers, contact details, wallet addresses.
 */
class EncryptionService
{
  private const CIPHER     = 'aes-256-gcm';
  private const IV_LENGTH  = 12;
  private const TAG_LENGTH = 16;

  public function encrypt(string $plaintext): string
  {
    $key = $this->getKey();
    $iv  = random_bytes(self::IV_LENGTH);
    $tag = '';

    $ciphertext = openssl_encrypt(
      $plaintext,
      self::CIPHER,
      $key,
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
      '',
      self::TAG_LENGTH
    );

    if ($ciphertext === false) {
      throw new RuntimeException('Encryption failed.');
    }

    return base64_encode($iv . $tag . $ciphertext);
  }

  public function decrypt(string $encoded): string
  {
    $key  = $this->getKey();
    $data = base64_decode($encoded, strict: true);

    if ($data === false || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
      throw new RuntimeException('Decryption failed: malformed ciphertext.');
    }

    $iv         = substr($data, 0, self::IV_LENGTH);
    $tag        = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
    $ciphertext = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

    $plaintext = openssl_decrypt(
      $ciphertext,
      self::CIPHER,
      $key,
      OPENSSL_RAW_DATA,
      $iv,
      $tag
    );

    if ($plaintext === false) {
      throw new RuntimeException('Decryption failed: data may be tampered.');
    }

    return $plaintext;
  }

  private function getKey(): string
  {
    $hexKey = config('security.encryption_key');

    if (empty($hexKey)) {
      throw new RuntimeException(
        'ENCRYPTION_KEY not set. Generate with: php -r "echo bin2hex(random_bytes(32));"'
      );
    }

    $key = hex2bin($hexKey);

    if ($key === false || strlen($key) !== 32) {
      throw new RuntimeException('ENCRYPTION_KEY must be a 64-character hex string.');
    }

    return $key;
  }
}
