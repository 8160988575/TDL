<?php
/* THE DIVINE LANDS — DAILY LAND PRIORITY BOARD
   Separate page. Does not modify available_lands.php.
*/
session_start();
$con = @mysqli_connect('localhost', 'root', '', 'tdl');
if (!$con) { http_response_code(500); die('Database connection failed: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8')); }
mysqli_set_charset($con, 'utf8mb4');
function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['daily_lands_csrf'])) $_SESSION['daily_lands_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['daily_lands_csrf'];

// The two ordering columns are required for persistent drag-and-drop priorities.
$colCheck = mysqli_query($con, "SHOW COLUMNS FROM `daily_lands`");
if (!$colCheck) die('Table `daily_lands` not found: ' . e(mysqli_error($con)));
$cols = [];
while ($c = mysqli_fetch_assoc($colCheck)) $cols[$c['Field']] = true;
/* Ensure priority columns exist automatically so the Add Land UI is not blocked
   by a setup screen. Existing daily_lands records are preserved. */
if (!isset($cols['area_priority'])) {
    if (!mysqli_query($con, "ALTER TABLE `daily_lands` ADD COLUMN `area_priority` INT NOT NULL DEFAULT 0")) {
        die('Could not create area_priority column. Please ensure your database user has ALTER permission. Details: ' . e(mysqli_error($con)));
    }
    $cols['area_priority'] = true;
}
if (!isset($cols['land_priority'])) {
    if (!mysqli_query($con, "ALTER TABLE `daily_lands` ADD COLUMN `land_priority` INT NOT NULL DEFAULT 0")) {
        die('Could not create land_priority column. Please ensure your database user has ALTER permission. Details: ' . e(mysqli_error($con)));
    }
    $cols['land_priority'] = true;
}

if (!isset($cols['all_priority'])) {
    if (!mysqli_query($con, "ALTER TABLE `daily_lands` ADD COLUMN `all_priority` INT NOT NULL DEFAULT 0")) {
        die('Could not create all_priority column: '.e(mysqli_error($con)));
    }
    $cols['all_priority'] = true;
}

/* SHOW/OFF toggle: OFF entries are hidden from ALL AREA and their area, but remain manageable below. */
$toggleError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_show') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $toggleError = 'Security check failed. Refresh the page and try again.';
    } else {
        $dailyLandId = (int)($_POST['daily_land_id'] ?? 0);
        $newShow = (int)($_POST['new_show_land'] ?? 0) === 1 ? 1 : 0;
        if ($dailyLandId <= 0) {
            $toggleError = 'Invalid land selected.';
        } else {
            $toggleStmt = mysqli_prepare($con, "UPDATE `daily_lands` SET `show_land`=? WHERE `id`=?");
            if (!$toggleStmt) {
                $toggleError = 'Could not prepare visibility update: ' . mysqli_error($con);
            } else {
                mysqli_stmt_bind_param($toggleStmt, 'ii', $newShow, $dailyLandId);
                if (mysqli_stmt_execute($toggleStmt) && mysqli_stmt_affected_rows($toggleStmt) >= 0) {
                    mysqli_stmt_close($toggleStmt);
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($newShow ? '?shown=1' : '?hidden=1'));
                    exit;
                }
                $toggleError = 'Could not update visibility: ' . mysqli_stmt_error($toggleStmt);
                mysqli_stmt_close($toggleStmt);
            }
        }
    }
}

/* REMOVE ONLY FROM THE DAILY PRIORITY BOARD; KEEP available_lands INTACT */
$removeError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_land') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $removeError = 'Security check failed. Refresh the page and try again.';
    } else {
        $dailyLandId = (int)($_POST['daily_land_id'] ?? 0);
        if ($dailyLandId <= 0) {
            $removeError = 'Invalid land selected.';
        } else {
            $deleteStmt = mysqli_prepare($con, "DELETE FROM `daily_lands` WHERE `id`=? LIMIT 1");
            if (!$deleteStmt) {
                $removeError = 'Could not prepare remove action: ' . mysqli_error($con);
            } else {
                mysqli_stmt_bind_param($deleteStmt, 'i', $dailyLandId);
                if (mysqli_stmt_execute($deleteStmt) && mysqli_stmt_affected_rows($deleteStmt) > 0) {
                    mysqli_stmt_close($deleteStmt);
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?removed=1');
                    exit;
                }
                $removeError = 'Could not remove this item from the priority board. It may already have been removed.';
                mysqli_stmt_close($deleteStmt);
            }
        }
    }
}

/* UPDATE AN EXISTING DAILY LAND ENTRY */
$updateError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_land') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $updateError = 'Security check failed. Refresh the page and try again.';
    } else {
        $dailyLandId = (int)($_POST['daily_land_id'] ?? 0);
        $area = trim((string)($_POST['area'] ?? ''));
        $landTitle = trim((string)($_POST['land_title'] ?? ''));
        $landDescription = trim((string)($_POST['land_description'] ?? ''));
        $availableId = (int)($_POST['available_lands_id'] ?? 0);
        if ($dailyLandId <= 0) $updateError = 'Invalid land entry selected.';
        elseif ($area === '' || $landTitle === '') $updateError = 'Please enter both Area and Land Title.';
        else {
            $existsStmt = mysqli_prepare($con, "SELECT `id`, `area` FROM `daily_lands` WHERE `id`=? LIMIT 1");
            if (!$existsStmt) $updateError = 'Could not check land entry: ' . mysqli_error($con);
            else {
                mysqli_stmt_bind_param($existsStmt, 'i', $dailyLandId);
                mysqli_stmt_execute($existsStmt);
                $oldResult = mysqli_stmt_get_result($existsStmt);
                $oldRow = mysqli_fetch_assoc($oldResult);
                mysqli_stmt_close($existsStmt);
                if (!$oldRow) $updateError = 'This daily land entry no longer exists.';
            }
            if ($updateError === '' && $availableId > 0) {
                $check = mysqli_prepare($con, "SELECT `id` FROM `available_lands` WHERE `id`=? LIMIT 1");
                if (!$check) $updateError = 'Could not validate linked land: ' . mysqli_error($con);
                else {
                    mysqli_stmt_bind_param($check, 'i', $availableId);
                    mysqli_stmt_execute($check);
                    $validLink = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
                    mysqli_stmt_close($check);
                    if (!$validLink) $updateError = 'The selected available land record was not found. Please choose it again.';
                }
            }
            if ($updateError === '') {
                $oldArea = trim((string)$oldRow['area']);
                if ($oldArea !== $area) {
                    $maxArea = mysqli_prepare($con, "SELECT COALESCE(MAX(`area_priority`),0)+1 AS next_priority FROM `daily_lands` WHERE `area`=?");
                    mysqli_stmt_bind_param($maxArea, 's', $area);
                    mysqli_stmt_execute($maxArea);
                    $maxRes = mysqli_stmt_get_result($maxArea);
                    $areaPriority = (int)(mysqli_fetch_assoc($maxRes)['next_priority'] ?? 1);
                    mysqli_stmt_close($maxArea);
                    $st = mysqli_prepare($con, "UPDATE `daily_lands` SET `area`=?, `land_title`=?, `land description`=?, `available_lands_id`=?, `area_priority`=?, `land_priority`=0 WHERE `id`=?");
                    if (!$st) $updateError = 'Could not prepare update: ' . mysqli_error($con);
                    else {
                        mysqli_stmt_bind_param($st, 'sssiii', $area, $landTitle, $landDescription, $availableId, $areaPriority, $dailyLandId);
                        if (mysqli_stmt_execute($st)) { mysqli_stmt_close($st); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1'); exit; }
                        $updateError = 'Could not update land: ' . mysqli_stmt_error($st); mysqli_stmt_close($st);
                    }
                } else {
                    $st = mysqli_prepare($con, "UPDATE `daily_lands` SET `land_title`=?, `land description`=?, `available_lands_id`=? WHERE `id`=?");
                    if (!$st) $updateError = 'Could not prepare update: ' . mysqli_error($con);
                    else {
                        mysqli_stmt_bind_param($st, 'ssii', $landTitle, $landDescription, $availableId, $dailyLandId);
                        if (mysqli_stmt_execute($st)) { mysqli_stmt_close($st); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1'); exit; }
                        $updateError = 'Could not update land: ' . mysqli_stmt_error($st); mysqli_stmt_close($st);
                    }
                }
            }
        }
    }
}

/* ADD A DAILY LAND AND LINK IT TO AN EXISTING AVAILABLE LAND */
$addMessage = '';
$addError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_land') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $addError = 'Security check failed. Refresh the page and try again.';
    } else {
        $area = trim((string)($_POST['area'] ?? ''));
        $landTitle = trim((string)($_POST['land_title'] ?? ''));
        $landDescription = trim((string)($_POST['land_description'] ?? ''));
        $availableId = (int)($_POST['available_lands_id'] ?? 0);
        if ($area === '' || $landTitle === '') {
            $addError = 'Please enter both Area and Land Title.';
        } else {
            if ($availableId > 0) {
                $check = mysqli_prepare($con, "SELECT id FROM `available_lands` WHERE id=? LIMIT 1");
                mysqli_stmt_bind_param($check, 'i', $availableId);
                mysqli_stmt_execute($check);
                $exists = mysqli_stmt_get_result($check);
                $validLink = mysqli_fetch_assoc($exists);
                mysqli_stmt_close($check);
                if (!$validLink) $addError = 'The selected available land record was not found. Please choose it again.';
            }
            if ($addError === '') {
                $maxArea = mysqli_prepare($con, "SELECT COALESCE(MAX(area_priority),0)+1 AS next_priority FROM `daily_lands` WHERE `area`=?");
                mysqli_stmt_bind_param($maxArea, 's', $area);
                mysqli_stmt_execute($maxArea);
                $maxRes = mysqli_stmt_get_result($maxArea);
                $areaPriority = (int)(mysqli_fetch_assoc($maxRes)['next_priority'] ?? 1);
                mysqli_stmt_close($maxArea);
                $landPriority = 0;
                $st = mysqli_prepare($con, "INSERT INTO `daily_lands` (`area`,`land_title`,`land description`,`available_lands_id`,`area_priority`,`land_priority`,`show_land`) VALUES (?,?,?,?,?,?,1)");
                if (!$st) {
                    $addError = 'Could not prepare the new land record: ' . mysqli_error($con);
                } else {
                    mysqli_stmt_bind_param($st, 'sssiii', $area, $landTitle, $landDescription, $availableId, $areaPriority, $landPriority);
                    if (mysqli_stmt_execute($st)) {
                        mysqli_stmt_close($st);
                        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?added=1');
                        exit;
                    }
                    $addError = 'Could not add land: ' . mysqli_stmt_error($st);
                    mysqli_stmt_close($st);
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_all_order') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Security check failed']); exit;
    }
    $ids = json_decode((string)($_POST['order'] ?? ''), true);
    if (!is_array($ids) || array_values($ids) !== $ids) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Invalid order']); exit; }
    $ids = array_map('intval', $ids);
    if (count($ids) !== count(array_unique($ids)) || in_array(0, $ids, true)) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Invalid or duplicate land IDs']); exit; }
    $existingIds = [];
    $existingRes = mysqli_query($con, "SELECT `id` FROM `daily_lands` WHERE COALESCE(`show_land`,0)=1 ORDER BY `id`");
    if (!$existingRes) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>mysqli_error($con)]); exit; }
    while ($existingRow = mysqli_fetch_assoc($existingRes)) $existingIds[] = (int)$existingRow['id'];
    $sortedIncoming = $ids; $sortedExisting = $existingIds;
    sort($sortedIncoming); sort($sortedExisting);
    if ($sortedIncoming !== $sortedExisting) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'The land list changed. Refresh the page and try again.']); exit; }
    $stmt = mysqli_prepare($con, "UPDATE `daily_lands` SET `all_priority`=? WHERE `id`=?");
    if (!$stmt) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>mysqli_error($con)]); exit; }
    mysqli_begin_transaction($con);
    try {
        foreach ($ids as $i=>$id) {
            $rank = $i + 1; $id = (int)$id;
            if ($id <= 0) throw new Exception('Invalid land ID');
            mysqli_stmt_bind_param($stmt,'ii',$rank,$id);
            if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
        }
        mysqli_commit($con);
        echo json_encode(['ok'=>true]);
    } catch(Throwable $ex) {
        mysqli_rollback($con); http_response_code(500);
        echo json_encode(['ok'=>false,'message'=>$ex->getMessage()]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_order') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Security check failed. Refresh the page and try again.']); exit; }
    $payload = json_decode((string)($_POST['order'] ?? ''), true);
    if (!is_array($payload) || !isset($payload['areas']) || !is_array($payload['areas'])) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Invalid order data.']); exit; }
    mysqli_begin_transaction($con);
    try {
        $areaStmt = mysqli_prepare($con, "UPDATE `daily_lands` SET `area_priority`=? WHERE `area`=?");
        $landStmt = mysqli_prepare($con, "UPDATE `daily_lands` SET `land_priority`=? WHERE `id`=?");
        if (!$areaStmt || !$landStmt) throw new Exception(mysqli_error($con));
        foreach ($payload['areas'] as $areaIndex => $areaItem) {
            if (!is_array($areaItem) || !isset($areaItem['name']) || !is_array($areaItem['lands'] ?? null)) continue;
            $areaName = (string)$areaItem['name']; $areaRank = (int)$areaIndex + 1;
            mysqli_stmt_bind_param($areaStmt, 'is', $areaRank, $areaName);
            if (!mysqli_stmt_execute($areaStmt)) throw new Exception(mysqli_stmt_error($areaStmt));
            foreach ($areaItem['lands'] as $landIndex => $landId) {
                $landId = (int)$landId; if ($landId <= 0) continue;
                $landRank = (int)$landIndex + 1;
                mysqli_stmt_bind_param($landStmt, 'ii', $landRank, $landId);
                if (!mysqli_stmt_execute($landStmt)) throw new Exception(mysqli_stmt_error($landStmt));
            }
        }
        mysqli_stmt_close($areaStmt); mysqli_stmt_close($landStmt);
        mysqli_commit($con); echo json_encode(['ok'=>true,'message'=>'Priority order saved.']);
    } catch (Throwable $ex) {
        mysqli_rollback($con); http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Could not save order: '.$ex->getMessage()]);
    }
    exit;
}

$availableLands = [];
$availableRes = mysqli_query($con, "SELECT `id`, `owner_details`, `land_area`, `land details`, `location`, `price`, `grade`, `confirmed`, `imp`, `stared` FROM `available_lands` ORDER BY COALESCE(`stared`,0) DESC, COALESCE(`imp`,0) DESC, COALESCE(`confirmed`,0) DESC, `id` DESC");
if ($availableRes) while ($availableRow = mysqli_fetch_assoc($availableRes)) $availableLands[] = $availableRow;
$showAddForm = isset($_GET['add']);
$editLand = null;
$editId = (int)($_GET['edit'] ?? ($_POST['daily_land_id'] ?? 0));
$showEditForm = isset($_GET['edit']) || $updateError !== '' || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_land');
if ($showEditForm && $editId > 0) {
    $editStmt = mysqli_prepare($con, "SELECT `id`, `area`, `land_title`, `land description`, `available_lands_id` FROM `daily_lands` WHERE `id`=? LIMIT 1");
    if ($editStmt) {
        mysqli_stmt_bind_param($editStmt, 'i', $editId);
        mysqli_stmt_execute($editStmt);
        $editLand = mysqli_fetch_assoc(mysqli_stmt_get_result($editStmt));
        mysqli_stmt_close($editStmt);
    }
}

$sql = "SELECT d.`id`, d.`area`, d.`land_title`, d.`land description`, d.`available_lands_id`, d.`area_priority`, d.`land_priority`, d.`all_priority`, COALESCE(d.`show_land`,0) AS show_land, a.`land details` AS linked_land_details, a.`location` AS linked_location, a.`land_area` AS linked_land_area, a.`price` AS linked_price FROM `daily_lands` d LEFT JOIN `available_lands` a ON a.`id` = d.`available_lands_id` ORDER BY CASE WHEN d.`area_priority` > 0 THEN 0 ELSE 1 END, d.`area_priority`, d.`area`, CASE WHEN d.`land_priority` > 0 THEN 0 ELSE 1 END, d.`land_priority`, d.`id`";
$res = mysqli_query($con, $sql);
if (!$res) die('Could not load daily lands. Check the column names in daily_lands and available_lands. Details: ' . e(mysqli_error($con)));
$areas = [];
$allDailyLands = [];
$hiddenLands = [];
while ($r = mysqli_fetch_assoc($res)) {
    $allDailyLands[] = $r;
    if ((int)$r['show_land'] !== 1) {
        $hiddenLands[] = $r;
        continue;
    }
    $areaName = trim((string)$r['area']); if ($areaName === '') $areaName = 'Unspecified Area';
    if (!isset($areas[$areaName])) $areas[$areaName] = [];
    $areas[$areaName][] = $r;
}
$allLands = [];
foreach ($areas as $group) foreach ($group as $land) $allLands[] = $land;
usort($allLands, static function($a,$b) {
    $ap=(int)$a['all_priority']; $bp=(int)$b['all_priority'];
    if ($ap === 0 && $bp !== 0) return 1;
    if ($bp === 0 && $ap !== 0) return -1;
    return ($ap <=> $bp) ?: ((int)$a['id'] <=> (int)$b['id']);
});
$areaList = [];
foreach ($areas as $name => $lands) $areaList[] = ['name'=>$name, 'lands'=>$lands];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>The Divine Lands | Daily Land Priorities</title>
<style>
:root{--navy:#071d34;--navy2:#103b5d;--gold:#e4b45b;--ink:#183047;--muted:#74879a;--line:#dfe7ee;--bg:#f3f6f9;--green:#16834a}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 0 0,#e4b45b12,transparent 25%),var(--bg);font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--ink)}.page{width:min(1180px,calc(100% - 28px));margin:auto;padding:22px 0 50px}.hero{position:relative;overflow:hidden;padding:28px 30px;border-radius:22px;background:linear-gradient(125deg,#04172d,#0a2c4b 60%,#164b70);color:#fff;box-shadow:0 18px 45px #051d3330}.eyebrow{font-size:10px;letter-spacing:2.2px;font-weight:900;color:var(--gold);text-transform:uppercase}.hero h1{margin:8px 0 7px;font-size:clamp(25px,4vw,36px);letter-spacing:-.7px}.hero p{margin:0;color:#b8c9d8;font-size:13px;line-height:1.6;max-width:700px}.hero-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:20px}.btn{border:0;border-radius:9px;padding:11px 15px;font-weight:850;font-size:11px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-gold{background:var(--gold);color:#14283d}.btn-light{background:#ffffff13;border:1px solid #ffffff30;color:#fff}.summary{display:flex;gap:10px;flex-wrap:wrap;margin:17px 0}.stat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 16px;min-width:130px;box-shadow:0 5px 16px #18304708}.stat strong{display:block;font-size:21px}.stat span{font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.7px}.hint{padding:12px 14px;background:#fffaf0;border:1px solid #efdfb7;border-radius:10px;color:#765b25;font-size:11px;margin-bottom:15px}.all-board{background:#fff;border:1px solid var(--line);border-radius:17px;padding:14px;margin:16px 0 0;box-shadow:0 8px 24px #18304708}.all-board-head{display:flex;align-items:center;gap:12px;padding:5px 4px 14px;margin-bottom:14px;border-bottom:1px solid #e8eef3}.all-badge{display:grid;place-items:center;min-width:48px;height:42px;padding:0 10px;border-radius:10px;background:#102d49;color:var(--gold);font-size:12px;font-weight:950;letter-spacing:.5px}.all-board-copy{min-width:0;flex:1}.all-board-copy h2{margin:0 0 4px;font-size:17px;color:#102d49}.all-board-copy p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.areas{display:flex;flex-direction:column;gap:14px}.area-card{background:#fff;border:1px solid var(--line);border-radius:15px;overflow:hidden;box-shadow:0 6px 20px #18304708}.area-card.dragging,.land-row.dragging{opacity:.45}.area-card.drop-target,.land-row.drop-target{outline:2px dashed var(--gold);outline-offset:2px}.area-head{display:flex;align-items:center;gap:12px;padding:15px 17px;background:linear-gradient(90deg,#f8fafc,#fff);border-bottom:1px solid #edf1f4;cursor:grab}.handle{width:32px;height:34px;flex:0 0 32px;border:1px solid #dce5ec;border-radius:8px;display:grid;place-items:center;color:#6d8295;font-size:19px;background:#fff}.area-title{min-width:0;flex:1}.area-title h2{font-size:17px;margin:0 0 3px;overflow-wrap:anywhere}.area-title small{font-size:10px;color:var(--muted);font-weight:750}.rank{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#102d49;color:var(--gold);font-weight:900;font-size:12px}.land-list{padding:10px;display:flex;flex-direction:column;gap:8px;min-height:26px}.land-row{display:flex;align-items:center;gap:11px;padding:12px;border:1px solid #e3eaf0;border-radius:11px;background:#fff;cursor:grab;transition:box-shadow .15s,border-color .15s}.land-row:hover{border-color:#c5d4e0;box-shadow:0 5px 14px #1830470c}.land-handle{color:#94a3b0;font-size:18px;flex:0 0 18px}.land-rank{flex:0 0 27px;height:27px;display:grid;place-items:center;border-radius:8px;background:#eef4f8;color:#355b78;font-size:10px;font-weight:900}.land-main{min-width:0;flex:1}.land-title{font-size:13px;font-weight:900;overflow-wrap:anywhere}.land-desc{font-size:11px;color:#6d8091;margin-top:4px;line-height:1.5;overflow-wrap:anywhere}.linked{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.pill{font-size:9px;font-weight:800;color:#587086;background:#f4f7f9;border:1px solid #dfe7ed;border-radius:6px;padding:4px 6px}.open{white-space:nowrap;background:#eaf4fb;color:#1e638e;text-decoration:none;padding:9px 11px;border-radius:8px;font-size:10px;font-weight:900}.open:hover{background:#dceef9}.land-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.remove-btn{white-space:nowrap;background:#fff1f2;color:#a52e38;border:1px solid #f0cbd0;padding:9px 10px;border-radius:8px;font-size:10px;font-weight:900;cursor:pointer}.remove-btn:hover{background:#ffe1e4}.show-toggle{white-space:nowrap;border:1px solid #cfe6d6;background:#edf9f1;color:#167543;padding:9px 10px;border-radius:8px;font-size:10px;font-weight:900;cursor:pointer}.show-toggle.off{border-color:#efc8cc;background:#fff1f2;color:#a52e38}.show-toggle:hover{filter:brightness(.97)}.hidden-board{margin:18px 0;background:#fff;border:1px solid #e8d7a8;border-radius:15px;padding:14px}.hidden-board h2{margin:0 0 5px;font-size:16px;color:#765b25}.hidden-board p{margin:0 0 12px;font-size:11px;color:#8b7445;line-height:1.5}.empty{padding:40px 20px;text-align:center;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:14px}.status{position:fixed;right:18px;bottom:18px;z-index:10;background:#102d49;color:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 10px 30px #0002;font-size:12px;font-weight:800;opacity:0;transform:translateY(8px);pointer-events:none;transition:.2s}.status.show{opacity:1;transform:translateY(0)}.status.error{background:#9f2934}.notice{margin:12px 0;padding:12px 14px;border-radius:10px;font-size:12px;font-weight:800}.notice.success{background:#edf9f1;border:1px solid #c7e9d1;color:#167543}.notice.error{background:#fff1f2;border:1px solid #efc8cc;color:#a52e38}.add-card{margin:16px 0;padding:20px;background:#fff;border:1px solid var(--line);border-radius:15px;box-shadow:0 8px 24px #1830470b}.add-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:17px}.add-heading h2{font-size:18px;margin:0 0 5px}.add-heading p{font-size:11px;color:var(--muted);margin:0;line-height:1.5}.close-add{font-size:11px;font-weight:850;color:#6d8091;text-decoration:none;white-space:nowrap}.add-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.add-grid label{display:block;min-width:0;font-size:10px;font-weight:900;letter-spacing:.5px;color:#536b80}.add-grid input,.add-grid select,.add-grid textarea{display:block;width:100%;margin-top:7px;padding:11px;border:1px solid #d7e0e8;border-radius:8px;background:#fafcfd;color:#183047;font:12px Inter,"Segoe UI",Arial,sans-serif;outline:none}.add-grid input:focus,.add-grid select:focus,.add-grid textarea:focus{border-color:#4b8cbd;box-shadow:0 0 0 3px #347cad14}.add-grid .wide{grid-column:1/-1}.add-grid small{display:block;margin-top:7px;color:#8393a1;font-size:10px;line-height:1.5;font-weight:500;letter-spacing:0}.add-actions{display:flex;gap:8px}.btn-light-dark{background:#f2f5f7;border:1px solid #dce5ec;color:#536b80}@media(max-width:600px){.add-grid{grid-template-columns:1fr}.add-grid .wide{grid-column:auto}.add-actions{flex-direction:column}.add-actions .btn{width:100%}}
@media(max-width:600px){.page{width:calc(100% - 16px);padding-top:9px}.hero{padding:22px 18px}.area-head{padding:12px}.land-row{align-items:flex-start;flex-wrap:wrap;padding:10px}.open{margin-left:56px}.land-main{flex-basis:calc(100% - 80px)}.stat{flex:1;min-width:95px}.stat strong{font-size:18px}}
</style>
</head>
<body>
<div class="page">
<header class="hero"><div class="eyebrow">The Divine Lands · Sales Focus</div><h1>Daily Land Priorities</h1><p>Arrange areas and lands by dragging them. Every completed drag is saved automatically to the database.</p><div class="hero-actions"><span class="pill">✓ Auto-save enabled</span><a class="btn btn-light" href="index.php">ALL</a></div></header>
<div class="hero-actions" style="margin-top:10px"><a class="btn btn-gold" href="?add=1#addLandForm">＋ Add Land to Priority Board</a><a class="btn btn-light" href="available_lands.php?add=1" target="_blank" rel="noopener noreferrer">＋ Create Available Land Record</a></div>
<?php if (isset($_GET['added'])): ?><div class="notice success">✓ Land added to your priority board.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="notice success">✓ Land entry updated successfully.</div><?php endif; ?>
<?php if (isset($_GET['shown'])): ?><div class="notice success">✓ Land is ON and visible in ALL AREA and its own area.</div><?php endif; ?>
<?php if (isset($_GET['hidden'])): ?><div class="notice success">✓ Land is OFF and hidden from ALL AREA and its own area. You can turn it ON in Hidden / Off Lands below.</div><?php endif; ?>
<?php if ($toggleError !== ''): ?><div class="notice error"><?= e($toggleError) ?></div><?php endif; ?>
<?php if (isset($_GET['removed'])): ?><div class="notice success">✓ Land removed from the priority board. The original available land record was not deleted.</div><?php endif; ?>
<?php if ($removeError !== ''): ?><div class="notice error"><?= e($removeError) ?></div><?php endif; ?>
<?php if ($addError !== ''): ?><div class="notice error"><?= e($addError) ?></div><?php endif; ?>
<?php if ($updateError !== ''): ?><div class="notice error"><?= e($updateError) ?></div><?php endif; ?>
<?php if ($showAddForm || $addError !== '' || $showEditForm): ?>
<section class="add-card" id="addLandForm">
  <div class="add-heading"><div><h2><?= $showEditForm ? 'Update Land Entry' : 'Add Land to Priority Board' ?></h2><p><?= $showEditForm ? 'Update the area, title, description, or linked available land record.' : 'Create an area/land entry and link it to an existing record in available_lands.' ?></p></div><a href="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" class="close-add">Close ×</a></div>
  <form method="post" class="add-grid" action="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?><?= $showEditForm ? '' : '?add=1' ?>#addLandForm">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="<?= $showEditForm ? 'update_land' : 'add_land' ?>"><?= $showEditForm ? '<input type="hidden" name="daily_land_id" value="'.(int)$editId.'">' : '' ?>
    <label>Area name<input name="area" required placeholder="e.g. Naroda" value="<?= e($_POST['area'] ?? ($editLand['area'] ?? '')) ?>" list="areaSuggestions"></label>
    <datalist id="areaSuggestions"><?php foreach (array_keys($areas) as $existingArea): ?><option value="<?= e($existingArea) ?>"><?php endforeach; ?></datalist>
    <label>Land title<input name="land_title" required placeholder="e.g. Near Hanspura" value="<?= e($_POST['land_title'] ?? ($editLand['land_title'] ?? '')) ?>"></label>
    <label class="wide">Land description<textarea name="land_description" rows="3" placeholder="Optional notes about this land"><?= e($_POST['land_description'] ?? ($editLand['land description'] ?? '')) ?></textarea></label>
    <label class="wide">Connect to an existing available land record
      <input id="availableSearch" type="search" placeholder="Search owner, location, land details, price or ID…" autocomplete="off">
      <select name="available_lands_id" id="availableSelect">
        <option value="0">— No link yet —</option>
        <?php foreach ($availableLands as $al): $aid=(int)$al['id']; $searchText='ID '.$aid.' '.($al['owner_details']??'').' '.($al['land_area']??'').' '.($al['land details']??'').' '.($al['location']??'').' '.($al['price']??'').' '.($al['grade']??''); ?>
        <option value="<?= $aid ?>" data-search="<?= e(mb_strtolower($searchText, 'UTF-8')) ?>" <?= (int)($_POST['available_lands_id'] ?? ($editLand['available_lands_id'] ?? 0))===$aid?'selected':'' ?>><?= e('#'.$aid.' · '.(trim((string)$al['owner_details']) ?: 'Land record').' · '.(trim((string)$al['land_area']) ?: 'Area n/a').' · '.(trim((string)$al['location']) ?: 'Location n/a').' · '.(trim((string)$al['price']) ?: 'Price n/a')) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Choose a record to link this board entry to the actual land inventory. The record itself will not be duplicated or changed.</small>
    </label>
    <div class="wide add-actions"><button class="btn btn-gold" type="submit"><?= $showEditForm ? 'Update Land' : 'Save Land' ?></button><a class="btn btn-light-dark" href="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Cancel</a></div>
  </form>
</section>
<?php endif; ?>
<div class="summary"><div class="stat"><strong><?= count($areaList) ?></strong><span>Areas</span></div><div class="stat"><strong><?= count($allLands) ?></strong><span>Shown Lands</span></div><div class="stat"><strong><?= count($hiddenLands) ?></strong><span>Hidden / Off</span></div><div class="stat"><strong>Drag &amp; Drop</strong><span>Set Your Focus</span></div></div>
<div class="hint">Tip: drag an area by its header to change area priority. Drag a land row to change priority within that same area. Click <b>Open Land Record</b> to open the linked entry in <code>available_lands.php</code> in a new tab.</div>
<?php if (!$areaList): ?><div class="empty"><h3>No lands are currently ON</h3><p>Turn a land ON in Hidden / Off Lands below, or add a new land to display it in ALL AREA and its own area.</p></div><?php else: ?>
<section class="all-board">
  <div class="all-board-head"><div class="all-badge">ALL</div><div class="all-board-copy"><h2>ALL AREA — Every Land</h2><p>Every land appears here and again in its own area below. This list has its own independent order and saves automatically.</p></div></div>
  <div class="land-list" id="allLandsList">
  <?php foreach ($allLands as $i => $land): $linkedId=(int)($land['available_lands_id'] ?? 0); ?>
    <article class="land-row" draggable="true" data-land-id="<?= (int)$land['id'] ?>">
      <span class="land-handle" title="Drag to reorder in ALL">⠿</span>
      <span class="land-rank"><?= $i+1 ?></span>
      <div class="land-main">
        <div class="land-title"><?= e($land['land_title'] ?: 'Untitled Land') ?></div>
        <div class="linked"><span class="pill">Area: <?= e($land['area'] ?: 'Unspecified Area') ?></span>
        <?php if($linkedId): ?><span class="pill">Linked land ID: <?= $linkedId ?></span><?php endif; ?></div>
        <?php if(trim((string)$land['land description'])!==''): ?><div class="land-desc"><?= nl2br(e($land['land description'])) ?></div><?php endif; ?>
      </div>
      <div class="land-actions">
        <a class="open" href="?edit=<?= (int)$land['id'] ?>#addLandForm">Edit Entry ✎</a>
        <?php if($linkedId): ?><a class="open" href="available_lands.php?edit=<?= $linkedId ?>" target="_blank" rel="noopener noreferrer">Open Land Record ↗</a><?php endif; ?>
        <form method="post" action="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle_show"><input type="hidden" name="daily_land_id" value="<?= (int)$land['id'] ?>"><input type="hidden" name="new_show_land" value="0"><button class="show-toggle off" type="submit" draggable="false">● Turn OFF</button></form>
        <form method="post" action="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" onsubmit="return confirm('Remove this land from the Daily Land Priority Board? It will disappear from ALL and its area, but the original available_lands record will remain.');" style="margin:0">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="remove_land">
          <input type="hidden" name="daily_land_id" value="<?= (int)$land['id'] ?>">
          <button class="remove-btn" type="submit" draggable="false">Remove ×</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<div class="hint"><b>Area-wise lands</b> — These are the same lands shown again, grouped by area. Their ordering is separate from ALL.</div>
<div class="areas" id="areas">
<?php foreach ($areaList as $ai => $area): ?>
<section class="area-card" draggable="true" data-area="<?= e($area['name']) ?>">
  <div class="area-head"><span class="handle" title="Drag to reorder area">⠿</span><div class="area-title"><h2><?= e($area['name']) ?></h2><small><?= count($area['lands']) ?> <?= count($area['lands']) === 1 ? 'land' : 'lands' ?> · Area priority</small></div><span class="rank area-rank"><?= $ai + 1 ?></span></div>
  <div class="land-list" data-land-list="<?= e($area['name']) ?>">
  <?php foreach ($area['lands'] as $li => $land): $linkedId = (int)($land['available_lands_id'] ?? 0); ?>
    <article class="land-row" draggable="true" data-land-id="<?= (int)$land['id'] ?>">
      <span class="land-handle" title="Drag to reorder land">⠿</span><span class="land-rank"><?= $li + 1 ?></span>
      <div class="land-main"><div class="land-title"><?= e($land['land_title'] ?: 'Untitled Land') ?></div>
        <?php if (trim((string)$land['land description']) !== ''): ?><div class="land-desc"><?= nl2br(e($land['land description'])) ?></div><?php endif; ?>
        <div class="linked">
          <?php if ($linkedId > 0): ?><span class="pill">Linked land ID: <?= $linkedId ?></span><?php if (!empty($land['linked_land_area'])): ?><span class="pill">Area: <?= e($land['linked_land_area']) ?></span><?php endif; ?><?php if (!empty($land['linked_price'])): ?><span class="pill">Price: <?= e($land['linked_price']) ?></span><?php endif; ?><?php if (!empty($land['linked_location'])): ?><span class="pill">Location available</span><?php endif; ?><?php else: ?><span class="pill">No available_lands record linked</span><?php endif; ?>
        </div>
      </div>
      <div class="land-actions">
        <a class="open" href="?edit=<?= (int)$land['id'] ?>#addLandForm">Edit Entry ✎</a>
        <?php if ($linkedId > 0): ?><a class="open" href="available_lands.php?edit=<?= $linkedId ?>" target="_blank" rel="noopener noreferrer">Open Land Record ↗</a><?php else: ?><span class="pill">Link missing</span><?php endif; ?>
        <form method="post" action="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle_show"><input type="hidden" name="daily_land_id" value="<?= (int)$land['id'] ?>"><input type="hidden" name="new_show_land" value="0"><button class="show-toggle off" type="submit" draggable="false">● Turn OFF</button></form>
        <form method="post" action="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" onsubmit="return confirm('Remove this land from the Daily Land Priority Board? The original record in available_lands will NOT be deleted.');" style="margin:0">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="remove_land">
          <input type="hidden" name="daily_land_id" value="<?= (int)$land['id'] ?>">
          <button class="remove-btn" type="submit" draggable="false">Remove ×</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($hiddenLands): ?>
<section class="hidden-board">
  <h2>Hidden / Off Lands (<?= count($hiddenLands) ?>)</h2>
  <p>These records are still saved in <code>daily_lands</code>, but are hidden from ALL AREA and their own area. Turn them ON to show them again.</p>
  <div class="land-list">
  <?php foreach ($hiddenLands as $land): $linkedId=(int)($land['available_lands_id'] ?? 0); ?>
    <article class="land-row" draggable="false">
      <span class="land-rank">OFF</span>
      <div class="land-main"><div class="land-title"><?= e($land['land_title'] ?: 'Untitled Land') ?></div><div class="linked"><span class="pill">Area: <?= e($land['area'] ?: 'Unspecified Area') ?></span><span class="pill">Not displayed in priority lists</span></div><?php if(trim((string)$land['land description'])!==''): ?><div class="land-desc"><?= nl2br(e($land['land description'])) ?></div><?php endif; ?></div>
      <div class="land-actions">
        <form method="post" action="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle_show"><input type="hidden" name="daily_land_id" value="<?= (int)$land['id'] ?>"><input type="hidden" name="new_show_land" value="1"><button class="show-toggle" type="submit">● Turn ON</button></form>
        <a class="open" href="?edit=<?= (int)$land['id'] ?>#addLandForm">Edit Entry ✎</a>
        <?php if($linkedId): ?><a class="open" href="available_lands.php?edit=<?= $linkedId ?>" target="_blank" rel="noopener noreferrer">Open Land Record ↗</a><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<div class="status" id="status" role="status" aria-live="polite"></div>
</div>
<script>
const csrf = <?= json_encode($csrf) ?>;
const areaContainer = document.getElementById('areas');
const statusBox = document.getElementById('status');
let draggedArea = null, draggedLand = null, statusTimer = null;
let isSavingOrder = false, saveAgain = false;
function showStatus(message, error=false){
  if(!statusBox)return;
  statusBox.textContent=message;
  statusBox.classList.toggle('error',error);
  statusBox.classList.add('show');
  clearTimeout(statusTimer);
  statusTimer=setTimeout(()=>statusBox.classList.remove('show'),2800);
}
function refreshRanks(){
  if(!areaContainer)return;
  [...areaContainer.querySelectorAll(':scope > .area-card')].forEach((card,i)=>{const r=card.querySelector('.area-rank');if(r)r.textContent=i+1;});
  areaContainer.querySelectorAll('.land-list').forEach(list=>[...list.querySelectorAll('.land-row')].forEach((row,i)=>{const r=row.querySelector('.land-rank');if(r)r.textContent=i+1;}));
}
async function saveOrderAutomatically(){
  if(!areaContainer)return;
  if(isSavingOrder){saveAgain=true;return;}
  isSavingOrder=true;
  do {
    saveAgain=false;
    const areas=[...areaContainer.querySelectorAll(':scope > .area-card')].map(card=>({name:card.dataset.area,lands:[...card.querySelectorAll('.land-list > .land-row')].map(row=>Number(row.dataset.landId))}));
    const form=new FormData();
    form.append('action','save_order');
    form.append('csrf',csrf);
    form.append('order',JSON.stringify({areas}));
    showStatus('Saving new priority order…');
    try{
      const response=await fetch(location.pathname+location.search,{method:'POST',body:form,credentials:'same-origin'});
      const result=await response.json();
      if(!response.ok||!result.ok)throw new Error(result.message||'Could not save order.');
      if(!saveAgain)showStatus('✓ Priority order saved automatically');
    }catch(err){
      showStatus((err.message||'Auto-save failed. Please try dragging again.'),true);
    }
  } while(saveAgain);
  isSavingOrder=false;
}
if(areaContainer){
  areaContainer.addEventListener('dragstart',e=>{
    const land=e.target.closest('.land-row'); const area=e.target.closest('.area-card');
    if(land){draggedLand=land;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',land.dataset.landId);setTimeout(()=>land.classList.add('dragging'),0);}
    else if(area){draggedArea=area;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',area.dataset.area);setTimeout(()=>area.classList.add('dragging'),0);}
  });
  areaContainer.addEventListener('dragover',e=>{
    if(draggedLand){const list=e.target.closest('.land-list');if(!list || !draggedLand.parentElement || list!==draggedLand.parentElement)return;e.preventDefault();const after=getAfter(list,e.clientY,'.land-row');if(after)list.insertBefore(draggedLand,after);else list.appendChild(draggedLand);}
    else if(draggedArea){const target=e.target.closest('.area-card');if(!target || target===draggedArea)return;e.preventDefault();const rect=target.getBoundingClientRect();const after=e.clientY>rect.top+rect.height/2;areaContainer.insertBefore(draggedArea,after?target.nextSibling:target);}
  });
  areaContainer.addEventListener('drop',e=>{if(draggedArea||draggedLand)e.preventDefault();refreshRanks();});
  areaContainer.addEventListener('dragend',()=>{
    const didDrag=!!(draggedArea||draggedLand);
    document.querySelectorAll('.dragging').forEach(el=>el.classList.remove('dragging'));
    draggedArea=null;draggedLand=null;refreshRanks();
    if(didDrag)saveOrderAutomatically();
  });
}
const allLandsList = document.getElementById('allLandsList');
let draggedAllLand = null, allSaving = false, allSaveAgain = false;
async function saveAllOrder(){
  if(!allLandsList)return;
  if(allSaving){allSaveAgain=true;return;}
  allSaving=true;
  do{
    allSaveAgain=false;
    const ids=[...allLandsList.querySelectorAll(':scope > .land-row')].map(row=>Number(row.dataset.landId));
    const data=new FormData();
    data.append('action','save_all_order');
    data.append('csrf',csrf);
    data.append('order',JSON.stringify(ids));
    showStatus('Saving ALL land priorities…');
    try {
      const response=await fetch(location.pathname+location.search,{method:'POST',body:data,credentials:'same-origin'});
      const result=await response.json();
      if(!response.ok||!result.ok)throw new Error(result.message||'Could not save ALL order');
      if(!allSaveAgain)showStatus('✓ ALL priorities saved automatically');
    }catch(err){showStatus(err.message||'ALL auto-save failed',true);}
  }while(allSaveAgain);
  allSaving=false;
}
if(allLandsList){
  allLandsList.addEventListener('dragstart',e=>{
    const row=e.target.closest('.land-row');
    if(!row)return;
    draggedAllLand=row;
    e.dataTransfer.effectAllowed='move';
    e.dataTransfer.setData('text/plain',row.dataset.landId);
    setTimeout(()=>row.classList.add('dragging'),0);
  });
  allLandsList.addEventListener('dragover',e=>{
    if(!draggedAllLand)return;
    e.preventDefault();
    const after=getAfter(allLandsList,e.clientY,'.land-row');
    if(after)allLandsList.insertBefore(draggedAllLand,after);
    else allLandsList.appendChild(draggedAllLand);
  });
  allLandsList.addEventListener('drop',e=>{if(draggedAllLand)e.preventDefault();});
  allLandsList.addEventListener('dragend',()=>{
    if(!draggedAllLand)return;
    draggedAllLand.classList.remove('dragging');
    draggedAllLand=null;
    [...allLandsList.querySelectorAll(':scope > .land-row')].forEach((row,i)=>{
      const rank=row.querySelector('.land-rank');
      if(rank)rank.textContent=i+1;
    });
    saveAllOrder();
  });
}
function getAfter(container,y,selector){const els=[...container.querySelectorAll(selector+':not(.dragging)')];return els.reduce((closest,child)=>{const box=child.getBoundingClientRect();const offset=y-box.top-box.height/2;if(offset<0&&offset>closest.offset)return{offset,element:child};return closest;},{offset:Number.NEGATIVE_INFINITY,element:null}).element;}
const availableSearch=document.getElementById('availableSearch');
const availableSelect=document.getElementById('availableSelect');
if(availableSearch && availableSelect){
  const allAvailableOptions=[...availableSelect.options].slice(1).map(o=>({value:o.value,text:o.text,search:o.dataset.search||o.text.toLowerCase()}));
  availableSearch.addEventListener('input',()=>{
    const q=availableSearch.value.trim().toLowerCase();
    const selectedValue=availableSelect.value;
    availableSelect.innerHTML='';
    const none=document.createElement('option');none.value='0';none.textContent='— No link yet —';availableSelect.appendChild(none);
    allAvailableOptions.filter(o=>!q||o.search.includes(q)).forEach(o=>{const option=document.createElement('option');option.value=o.value;option.textContent=o.text;if(o.value===selectedValue)option.selected=true;availableSelect.appendChild(option);});
  });
}
refreshRanks();
</script>
</body>
</html>
