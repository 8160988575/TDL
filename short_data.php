<?php
/*
 * THE DIVINE LANDS — PROJECT EXPLORER
 * Standalone Core PHP + MySQL page for browsing projects by area.
 * Database: tdl | Main table: data | Projects grouped by grp_id.
 * This page does not alter the existing bulk-data page.
 */
session_start();

$con = @mysqli_connect('localhost', 'root', '', 'tdl');
if (!$con) {
    http_response_code(500);
    exit('Database connection failed. Check the MySQL connection settings in project_explorer.php.');
}
mysqli_set_charset($con, 'utf8mb4');

if (empty($_SESSION['project_explorer_csrf'])) {
    $_SESSION['project_explorer_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['project_explorer_csrf'];

function pe_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pe_redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}
function pe_url(array $changes = []): string {
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    return $base . ($query ? '?' . http_build_query($query) : '');
}
function pe_bind_values(mysqli_stmt $stmt, string $types, array &$values): bool {
    $args = [$types];
    foreach ($values as $key => &$value) $args[] = &$value;
    return (bool)call_user_func_array([$stmt, 'bind_param'], $args);
}

$columns = [];
$columnTypes = [];
$columnResult = mysqli_query($con, "SHOW COLUMNS FROM `data`");
if (!$columnResult) {
    http_response_code(500);
    exit('Could not read the data table structure: ' . pe_h(mysqli_error($con)));
}
while ($col = mysqli_fetch_assoc($columnResult)) {
    $field = (string)$col['Field'];
    $columns[] = $field;
    $columnTypes[$field] = (string)$col['Type'];
}
mysqli_free_result($columnResult);

if (!in_array('id', $columns, true) || !in_array('grp_id', $columns, true)) {
    http_response_code(500);
    exit('The data table must contain id and grp_id columns.');
}

$notice = '';
$error = '';

/* Save all editable columns for one row. IDs are protected from editing. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_record'])) {
    $sentToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $sentToken)) {
        $error = 'Security token expired. Refresh the page and try again.';
    } else {
        $rowId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
        $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT);
        $posted = $_POST['fields'] ?? [];
        if (!$rowId || !$groupId || !is_array($posted)) {
            $error = 'Invalid record submitted.';
        } else {
            $editable = array_values(array_filter($columns, static function ($column) {
                return !in_array($column, ['id', 'grp_id'], true);
            }));
            $sets = [];
            $values = [];
            $types = '';
            foreach ($editable as $column) {
                if (!array_key_exists($column, $posted)) continue;
                $sets[] = '`' . str_replace('`', '``', $column) . '` = ?';
                $values[] = is_array($posted[$column]) ? '' : trim((string)$posted[$column]);
                $types .= 's';
            }
            if (!$sets) {
                $error = 'There were no editable fields to save.';
            } else {
                $values[] = (int)$rowId;
                $values[] = (int)$groupId;
                $types .= 'ii';
                $sql = 'UPDATE `data` SET ' . implode(', ', $sets) . ' WHERE `id` = ? AND `grp_id` = ? LIMIT 1';
                $stmt = mysqli_prepare($con, $sql);
                if (!$stmt) {
                    $error = 'Could not prepare save: ' . mysqli_error($con);
                } else {
                    pe_bind_values($stmt, $types, $values);
                    if (mysqli_stmt_execute($stmt)) {
                        $notice = 'Record #' . (int)$rowId . ' saved successfully.';
                    } else {
                        $error = 'Save failed: ' . mysqli_stmt_error($stmt);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

/* Area options: area values may contain comma-separated multiple areas. */
$areaOptions = [];
$areaColExists = in_array('area', $columns, true);
if ($areaColExists) {
    $areaResult = mysqli_query($con, "SELECT DISTINCT `area` FROM `data` WHERE `area` IS NOT NULL AND TRIM(`area`) <> ''");
    if ($areaResult) {
        while ($ar = mysqli_fetch_assoc($areaResult)) {
            foreach (preg_split('/\s*,\s*/', (string)$ar['area']) as $part) {
                $part = trim($part);
                if ($part !== '') $areaOptions[mb_strtolower($part, 'UTF-8')] = $part;
            }
        }
        mysqli_free_result($areaResult);
    }
}
natcasesort($areaOptions);
$areaOptions = array_values($areaOptions);

$selectedArea = trim((string)($_GET['area'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$groupId = filter_var($_GET['grp_id'] ?? null, FILTER_VALIDATE_INT);
$groupId = $groupId && $groupId > 0 ? (int)$groupId : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$groups = [];
$totalGroups = 0;
$detailRows = [];
$projectMeta = ['company_name' => '', 'scheme_name' => '', 'area' => ''];

/* Fetch complete detail records on demand. */
if ($groupId > 0) {
    $stmt = mysqli_prepare($con, "SELECT * FROM `data` WHERE `grp_id` = ? ORDER BY `id` ASC");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $groupId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $detailRows[] = $row;
            foreach (['company_name', 'scheme_name', 'area'] as $meta) {
                if (array_key_exists($meta, $row) && strcmp((string)$row[$meta], (string)$projectMeta[$meta]) > 0) {
                    $projectMeta[$meta] = (string)$row[$meta];
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
    if (!$detailRows) $error = 'This project could not be found, or it has no records.';
}

/* Build one prepared WHERE clause for the compact project directory. */
$where = ['d.`grp_id` > 0'];
$params = [];
$types = '';
if ($selectedArea !== '' && $areaColExists) {
    $where[] = "EXISTS (
        SELECT 1 FROM `data` da
        WHERE da.`grp_id` = d.`grp_id`
          AND FIND_IN_SET(?, REPLACE(REPLACE(COALESCE(da.`area`, ''), ', ', ','), ' ,', ',')) > 0
    )";
    $params[] = $selectedArea;
    $types .= 's';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $searchFields = [];
    foreach (['company_name', 'scheme_name'] as $field) {
        if (in_array($field, $columns, true)) $searchFields[] = "ds.`$field` LIKE ?";
    }
    if (in_array('grp_id', $columns, true)) $searchFields[] = "CAST(ds.`grp_id` AS CHAR) LIKE ?";
    if ($searchFields) {
        $where[] = 'EXISTS (SELECT 1 FROM `data` ds WHERE ds.`grp_id` = d.`grp_id` AND (' . implode(' OR ', $searchFields) . '))';
        foreach ($searchFields as $_) { $params[] = $like; $types .= 's'; }
    }
}
$whereSql = implode(' AND ', $where);

/* Count projects (groups) for pagination. */
$countSql = "SELECT COUNT(*) AS total FROM (SELECT d.`grp_id` FROM `data` d WHERE $whereSql GROUP BY d.`grp_id`) project_count";
$countStmt = mysqli_prepare($con, $countSql);
if ($countStmt) {
    if ($types !== '') pe_bind_values($countStmt, $types, $params);
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $totalGroups = (int)(mysqli_fetch_assoc($countResult)['total'] ?? 0);
    mysqli_stmt_close($countStmt);
}
$totalPages = max(1, (int)ceil($totalGroups / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "SELECT d.`grp_id`,
    MAX(" . (in_array('company_name', $columns, true) ? "d.`company_name`" : "''") . ") AS company_name,
    MAX(" . (in_array('scheme_name', $columns, true) ? "d.`scheme_name`" : "''") . ") AS scheme_name,
    MAX(" . ($areaColExists ? "d.`area`" : "''") . ") AS area,
    COUNT(*) AS record_count
    FROM `data` d
    WHERE $whereSql
    GROUP BY d.`grp_id`
    ORDER BY MAX(" . (in_array('scheme_name', $columns, true) ? "d.`scheme_name`" : "d.`grp_id`") . ") ASC
    LIMIT ? OFFSET ?";
$listStmt = mysqli_prepare($con, $listSql);
if ($listStmt) {
    $listParams = $params;
    $listTypes = $types . 'ii';
    $listParams[] = $perPage;
    $listParams[] = $offset;
    pe_bind_values($listStmt, $listTypes, $listParams);
    mysqli_stmt_execute($listStmt);
    $listResult = mysqli_stmt_get_result($listStmt);
    while ($row = mysqli_fetch_assoc($listResult)) $groups[] = $row;
    mysqli_stmt_close($listStmt);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>The Divine Lands — Project Explorer</title>
<style>
:root{--gold:#e8b65b;--gold-dark:#c99535;--navy:#101c32;--navy2:#172641;--navy3:#223452;--bg:#f5f7fb;--text:#172033;--muted:#6d7788;--border:#e5e9f0;--green:#159957;--red:#dc3545;--blue:#2878d4;--white:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--text)}button,input,select,textarea{font:inherit}
.topbar{min-height:78px;background:linear-gradient(135deg,var(--navy),var(--navy2));display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px clamp(16px,3vw,38px);box-shadow:0 8px 30px #101c322e}
.brand{display:flex;align-items:center;gap:14px;color:#fff}.logo-mark{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,#fff,#f5e5c5);display:grid;place-items:center;color:var(--navy);font-size:25px;font-weight:900;border:2px solid #e8b65b}
.brand strong{display:block;font-size:18px;letter-spacing:1.2px}.brand span{display:block;color:#d9e0ec;font-size:12px;margin-top:4px}.top-pill{color:#f6d99d;border:1px solid #ffffff35;border-radius:999px;padding:9px 13px;font-size:12px;font-weight:700}
.page{max-width:1500px;margin:auto;padding:clamp(16px,3vw,34px)}.hero{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}.eyebrow{color:var(--gold-dark);font-size:11px;font-weight:800;letter-spacing:1.8px;text-transform:uppercase}.hero h1{margin:7px 0 0;color:var(--navy);font-size:clamp(26px,3vw,34px);letter-spacing:-.7px}.hero p{margin:8px 0 0;color:var(--muted);font-size:14px;line-height:1.5}.total-chip{background:#fff;border:1px solid var(--border);border-radius:14px;padding:13px 17px;min-width:125px;text-align:right;box-shadow:0 5px 18px #17203308}.total-chip strong{display:block;color:var(--navy);font-size:23px}.total-chip span{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.7px}
.panel{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:0 12px 35px #17203308;overflow:hidden;margin-bottom:20px}.filter-head{padding:19px 21px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}.filter-icon{width:37px;height:37px;display:grid;place-items:center;border-radius:11px;background:#fff5e2;color:#9a6817;font-size:18px}.filter-head strong{color:var(--navy);font-size:15px}.filter-head span{display:block;color:var(--muted);font-size:12px;margin-top:4px}.filters{padding:20px;display:grid;grid-template-columns:minmax(190px,.8fr) minmax(260px,1.6fr) auto auto;gap:13px;align-items:end}.field label{display:block;font-size:11px;font-weight:800;color:#596477;text-transform:uppercase;letter-spacing:.8px;margin:0 0 8px}.control{width:100%;min-height:45px;border:1px solid #dce2eb;border-radius:10px;background:#fff;padding:11px 13px;color:var(--text);outline:none;transition:border .15s,box-shadow .15s}.control:focus{border-color:var(--gold-dark);box-shadow:0 0 0 3px #e8b65b2b}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:10px;padding:12px 16px;min-height:44px;text-decoration:none;font-weight:800;font-size:13px;cursor:pointer;white-space:nowrap;transition:transform .15s,filter .15s}.btn:hover{filter:brightness(.97);transform:translateY(-1px)}.btn-primary{background:var(--navy);color:#fff}.btn-gold{background:var(--gold);color:var(--navy)}.btn-light{background:#f1f4f8;color:var(--navy);border:1px solid var(--border)}.notice{padding:13px 16px;border-radius:11px;margin-bottom:16px;font-size:13px;font-weight:700}.success{background:#e9f8f0;color:#116b3d;border:1px solid #bce8cf}.danger{background:#fff0f0;color:#a62632;border:1px solid #ffd1d5}
.list-head{padding:18px 21px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--border)}.list-head h2{font-size:17px;color:var(--navy);margin:0}.list-head p{margin:5px 0 0;color:var(--muted);font-size:12px}.list-head .count{font-size:12px;color:var(--muted);font-weight:700}.project-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px;padding:18px}.project-card{display:flex;gap:14px;align-items:flex-start;padding:17px;border:1px solid var(--border);border-radius:14px;background:#fff;min-width:0;transition:border .15s,box-shadow .15s,transform .15s}.project-card:hover{border-color:#e7c47f;box-shadow:0 8px 23px #1720330c;transform:translateY(-1px)}.project-symbol{width:43px;height:43px;flex:0 0 43px;border-radius:12px;background:var(--navy);color:var(--gold);display:grid;place-items:center;font-size:19px}.project-info{min-width:0;flex:1}.project-title{font-size:15px;line-height:1.35;font-weight:800;color:var(--navy);overflow-wrap:anywhere}.scheme{margin-top:5px;color:#4e5a6e;font-size:13px;line-height:1.45;overflow-wrap:anywhere}.meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.tag{font-size:11px;padding:6px 8px;border-radius:7px;background:#f3f5f9;color:#596477;font-weight:700}.tag.area{background:#fff6e6;color:#8c621d}.open-link{margin-top:13px;display:inline-flex;align-items:center;gap:7px;color:#8b5e13;font-size:12px;font-weight:800;text-decoration:none}.open-link:hover{text-decoration:underline}.empty{padding:50px 20px;text-align:center;color:var(--muted)}.empty .symbol{font-size:36px;margin-bottom:10px}.pagination{display:flex;justify-content:center;align-items:center;gap:7px;padding:18px;border-top:1px solid var(--border);flex-wrap:wrap}.page-link{padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:#fff;text-decoration:none;color:var(--navy);font-size:12px;font-weight:800}.page-link.active{background:var(--navy);color:#fff;border-color:var(--navy)}.page-link.disabled{opacity:.4;pointer-events:none}
.detail-hero{padding:22px;background:linear-gradient(135deg,var(--navy),var(--navy3));color:#fff;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.detail-hero h2{margin:6px 0 0;font-size:24px;overflow-wrap:anywhere}.detail-hero p{margin:7px 0 0;color:#d8e0ec;font-size:13px}.detail-actions{display:flex;gap:8px;flex-wrap:wrap}.detail-body{padding:20px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px;color:var(--navy);font-size:16px}.record{border:1px solid var(--border);border-radius:14px;margin-bottom:16px;overflow:hidden}.record-head{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#f8f9fc;border-bottom:1px solid var(--border);padding:13px 15px}.record-head strong{font-size:13px;color:var(--navy)}.record-head span{font-size:11px;color:var(--muted)}.record-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;padding:16px}.record-field{min-width:0}.record-field label{display:block;margin-bottom:6px;color:#667185;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:800;overflow-wrap:anywhere}.record-field input,.record-field textarea{width:100%;border:1px solid #dce2eb;border-radius:8px;padding:10px;font-size:13px;color:var(--text);background:#fff;min-height:39px;outline:none}.record-field textarea{resize:vertical;min-height:68px}.record-field input:focus,.record-field textarea:focus{border-color:var(--gold-dark);box-shadow:0 0 0 3px #e8b65b20}.readonly{background:#f2f4f8!important;color:#7a8493!important}.record-footer{display:flex;justify-content:flex-end;padding:0 16px 16px}.detail-sticky{position:sticky;bottom:12px;display:flex;justify-content:flex-end;gap:9px;padding:12px;background:#ffffffed;backdrop-filter:blur(8px);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 30px #17203314}.footer{text-align:center;padding:18px;color:#8992a1;font-size:11px;letter-spacing:1px}.small-note{font-size:11px;color:var(--muted);line-height:1.5;margin:0 0 16px}
/* Premium layout refinements */
.project-list{grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;padding:20px}
.project-card{position:relative;padding:19px;background:linear-gradient(155deg,#fff 0%,#fcfcfe 100%);border-color:#e4e9f1;box-shadow:0 4px 15px #17203308}
.project-symbol{box-shadow:0 5px 12px #101c321c}
.open-link{padding:8px 10px;border-radius:8px;background:#fff6e5}
.filter-head{background:linear-gradient(90deg,#fffaf0,#fff 70%)}
.detail-hero{position:relative;overflow:hidden;padding:27px 26px;background:radial-gradient(circle at 90% 0%,#334b70 0,transparent 36%),linear-gradient(125deg,var(--navy),#1b2d4a 68%,#263c5d)}
.detail-hero:after{content:"✦";position:absolute;right:26px;bottom:-34px;font-size:135px;color:#ffffff0a;pointer-events:none}
.detail-hero>*{position:relative;z-index:1}
.detail-hero h2{font-size:clamp(23px,3vw,30px);letter-spacing:-.5px}
.detail-body{padding:24px}
.details-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}
.details-toolbar .section-title{margin-bottom:8px;font-size:19px}
.record-count{white-space:nowrap;background:#fff4dc;border:1px solid #f2dfb5;color:#875c12;padding:9px 12px;border-radius:999px;font-size:10px;letter-spacing:1px;font-weight:900}
.records-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}
.record{margin:0;min-width:0;border:1px solid #dfe5ee;border-radius:16px;background:#fff;box-shadow:0 7px 22px #17203309;transition:box-shadow .18s,border-color .18s}
.record:focus-within{border-color:#dfbd78;box-shadow:0 10px 26px #17203312}
.record-head{padding:14px 16px;background:linear-gradient(90deg,#f5f7fb,#fff);border-bottom:1px solid #e7ebf2}
.record-head strong{display:flex;align-items:center;gap:9px;font-size:13px}
.record-head strong:before{content:"▦";display:grid;place-items:center;width:27px;height:27px;border-radius:8px;background:var(--navy);color:var(--gold);font-size:14px}
.record-head span{padding:5px 8px;background:#edf1f7;border-radius:7px;font-weight:700}
.record-fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:16px}
.record-field label{letter-spacing:.55px;color:#667185}
.record-field input,.record-field textarea{background:#fbfcfe;border-color:#e0e5ed;border-radius:9px;transition:border .15s,background .15s,box-shadow .15s}
.record-field input:focus,.record-field textarea:focus{background:#fff}
.record-field input.readonly{font-weight:800;color:#687487!important}
.record-footer{padding:0 16px 16px}
.record-footer .btn{width:100%;background:linear-gradient(135deg,var(--navy),#253b5d);box-shadow:0 5px 12px #101c321c}
.detail-sticky{margin-top:20px}
/* Color-rich Explorer refresh: keep the directory and records at two columns. */
:root{--gold:#ffbd59;--gold-dark:#b76a16;--navy:#172554;--navy2:#312e81;--navy3:#4338ca;--bg:#f4f6ff;--text:#172033;--muted:#667085;--border:#e1e6f5;--green:#07856d;--blue:#2563eb}
body{background:radial-gradient(ellipse at 8% 0%,#e8eaff 0,transparent 35%),radial-gradient(ellipse at 96% 10%,#dff8f3 0,transparent 28%),#f6f7fc}
.topbar{background:linear-gradient(115deg,#111b42 0%,#312e81 58%,#075985 100%);box-shadow:0 12px 34px #312e8130}
.logo-mark{background:linear-gradient(135deg,#fff5d8,#ffbd59);border-color:#fff0c2;box-shadow:0 5px 18px #ffbd5940}
.top-pill{background:#ffffff12;border-color:#ffffff40}
.eyebrow{color:#7c3aed}
.hero h1{background:linear-gradient(90deg,#172554,#4338ca 58%,#087f8c);-webkit-background-clip:text;background-clip:text;color:transparent}
.total-chip{border-color:#d8ddf6;background:linear-gradient(145deg,#fff,#f3f2ff);box-shadow:0 8px 22px #312e8110}
.total-chip strong{color:#4338ca}
.panel{border-color:#dfe4f5;box-shadow:0 14px 38px #252d6410}
.filter-head{background:linear-gradient(100deg,#f1efff,#eafaff 60%,#fff)}
.filter-icon{background:linear-gradient(135deg,#ede9fe,#cffafe);color:#4338ca}
.btn-primary{background:linear-gradient(115deg,#312e81,#2563eb);box-shadow:0 6px 15px #312e8128;color:#fff}
.btn-gold{background:linear-gradient(115deg,#ffcf75,#ffad42);color:#35214a;box-shadow:0 5px 14px #ffbd5933}
.btn-light{background:linear-gradient(135deg,#fff,#f2f5ff);border-color:#dfe4f5}
.list-head{background:linear-gradient(100deg,#fff,#f5f3ff)}
.project-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;padding:20px}
.project-card{background:linear-gradient(135deg,#fff 0%,#fbfbff 65%,#f1fbff 100%);border:1px solid #dce3f6;box-shadow:0 7px 22px #312e810a}
.project-card:nth-child(4n+1){border-left:4px solid #7c3aed}
.project-card:nth-child(4n+2){border-left:4px solid #0891b2}
.project-card:nth-child(4n+3){border-left:4px solid #f59e0b}
.project-card:nth-child(4n){border-left:4px solid #10b981}
.project-card:hover{border-color:#a5b4fc;box-shadow:0 13px 28px #312e8118;transform:translateY(-3px)}
.project-symbol{background:linear-gradient(145deg,#312e81,#2563eb 65%,#0891b2);color:#fff;box-shadow:0 7px 16px #312e8130}
.project-title{color:#202b62}
.scheme{color:#59647e}
.tag{background:#eeefff;color:#4438a8}
.tag.area{background:#dcfce7;color:#166534}
.project-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}
.project-actions .open-link{margin-top:0}
.open-link{background:#ede9fe;color:#4c1d95;padding:9px 11px;border-radius:9px}
.open-link.edit-project-link{background:#dff7f2;color:#066b5c}
.detail-hero{background:radial-gradient(circle at 95% 5%,#0e7490 0,transparent 32%),linear-gradient(120deg,#172554,#312e81 60%,#075985)}
.detail-hero .eyebrow{color:#fde68a!important}
.record-count{background:#fff4dc;border-color:#f6d58c;color:#854d0e}
.record{border-color:#dce3f6;box-shadow:0 8px 23px #312e810b}
.record-head{background:linear-gradient(90deg,#eef2ff,#f0fdfa 80%,#fff);border-color:#e1e7f5}
.record-head strong:before{background:linear-gradient(145deg,#312e81,#0891b2);color:#fff}
.record-head span{background:#e0e7ff;color:#3730a3}
.record-field input:focus,.record-field textarea:focus{border-color:#818cf8;box-shadow:0 0 0 3px #818cf829}
.record-footer .btn{background:linear-gradient(115deg,#312e81,#2563eb);box-shadow:0 6px 15px #312e8128}
.detail-sticky{border-color:#dfe4f5;box-shadow:0 9px 30px #312e8115}
.footer{color:#717c99}
@media(min-width:1200px){.record-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1050px){.project-list{grid-template-columns:repeat(2,minmax(0,1fr))}.records-grid{grid-template-columns:1fr}}
@media(max-width:850px){.filters{grid-template-columns:1fr 1fr}.project-list{grid-template-columns:repeat(2,minmax(0,1fr))}.record-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.hero{align-items:flex-start}.total-chip{min-width:95px}}
@media(max-width:560px){.topbar{align-items:flex-start}.top-pill{display:none}.hero{display:block}.total-chip{margin-top:16px;text-align:left;display:inline-block}.filters{grid-template-columns:1fr;padding:15px}.filter-head,.list-head{padding:15px}.project-list{grid-template-columns:1fr;padding:12px}.project-card{padding:13px}.detail-hero{display:block;padding:18px}.detail-actions{margin-top:15px}.detail-body{padding:12px}.details-toolbar{display:block}.record-count{display:inline-block;margin-bottom:13px}.records-grid{grid-template-columns:1fr;gap:13px}.record-fields{grid-template-columns:1fr;padding:12px}.detail-sticky{position:static;margin-top:12px;flex-wrap:wrap}.record-field textarea{min-height:56px}}

/* Calm, Bulk-Manager-inspired styling: restrained navy, slate and muted gold. */
:root{--gold:#d7b36a;--gold-dark:#9b7a36;--navy:#202d42;--navy2:#2b3b53;--navy3:#34465f;--bg:#f3f5f8;--text:#202938;--muted:#6b7482;--border:#dfe4eb;--green:#25845b;--blue:#476b9a}
body{background:#f3f5f8}
.topbar{background:linear-gradient(110deg,#1c293c,#2a3b52);box-shadow:0 5px 18px #18243612}
.logo-mark{background:#f3f0e8;border:1px solid #d7c59d;box-shadow:none;color:#243247}
.top-pill{background:#ffffff0a;border-color:#ffffff2b;color:#e5d6b2}
.eyebrow,.hero h1,.total-chip strong{color:#29384d;background:none;-webkit-text-fill-color:initial}
.total-chip{background:#fff;border-color:var(--border);box-shadow:0 4px 12px #18243608}
.panel{border-color:var(--border);box-shadow:0 5px 20px #18243608}
.filter-head,.list-head{background:#f8f9fb}
.filter-icon{background:#edf0f4;color:#485b73}
.btn-primary,.record-footer .btn{background:#2b3b53;color:#fff;box-shadow:none}
.btn-gold{background:#e9dfc8;color:#3d3525;box-shadow:none}
.btn-light{background:#f4f6f8;border-color:#dfe4eb}
.project-card{background:#fff;border:1px solid #dfe4eb;box-shadow:0 3px 12px #18243607}
.project-card:nth-child(4n+1),.project-card:nth-child(4n+2),.project-card:nth-child(4n+3),.project-card:nth-child(4n){border-left:1px solid #dfe4eb}
.project-card:hover{border-color:#c6ced9;box-shadow:0 6px 16px #1824360c;transform:translateY(-1px)}
.project-symbol{background:#e9edf2;color:#34465f;box-shadow:none}
.project-title,.scheme{color:#2d394a}
.tag,.tag.area{background:#f0f2f5;color:#596475}
.open-link,.open-link.edit-project-link{background:#f0f2f5;color:#34465f}
.detail-hero{background:linear-gradient(110deg,#25344a,#34465f)}
.detail-hero .eyebrow{color:#e7d7b5!important}
.record-count{background:#f3efe5;border-color:#e4dac2;color:#75613a}
.records-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.record{border-color:#dfe4eb;border-radius:12px;box-shadow:0 3px 12px #18243607}
.record-head{background:#eef1f5;border-color:#dfe4eb}
.record-head strong:before{background:#34465f;color:#e9dfc8}
.record-head span{background:#e3e7ed;color:#4e5968}
.record-fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;padding:14px}
.record-field input,.record-field textarea{background:#fff;border-color:#d8dee7;border-radius:7px}
.record-field input:focus,.record-field textarea:focus{border-color:#8796aa;box-shadow:0 0 0 2px #8796aa20}
.detail-sticky{border-color:#dfe4eb;box-shadow:0 5px 18px #1824360c}
.page-link.active{background:#34465f;border-color:#34465f}
@media(max-width:1050px){.records-grid{grid-template-columns:1fr}}

/* Project details use a spreadsheet-style table instead of record cards. */
.records-table-wrap{width:100%;overflow:auto;border:1px solid #d9e0e9;border-radius:10px;background:#fff;max-height:72vh}
.records-table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
.records-table th,.records-table td{border-right:1px solid #e1e6ed;border-bottom:1px solid #e1e6ed;padding:8px;vertical-align:top;text-align:left}
.records-table thead th{position:sticky;top:0;z-index:3;background:#e9edf3;color:#344258;font-size:11px;text-transform:uppercase;letter-spacing:.35px;white-space:nowrap}
.records-table tbody tr:nth-child(even){background:#f8fafc}
.records-table tbody tr:hover{background:#f0f4f8}
.records-table .row-number{position:sticky;left:0;z-index:2;min-width:94px;background:#eef2f6;color:#334155;white-space:nowrap}
.records-table thead .row-number{z-index:4;background:#dfe5ed}
.records-table .row-number span,.records-table .row-number small{display:block}
.records-table .row-number small{margin-top:4px;color:#697586;font-weight:500}
.records-table .table-input{width:190px;min-width:150px;min-height:34px;padding:7px 8px;border:1px solid #d4dce6;border-radius:5px;background:#fff;color:#263445;font:inherit;outline:none}
.records-table .table-input:focus{border-color:#8493a7;box-shadow:0 0 0 2px #8493a71c}
.records-table .table-textarea{width:240px;min-height:58px;resize:vertical;white-space:pre-wrap}
.records-table .readonly{background:#eef1f5!important;color:#687386!important}
.records-table .save-column{position:sticky;right:0;z-index:2;min-width:110px;background:#f0f3f7;box-shadow:-3px 0 5px #1720330a}
.records-table thead .save-column{z-index:4;background:#dfe5ed}
.records-table .btn-save-row{white-space:nowrap;padding:8px 11px;font-size:12px}
.table-row-form{display:none}
@media(max-width:560px){.records-table-wrap{max-height:68vh}.records-table .table-input{width:160px}.records-table .table-textarea{width:200px}}

</style>
</head>
<body>
<header class="topbar">
  <div class="brand"><div class="logo-mark">D</div><div><strong>THE DIVINE LANDS</strong><span>Project directory · Area-wise explorer</span></div></div>
  <div class="top-pill">✦ PROJECT EXPLORER</div>
</header>
<main class="page">
<?php if ($groupId > 0): ?>
  <div class="hero">
    <div><div class="eyebrow">Project details</div><h1>Complete project record</h1><p>Review and edit all fields saved for this project in your database.</p></div>
    <a class="btn btn-light" href="<?= pe_h(pe_url(['grp_id'=>null,'page'=>1])) ?>">← Back to projects</a>
  </div>
  <?php if ($notice): ?><div class="notice success">✓ <?= pe_h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger">! <?= pe_h($error) ?></div><?php endif; ?>
  <?php if ($detailRows): ?>
    <section class="panel">
      <div class="detail-hero">
        <div><div class="eyebrow" style="color:#f2ce84">GROUP ID <?= (int)$groupId ?></div>
          <h2><?= pe_h($projectMeta['scheme_name'] ?: 'Untitled scheme') ?></h2>
          <p><?= pe_h($projectMeta['company_name'] ?: 'Company not specified') ?><?= $projectMeta['area'] !== '' ? ' · ' . pe_h($projectMeta['area']) : '' ?></p>
        </div>
        <div class="detail-actions"><span class="top-pill"><?= count($detailRows) ?> record<?= count($detailRows) === 1 ? '' : 's' ?></span><a class="btn btn-gold" href="wapnnn14.php" target="_blank" rel="noopener noreferrer">✎ Edit group in Bulk Manager ↗</a></div>
      </div>
      <div class="detail-body">
        <div class="details-toolbar">
          <div><h3 class="section-title"><span>Project data workspace</span><span class="count"><?= count($columns) ?> fields</span></h3>
          <p class="small-note">All records are shown in a spreadsheet-style table, similar to the Bulk Manager. Edit cells and save each row independently. Record ID and Group ID stay locked to protect relationships.</p></div>
          <span class="record-count"><?= count($detailRows) ?> RECORD<?= count($detailRows) === 1 ? '' : 'S' ?></span>
        </div>
        <div class="records-table-wrap">
          <?php foreach ($detailRows as $row): ?>
            <form id="record-form-<?= (int)$row['id'] ?>" method="post" class="table-row-form">
              <input type="hidden" name="csrf_token" value="<?= pe_h($csrf) ?>">
              <input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
              <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
            </form>
          <?php endforeach; ?>
          <table class="records-table">
            <thead><tr><th class="row-number">Record</th>
              <?php foreach ($columns as $column): ?><th><?= pe_h(str_replace('_', ' ', $column)) ?></th><?php endforeach; ?>
              <th class="save-column">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($detailRows as $row): ?>
              <tr>
                <th class="row-number"><span>#<?= (int)$row['id'] ?></span><small>Group <?= (int)$groupId ?></small></th>
                <?php foreach ($columns as $column):
                  $value = $row[$column] ?? '';
                  $readonly = in_array($column, ['id','grp_id'], true);
                  $isLong = mb_strlen((string)$value, 'UTF-8') > 90 || stripos($column, 'relation') !== false || stripos($column, 'note') !== false || stripos($column, 'address') !== false;
                  $inputId = 'f_' . (int)$row['id'] . '_' . $column;
                ?>
                  <td>
                    <?php if ($readonly): ?>
                      <input class="table-input readonly" id="<?= pe_h($inputId) ?>" value="<?= pe_h($value) ?>" readonly>
                    <?php elseif ($isLong): ?>
                      <textarea class="table-input table-textarea" id="<?= pe_h($inputId) ?>" name="fields[<?= pe_h($column) ?>]" form="record-form-<?= (int)$row['id'] ?>"><?= pe_h($value) ?></textarea>
                    <?php else: ?>
                      <input class="table-input" id="<?= pe_h($inputId) ?>" name="fields[<?= pe_h($column) ?>]" value="<?= pe_h($value) ?>" form="record-form-<?= (int)$row['id'] ?>">
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td class="save-column"><button class="btn btn-primary btn-save-row" type="submit" name="save_record" value="1" form="record-form-<?= (int)$row['id'] ?>">Save #<?= (int)$row['id'] ?></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="detail-sticky"><a class="btn btn-light" href="<?= pe_h(pe_url(['grp_id'=>null])) ?>">Back to results</a><button class="btn btn-gold" type="button" onclick="window.scrollTo({top:0,behavior:'smooth'})">↑ Back to top</button></div>
      </div>
    </section>
  <?php endif; ?>
<?php else: ?>
  <div class="hero">
    <div><div class="eyebrow">The Divine Lands · Data tools</div><h1>Project Explorer</h1><p>Select an area, browse company and scheme names, then open any project to see and edit every field stored in your database.</p></div>
    <div class="total-chip"><strong><?= number_format($totalGroups) ?></strong><span>Matching projects</span></div>
  </div>
  <?php if ($notice): ?><div class="notice success">✓ <?= pe_h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger">! <?= pe_h($error) ?></div><?php endif; ?>
  <section class="panel">
    <div class="filter-head"><div class="filter-icon">⌕</div><div><strong>Quick filters</strong><span>Only company and scheme names are shown in the results.</span></div></div>
    <form class="filters" method="get">
      <div class="field"><label for="area">Select area</label><select class="control" id="area" name="area"><option value="">All areas</option><?php foreach ($areaOptions as $areaOption): ?><option value="<?= pe_h($areaOption) ?>" <?= strcasecmp($selectedArea,$areaOption)===0?'selected':'' ?>><?= pe_h($areaOption) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="q">Search companies / schemes / ID</label><input class="control" id="q" name="q" value="<?= pe_h($search) ?>" placeholder="Type a company, scheme or group ID…"></div>
      <button class="btn btn-primary" type="submit">⌕ Find projects</button>
      <a class="btn btn-light" href="<?= pe_h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Reset</a>
    </form>
  </section>
  <section class="panel">
    <div class="list-head"><div><h2>Companies & schemes</h2><p>Click any project card to open its complete editable database record.</p></div><div class="count"><?= number_format($totalGroups) ?> result<?= $totalGroups===1?'':'s' ?></div></div>
    <?php if ($groups): ?>
      <div class="project-list">
      <?php foreach ($groups as $project): $gid = (int)$project['grp_id']; ?>
        <article class="project-card">
          <div class="project-symbol">⌂</div>
          <div class="project-info">
            <div class="project-title"><?= pe_h($project['company_name'] ?: 'Company not specified') ?></div>
            <div class="scheme"><?= pe_h($project['scheme_name'] ?: 'Scheme not specified') ?></div>
            <div class="meta"><span class="tag">GROUP #<?= $gid ?></span><span class="tag"><?= (int)$project['record_count'] ?> record<?= (int)$project['record_count']===1?'':'s' ?></span><?php if (!empty($project['area'])): ?><span class="tag area">⌖ <?= pe_h($project['area']) ?></span><?php endif; ?></div>
            <div class="project-actions"><a class="open-link" href="<?= pe_h(pe_url(['grp_id'=>$gid])) ?>">▦ View project data <span aria-hidden="true">→</span></a><a class="open-link edit-project-link" href="wapnnn14.php" target="_blank" rel="noopener noreferrer">✎ Edit group ↗</a></div>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
      <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Project pages">
        <a class="page-link <?= $page<=1?'disabled':'' ?>" href="<?= pe_h(pe_url(['page'=>max(1,$page-1)])) ?>">← Previous</a>
        <?php $start=max(1,$page-2); $end=min($totalPages,$page+2); for($p=$start;$p<=$end;$p++): ?>
          <a class="page-link <?= $p===$page?'active':'' ?>" href="<?= pe_h(pe_url(['page'=>$p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a class="page-link <?= $page>=$totalPages?'disabled':'' ?>" href="<?= pe_h(pe_url(['page'=>min($totalPages,$page+1)])) ?>">Next →</a>
      </nav><?php endif; ?>
    <?php else: ?>
      <div class="empty"><div class="symbol">⌕</div><strong>No matching projects found</strong><p>Try another area or clear the search text.</p><a class="btn btn-light" href="<?= pe_h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Clear filters</a></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
</main>
<footer class="footer">THE DIVINE LANDS · PROJECT EXPLORER · CORE PHP + MYSQL</footer>
</body>
</html>
