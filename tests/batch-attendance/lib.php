<?php
// HTTP client, database probe and assertion helpers for flow.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class BflowClient
{
    public $sessionId = '';
    public $token = '';
    public $userId = 0;
    public $bid = 1;
    private $base;

    public function __construct(int $port)
    {
        $this->base = "http://127.0.0.1:{$port}";
    }

    public static function login(int $port, int $userId, int $bid = 1): self
    {
        $client = new self($port);
        $raw = $client->raw('GET', "/__bflow/login?user_id={$userId}&bid={$bid}", null, ['X-Bflow-Key: ' . getenv('BFLOW_KEY')]);
        $data = json_decode($raw['body'], true);
        if (!is_array($data) || empty($data['session_id'])) {
            throw new RuntimeException("test login failed for {$userId}: HTTP {$raw['status']} {$raw['body']}");
        }
        $client->sessionId = $data['session_id'];
        $client->token = $data['token'];
        $client->userId = $userId;
        $client->bid = $bid;
        return $client;
    }

    /** Same request the admin pages send through apiCall.callEndpoint (pf/js/controllers/mainController.js:103). */
    public function call(string $route, string $action, array $body = []): array
    {
        if ($this->sessionId !== '' && !array_key_exists('session_id', $body)) {
            $body['session_id'] = $this->sessionId;
        }
        $headers = ['Content-Type: application/json', "uid: {$this->userId}", "bid: {$this->bid}", "token: {$this->token}"];
        if ($this->sessionId !== '') {
            $headers[] = 'Cookie: PHPSESSID=' . $this->sessionId;
        }
        $raw = $this->raw('POST', "/routes/{$route}?action=" . rawurlencode($action), json_encode($body), $headers);
        $json = json_decode($raw['body'], true);
        return ['status' => $raw['status'], 'body' => $raw['body'], 'json' => is_array($json) ? $json : null];
    }

    /** Anonymous request: no login, no headers beyond what is passed. */
    public function anonymous(string $route, string $action, array $body = [], array $headers = []): array
    {
        $raw = $this->raw('POST', "/routes/{$route}?action=" . rawurlencode($action), json_encode($body), array_merge(['Content-Type: application/json'], $headers));
        $json = json_decode($raw['body'], true);
        return ['status' => $raw['status'], 'body' => $raw['body'], 'json' => is_array($json) ? $json : null];
    }

    public function upload(string $route,string $action,array $fields,string $path,bool $anonymous=false): array
    {
        if(!$anonymous)$fields['session_id']=$this->sessionId;
        $fields['file']=new CURLFile($path);
        $curl=curl_init($this->base.'/routes/'.$route.'?action='.rawurlencode($action));
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$json=json_decode((string)$body,true);return ['status'=>$status,'body'=>$body,'json'=>$json];
    }

    private function raw(string $method, string $path, ?string $body, array $headers): array
    {
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new RuntimeException("HTTP {$method} {$path} failed: {$error}");
        }
        return ['status' => $status, 'body' => (string)$response];
    }
}

final class BflowDb
{
    private $db;

    public function __construct()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new mysqli('localhost', getenv('BFLOW_DB_USER'), getenv('BFLOW_DB_PASS'), getenv('BFLOW_TENANT_DB'));
        $this->db->set_charset('utf8mb4');
    }

    public function row(string $sql): ?array
    {
        $row = $this->db->query($sql)->fetch_assoc();
        return $row ?: null;
    }

    public function rows(string $sql): array
    {
        return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    public function value(string $sql)
    {
        $row = $this->db->query($sql)->fetch_row();
        return $row ? $row[0] : null;
    }

    public function exec(string $sql): void
    {
        $this->db->query($sql);
    }
}

final class BflowSuite
{
    private $results = [];
    private $current = '';
    private $filter;

    public function __construct(string $filter)
    {
        $this->filter = $filter;
    }

    /**
     * $known: a short id of a defect that this scenario is known to expose.
     * A failing known scenario is reported as KNOWN (does not fail the run);
     * a known scenario that starts passing is reported as FIXED so the marker
     * can be removed.
     */
    public function scenario(string $id, string $title, callable $body, string $known = ''): void
    {
        if ($this->filter !== '' && stripos($id . ' ' . $title, $this->filter) === false) {
            return;
        }
        $this->current = $id;
        $started = microtime(true);
        try {
            $body();
            $status = $known === '' ? 'PASS' : 'FIXED';
            $detail = $known === '' ? '' : "known defect {$known} no longer reproduces - remove the marker";
        } catch (BflowFailure $failure) {
            $status = $known === '' ? 'FAIL' : 'KNOWN';
            $detail = ($known === '' ? '' : "[{$known}] ") . $failure->getMessage();
        } catch (Throwable $error) {
            $status = $known === '' ? 'FAIL' : 'KNOWN';
            $detail = ($known === '' ? '' : "[{$known}] ") . get_class($error) . ': ' . $error->getMessage();
        }
        $ms = (int)round((microtime(true) - $started) * 1000);
        $this->results[] = [$status, $id, $title, $detail];
        printf("%-5s %-8s %s (%d ms)%s\n", $status, $id, $title, $ms, $detail !== '' ? "\n      " . $detail : '');
    }

    public function finish(): int
    {
        $counts = array_count_values(array_column($this->results, 0));
        $summary = [];
        foreach (['PASS', 'FAIL', 'KNOWN', 'FIXED'] as $status) {
            $summary[] = $status . '=' . ($counts[$status] ?? 0);
        }
        echo "\n" . count($this->results) . ' scenarios: ' . implode(' ', $summary) . "\n";
        return (($counts['FAIL'] ?? 0) + ($counts['FIXED'] ?? 0)) > 0 ? 1 : 0;
    }
}

final class BflowFailure extends RuntimeException
{
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new BflowFailure($message);
    }
}

function same($expected, $actual, string $what): void
{
    if ($expected != $actual) {
        throw new BflowFailure("{$what}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Asserts an API envelope {error, message, data} with the expected error code. */
function api(array $response, int $error, string $what): array
{
    if ($response['json'] === null) {
        $body = trim(preg_replace('/\s+/', ' ', strip_tags($response['body'])));
        throw new BflowFailure("{$what}: HTTP {$response['status']}, not a JSON response: " . substr($body === '' ? '(empty body)' : $body, 0, 300));
    }
    $actual = (int)($response['json']['error'] ?? -1);
    if ($actual !== $error) {
        throw new BflowFailure("{$what}: expected error={$error}, got error={$actual} message=" . json_encode($response['json']['message'] ?? null) . ' error_msg=' . json_encode($response['json']['error_msg'] ?? null));
    }
    return $response['json'];
}
