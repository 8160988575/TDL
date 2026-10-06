<?php
// TEMPORARY GUJRERA API 1 -> API 2 TEST
// This file does NOT modify or depend on the main PHP file.
//
// Flow:
// API 1:
// /project_reg/public/alldatabyprojectid/13705
//
// Extract:
// promoterId = 14777
//
// API 2:
// /user_reg/promoter/promoter14777
//
// Then display the RAW API 2 response.

$projectId = 13705;

function callGujreraApi(string $url): array
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Keep SSL verification enabled.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);

    $errorNumber = curl_errno($ch);
    $errorMessage = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);

    return [
        'response' => $response,
        'error_number' => $errorNumber,
        'error_message' => $errorMessage,
        'http_code' => $httpCode,
        'content_type' => $contentType
    ];
}

echo '<h2>GujRERA API 1 → API 2 Temporary Test</h2>';


// ============================================================
// API 1
// ============================================================

$api1Url =
    'https://gujrera.gujarat.gov.in/project_reg/public/alldatabyprojectid/'
    . $projectId;

echo '<h3>STEP 1 — API 1</h3>';
echo '<p><strong>URL:</strong> '
    . htmlspecialchars($api1Url, ENT_QUOTES, 'UTF-8')
    . '</p>';

$api1 = callGujreraApi($api1Url);

echo '<p><strong>HTTP Code:</strong> '
    . htmlspecialchars((string)$api1['http_code'])
    . '</p>';

echo '<p><strong>cURL Error:</strong> '
    . htmlspecialchars(
        $api1['error_message'] !== ''
            ? $api1['error_message']
            : 'None'
    )
    . '</p>';

if ($api1['response'] === false) {
    echo '<h3 style="color:red">API 1 FAILED</h3>';
    exit;
}

echo '<h4>API 1 RAW RESPONSE</h4>';
echo '<pre>'
    . htmlspecialchars($api1['response'], ENT_QUOTES, 'UTF-8')
    . '</pre>';


// ============================================================
// Decode API 1
// ============================================================

$api1Data = json_decode($api1['response'], true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo '<h3 style="color:red">API 1 JSON DECODE FAILED</h3>';
    echo '<p>'
        . htmlspecialchars(json_last_error_msg(), ENT_QUOTES, 'UTF-8')
        . '</p>';
    exit;
}

if (
    !isset($api1Data['data']) ||
    !is_array($api1Data['data'])
) {
    echo '<h3 style="color:red">API 1 data object not found.</h3>';
    exit;
}

if (
    !isset($api1Data['data']['promoterId']) ||
    !filter_var(
        $api1Data['data']['promoterId'],
        FILTER_VALIDATE_INT
    )
) {
    echo '<h3 style="color:red">promoterId not found in API 1.</h3>';
    exit;
}

$promoterId = (int)$api1Data['data']['promoterId'];

echo '<h3 style="color:green">API 1 SUCCESS</h3>';
echo '<p style="font-size:22px"><strong>Promoter ID: '
    . htmlspecialchars((string)$promoterId)
    . '</strong></p>';


// ============================================================
// API 2
// ============================================================

$api2Url =
    'https://gujrera.gujarat.gov.in/user_reg/promoter/promoter'
    . $promoterId;

echo '<hr>';
echo '<h3>STEP 2 — API 2</h3>';
echo '<p><strong>URL:</strong> '
    . htmlspecialchars($api2Url, ENT_QUOTES, 'UTF-8')
    . '</p>';

$api2 = callGujreraApi($api2Url);

echo '<p><strong>HTTP Code:</strong> '
    . htmlspecialchars((string)$api2['http_code'])
    . '</p>';

echo '<p><strong>Content Type:</strong> '
    . htmlspecialchars((string)$api2['content_type'])
    . '</p>';

echo '<p><strong>cURL Error:</strong> '
    . htmlspecialchars(
        $api2['error_message'] !== ''
            ? $api2['error_message']
            : 'None'
    )
    . '</p>';

if ($api2['response'] === false) {
    echo '<h3 style="color:red">API 2 FAILED</h3>';
    exit;
}


// ============================================================
// RAW API 2 RESPONSE
// ============================================================

echo '<h3>API 2 RAW RESPONSE</h3>';

echo '<pre style="
    background:#111827;
    color:#e5e7eb;
    padding:20px;
    border-radius:8px;
    white-space:pre-wrap;
    word-break:break-word;
">'
    . htmlspecialchars($api2['response'], ENT_QUOTES, 'UTF-8')
    . '</pre>';


// ============================================================
// API 2 JSON TEST
// ============================================================

$api2Data = json_decode($api2['response'], true);

echo '<hr>';
echo '<h3>API 2 JSON STATUS</h3>';

if (json_last_error() !== JSON_ERROR_NONE) {

    echo '<p style="color:red;font-size:18px"><strong>
        API 2 returned a response, but it is not valid JSON.
    </strong></p>';

    echo '<p>JSON Error: '
        . htmlspecialchars(json_last_error_msg(), ENT_QUOTES, 'UTF-8')
        . '</p>';

} else {

    echo '<p style="color:green;font-size:20px"><strong>
        API 2 JSON DECODE SUCCESS
    </strong></p>';

    echo '<p><strong>Promoter ID used:</strong> '
        . htmlspecialchars((string)$promoterId)
        . '</p>';

    echo '<h4>Decoded API 2 Response</h4>';

    echo '<pre>'
        . htmlspecialchars(
            json_encode(
                $api2Data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ),
            ENT_QUOTES,
            'UTF-8'
        )
        . '</pre>';
}

?>
