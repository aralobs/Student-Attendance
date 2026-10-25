"""Local HTTPS certificate checks. Requires Python cryptography and PHP cURL.

Only test copies change the API URL/CA path. Production verification settings
are preserved; no external provider, real credentials or application DB is used.
"""
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json
import shutil
import ssl
import subprocess
import tempfile
import threading
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

root = Path(__file__).resolve().parents[1]
requests = []

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        requests.append(self.command)
        body = b'{"status":"sent"}'
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length', 0)))
        self.do_GET()

    def log_message(self, *args):
        pass

with tempfile.TemporaryDirectory(prefix='.sms-tls-', dir=root / 'tests') as directory:
    fixture = Path(directory)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'localhost')])
    now = datetime.now(timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
            .public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now - timedelta(minutes=5)).not_valid_after(now + timedelta(days=1))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName('localhost')]), critical=False)
            .sign(key, hashes.SHA256()))
    cert_path = fixture / 'cert.pem'
    key_path = fixture / 'key.pem'
    cert_path.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    key_path.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert_path, key_path)
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    checks = 0
    try:
        source = (root / 'includes/UniSms.php').read_text(encoding='utf-8')
        for method in ('get', 'send'):
            for case in ('trusted', 'untrusted', 'wrong_hostname'):
                hostname = '127.0.0.1' if case == 'wrong_hostname' else 'localhost'
                url = f'https://{hostname}:{server.server_port}/api/sms'
                test_source = source.replace('https://unismsapi.com/api/sms', url)
                if case != 'untrusted':
                    test_source = test_source.replace('CURLOPT_RETURNTRANSFER => true,',
                        'CURLOPT_CAINFO => ' + json.dumps(cert_path.as_posix()) + ',\n          CURLOPT_RETURNTRANSFER => true,')
                # Skip any workstation proxy for this loopback-only test.
                test_source = test_source.replace('CURLOPT_RETURNTRANSFER => true,', 'CURLOPT_PROXY => "",\n          CURLOPT_RETURNTRANSFER => true,')
                (fixture / 'client.php').write_text(test_source, encoding='utf-8')
                (fixture / 'run.php').write_text('''<?php
require __DIR__ . '/client.php';
$client = new UniSms('synthetic-test-key');
$client->recipient = 'synthetic-recipient';
$client->content = 'synthetic-message';
ob_start();
try {
    $response = $argv[1] === 'send' ? $client->send() : $client->get('synthetic-id');
    $result = ['ok' => true, 'response' => $response];
} catch (Exception $e) {
    $result = ['ok' => false, 'message' => $e->getMessage()];
}
$result['output'] = ob_get_clean();
echo json_encode($result);
''', encoding='utf-8')
                before = len(requests)
                run = subprocess.run([shutil.which('php'), str(fixture / 'run.php'), method], capture_output=True, text=True, timeout=40)
                assert run.returncode == 0 and not run.stderr, run.stderr
                result = json.loads(run.stdout)
                assert result['output'] == '', 'Raw diagnostics leaked'
                if case == 'trusted':
                    assert result['ok'] and result['response'] == '{"status":"sent"}', result
                    assert len(requests) == before + 1
                else:
                    assert not result['ok'] and result['message'] == 'SMS service connection failed.', result
                    assert len(requests) == before, 'Credentials/message sent before certificate validation'
                checks += 1
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)
print(f'SMS local HTTPS checks passed ({checks} cases: trusted, untrusted, wrong hostname; send and get).')
