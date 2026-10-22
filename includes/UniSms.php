<?php

// UniSMS API official library
// https://unismsapi.com
class UniSms {
  public const API_URL = 'https://unismsapi.com/api/sms';

  private $secret_key;

  public $recipient;
  public $content;
  public $sender_id;

  public function __construct($secret_key) {
    $this->secret_key = $secret_key;
  }

  public function get($id) {
    if (!isset($id)) throw new Exception("Secret key must be set.");

    return $this->request(self::API_URL . '/' . rawurlencode((string)$id));
  }

  public function send() {
    if (!isset($this->secret_key)) throw new Exception("Secret key must be set.");

    if (!isset($this->recipient)) throw new Exception("Recipient key must be set.");

    if (!isset($this->content)) throw new Exception("SMS content message is empty.");

    $payload = json_encode([
      "recipient" => $this->recipient,
      "content" => $this->content,
      "sender_id" => $this->sender_id ?? "UniSMS"
    ]);

    return $this->request(self::API_URL, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
    ]);
  }

  /** Both sending and status retrieval require an authenticated HTTPS peer. */
  private function request(string $url, array $options = []): string {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('SMS service connection failed.');

    try {
      if (!curl_setopt_array($ch, $options + [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
          CURLOPT_USERPWD        => "$this->secret_key:",
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_CONNECTTIMEOUT => 10,
          CURLOPT_TIMEOUT        => 30,
          CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
          CURLOPT_FOLLOWLOCATION => false,
      ])) throw new RuntimeException('SMS service connection failed.');

      // Use PHP/cURL's configured CA trust store. Never retry without verification.
      $response = curl_exec($ch);
      if ($response === false || curl_errno($ch) !== 0) {
        throw new RuntimeException('SMS service connection failed.');
      }
      return $response;
    } finally {
      curl_close($ch);
    }
  }
}
?>
