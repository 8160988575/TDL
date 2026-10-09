<?php
/* =========================================================
   FAST OUTPUT - compress HTML/CSS/JS when the server supports it.
   This does not change any application functionality.
   ========================================================= */
if (!headers_sent() && function_exists('ob_gzhandler')) {
    ob_start('ob_gzhandler');
}

/* =========================================================
   THE DIVINE LANDS
   GROUP MANAGEMENT / GROUP DIRECTORY
   CORE PHP + MYSQL ONLY
   ========================================================= */


/* =========================================================
   DATABASE CONNECTION
   ========================================================= */

$con = @mysqli_connect(
    'localhost',
    'root',
    '',
    'tdl'
);

if (!$con) {
    die(
        'Database connection failed: ' .
        mysqli_connect_error()
    );
}

mysqli_set_charset($con, 'utf8mb4');

/* Add/update the current Bulk WhatsApp contact in Client Handling. */
if (isset($_GET['client_campaign_action']) && $_GET['client_campaign_action'] === 'add') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST request required.']); exit;
    }
    $cid = (int)($_POST['cid'] ?? 0);
    $status = trim((string)($_POST['status'] ?? 'New'));
    $validStatuses = ['New','Contacted','Interested','Requirement Received','Property Shared','Site Visit Planned','Site Visit Done','Negotiation','Follow-up','On Hold','Converted','Not Interested','Closed'];
    if ($cid < 1 || !in_array($status, $validStatuses, true)) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Please choose a valid client and status.']); exit;
    }
    $check = mysqli_prepare($con, 'SELECT id FROM data WHERE id=? LIMIT 1');
    if (!$check) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_error($con)]); exit; }
    mysqli_stmt_bind_param($check, 'i', $cid); mysqli_stmt_execute($check);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($check)); mysqli_stmt_close($check);
    if (!$exists) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Contact not found in the client base.']); exit; }

    $check = mysqli_prepare($con, 'SELECT id FROM client_updates WHERE cid=? LIMIT 1');
    if (!$check) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_error($con)]); exit; }
    mysqli_stmt_bind_param($check, 'i', $cid); mysqli_stmt_execute($check);
    $active = mysqli_fetch_assoc(mysqli_stmt_get_result($check)); mysqli_stmt_close($check);
    if ($active) {
        $update = mysqli_prepare($con, 'UPDATE client_updates SET status=? WHERE cid=?');
        if (!$update) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_error($con)]); exit; }
        mysqli_stmt_bind_param($update, 'si', $status, $cid); $ok = mysqli_stmt_execute($update); $err = mysqli_stmt_error($update); mysqli_stmt_close($update);
        if (!$ok) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$err]); exit; }
        echo json_encode(['ok'=>true,'message'=>'Client already existed; status updated to '.$status.'.','updated'=>true]); exit;
    }
    $notes = ''; $serious = 0; $openClient = 0;
    $insert = mysqli_prepare($con, 'INSERT INTO client_updates (cid,status,notes,serious,open_client) VALUES (?,?,?,?,?)');
    if (!$insert) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_error($con)]); exit; }
    mysqli_stmt_bind_param($insert, 'issii', $cid, $status, $notes, $serious, $openClient);
    $ok = mysqli_stmt_execute($insert); $err = mysqli_stmt_error($insert); mysqli_stmt_close($insert);
    if (!$ok) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$err]); exit; }
    echo json_encode(['ok'=>true,'message'=>'Added to Client Handling with status: '.$status.'.','updated'=>false]); exit;
}

/* =========================================================
   SINGLE-FILE AJAX DETAILS ENDPOINT
   The same PHP file serves the page and on-demand group details.
   ========================================================= */
if (isset($_GET['ajax_group'])) {
    header('Content-Type: application/json; charset=utf-8');

    $gid = (int)($_GET['ajax_group'] ?? 0);
    if ($gid <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Invalid group ID']);
        exit;
    }

    $stmt = mysqli_prepare($con, "SELECT id, grp_id, company_name, scheme_name, name, number1, number2, number3, relation1, relation2, relation_all, `main`, area FROM data WHERE grp_id = ? ORDER BY id ASC");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>mysqli_error($con)]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, 'i', $gid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $persons = [];
    $group = ['grp_id'=>$gid,'company_name'=>'','scheme_name'=>'','area'=>''];
    $relations = [];
    $mainPersons = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if ($group['company_name'] === '') $group['company_name'] = $row['company_name'] ?? '';
        elseif (strcmp((string)($row['company_name'] ?? ''), (string)$group['company_name']) > 0) $group['company_name'] = $row['company_name'] ?? '';

        if ($group['scheme_name'] === '') $group['scheme_name'] = $row['scheme_name'] ?? '';
        elseif (strcmp((string)($row['scheme_name'] ?? ''), (string)$group['scheme_name']) > 0) $group['scheme_name'] = $row['scheme_name'] ?? '';

        if ($group['area'] === '') $group['area'] = $row['area'] ?? '';
        elseif (strcmp((string)($row['area'] ?? ''), (string)$group['area']) > 0) $group['area'] = $row['area'] ?? '';

        $persons[] = $row;

        $rel = trim((string)($row['relation_all'] ?? ''));
        if ($rel !== '') {
            foreach (preg_split('/\s*,\s*/', $rel) as $r) {
                $r = trim($r);
                if ($r !== '' && !in_array($r, $relations, true)) $relations[] = $r;
            }
        }

        if (trim((string)($row['main'] ?? '')) === 'main') {
            $mn = trim((string)($row['name'] ?? ''));
            if ($mn !== '' && !in_array($mn, $mainPersons, true)) $mainPersons[] = $mn;
        }
    }
    mysqli_stmt_close($stmt);

    $star = 0;
    $st = mysqli_prepare($con, "SELECT MAX(CASE WHEN LOWER(TRIM(COALESCE(star,''))) IN ('1','star','yes','true') THEN 1 ELSE 0 END) AS is_starred FROM area WHERE grp_id = ?");
    if ($st) {
        mysqli_stmt_bind_param($st, 'i', $gid);
        mysqli_stmt_execute($st);
        $sr = mysqli_stmt_get_result($st);
        if ($x = mysqli_fetch_assoc($sr)) $star = (int)($x['is_starred'] ?? 0);
        mysqli_stmt_close($st);
    }

    echo json_encode([
        'ok'=>true,
        'group'=>$group,
        'persons'=>$persons,
        'relations'=>$relations,
        'main_persons'=>$mainPersons,
        'is_starred'=>$star
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}



/* =========================================================
   SAVED MEMBER GROUPS - DATABASE AJAX ENDPOINT
   Uses the permanent member_groups table only.
   Existing group/person functionality is untouched.
   ========================================================= */
if (isset($_GET['member_group_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = trim((string)($_GET['member_group_action'] ?? ''));

    function tdlMemberGroupResponse($payload, $status = 200) {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    function tdlMemberGroupIdsToString($ids) {
        if (!is_array($ids)) {
            $ids = preg_split('/\s*,\s*/', (string)$ids);
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id === '' || !ctype_digit($id) || (int)$id <= 0) continue;
            $clean[(string)(int)$id] = true;
        }
        return implode(',', array_keys($clean));
    }

    function tdlMemberGroupStringToIds($members) {
        $members = trim((string)$members);
        if ($members === '') return [];
        $decoded = json_decode($members, true);
        $items = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $members);
        $ids = [];
        foreach ($items as $id) {
            $id = trim((string)$id);
            if ($id === '' || !ctype_digit($id) || (int)$id <= 0) continue;
            $ids[(string)(int)$id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    if ($action === 'list') {
        $result = mysqli_query($con, "SELECT id, group_name, members, created_at, updated_at FROM member_groups ORDER BY group_name ASC, id ASC");
        if (!$result) tdlMemberGroupResponse(['ok'=>false, 'error'=>mysqli_error($con)], 500);
        $groups = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $groups[] = [
                'id'=>(int)$row['id'],
                'group_name'=>(string)$row['group_name'],
                'member_count'=>count(tdlMemberGroupStringToIds($row['members'] ?? '')),
                'created_at'=>$row['created_at'] ?? null,
                'updated_at'=>$row['updated_at'] ?? null
            ];
        }
        tdlMemberGroupResponse(['ok'=>true, 'groups'=>$groups]);
    }

    if ($action === 'load') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) tdlMemberGroupResponse(['ok'=>false, 'error'=>'Invalid saved group ID.'], 400);
        $stmt = mysqli_prepare($con, "SELECT id, group_name, members FROM member_groups WHERE id = ? LIMIT 1");
        if (!$stmt) tdlMemberGroupResponse(['ok'=>false, 'error'=>mysqli_error($con)], 500);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if (!$row) tdlMemberGroupResponse(['ok'=>false, 'error'=>'Saved group not found.'], 404);
        tdlMemberGroupResponse(['ok'=>true, 'group'=>[
            'id'=>(int)$row['id'],
            'group_name'=>(string)$row['group_name'],
            'members'=>tdlMemberGroupStringToIds($row['members'] ?? '')
        ]]);
    }

    if ($action === 'create' || $action === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') tdlMemberGroupResponse(['ok'=>false, 'error'=>'POST request required.'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['group_name'] ?? ''));
        $members = tdlMemberGroupIdsToString($_POST['members'] ?? '');

        if ($members === '') tdlMemberGroupResponse(['ok'=>false, 'error'=>'Please select at least one person.'], 400);

        if ($action === 'create') {
            if ($name === '') tdlMemberGroupResponse(['ok'=>false, 'error'=>'Group name is required.'], 400);
            $check = mysqli_prepare($con, "SELECT id FROM member_groups WHERE LOWER(TRIM(group_name)) = LOWER(TRIM(?)) LIMIT 1");
            if (!$check) tdlMemberGroupResponse(['ok'=>false, 'error'=>mysqli_error($con)], 500);
            mysqli_stmt_bind_param($check, 's', $name);
            mysqli_stmt_execute($check);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
            mysqli_stmt_close($check);
            if ($existing) tdlMemberGroupResponse(['ok'=>false, 'duplicate'=>true, 'id'=>(int)$existing['id'], 'error'=>'A saved group with this name already exists.'], 409);

            $stmt = mysqli_prepare($con, "INSERT INTO member_groups (group_name, members) VALUES (?, ?)");
            if (!$stmt) tdlMemberGroupResponse(['ok'=>false, 'error'=>mysqli_error($con)], 500);
            mysqli_stmt_bind_param($stmt, 'ss', $name, $members);
            $ok = mysqli_stmt_execute($stmt);
            $newId = mysqli_insert_id($con);
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            if (!$ok) tdlMemberGroupResponse(['ok'=>false, 'error'=>$err], 500);
            tdlMemberGroupResponse(['ok'=>true, 'id'=>(int)$newId, 'group_name'=>$name, 'member_count'=>count(tdlMemberGroupStringToIds($members)), 'message'=>'Saved "'.$name.'" successfully.']);
        }

        if ($id <= 0) tdlMemberGroupResponse(['ok'=>false, 'error'=>'Please select a saved group first.'], 400);
        $stmt = mysqli_prepare($con, "UPDATE member_groups SET members = ? WHERE id = ?");
        if (!$stmt) tdlMemberGroupResponse(['ok'=>false, 'error'=>mysqli_error($con)], 500);
        mysqli_stmt_bind_param($stmt, 'si', $members, $id);
        $ok = mysqli_stmt_execute($stmt);
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) tdlMemberGroupResponse(['ok'=>false, 'error'=>$err], 500);

        $nameStmt = mysqli_prepare($con, "SELECT group_name FROM member_groups WHERE id = ? LIMIT 1");
        $savedName = '';
        if ($nameStmt) {
            mysqli_stmt_bind_param($nameStmt, 'i', $id);
            mysqli_stmt_execute($nameStmt);
            $nameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
            if ($nameRow) $savedName = (string)$nameRow['group_name'];
            mysqli_stmt_close($nameStmt);
        }
        tdlMemberGroupResponse(['ok'=>true, 'id'=>$id, 'group_name'=>$savedName, 'member_count'=>count(tdlMemberGroupStringToIds($members)), 'message'=>'Saved group updated successfully.']);
    }

    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') tdlMemberGroupResponse(['ok'=>false, 'error'=>'POST request required.'], 405);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) tdlMemberGroupResponse(['ok'=>false, 'error'=>'Invalid saved group ID.'], 400);
        $stmt = mysqli_prepare($con, "DELETE FROM member_groups WHERE id = ?");
        if (!$stmt) tdlMemberGroupResponse(['ok'=>false, 'error'=>mysqli_error($con)], 500);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $ok = mysqli_stmt_execute($stmt);
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) tdlMemberGroupResponse(['ok'=>false, 'error'=>$err], 500);
        tdlMemberGroupResponse(['ok'=>true, 'message'=>'Saved group deleted.']);
    }

    tdlMemberGroupResponse(['ok'=>false, 'error'=>'Unknown member group action.'], 400);
}


/* =========================================================
   VARIABLES
   ========================================================= */

$message = '';
$error = '';
$star_filter = $_GET['star_filter'] ?? '';
if (!in_array($star_filter, ['', 'starred', 'not_starred'], true)) {
    $star_filter = '';
}

$group_sort = $_GET['group_sort'] ?? 'desc';
if (!in_array($group_sort, ['asc', 'desc'], true)) {
    $group_sort = 'desc';
}



/* =========================================================
   ESCAPE
   ========================================================= */

function e($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   NORMALIZE NAME
   ========================================================= */

function normalizeName($name)
{
    $name = trim($name);

    $name = preg_replace(
        '/\s+/',
        ' ',
        $name
    );

    return strtoupper($name);
}


/* =========================================================
   CLEAN MOBILE
   ========================================================= */

function cleanMobile($mobile)
{
    return preg_replace(
        '/[^0-9]/',
        '',
        trim($mobile ?? '')
    );
}


/* =========================================================
   BUILD MAIN PERSONS
   ========================================================= */

function getMainPersons($con, $grp_id)
{
    $mainPersons = [];

    $sql = "
        SELECT name
        FROM data
        WHERE grp_id = ?
          AND TRIM(`main`) = 'main'
        ORDER BY id ASC
    ";

    $stmt = mysqli_prepare($con, $sql);

    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $grp_id
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {

        $name = trim(
            $row['name'] ?? ''
        );

        if (
            $name !== '' &&
            !in_array(
                $name,
                $mainPersons,
                true
            )
        ) {
            $mainPersons[] = $name;
        }
    }

    mysqli_stmt_close($stmt);

    return $mainPersons;
}


/* =========================================================
   GET ALL AREAS FOR GROUP
   ========================================================= */

function getGroupAreas($con, $grp_id)
{
    $areas = [];

    $sql = "
        SELECT area
        FROM area
        WHERE grp_id = ?
        ORDER BY id ASC
    ";

    $stmt = mysqli_prepare(
        $con,
        $sql
    );

    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $grp_id
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {

        $oneArea = trim(
            $row['area'] ?? ''
        );

        if (
            $oneArea !== '' &&
            !in_array(
                $oneArea,
                $areas,
                true
            )
        ) {
            $areas[] = $oneArea;
        }
    }

    mysqli_stmt_close($stmt);

    return $areas;
}


/* =========================================================
   UPDATE AREA TABLE FOR GROUP
   ========================================================= */

function updateAreaTable(
    $con,
    $grp_id,
    $company_name,
    $scheme_name,
    $areas
) {

    /* Preserve the group's existing star status before rebuilding area rows. */
    $existingStar = 0;

    $starCheck = mysqli_prepare(
        $con,
        "SELECT COALESCE(MAX(star), 0) AS star
         FROM area
         WHERE grp_id = ?"
    );

    if ($starCheck) {
        mysqli_stmt_bind_param(
            $starCheck,
            'i',
            $grp_id
        );

        mysqli_stmt_execute($starCheck);

        $starResult =
            mysqli_stmt_get_result($starCheck);

        if ($starRow = mysqli_fetch_assoc($starResult)) {
            $existingStar =
                ((int)($starRow['star'] ?? 0) === 1)
                ? 1
                : 0;
        }

        mysqli_stmt_close($starCheck);
    }

    mysqli_query(
        $con,
        "
        DELETE FROM area
        WHERE grp_id = " .
        (int)$grp_id
    );

    $mainPersons =
        getMainPersons(
            $con,
            $grp_id
        );

    $mainPersonString =
        implode(
            ', ',
            $mainPersons
        );

    $sql = "
        INSERT INTO area
        (
            area,
            company_name,
            scheme_name,
            grp_id,
            main_persons,
            star
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ";

    $stmt = mysqli_prepare(
        $con,
        $sql
    );

    if (!$stmt) {
        throw new Exception(
            'Unable to prepare area update: ' .
            mysqli_error($con)
        );
    }

    foreach ($areas as $oneArea) {

        $oneArea = trim(
            $oneArea
        );

        if ($oneArea === '') {
            continue;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'sssisi',
            $oneArea,
            $company_name,
            $scheme_name,
            $grp_id,
            $mainPersonString,
            $existingStar
        );

        if (
            !mysqli_stmt_execute(
                $stmt
            )
        ) {
            throw new Exception(
                'Unable to update area: ' .
                mysqli_stmt_error($stmt)
            );
        }
    }

    mysqli_stmt_close($stmt);
}


/* =========================================================
   REFRESH MAIN PERSONS IN AREA TABLE
   ========================================================= */

function refreshAreaMainPersons(
    $con,
    $grp_id
) {

    $mainPersons =
        getMainPersons(
            $con,
            $grp_id
        );

    $mainString =
        implode(
            ', ',
            $mainPersons
        );

    $stmt = mysqli_prepare(
        $con,
        "
        UPDATE area
        SET main_persons = ?
        WHERE grp_id = ?
        "
    );

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'si',
        $mainString,
        $grp_id
    );

    mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
}


/* =========================================================
   UPDATE ALL RELATION FOR SAME NAME
   ========================================================= */

function refreshAllRelationForName($con, $name)
{
    $normalized = normalizeName($name);

    /*
     * All Relation is based ONLY on previous/current database scheme history.
     * For each row, its own current scheme is excluded.
     * Relation 1 and Relation 2 are completely independent.
     */
    $stmt = mysqli_prepare(
        $con,
        "
        SELECT id, scheme_name
        FROM data
        WHERE UPPER(TRIM(name)) = ?
        ORDER BY id ASC
        "
    );

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param($stmt, 's', $normalized);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = [
            'id' => (int)$row['id'],
            'scheme' => trim((string)($row['scheme_name'] ?? ''))
        ];
    }

    mysqli_stmt_close($stmt);

    foreach ($rows as $personRow) {

        $previousSchemes = [];

        foreach ($rows as $otherRow) {

            $otherScheme = trim($otherRow['scheme']);

            if ($otherScheme === '') {
                continue;
            }

            /*
             * NEVER include this row's current scheme.
             */
            if (
                strcasecmp(
                    $otherScheme,
                    $personRow['scheme']
                ) === 0
            ) {
                continue;
            }

            if (!in_array($otherScheme, $previousSchemes, true)) {
                $previousSchemes[] = $otherScheme;
            }
        }

        $allRelation = implode(', ', $previousSchemes);

        $update = mysqli_prepare(
            $con,
            "
            UPDATE data
            SET relation_all = ?
            WHERE id = ?
            "
        );

        if ($update) {
            mysqli_stmt_bind_param(
                $update,
                'si',
                $allRelation,
                $personRow['id']
            );
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
        }
    }
}


/* =========================================================
   STAR / UNSTAR GROUP
   Copied from the working group-list page.
   Uses area.star and updates every area row for the group.
   ========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['star_action'])
) {
    $star_grp_id = (int)($_POST['grp_id'] ?? 0);
    $new_star = (
        ($_POST['star_action'] ?? '') === 'star'
    ) ? 1 : 0;

    if ($star_grp_id > 0) {
        $starStmt = mysqli_prepare(
            $con,
            "UPDATE area SET star = ? WHERE grp_id = ?"
        );

        if ($starStmt) {
            mysqli_stmt_bind_param(
                $starStmt,
                'ii',
                $new_star,
                $star_grp_id
            );

            if (mysqli_stmt_execute($starStmt)) {
                mysqli_stmt_close($starStmt);

                header(
                    'Location: ' .
                    $_SERVER['PHP_SELF'] .
                    '?star_updated=1'
                );
                exit;
            }

            $error = mysqli_stmt_error($starStmt);
            mysqli_stmt_close($starStmt);
        } else {
            $error = mysqli_error($con);
        }
    }
}

/* =========================================================
   DELETE GROUP
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_group'])
) {

    $grp_id =
        (int)(
            $_POST['grp_id'] ?? 0
        );

    if ($grp_id <= 0) {

        $error =
            'Invalid Group ID.';

    } else {

        mysqli_begin_transaction($con);

        try {

            $stmt =
                mysqli_prepare(
                    $con,
                    "
                    DELETE FROM data
                    WHERE grp_id = ?
                    "
                );

            if (!$stmt) {
                throw new Exception(
                    mysqli_error($con)
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'i',
                $grp_id
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);


            $stmt =
                mysqli_prepare(
                    $con,
                    "
                    DELETE FROM area
                    WHERE grp_id = ?
                    "
                );

            if (!$stmt) {
                throw new Exception(
                    mysqli_error($con)
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'i',
                $grp_id
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);

            mysqli_commit($con);

            header(
                'Location: ' .
                $_SERVER['PHP_SELF'] .
                '?deleted=1'
            );

            exit;

        } catch (Exception $ex) {

            mysqli_rollback($con);

            $error =
                $ex->getMessage();
        }
    }
}


/* =========================================================
   UPDATE GROUP
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_group'])
) {

    $grp_id =
        (int)(
            $_POST['grp_id'] ?? 0
        );

    $company_name =
        trim(
            $_POST['company_name'] ?? ''
        );

    $scheme_name =
        trim(
            $_POST['scheme_name'] ?? ''
        );

    $areas =
        $_POST['areas'] ?? [];

    if (!is_array($areas)) {
        $areas = [];
    }

    /*
     * The area filter/options are built later from the already-loaded
     * group data so the initial page stays fast.
     *
     * IMPORTANT: do not access $area_options here. During a POST request
     * the update handler runs before the display/query section builds that
     * variable. The submitted values already came from the area checkboxes,
     * so we only need to clean and de-duplicate them here.
     */
    $cleanAreas = [];
    foreach ($areas as $submittedArea) {
        $submittedArea = trim((string)$submittedArea);
        if ($submittedArea !== '') {
            $cleanAreas[] = $submittedArea;
        }
    }

    $areas = array_values(array_unique($cleanAreas, SORT_STRING));

    if ($grp_id <= 0) {

        $error =
            'Invalid Group ID.';

    } elseif ($company_name === '') {

        $error =
            'Company Name is required.';

    } elseif ($scheme_name === '') {

        $error =
            'Scheme Name is required.';

    } elseif (empty($areas)) {

        $error =
            'At least one area is required.';

    } else {

        mysqli_begin_transaction($con);

        try {

            $stmt =
                mysqli_prepare(
                    $con,
                    "
                    UPDATE data
                    SET
                        company_name = ?,
                        scheme_name = ?,
                        area = ?
                    WHERE grp_id = ?
                    "
                );

            if (!$stmt) {
                throw new Exception(
                    mysqli_error($con)
                );
            }

            $areaString =
                implode(
                    ', ',
                    $areas
                );

            mysqli_stmt_bind_param(
                $stmt,
                'sssi',
                $company_name,
                $scheme_name,
                $areaString,
                $grp_id
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);


            updateAreaTable(
                $con,
                $grp_id,
                $company_name,
                $scheme_name,
                $areas
            );

            /*
             * Save ALL edited person rows submitted with the group form.
             */
            if (
                isset($_POST['persons']) &&
                is_array($_POST['persons'])
            ) {
                $personUpdate = mysqli_prepare(
                    $con,
                    "
                    UPDATE data
                    SET
                        name = ?,
                        number1 = ?,
                        number2 = ?,
                        number3 = ?,
                        relation1 = ?,
                        relation2 = ?,
                        `main` = ?
                    WHERE id = ?
                      AND grp_id = ?
                    "
                );

                if (!$personUpdate) {
                    throw new Exception(
                        mysqli_error($con)
                    );
                }

                foreach ($_POST['persons'] as $personId => $personData) {

                    if (!is_array($personData)) {
                        continue;
                    }

                    $personId = (int)$personId;

                    if ($personId <= 0) {
                        continue;
                    }

                    $pName = trim(
                        $personData['name'] ?? ''
                    );

                    if ($pName === '') {
                        throw new Exception(
                            'A person name cannot be empty.'
                        );
                    }

                    $pNumber1 = trim((string)(
                        $personData['number1'] ?? ''
                    ));

                    $pNumber2 = trim((string)(
                        $personData['number2'] ?? ''
                    ));

                    $pNumber3 = trim((string)(
                        $personData['number3'] ?? ''
                    ));

                    $pRelation1 = trim(
                        $personData['relation1'] ?? ''
                    );

                    $pRelation2 = trim(
                        $personData['relation2'] ?? ''
                    );

                    $pMain = trim(
                        $personData['main'] ?? ''
                    );

                    if ($pMain !== 'main') {
                        $pMain = '';
                    }

                    mysqli_stmt_bind_param(
                        $personUpdate,
                        'sssssssii',
                        $pName,
                        $pNumber1,
                        $pNumber2,
                        $pNumber3,
                        $pRelation1,
                        $pRelation2,
                        $pMain,
                        $personId,
                        $grp_id
                    );

                    if (!mysqli_stmt_execute($personUpdate)) {
                        throw new Exception(
                            mysqli_stmt_error($personUpdate)
                        );
                    }
                }

                mysqli_stmt_close($personUpdate);
            }


            refreshAreaMainPersons(
                $con,
                $grp_id
            );

            /*
             * Scheme may have changed. Rebuild All Relation for every
             * person in this group, excluding that person's current scheme.
             */
            $personNamesStmt = mysqli_prepare(
                $con,
                "
                SELECT DISTINCT name
                FROM data
                WHERE grp_id = ?
                  AND TRIM(name) <> ''
                "
            );

            if ($personNamesStmt) {
                mysqli_stmt_bind_param(
                    $personNamesStmt,
                    'i',
                    $grp_id
                );
                mysqli_stmt_execute($personNamesStmt);
                $personNamesResult =
                    mysqli_stmt_get_result($personNamesStmt);

                while (
                    $personNameRow =
                    mysqli_fetch_assoc($personNamesResult)
                ) {
                    refreshAllRelationForName(
                        $con,
                        $personNameRow['name']
                    );
                }

                mysqli_stmt_close($personNamesStmt);
            }

            mysqli_commit($con);

            $message =
                'Group updated successfully.';

        } catch (Exception $ex) {

            mysqli_rollback($con);

            $error =
                $ex->getMessage();
        }
    }
}


/* =========================================================
   UPDATE PERSON
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_person'])
) {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );

    $grp_id =
        (int)(
            $_POST['grp_id'] ?? 0
        );

    $name =
        trim(
            $_POST['name'] ?? ''
        );

    $number1 = trim((string)(
            $_POST['number1'] ?? ''
        ));

    $number2 = trim((string)(
            $_POST['number2'] ?? ''
        ));

    $number3 = trim((string)(
            $_POST['number3'] ?? ''
        ));

    $relation1 =
        trim(
            $_POST['relation1'] ?? ''
        );

    $relation2 =
        trim(
            $_POST['relation2'] ?? ''
        );

    $main =
        trim(
            $_POST['main'] ?? ''
        );

    if ($main !== 'main') {
        $main = '';
    }

    if ($id <= 0 || $grp_id <= 0) {

        $error =
            'Invalid person or group.';

    } elseif ($name === '') {

        $error =
            'Person name is required.';

    } else {

        $stmt =
            mysqli_prepare(
                $con,
                "
                UPDATE data
                SET
                    name = ?,
                    number1 = ?,
                    number2 = ?,
                    number3 = ?,
                    relation1 = ?,
                    relation2 = ?,
                    `main` = ?
                WHERE id = ?
                  AND grp_id = ?
                "
            );

        if (!$stmt) {

            $error =
                mysqli_error($con);

        } else {

            mysqli_stmt_bind_param(
                $stmt,
                'sssssssii',
                $name,
                $number1,
                $number2,
                $number3,
                $relation1,
                $relation2,
                $main,
                $id,
                $grp_id
            );

            if (
                mysqli_stmt_execute($stmt)
            ) {

                mysqli_stmt_close($stmt);

                refreshAllRelationForName(
                    $con,
                    $name
                );

                refreshAreaMainPersons(
                    $con,
                    $grp_id
                );

                $message =
                    'Person updated successfully.';

            } else {

                $error =
                    mysqli_stmt_error(
                        $stmt
                    );

                mysqli_stmt_close($stmt);
            }
        }
    }
}


/* =========================================================
   DELETE PERSON
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_person'])
) {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );

    $grp_id =
        (int)(
            $_POST['grp_id'] ?? 0
        );

    if ($id <= 0) {

        $error =
            'Invalid person ID.';

    } else {

        $stmt =
            mysqli_prepare(
                $con,
                "
                DELETE FROM data
                WHERE id = ?
                  AND grp_id = ?
                "
            );

        if (!$stmt) {

            $error =
                mysqli_error($con);

        } else {

            mysqli_stmt_bind_param(
                $stmt,
                'ii',
                $id,
                $grp_id
            );

            if (
                mysqli_stmt_execute($stmt)
            ) {

                mysqli_stmt_close($stmt);

                refreshAreaMainPersons(
                    $con,
                    $grp_id
                );

                $message =
                    'Person deleted successfully.';

            } else {

                $error =
                    mysqli_stmt_error(
                        $stmt
                    );

                mysqli_stmt_close($stmt);
            }
        }
    }
}


/* =========================================================
   ADD PERSON TO EXISTING GROUP
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_person'])
) {

    $grp_id =
        (int)(
            $_POST['grp_id'] ?? 0
        );

    $name =
        trim(
            $_POST['new_name'] ?? ''
        );

    $number1 = trim((string)(
            $_POST['new_number1'] ?? ''
        ));

    $number2 = trim((string)(
            $_POST['new_number2'] ?? ''
        ));

    $number3 = trim((string)(
            $_POST['new_number3'] ?? ''
        ));

    $relation1 =
        trim(
            $_POST['new_relation1'] ?? ''
        );

    $relation2 =
        trim(
            $_POST['new_relation2'] ?? ''
        );

    $main =
        trim(
            $_POST['new_main'] ?? ''
        );

    if ($main !== 'main') {
        $main = '';
    }

    if ($grp_id <= 0) {

        $error =
            'Invalid Group ID.';

    } elseif ($name === '') {

        $error =
            'Name is required.';

    } else {

        $groupStmt =
            mysqli_prepare(
                $con,
                "
                SELECT
                    company_name,
                    scheme_name,
                    area
                FROM data
                WHERE grp_id = ?
                LIMIT 1
                "
            );

        if (!$groupStmt) {

            $error =
                mysqli_error($con);

        } else {

            mysqli_stmt_bind_param(
                $groupStmt,
                'i',
                $grp_id
            );

            mysqli_stmt_execute(
                $groupStmt
            );

            $groupResult =
                mysqli_stmt_get_result(
                    $groupStmt
                );

            $groupData =
                mysqli_fetch_assoc(
                    $groupResult
                );

            mysqli_stmt_close(
                $groupStmt
            );

            if (!$groupData) {

                $error =
                    'Group not found.';

            } else {

                $company =
                    $groupData[
                        'company_name'
                    ];

                $scheme =
                    $groupData[
                        'scheme_name'
                    ];

                $area =
                    $groupData[
                        'area'
                    ];

                $normalized =
                    normalizeName(
                        $name
                    );

                $schemes = [];

                $historyStmt =
                    mysqli_prepare(
                        $con,
                        "
                        SELECT DISTINCT scheme_name
                        FROM data
                        WHERE UPPER(TRIM(name)) = ?
                        "
                    );

                if ($historyStmt) {

                    mysqli_stmt_bind_param(
                        $historyStmt,
                        's',
                        $normalized
                    );

                    mysqli_stmt_execute(
                        $historyStmt
                    );

                    $historyResult =
                        mysqli_stmt_get_result(
                            $historyStmt
                        );

                    while (
                        $historyRow =
                        mysqli_fetch_assoc(
                            $historyResult
                        )
                    ) {

                        $oldScheme =
                            trim(
                                $historyRow[
                                    'scheme_name'
                                ] ?? ''
                            );

                        if (
                            $oldScheme !== '' &&
                            !in_array(
                                $oldScheme,
                                $schemes,
                                true
                            )
                        ) {
                            $schemes[] =
                                $oldScheme;
                        }
                    }

                    mysqli_stmt_close(
                        $historyStmt
                    );
                }

                if (
                    $scheme !== '' &&
                    !in_array(
                        $scheme,
                        $schemes,
                        true
                    )
                ) {
                    $schemes[] =
                        $scheme;
                }

                $relationAll =
                    implode(
                        ', ',
                        $schemes
                    );


                $insertStmt =
                    mysqli_prepare(
                        $con,
                        "
                        INSERT INTO data
                        (
                            grp_id,
                            company_name,
                            scheme_name,
                            name,
                            number1,
                            number2,
                            number3,
                            relation1,
                            relation2,
                            relation_all,
                            `main`,
                            area
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                        "
                    );

                if (!$insertStmt) {

                    $error =
                        mysqli_error($con);

                } else {

                    mysqli_stmt_bind_param(
                        $insertStmt,
                        'isssssssssss',
                        $grp_id,
                        $company,
                        $scheme,
                        $name,
                        $number1,
                        $number2,
                        $number3,
                        $relation1,
                        $relation2,
                        $relationAll,
                        $main,
                        $area
                    );

                    if (
                        mysqli_stmt_execute(
                            $insertStmt
                        )
                    ) {

                        mysqli_stmt_close(
                            $insertStmt
                        );

                        refreshAllRelationForName(
                            $con,
                            $name
                        );

                        refreshAreaMainPersons(
                            $con,
                            $grp_id
                        );

                        $message =
                            'Person added successfully.';

                    } else {

                        $error =
                            mysqli_stmt_error(
                                $insertStmt
                            );

                        mysqli_stmt_close(
                            $insertStmt
                        );
                    }
                }
            }
        }
    }
}


/* =========================================================
   SUCCESS / DELETE MESSAGE
   ========================================================= */

if (isset($_GET['star_updated'])) {
    $message =
        'Group star status updated successfully.';
}

if (
    isset($_GET['deleted'])
) {

    $message =
        'Group deleted successfully.';
}


/* =========================================================
   GROUP + PERSON DATA — SINGLE DATABASE READ
   =========================================================
   The previous version loaded groups first and then ran a second
   large query for every person's group IDs.  On a large table this
   means the data table can be scanned twice and a very large IN(...)
   list has to be prepared.

   This version reads the group/person records in ONE query.  The
   area-star status is also calculated once in a derived table instead
   of running an EXISTS lookup for every group.
*/
$groups = [];

$groupSortSql = strtoupper($group_sort) === 'ASC' ? 'ASC' : 'DESC';

$starWhereSql = '';
if ($star_filter === 'starred') {
    $starWhereSql = ' WHERE COALESCE(ast.is_starred, 0) = 1 ';
} elseif ($star_filter === 'not_starred') {
    $starWhereSql = ' WHERE COALESCE(ast.is_starred, 0) = 0 ';
}

$combinedSql = "
    SELECT
        d.id,
        d.grp_id,
        d.company_name,
        d.scheme_name,
        d.name,
        d.number1,
        d.number2,
        d.number3,
        d.relation1,
        d.relation2,
        d.relation_all,
        d.`main`,
        d.area,
        COALESCE(ast.is_starred, 0) AS is_starred
    FROM data d
    LEFT JOIN (
        SELECT
            grp_id,
            MAX(
                CASE
                    WHEN LOWER(TRIM(COALESCE(star,'')))
                         IN ('1','star','yes','true')
                    THEN 1
                    ELSE 0
                END
            ) AS is_starred
        FROM area
        GROUP BY grp_id
    ) ast ON ast.grp_id = d.grp_id
    " . $starWhereSql . "
    ORDER BY
        CAST(d.grp_id AS UNSIGNED) " . $groupSortSql . ",
        d.grp_id " . $groupSortSql . ",
        d.id ASC
";

$combinedResult = mysqli_query($con, $combinedSql);

if ($combinedResult) {
    while ($person = mysqli_fetch_assoc($combinedResult)) {
        $gid = (int)($person['grp_id'] ?? 0);

        if ($gid <= 0) {
            continue;
        }

        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'grp_id'       => $gid,
                'company_name' => $person['company_name'] ?? '',
                'scheme_name'  => $person['scheme_name'] ?? '',
                'area'         => $person['area'] ?? '',
                'is_starred'   => ((int)($person['is_starred'] ?? 0) === 1),
                'persons'      => [],
                'main_persons' => [],
                'relations'    => []
            ];
        }

        /*
         * Preserve the old MAX()-style group values where records in
         * one group contain different values.  This is done in PHP so
         * no second database query is necessary.
         */
        if (strcmp((string)($person['company_name'] ?? ''), (string)$groups[$gid]['company_name']) > 0) {
            $groups[$gid]['company_name'] = $person['company_name'] ?? '';
        }
        if (strcmp((string)($person['scheme_name'] ?? ''), (string)$groups[$gid]['scheme_name']) > 0) {
            $groups[$gid]['scheme_name'] = $person['scheme_name'] ?? '';
        }
        if (strcmp((string)($person['area'] ?? ''), (string)$groups[$gid]['area']) > 0) {
            $groups[$gid]['area'] = $person['area'] ?? '';
        }

        $groups[$gid]['persons'][] = $person;

        $relation = trim((string)($person['relation_all'] ?? ''));
        if ($relation !== '') {
            $parts = preg_split('/\s*,\s*/', $relation);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' && !in_array($part, $groups[$gid]['relations'], true)) {
                    $groups[$gid]['relations'][] = $part;
                }
            }
        }

        if (trim((string)($person['main'] ?? '')) === 'main') {
            $mainName = trim((string)($person['name'] ?? ''));
            if ($mainName !== '' && !in_array($mainName, $groups[$gid]['main_persons'], true)) {
                $groups[$gid]['main_persons'][] = $mainName;
            }
        }
    }
}

/* =========================================================
   BUILD AREA FILTER FROM ALREADY-LOADED GROUP DATA
   ========================================================= */
$areaMap = [];

foreach ($groups as $group) {
    $groupAreaString = (string)($group['area'] ?? '');
    if ($groupAreaString === '') {
        continue;
    }

    foreach (preg_split('/\s*,\s*/', $groupAreaString) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $key = strtolower($part);
        if (!isset($areaMap[$key])) {
            $areaMap[$key] = $part;
        }
    }
}

$area_options = array_values($areaMap);
natcasesort($area_options);
$area_options = array_values($area_options);

/* =========================================================
   TOTALS
   ========================================================= */

$totalGroups =
    count($groups);

$totalPersons = 0;
$totalMain = 0;

foreach (
    $groups as $g
) {

    $totalPersons +=
        count(
            $g['persons']
        );

    $totalMain +=
        count(
            $g['main_persons']
        );
}

?>


<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    The Divine Lands — Group Directory
</title>


<style>

/* =========================================================
   ROOT
   ========================================================= */

:root {

    --gold:
        #e8b65b;

    --gold-dark:
        #c99535;

    --navy:
        #101c32;

    --navy-2:
        #172641;

    --navy-3:
        #223452;

    --white:
        #ffffff;

    --bg:
        #f5f7fb;

    --text:
        #172033;

    --muted:
        #6d7788;

    --border:
        #e5e9f0;

    --green:
        #159957;

    --red:
        #dc3545;

    --blue:
        #2878d4;

}


/* =========================================================
   RESET
   ========================================================= */

* {
    box-sizing:
        border-box;
}

html {
    scroll-behavior:
        smooth;
}

body {

    margin:
        0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        var(--bg);

    color:
        var(--text);

}


/* =========================================================
   HEADER
   ========================================================= */

.topbar {

    min-height:
        82px;

    background:
        linear-gradient(
            135deg,
            var(--navy),
            var(--navy-2)
        );

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    padding:
        12px 35px;

    box-shadow:
        0 8px 30px
        rgba(
            16,
            28,
            50,
            .18
        );

}


.brand {

    display:
        flex;

    align-items:
        center;

    gap:
        15px;

}


.logo {

    width:
        56px;

    height:
        56px;

    object-fit:
        contain;

    background:
        #fff;

    border-radius:
        12px;

    padding:
        5px;

}


.brand-text {

    color:
        white;

}


.brand-text strong {

    display:
        block;

    font-size:
        20px;

    letter-spacing:
        1.5px;

}


.brand-text span {

    display:
        block;

    margin-top:
        4px;

    font-size:
        12px;

    color:
        #d9e0ec;

}


/* =========================================================
   PAGE
   ========================================================= */

.page {

    max-width:
        1650px;

    margin:
        0 auto;

    padding:
        30px 25px 60px;

}


/* =========================================================
   HERO
   ========================================================= */

.hero {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        flex-end;

    gap:
        20px;

    margin-bottom:
        25px;

}


.hero h1 {

    margin:
        0;

    font-size:
        31px;

    color:
        var(--navy);

}


.hero p {

    margin:
        8px 0 0;

    color:
        var(--muted);

    font-size:
        14px;

}


/* =========================================================
   STATS
   ========================================================= */

.stats {

    display:
        grid;

    grid-template-columns:
        repeat(
            3,
            1fr
        );

    gap:
        18px;

    margin-bottom:
        24px;

}


.stat {

    background:
        white;

    border:
        1px solid var(--border);

    border-radius:
        17px;

    padding:
        21px;

    box-shadow:
        0 8px 25px
        rgba(
            25,
            42,
            70,
            .06
        );

    position:
        relative;

    overflow:
        hidden;

}


.stat::after {

    content:
        "";

    position:
        absolute;

    width:
        85px;

    height:
        85px;

    right:
        -25px;

    top:
        -25px;

    border-radius:
        50%;

    background:
        rgba(
            232,
            182,
            91,
            .12
        );

}


.stat-label {

    color:
        var(--muted);

    font-size:
        12px;

    font-weight:
        bold;

    text-transform:
        uppercase;

    letter-spacing:
        .8px;

}


.stat-number {

    margin-top:
        7px;

    font-size:
        28px;

    font-weight:
        800;

    color:
        var(--navy);

}


/* =========================================================
   TOOLBAR
   ========================================================= */

.toolbar {

    background:
        white;

    border:
        1px solid var(--border);

    border-radius:
        17px;

    padding:
        17px;

    display:
        flex;

    align-items:
        center;

    gap:
        12px;

    margin-bottom:
        20px;

    box-shadow:
        0 7px 22px
        rgba(
            25,
            42,
            70,
            .05
        );

}


.search-box {

    flex:
        1;

    position:
        relative;

}


.search-box input {

    width:
        100%;

    height:
        45px;

    border:
        1px solid var(--border);

    border-radius:
        11px;

    padding:
        0 15px;

    outline:
        none;

    font-size:
        14px;

}


.search-box input:focus {

    border-color:
        var(--gold);

    box-shadow:
        0 0 0 3px
        rgba(
            232,
            182,
            91,
            .14
        );

}


.area-filter {

    height:
        45px;

    border:
        1px solid var(--border);

    border-radius:
        11px;

    padding:
        0 14px;

    background:
        white;

    color:
        var(--text);

    outline:
        none;

}

/* AREA FILTER - MULTI SELECT ONLY */
.area-filter {
    min-height: 45px;
    height: auto;
    max-height: 120px;
    overflow-y: auto;
}



/* =========================================================
   CARD
   ========================================================= */

.card {

    background:
        white;

    border:
        1px solid var(--border);

    border-radius:
        19px;

    overflow:
        hidden;

    box-shadow:
        0 12px 35px
        rgba(
            25,
            42,
            70,
            .07
        );

}


/* =========================================================
   CARD HEADER
   ========================================================= */

.card-header {

    padding:
        20px 22px;

    background:
        linear-gradient(
            135deg,
            #ffffff,
            #fafbfe
        );

    border-bottom:
        1px solid var(--border);

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

}


.card-title {

    font-size:
        17px;

    font-weight:
        800;

    color:
        var(--navy);

}


.card-subtitle {

    margin-top:
        4px;

    color:
        var(--muted);

    font-size:
        12px;

}


/* =========================================================
   TABLE
   ========================================================= */

.table-wrap {

    overflow-x:
        auto;

}


table {

    width:
        100%;

    min-width:
        1350px;

    border-collapse:
        collapse;

}

/* MAIN GROUP GRID ONLY - fixed to viewport, no horizontal scrolling */
#groupsTable {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    table-layout: fixed !important;
}

#groupsTable th,
#groupsTable td {
    overflow: hidden;
    overflow-wrap: anywhere;
    word-break: break-word;
}

#groupsTable th:nth-child(1), #groupsTable td:nth-child(1) { width: 5% !important; }
#groupsTable th:nth-child(2), #groupsTable td:nth-child(2) { width: 7% !important; }
#groupsTable th:nth-child(3), #groupsTable td:nth-child(3) { width: 10% !important; }
#groupsTable th:nth-child(4), #groupsTable td:nth-child(4) { width: 10% !important; }
#groupsTable th:nth-child(5), #groupsTable td:nth-child(5) { width: 26.5% !important; }
#groupsTable th:nth-child(6), #groupsTable td:nth-child(6) { width: 26.5% !important; }
#groupsTable th:nth-child(7), #groupsTable td:nth-child(7) { width: 10% !important; }
#groupsTable th:nth-child(8), #groupsTable td:nth-child(8) {
    width: 5% !important;
    padding-left: 6px !important;
    padding-right: 6px !important;
}

#groupsTable .actions {
    width: 100%;
    min-width: 0;
    gap: 4px;
    box-sizing: border-box;
}

#groupsTable .actions .btn {
    max-width: 100%;
    min-width: 0;
    padding-left: 5px;
    padding-right: 5px;
    white-space: normal;
    overflow-wrap: anywhere;
    box-sizing: border-box;
}

.table-wrap {
    overflow-x: hidden !important;
    max-width: 100%;
}


thead th {

    background:
        var(--navy);

    color:
        white;

    padding:
        14px 13px;

    text-align:
        left;

    font-size:
        11px;

    text-transform:
        uppercase;

    letter-spacing:
        .7px;

    white-space:
        nowrap;

}


thead th:nth-child(3) {

    background:
        var(--gold-dark);

}


tbody td {

    padding:
        15px 13px;

    border-bottom:
        1px solid var(--border);

    vertical-align:
        top;

    font-size:
        13px;

}


tbody tr {

    transition:
        .18s ease;

}


tbody tr:hover {

    background:
        #fbfcfe;

}


.group-id {

    font-weight:
        800;

    color:
        var(--gold-dark);

}

.group-sort-header {
    cursor: pointer;
    user-select: none;
}

.group-sort-header:hover {
    background: var(--navy-3);
}

.group-sort-header > span:first-child {
    display: inline-block;
    margin-right: 4px;
}

.group-sort-icon {
    font-size: 12px;
    opacity: .85;
}


.company {

    font-weight:
        700;

    color:
        var(--navy);

}


.scheme {

    font-weight:
        700;

}


/* =========================================================
   LINE LIST
   ========================================================= */

.line-list {

    display:
        flex;

    flex-direction:
        column;

    gap:
        5px;

}


.line-item {

    padding:
        5px 8px;

    border-radius:
        7px;

    background:
        #f5f7fa;

    line-height:
        1.35;

}


.line-item.main {

    background:
        #fff8e9;

    border-left:
        3px solid var(--gold);

    font-weight:
        700;

}


.relation-item {

    color:
        #5d4b25;

    background:
        #fffaf0;

}


/* =========================================================
   AREA BADGES
   ========================================================= */

.area-list {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        6px;

}


.area-badge {

    display:
        inline-flex;

    padding:
        5px 9px;

    border-radius:
        20px;

    background:
        #edf4ff;

    color:
        #255b96;

    font-size:
        11px;

    font-weight:
        700;

}


/* =========================================================
   ACTIONS
   ========================================================= */

.actions {

    display:
        flex;

    gap:
        7px;

    flex-wrap:
        wrap;

}


.btn {

    border:
        0;

    border-radius:
        8px;

    padding:
        8px 11px;

    font-size:
        11px;

    font-weight:
        700;

    cursor:
        pointer;

    transition:
        .18s ease;

    white-space:
        nowrap;

}


.btn:hover {

    transform:
        translateY(-1px);

}


.btn-star{
    color:#735a24;
    background:#fff8e8;
    border:1px solid #eedaa9;
    cursor:pointer;
    transition:.18s;
}
.btn-star:hover{
    background:#fff3cf;
    border-color:#d9bd78;
    color:#936919;
    transform:translateY(-1px);
}
.btn-star.starred{
    color:#fff;
    background:linear-gradient(135deg,#c38d28,#e3b54f);
    border-color:#d8aa4a;
    box-shadow:0 5px 14px rgba(195,141,40,.20);
}

.btn-view {

    background:
        #edf4ff;

    color:
        #2364a7;

}


.btn-edit {

    background:
        #fff5dc;

    color:
        #946719;

}


.btn-delete {

    background:
        #fff0f1;

    color:
        #b42331;

}


.btn-save {

    background:
        var(--green);

    color:
        white;

}


.btn-cancel {

    background:
        #eef0f4;

    color:
        #4d5665;

}


.btn-add {

    background:
        var(--navy);

    color:
        white;

}


.btn-add:hover {

    background:
        var(--navy-3);

}


/* =========================================================
   DETAILS
   ========================================================= */

.details-row {

    display:
        none;

}


.details-row.open {

    display:
        table-row;

}


.details-content {

    padding:
        22px;

    background:
        #f8faff;

    border-bottom:
        1px solid var(--border);

}


.details-grid {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        18px;

}


.details-panel {

    background:
        white;

    border:
        1px solid var(--border);

    border-radius:
        14px;

    overflow:
        hidden;

}


.panel-title {

    padding:
        13px 15px;

    background:
        var(--navy);

    color:
        white;

    font-weight:
        700;

    font-size:
        12px;

}


.panel-body {

    padding:
        15px;

}


/* =========================================================
   PERSON EDIT TABLE
   ========================================================= */

.person-table-wrap {

    overflow-x:
        auto;

}


.person-table {

    min-width:
        1200px;

}


.person-table th {

    background:
        #eef2f7;

    color:
        #39465a;

    padding:
        10px;

    text-transform:
        uppercase;

    font-size:
        10px;

}


.person-table td {

    padding:
        8px;

}


.person-input {

    width:
        100%;

    min-width:
        110px;

    border:
        1px solid #dfe4eb;

    border-radius:
        7px;

    padding:
        8px;

    outline:
        none;

    font-size:
        12px;

}


.person-input:focus {

    border-color:
        var(--gold);

    box-shadow:
        0 0 0 2px
        rgba(
            232,
            182,
            91,
            .13
        );

}


.person-name {

    min-width:
        210px;

}


.main-select {

    border:
        1px solid #dfe4eb;

    border-radius:
        7px;

    padding:
        8px;

    background:
        white;

}


.main-person-row {

    background:
        #fffaf0 !important;

}


/* =========================================================
   GROUP EDIT FORM
   ========================================================= */

.group-edit {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        13px;

}


.field label {

    display:
        block;

    font-size:
        11px;

    font-weight:
        700;

    margin-bottom:
        6px;

    color:
        #5a6474;

}


.field input {

    width:
        100%;

    height:
        40px;

    border:
        1px solid var(--border);

    border-radius:
        8px;

    padding:
        0 11px;

    outline:
        none;

}


.area-checks-lazy .area-loading-placeholder{
    width:100%;
    padding:10px 12px;
    border:1px dashed var(--border);
    border-radius:9px;
    color:var(--muted);
    font-size:11px;
    background:#fafbfd;
}

.area-checks {

    grid-column:
        1 / -1;

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        8px;

}


.area-check {

    position:
        relative;

}


.area-check input {

    display:
        none;

}


.area-check label {

    display:
        block;

    padding:
        8px 13px;

    border:
        1px solid var(--border);

    border-radius:
        20px;

    cursor:
        pointer;

    font-size:
        11px;

}


.area-check input:checked + label {

    background:
        var(--navy);

    color:
        white;

    border-color:
        var(--navy);

}


/* =========================================================
   ADD PERSON
   ========================================================= */

.add-person {

    margin-top:
        15px;

    padding:
        15px;

    border:
        1px dashed #d5dbe5;

    border-radius:
        12px;

    background:
        #fafbfd;

}


.add-person-grid {

    display:
        grid;

    grid-template-columns:
        1.5fr 1fr 1fr 1fr 1fr 1fr auto;

    gap:
        8px;

}


.add-person-grid input,
.add-person-grid select {

    width:
        100%;

    height:
        38px;

    border:
        1px solid var(--border);

    border-radius:
        7px;

    padding:
        0 9px;

    outline:
        none;

    font-size:
        11px;

}


/* =========================================================
   ALERT
   ========================================================= */

.alert {

    padding:
        13px 17px;

    border-radius:
        11px;

    margin-bottom:
        18px;

    font-size:
        13px;

    font-weight:
        600;

}


.alert-success {

    background:
        #eaf8f0;

    color:
        #146c3c;

    border:
        1px solid #c7ebd6;

}


.alert-error {

    background:
        #fff0f1;

    color:
        #a51f2c;

    border:
        1px solid #f3c9ce;

}

.panel-link{
    position:absolute;
    z-index:5;
    top:27px;
    right:28px;
    padding:10px 16px;
    border:1px solid rgba(255,255,255,.15);
    border-radius:999px;
    color:#eaf1f7;
    background:rgba(255,255,255,.06);
    text-decoration:none;
    font-size:12px;
    font-weight:800;
    transition:.18s;
}

.panel-link:hover{
    background:rgba(255,255,255,.13);
    transform:translateY(-1px);
}

/* =========================================================
   EMPTY
   ========================================================= */

.empty {

    text-align:
        center;

    padding:
        70px 20px;

    color:
        var(--muted);

}


.empty-icon {

    font-size:
        42px;

    margin-bottom:
        10px;

}


/* =========================================================
   FOOTER
   ========================================================= */

.footer {

    text-align:
        center;

    margin-top:
        35px;

    color:
        #8993a3;

    font-size:
        11px;

}


/* =========================================================
   MODAL
   ========================================================= */

.modal {

    display:
        none;

    position:
        fixed;

    z-index:
        9999;

    inset:
        0;

    background:
        rgba(
            8,
            17,
            31,
            .72
        );

    padding:
        25px;

    overflow-y:
        auto;

}


.modal.show {

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

}


.modal-box {

    width:
        min(
            850px,
            100%
        );

    background:
        white;

    border-radius:
        18px;

    overflow:
        hidden;

    box-shadow:
        0 30px 90px
        rgba(
            0,
            0,
            0,
            .25
        );

}


.modal-head {

    background:
        var(--navy);

    color:
        white;

    padding:
        18px 22px;

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

}


.modal-head strong {

    font-size:
        17px;

}


.modal-close {

    border:
        0;

    background:
        transparent;

    color:
        white;

    font-size:
        25px;

    cursor:
        pointer;

}


.modal-body {

    padding:
        22px;

}


.modal-info {

    display:
        grid;

    grid-template-columns:
        repeat(
            3,
            1fr
        );

    gap:
        12px;

    margin-bottom:
        20px;

}


.info-box {

    background:
        #f6f8fb;

    border-radius:
        10px;

    padding:
        12px;

}


.info-box small {

    display:
        block;

    color:
        var(--muted);

    font-size:
        10px;

    text-transform:
        uppercase;

}


.info-box strong {

    display:
        block;

    margin-top:
        4px;

    font-size:
        13px;

}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (
    max-width: 1000px
) {

    .stats {

        grid-template-columns:
            1fr;

    }

    .details-grid {

        grid-template-columns:
            1fr;

    }

    .group-edit {

        grid-template-columns:
            1fr;

    }

    .add-person-grid {

        grid-template-columns:
            1fr 1fr;

    }

}


@media (
    max-width: 700px
) {

    .panel-link{position:static;display:inline-block;margin-top:17px}

    .topbar {

        padding:
            12px 17px;

    }

    .brand-text strong {

        font-size:
            16px;

    }

    .page {

        padding:
            20px 12px 45px;

    }

    .hero {

        align-items:
            flex-start;

    }

    .hero h1 {

        font-size:
            24px;

    }

    .toolbar {

        flex-direction:
            column;

        align-items:
            stretch;

    }

    .area-filter {

        width:
            100%;

    }

    .modal {

        padding:
            10px;

    }

    .modal-info {

        grid-template-columns:
            1fr;

    }

}


/* =========================================================
   GROUP DETAILS — CLOSE / SAVE UX
========================================================= */
.details-content{position:relative}
.details-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:15px;
    margin:-2px -2px 18px;padding:13px 15px;
    border:1px solid #e4e8ef;border-radius:12px;
    background:linear-gradient(135deg,#fbfcfe,#fffdf8)
}
.details-toolbar-title{display:flex;align-items:center;gap:10px}
.details-toolbar-icon{
    width:30px;height:30px;display:grid;place-items:center;border-radius:9px;
    background:#fff3d6;color:#a47728;font-size:10px
}
.details-toolbar-title strong{display:block;color:var(--navy);font-size:12px}
.details-toolbar-title small{display:block;margin-top:2px;color:var(--muted);font-size:10px}
.btn-close-details{
    background:#eef1f5!important;color:#4b5667!important;
    border:1px solid #dce1e8!important;cursor:pointer
}
.btn-close-details:hover{background:#e4e8ee!important;color:#182338!important}
@media(max-width:600px){
    .details-toolbar{align-items:flex-start;flex-direction:column}
    .details-toolbar .btn-close-details{width:100%}
}


.filter-form{margin:0}
.filter-search-btn{
    height:45px;
    border:0;
    border-radius:11px;
    padding:0 17px;
    background:linear-gradient(135deg,#101c32,#223452);
    color:#fff;
    font-weight:800;
    font-size:12px;
    cursor:pointer;
    white-space:nowrap;
    transition:.18s;
}
.filter-search-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 7px 16px rgba(16,28,50,.18);
}
@media(max-width:800px){
    .toolbar{flex-wrap:wrap}
    .search-box{flex-basis:100%}
    .filter-search-btn,.area-filter{flex:1}
}

/* AREA SELECT BUTTON + MULTI-SELECT DROPDOWN */
.area-select-wrap{position:relative;min-width:190px}
.area-select-button{
    width:100%;height:45px;padding:0 14px;border:1px solid var(--border);
    border-radius:11px;background:#fff;color:var(--text);font-weight:800;
    font-size:12px;cursor:pointer;display:flex;align-items:center;
    justify-content:space-between;gap:12px;outline:none;transition:.18s;
}
.area-select-button:hover,.area-select-wrap.open .area-select-button{
    border-color:var(--gold);box-shadow:0 0 0 3px rgba(232,182,91,.14)
}
.area-select-arrow{font-size:15px;line-height:1;transition:.18s}
.area-select-wrap.open .area-select-arrow{transform:rotate(180deg)}
.area-select-menu{
    display:none;position:absolute;z-index:9999;top:calc(100% + 7px);left:0;
    width:100%;min-width:230px;background:#fff;border:1px solid #dfe4eb;
    border-radius:12px;box-shadow:0 14px 35px rgba(16,28,50,.18);overflow:hidden;
}
.area-select-wrap.open .area-select-menu{display:block}
.area-select-menu-top{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:10px 12px;border-bottom:1px solid #edf0f4;background:#fafbfc;
}
.area-select-menu-top strong{font-size:12px;color:var(--navy)}
.area-select-menu-top button{
    border:0;background:transparent;color:#a47728;font-weight:800;font-size:11px;
    cursor:pointer;padding:3px 5px
}
.area-select-options{max-height:240px;overflow-y:auto;padding:6px}
.area-option{
    display:flex;align-items:center;gap:9px;padding:9px 8px;border-radius:8px;
    cursor:pointer;font-size:12px;color:var(--text);font-weight:600;
}
.area-option:hover{background:#f5f7fa}
.area-option input{width:16px;height:16px;margin:0;accent-color:#b78b3f;cursor:pointer}
@media(max-width:800px){.area-select-wrap{width:100%;min-width:0}.area-select-menu{width:100%}}


/* SEARCH HIGHLIGHT ONLY */
#groupsTable .search-match{
    display:inline !important;
    padding:1px 3px !important;
    margin:0 !important;
    background:#ffe08a !important;
    color:#111827 !important;
    border-radius:4px !important;
    font-weight:800 !important;
    line-height:inherit !important;
    vertical-align:baseline !important;
}

/* FINAL SEARCH HIGHLIGHT */
#groupsTable mark.search-match{
    display:inline !important;
    background:#ffe08a !important;
    color:#111827 !important;
    padding:1px 3px !important;
    margin:0 !important;
    border-radius:4px !important;
    font-weight:800 !important;
    line-height:inherit !important;
    vertical-align:baseline !important;
}

/* LIVE SEARCH HIGHLIGHT */
#groupsTable mark.tdl-live-hit{
    display:inline !important;
    background:#ffe08a !important;
    color:#111827 !important;
    padding:1px 3px !important;
    margin:0 !important;
    border-radius:4px !important;
    font-weight:800 !important;
    line-height:inherit !important;
}



/* =========================================================
   WHATSAPP SELECTION UI
   ========================================================= */
.tdl-wa-panel {
    margin: 0 0 18px;
    background: linear-gradient(135deg, #ffffff, #f8fbfa);
    border: 1px solid #dce8e1;
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 8px 24px rgba(16, 28, 50, .07);
}
.tdl-wa-panel-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:13px;
}
.tdl-wa-title { font-size:17px; font-weight:800; color:var(--navy); }
.tdl-wa-subtitle { margin-top:3px; color:var(--muted); font-size:12px; }
.tdl-selected-pill {
    white-space:nowrap;
    background:#edf8f1;
    color:#137b47;
    border:1px solid #cfe9da;
    border-radius:999px;
    padding:8px 13px;
    font-size:13px;
}
.tdl-wa-controls { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.tdl-wa-select {
    min-height:38px;
    border:1px solid #d7dee8;
    border-radius:9px;
    padding:0 11px;
    background:#fff;
    color:var(--text);
    font-weight:600;
    outline:none;
}
.tdl-wa-select:focus { border-color:var(--green); box-shadow:0 0 0 3px rgba(21,153,87,.10); }
.tdl-wa-btn {
    min-height:38px;
    border-radius:9px;
    padding:0 13px;
    border:1px solid transparent;
    cursor:pointer;
    font-weight:800;
    transition:.15s ease;
}
.tdl-wa-btn:hover { transform:translateY(-1px); }
.tdl-wa-btn-light { background:#fff; color:var(--navy); border-color:#d7dee8; }
.tdl-wa-btn-green { background:#159957; color:#fff; box-shadow:0 5px 14px rgba(21,153,87,.20); }
.tdl-message-wrap { margin-top:12px; }
.tdl-message-wrap label { display:flex; justify-content:space-between; gap:10px; margin-bottom:6px; font-size:12px; font-weight:800; color:var(--navy); }
.tdl-message-wrap label span { color:var(--muted); font-weight:500; }
#tdlWhatsAppMessage { width:100%; resize:vertical; min-height:58px; border:1px solid #d7dee8; border-radius:9px; padding:10px 12px; font:inherit; outline:none; }
#tdlWhatsAppMessage:focus { border-color:var(--green); box-shadow:0 0 0 3px rgba(21,153,87,.10); }
.tdl-wa-status { margin-top:9px; font-size:12px; color:var(--muted); }

.tdl-person-item { transition:opacity .15s ease, background .15s ease; }
.tdl-person-head { display:flex; align-items:center; gap:7px; min-height:22px; }
.tdl-person-select-label { width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; flex:0 0 22px; }
.tdl-person-checkbox { position:absolute; opacity:0; width:1px; height:1px; pointer-events:none; }
.tdl-checkmark { width:16px; height:16px; border:2px solid #aeb9c7; border-radius:4px; background:#fff; display:block; position:relative; transition:.12s ease; }
.tdl-person-checkbox:checked + .tdl-checkmark { background:#159957; border-color:#159957; }
.tdl-person-checkbox:checked + .tdl-checkmark:after { content:'✓'; position:absolute; color:#fff; font-size:12px; font-weight:900; left:1px; top:-2px; }
.tdl-wa-ready, .tdl-wa-missing { display:inline-block; font-size:9px; font-weight:900; border-radius:999px; padding:2px 5px; letter-spacing:.2px; }
.tdl-wa-ready { background:#e9f8ef; color:#13814b; }
.tdl-wa-missing { background:#f2f3f5; color:#7b8491; }
.tdl-person-item.tdl-person-hidden { display:none !important; }
.tdl-group-no-visible-persons { display:none !important; }

/* =========================================================
   WHATSAPP STEP MODAL
   ========================================================= */
.tdl-wa-modal {
    position:fixed; inset:0; z-index:99999; display:none;
    align-items:center; justify-content:center; padding:18px;
    background:rgba(8,16,29,.65); backdrop-filter:blur(4px);
}
.tdl-wa-modal.open { display:flex; }
.tdl-wa-dialog { width:min(560px, 100%); background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 25px 70px rgba(0,0,0,.30); }
.tdl-wa-dialog-head { padding:17px 19px; background:linear-gradient(135deg,var(--navy),var(--navy-2)); color:#fff; display:flex; justify-content:space-between; align-items:center; }
.tdl-wa-dialog-head strong { font-size:17px; }
.tdl-wa-close { border:0; background:rgba(255,255,255,.10); color:#fff; width:34px; height:34px; border-radius:8px; cursor:pointer; font-size:18px; }
.tdl-wa-dialog-body { padding:20px; }
.tdl-wa-progress { height:7px; background:#edf0f4; border-radius:99px; overflow:hidden; margin:0 0 18px; }
.tdl-wa-progress > div { height:100%; background:#159957; width:0%; transition:.2s ease; }
.tdl-wa-contact { border:1px solid #e2e7ed; border-radius:13px; padding:17px; background:#fafcfd; }
.tdl-wa-contact-name { font-size:21px; font-weight:900; color:var(--navy); }
.tdl-wa-contact-number { margin-top:5px; color:#159957; font-weight:800; font-size:15px; }
.tdl-wa-contact-meta { margin-top:8px; color:var(--muted); font-size:12px; line-height:1.6; }
.tdl-wa-dialog-actions { display:flex; gap:8px; margin-top:15px; flex-wrap:wrap; }
.tdl-wa-dialog-actions button { min-height:42px; border-radius:9px; padding:0 14px; border:1px solid #d7dee8; cursor:pointer; font-weight:800; }
.tdl-wa-open-btn { background:#159957; color:#fff; border-color:#159957 !important; flex:1; }
.tdl-wa-next-btn { background:var(--navy); color:#fff; border-color:var(--navy) !important; flex:1; }
.tdl-wa-skip-btn { background:#fff; color:var(--text); }
.tdl-wa-dialog-note { margin-top:12px; font-size:11px; color:var(--muted); line-height:1.5; }

@media (max-width:700px) {
    .tdl-wa-panel-head { align-items:flex-start; flex-direction:column; }
    .tdl-wa-select, .tdl-wa-btn { width:100%; }
    .tdl-message-wrap label { display:block; }
    .tdl-message-wrap label span { display:block; margin-top:3px; }
}


/* =========================================================
   SAVED MEMBER GROUPS
   Reusable browser-local groups of selected people.
   Does not change the existing WhatsApp campaign storage.
   ========================================================= */
.tdl-member-groups{
    margin-top:12px;padding:12px;border:1px solid #dce8e1;border-radius:12px;
    background:#f9fcfa;
}
.tdl-member-groups-title{font-size:12px;font-weight:900;color:var(--navy);margin-bottom:8px}
.tdl-member-groups-controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.tdl-group-name-input{
    min-height:38px;min-width:210px;flex:1;border:1px solid #d7dee8;border-radius:9px;
    padding:0 11px;background:#fff;color:var(--text);outline:none;font:inherit;font-size:12px;
}
.tdl-group-name-input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(21,153,87,.10)}
.tdl-member-group-select{
    min-height:38px;min-width:190px;border:1px solid #d7dee8;border-radius:9px;
    padding:0 11px;background:#fff;color:var(--text);font-weight:700;outline:none;font-size:12px;
}
.tdl-member-group-status{margin-top:7px;font-size:11px;color:var(--muted);font-weight:700}
.tdl-member-group-count{color:#137b47}
@media(max-width:700px){
    .tdl-group-name-input,.tdl-member-group-select,.tdl-member-groups .tdl-wa-btn{width:100%;min-width:0}
}

</style>

</head>


<body>


<!-- =========================================================
     TOP BAR
     ========================================================= -->

<header class="topbar">

    <div class="brand">

        <img
            src="logo.png"
            class="logo"
            alt="The Divine Lands"
        >

        <div class="brand-text">

            <strong>
                THE DIVINE LANDS
            </strong>

            <span>
                Group Management System
            </span>

        </div>
       

         <!-- <div class="header-status" style="margin-left:auto;"><a href="dataadding.php" class="header-status" style="text-decoration:none;color:#fff;background:linear-gradient(135deg,#667eea,#764ba2);padding:10px 18px;border-radius:10px;align-items:center;gap:8px;font-weight:600;box-shadow:0 4px 12px rgba(102,126,234,.3);transition:all .2s ease;cursor:pointer;"><span class="status-dot"></span> Add Section </a></div>
 <div class="header-status" style="margin-left:auto;"><a href="listshow.php" class="header-status" style="text-decoration:none;color:#fff;border:1px solid #fff;padding:10px 18px;border-radius:10px;align-items:center;gap:8px;font-weight:600;box-shadow:0 4px 12px rgba(102,126,234,.3);transition:all .2s ease;cursor:pointer;"><span class="status-dot"></span> List Show </a></div>
 <div class="header-status" style="margin-left:auto;"><a href="available_lands.php" class="header-status" style="text-decoration:none;color:#fff;background:linear-gradient(135deg,#667eea,#764ba2);padding:10px 18px;border-radius:10px;align-items:center;gap:8px;font-weight:600;box-shadow:0 4px 12px rgba(102,126,234,.3);transition:all .2s ease;cursor:pointer;"><span class="status-dot"></span> Lands </a></div>
 <div class="header-status" style="margin-left:auto;"><a href="index.php" class="header-status" style="text-decoration:none;color:#fff;background:linear-gradient(135deg,#667eea,#764ba2);padding:10px 18px;border-radius:10px;align-items:center;gap:8px;font-weight:600;box-shadow:0 4px 12px rgba(102,126,234,.3);transition:all .2s ease;cursor:pointer;"><span class="status-dot"></span> All </a></div> -->

 <a href="index.php" class="panel-link">
            ALL
        </a>

        

    </div>

</header>


<main class="page">


<!-- =========================================================
     HERO
     ========================================================= -->

<section class="hero">

    <div>

        <h1>
            Group Directory
        </h1>

        <p>
            Manage groups, people, main contacts,
            schemes, relations and areas.
        </p>

    </div>

</section>


<!-- =========================================================
     ALERTS
     ========================================================= -->

<?php if ($message !== ''): ?>

    <div class="alert alert-success">

        ✓ <?= e($message) ?>

    </div>

<?php endif; ?>


<?php if ($error !== ''): ?>

    <div class="alert alert-error">

        ⚠ <?= e($error) ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     STATS
     ========================================================= -->

<section class="stats">

    <div class="stat">

        <div class="stat-label">
            Total Groups
        </div>

        <div
            class="stat-number"
            id="totalGroups"
        >
            <?= $totalGroups ?>
        </div>

    </div>


    <div class="stat">

        <div class="stat-label">
            Total Persons
        </div>

        <div class="stat-number">
            <?= $totalPersons ?>
        </div>

    </div>


    <div class="stat">

        <div class="stat-label">
            Main Persons
        </div>

        <div class="stat-number">
            <?= $totalMain ?>
        </div>

    </div>

</section>


<!-- =========================================================
     SEARCH
     ========================================================= -->

<form method="GET" class="filter-form">
<div class="toolbar">

    <div class="search-box">

        <input
            type="text"
            id="groupSearch"
            name="q"
            value="<?= e($_GET['q'] ?? '') ?>"
            placeholder="Search company, scheme, person, group ID or relation..."
            autocomplete="off"
            oninput="tdlLiveSearchDebounced()"
        >

    </div>


    <button
        type="button"
        id="groupSearchButton"
        class="filter-search-btn"
        onclick="tdlLiveSearch()"
    >🔎 Search</button>

    <select
        class="area-filter"
        name="star_filter"
        id="starFilter"
        onchange="this.form.submit()"
    >
        <option value="">All Groups</option>
        <option value="starred" <?= $star_filter === 'starred' ? 'selected' : '' ?>>
            ★ Starred Groups
        </option>
        <option value="not_starred" <?= $star_filter === 'not_starred' ? 'selected' : '' ?>>
            ☆ Not Starred Groups
        </option>
    </select>

    <div class="area-select-wrap" id="areaSelectWrap">
        <button
            type="button"
            class="area-select-button"
            id="areaSelectButton"
            onclick="tdlToggleAreaMenu(event)"
        >
            <span>📍 Select Area</span>
            <span class="area-select-arrow">▾</span>
        </button>

        <div class="area-select-menu" id="areaSelectMenu">
            <div class="area-select-menu-top">
                <strong>Select Areas</strong>
                <button type="button" onclick="tdlClearAreaFilter(event)">All Areas</button>
            </div>

            <div class="area-select-options">
                <?php foreach ($area_options as $area): ?>
                    <label class="area-option">
                        <input
                            type="checkbox"
                            name="area_filter[]"
                            value="<?= e($area) ?>"
                            onchange="tdlAreaSelectionChanged()"
                        >
                        <span><?= e(ucwords($area)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>
</form>

<!-- =========================================================
     WHATSAPP BULK SELECTION TOOL
     Browser-safe: one contact is opened at a time after a user click.
     ========================================================= -->
<div class="tdl-wa-panel" id="tdlWhatsAppPanel">
    <div class="tdl-wa-panel-head">
        <div>
            <div class="tdl-wa-title">💬 WhatsApp Selection</div>
            <div class="tdl-wa-subtitle">Select members from any group, then open them one-by-one in WhatsApp.</div>
        </div>
        <div class="tdl-selected-pill">Selected: <strong id="tdlSelectedCount">0</strong></div>
    </div>

    <div class="tdl-wa-controls">
        <select id="tdlWaFilter" class="tdl-wa-select" onchange="tdlLiveSearch()">
            <option value="all">All Members</option>
            <option value="with_whatsapp">Only With WhatsApp</option>
            <option value="without_whatsapp">Without WhatsApp</option>
            <option value="selected">Selected Members</option>
            <option value="unselected">Unselected Members</option>
        </select>

        <select id="tdlPersonTypeFilter" class="tdl-wa-select" onchange="tdlLiveSearch()">
            <option value="all">All Person Types</option>
            <option value="main">Main Persons Only</option>
            <option value="non_main">Non-Main Persons</option>
        </select>

        <button type="button" class="tdl-wa-btn tdl-wa-btn-light" onclick="tdlSelectAllVisible()">☑ Select All Visible</button>
        <button type="button" class="tdl-wa-btn tdl-wa-btn-light" id="tdlShowSelectedGroupsBtn" onclick="tdlToggleShowSelectedGroups()">👁 Show Selected Groups</button>
        <button type="button" class="tdl-wa-btn tdl-wa-btn-light" id="tdlShowSelectedPersonsBtn" onclick="tdlToggleShowSelectedPersons()">👤 Only Selected Persons</button>
        <button type="button" class="tdl-wa-btn tdl-wa-btn-light" onclick="tdlClearSelection()">☐ Clear Selection</button>
        <button type="button" class="tdl-wa-btn tdl-wa-btn-green" onclick="tdlStartWhatsApp()">💬 Start WhatsApp</button>
        <button type="button" class="tdl-wa-btn tdl-wa-btn-light" id="tdlWaSavedResumeBtn" onclick="tdlResumeSavedWhatsApp()" style="display:none;">▶ Resume Saved Campaign</button>
        <span id="tdlWaSavedCampaignNotice" style="display:none;font-weight:800;font-size:12px;color:#159957;align-self:center;">Saved campaign available</span>
    </div>

    <div class="tdl-message-wrap">
        <label for="tdlWhatsAppMessage">Message <span>Optional — use <b>{{name}}</b>, <b>{{company}}</b>, <b>{{scheme}}</b>, <b>{{area}}</b></span></label>
        <textarea id="tdlWhatsAppMessage" rows="2" placeholder="Example: Hello {{name}}, I wanted to share an update with you..."></textarea>
    </div>

    <div class="tdl-wa-status" id="tdlWhatsAppStatus">Select members from the table above.</div>

    <div class="tdl-member-groups" id="tdlMemberGroupsPanel">
        <div class="tdl-member-groups-title">👥 Saved Member Groups</div>
        <div class="tdl-member-groups-controls">
            <input
                type="text"
                id="tdlMemberGroupName"
                class="tdl-group-name-input"
                maxlength="80"
                placeholder="Group name, e.g. Ahmedabad Builders"
                autocomplete="off"
            >
            <button type="button" class="tdl-wa-btn tdl-wa-btn-light" onclick="tdlSaveSelectedMemberGroup()">💾 Save New Group</button>
            <select id="tdlMemberGroupSelect" class="tdl-member-group-select" onchange="tdlLoadSelectedMemberGroup()">
                <option value="">Select Saved Group</option>
            </select>
            <button type="button" class="tdl-wa-btn tdl-wa-btn-green" onclick="tdlUpdateSelectedMemberGroup()">💾 Save Changes</button>
            <button type="button" class="tdl-wa-btn tdl-wa-btn-light" onclick="tdlDeleteSelectedMemberGroup()">🗑 Delete Group</button>
            <button type="button" class="tdl-wa-btn tdl-wa-btn-light" onclick="tdlClearSavedMemberGroupSelection()">☐ Clear Group Choice</button>
        </div>
        <div class="tdl-member-group-status" id="tdlMemberGroupStatus">Create groups from your current selection. Saved groups are stored permanently in the database.</div>
    </div>

</div>

<!-- =========================================================
     GROUP CARD
     ========================================================= -->

<div class="card">

    <div class="card-header">

        <div>

            <div class="card-title">
                All Groups
            </div>

            <div class="card-subtitle">
                All Relation is intentionally kept as the 3rd column.
            </div>

        </div>

    </div>


    <div class="table-wrap">

        <table id="groupsTable">
            <colgroup>
                <col style="width:5%">
                <col style="width:7%">
                <col style="width:10%">
                <col style="width:10%">
                <col style="width:26.5%">
                <col style="width:26.5%">
                <col style="width:10%">
                <col style="width:5%">
            </colgroup>

            <thead>

                <tr>

                    <th class="group-sort-header" title="Click to sort Group high to low / low to high">
                        <a
                            href="?group_sort=<?= $group_sort === 'asc' ? 'desc' : 'asc' ?>&star_filter=<?= e($star_filter) ?>&q=<?= e($_GET['q'] ?? '') ?>"
                            style="display:flex;align-items:center;gap:4px;color:inherit;text-decoration:none;width:100%;height:100%;cursor:pointer;"
                        >
                            <span>Group</span>
                            <span class="group-sort-icon"><?= $group_sort === 'asc' ? '↑' : '↓' ?></span>
                        </a>
                    </th>

                    <th>
                        Company
                    </th>

                    <!-- THIRD COLUMN -->
                    <th>
                        All Relation
                    </th>

                    <th>
                        Scheme
                    </th>

                    <th>
                        All Persons
                    </th>

                    <th>
                        Main Persons
                    </th>

                    <th>
                        Area
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

            </thead>


            <tbody>


<?php if (empty($groups)): ?>


                <tr>

                    <td
                        colspan="8"
                        class="empty"
                    >

                        <div class="empty-icon">
                            ◌
                        </div>

                        No groups found.

                    </td>

                </tr>


<?php else: ?>


<?php foreach ($groups as $group): ?>

<?php
$gid = (int)$group['grp_id'];
$searchParts = [
    $gid,
    $group['company_name'] ?? '',
    $group['scheme_name'] ?? '',
    implode(' ', $group['relations'] ?? []),
    implode(' ', array_column($group['persons'] ?? [], 'name')),
    $group['area'] ?? ''
];
$searchText = strtolower(implode(' ', $searchParts));
?>

<tr
    class="group-row"
    data-group-id="<?= $gid ?>"
    data-search="<?= e($searchText) ?>"
    data-area="<?= e(strtolower((string)($group['area'] ?? ''))) ?>"
>
    <td><div class="group-id">#<?= $gid ?></div></td>
    <td><div class="company"><?= e($group['company_name'] ?? '') ?></div></td>

    <td>
        <div class="line-list">
<?php if (!empty($group['relations'])): ?>
<?php foreach ($group['relations'] as $relation): ?>
            <div class="line-item relation-item"><?= e($relation) ?></div>
<?php endforeach; ?>
<?php else: ?>
            <div class="line-item">—</div>
<?php endif; ?>
        </div>
    </td>

    <td><div class="scheme"><?= e($group['scheme_name'] ?? '') ?></div></td>

    <td>
        <div class="line-list">
<?php foreach (($group['persons'] ?? []) as $person): ?>
<?php
$tdlPersonId = (int)($person['id'] ?? 0);
$tdlPersonName = trim((string)($person['name'] ?? ''));
$tdlPersonMain = trim((string)($person['main'] ?? '')) === 'main';
$tdlPersonNumbers = array_values(array_filter([
    trim((string)($person['number1'] ?? '')),
    trim((string)($person['number2'] ?? '')),
    trim((string)($person['number3'] ?? ''))
], static function ($v) { return $v !== ''; }));
$tdlWhatsAppNumber = '';
foreach ($tdlPersonNumbers as $tdlCandidateNumber) {
    $tdlCleanCandidate = preg_replace('/[^0-9]/', '', $tdlCandidateNumber);
    if ($tdlCleanCandidate !== '') {
        $tdlWhatsAppNumber = $tdlCleanCandidate;
        break;
    }
}
$tdlPersonSearch = strtolower(implode(' ', [
    $tdlPersonName,
    $person['company_name'] ?? '',
    $person['scheme_name'] ?? '',
    $person['relation1'] ?? '',
    $person['relation2'] ?? '',
    $person['relation_all'] ?? ''
]));
?>
            <div
                class="line-item tdl-person-item"
                data-person-id="<?= $tdlPersonId ?>"
                data-person-name="<?= e($tdlPersonName) ?>"
                data-person-search="<?= e($tdlPersonSearch) ?>"
                data-person-main="<?= $tdlPersonMain ? '1' : '0' ?>"
                data-whatsapp="<?= e($tdlWhatsAppNumber) ?>"
            >
                <div class="tdl-person-head">
                    <label class="tdl-person-select-label" title="Select this member for WhatsApp">
                        <input
                            type="checkbox"
                            class="tdl-person-checkbox"
                            data-person-id="<?= $tdlPersonId ?>"
                            data-group-id="<?= $gid ?>"
                            data-name="<?= e($tdlPersonName) ?>"
                            data-main="<?= $tdlPersonMain ? '1' : '0' ?>"
                            data-whatsapp="<?= e($tdlWhatsAppNumber) ?>"
                            onchange="tdlSelectionChanged(this)"
                            <?= $tdlWhatsAppNumber === '' ? 'data-no-whatsapp="1"' : '' ?>
                        >
                        <span class="tdl-checkmark"></span>
                    </label>
<?php
$isMainPerson = strtolower(trim((string)($person['main'] ?? ''))) === 'main';
?>
                    <strong><?= e($person['name'] ?? '') ?></strong><?php if ($isMainPerson): ?><span style="color:#159957;font-weight:800;margin-left:5px;">(M)</span><?php endif; ?>
                    <?php if ($tdlWhatsAppNumber !== ''): ?>
                        <span class="tdl-wa-ready" title="WhatsApp number available">WA</span>
                    <?php else: ?>
                        <span class="tdl-wa-missing" title="No usable WhatsApp number">No WA</span>
                    <?php endif; ?>
                </div>
<?php
$personNumbers = array_values(array_filter([
    trim((string)($person['number1'] ?? '')),
    trim((string)($person['number2'] ?? '')),
    trim((string)($person['number3'] ?? ''))
], static function ($v) { return $v !== ''; }));
?>
<?php if (!empty($personNumbers)): ?>
                <div style="margin-top:3px;font-size:11px;line-height:1.35;">
<?php foreach ($personNumbers as $personNumber): ?>
<?php $waNumber = preg_replace('/[^0-9]/', '', (string)$personNumber); ?>
<?php if ($waNumber !== ''): ?>
                    <a href="https://wa.me/<?= e($waNumber) ?>" target="_blank" rel="noopener noreferrer" style="color:#159957;text-decoration:none;font-weight:700;display:inline-block;margin-right:7px;" title="Open WhatsApp"><?= e($personNumber) ?></a>
<?php else: ?>
                    <span style="display:inline-block;margin-right:7px;"><?= e($personNumber) ?></span>
<?php endif; ?>
<?php endforeach; ?>
                </div>
<?php endif; ?>
            </div>
<?php endforeach; ?>
        </div>
    </td>

    <td>
        <div class="line-list">
<?php if (!empty($group['main_persons'])): ?>
<?php foreach ($group['main_persons'] as $mainPerson): ?>
<?php
$mainMatch = null;
foreach (($group['persons'] ?? []) as $mainPersonRow) {
    if (
        strcasecmp(
            trim((string)($mainPersonRow['name'] ?? '')),
            trim((string)$mainPerson)
        ) === 0
        && trim((string)($mainPersonRow['main'] ?? '')) === 'main'
    ) {
        $mainMatch = $mainPersonRow;
        break;
    }
}

$mainNumbers = $mainMatch
    ? array_values(array_filter([
        trim((string)($mainMatch['number1'] ?? '')),
        trim((string)($mainMatch['number2'] ?? '')),
        trim((string)($mainMatch['number3'] ?? ''))
    ], static function ($v) {
        return $v !== '';
    }))
    : [];
?>
            <div class="line-item main">
                <strong><?= e($mainPerson) ?></strong>

<?php if (!empty($mainNumbers)): ?>
                <div style="margin-top:3px;font-size:11px;line-height:1.35;">
<?php foreach ($mainNumbers as $mainNumber): ?>
<?php $mainWaNumber = preg_replace('/[^0-9]/', '', (string)$mainNumber); ?>
<?php if ($mainWaNumber !== ''): ?>
                    <a
                        href="https://wa.me/<?= e($mainWaNumber) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="color:#159957;text-decoration:none;font-weight:700;display:inline-block;margin-right:7px;"
                        title="Open WhatsApp"
                    ><?= e($mainNumber) ?></a>
<?php else: ?>
                    <span style="display:inline-block;margin-right:7px;"><?= e($mainNumber) ?></span>
<?php endif; ?>
<?php endforeach; ?>
                </div>
<?php endif; ?>
            </div>
<?php endforeach; ?>
<?php else: ?>
            <div class="line-item">—</div>
<?php endif; ?>
        </div>
    </td>

    <td>
        <div class="area-list">
<?php foreach (preg_split('/\s*,\s*/', (string)($group['area'] ?? '')) as $oneArea): ?>
<?php if (trim($oneArea) !== ''): ?>
            <span class="area-badge"><?= e(ucwords(trim($oneArea))) ?></span>
<?php endif; ?>
<?php endforeach; ?>
        </div>
    </td>

    <td>
        <div class="actions">
            <form method="POST" style="display:inline">
                <input type="hidden" name="grp_id" value="<?= $gid ?>">
                <input type="hidden" name="star_action" value="<?= !empty($group['is_starred']) ? 'unstar' : 'star' ?>">
                <button type="submit" class="btn btn-star <?= !empty($group['is_starred']) ? 'starred' : '' ?>" title="<?= !empty($group['is_starred']) ? 'Remove star' : 'Star this group' ?>">
                    <?= !empty($group['is_starred']) ? '★ Starred' : '☆ Star' ?>
                </button>
            </form>
            <button type="button" class="btn btn-view" onclick="toggleDetails(<?= $gid ?>)">👁 View</button>
            <button type="button" class="btn btn-edit" onclick="toggleDetails(<?= $gid ?>, true)">✎ Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirmDeleteGroup(<?= $gid ?>);">
                <input type="hidden" name="grp_id" value="<?= $gid ?>">
                <button type="submit" name="delete_group" value="1" class="btn btn-delete">🗑 Delete</button>
            </form>
        </div>
    </td>
</tr>

<tr class="details-row" id="details-<?= $gid ?>">
    <td colspan="8">
        <div class="details-content" id="details-content-<?= $gid ?>">
            <div style="padding:22px;text-align:center;color:var(--muted)">Loading details…</div>
        </div>
    </td>
</tr>

<?php endforeach; ?>


<?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


<div class="footer">

    THE DIVINE LANDS
    •
    Group Management System
    •
    Core PHP + MySQL

</div>




<!-- =========================================================
     WHATSAPP CONTACT-BY-CONTACT MODAL
     ========================================================= -->
<div class="tdl-wa-modal" id="tdlWaModal" aria-hidden="true">
    <div class="tdl-wa-dialog" role="dialog" aria-modal="true" aria-labelledby="tdlWaModalTitle">
        <div class="tdl-wa-dialog-head">
            <strong id="tdlWaModalTitle">WhatsApp Contacts</strong>
            <button type="button" class="tdl-wa-close" onclick="tdlCloseWhatsApp()">×</button>
        </div>
        <div class="tdl-wa-dialog-body">
            <div class="tdl-wa-progress"><div id="tdlWaProgressBar"></div></div>
            <div id="tdlWaContactCard" class="tdl-wa-contact"></div>
            <div id="tdlWaCampaignStatus" style="margin:10px 0;padding:9px 11px;border-radius:9px;background:#f4f7fa;color:var(--navy);font-size:12px;font-weight:800;">Campaign ready</div>
            <div class="tdl-wa-dialog-actions">
                <button type="button" class="tdl-wa-open-btn" id="tdlWaOpenBtn" onclick="tdlOpenCurrentWhatsApp()">💬 Open WhatsApp</button>
                <button type="button" class="tdl-wa-next-btn" id="tdlWaNextBtn" onclick="tdlNextWhatsApp()">Sent / Next Contact →</button>
                <button type="button" class="tdl-wa-skip-btn" id="tdlWaSkipBtn" onclick="tdlSkipWhatsApp()">Skip</button>
                <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;width:100%;padding:10px;border:1px solid #dce8e1;border-radius:10px;background:#f7fcf8;">
                    <label for="tdlWaClientStatus" style="font-size:12px;font-weight:800;color:var(--navy);">Client status</label>
                    <select id="tdlWaClientStatus" style="min-height:40px;flex:1;min-width:170px;border:1px solid #d7dee8;border-radius:9px;padding:0 10px;background:#fff;">
                        <option>New</option><option>Contacted</option><option>Interested</option><option>Requirement Received</option><option>Property Shared</option><option>Site Visit Planned</option><option>Site Visit Done</option><option>Negotiation</option><option>Follow-up</option><option>On Hold</option><option>Converted</option><option>Not Interested</option><option>Closed</option>
                    </select>
                    <button type="button" class="tdl-wa-next-btn" id="tdlWaAddClientBtn" onclick="tdlAddCurrentContactToClientHandling()">＋ Add to Client Handling</button>
                </div>
                <button type="button" class="tdl-wa-skip-btn" id="tdlWaPauseBtn" onclick="tdlPauseWhatsApp()">⏸ Pause Campaign</button>
                <button type="button" class="tdl-wa-next-btn" id="tdlWaResumeBtn" onclick="tdlResumeWhatsApp()" style="display:none;">▶ Resume Campaign</button>
                <button type="button" class="tdl-wa-skip-btn" id="tdlWaStopBtn" onclick="tdlStopWhatsApp()">■ Stop Campaign</button>
            </div>
            <div class="tdl-wa-dialog-note">
                Each WhatsApp contact opens only after your click. <b>Sent / Next Contact</b> advances the campaign; <b>Skip</b> leaves the contact uncompleted. Pause keeps the current campaign position so you can resume it later.
            </div>
        </div>
    </div>
</div>

</main>


<script>

/*
 * Full area list is transferred to the browser ONCE.
 * Edit forms build their checkboxes only when opened.
 * This removes the largest repeated HTML block from initial page load.
 */
window.tdlAreaOptions = <?= json_encode(array_values($area_options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;


/* =========================================================
   LIVE GROUP SEARCH + HIGHLIGHT
   Runs immediately while typing — NO PAGE REFRESH.
   ========================================================= */

function tdlNormalize(value) {
    return String(value == null ? '' : value)
        .toLowerCase()
        .normalize('NFKC')
        .replace(/[\u200B-\u200D\uFEFF]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function tdlEscapeRegex(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function tdlGetRows() {
    return document.querySelectorAll(
        '#groupsTable tbody tr.group-row'
    );
}

/* Cache static row data so typing does not repeatedly read/normalize large DOM nodes. */
var tdlRowSearchCache = new WeakMap();

function tdlGetRowMeta(row) {
    var cached = tdlRowSearchCache.get(row);
    if (cached) return cached;

    var text = row.textContent || '';
    var extra = row.getAttribute('data-search') || '';
    var area = row.getAttribute('data-area') || '';

    cached = {
        searchText: tdlNormalize(text + ' ' + extra + ' ' + area),
        areaText: tdlNormalize(area),
        areaList: area.split(',').map(function(oneArea) {
            return tdlNormalize(oneArea).trim();
        }).filter(Boolean)
    };

    tdlRowSearchCache.set(row, cached);
    return cached;
}

function tdlGetRowSearchText(row) {
    return tdlGetRowMeta(row).searchText;
}

function tdlRowMatches(row, query) {
    if (!query) {
        return true;
    }

    var text = tdlGetRowSearchText(row);

    /* "tarun patel" = both words can occur anywhere. */
    return query.split(' ').every(function(word) {
        if (!word) return true;

        return text.indexOf(word) !== -1 ||
               text.replace(/[^a-z0-9]/g, '')
                   .indexOf(word.replace(/[^a-z0-9]/g, '')) !== -1;
    });
}

function tdlClearHighlights() {
    document
        .querySelectorAll('#groupsTable mark.tdl-live-hit')
        .forEach(function(mark) {
            mark.replaceWith(
                document.createTextNode(mark.textContent)
            );
        });
}

function tdlHighlightWord(row, word) {
    if (!word) return;

    var regex = new RegExp(
        tdlEscapeRegex(word),
        'gi'
    );

    var walker = document.createTreeWalker(
        row,
        NodeFilter.SHOW_TEXT
    );

    var nodes = [];
    var node;

    while ((node = walker.nextNode())) {
        var parent = node.parentElement;

        if (!parent) continue;

        /* Never touch controls, buttons or existing marks. */
        if (
            parent.closest(
                'input, textarea, select, option, button, script, style, mark'
            )
        ) {
            continue;
        }

        regex.lastIndex = 0;

        if (regex.test(node.nodeValue)) {
            nodes.push(node);
        }
    }

    nodes.forEach(function(textNode) {
        var text = textNode.nodeValue;
        var fragment = document.createDocumentFragment();
        var last = 0;
        var match;

        regex.lastIndex = 0;

        while ((match = regex.exec(text)) !== null) {
            if (match.index > last) {
                fragment.appendChild(
                    document.createTextNode(
                        text.slice(last, match.index)
                    )
                );
            }

            var mark = document.createElement('mark');
            mark.className = 'tdl-live-hit';
            mark.textContent = match[0];

            fragment.appendChild(mark);

            last = match.index + match[0].length;
        }

        if (last < text.length) {
            fragment.appendChild(
                document.createTextNode(text.slice(last))
            );
        }

        textNode.parentNode.replaceChild(
            fragment,
            textNode
        );
    });
}

function tdlApplyHighlights(query) {
    tdlClearHighlights();

    query = tdlNormalize(query);

    if (!query) return;

    var words = query.split(' ').filter(Boolean);

    tdlGetRows().forEach(function(row) {
        if (row.style.display === 'none') return;

        words.forEach(function(word) {
            tdlHighlightWord(row, word);
        });
    });
}

function tdlGetSelectedAreas() {
    var checks = document.querySelectorAll('#areaSelectMenu input[name="area_filter[]"]:checked');
    return Array.from(checks).map(function(check) {
        return tdlNormalize(check.value || '');
    }).filter(Boolean);
}

function tdlUpdateAreaButton() {
    var button = document.getElementById('areaSelectButton');
    if (!button) return;

    var selected = tdlGetSelectedAreas();
    var label = selected.length
        ? (selected.length + ' Area' + (selected.length > 1 ? 's' : '') + ' Selected')
        : '📍 Select Area';

    button.querySelector('span:first-child').textContent = label;
}

function tdlToggleAreaMenu(event) {
    if (event) event.stopPropagation();
    var wrap = document.getElementById('areaSelectWrap');
    if (wrap) wrap.classList.toggle('open');
}

function tdlClearAreaFilter(event) {
    if (event) event.stopPropagation();

    document.querySelectorAll('#areaSelectMenu input[name="area_filter[]"]').forEach(function(check) {
        check.checked = false;
    });

    tdlUpdateAreaButton();
    tdlLiveSearch();
}

function tdlAreaSelectionChanged() {
    tdlUpdateAreaButton();
    tdlLiveSearch();
}

function tdlLiveSearch() {
    var input = document.getElementById('groupSearch');
    var query = input ? input.value : '';
    var selectedAreas = tdlGetSelectedAreas();

    var visible = 0;
    var selectedPersonFilterActive = !!tdlShowSelectedPersonsOnly;
    var selectedGroupFilterActive = !!tdlShowSelectedGroupsOnly;
    var memberFilterActive =
        tdlGetWaFilter() !== 'all' ||
        tdlGetPersonTypeFilter() !== 'all';

    tdlGetRows().forEach(function(row) {
        var searchOK = tdlRowMatches(row, query);

        var rowArea = row.getAttribute('data-area') || '';
        var rowAreas = rowArea.split(',').map(function(oneArea) {
            return tdlNormalize(oneArea).trim();
        }).filter(Boolean);

        var areaOK =
            selectedAreas.length === 0 ||
            selectedAreas.some(function(selectedArea) {
                return rowAreas.indexOf(selectedArea) !== -1;
            });

        var personItems = row.querySelectorAll('.tdl-person-item');
        var anyPersonPassesFilters = false;
        var anySelectedPerson = false;

        personItems.forEach(function(item) {
            var cb = item.querySelector('.tdl-person-checkbox');
            if (!cb) return;

            var isSelected = !!cb.checked;
            if (isSelected) anySelectedPerson = true;

            var selectedOK = !selectedPersonFilterActive || isSelected;
            var waOK = tdlPersonPassesWhatsAppFilter(item);
            var typeOK = tdlPersonPassesTypeFilter(item);
            var personOK = selectedOK && waOK && typeOK;

            if (personOK) {
                anyPersonPassesFilters = true;
            }

            item.classList.toggle('tdl-person-hidden', !personOK);
        });

        /*
         * Show Selected Groups means: show the group when at least one
         * person in that group is selected. It must work independently
         * of the person-type / WhatsApp filters.
         */
        var selectedGroupOK =
            !selectedGroupFilterActive || anySelectedPerson;

        /*
         * Only Selected Persons means: the group remains visible only
         * when it contains at least one selected person. Unselected
         * person rows are hidden above.
         */
        var selectedPersonOK =
            !selectedPersonFilterActive || anySelectedPerson;

        /* Existing member filters continue to work as before. */
        var memberFilterOK =
            !memberFilterActive || anyPersonPassesFilters;

        var show =
            searchOK &&
            areaOK &&
            selectedGroupOK &&
            selectedPersonOK &&
            memberFilterOK;

        row.style.display = show ? 'table-row' : 'none';

        if (show) {
            visible++;
        } else {
            var details = row.nextElementSibling;

            if (
                details &&
                details.classList.contains('details-row')
            ) {
                details.classList.remove('open');
            }
        }
    });

    var total = document.getElementById('totalGroups');

    if (total) {
        total.textContent = visible;
    }

    /*
     * Highlight in the SAME event cycle.
     * No refresh, no AJAX, no GET request.
     */
    tdlApplyHighlights(query);

    tdlUpdateSelectionUI();
    tdlUpdateWhatsAppStatus();
}

/* =========================================================
   GROUP COLUMN SORT
   Sorting is handled by PHP/SQL so it cannot interfere with
   any other JavaScript functionality on this page.
   ========================================================= */

/* =========================================================
   LAZY GROUP DETAILS
   Details are NOT rendered during initial page load.
   They are fetched only when View/Edit is clicked.
   ========================================================= */

var tdlDetailsCache = {};

function tdlEscapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function tdlWaHtml(number) {
    var raw = String(number == null ? '' : number).trim();
    if (!raw) return '';
    var wa = raw.replace(/[^0-9]/g, '');
    if (!wa) return '<span>' + tdlEscapeHtml(raw) + '</span>';
    return '<a href="https://wa.me/' + encodeURIComponent(wa) + '" target="_blank" rel="noopener noreferrer" style="color:#159957;text-decoration:none;font-weight:700;display:inline-block;margin-right:7px" title="Open WhatsApp">' + tdlEscapeHtml(raw) + '</a>';
}

function tdlNumbersHtml(person) {
    var numbers = [person.number1, person.number2, person.number3].filter(function(v){ return String(v || '').trim() !== ''; });
    if (!numbers.length) return '';
    return '<div style="margin-top:3px;font-size:11px;line-height:1.35">' + numbers.map(tdlWaHtml).join('') + '</div>';
}

function tdlBuildDetailsHtml(data, editMode) {
    var gid = Number(data.group.grp_id);
    var group = data.group;
    var persons = data.persons || [];
    var mainPersonNames = (data.main_persons || []).map(function(v){
        return String(v == null ? '' : v).trim().toLowerCase();
    }).filter(Boolean);
    var areas = String(group.area || '').split(',').map(function(v){ return v.trim().toLowerCase(); }).filter(Boolean);

    var html = '';
    html += '<div class="details-toolbar">';
    html += '<div class="details-toolbar-title"><span class="details-toolbar-icon">◆</span><div><strong>Group Details</strong><small>View, edit people, or update group information</small></div></div>';
    html += '<button type="button" class="btn btn-close-details" onclick="closeDetails(' + gid + ')">✕ Close</button>';
    html += '</div>';

    html += '<div class="details-panel" id="group-edit-' + gid + '" style="display:' + (editMode ? 'block' : 'none') + '">';
    html += '<div class="panel-title">✎ Edit Group #' + gid + '</div><div class="panel-body">';
    html += '<form method="POST" id="group-save-form-' + gid + '" onsubmit="return prepareGroupSave(' + gid + ', this);">';
    html += '<input type="hidden" name="grp_id" value="' + gid + '">';
    html += '<div class="group-edit">';
    html += '<div class="field"><label>Company Name</label><input type="text" name="company_name" value="' + tdlEscapeHtml(group.company_name || '') + '" required></div>';
    html += '<div class="field"><label>Scheme Name</label><input type="text" name="scheme_name" value="' + tdlEscapeHtml(group.scheme_name || '') + '" required></div>';
    html += '<div class="area-checks">';
    (window.tdlAreaOptions || []).forEach(function(area, index) {
        var checked = areas.indexOf(String(area).trim().toLowerCase()) !== -1 ? ' checked' : '';
        var id = 'edit_' + gid + '_' + index;
        html += '<div class="area-check"><input type="checkbox" id="' + id + '" name="areas[]" value="' + tdlEscapeHtml(area) + '"' + checked + '><label for="' + id + '">' + tdlEscapeHtml(String(area).replace(/\b\w/g,function(c){return c.toUpperCase();})) + '</label></div>';
    });
    html += '</div></div>';
    html += '<div style="margin-top:15px;display:flex;gap:8px"><button type="submit" name="update_group" value="1" class="btn btn-save">✓ Save Group</button><button type="button" class="btn btn-cancel" onclick="toggleEdit(' + gid + ', false)">Cancel</button><button type="button" class="btn btn-close-details" onclick="closeDetails(' + gid + ')">✕ Close Details</button></div>';
    html += '</form></div></div>';

    html += '<div class="details-panel" style="margin-top:18px"><div class="panel-title">👥 All Persons — ' + persons.length + '</div><div class="panel-body">';
    persons.forEach(function(p) {
        var pid = Number(p.id);
        html += '<form id="person-form-' + pid + '" method="POST" style="display:none"><input type="hidden" name="id" value="' + pid + '"><input type="hidden" name="grp_id" value="' + gid + '"></form>';
    });
    html += '<div class="person-table-wrap"><table class="person-table"><thead><tr><th>#</th><th>Name</th><th>Number 1</th><th>Number 2</th><th>Number 3</th><th>Relation 1</th><th>Relation 2</th><th>All Relation</th><th>Main</th><th>Action</th></tr></thead><tbody>';
    persons.forEach(function(p, i) {
        var pid = Number(p.id);
        var mainValue = String(p.main == null ? '' : p.main).trim().toLowerCase();
        var personNameKey = String(p.name == null ? '' : p.name).trim().toLowerCase();
        var main = (mainValue === 'main' || mainValue === '1' || mainValue === 'yes' || mainValue === 'true' || mainPersonNames.indexOf(personNameKey) !== -1);
        html += '<tr class="' + (main ? 'main-person-row' : '') + '" data-person-id="' + pid + '">';
        html += '<td>' + (i + 1) + '</td>';
        [['name',p.name],['number1',p.number1],['number2',p.number2],['number3',p.number3],['relation1',p.relation1],['relation2',p.relation2]].forEach(function(pair){
            if (pair[0] === 'name') {
                html += '<td><div style="display:flex;align-items:center;gap:6px;">' +
                    '<input class="person-input person-name" type="text" name="name" data-field="name" form="person-form-' + pid + '" value="' + tdlEscapeHtml(pair[1] || '') + '" required>' +
                    (main ? '<span data-main-badge="1" style="display:inline-block;color:#159957;font-weight:800;font-size:13px;white-space:nowrap;">(M)</span>' : '') +
                    '</div></td>';
            } else {
                html += '<td><input class="person-input" type="text" name="' + pair[0] + '" data-field="' + pair[0] + '" form="person-form-' + pid + '" value="' + tdlEscapeHtml(pair[1] || '') + '"></td>';
            }
        });
        html += '<td><input class="person-input" type="text" value="' + tdlEscapeHtml(p.relation_all || '') + '" readonly></td>';
        html += '<td><select name="main" data-field="main" form="person-form-' + pid + '" class="main-select"><option value=""' + (!main ? ' selected' : '') + '>—</option><option value="main"' + (main ? ' selected' : '') + '>Main</option></select></td>';
        html += '<td><button type="submit" name="update_person" value="1" form="person-form-' + pid + '" class="btn btn-save">Save</button> <button type="submit" name="delete_person" value="1" form="person-form-' + pid + '" class="btn btn-delete" onclick="return confirm(\'Delete this person from Group #' + gid + '?\');">Delete</button></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    html += '<div class="add-person"><div style="font-weight:800;color:var(--navy);font-size:13px;margin-bottom:11px">+ Add Person to Group #' + gid + '</div>';
    html += '<form method="POST"><input type="hidden" name="grp_id" value="' + gid + '"><div class="add-person-grid"><input type="text" name="new_name" placeholder="Name" required><input type="text" name="new_number1" placeholder="Number 1"><input type="text" name="new_number2" placeholder="Number 2"><input type="text" name="new_number3" placeholder="Number 3"><input type="text" name="new_relation1" placeholder="Relation 1"><input type="text" name="new_relation2" placeholder="Relation 2"><select name="new_main"><option value="">Not Main</option><option value="main">Main</option></select></div><div style="margin-top:10px"><button type="submit" name="add_person" value="1" class="btn btn-add">+ Add Person</button></div></form></div></div></div>';

    html += '<div class="details-grid" style="margin-top:18px"><div class="details-panel"><div class="panel-title">★ Main Persons</div><div class="panel-body">';
    if (data.main_persons && data.main_persons.length) {
        html += '<div class="line-list">' + data.main_persons.map(function(n){ return '<div class="line-item main">★ ' + tdlEscapeHtml(n) + '</div>'; }).join('') + '</div>';
    } else html += '<div style="color:var(--muted)">No main person selected.</div>';
    html += '</div></div><div class="details-panel"><div class="panel-title">🔗 All Relation</div><div class="panel-body">';
    if (data.relations && data.relations.length) html += '<div class="line-list">' + data.relations.map(function(n){ return '<div class="line-item relation-item">' + tdlEscapeHtml(n) + '</div>'; }).join('') + '</div>'; else html += '<div style="color:var(--muted)">No relation history.</div>';
    html += '</div></div></div>';

    return html;
}

function toggleDetails(grpId, editMode) {
    var details = document.getElementById('details-' + grpId);
    var content = document.getElementById('details-content-' + grpId);
    if (!details || !content) return;

    if (details.classList.contains('open')) {
        closeDetails(grpId);
        return;
    }

    details.classList.add('open');
    content.innerHTML = '<div style="padding:22px;text-align:center;color:var(--muted)">Loading details…</div>';

    if (tdlDetailsCache[grpId]) {
        content.innerHTML = tdlBuildDetailsHtml(tdlDetailsCache[grpId], !!editMode);
        tdlSyncMainBadges(details);
        return;
    }

    fetch(window.location.pathname + '?ajax_group=' + encodeURIComponent(grpId), {cache:'no-store'})
        .then(function(response){ if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
        .then(function(data){
            if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Unable to load group');
            tdlDetailsCache[grpId] = data;
            if (details.classList.contains('open')) {
                content.innerHTML = tdlBuildDetailsHtml(data, !!editMode);
                tdlSyncMainBadges(details);
            }
        })
        .catch(function(error){
            content.innerHTML = '<div style="padding:22px;color:#b42318">Unable to load details. Please try again.</div>';
            console.error(error);
        });
}

function tdlSyncMainBadges(details) {
    if (!details) return;

    details.querySelectorAll('tr[data-person-id]').forEach(function(row) {
        var select = row.querySelector('select.main-select');
        var badge = row.querySelector('[data-main-badge="1"]');
        if (!select || !badge) return;

        var value = String(select.value || '').trim().toLowerCase();
        var isMain = (value === 'main' || value === '1' || value === 'yes' || value === 'true');
        badge.style.display = isMain ? 'inline-block' : 'none';
    });
}


function closeDetails(grpId) {
    var details = document.getElementById('details-' + grpId);
    if (!details) return;
    details.classList.remove('open');
}

function toggleEdit(grpId, show) {
    var edit = document.getElementById('group-edit-' + grpId);
    if (edit) edit.style.display = show ? 'block' : 'none';
}


/* =========================================================
   DELETE GROUP
   ========================================================= */

function confirmDeleteGroup(
    grpId
)
{

    return confirm(
        'DELETE GROUP #' +
        grpId +
        '?\n\n' +
        'This will permanently delete all persons and area records belonging to this group.'
    );

}


/* =========================================================
   AUTO HIDE ALERT
   ========================================================= */

setTimeout(
    function()
    {

        document
            .querySelectorAll(
                '.alert'
            )
            .forEach(
                function(item)
                {

                    item.style.transition =
                        'opacity .5s';

                    item.style.opacity =
                        '0';

                    setTimeout(
                        function()
                        {
                            item.remove();
                        },
                        500
                    );

                }
            );

    },
    4500
);


/* =========================================================
   GROUP SAVE: SERIALIZE ALL PERSON ROWS
   ========================================================= */
function prepareGroupSave(gid, form)
{
    if (!form) {
        return false;
    }

    form.querySelectorAll('.bulk-person-field').forEach(function(field) {
        field.remove();
    });

    var details = document.getElementById('details-' + gid);

    if (!details) {
        alert('Group details not found.');
        return false;
    }

    details.querySelectorAll('tr[data-person-id]').forEach(function(row) {
        var personId = row.getAttribute('data-person-id');
        if (!personId) return;

        row.querySelectorAll('[data-field]').forEach(function(field) {
            var hidden = document.createElement('input');

            hidden.type = 'hidden';
            hidden.className = 'bulk-person-field';
            hidden.name =
                'persons[' + personId + '][' +
                field.getAttribute('data-field') + ']';
            hidden.value = field.value || '';

            form.appendChild(hidden);
        });
    });

    /* TRUE = perform the normal PHP POST. */
    return true;
}




/* =========================================================
   SAVED MEMBER GROUPS
   Permanent database-backed saved selections.
   This replaces ONLY the old localStorage member-group storage.
   ========================================================= */
var tdlMemberGroups = [];
var tdlSelectedMemberGroupId = '';
var tdlShowSelectedGroupsOnly = false;
var tdlShowSelectedPersonsOnly = false;

function tdlGroupHasSelectedPerson(row) {
    if (!row) return false;
    return Array.from(row.querySelectorAll('.tdl-person-checkbox')).some(function(cb) { return cb.checked; });
}

function tdlUpdateShowSelectedGroupsButton() {
    var btn = document.getElementById('tdlShowSelectedGroupsBtn');
    if (!btn) return;
    btn.textContent = tdlShowSelectedGroupsOnly ? '👁 Showing Selected Groups' : '👁 Show Selected Groups';
    btn.classList.toggle('tdl-wa-btn-green', tdlShowSelectedGroupsOnly);
    btn.classList.toggle('tdl-wa-btn-light', !tdlShowSelectedGroupsOnly);
}
function tdlToggleShowSelectedGroups() {
    tdlShowSelectedGroupsOnly = !tdlShowSelectedGroupsOnly;
    tdlUpdateShowSelectedGroupsButton();
    tdlLiveSearch();
}
function tdlUpdateShowSelectedPersonsButton() {
    var btn = document.getElementById('tdlShowSelectedPersonsBtn');
    if (!btn) return;
    btn.textContent = tdlShowSelectedPersonsOnly ? '👤 Showing Selected Persons' : '👤 Only Selected Persons';
    btn.classList.toggle('tdl-wa-btn-green', tdlShowSelectedPersonsOnly);
    btn.classList.toggle('tdl-wa-btn-light', !tdlShowSelectedPersonsOnly);
}
function tdlToggleShowSelectedPersons() {
    tdlShowSelectedPersonsOnly = !tdlShowSelectedPersonsOnly;
    tdlUpdateShowSelectedPersonsButton();
    tdlLiveSearch();
}
function tdlGetSelectedPersonIds() {
    return tdlGetPersonCheckboxes().filter(function(cb){ return cb.checked; }).map(function(cb){ return String(cb.dataset.personId || '').trim(); }).filter(Boolean);
}
function tdlMemberGroupRequest(action, options) {
    options = options || {};
    var url = window.location.pathname + '?member_group_action=' + encodeURIComponent(action);
    if (options.query) Object.keys(options.query).forEach(function(key){ url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(options.query[key]); });
    var fetchOptions = {method:options.method || 'GET', credentials:'same-origin'};
    if (options.method === 'POST') {
        fetchOptions.headers = {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'};
        var params = new URLSearchParams();
        Object.keys(options.data || {}).forEach(function(key){
            var value = options.data[key];
            if (Array.isArray(value)) value.forEach(function(item){ params.append(key + '[]', item); });
            else params.append(key, value == null ? '' : value);
        });
        fetchOptions.body = params.toString();
    }
    return fetch(url, fetchOptions).then(function(response){
        return response.text().then(function(text){
            var data;
            try { data = JSON.parse(text); } catch(e) { throw new Error('Invalid server response while saving member groups.'); }
            if (!response.ok || !data.ok) { var error = new Error(data.error || 'Unable to complete member group request.'); error.data=data; throw error; }
            return data;
        });
    });
}
function tdlRefreshMemberGroupSelect(preferredId) {
    var select = document.getElementById('tdlMemberGroupSelect');
    if (!select) return;
    var current = preferredId != null ? String(preferredId) : String(select.value || tdlSelectedMemberGroupId || '');
    select.innerHTML = '<option value="">Select Saved Group</option>';
    tdlMemberGroups.forEach(function(group){
        var option=document.createElement('option'); option.value=String(group.id); option.textContent=group.group_name+' ('+Number(group.member_count||0)+')'; select.appendChild(option);
    });
    if (current) { select.value=current; if (select.value===current) tdlSelectedMemberGroupId=current; }
}
function tdlUpdateMemberGroupStatus(message) {
    var status=document.getElementById('tdlMemberGroupStatus'); if(!status) return;
    var selected=tdlGetSelectedPersonIds().length;
    status.innerHTML=(message ? tdlEscapeHtml(message)+' ' : '')+'<span class="tdl-member-group-count">'+tdlMemberGroups.length+' saved group'+(tdlMemberGroups.length===1?'':'s')+' • '+selected+' currently selected</span>';
}
function tdlLoadMemberGroupList(preferredId) {
    return tdlMemberGroupRequest('list').then(function(data){
        tdlMemberGroups=Array.isArray(data.groups)?data.groups:[];
        tdlRefreshMemberGroupSelect(preferredId||''); tdlUpdateMemberGroupStatus(); return tdlMemberGroups;
    }).catch(function(error){ console.error(error); tdlUpdateMemberGroupStatus('Unable to load saved member groups.'); return []; });
}
function tdlSaveSelectedMemberGroup() {
    var input=document.getElementById('tdlMemberGroupName');
    var name=input?String(input.value||'').trim():''; var ids=tdlGetSelectedPersonIds();
    if(!name){alert('Please enter a group name.');if(input)input.focus();return;}
    if(!ids.length){alert('Please select at least one person first.');return;}
    var existing=tdlMemberGroups.find(function(group){return String(group.group_name||'').toLowerCase()===name.toLowerCase();});
    if(existing && !confirm('The group "'+existing.group_name+'" already exists. Replace its selected people with the current selection?')) return;
    if(!existing && !confirm('Are you sure you want to save the new group "'+name+'" with '+ids.length+' selected people?')) return;
    var data={group_name:existing?existing.group_name:name,members:ids}; if(existing)data.id=existing.id;
    tdlMemberGroupRequest(existing?'update':'create',{method:'POST',data:data}).then(function(result){
        if(input)input.value=''; tdlSelectedMemberGroupId=String(result.id||(existing?existing.id:'')); return tdlLoadMemberGroupList(tdlSelectedMemberGroupId);
    }).then(function(){tdlUpdateMemberGroupStatus('Saved "'+(existing?existing.group_name:name)+'" with '+ids.length+' people.');}).catch(function(error){alert(error.message||'Unable to save the member group.');});
}
function tdlLoadSelectedMemberGroup() {
    var select=document.getElementById('tdlMemberGroupSelect'); var id=select?String(select.value||''):''; tdlSelectedMemberGroupId=id;
    if(!id){tdlUpdateMemberGroupStatus('Group choice cleared. Current person selection was not changed.');return;}
    tdlMemberGroupRequest('load',{query:{id:id}}).then(function(data){
        var savedIds=Array.isArray(data.group.members)?data.group.members.map(String):[]; var savedSet=new Set(savedIds); var found=0;
        tdlGetPersonCheckboxes().forEach(function(cb){var personId=String(cb.dataset.personId||'');var shouldCheck=savedSet.has(personId);cb.checked=shouldCheck;if(shouldCheck)found++;});
        tdlUpdateSelectionUI(); tdlUpdateWhatsAppStatus(); tdlUpdateMemberGroupStatus('Loaded "'+data.group.group_name+'" — '+found+' people selected on this page.');
        if(tdlShowSelectedGroupsOnly||tdlShowSelectedPersonsOnly)tdlLiveSearch();
    }).catch(function(error){alert(error.message||'Unable to load the saved group.');});
}
function tdlUpdateSelectedMemberGroup() {
    var select=document.getElementById('tdlMemberGroupSelect'); var id=select?String(select.value||tdlSelectedMemberGroupId||''):String(tdlSelectedMemberGroupId||''); var ids=tdlGetSelectedPersonIds();
    if(!id){alert('Please select a saved group first.');return;} if(!ids.length){alert('Please select at least one person first.');return;}
    var group=tdlMemberGroups.find(function(item){return String(item.id)===id;}); var name=group?group.group_name:'this group';
    if(!confirm('Are you sure you want to save changes to "'+name+'"?\n\nThis will replace the saved group members with the '+ids.length+' currently selected people.')) return;
    tdlMemberGroupRequest('update',{method:'POST',data:{id:id,group_name:name,members:ids}}).then(function(result){tdlSelectedMemberGroupId=id;return tdlLoadMemberGroupList(id).then(function(){tdlUpdateMemberGroupStatus('"'+(result.group_name||name)+'" updated with '+ids.length+' people.');});}).catch(function(error){alert(error.message||'Unable to update the saved group.');});
}
function tdlDeleteSelectedMemberGroup() {
    var select=document.getElementById('tdlMemberGroupSelect'); var id=select?String(select.value||''):''; if(!id){alert('Please select a saved group first.');return;}
    var group=tdlMemberGroups.find(function(item){return String(item.id)===id;}); var name=group?group.group_name:'this group';
    if(!confirm('Delete saved group "'+name+'"? This does not delete any people.'))return;
    tdlMemberGroupRequest('delete',{method:'POST',data:{id:id}}).then(function(){tdlSelectedMemberGroupId='';return tdlLoadMemberGroupList('');}).then(function(){tdlUpdateMemberGroupStatus('Saved group deleted.');}).catch(function(error){alert(error.message||'Unable to delete the saved group.');});
}
function tdlClearSavedMemberGroupSelection() { var select=document.getElementById('tdlMemberGroupSelect'); if(select)select.value=''; tdlSelectedMemberGroupId=''; tdlUpdateMemberGroupStatus('Group choice cleared. Current person selection was not changed.'); }
function tdlInitMemberGroups() { tdlUpdateShowSelectedGroupsButton(); tdlUpdateShowSelectedPersonsButton(); tdlUpdateMemberGroupStatus('Loading saved groups...'); tdlLoadMemberGroupList(); }

/* =========================================================
   WHATSAPP MEMBER SELECTION + CONTACT-BY-CONTACT WORKFLOW
   ========================================================= */
var tdlWhatsAppQueue = [];
var tdlWhatsAppIndex = 0;
var tdlCurrentWhatsAppUrl = '';
var tdlWhatsAppCampaignStatus = 'idle'; // idle, running, paused, completed, stopped
var tdlWhatsAppOpenedCurrent = false;
var TDL_WA_CAMPAIGN_KEY = 'tdl_whatsapp_campaign_v2';

function tdlGetPersonCheckboxes() {
    return Array.from(document.querySelectorAll('.tdl-person-checkbox'));
}

function tdlGetVisiblePersonItems() {
    return Array.from(document.querySelectorAll('.tdl-person-item')).filter(function(item) {
        return item.offsetParent !== null && !item.classList.contains('tdl-person-hidden');
    });
}

function tdlSelectionChanged() {
    tdlUpdateSelectionUI();
    tdlUpdateWhatsAppStatus();
    tdlUpdateMemberGroupStatus();
    if (tdlShowSelectedGroupsOnly || tdlShowSelectedPersonsOnly) tdlLiveSearch();
}

function tdlUpdateSelectionUI() {
    var selected = tdlGetPersonCheckboxes().filter(function(cb){ return cb.checked; });
    var count = document.getElementById('tdlSelectedCount');
    if (count) count.textContent = selected.length;

    tdlGetPersonCheckboxes().forEach(function(cb) {
        var item = cb.closest('.tdl-person-item');
        if (item) item.classList.toggle('tdl-person-selected', cb.checked);
    });
}

function tdlUpdateWhatsAppStatus() {
    var status = document.getElementById('tdlWhatsAppStatus');
    if (!status) return;
    var selected = tdlGetPersonCheckboxes().filter(function(cb){ return cb.checked; });
    var usable = selected.filter(function(cb){ return String(cb.dataset.whatsapp || '').trim() !== ''; });
    if (!selected.length) {
        status.textContent = 'Select members from the table above.';
        return;
    }
    if (usable.length !== selected.length) {
        status.textContent = selected.length + ' selected • ' + usable.length + ' have a usable WhatsApp number. Contacts without a number will be skipped.';
    } else {
        status.textContent = selected.length + ' member' + (selected.length === 1 ? '' : 's') + ' ready for WhatsApp.';
    }
}

function tdlSelectAllVisible() {
    var items = tdlGetVisiblePersonItems();
    items.forEach(function(item) {
        var cb = item.querySelector('.tdl-person-checkbox');
        if (cb && String(cb.dataset.whatsapp || '').trim() !== '') cb.checked = true;
    });
    tdlUpdateSelectionUI();
    tdlUpdateWhatsAppStatus();
    tdlUpdateMemberGroupStatus();
    if (tdlShowSelectedGroupsOnly || tdlShowSelectedPersonsOnly) tdlLiveSearch();
}

function tdlClearSelection() {
    tdlGetPersonCheckboxes().forEach(function(cb){ cb.checked = false; });
    tdlUpdateSelectionUI();
    tdlUpdateWhatsAppStatus();
    tdlUpdateMemberGroupStatus();
    if (tdlShowSelectedGroupsOnly || tdlShowSelectedPersonsOnly) tdlLiveSearch();
}

function tdlGetWaFilter() {
    var el = document.getElementById('tdlWaFilter');
    return el ? el.value : 'all';
}

function tdlGetPersonTypeFilter() {
    var el = document.getElementById('tdlPersonTypeFilter');
    return el ? el.value : 'all';
}

function tdlPersonPassesWhatsAppFilter(item) {
    var cb = item.querySelector('.tdl-person-checkbox');
    if (!cb) return false;
    var filter = tdlGetWaFilter();
    var hasWa = String(cb.dataset.whatsapp || '').trim() !== '';
    var selected = !!cb.checked;
    if (filter === 'with_whatsapp') return hasWa;
    if (filter === 'without_whatsapp') return !hasWa;
    if (filter === 'selected') return selected;
    if (filter === 'unselected') return !selected;
    return true;
}

function tdlPersonPassesTypeFilter(item) {
    var filter = tdlGetPersonTypeFilter();
    var cb = item.querySelector('.tdl-person-checkbox');
    if (!cb) return false;
    var isMain = cb.dataset.main === '1';
    if (filter === 'main') return isMain;
    if (filter === 'non_main') return !isMain;
    return true;
}

/* Debounced search uses the single live-search implementation above. */
var tdlSearchDebounceTimer = null;

function tdlLiveSearchDebounced() {
    clearTimeout(tdlSearchDebounceTimer);

    tdlSearchDebounceTimer = setTimeout(function() {
        tdlLiveSearch();
    }, 180);
}

function tdlBuildWhatsAppQueue() {
    return tdlGetPersonCheckboxes().filter(function(cb) {
        return cb.checked && String(cb.dataset.whatsapp || '').trim() !== '';
    }).map(function(cb) {
        var item = cb.closest('.tdl-person-item');
        var row = cb.closest('tr.group-row');
        var gid = cb.dataset.groupId || (row ? row.dataset.groupId : '');
        var name = cb.dataset.name || 'Contact';
        var number = String(cb.dataset.whatsapp || '').trim();
        var company = row ? ((row.querySelector('.company') || {}).textContent || '').trim() : '';
        var scheme = row ? ((row.querySelector('.scheme') || {}).textContent || '').trim() : '';
        var area = row ? (row.dataset.area || '') : '';
        return {id:cb.dataset.personId, gid:gid, name:name, number:number, company:company, scheme:scheme, area:area};
    });
}

function tdlPrepareWhatsAppMessage(contact) {
    var el = document.getElementById('tdlWhatsAppMessage');
    var message = el ? el.value : '';
    return message
        .replace(/\{\{\s*name\s*\}\}/gi, contact.name)
        .replace(/\{\{\s*company\s*\}\}/gi, contact.company)
        .replace(/\{\{\s*scheme\s*\}\}/gi, contact.scheme)
        .replace(/\{\{\s*area\s*\}\}/gi, contact.area);
}

function tdlSaveWhatsAppCampaign() {
    if (!tdlWhatsAppQueue.length || tdlWhatsAppCampaignStatus === 'idle') return;
    try {
        var messageEl = document.getElementById('tdlWhatsAppMessage');
        localStorage.setItem(TDL_WA_CAMPAIGN_KEY, JSON.stringify({
            queue: tdlWhatsAppQueue,
            index: tdlWhatsAppIndex,
            status: tdlWhatsAppCampaignStatus,
            message: messageEl ? messageEl.value : '',
            savedAt: Date.now()
        }));
    } catch (e) {}
}

function tdlReadSavedWhatsAppCampaign() {
    try {
        var raw = localStorage.getItem(TDL_WA_CAMPAIGN_KEY);
        if (!raw) return null;
        var saved = JSON.parse(raw);
        if (!saved || !Array.isArray(saved.queue) || !saved.queue.length) return null;
        if (saved.status === 'completed') return null;
        return saved;
    } catch (e) {
        return null;
    }
}

function tdlLoadWhatsAppCampaign() {
    var saved = tdlReadSavedWhatsAppCampaign();
    if (!saved) return false;
    tdlWhatsAppQueue = saved.queue;
    tdlWhatsAppIndex = Math.max(0, Math.min(Number(saved.index) || 0, tdlWhatsAppQueue.length));
    tdlWhatsAppCampaignStatus = (saved.status === 'stopped') ? 'stopped' : 'paused';
    var messageEl = document.getElementById('tdlWhatsAppMessage');
    if (messageEl && typeof saved.message === 'string') messageEl.value = saved.message;
    return true;
}

function tdlUpdateSavedResumeButton() {
    var btn = document.getElementById('tdlWaSavedResumeBtn');
    var notice = document.getElementById('tdlWaSavedCampaignNotice');
    var saved = tdlReadSavedWhatsAppCampaign();
    var show = !!saved && Number(saved.index || 0) < saved.queue.length;

    if (btn) {
        btn.style.display = show ? 'inline-flex' : 'none';
        if (show) {
            var index = Math.max(0, Number(saved.index) || 0);
            btn.textContent = '▶ Resume Saved Campaign (' + (index + 1) + '/' + saved.queue.length + ')';
        }
    }
    if (notice) {
        notice.style.display = show ? 'inline' : 'none';
        if (show) {
            notice.textContent = 'Saved campaign: ' + ((Number(saved.index || 0)) + 1) + '/' + saved.queue.length;
        }
    }
}

function tdlResumeSavedWhatsApp() {
    var saved = tdlReadSavedWhatsAppCampaign();
    if (!saved) {
        tdlUpdateSavedResumeButton();
        return;
    }
    tdlWhatsAppQueue = saved.queue;
    tdlWhatsAppIndex = Math.max(0, Math.min(Number(saved.index) || 0, tdlWhatsAppQueue.length));
    tdlWhatsAppCampaignStatus = 'running';
    var messageEl = document.getElementById('tdlWhatsAppMessage');
    if (messageEl && typeof saved.message === 'string') messageEl.value = saved.message;
    var modal = document.getElementById('tdlWaModal');
    if (modal) { modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); }
    tdlRenderWhatsAppContact();
    tdlUpdateSavedResumeButton();
}

function tdlClearWhatsAppCampaign() {
    try { localStorage.removeItem(TDL_WA_CAMPAIGN_KEY); } catch (e) {}
}

function tdlSetWhatsAppCampaignStatus(status) {
    tdlWhatsAppCampaignStatus = status;
    var statusEl = document.getElementById('tdlWaCampaignStatus');
    var pauseBtn = document.getElementById('tdlWaPauseBtn');
    var resumeBtn = document.getElementById('tdlWaResumeBtn');
    var openBtn = document.getElementById('tdlWaOpenBtn');
    var nextBtn = document.getElementById('tdlWaNextBtn');
    var skipBtn = document.getElementById('tdlWaSkipBtn');
    var stopBtn = document.getElementById('tdlWaStopBtn');

    if (status === 'running') {
        if (statusEl) statusEl.textContent = 'Campaign running — open WhatsApp, send the message, then advance to the next contact.';
        if (pauseBtn) pauseBtn.style.display = '';
        if (resumeBtn) resumeBtn.style.display = 'none';
        if (openBtn) openBtn.disabled = false;
        if (nextBtn) nextBtn.disabled = false;
        if (skipBtn) skipBtn.disabled = false;
        if (stopBtn) stopBtn.style.display = '';
    } else if (status === 'paused') {
        if (statusEl) statusEl.textContent = 'Campaign paused — your current contact and position are saved in this browser session.';
        if (pauseBtn) pauseBtn.style.display = 'none';
        if (resumeBtn) resumeBtn.style.display = '';
        if (openBtn) openBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        if (skipBtn) skipBtn.disabled = true;
        if (stopBtn) stopBtn.style.display = '';
    } else if (status === 'completed') {
        if (statusEl) statusEl.textContent = 'Campaign completed — all selected contacts have been processed.';
        if (pauseBtn) pauseBtn.style.display = 'none';
        if (resumeBtn) resumeBtn.style.display = 'none';
        if (openBtn) openBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        if (skipBtn) skipBtn.disabled = true;
        if (stopBtn) stopBtn.style.display = 'none';
    } else if (status === 'stopped') {
        if (statusEl) statusEl.textContent = 'Campaign stopped and saved. You can resume from the same contact later.';
        if (pauseBtn) pauseBtn.style.display = 'none';
        if (resumeBtn) resumeBtn.style.display = '';
        if (openBtn) openBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        if (skipBtn) skipBtn.disabled = true;
        if (stopBtn) stopBtn.style.display = 'none';
    }
}

function tdlUpdateWhatsAppCampaignProgress() {
    var bar = document.getElementById('tdlWaProgressBar');
    if (!bar) return;
    var total = tdlWhatsAppQueue.length;
    var done = Math.min(tdlWhatsAppIndex, total);
    bar.style.width = (total ? (done / total) * 100 : 0) + '%';
}

function tdlRenderWhatsAppContact() {
    var modal = document.getElementById('tdlWaModal');
    var card = document.getElementById('tdlWaContactCard');
    if (!modal || !card) return;

    tdlUpdateWhatsAppCampaignProgress();

    if (tdlWhatsAppIndex >= tdlWhatsAppQueue.length) {
        card.innerHTML = '<div style="text-align:center;padding:18px"><div style="font-size:30px">✓</div><div style="font-size:18px;font-weight:900;color:var(--navy);margin-top:6px">All selected contacts completed</div><div style="color:var(--muted);font-size:12px;margin-top:5px">The campaign has reached the end of the queue.</div></div>';
        tdlCurrentWhatsAppUrl = '';
        tdlSetWhatsAppCampaignStatus('completed');
        tdlClearWhatsAppCampaign();
        return;
    }

    var c = tdlWhatsAppQueue[tdlWhatsAppIndex];
    var message = tdlPrepareWhatsAppMessage(c);
    tdlCurrentWhatsAppUrl = 'https://wa.me/' + encodeURIComponent(c.number) + (message ? '?text=' + encodeURIComponent(message) : '');
    tdlWhatsAppOpenedCurrent = false;

    card.innerHTML =
        '<div style="font-size:11px;color:var(--muted);font-weight:800;margin-bottom:7px">CONTACT ' + (tdlWhatsAppIndex + 1) + ' OF ' + tdlWhatsAppQueue.length + '</div>' +
        '<div class="tdl-wa-contact-name">' + tdlEscapeHtml(c.name) + '</div>' +
        '<div class="tdl-wa-contact-number">+ ' + tdlEscapeHtml(c.number) + '</div>' +
        '<div class="tdl-wa-contact-meta">Group #' + tdlEscapeHtml(c.gid) + (c.company ? ' • ' + tdlEscapeHtml(c.company) : '') + (c.scheme ? ' • ' + tdlEscapeHtml(c.scheme) : '') + '<br>' + (message ? 'Message prepared and will be inserted into WhatsApp.' : 'No message entered — WhatsApp will open without a prefilled message.') + '</div>';
    tdlSetWhatsAppCampaignStatus('running');
    tdlSaveWhatsAppCampaign();
}

function tdlStartWhatsApp() {
    var selected = tdlGetPersonCheckboxes().filter(function(cb){ return cb.checked; });
    if (!selected.length) {
        alert('Please select at least one member first.');
        return;
    }
    tdlWhatsAppQueue = tdlBuildWhatsAppQueue();
    if (!tdlWhatsAppQueue.length) {
        alert('The selected members do not have a usable WhatsApp number.');
        return;
    }
    tdlClearWhatsAppCampaign();
    tdlWhatsAppIndex = 0;
    tdlWhatsAppOpenedCurrent = false;
    tdlWhatsAppCampaignStatus = 'running';
    tdlUpdateSavedResumeButton();
    var modal = document.getElementById('tdlWaModal');
    if (modal) { modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); }
    tdlRenderWhatsAppContact();
}

function tdlOpenCurrentWhatsApp() {
    if (tdlWhatsAppCampaignStatus !== 'running' || !tdlCurrentWhatsAppUrl) return;
    tdlWhatsAppOpenedCurrent = true;
    window.open(tdlCurrentWhatsAppUrl, '_blank', 'noopener,noreferrer');
}

function tdlAddCurrentContactToClientHandling() {
    var contact = tdlWhatsAppQueue[tdlWhatsAppIndex];
    if (!contact || !contact.id) { alert('Unable to identify this contact in the client base.'); return; }
    var statusSelect = document.getElementById('tdlWaClientStatus');
    var button = document.getElementById('tdlWaAddClientBtn');
    var status = statusSelect ? statusSelect.value : 'New';
    if (button) button.disabled = true;
    fetch(location.pathname + '?client_campaign_action=add', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({cid:String(contact.id), status:status})
    }).then(function(response){ return response.json().then(function(data){ if(!response.ok || !data.ok) throw new Error(data.error || 'Unable to add client.'); return data; }); })
      .then(function(data){ var statusEl=document.getElementById('tdlWaCampaignStatus'); if(statusEl) statusEl.textContent=data.message; })
      .catch(function(error){ alert(error.message || 'Unable to add client to Client Handling.'); })
      .finally(function(){ if(button) button.disabled = false; });
}

function tdlNextWhatsApp() {
    if (tdlWhatsAppCampaignStatus !== 'running') return;
    if (tdlWhatsAppIndex < tdlWhatsAppQueue.length) tdlWhatsAppIndex++;
    tdlSaveWhatsAppCampaign();
    tdlRenderWhatsAppContact();
}

function tdlSkipWhatsApp() {
    if (tdlWhatsAppCampaignStatus !== 'running') return;
    tdlNextWhatsApp();
}

function tdlPauseWhatsApp() {
    if (tdlWhatsAppCampaignStatus !== 'running') return;
    tdlSetWhatsAppCampaignStatus('paused');
    tdlSaveWhatsAppCampaign();
}

function tdlResumeWhatsApp() {
    if (tdlWhatsAppCampaignStatus !== 'paused') return;
    if (!tdlWhatsAppQueue.length || tdlWhatsAppIndex >= tdlWhatsAppQueue.length) {
        tdlRenderWhatsAppContact();
        return;
    }
    tdlSetWhatsAppCampaignStatus('running');
    tdlRenderWhatsAppContact();
}

function tdlStopWhatsApp() {
    if (!tdlWhatsAppQueue.length || tdlWhatsAppCampaignStatus === 'completed') return;
    if (!confirm('Stop this WhatsApp campaign permanently? The saved campaign will be deleted and cannot be resumed.')) return;

    // STOP is different from PAUSE: permanently terminate this campaign.
    // Delete the saved queue immediately so no Resume button can bring it back.
    tdlClearWhatsAppCampaign();
    tdlWhatsAppCampaignStatus = 'stopped';
    tdlWhatsAppQueue = [];
    tdlWhatsAppIndex = 0;
    tdlCurrentWhatsAppUrl = '';
    tdlWhatsAppOpenedCurrent = false;

    // Hide the saved-resume control on the main page.
    tdlUpdateSavedResumeButton();
    tdlSetWhatsAppCampaignStatus('stopped');

    var card = document.getElementById('tdlWaContactCard');
    if (card) {
        card.innerHTML = '<div style="text-align:center;padding:18px">' +
            '<div style="font-size:30px">■</div>' +
            '<div style="font-size:18px;font-weight:900;color:var(--navy);margin-top:6px">Campaign stopped</div>' +
            '<div style="color:var(--muted);font-size:12px;margin-top:5px">This campaign has been permanently stopped. The saved campaign was deleted, so it will not resume from this position.</div>' +
            '</div>';
    }

    // Close the campaign popup after stopping.
    setTimeout(function(){ tdlCloseWhatsApp(); }, 250);
}



function tdlCloseWhatsApp() {
    // Never clear the saved campaign when closing the popup.
    var modal = document.getElementById('tdlWaModal');
    if (modal) { modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); }
    tdlUpdateSavedResumeButton();
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') tdlCloseWhatsApp();
});

/* Restore an unfinished campaign after refresh in the browser. */
document.addEventListener('DOMContentLoaded', function() {
    tdlInitMemberGroups();
    tdlUpdateSelectionUI();
    tdlUpdateWhatsAppStatus();
    tdlLiveSearch();

    var restored = tdlLoadWhatsAppCampaign();
    if (restored) {
        if (tdlWhatsAppCampaignStatus === 'paused') {
            var modal = document.getElementById('tdlWaModal');
            if (modal) { modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); }
            tdlRenderWhatsAppContact();
            tdlSetWhatsAppCampaignStatus('paused');
        } else {
            tdlUpdateSavedResumeButton();
        }
    } else {
        tdlUpdateSavedResumeButton();
    }
});

</script>


</body>

</html>
