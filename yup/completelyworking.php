<?php
/*
|--------------------------------------------------------------------------
| GujRERA PROJECT FOCUSED DATA
|--------------------------------------------------------------------------
| INPUT:
| Paste JSON containing a top-level "data" array.
|
| ONLY entityType = "PROJECT" records are processed.
|
| For every PROJECT:
|
|   input entityId
|        |
|        v
|   /project_reg/public/alldatabyprojectid/{entityId}
|        |
|        v
|   promoterId
|        |
|        v
|   /user_reg/promoter/promoter{promoterId}
|
| PROMOTER records in the input JSON are ignored.
|
| No project ID is requested from the user.
|--------------------------------------------------------------------------
*/

$inputJson = '';
$records = [];

$results = [];
$errors = [];

$submitted = false;

/*
|--------------------------------------------------------------------------
| Safe HTML output
|--------------------------------------------------------------------------
*/
function h($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Validate numeric GujRERA ID
|--------------------------------------------------------------------------
*/
function validId($value): bool
{
    return preg_match('/^[0-9]+$/', (string)$value)
        && (int)$value > 0
        && (int)$value <= 2147483647;
}

/*
|--------------------------------------------------------------------------
| Simple GujRERA GET
|--------------------------------------------------------------------------
*/
function gujreraGet(string $url): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'response' => '',
            'error' => 'Unable to initialize cURL.',
            'http_code' => 0
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,

        // SSL verification stays enabled.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],

        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);

    $response = curl_exec($ch);

    $error = curl_error($ch);
    $errorNumber = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'response' => '',
            'error' => 'cURL error #' . $errorNumber . ': ' .
                ($error !== '' ? $error : 'Unknown cURL error'),
            'http_code' => $httpCode
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'response' => $response,
            'error' => 'GujRERA returned HTTP ' . $httpCode . '.',
            'http_code' => $httpCode
        ];
    }

    return [
        'success' => true,
        'response' => $response,
        'error' => '',
        'http_code' => $httpCode
    ];
}

/*
|--------------------------------------------------------------------------
| JSON decode
|--------------------------------------------------------------------------
*/
function decodeJson(string $json): ?array
{
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : null;
}

/*
|--------------------------------------------------------------------------
| Display value
|--------------------------------------------------------------------------
*/
function valueOrDash($value): string
{
    if ($value === null || $value === '') {
        return '<span class="muted">—</span>';
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    if (is_array($value)) {
        return '<span class="muted">—</span>';
    }

    return h($value);
}

/*
|--------------------------------------------------------------------------
| Phone
|--------------------------------------------------------------------------
*/
function phoneLinks($number): string
{
    $number = trim((string)$number);

    if ($number === '') {
        return '<span class="muted">—</span>';
    }

    $digits = preg_replace('/\D+/', '', $number);

    $html =
        '<a class="phone-link" href="tel:' . h($number) . '">' .
        h($number) .
        '</a>';

    if ($digits !== '') {
        $html .=
            ' <a class="whatsapp-link" target="_blank" rel="noopener noreferrer" ' .
            'href="https://wa.me/' . h($digits) . '">' .
            'WhatsApp' .
            '</a>';
    }

    return $html;
}

/*
|--------------------------------------------------------------------------
| Person name
|--------------------------------------------------------------------------
*/
function personName(array $person, string $prefix): string
{
    $first = trim((string)($person[$prefix . 'FirstName'] ?? ''));
    $middle = trim((string)($person[$prefix . 'MiddleName'] ?? ''));
    $last = trim((string)($person[$prefix . 'LastName'] ?? ''));

    return trim(implode(' ', array_filter(
        [$first, $middle, $last],
        static function ($part) {
            return $part !== '';
        }
    )));
}

/*
|--------------------------------------------------------------------------
| Process one PROJECT
|--------------------------------------------------------------------------
*/
function processProject(array $project, int $projectIndex): array
{
    $entityId = $project['entityId'] ?? '';

    if (!validId($entityId)) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'Invalid or missing PROJECT entityId.',
            'api1' => null,
            'api2' => null,
            'promoterId' => null,
            'promoter' => null
        ];
    }

    $projectId = (int)$entityId;

    /*
     * API 1:
     * The PROJECT entityId from the input is used directly.
     */
    $api1Url =
        'https://gujrera.gujarat.gov.in/project_reg/public/alldatabyprojectid/' .
        $projectId;

    $api1 = gujreraGet($api1Url);

    if (!$api1['success']) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 1: ' . $api1['error'],
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => null,
            'promoterId' => null,
            'promoter' => null
        ];
    }

    $api1Data = decodeJson($api1['response']);

    if ($api1Data === null) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 1 returned invalid JSON.',
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => null,
            'promoterId' => null,
            'promoter' => null
        ];
    }

    if (
        !isset($api1Data['data']) ||
        !is_array($api1Data['data']) ||
        count($api1Data['data']) === 0
    ) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 1 returned empty project data.',
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => null,
            'promoterId' => null,
            'promoter' => null
        ];
    }

    $apiProject = $api1Data['data'];

    if (
        !isset($apiProject['promoterId']) ||
        !validId($apiProject['promoterId'])
    ) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 1 did not return a valid promoterId.',
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => null,
            'promoterId' => null,
            'promoter' => null
        ];
    }

    $promoterId = (int)$apiProject['promoterId'];

    /*
     * API 2:
     * Use promoterId returned by API 1.
     */
    $api2Url =
        'https://gujrera.gujarat.gov.in/user_reg/promoter/promoter' .
        $promoterId;

    $api2 = gujreraGet($api2Url);

    if (!$api2['success']) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 2: ' . $api2['error'],
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => [
                'url' => $api2Url,
                'http_code' => $api2['http_code'],
                'response' => $api2['response']
            ],
            'promoterId' => $promoterId,
            'promoter' => null
        ];
    }

    $api2Data = decodeJson($api2['response']);

    if ($api2Data === null) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 2 returned invalid JSON.',
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => [
                'url' => $api2Url,
                'http_code' => $api2['http_code'],
                'response' => $api2['response']
            ],
            'promoterId' => $promoterId,
            'promoter' => null
        ];
    }

    /*
     * Support direct promoter object or {data:{...}}.
     */
    if (
        isset($api2Data['data']) &&
        is_array($api2Data['data'])
    ) {
        $promoter = $api2Data['data'];
    } else {
        $promoter = $api2Data;
    }

    if (!is_array($promoter) || count($promoter) === 0) {
        return [
            'success' => false,
            'index' => $projectIndex,
            'project' => $project,
            'error' => 'API 2 returned empty promoter data.',
            'api1' => [
                'url' => $api1Url,
                'http_code' => $api1['http_code'],
                'response' => $api1['response']
            ],
            'api2' => [
                'url' => $api2Url,
                'http_code' => $api2['http_code'],
                'response' => $api2['response']
            ],
            'promoterId' => $promoterId,
            'promoter' => null
        ];
    }

    return [
        'success' => true,
        'index' => $projectIndex,
        'project' => $project,
        'apiProject' => $apiProject,
        'error' => '',
        'api1' => [
            'url' => $api1Url,
            'http_code' => $api1['http_code'],
            'response' => $api1['response']
        ],
        'api2' => [
            'url' => $api2Url,
            'http_code' => $api2['http_code'],
            'response' => $api2['response']
        ],
        'promoterId' => $promoterId,
        'promoter' => $promoter
    ];
}

/*
|--------------------------------------------------------------------------
| FORM SUBMISSION
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted = true;

    $inputJson = trim((string)($_POST['json_data'] ?? ''));

    if ($inputJson === '') {

        $errors[] = 'Please paste the JSON data.';

    } else {

        $decodedInput = decodeJson($inputJson);

        if ($decodedInput === null) {

            $errors[] =
                'Invalid JSON. ' . json_last_error_msg();

        } elseif (
            !isset($decodedInput['data']) ||
            !is_array($decodedInput['data'])
        ) {

            $errors[] =
                'The JSON must contain a top-level "data" array.';

        } else {

            /*
             * IMPORTANT:
             * Only PROJECT records are selected.
             * PROMOTER records are completely ignored.
             */
            foreach ($decodedInput['data'] as $record) {

                if (
                    !is_array($record) ||
                    strtoupper(trim((string)($record['entityType'] ?? ''))) !==
                    'PROJECT'
                ) {
                    continue;
                }

                $records[] = $record;
            }

            if (count($records) === 0) {

                $errors[] =
                    'No records with entityType = PROJECT were found.';

            } else {

                foreach ($records as $index => $project) {

                    $result =
                        processProject($project, $index + 1);

                    if ($result['success']) {
                        $results[] = $result;
                    } else {
                        $errors[] =
                            'Project #' . ($index + 1) . ' (' .
                            ($project['entityName'] ?? 'Unknown') .
                            '): ' .
                            $result['error'];
                        $results[] = $result;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>GujRERA Project Data</title>

<style>
* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    margin: 0;
    background: #f4f7fb;
    color: #172033;
    font-family: Arial, Helvetica, sans-serif;
}

a {
    text-decoration: none;
}

.container {
    width: min(1400px, calc(100% - 30px));
    margin: 30px auto 60px;
}

/* HEADER */

.header {
    background: linear-gradient(135deg, #172554, #1e3a8a);
    color: #fff;
    padding: 30px;
    border-radius: 20px;
    margin-bottom: 20px;
    box-shadow: 0 12px 35px rgba(15, 23, 42, .14);
}

.header h1 {
    margin: 0 0 8px;
    font-size: 30px;
}

.header p {
    margin: 0;
    color: #dbeafe;
}

/* CARDS */

.card {
    background: #fff;
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: 0 8px 28px rgba(15, 23, 42, .07);
}

.card-title {
    margin: 0 0 15px;
    font-size: 21px;
}

/* TEXTAREA */

textarea {
    width: 100%;
    min-height: 300px;
    resize: vertical;
    padding: 15px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    font-family: Consolas, Monaco, monospace;
    font-size: 13px;
    line-height: 1.5;
    outline: none;
}

textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
}

/* BUTTON */

.button-row {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.fetch-button {
    border: 0;
    border-radius: 10px;
    background: #2563eb;
    color: #fff;
    padding: 14px 25px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
}

.fetch-button:hover {
    background: #1d4ed8;
}

.loading {
    display: none;
    color: #2563eb;
    font-weight: 700;
}

/* SUMMARY */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}

.summary-box {
    padding: 17px;
    border-radius: 13px;
    background: #f8fafc;
    border: 1px solid #dbe3ef;
}

.summary-box.success {
    background: #ecfdf5;
    border-color: #86efac;
}

.summary-box.error {
    background: #fff7f7;
    border-color: #fecaca;
}

.summary-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.summary-value {
    font-size: 23px;
    font-weight: 800;
}

.summary-box.success .summary-value {
    color: #15803d;
}

.summary-box.error .summary-value {
    color: #b91c1c;
}

/* PROJECT CARD */

.project-card {
    border-left: 5px solid #2563eb;
}

.project-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 18px;
}

.project-title {
    margin: 0;
    font-size: 23px;
}

.project-subtitle {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 14px;
}

.project-number {
    background: #e0e7ff;
    color: #3730a3;
    padding: 7px 11px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

/* ID BOXES */

.id-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}

.id-box {
    background: #f8fafc;
    border: 1px solid #dbe3ef;
    border-radius: 12px;
    padding: 14px;
}

.id-box.highlight {
    background: #ecfdf5;
    border-color: #86efac;
}

.id-label {
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.id-value {
    font-size: 20px;
    font-weight: 800;
}

.id-box.highlight .id-value {
    color: #15803d;
}

/* INFO GRID */

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 11px;
}

.info-box {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 13px;
    background: #fff;
}

.info-label {
    display: block;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    word-break: break-word;
}

/* SECTION */

.section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin: 22px 0 12px;
}

.section-head h3 {
    margin: 0;
    font-size: 19px;
}

.badge {
    background: #e0e7ff;
    color: #3730a3;
    border-radius: 999px;
    padding: 6px 11px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

/* TABLE */

.table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}

.data-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

.data-table th {
    background: #f8fafc;
    color: #334155;
    font-size: 11px;
    text-transform: uppercase;
    padding: 12px;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid #e2e8f0;
}

.data-table td {
    padding: 12px;
    font-size: 13px;
    vertical-align: top;
    border-bottom: 1px solid #e2e8f0;
}

.data-table tbody tr:hover {
    background: #f8fafc;
}

.data-table tr:last-child td {
    border-bottom: 0;
}

.number-cell {
    width: 45px;
    color: #64748b;
    font-weight: 800;
}

/* LINKS */

.phone-link {
    color: #15803d;
    font-weight: 700;
}

.phone-link:hover,
.whatsapp-link:hover {
    text-decoration: underline;
}

.whatsapp-link {
    color: #15803d;
    font-weight: 700;
    margin-left: 6px;
}

.muted {
    color: #94a3b8;
}

/* STATUS */

.status-success {
    color: #15803d;
    font-weight: 700;
}

.status-error {
    color: #b91c1c;
    font-weight: 700;
}

/* ERROR */

.error-card {
    border-left: 5px solid #dc2626;
    background: #fff8f8;
}

.error-card h3 {
    margin-top: 0;
    color: #b91c1c;
}

.error-list {
    margin: 0;
    padding-left: 20px;
}

.error-list li {
    margin-bottom: 7px;
}

/* DETAILS */

details {
    margin-top: 12px;
}

summary {
    cursor: pointer;
    font-weight: 700;
    color: #334155;
}

pre {
    background: #0f172a;
    color: #e2e8f0;
    padding: 16px;
    border-radius: 10px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 12px;
    line-height: 1.5;
}

/* MOBILE */

@media (max-width: 900px) {

    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .id-grid,
    .info-grid {
        grid-template-columns: 1fr;
    }

    .project-head {
        flex-direction: column;
    }
}

@media (max-width: 600px) {

    .container {
        width: calc(100% - 20px);
        margin-top: 15px;
    }

    .header {
        padding: 22px;
        border-radius: 15px;
    }

    .header h1 {
        font-size: 24px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .button-row {
        flex-direction: column;
        align-items: stretch;
    }

    .fetch-button {
        width: 100%;
    }
}
</style>

</head>

<body>

<div class="container">

    <div class="header">
        <h1>GujRERA Project Data</h1>
        <p>
            Paste GujRERA JSON — PROJECT records are automatically processed
            and connected to their promoters.
        </p>
    </div>


    <!-- JSON INPUT -->

    <div class="card">

        <h2 class="card-title">
            Paste GujRERA JSON
        </h2>

        <form method="post" id="jsonForm">

            <textarea
                name="json_data"
                id="json_data"
                placeholder='Paste JSON containing a "data" array here...'
                required><?= h($inputJson) ?></textarea>

            <div class="button-row">

                <button
                    type="submit"
                    class="fetch-button"
                    id="fetchButton"
                >
                    Fetch Project Data
                </button>

                <span
                    class="loading"
                    id="loading"
                >
                    Processing PROJECT records...
                </span>

            </div>

        </form>

    </div>


    <!-- ERRORS -->

    <?php if (count($errors) > 0): ?>

        <div class="card error-card">

            <h3>
                Processing Issues
            </h3>

            <ul class="error-list">

                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= h($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <?php if ($submitted && count($records) > 0): ?>

        <?php
        $totalProjects = count($records);
        $successfulProjects = 0;
        $failedProjects = 0;

        foreach ($results as $r) {
            if (!empty($r['success'])) {
                $successfulProjects++;
            } else {
                $failedProjects++;
            }
        }
        ?>

        <!-- SUMMARY -->

        <div class="card">

            <div class="summary-grid">

                <div class="summary-box">

                    <div class="summary-label">
                        PROJECT records
                    </div>

                    <div class="summary-value">
                        <?= h($totalProjects) ?>
                    </div>

                </div>

                <div class="summary-box success">

                    <div class="summary-label">
                        Successfully fetched
                    </div>

                    <div class="summary-value">
                        <?= h($successfulProjects) ?>
                    </div>

                </div>

                <div class="summary-box error">

                    <div class="summary-label">
                        Failed
                    </div>

                    <div class="summary-value">
                        <?= h($failedProjects) ?>
                    </div>

                </div>

                <div class="summary-box">

                    <div class="summary-label">
                        PROMOTER records ignored
                    </div>

                    <div class="summary-value">
                        <?= h(
                            count($decodedInput['data'] ?? []) -
                            $totalProjects
                        ) ?>
                    </div>

                </div>

            </div>

        </div>


        <!-- PROJECT RESULTS -->

        <?php foreach ($results as $result): ?>

            <?php
            $project = $result['project'];
            $projectIndex = $result['index'];
            ?>

            <div class="card project-card">

                <div class="project-head">

                    <div>

                        <h2 class="project-title">
                            <?= h(
                                $project['entityName'] ??
                                'Unnamed Project'
                            ) ?>
                        </h2>

                        <p class="project-subtitle">
                            <?= h(
                                $project['distName'] ??
                                ''
                            ) ?>

                            <?php if (!empty($project['taluka'])): ?>
                                ·
                                <?= h($project['taluka']) ?>
                            <?php endif; ?>
                        </p>

                    </div>

                    <span class="project-number">
                        PROJECT #<?= h($projectIndex) ?>
                    </span>

                </div>


                <!-- INPUT PROJECT DETAILS -->

                <div class="info-grid">

                    <div class="info-box">
                        <span class="info-label">
                            Project Address
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['address'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            Description
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['description'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            RERA Registration
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['regNo'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            Project Type
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['ptype'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            District
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['distName'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            Taluka
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['taluka'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            Project Period
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['pdate'] ?? ''
                            ) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <span class="info-label">
                            Input Entity ID
                        </span>

                        <div class="info-value">
                            <?= valueOrDash(
                                $project['entityId'] ?? ''
                            ) ?>
                        </div>
                    </div>

                </div>


                <?php if (!empty($result['success'])): ?>

                    <?php
                    $apiProject =
                        $result['apiProject'] ?? [];

                    $promoter =
                        $result['promoter'] ?? [];

                    $associates =
                        isset($promoter['assosiateList']) &&
                        is_array($promoter['assosiateList'])
                            ? $promoter['assosiateList']
                            : [];

                    $authorized =
                        isset($promoter['authorizedSignatoryList']) &&
                        is_array($promoter['authorizedSignatoryList'])
                            ? $promoter['authorizedSignatoryList']
                            : [];
                    ?>


                    <!-- IDs -->

                    <div class="section-head">

                        <h3>
                            GujRERA API Information
                        </h3>

                        <span class="badge">
                            API 1 + API 2 Success
                        </span>

                    </div>

                    <div class="id-grid">

                        <div class="id-box">

                            <div class="id-label">
                                Project ID Used
                            </div>

                            <div class="id-value">
                                <?= h(
                                    $project['entityId'] ?? ''
                                ) ?>
                            </div>

                        </div>

                        <div class="id-box">

                            <div class="id-label">
                                Project Registration ID
                            </div>

                            <div class="id-value">
                                <?= h(
                                    $apiProject['projRegId'] ??
                                    $apiProject['wfoId'] ??
                                    ''
                                ) ?>
                            </div>

                        </div>

                        <div class="id-box highlight">

                            <div class="id-label">
                                Promoter ID
                            </div>

                            <div class="id-value">
                                <?= h(
                                    $result['promoterId'] ?? ''
                                ) ?>
                            </div>

                        </div>

                    </div>


                    <!-- API PROJECT DETAILS -->

                    <div class="info-grid">

                        <div class="info-box">
                            <span class="info-label">
                                API Project Name
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['projectName'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                API RERA Number
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['projRegNo'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                API Project Type
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['projectType'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                API Promoter Name
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['promoterName'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Promoter Email
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['promoterEmailId'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Promoter Mobile
                            </span>

                            <div class="info-value">
                                <?= phoneLinks(
                                    $apiProject['promoterMobileNo'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Approved Date
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['approvedDate'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Project Acknowledgement
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $apiProject['projectAckNo'] ?? ''
                                ) ?>
                            </div>
                        </div>

                    </div>


                    <!-- PROMOTER -->

                    <div class="section-head">

                        <h3>
                            Promoter Details
                        </h3>

                        <span class="badge">
                            ID: <?= h(
                                $result['promoterId'] ?? ''
                            ) ?>
                        </span>

                    </div>

                    <div class="info-grid">

                        <div class="info-box">
                            <span class="info-label">
                                Promoter Name
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['promoterName'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Promoter Type
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['promoterType'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Email
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['emailId'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Mobile
                            </span>

                            <div class="info-value">
                                <?= phoneLinks(
                                    $promoter['mobileNo'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Address
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['address'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Address 2
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['address2'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                District
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['districtName'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                Taluka
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['talukaName'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                PAN
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['panNo'] ?? ''
                                ) ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <span class="info-label">
                                PIN Code
                            </span>

                            <div class="info-value">
                                <?= valueOrDash(
                                    $promoter['pinCode'] ?? ''
                                ) ?>
                            </div>
                        </div>

                    </div>


                    <!-- AUTHORIZED SIGNATORIES -->

                    <div class="section-head">

                        <h3>
                            AUTHORITY / AUTHORIZED SIGNATORY
                        </h3>

                        <span class="badge">
                            <?= h(count($authorized)) ?>
                            records
                        </span>

                    </div>

                    <?php if (count($authorized) === 0): ?>

                        <p class="muted">
                            No authorized signatory records returned.
                        </p>

                    <?php else: ?>

                        <div class="table-wrap">

                            <table class="data-table">

                                <thead>

                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Mobile</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>District</th>
                                    <th>Taluka</th>
                                    <th>PAN</th>
                                </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($authorized as $i => $person): ?>

                                    <tr>

                                        <td class="number-cell">
                                            <?= h($i + 1) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(
                                                    personName(
                                                        $person,
                                                        'authsign'
                                                    ) ?: '—'
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= phoneLinks(
                                                $person[
                                                    'authsignMobileNumber'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'authsignEmailId'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'authsignAddress'
                                                ] ?? ''
                                            ) ?>

                                            <?php if (!empty(
                                                $person['authsignAddress2']
                                            )): ?>

                                                <br>

                                                <?= valueOrDash(
                                                    $person[
                                                        'authsignAddress2'
                                                    ]
                                                ) ?>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'authsignDistrictName'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'authsignTalukaName'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'authsignPan'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>


                    <!-- ASSOCIATED PEOPLE -->

                    <div class="section-head">

                        <h3>
                            ASSOCIATED PEOPLE
                        </h3>

                        <span class="badge">
                            <?= h(count($associates)) ?>
                            records
                        </span>

                    </div>

                    <?php if (count($associates) === 0): ?>

                        <p class="muted">
                            No associated people records returned.
                        </p>

                    <?php else: ?>

                        <div class="table-wrap">

                            <table class="data-table">

                                <thead>

                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Mobile</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>District</th>
                                    <th>Taluka</th>
                                    <th>PAN</th>
                                </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($associates as $i => $person): ?>

                                    <tr>

                                        <td class="number-cell">
                                            <?= h($i + 1) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(
                                                    personName(
                                                        $person,
                                                        'associate'
                                                    ) ?: '—'
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= phoneLinks(
                                                $person[
                                                    'assocaiteMobileNumber'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'assocaiteEmailId'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'associateAddress'
                                                ] ?? ''
                                            ) ?>

                                            <?php if (!empty(
                                                $person['associateAddress2']
                                            )): ?>

                                                <br>

                                                <?= valueOrDash(
                                                    $person[
                                                        'associateAddress2'
                                                    ]
                                                ) ?>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'assocaiteDistrictName'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'assocaiteTalukaName'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= valueOrDash(
                                                $person[
                                                    'associatePan'
                                                ] ?? ''
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>


                    <!-- API STATUS -->

                    <div class="section-head">

                        <h3>
                            API Status
                        </h3>

                    </div>

                    <div class="info-grid">

                        <div class="info-box">

                            <span class="info-label">
                                API 1
                            </span>

                            <div class="status-success">
                                HTTP
                                <?= h(
                                    $result['api1']['http_code']
                                ) ?>
                                — Success
                            </div>

                            <details>

                                <summary>
                                    API 1 URL
                                </summary>

                                <pre><?= h(
                                    $result['api1']['url']
                                ) ?></pre>

                            </details>

                        </div>


                        <div class="info-box">

                            <span class="info-label">
                                API 2
                            </span>

                            <div class="status-success">
                                HTTP
                                <?= h(
                                    $result['api2']['http_code']
                                ) ?>
                                — Success
                            </div>

                            <details>

                                <summary>
                                    API 2 URL
                                </summary>

                                <pre><?= h(
                                    $result['api2']['url']
                                ) ?></pre>

                            </details>

                        </div>

                    </div>


                    <!-- RAW API DATA -->

                    <details>

                        <summary>
                            Show raw API 1 response
                        </summary>

                        <pre><?= h(
                            $result['api1']['response']
                        ) ?></pre>

                    </details>


                    <details>

                        <summary>
                            Show raw API 2 response
                        </summary>

                        <pre><?= h(
                            $result['api2']['response']
                        ) ?></pre>

                    </details>


                <?php else: ?>

                    <!-- FAILED PROJECT -->

                    <div class="card error-card">

                        <h3>
                            Project API Processing Failed
                        </h3>

                        <p>
                            <?= h($result['error'] ?? 'Unknown error') ?>
                        </p>

                        <?php if (
                            !empty($result['api1']['response'])
                        ): ?>

                            <details>

                                <summary>
                                    Show API 1 response
                                </summary>

                                <pre><?= h(
                                    $result['api1']['response']
                                ) ?></pre>

                            </details>

                        <?php endif; ?>

                        <?php if (
                            !empty($result['api2']['response'])
                        ): ?>

                            <details>

                                <summary>
                                    Show API 2 response
                                </summary>

                                <pre><?= h(
                                    $result['api2']['response']
                                ) ?></pre>

                            </details>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>


    <div style="
        text-align:center;
        color:#94a3b8;
        font-size:12px;
        margin-top:25px;
    ">
        Only PROJECT records from the supplied JSON are processed.
    </div>

</div>


<script>
document.getElementById('jsonForm').addEventListener('submit', function () {

    const button = document.getElementById('fetchButton');
    const loading = document.getElementById('loading');

    button.disabled = true;
    button.textContent = 'Processing...';

    loading.style.display = 'inline';
});
</script>

</body>
</html>
