<?php
/*
 * GujRERA Bulk Project Processor
 * --------------------------------
 * - Accepts JSON with top-level data[]
 * - Processes ONLY entityType=PROJECT
 * - API 1: project entityId -> promoterId
 * - API 2: promoterId -> promoter details
 * - AJAX batches; every browser request is short-lived
 * - curl_multi parallel API calls
 * - Promoter caching inside each job
 * - Live progress
 * - One Excel-compatible .xls file / one sheet
 * - Email included
 * - Exactly 3 blank rows after every project
 * - No Composer / no external library
 */

@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');

const API1_BASE = 'https://gujrera.gujarat.gov.in/project_reg/public/alldatabyprojectid/';
const API2_BASE = 'https://gujrera.gujarat.gov.in/user_reg/promoter/promoter';
const BATCH_SIZE = 20;       // Projects per AJAX request.
const CONCURRENCY = 10;      // Parallel HTTP requests at a time.
const CURL_CONNECT_TIMEOUT = 10;
const CURL_TIMEOUT = 30;
const JOB_TTL = 7200;        // 2 hours.

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function validId($value): bool
{
    return preg_match('/^[0-9]+$/', (string)$value)
        && (int)$value > 0
        && (int)$value <= 2147483647;
}

function decodeJson(string $json): ?array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function tempJobDir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gujrera_bulk_jobs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function validJobId(string $jobId): bool
{
    return preg_match('/^[a-f0-9]{32}$/', $jobId) === 1;
}

function jobPath(string $jobId): string
{
    if (!validJobId($jobId)) {
        return '';
    }
    return tempJobDir() . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.json';
}

function loadJob(string $jobId): ?array
{
    $path = jobPath($jobId);
    if ($path === '' || !is_file($path)) {
        return null;
    }

    if ((time() - (int)@filemtime($path)) > JOB_TTL) {
        @unlink($path);
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $job = json_decode($raw, true);
    return is_array($job) ? $job : null;
}

function saveJob(string $jobId, array $job): bool
{
    $path = jobPath($jobId);
    if ($path === '') {
        return false;
    }

    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, $path);
}

function cleanupOldJobs(): void
{
    $dir = tempJobDir();
    $files = @glob($dir . DIRECTORY_SEPARATOR . 'job_*.json');
    if (!is_array($files)) {
        return;
    }

    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - (int)@filemtime($file)) > JOB_TTL) {
            @unlink($file);
        }
    }
}

function curlOptions(string $url): array
{
    return [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'GujRERA-Bulk-Processor/1.0'
    ];
}

/*
 * Parallel GET requests.
 * Returns keyed results:
 * [key => ['success'=>bool,'response'=>string,'error'=>string,'http_code'=>int]]
 */
function parallelGet(array $requests, int $concurrency = CONCURRENCY): array
{
    $results = [];
    if (!$requests) {
        return $results;
    }

    $queue = [];
    foreach ($requests as $key => $url) {
        $queue[] = ['key' => (string)$key, 'url' => $url];
    }

    $mh = curl_multi_init();
    $active = [];
    $next = 0;

    $addHandle = function (string $key, string $url) use ($mh, &$active): void {
        $ch = curl_init();
        if ($ch === false) {
            $active[$key] = [
                'handle' => null,
                'key' => $key,
                'url' => $url,
                'init_error' => 'Unable to initialize cURL.'
            ];
            return;
        }

        curl_setopt_array($ch, curlOptions($url));
        curl_multi_add_handle($mh, $ch);
        $active[(int)$ch] = [
            'handle' => $ch,
            'key' => $key,
            'url' => $url
        ];
    };

    $fill = function () use (&$next, &$queue, &$active, $concurrency, $addHandle): void {
        while ($next < count($queue) && count($active) < $concurrency) {
            $item = $queue[$next++];
            $addHandle($item['key'], $item['url']);
        }
    };

    $fill();

    do {
        do {
            $multiStatus = curl_multi_exec($mh, $running);
        } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

        if ($multiStatus !== CURLM_OK) {
            foreach ($active as $info) {
                if ($info['handle'] !== null) {
                    $ch = $info['handle'];
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                }
                $results[$info['key']] = [
                    'success' => false,
                    'response' => '',
                    'error' => 'curl_multi_exec error: ' . $multiStatus,
                    'http_code' => 0
                ];
            }
            $active = [];
            break;
        }

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $handleId = (int)$ch;
            if (!isset($active[$handleId])) {
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                continue;
            }

            $meta = $active[$handleId];
            $response = curl_multi_getcontent($ch);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($info['result'] !== CURLE_OK || $curlError !== '') {
                $results[$meta['key']] = [
                    'success' => false,
                    'response' => (string)$response,
                    'error' => 'cURL error #' . $curlErrno . ': ' . ($curlError !== '' ? $curlError : 'Request failed.'),
                    'http_code' => $httpCode
                ];
            } elseif ($httpCode < 200 || $httpCode >= 300) {
                $results[$meta['key']] = [
                    'success' => false,
                    'response' => (string)$response,
                    'error' => 'GujRERA returned HTTP ' . $httpCode . '.',
                    'http_code' => $httpCode
                ];
            } else {
                $results[$meta['key']] = [
                    'success' => true,
                    'response' => (string)$response,
                    'error' => '',
                    'http_code' => $httpCode
                ];
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[$handleId]);
            $fill();
        }

        if ($running && $active) {
            $selected = curl_multi_select($mh, 1.0);
            if ($selected === -1) {
                usleep(10000);
            }
        }
    } while ($running || $active);

    curl_multi_close($mh);
    return $results;
}

function promoterName(array $promoter): string
{
    return trim((string)($promoter['promoterName'] ?? ''));
}

function personName(array $person, string $prefix): string
{
    $first = trim((string)($person[$prefix . 'FirstName'] ?? ''));
    $middle = trim((string)($person[$prefix . 'MiddleName'] ?? ''));
    $last = trim((string)($person[$prefix . 'LastName'] ?? ''));
    return trim(implode(' ', array_filter([$first, $middle, $last], function ($v) {
        return $v !== '';
    })));
}

function joinAddress(array $parts): string
{
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $clean[] = $part;
        }
    }
    return implode(', ', $clean);
}

function normalizePromoterResponse(string $response): array
{
    $decoded = decodeJson($response);
    if ($decoded === null) {
        return [false, null, 'API 2 returned invalid JSON.'];
    }

    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $promoter = $decoded['data'];
    } else {
        $promoter = $decoded;
    }

    if (!is_array($promoter) || !$promoter) {
        return [false, null, 'API 2 returned empty promoter data.'];
    }

    return [true, $promoter, ''];
}

/*
 * Process a batch:
 * 1) API 1 for all projects in parallel.
 * 2) Collect unique promoter IDs.
 * 3) API 2 only for uncached promoter IDs, in parallel.
 * 4) Attach promoter data to each project.
 */
function processBatch(array &$job, array $indexes): array
{
    $projects = $job['projects'] ?? [];
    $results = $job['results'] ?? [];
    $cache = $job['promoter_cache'] ?? [];

    $api1Requests = [];
    $validIndexes = [];

    foreach ($indexes as $index) {
        $index = (int)$index;
        if (!isset($projects[$index]) || !is_array($projects[$index])) {
            continue;
        }
        $validIndexes[] = $index;
        $projectId = $projects[$index]['entityId'] ?? '';
        if (!validId($projectId)) {
            $results[(string)$index] = [
                'success' => false,
                'error' => 'Invalid or missing PROJECT entityId.',
                'api1' => null,
                'api2' => null,
                'promoterId' => null,
                'promoter' => null
            ];
            continue;
        }
        $api1Requests[(string)$index] = API1_BASE . (int)$projectId;
    }

    $api1Responses = parallelGet($api1Requests, CONCURRENCY);
    $promoterRequests = [];
    $projectPromoterIds = [];

    foreach ($validIndexes as $index) {
        $key = (string)$index;
        if (!isset($api1Responses[$key])) {
            continue;
        }

        $api1 = $api1Responses[$key];
        if (!$api1['success']) {
            $results[$key] = [
                'success' => false,
                'error' => 'API 1: ' . $api1['error'],
                'api1' => $api1,
                'api2' => null,
                'promoterId' => null,
                'promoter' => null
            ];
            continue;
        }

        $decoded = decodeJson($api1['response']);
        $apiProject = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data'] : null;

        if (!$apiProject) {
            $results[$key] = [
                'success' => false,
                'error' => 'API 1 returned empty or invalid project data.',
                'api1' => $api1,
                'api2' => null,
                'promoterId' => null,
                'promoter' => null
            ];
            continue;
        }

        $promoterId = $apiProject['promoterId'] ?? '';
        if (!validId($promoterId)) {
            $results[$key] = [
                'success' => false,
                'error' => 'API 1 did not return a valid promoterId.',
                'api1' => $api1,
                'api2' => null,
                'promoterId' => null,
                'promoter' => null
            ];
            continue;
        }

        $promoterId = (string)(int)$promoterId;
        $projectPromoterIds[$key] = $promoterId;

        if (!isset($cache[$promoterId])) {
            $promoterRequests[$promoterId] = API2_BASE . $promoterId;
        }
    }

    if ($promoterRequests) {
        $api2Responses = parallelGet($promoterRequests, CONCURRENCY);
        foreach ($api2Responses as $promoterId => $api2) {
            if ($api2['success']) {
                [$ok, $promoter, $error] = normalizePromoterResponse($api2['response']);
                if ($ok) {
                    $cache[$promoterId] = [
                        'success' => true,
                        'promoter' => $promoter,
                        'api2' => $api2
                    ];
                } else {
                    $cache[$promoterId] = [
                        'success' => false,
                        'promoter' => null,
                        'api2' => $api2,
                        'error' => $error
                    ];
                }
            } else {
                $cache[$promoterId] = [
                    'success' => false,
                    'promoter' => null,
                    'api2' => $api2,
                    'error' => $api2['error']
                ];
            }
        }
    }

    foreach ($projectPromoterIds as $key => $promoterId) {
        $api1 = $api1Responses[$key];
        $cacheEntry = $cache[$promoterId] ?? null;

        if (!$cacheEntry || empty($cacheEntry['success'])) {
            $results[$key] = [
                'success' => false,
                'error' => 'API 2: ' . ($cacheEntry['error'] ?? 'Promoter data unavailable.'),
                'api1' => $api1,
                'api2' => $cacheEntry['api2'] ?? null,
                'promoterId' => $promoterId,
                'promoter' => null
            ];
            continue;
        }

        $results[$key] = [
            'success' => true,
            'error' => '',
            'api1' => $api1,
            'api2' => $cacheEntry['api2'],
            'promoterId' => $promoterId,
            'promoter' => $cacheEntry['promoter']
        ];
    }

    $job['results'] = $results;
    $job['promoter_cache'] = $cache;
    return $results;
}

function cleanForClientResult(array $job, array $indexes): array
{
    $out = [];
    foreach ($indexes as $index) {
        $key = (string)(int)$index;
        if (!isset($job['results'][$key])) {
            continue;
        }
        $r = $job['results'][$key];
        $out[$key] = [
            'success' => !empty($r['success']),
            'error' => (string)($r['error'] ?? ''),
            'promoterId' => $r['promoterId'] ?? null,
            'promoterName' => !empty($r['promoter']) && is_array($r['promoter'])
                ? promoterName($r['promoter']) : '',
        ];
    }
    return $out;
}

function excelXmlEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function excelCell($value, string $type = 'String', string $style = ''): string
{
    $styleAttr = $style !== '' ? ' ss:StyleID="' . $style . '"' : '';
    return '<Cell' . $styleAttr . '><Data ss:Type="' . $type . '">' .
        excelXmlEscape($value) . '</Data></Cell>';
}

function buildPeople(array $promoter): array
{
    $people = [];
    $associates = is_array($promoter['assosiateList'] ?? null)
        ? $promoter['assosiateList'] : [];
    $authorized = is_array($promoter['authorizedSignatoryList'] ?? null)
        ? $promoter['authorizedSignatoryList'] : [];

    foreach ($associates as $person) {
        if (!is_array($person)) continue;
        $people[] = [
            'name' => personName($person, 'associate'),
            'mobile' => $person['assocaiteMobileNumber'] ?? '',
            'email' => $person['assocaiteEmailId'] ?? '',
            'address' => joinAddress([
                $person['associateAddress'] ?? '',
                $person['associateAddress2'] ?? '',
                $person['assocaiteTalukaName'] ?? '',
                $person['assocaiteDistrictName'] ?? '',
                $person['assocaiteStateName'] ?? '',
                $person['assocaitePinCode'] ?? ''
            ]),
            'pan' => $person['associatePan'] ?? '',
            'authorized' => ''
        ];
    }

    foreach ($authorized as $person) {
        if (!is_array($person)) continue;
        $people[] = [
            'name' => personName($person, 'authsign'),
            'mobile' => $person['authsignMobileNumber'] ?? '',
            'email' => $person['authsignEmailId'] ?? '',
            'address' => joinAddress([
                $person['authsignAddress'] ?? '',
                $person['authsignAddress2'] ?? '',
                $person['authsignTalukaName'] ?? '',
                $person['authsignDistrictName'] ?? '',
                $person['authsignStateName'] ?? '',
                $person['authsignPinCode'] ?? ''
            ]),
            'pan' => $person['authsignPan'] ?? '',
            'authorized' => 'Authorized'
        ];
    }

    return $people;
}

function exportExcel(string $jobId): void
{
    $job = loadJob($jobId);
    if (!$job) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Bulk job not found or expired.';
        exit;
    }

    $projects = $job['projects'] ?? [];
    $results = $job['results'] ?? [];

    $xml = '<?xml version="1.0"?>' .
        '<?mso-application progid="Excel.Sheet"?>' .
        '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ' .
        'xmlns:o="urn:schemas-microsoft-com:office:office" ' .
        'xmlns:x="urn:schemas-microsoft-com:office:excel" ' .
        'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
        '<Styles>' .
        '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/></Style>' .
        '<Style ss:ID="Authorized"><Font ss:Bold="1"/><Interior ss:Color="#E2F0D9" ss:Pattern="Solid"/></Style>' .
        '</Styles>' .
        '<Worksheet ss:Name="Project People"><Table>';

    $headers = [
        'Project Name',
        'Group / Promoter Name',
        'Person Name',
        'Mobile Number',
        'Email',
        'Address',
        'PAN',
        'Authorized'
    ];

    $xml .= '<Row>';
    foreach ($headers as $header) {
        $xml .= excelCell($header, 'String', 'Header');
    }
    $xml .= '</Row>';

    foreach ($projects as $index => $project) {
        $key = (string)$index;
        $result = $results[$key] ?? null;

        if (!$result || empty($result['success']) || !is_array($result['promoter'] ?? null)) {
            // Keep a visible project row even if API processing failed.
            $xml .= '<Row>' .
                excelCell($project['entityName'] ?? '') .
                excelCell('') .
                excelCell('ERROR: ' . ($result['error'] ?? 'Not processed')) .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                '</Row>';

            for ($i = 0; $i < 3; $i++) {
                $xml .= '<Row></Row>';
            }
            continue;
        }

        $promoter = $result['promoter'];
        $projectName = trim((string)($project['entityName'] ?? ''));
        $groupName = promoterName($promoter);
        $people = buildPeople($promoter);

        if (!$people) {
            $xml .= '<Row>' .
                excelCell($projectName) .
                excelCell($groupName) .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                excelCell('') .
                '</Row>';
        } else {
            foreach ($people as $person) {
                $style = $person['authorized'] === 'Authorized' ? 'Authorized' : '';
                $xml .= '<Row>' .
                    excelCell($projectName) .
                    excelCell($groupName) .
                    excelCell($person['name']) .
                    excelCell($person['mobile']) .
                    excelCell($person['email']) .
                    excelCell($person['address']) .
                    excelCell($person['pan']) .
                    excelCell($person['authorized'], 'String', $style) .
                    '</Row>';
            }
        }

        // EXACTLY 3 blank rows after every project.
        for ($i = 0; $i < 3; $i++) {
            $xml .= '<Row></Row>';
        }
    }

    $xml .= '</Table></Worksheet></Workbook>';

    // Prevent any previous output from corrupting the Excel file.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gujrera_project_people.xls"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $xml;
    exit;
}

/* -------------------------------------------------------------------------
 * AJAX / API ROUTES
 * ------------------------------------------------------------------------- */
if (isset($_GET['action'])) {
    cleanupOldJobs();
    $action = (string)$_GET['action'];

    if ($action === 'init') {
        $raw = trim((string)($_POST['json_data'] ?? ''));
        if ($raw === '') {
            jsonResponse(['success' => false, 'error' => 'Please paste JSON data.'], 400);
        }

        $decoded = decodeJson($raw);
        if ($decoded === null) {
            jsonResponse(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            jsonResponse(['success' => false, 'error' => 'JSON must contain a top-level "data" array.'], 400);
        }

        $projects = [];
        foreach ($decoded['data'] as $record) {
            if (!is_array($record)) continue;
            if (strtoupper(trim((string)($record['entityType'] ?? ''))) !== 'PROJECT') continue;
            $projects[] = $record;
        }

        if (!$projects) {
            jsonResponse(['success' => false, 'error' => 'No entityType = PROJECT records were found.'], 400);
        }

        $jobId = bin2hex(random_bytes(16));
        $job = [
            'created_at' => time(),
            'projects' => $projects,
            'results' => [],
            'promoter_cache' => []
        ];

        if (!saveJob($jobId, $job)) {
            jsonResponse(['success' => false, 'error' => 'Unable to create the bulk job on the server.'], 500);
        }

        jsonResponse([
            'success' => true,
            'jobId' => $jobId,
            'total' => count($projects),
            'batchSize' => BATCH_SIZE,
            'concurrency' => CONCURRENCY
        ]);
    }

    if ($action === 'process') {
        $jobId = (string)($_POST['jobId'] ?? '');
        $start = max(0, (int)($_POST['start'] ?? 0));
        $batchSize = max(1, min(BATCH_SIZE, (int)($_POST['batchSize'] ?? BATCH_SIZE)));

        $job = loadJob($jobId);
        if (!$job) {
            jsonResponse(['success' => false, 'error' => 'Bulk job not found or expired.'], 404);
        }

        $total = count($job['projects'] ?? []);
        if ($start >= $total) {
            jsonResponse([
                'success' => true,
                'done' => true,
                'start' => $start,
                'processed' => $total,
                'total' => $total,
                'results' => []
            ]);
        }

        $indexes = range($start, min($total - 1, $start + $batchSize - 1));
        processBatch($job, $indexes);

        if (!saveJob($jobId, $job)) {
            jsonResponse(['success' => false, 'error' => 'Unable to save bulk progress.'], 500);
        }

        $processed = min($total, $start + count($indexes));
        $successCount = 0;
        $errorCount = 0;
        foreach ($job['results'] as $r) {
            if (!empty($r['success'])) $successCount++;
            else $errorCount++;
        }

        jsonResponse([
            'success' => true,
            'done' => $processed >= $total,
            'start' => $start,
            'nextStart' => $processed,
            'processed' => $processed,
            'total' => $total,
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'results' => cleanForClientResult($job, $indexes)
        ]);
    }

    if ($action === 'export') {
        $jobId = (string)($_GET['jobId'] ?? '');
        exportExcel($jobId);
    }

    if ($action === 'status') {
        $jobId = (string)($_GET['jobId'] ?? '');
        $job = loadJob($jobId);
        if (!$job) {
            jsonResponse(['success' => false, 'error' => 'Bulk job not found or expired.'], 404);
        }
        $total = count($job['projects'] ?? []);
        $processed = count($job['results'] ?? []);
        $successCount = 0;
        foreach ($job['results'] as $r) {
            if (!empty($r['success'])) $successCount++;
        }
        jsonResponse([
            'success' => true,
            'processed' => $processed,
            'total' => $total,
            'successCount' => $successCount,
            'errorCount' => $processed - $successCount
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GujRERA Bulk Project Processor</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fb;color:#172033;font-family:Arial,Helvetica,sans-serif}
.container{width:min(1450px,calc(100% - 30px));margin:30px auto 60px}
.header{background:linear-gradient(135deg,#172554,#1e3a8a);color:#fff;padding:30px;border-radius:20px;margin-bottom:20px;box-shadow:0 12px 35px rgba(15,23,42,.14)}
.header h1{margin:0 0 8px;font-size:30px}.header p{margin:0;color:#dbeafe}
.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:20px;box-shadow:0 8px 28px rgba(15,23,42,.07)}
.card-title{margin:0 0 14px;font-size:21px}
textarea{width:100%;min-height:340px;resize:vertical;padding:15px;border:1px solid #cbd5e1;border-radius:12px;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.5;outline:none}
textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
button,.download{border:0;border-radius:10px;padding:13px 20px;font-weight:700;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}
.primary{background:#2563eb;color:#fff}.primary:hover{background:#1d4ed8}
.secondary{background:#e2e8f0;color:#172033}.download{background:#15803d;color:#fff}.download.disabled{opacity:.45;pointer-events:none}
.progress-card{display:none}.progress-top{display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:10px;font-weight:700}
.progress{height:18px;background:#e2e8f0;border-radius:999px;overflow:hidden}.bar{height:100%;width:0;background:#2563eb;transition:width .2s ease}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:15px}.stat{padding:14px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.stat-label{font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700}.stat-value{font-size:22px;font-weight:800;margin-top:4px}.green{color:#15803d}.red{color:#b91c1c}.blue{color:#1d4ed8}
.status{margin-top:12px;padding:12px;border-radius:10px;background:#f8fafc;color:#475569}.error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.results{display:none}.project-row{padding:14px 0;border-bottom:1px solid #e2e8f0;display:grid;grid-template-columns:50px 1fr 160px 120px;gap:12px;align-items:center}.badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:700}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.muted{color:#64748b}
.note{font-size:13px;color:#64748b;line-height:1.5;margin-top:10px}
@media(max-width:800px){.stats{grid-template-columns:repeat(2,1fr)}.project-row{grid-template-columns:35px 1fr}.project-row>div:nth-child(3),.project-row>div:nth-child(4){grid-column:2}.header h1{font-size:24px}}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>GujRERA Bulk Project Processor</h1>
        <p>Process multiple PROJECT records with parallel API requests and export everything into one Excel sheet.</p>
    </div>

    <div class="card">
        <h2 class="card-title">Paste GujRERA JSON</h2>
        <textarea id="jsonData" placeholder='Paste JSON containing a top-level "data" array here...'></textarea>
        <div class="actions">
            <button class="primary" id="startBtn" type="button">Start Bulk Processing</button>
            <button class="secondary" id="clearBtn" type="button">Clear</button>
        </div>
        <div class="note">
            Only records with <b>entityType = PROJECT</b> are processed. PROMOTER records in the input are ignored.
            Each AJAX batch processes up to <?php echo BATCH_SIZE; ?> projects, with up to <?php echo CONCURRENCY; ?> HTTP requests in parallel.
        </div>
    </div>

    <div class="card progress-card" id="progressCard">
        <div class="progress-top">
            <span id="progressText">Starting...</span>
            <span id="percentText">0%</span>
        </div>
        <div class="progress"><div class="bar" id="progressBar"></div></div>
        <div class="stats">
            <div class="stat"><div class="stat-label">Total Projects</div><div class="stat-value blue" id="totalStat">0</div></div>
            <div class="stat"><div class="stat-label">Processed</div><div class="stat-value" id="processedStat">0</div></div>
            <div class="stat"><div class="stat-label">Successful</div><div class="stat-value green" id="successStat">0</div></div>
            <div class="stat"><div class="stat-label">Errors</div><div class="stat-value red" id="errorStat">0</div></div>
        </div>
        <div class="status" id="statusBox">Waiting...</div>
        <div class="actions">
            <a class="download disabled" id="downloadBtn" href="#">Download One Excel</a>
        </div>
    </div>

    <div class="card results" id="resultsCard">
        <h2 class="card-title">Processing Details</h2>
        <div id="resultsList"></div>
    </div>
</div>

<script>
(function(){
    const BATCH_SIZE = <?php echo BATCH_SIZE; ?>;
    const jsonData = document.getElementById('jsonData');
    const startBtn = document.getElementById('startBtn');
    const clearBtn = document.getElementById('clearBtn');
    const progressCard = document.getElementById('progressCard');
    const progressText = document.getElementById('progressText');
    const percentText = document.getElementById('percentText');
    const progressBar = document.getElementById('progressBar');
    const totalStat = document.getElementById('totalStat');
    const processedStat = document.getElementById('processedStat');
    const successStat = document.getElementById('successStat');
    const errorStat = document.getElementById('errorStat');
    const statusBox = document.getElementById('statusBox');
    const downloadBtn = document.getElementById('downloadBtn');
    const resultsCard = document.getElementById('resultsCard');
    const resultsList = document.getElementById('resultsList');

    let running = false;
    let jobId = '';
    let total = 0;
    let processed = 0;
    let successCount = 0;
    let errorCount = 0;

    function escapeHtml(value){
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function setProgress(){
        const pct = total ? Math.min(100, Math.round((processed / total) * 100)) : 0;
        progressBar.style.width = pct + '%';
        percentText.textContent = pct + '%';
        progressText.textContent = processed >= total ? 'Completed' : ('Processing ' + processed + ' of ' + total + ' projects');
        totalStat.textContent = total;
        processedStat.textContent = processed;
        successStat.textContent = successCount;
        errorStat.textContent = errorCount;
    }

    function addResults(batchResults){
        Object.keys(batchResults || {}).forEach(function(key){
            const r = batchResults[key];
            const projectNumber = Number(key) + 1;
            const row = document.createElement('div');
            row.className = 'project-row';
            const status = r.success
                ? '<span class="badge ok">Success</span>'
                : '<span class="badge bad">Error</span>';
            row.innerHTML = '<div><b>#' + projectNumber + '</b></div>' +
                '<div>' + (r.promoterName ? '<b>' + escapeHtml(r.promoterName) + '</b>' : '<span class="muted">Promoter not available</span>') +
                (r.promoterId ? '<br><span class="muted">Promoter ID: ' + escapeHtml(r.promoterId) + '</span>' : '') +
                (r.error ? '<br><span class="muted">' + escapeHtml(r.error) + '</span>' : '') + '</div>' +
                '<div>' + status + '</div>' +
                '<div class="muted">Batch result</div>';
            resultsList.appendChild(row);
        });
        resultsCard.style.display = 'block';
    }

    async function postForm(url, data){
        const response = await fetch(url, {method:'POST', body:data, cache:'no-store'});
        const text = await response.text();
        let dataJson;
        try { dataJson = JSON.parse(text); }
        catch(e){ throw new Error('Server returned an invalid response. ' + text.slice(0,300)); }
        if(!response.ok || !dataJson.success){
            throw new Error(dataJson.error || ('HTTP ' + response.status));
        }
        return dataJson;
    }

    async function startProcessing(){
        if(running) return;
        const raw = jsonData.value.trim();
        if(!raw){
            alert('Please paste the JSON data first.');
            return;
        }

        running = true;
        startBtn.disabled = true;
        clearBtn.disabled = true;
        downloadBtn.classList.add('disabled');
        downloadBtn.href = '#';
        resultsList.innerHTML = '';
        resultsCard.style.display = 'none';
        progressCard.style.display = 'block';
        statusBox.className = 'status';
        statusBox.textContent = 'Creating bulk job...';
        processed = 0; successCount = 0; errorCount = 0; total = 0;
        setProgress();

        try{
            const form = new FormData();
            form.append('json_data', raw);
            const init = await postForm('?action=init', form);
            jobId = init.jobId;
            total = Number(init.total) || 0;
            setProgress();
            statusBox.textContent = 'Bulk job created. Processing batches in parallel...';

            while(processed < total){
                const batchForm = new FormData();
                batchForm.append('jobId', jobId);
                batchForm.append('start', String(processed));
                batchForm.append('batchSize', String(BATCH_SIZE));

                const result = await postForm('?action=process', batchForm);
                processed = Number(result.processed) || processed;
                successCount = Number(result.successCount) || 0;
                errorCount = Number(result.errorCount) || 0;
                addResults(result.results || {});
                setProgress();
                statusBox.textContent = result.done
                    ? 'All projects processed successfully. Your Excel is ready.'
                    : 'Batch completed. Starting the next batch...';

                // Yield to the browser so progress/UI can repaint.
                await new Promise(function(resolve){ setTimeout(resolve, 20); });
            }

            downloadBtn.href = '?action=export&jobId=' + encodeURIComponent(jobId);
            downloadBtn.classList.remove('disabled');
            setProgress();
            statusBox.textContent = 'Completed. Click “Download One Excel” to download the single-sheet Excel file.';
        }catch(error){
            statusBox.className = 'status error';
            statusBox.textContent = error.message || 'An unexpected error occurred.';
        }finally{
            running = false;
            startBtn.disabled = false;
            clearBtn.disabled = false;
        }
    }

    startBtn.addEventListener('click', startProcessing);
    clearBtn.addEventListener('click', function(){
        if(running) return;
        jsonData.value = '';
        progressCard.style.display = 'none';
        resultsCard.style.display = 'none';
        resultsList.innerHTML = '';
        downloadBtn.classList.add('disabled');
        downloadBtn.href = '#';
        jobId = ''; total = 0; processed = 0; successCount = 0; errorCount = 0;
    });
})();
</script>
</body>
</html>
