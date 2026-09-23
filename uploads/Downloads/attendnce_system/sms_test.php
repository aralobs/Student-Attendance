<?php

require_once './includes/UniSms.php';

$secret_key = "sk_e2648ee7-3fd4-4cef-974e-71a169c70bdb";

$client = new UniSms($secret_key);
$client->recipient = "+639955425054";
$client->content = "Hello Ma'am, Your child has arrived at San Pablo City Central School. Thank you.";
$client->sender_id = "UnisoftSMS"; // required for trial/limited accounts
// Send message
$response = $client->send();

if ($response === false) {
    echo "❌ Failed to send SMS.\n";
} else {
    $result = json_decode($response, true);

    // Adjust this check based on UniSMS's actual response structure
    if (json_last_error() === JSON_ERROR_NONE && isset($result['code']) && $result['code'] == 0) {
        echo "✅ SMS sent successfully!\n";
        echo "Response: " . $response . "\n";
    } else {
        echo "⚠️ SMS request completed, but response indicates a possible issue.\n";
        echo "Raw response: " . $response . "\n";
    }
}