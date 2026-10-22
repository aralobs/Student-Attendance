<?php
// Mock only the transport functions; load the actual client in a namespace.
namespace SmsTransportTest;
$state = ['options' => [], 'closed' => 0, 'calls' => 0, 'mode' => 'success'];
function curl_init($url) { $GLOBALS['state']['url'] = $url; return new \stdClass; }
function curl_setopt_array($handle, $options) { $GLOBALS['state']['options'] = $options; return $GLOBALS['state']['mode'] !== 'setup_failure'; }
function curl_exec($handle) {
    $GLOBALS['state']['calls']++;
    return $GLOBALS['state']['mode'] === 'success' ? '{"status":"sent"}' : false;
}
function curl_errno($handle) { return $GLOBALS['state']['mode'] === 'success' ? 0 : CURLE_PEER_FAILED_VERIFICATION; }
function curl_close($handle) { $GLOBALS['state']['closed']++; }
$source = file_get_contents(__DIR__ . '/../includes/UniSms.php');
eval('namespace SmsTransportTest; use RuntimeException; use Exception; ' . preg_replace('/^<\?php|\?>\s*$/', '', $source));
$checks = 0;
function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); $GLOBALS['checks']++; }
foreach (['send', 'get'] as $method) {
    foreach (['success', 'certificate_failure', 'setup_failure'] as $mode) {
        $state = ['options' => [], 'closed' => 0, 'calls' => 0, 'mode' => $mode];
        $client = new UniSms('synthetic-test-key');
        $client->recipient = 'synthetic-recipient';
        $client->content = 'synthetic-message';
        ob_start();
        try {
            $result = $method === 'send' ? $client->send() : $client->get('synthetic-id');
            check($mode === 'success' && $result === '{"status":"sent"}', 'Failure did not fail closed');
        } catch (\RuntimeException $error) {
            check($mode !== 'success' && $error->getMessage() === 'SMS service connection failed.', 'Error is not generic');
        } finally {
            $output = ob_get_clean();
        }
        check($output === '', 'Transport emitted diagnostics into the page/API');
        check($state['closed'] === 1, 'Connection handle leaked');
        check($state['calls'] === ($mode === 'setup_failure' ? 0 : 1), 'Unsafe retry/setup failure request');
        check($state['options'][CURLOPT_SSL_VERIFYPEER] === true, 'Peer verification disabled');
        check($state['options'][CURLOPT_SSL_VERIFYHOST] === 2, 'Hostname verification disabled');
        check($state['options'][CURLOPT_CONNECTTIMEOUT] === 10 && $state['options'][CURLOPT_TIMEOUT] === 30, 'Unbounded request');
        check($state['options'][CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS && $state['options'][CURLOPT_FOLLOWLOCATION] === false, 'Transport may leave HTTPS endpoint');
    }
}
echo "SMS transport checks passed ($checks checks; no network or real credentials).\n";
