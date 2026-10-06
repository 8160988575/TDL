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

    /* Keep submitted area values matched case-insensitively
       against the individual area options. */
    $validAreaMap = [];
    foreach ($area_options as $validArea) {
        $validAreaMap[strtolower(trim((string)$validArea))] = $validArea;
    }

    $cleanAreas = [];
    foreach ($areas as $submittedArea) {
        $key = strtolower(trim((string)$submittedArea));
        if ($key !== '' && isset($validAreaMap[$key])) {
            $cleanAreas[] = $validAreaMap[$key];
        }
    }

    $areas = array_values(array_unique($cleanAreas));

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
            oninput="tdlLiveSearch()"
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


<?php foreach (
    $groups as $group
): ?>


<?php

$gid =
    $group['grp_id'];

$searchText =
    strtolower(
        $gid .
        ' ' .
        $group['company_name'] .
        ' ' .
        $group['scheme_name'] .
        ' ' .
        implode(
            ' ',
            $group['relations']
        ) .
        ' ' .
        implode(
            ' ',
            array_column(
                $group['persons'],
                'name'
            )
        )
    );

?>


                <tr
                    class="group-row"
                    data-group-id="<?= $gid ?>"
                    data-search="<?= e($searchText) ?>"
                    data-area="<?= e(
                        strtolower(
                            $group['area']
                        )
                    ) ?>"
                >

                    <td>

                        <div class="group-id">
                            #<?= $gid ?>
                        </div>

                    </td>


                    <td>

                        <div class="company">
                            <?= e(
                                $group[
                                    'company_name'
                                ]
                            ) ?>
                        </div>

                    </td>


                    <!-- =================================================
                         THIRD COLUMN - ALL RELATION
                         ================================================= -->

                    <td>

                        <div class="line-list">

<?php if (
    !empty(
        $group['relations']
    )
): ?>

<?php foreach (
    $group['relations']
    as $relation
): ?>

                            <div
                                class="line-item relation-item"
                            >
                                <?= e(
                                    $relation
                                ) ?>
                            </div>

<?php endforeach; ?>

<?php else: ?>

                            <div class="line-item">
                                —
                            </div>

<?php endif; ?>

                        </div>

                    </td>


                    <td>

                        <div class="scheme">
                            <?= e(
                                $group[
                                    'scheme_name'
                                ]
                            ) ?>
                        </div>

                    </td>


                    <!-- =================================================
                         ALL PERSONS
                         ================================================= -->

                    <td>

                        <div class="line-list">

<?php foreach (
    $group['persons']
    as $person
): ?>

                            <div class="line-item">
                                <strong><?= e($person['name']) ?></strong>
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


                    <!-- =================================================
                         MAIN PERSONS
                         ================================================= -->

                    <td>

                        <div class="line-list">

<?php if (
    !empty(
        $group[
            'main_persons'
        ]
    )
): ?>

<?php foreach (
    $group[
        'main_persons'
    ] as $mainPerson
): ?>

                            <?php
                            $mainMatch = null;
                            foreach ($group['persons'] as $person) {
                                if (strcasecmp(trim((string)($person['name'] ?? '')), trim((string)$mainPerson)) === 0
                                    && trim((string)($person['main'] ?? '')) === 'main') {
                                    $mainMatch = $person;
                                    break;
                                }
                            }
                            ?>
                            <div class="line-item main">
                                <strong><?= e($mainPerson) ?></strong>
                                <?php if ($mainMatch): ?>
                                    <?php
                                    $mainNumbers = array_values(array_filter([
                                        trim((string)($mainMatch['number1'] ?? '')),
                                        trim((string)($mainMatch['number2'] ?? '')),
                                        trim((string)($mainMatch['number3'] ?? ''))
                                    ], static function ($v) { return $v !== ''; }));
                                    ?>
                                    <?php if (!empty($mainNumbers)): ?>
                                        <div style="margin-top:3px;font-size:11px;line-height:1.35;">
                                            <?php foreach ($mainNumbers as $mainNumber): ?>
                                                <?php $mainWaNumber = preg_replace('/[^0-9]/', '', (string)$mainNumber); ?>
                                                <?php if ($mainWaNumber !== ''): ?>
                                                    <a href="https://wa.me/<?= e($mainWaNumber) ?>" target="_blank" rel="noopener noreferrer" style="color:#159957;text-decoration:none;font-weight:700;display:inline-block;margin-right:7px;" title="Open WhatsApp"><?= e($mainNumber) ?></a>
                                                <?php else: ?>
                                                    <span style="display:inline-block;margin-right:7px;"><?= e($mainNumber) ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

<?php endforeach; ?>

<?php else: ?>

                            <div class="line-item">
                                —
                            </div>

<?php endif; ?>

                        </div>

                    </td>


                    <!-- =================================================
                         AREA
                         ================================================= -->

                    <td>

                        <div class="area-list">

<?php

$displayAreas =
    preg_split(
        '/\s*,\s*/',
        $group['area']
    );

foreach (
    $displayAreas
    as $oneArea
):

if (
    trim($oneArea) === ''
) {
    continue;
}

?>

                            <span
                                class="area-badge"
                            >
                                <?= e(
                                    ucwords(
                                        trim(
                                            $oneArea
                                        )
                                    )
                                ) ?>
                            </span>

<?php endforeach; ?>

                        </div>

                    </td>


                    <!-- =================================================
                         ACTIONS
                         ================================================= -->

                    <td>

                        <div class="actions">

                            <form
                                method="POST"
                                style="display:inline"
                            >
                                <input
                                    type="hidden"
                                    name="grp_id"
                                    value="<?= $gid ?>"
                                >

                                <input
                                    type="hidden"
                                    name="star_action"
                                    value="<?= !empty($group['is_starred']) ? 'unstar' : 'star' ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-star <?= !empty($group['is_starred']) ? 'starred' : '' ?>"
                                    title="<?= !empty($group['is_starred']) ? 'Remove star' : 'Star this group' ?>"
                                >
                                    <?= !empty($group['is_starred']) ? '★ Starred' : '☆ Star' ?>
                                </button>
                            </form>

                            <button
                                type="button"
                                class="btn btn-view"
                                onclick="toggleDetails(<?= $gid ?>)"
                            >
                                👁 View
                            </button>


                            <button
                                type="button"
                                class="btn btn-edit"
                                onclick="toggleDetails(<?= $gid ?>, true)"
                            >
                                ✎ Edit
                            </button>


                            <form
                                method="POST"
                                style="display:inline"
                                onsubmit="return confirmDeleteGroup(<?= $gid ?>);"
                            >

                                <input
                                    type="hidden"
                                    name="grp_id"
                                    value="<?= $gid ?>"
                                >

                                <button
                                    type="submit"
                                    name="delete_group"
                                    value="1"
                                    class="btn btn-delete"
                                >
                                    🗑 Delete
                                </button>

                            </form>

                        </div>

                    </td>

                </tr>


                <!-- =====================================================
                     DETAILS
                     ===================================================== -->

                <tr
                    class="details-row"
                    id="details-<?= $gid ?>"
                >

                    <td
                        colspan="8"
                    >

                        <div class="details-content">

                            <div class="details-toolbar">
    <div class="details-toolbar-title">
        <span class="details-toolbar-icon">◆</span>
        <div>
            <strong>Group Details</strong>
            <small>View, edit people, or update group information</small>
        </div>
    </div>
    <button type="button" class="btn btn-close-details"
            onclick="closeDetails(<?= $gid ?>)">✕ Close</button>
</div>




                            <!-- =================================================
                                 GROUP EDIT
                                 ================================================= -->

                            <div
                                class="details-panel"
                                id="group-edit-<?= $gid ?>"
                                style="display:none"
                            >

                                <div class="panel-title">
                                    ✎ Edit Group #<?= $gid ?>
                                </div>

                                <div class="panel-body">

                                    <form
                                        method="POST"
                                        id="group-save-form-<?= $gid ?>"
                                        onsubmit="return prepareGroupSave(<?= $gid ?>, this);"
                                    >

                                        <input
                                            type="hidden"
                                            name="grp_id"
                                            value="<?= $gid ?>"
                                        >


                                        <div class="group-edit">


                                            <div class="field">

                                                <label>
                                                    Company Name
                                                </label>

                                                <input
                                                    type="text"
                                                    name="company_name"
                                                    value="<?= e(
                                                        $group[
                                                            'company_name'
                                                        ]
                                                    ) ?>"
                                                    required
                                                >

                                            </div>


                                            <div class="field">

                                                <label>
                                                    Scheme Name
                                                </label>

                                                <input
                                                    type="text"
                                                    name="scheme_name"
                                                    value="<?= e(
                                                        $group[
                                                            'scheme_name'
                                                        ]
                                                    ) ?>"
                                                    required
                                                >

                                            </div>


<?php

$currentAreas = preg_split(
    '/\s*,\s*/',
    (string)($group['area'] ?? '')
);

$currentAreasNormalized = array_values(array_filter(array_map(
    static function ($value) {
        return strtolower(trim((string)$value));
    },
    $currentAreas ?: []
)));

?>

                                            <!--
                                                IMPORTANT PERFORMANCE OPTIMIZATION:
                                                Do not render every area checkbox for every group.
                                                The full area list is created once in JavaScript and
                                                populated only when this group is actually opened for edit.
                                            -->
                                            <div
                                                class="area-checks area-checks-lazy"
                                                data-current-areas='<?= e(json_encode($currentAreasNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                                            >
                                                <div class="area-loading-placeholder">
                                                    Areas load when Edit is opened…
                                                </div>
                                            </div>

                                        </div>


                                        <div
                                            style="margin-top:15px;display:flex;gap:8px"
                                        >

                                            <button
                                                type="submit"
                                                name="update_group"
                                                value="1"
                                                class="btn btn-save">
                                                ✓ Save Group
                                            </button>

                                            <button
                                                type="button"
                                                class="btn btn-cancel"
                                                onclick="toggleEdit(<?= $gid ?>, false)"
                                            >
                                                Cancel
                                            </button>

                                            <button
                                                type="button"
                                                class="btn btn-close-details"
                                                onclick="closeDetails(<?= $gid ?>)"
                                            >
                                                ✕ Close Details
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>


                            <!-- =================================================
                                 PERSONS
                                 ================================================= -->

                            <div
                                class="details-panel"
                                style="margin-top:18px"
                            >

                                <div class="panel-title">

                                    👥
                                    All Persons
                                    —
                                    <?= count(
                                        $group['persons']
                                    ) ?>

                                </div>


                                <div class="panel-body">

                                    <?php foreach ($group['persons'] as $personForm): ?>
                                        <form
                                            id="person-form-<?= (int)$personForm['id'] ?>"
                                            method="POST"
                                            style="display:none"
                                        >
                                            <input type="hidden" name="id" value="<?= (int)$personForm['id'] ?>">
                                            <input type="hidden" name="grp_id" value="<?= (int)$gid ?>">
                                        </form>
                                    <?php endforeach; ?>

                                    <div
                                        class="person-table-wrap"
                                    >

                                        <table
                                            class="person-table"
                                        >

                                            <thead>

                                                <tr>

                                                    <th>
                                                        #
                                                    </th>

                                                    <th>
                                                        Name
                                                    </th>

                                                    <th>
                                                        Number 1
                                                    </th>

                                                    <th>
                                                        Number 2
                                                    </th>

                                                    <th>
                                                        Number 3
                                                    </th>

                                                    <th>
                                                        Relation 1
                                                    </th>

                                                    <th>
                                                        Relation 2
                                                    </th>

                                                    <th>
                                                        All Relation
                                                    </th>

                                                    <th>
                                                        Main
                                                    </th>

                                                    <th>
                                                        Action
                                                    </th>

                                                </tr>

                                            </thead>


                                            <tbody>


<?php

$personNo =
    1;

foreach (
    $group['persons']
    as $person
):

?>


                                                <tr
                                                    class="<?= trim(
                                                        $person['main']
                                                    ) === 'main'
                                                        ? 'main-person-row'
                                                        : ''
                                                    ?>"
                                                    data-person-id="<?= (int)$person['id'] ?>"
                                                >


                                                        <td>
                                                            <?= $personNo ?>
                                                        </td>

                                                        <td>

                                                            <input
                                                                class="person-input person-name"
                                                                type="text"
                                                                name="name" data-field="name" form="person-form-<?= (int)$person['id'] ?>"
                                                                value="<?= e(
                                                                    $person[
                                                                        'name'
                                                                    ]
                                                                ) ?>"
                                                                required
                                                            >

                                                        </td>


                                                        <td>

                                                            <input
                                                                class="person-input"
                                                                type="text"
                                                                name="number1" data-field="number1" form="person-form-<?= (int)$person['id'] ?>"
                                                                value="<?= e(
                                                                    $person[
                                                                        'number1'
                                                                    ]
                                                                ) ?>"
                                                            >

                                                        </td>


                                                        <td>

                                                            <input
                                                                class="person-input"
                                                                type="text"
                                                                name="number2" data-field="number2" form="person-form-<?= (int)$person['id'] ?>"
                                                                value="<?= e(
                                                                    $person[
                                                                        'number2'
                                                                    ]
                                                                ) ?>"
                                                            >

                                                        </td>


                                                        <td>

                                                            <input
                                                                class="person-input"
                                                                type="text"
                                                                name="number3" data-field="number3" form="person-form-<?= (int)$person['id'] ?>"
                                                                value="<?= e(
                                                                    $person[
                                                                        'number3'
                                                                    ]
                                                                ) ?>"
                                                            >

                                                        </td>


                                                        <td>

                                                            <input
                                                                class="person-input"
                                                                type="text"
                                                                name="relation1" data-field="relation1" form="person-form-<?= (int)$person['id'] ?>"
                                                                value="<?= e(
                                                                    $person[
                                                                        'relation1'
                                                                    ]
                                                                ) ?>"
                                                            >

                                                        </td>


                                                        <td>

                                                            <input
                                                                class="person-input"
                                                                type="text"
                                                                name="relation2" data-field="relation2" form="person-form-<?= (int)$person['id'] ?>"
                                                                value="<?= e(
                                                                    $person[
                                                                        'relation2'
                                                                    ]
                                                                ) ?>"
                                                            >

                                                        </td>


                                                        <td>

                                                            <input
                                                                class="person-input"
                                                                type="text"
                                                                value="<?= e(
                                                                    $person[
                                                                        'relation_all'
                                                                    ]
                                                                ) ?>"
                                                                readonly
                                                            >

                                                        </td>


                                                        <td>

                                                            <select
                                                                name="main" data-field="main" form="person-form-<?= (int)$person['id'] ?>"
                                                                class="main-select"
                                                            >

                                                                <option
                                                                    value=""
                                                                    <?= trim(
                                                                        $person[
                                                                            'main'
                                                                        ]
                                                                    ) === ''
                                                                        ? 'selected'
                                                                        : ''
                                                                    ?>
                                                                >
                                                                    —
                                                                </option>

                                                                <option
                                                                    value="main"
                                                                    <?= trim(
                                                                        $person[
                                                                            'main'
                                                                        ]
                                                                    ) === 'main'
                                                                        ? 'selected'
                                                                        : ''
                                                                    ?>
                                                                >
                                                                    Main
                                                                </option>

                                                            </select>

                                                        </td>


                                                        <td>

                                                            <button
                                                                type="submit"
                                                                name="update_person"
                                                                value="1"
                                                                form="person-form-<?= (int)$person['id'] ?>"
                                                                class="btn btn-save"
                                                            >
                                                                Save
                                                            </button>


                                                            <button
                                                                type="submit"
                                                                name="delete_person"
                                                                value="1"
                                                                form="person-form-<?= (int)$person['id'] ?>"
                                                                class="btn btn-delete"
                                                                onclick="return confirm('Delete this person from Group #<?= $gid ?>?');"
                                                            >
                                                                Delete
                                                            </button>

                                                        </td>

                                                </tr>


<?php

$personNo++;

endforeach;

?>


                                            </tbody>

                                        </table>

                                    </div>


                                    <!-- =================================================
                                         ADD PERSON
                                         ================================================= -->

                                    <div class="add-person">

                                        <div
                                            style="font-weight:800;color:var(--navy);font-size:13px;margin-bottom:11px"
                                        >
                                            + Add Person to Group #<?= $gid ?>
                                        </div>


                                        <form
                                            method="POST"
                                        >

                                            <input
                                                type="hidden"
                                                name="grp_id"
                                                value="<?= $gid ?>"
                                            >


                                            <div
                                                class="add-person-grid"
                                            >

                                                <input
                                                    type="text"
                                                    name="new_name"
                                                    placeholder="Name"
                                                    required
                                                >

                                                <input
                                                    type="text"
                                                    name="new_number1"
                                                    placeholder="Number 1"
                                                >

                                                <input
                                                    type="text"
                                                    name="new_number2"
                                                    placeholder="Number 2"
                                                >

                                                <input
                                                    type="text"
                                                    name="new_number3"
                                                    placeholder="Number 3"
                                                >

                                                <input
                                                    type="text"
                                                    name="new_relation1"
                                                    placeholder="Relation 1"
                                                >

                                                <input
                                                    type="text"
                                                    name="new_relation2"
                                                    placeholder="Relation 2"
                                                >

                                                <select
                                                    name="new_main"
                                                >

                                                    <option
                                                        value=""
                                                    >
                                                        Not Main
                                                    </option>

                                                    <option
                                                        value="main"
                                                    >
                                                        Main
                                                    </option>

                                                </select>

                                            </div>


                                            <div
                                                style="margin-top:10px"
                                            >

                                                <button
                                                    type="submit"
                                                    name="add_person"
                                                    value="1"
                                                    class="btn btn-add"
                                                >
                                                    + Add Person
                                                </button>

                                            </div>

                                        </form>

                                    </div>

                                </div>

                            </div>


                            <!-- =================================================
                                 MAIN PERSON PANEL
                                 ================================================= -->

                            <div
                                class="details-grid"
                                style="margin-top:18px"
                            >

                                <div
                                    class="details-panel"
                                >

                                    <div class="panel-title">
                                        ★ Main Persons
                                    </div>

                                    <div class="panel-body">

<?php if (
    !empty(
        $group[
            'main_persons'
        ]
    )
): ?>

                                        <div class="line-list">

<?php foreach (
    $group[
        'main_persons'
    ] as $mainPerson
): ?>

                                            <div
                                                class="line-item main"
                                            >
                                                ★
                                                <?= e(
                                                    $mainPerson
                                                ) ?>
                                            </div>

<?php endforeach; ?>

                                        </div>

<?php else: ?>

                                        <div
                                            style="color:var(--muted)"
                                        >
                                            No main person selected.
                                        </div>

<?php endif; ?>

                                    </div>

                                </div>


                                <div
                                    class="details-panel"
                                >

                                    <div class="panel-title">
                                        🔗 All Relation
                                    </div>

                                    <div class="panel-body">

<?php if (
    !empty(
        $group['relations']
    )
): ?>

                                        <div class="line-list">

<?php foreach (
    $group['relations']
    as $relation
): ?>

                                            <div
                                                class="line-item relation-item"
                                            >
                                                <?= e(
                                                    $relation
                                                ) ?>
                                            </div>

<?php endforeach; ?>

                                        </div>

<?php else: ?>

                                        <div
                                            style="color:var(--muted)"
                                        >
                                            No relation history.
                                        </div>

<?php endif; ?>

                                    </div>

                                </div>

                            </div>


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

function tdlGetRowSearchText(row) {
    var text = row.textContent || '';

    var extra = row.getAttribute('data-search') || '';
    var area  = row.getAttribute('data-area') || '';

    return tdlNormalize(text + ' ' + extra + ' ' + area);
}

function tdlRowMatches(row, query) {
    query = tdlNormalize(query);

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

        var show = searchOK && areaOK;

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
}

/* =========================================================
   GROUP COLUMN SORT
   Sorting is handled by PHP/SQL so it cannot interfere with
   any other JavaScript functionality on this page.
   ========================================================= */

/* =========================================================
   VIEW DETAILS
   ========================================================= */

function toggleDetails(
    grpId,
    editMode = false
)
{
    const details =
        document.getElementById(
            'details-' + grpId
        );

    if (!details) {
        return;
    }

    if (details.classList.contains('open')) {
        closeDetails(grpId);
        return;
    }

    details.classList.add('open');

    toggleEdit(
        grpId,
        editMode
    );

    setTimeout(
        function()
        {
            details.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        },
        80
    );
}


/* =========================================================
   CLOSE GROUP DETAILS
   ========================================================= */

function closeDetails(
    grpId
)
{
    const details =
        document.getElementById(
            'details-' + grpId
        );

    if (!details) {
        return;
    }

    toggleEdit(
        grpId,
        false
    );

    details.classList.remove(
        'open'
    );
}


/* =========================================================
   EDIT MODE
   ========================================================= */

function tdlBuildEditAreas(edit)
{
    if (!edit || edit.dataset.areasLoaded === '1') {
        return;
    }

    const container = edit.querySelector('.area-checks-lazy');
    if (!container) {
        edit.dataset.areasLoaded = '1';
        return;
    }

    let currentAreas = [];

    try {
        currentAreas = JSON.parse(
            container.getAttribute('data-current-areas') || '[]'
        );
    } catch (e) {
        currentAreas = [];
    }

    const currentSet = new Set(
        currentAreas.map(function(value) {
            return String(value || '').trim().toLowerCase();
        })
    );

    const fragment = document.createDocumentFragment();

    if (!Array.isArray(window.tdlAreaOptions) || !window.tdlAreaOptions.length) {
        container.innerHTML = '<div style="color:var(--muted);font-size:11px">No areas available.</div>';
        edit.dataset.areasLoaded = '1';
        return;
    }

    const gid = edit.id.replace('group-edit-', '');

    window.tdlAreaOptions.forEach(function(area, index) {
        const cleanArea = String(area || '').trim();
        if (!cleanArea) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'area-check';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'areas[]';
        input.value = cleanArea;
        input.id = 'edit_' + gid + '_area_' + index;
        input.checked = currentSet.has(cleanArea.toLowerCase());

        const label = document.createElement('label');
        label.htmlFor = input.id;
        label.textContent = cleanArea
            .toLowerCase()
            .replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });

        wrapper.appendChild(input);
        wrapper.appendChild(label);
        fragment.appendChild(wrapper);
    });

    container.replaceChildren(fragment);
    edit.dataset.areasLoaded = '1';
}


function toggleEdit(
    grpId,
    show
)
{

    const edit =
        document.getElementById(
            'group-edit-' + grpId
        );


    if (!edit) {
        return;
    }

    if (show) {
        tdlBuildEditAreas(edit);
    }

    edit.style.display =
        show
            ? 'block'
            : 'none';

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
</script>


</body>

</html>
