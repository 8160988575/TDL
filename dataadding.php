
<?php

/* =========================================================
   TDL CONTACT MANAGER
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

mysqli_set_charset(
    $con,
    'utf8mb4'
);


/* =========================================================
   VARIABLES
========================================================= */

$contacts = [];

$error = '';

$success = '';

$input = '';

$company_name = '';

$scheme_name = '';

$selected_areas = [];


/* =========================================================
   AREA OPTIONS
========================================================= */

$area_options = [

    'naroda',
    'zundal',
    'sg highway',
    'gift city',
    'gandhinagar',
    'Narol',
    'Vatva',
    'Vastral',
    'Odhav'

];


/* =========================================================
   HTML ESCAPE
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
   CLEAN EMAIL
========================================================= */

function cleanEmail($email)
{

    $email = trim($email);

    $email = str_replace(
        '\@',
        '@',
        $email
    );

    return $email;

}


/* =========================================================
   CLEAN MOBILE
========================================================= */

function cleanMobile($mobile)
{

    $mobile = trim($mobile);

    return preg_replace(
        '/[^0-9]/',
        '',
        $mobile
    );

}


/* =========================================================
   BUILD RELATION ALL
========================================================= */

function buildRelationAll(
    $existing,
    $schemeNames
)
{

    $all = [];


    /* =====================================================
       EXISTING VALUES
    ===================================================== */

    if (
        trim($existing) !== ''
    ) {

        $existingParts =
            preg_split(
                '/\s*,\s*/',
                $existing
            );


        foreach (
            $existingParts as $item
        ) {

            $item =
                trim($item);


            if (
                $item !== ''
            ) {

                $all[] =
                    $item;

            }

        }

    }


    /* =====================================================
       NEW SCHEMES
    ===================================================== */

    foreach (
        $schemeNames as $scheme
    ) {

        $scheme =
            trim($scheme);


        if (
            $scheme === ''
        ) {

            continue;

        }


        $exists =
            false;


        foreach (
            $all as $existingScheme
        ) {

            if (
                strcasecmp(
                    $existingScheme,
                    $scheme
                ) === 0
            ) {

                $exists =
                    true;

                break;

            }

        }


        if (
            !$exists
        ) {

            $all[] =
                $scheme;

        }

    }


    return implode(
        ', ',
        $all
    );

}


/* =========================================================
   PARSE CONTACTS
========================================================= */

function parseContacts($input)
{
    $result = [];

    /*
    =========================================================
    CONTACT PARSER
    Supports ALL existing formats:

    1) Labelled:
       **Name:**JOHN
       **Email Id:**john@gmail.com
       **Mobile:**9999999999

    2) Label/value on separate lines:
       Name:
       JOHN
       Email Id:
       john@gmail.com
       Mobile:
       9999999999

    3) Excel / tab / pasted rows:
       JOHN                         9999999999
       JOHN                         9999999999 8888888888

    4) Markdown:
       | JOHN | | | | 9999999999 |
       | JOHN | | | | 9999999999 | 8888888888 |

    IMPORTANT:
    - Name is kept even when there is NO mobile.
    - Up to 3 numbers are captured in order:
      first = number1
      second = number2
      third = number3.
    =========================================================
    */

    $lines = preg_split('/\R/u', $input);

    $current = null;
    $pendingField = null;

    $addPerson = function($person) use (&$result) {

        if (!$person || trim($person['name'] ?? '') === '') {
            return;
        }

        $name  = trim($person['name']);
        $email = cleanEmail(trim($person['email'] ?? ''));

        $mobiles = [];

        /*
         * Labelled format normally has one Mobile field.
         * Keep it as the first number.
         */
        if (!empty($person['mobile'])) {
            $mobile = cleanMobile($person['mobile']);

            if ($mobile !== '') {
                $mobiles[] = $mobile;
            }
        }

        $key = normalizeName($name);

        if (!isset($result[$key])) {

            $result[$key] = [
                'name'            => $name,
                'email'           => $email,
                'mobiles'         => [],
                'duplicate_count' => 1,
                'relation1'       => ''
            ];

        } else {

            /* Same name found again: only increase the count. */
            $result[$key]['duplicate_count'] =
                (int)($result[$key]['duplicate_count'] ?? 1) + 1;

            if (
                $result[$key]['email'] === '' &&
                $email !== ''
            ) {
                $result[$key]['email'] = $email;
            }
        }

        /* Different numbers go into Number 1, Number 2, Number 3. */
        foreach ($mobiles as $mobile) {

            if (
                $mobile !== '' &&
                !in_array(
                    $mobile,
                    $result[$key]['mobiles'],
                    true
                )
            ) {
                $result[$key]['mobiles'][] = $mobile;
            }

            if (count($result[$key]['mobiles']) >= 3) {
                break;
            }
        }
    };

    foreach ($lines as $rawLine) {

        /*
         * Decode things such as:
         * &#x9;
         * &nbsp;
         */
        $line = html_entity_decode(
            $rawLine,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $line = str_replace(
            ["\xC2\xA0", "\r"],
            [' ', ''],
            $line
        );

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        /*
         * Ignore markdown separator rows.
         */
        if (
            preg_match(
                '/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?$/',
                $line
            )
        ) {
            continue;
        }

        /*
        =====================================================
        LABELLED NAME
        =====================================================
        */

        if (
            preg_match(
                '/^\s*\**Name\s*:\s*\**\s*(.*)$/i',
                $line,
                $m
            )
        ) {

            if ($current !== null) {
                $addPerson($current);
            }

            $current = [
                'name'   => trim($m[1]),
                'email'  => '',
                'mobile' => ''
            ];

            $pendingField =
                ($current['name'] === '')
                ? 'name'
                : null;

            continue;
        }

        /*
        =====================================================
        LABELLED EMAIL
        =====================================================
        */

        if (
            preg_match(
                '/^\s*\**Email\s*(?:Id|ID)?\s*:\s*\**\s*(.*)$/i',
                $line,
                $m
            )
        ) {

            if ($current === null) {
                $current = [
                    'name'   => '',
                    'email'  => '',
                    'mobile' => ''
                ];
            }

            $value = trim($m[1]);

            if ($value !== '') {

                $current['email'] =
                    cleanEmail($value);

                $pendingField = null;

            } else {

                $pendingField = 'email';
            }

            continue;
        }

        /*
        =====================================================
        LABELLED MOBILE
        =====================================================
        */

        if (
            preg_match(
                '/^\s*\**Mobile\s*:\s*\**\s*(.*)$/i',
                $line,
                $m
            )
        ) {

            if ($current === null) {
                $current = [
                    'name'   => '',
                    'email'  => '',
                    'mobile' => ''
                ];
            }

            $value = trim($m[1]);

            if ($value !== '') {

                $current['mobile'] =
                    cleanMobile($value);

                $pendingField = null;

            } else {

                $pendingField = 'mobile';
            }

            continue;
        }

        /*
        =====================================================
        VALUE AFTER LABEL
        =====================================================
        */

        if (
            $current !== null &&
            $pendingField !== null
        ) {

            if ($pendingField === 'name') {

                $current['name'] = trim($line);
                $pendingField = null;

                continue;
            }

            if ($pendingField === 'email') {

                $current['email'] =
                    cleanEmail($line);

                $pendingField = null;

                continue;
            }

            if ($pendingField === 'mobile') {

                $current['mobile'] =
                    cleanMobile($line);

                $pendingField = null;

                continue;
            }
        }

        /*
        =====================================================
        IF A LABELLED PERSON IS COMPLETE, SAVE IT BEFORE
        READING THE NEXT ORDINARY EXCEL/TABLE ROW.
        =====================================================
        */

        if (
            $current !== null &&
            trim($current['name'] ?? '') !== '' &&
            $pendingField === null
        ) {

            $addPerson($current);
            $current = null;
        }

        /*
        =====================================================
        MARKDOWN TABLE FORMAT

        Example:
        | NAME | | | | 9999999999 | 8888888888 |

        First non-empty 10-digit number = number1
        Second = number2
        Third = number3
        =====================================================
        */

        if (strpos($line, '|') !== false) {

            $columns = array_map(
                'trim',
                explode(
                    '|',
                    trim($line, " \t|")
                )
            );

            if (count($columns) > 0) {

                $name = trim($columns[0]);

                $numbers = [];

                foreach ($columns as $column) {

                    if (
                        preg_match(
                            '/^\s*([0-9]{10})\s*$/',
                            $column,
                            $m
                        )
                    ) {

                        $number = cleanMobile($m[1]);

                        if (
                            $number !== '' &&
                            !in_array(
                                $number,
                                $numbers,
                                true
                            )
                        ) {
                            $numbers[] = $number;
                        }

                        if (count($numbers) >= 3) {
                            break;
                        }
                    }
                }

                $mobile1 = $numbers[0] ?? '';
                $mobile2 = $numbers[1] ?? '';
                $mobile3 = $numbers[2] ?? '';

                if (
                    $name !== '' &&
                    !preg_match(
                        '/^[\-\s]+$/u',
                        $name
                    )
                ) {

                    $key = normalizeName($name);

                    if (!isset($result[$key])) {

                        $result[$key] = [
                            'name'            => $name,
                            'email'           => '',
                            'mobiles'         => [],
                            'duplicate_count' => 1,
                            'relation1'       => ''
                        ];

                        foreach (
                            [$mobile1, $mobile2, $mobile3]
                            as $number
                        ) {

                            if (
                                $number !== '' &&
                                !in_array(
                                    $number,
                                    $result[$key]['mobiles'],
                                    true
                                )
                            ) {
                                $result[$key]['mobiles'][] =
                                    $number;
                            }
                        }

                    } else {

                        /* Same name: count only. */
                        $result[$key]['duplicate_count'] =
                            (int)($result[$key]['duplicate_count'] ?? 1) + 1;

                        foreach (
                            [$mobile1, $mobile2, $mobile3] as $number
                        ) {
                            if (
                                $number !== '' &&
                                !in_array(
                                    $number,
                                    $result[$key]['mobiles'],
                                    true
                                )
                            ) {
                                $result[$key]['mobiles'][] = $number;
                            }

                            if (count($result[$key]['mobiles']) >= 3) {
                                break;
                            }
                        }
                    }                }
            }

            continue;
        }

        /*
        =====================================================
        NORMAL EXCEL / TAB / SPACE FORMAT

        Examples:

        JOHN                 9999999999
        JOHN                 9999999999 8888888888
        JOHN                 9999999999 8888888888 7777777777

        Also works when Excel converts tabs to spaces.

        The LAST 1-3 groups of exactly 10 digits are treated
        as phone numbers. Everything before them remains the
        person's name.

        If there are NO numbers, the COMPLETE LINE becomes
        the person's name.
        =====================================================
        */

        $name = trim($line);
        $numbers = [];

        /*
         * Find 1 to 3 consecutive 10-digit phone numbers at
         * the END of the row.
         */
        if (
            preg_match(
                '/^(.*?)((?:\s+[0-9]{10}){1,3})\s*$/u',
                $line,
                $m
            )
        ) {

            $possibleName = trim($m[1]);

            preg_match_all(
                '/[0-9]{10}/',
                $m[2],
                $phoneMatches
            );

            foreach (
                ($phoneMatches[0] ?? [])
                as $phone
            ) {

                $phone = cleanMobile($phone);

                if (
                    $phone !== '' &&
                    !in_array(
                        $phone,
                        $numbers,
                        true
                    )
                ) {
                    $numbers[] = $phone;
                }

                if (count($numbers) >= 3) {
                    break;
                }
            }

            $name = $possibleName;
        }

        $name = trim(
            $name,
            " \t\r\n|"
        );

        if (
            $name === '' ||
            preg_match(
                '/^[\-\s]+$/u',
                $name
            )
        ) {
            continue;
        }

        /*
        -----------------------------------------------------
        Ignore headers.
        -----------------------------------------------------
        */

        $upper = strtoupper($name);

        if (
            in_array(
                $upper,
                [
                    'NAME',
                    'MOBILE',
                    'NUMBER',
                    'EMAIL',
                    'EMAIL ID'
                ],
                true
            )
        ) {
            continue;
        }

        /*
        -----------------------------------------------------
        Add the person.
        Empty number is intentionally allowed.
        -----------------------------------------------------
        */

        $key = normalizeName($name);

        if (!isset($result[$key])) {

            $result[$key] = [
                'name'    => $name,
                'email'   => '',
                'mobiles' => [],
                'duplicate_count' => 1
            ];
        } else {
            $result[$key]['duplicate_count'] =
                (int)($result[$key]['duplicate_count'] ?? 1) + 1;
        }

        foreach (
            array_slice($numbers, 0, 3)
            as $number
        ) {

            if (
                $number !== '' &&
                !in_array(
                    $number,
                    $result[$key]['mobiles'],
                    true
                )
            ) {
                $result[$key]['mobiles'][] =
                    $number;
            }
        }
    }

    /*
    =========================================================
    SAVE FINAL LABELLED RECORD
    =========================================================
    */

    if ($current !== null) {
        $addPerson($current);
    }

/*
     * Highest duplicate count first: 4, 3, 2, 1.
     * For equal counts, keep alphabetical order.
     */
    uasort(
        $result,
        function($a, $b) {
            $countA = (int)($a['duplicate_count'] ?? 1);
            $countB = (int)($b['duplicate_count'] ?? 1);

            /*
             * ONLY sort by duplicate count.
             * Equal counts return 0, so their original input order
             * is preserved. There is NO alphabetical sorting.
             */
            if ($countA === $countB) {
                return 0;
            }

            return $countB <=> $countA;
        }
    );

    return $result;
}

/* =========================================================
   GENERATE PREVIEW
========================================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'POST' &&

    isset(
        $_POST['generate_preview']
    )

) {


    $input =
        $_POST['contacts']
        ?? '';


    $company_name =
        trim(
            $_POST['company_name']
            ?? ''
        );


    $scheme_name =
        trim(
            $_POST['scheme_name']
            ?? ''
        );


    $selected_areas =
        $_POST['areas']
        ?? [];


    if (
        !is_array(
            $selected_areas
        )
    ) {

        $selected_areas =
            [];

    }


    /* =====================================================
       ONLY VALID AREAS
    ===================================================== */

    $selected_areas =
        array_values(
            array_intersect(
                $selected_areas,
                $area_options
            )
        );


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $company_name === ''
    ) {

        $error =
            'Please enter Company Name.';

    }

    elseif (
        $scheme_name === ''
    ) {

        $error =
            'Please enter Scheme Name.';

    }

    elseif (
        empty($selected_areas)
    ) {

        $error =
            'Please select at least one Area.';

    }

    else {


        $contacts =
            parseContacts(
                $input
            );


        if (
            empty($contacts)
        ) {

            $error =
                'No valid contacts found. Please check the pasted format.';

        }

        else {

            $success =
                count($contacts) .
                ' unique contacts found.';

        }

    }

}


/* =========================================================
   ADD TO DATABASE
========================================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'POST' &&

    isset(
        $_POST['add_to_database']
    )

) {


    /* =====================================================
       BASIC DATA
    ===================================================== */

    $company_name =
        trim(
            $_POST['company_name']
            ?? ''
        );


    $scheme_name =
        trim(
            $_POST['scheme_name']
            ?? ''
        );


    /* =====================================================
       AREAS
    ===================================================== */

    $selected_areas =
        $_POST['areas']
        ?? [];


    if (
        !is_array(
            $selected_areas
        )
    ) {

        $selected_areas =
            [];

    }


    $selected_areas =
        array_values(
            array_intersect(
                $selected_areas,
                $area_options
            )
        );


    /* =====================================================
       CONTACT ARRAYS
    ===================================================== */

    $names =
        $_POST['person_name']
        ?? [];


    $number1 =
        $_POST['number1']
        ?? [];


    $number2 =
        $_POST['number2']
        ?? [];


    $number3 =
        $_POST['number3']
        ?? [];


    $relation1 =
        $_POST['relation1']
        ?? [];


    $relation2 =
        $_POST['relation2']
        ?? [];


    $main =
        $_POST['main']
        ?? [];


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $company_name === ''
    ) {

        $error =
            'Please enter Company Name.';

    }

    elseif (
        $scheme_name === ''
    ) {

        $error =
            'Please enter Scheme Name.';

    }

    elseif (
        empty($selected_areas)
    ) {

        $error =
            'Please select at least one Area.';

    }

    elseif (
        empty($names)
    ) {

        $error =
            'No contacts found.';

    }

    else {


        /* =================================================
           START TRANSACTION
        ================================================= */

        mysqli_begin_transaction(
            $con
        );


        try {


            /* =============================================
               CREATE A COMPLETELY NEW GROUP ID

               GET_LOCK prevents two users submitting at the
               same time from receiving the same grp_id.
            ============================================= */

            $lockResult = mysqli_query(
                $con,
                "SELECT GET_LOCK('tdl_new_group_id', 10) AS lock_status"
            );

            if (!$lockResult) {
                throw new Exception(
                    'Unable to lock Group ID generator: ' .
                    mysqli_error($con)
                );
            }

            $lockRow = mysqli_fetch_assoc($lockResult);

            if ((int)($lockRow['lock_status'] ?? 0) !== 1) {
                throw new Exception(
                    'Could not reserve a new Group ID. Please try again.'
                );
            }

            try {
                $groupQuery = mysqli_query(
                    $con,
                    "SELECT COALESCE(MAX(grp_id), 0) AS max_grp_id FROM data"
                );

                if (!$groupQuery) {
                    throw new Exception(
                        'Unable to get previous Group ID: ' .
                        mysqli_error($con)
                    );
                }

                $groupRow = mysqli_fetch_assoc($groupQuery);

                $last_grp_id = (int)(
                    $groupRow['max_grp_id'] ?? 0
                );

                /* ALWAYS create the next unused group */
                $new_grp_id = $last_grp_id + 1;

                /* Extra safety check */
                while (true) {
                    $checkStmt = mysqli_prepare(
                        $con,
                        "SELECT id FROM data WHERE grp_id = ? LIMIT 1"
                    );

                    if (!$checkStmt) {
                        throw new Exception(
                            'Unable to verify new Group ID: ' .
                            mysqli_error($con)
                        );
                    }

                    mysqli_stmt_bind_param(
                        $checkStmt,
                        'i',
                        $new_grp_id
                    );

                    mysqli_stmt_execute($checkStmt);
                    $checkResult = mysqli_stmt_get_result($checkStmt);
                    $groupExists = mysqli_num_rows($checkResult) > 0;
                    mysqli_stmt_close($checkStmt);

                    if (!$groupExists) {
                        break;
                    }

                    $new_grp_id++;
                }

            } finally {
                mysqli_query(
                    $con,
                    "SELECT RELEASE_LOCK('tdl_new_group_id')"
                );
            }


            /* =============================================
               AREA STRING
            ============================================= */

            $areaString =
                implode(
                    ', ',
                    $selected_areas
                );


            /* =============================================
               INSERT DATA QUERY
            ============================================= */

            $insertSql = "

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

            ";


            $insertStmt =
                mysqli_prepare(
                    $con,
                    $insertSql
                );


            if (!$insertStmt) {

                throw new Exception(

                    'Unable to prepare data insert: ' .
                    mysqli_error($con)

                );

            }


            $inserted =
                0;


            /* =================================================
               PROCESS CONTACTS IN TABLE ORDER
            ================================================= */

            foreach (
                $names as $key => $name
            ) {


                $name =
                    trim(
                        $name
                    );


                if (
                    $name === ''
                ) {

                    continue;

                }


                /* =============================================
                   NUMBERS
                ============================================= */

                $n1 =
                    cleanMobile(
                        $number1[$key]
                        ?? ''
                    );


                $n2 =
                    cleanMobile(
                        $number2[$key]
                        ?? ''
                    );


                $n3 =
                    cleanMobile(
                        $number3[$key]
                        ?? ''
                    );


                /* =============================================
                   RELATIONS
                ============================================= */

                $r1 =
                    trim(
                        $relation1[$key]
                        ?? ''
                    );


                $r2 =
                    trim(
                        $relation2[$key]
                        ?? ''
                    );


                /* =============================================
                   MAIN

                   Allowed values:

                   main
                   blank
                ============================================= */

                $m =
                    trim(
                        $main[$key]
                        ?? ''
                    );


                if (
                    $m !== 'main'
                ) {

                    $m =
                        '';

                }


                /* =============================================
                   NORMALIZED NAME
                ============================================= */

                $normalizedName =
                    normalizeName(
                        $name
                    );


                /* =============================================
                   FIND OLD SAME-NAME RECORDS
                ============================================= */

                $oldSql = "

                    SELECT

                        id,
                        scheme_name,
                        relation_all

                    FROM data

                    WHERE

                        UPPER(
                            TRIM(name)
                        ) = ?

                ";


                $oldStmt =
                    mysqli_prepare(
                        $con,
                        $oldSql
                    );


                if (!$oldStmt) {

                    throw new Exception(

                        'Unable to prepare old-name search: ' .
                        mysqli_error($con)

                    );

                }


                mysqli_stmt_bind_param(

                    $oldStmt,

                    's',

                    $normalizedName

                );


                if (
                    !mysqli_stmt_execute(
                        $oldStmt
                    )
                ) {

                    throw new Exception(

                        'Unable to search old records: ' .
                        mysqli_stmt_error(
                            $oldStmt
                        )

                    );

                }


                $oldResult =
                    mysqli_stmt_get_result(
                        $oldStmt
                    );


                $oldRecords =
                    [];


                $previousSchemes =
                    [];


                while (
                    $oldRow =
                        mysqli_fetch_assoc(
                            $oldResult
                        )
                ) {


                    $oldRecords[] =
                        $oldRow;


                    $oldScheme =
                        trim(
                            $oldRow['scheme_name']
                            ?? ''
                        );


                    if (
                        $oldScheme !== ''
                    ) {


                        $alreadyExists =
                            false;


                        foreach (
                            $previousSchemes
                            as $previousScheme
                        ) {

                            if (
                                strcasecmp(
                                    $previousScheme,
                                    $oldScheme
                                ) === 0
                            ) {

                                $alreadyExists =
                                    true;

                                break;

                            }

                        }


                        if (
                            !$alreadyExists
                        ) {

                            $previousSchemes[] =
                                $oldScheme;

                        }

                    }

                }


                mysqli_stmt_close(
                    $oldStmt
                );


                /* =============================================
                   NEW RECORD RELATION ALL
                ============================================= */

                $newRelationAll =
                    buildRelationAll(

                        '',

                        $previousSchemes

                    );


                /* =============================================
                   INSERT PERSON
                ============================================= */

                mysqli_stmt_bind_param(

                    $insertStmt,

                    'isssssssssss',

                    $new_grp_id,

                    $company_name,

                    $scheme_name,

                    $name,

                    $n1,

                    $n2,

                    $n3,

                    $r1,

                    $r2,

                    $newRelationAll,

                    $m,

                    $areaString

                );


                if (
                    !mysqli_stmt_execute(
                        $insertStmt
                    )
                ) {

                    throw new Exception(

                        'Failed to insert ' .
                        $name .
                        ': ' .
                        mysqli_stmt_error(
                            $insertStmt
                        )

                    );

                }


                $inserted++;


                /* =============================================
                   UPDATE PREVIOUS SAME NAME RECORDS
                ============================================= */

                if (
                    !empty($oldRecords)
                ) {


                    $updateSql = "

                        UPDATE data

                        SET
                            relation_all = ?

                        WHERE
                            id = ?

                    ";


                    $updateStmt =
                        mysqli_prepare(
                            $con,
                            $updateSql
                        );


                    if (!$updateStmt) {

                        throw new Exception(

                            'Unable to prepare relation update: ' .
                            mysqli_error($con)

                        );

                    }


                    foreach (
                        $oldRecords as $oldRecord
                    ) {


                        $oldRelationAll =
                            $oldRecord[
                                'relation_all'
                            ]
                            ?? '';


                        $updatedRelationAll =
                            buildRelationAll(

                                $oldRelationAll,

                                [
                                    $scheme_name
                                ]

                            );


                        $oldId =
                            (int)(
                                $oldRecord['id']
                            );


                        mysqli_stmt_bind_param(

                            $updateStmt,

                            'si',

                            $updatedRelationAll,

                            $oldId

                        );


                        if (
                            !mysqli_stmt_execute(
                                $updateStmt
                            )
                        ) {

                            throw new Exception(

                                'Unable to update relation_all: ' .
                                mysqli_stmt_error(
                                    $updateStmt
                                )

                            );

                        }

                    }


                    mysqli_stmt_close(
                        $updateStmt
                    );

                }

            }


            mysqli_stmt_close(
                $insertStmt
            );


            /* =================================================
               CHECK INSERTED
            ================================================= */

            if (
                $inserted === 0
            ) {

                throw new Exception(
                    'No contacts were inserted.'
                );

            }


            /* =================================================
               COLLECT MAIN PERSON NAMES
            ================================================= */

            $main_person_names =
                [];


            foreach (
                $names as $key => $personName
            ) {


                $personName =
                    trim(
                        $personName
                    );


                $personMain =
                    trim(
                        $main[$key]
                        ?? ''
                    );


                if (

                    $personName !== '' &&

                    $personMain === 'main'

                ) {


                    if (
                        !in_array(
                            $personName,
                            $main_person_names,
                            true
                        )
                    ) {

                        $main_person_names[] =
                            $personName;

                    }

                }

            }


            /* =================================================
               COMMA SEPARATED MAIN PERSONS
            ================================================= */

            $main_persons =
                implode(
                    ', ',
                    $main_person_names
                );


            /* =================================================
               INSERT INTO AREA TABLE

               area table:

               area
               company_name
               scheme_name
               grp_id
               main_persons
            ================================================= */

            $areaSql = "

                INSERT INTO area

                (
                    area,
                    company_name,
                    scheme_name,
                    grp_id,
                    main_persons
                )

                VALUES

                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )

            ";


            $areaStmt =
                mysqli_prepare(
                    $con,
                    $areaSql
                );


            if (!$areaStmt) {

                throw new Exception(

                    'Unable to prepare area insert: ' .
                    mysqli_error($con)

                );

            }


            /* =================================================
               INSERT EACH SELECTED AREA
            ================================================= */

            foreach (
                $selected_areas as $oneArea
            ) {


                mysqli_stmt_bind_param(

                    $areaStmt,

                    'sssis',

                    $oneArea,

                    $company_name,

                    $scheme_name,

                    $new_grp_id,

                    $main_persons

                );


                if (
                    !mysqli_stmt_execute(
                        $areaStmt
                    )
                ) {

                    throw new Exception(

                        'Unable to insert area: ' .
                        mysqli_stmt_error(
                            $areaStmt
                        )

                    );

                }

            }


            mysqli_stmt_close(
                $areaStmt
            );


            /* =================================================
               COMMIT
            ================================================= */

            mysqli_commit(
                $con
            );


            /* =================================================
               REDIRECT

               Prevent duplicate insert on refresh.
            ================================================= */

            header(

                'Location: ' .
                $_SERVER['PHP_SELF'] .
                '?added=' .
                $inserted .
                '&grp_id=' .
                $new_grp_id

            );


            exit;

        }

        catch (
            Exception $e
        ) {


            /* =================================================
               ROLLBACK
            ================================================= */

            mysqli_rollback(
                $con
            );


            $error =
                $e->getMessage();

        }

    }

}


/* =========================================================
   SUCCESS AFTER REDIRECT
========================================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'GET' &&

    isset(
        $_GET['added']
    )

) {


    $added =
        (int)(
            $_GET['added']
        );


    $grp_id =
        (int)(
            $_GET['grp_id']
            ?? 0
        );


    if (
        $added > 0
    ) {

        $success =

            $added .
            ' contacts added successfully. ' .

            'Group ID: ' .
            $grp_id;

    }

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
    TDL Contact Manager
</title>


<style>
/* =========================================================
   THE DIVINE LANDS — SIGNATURE CONTACT MANAGER UI
   ========================================================= */
*{box-sizing:border-box}
:root{
    --navy:#061a31;
    --navy2:#0a2746;
    --navy3:#123d64;
    --gold:#e8b85d;
    --gold-soft:#f7e3b6;
    --blue:#2d72aa;
    --green:#1a9a58;
    --red:#d83b45;
    --ink:#15263a;
    --muted:#718196;
    --line:#e2e8ef;
    --surface:#fff;
    --canvas:#f2f5f8;
    --shadow:0 22px 65px rgba(7,28,50,.13);
}
html{scroll-behavior:smooth}
body{
    margin:0;
    background:
      radial-gradient(circle at 8% 8%,rgba(232,184,93,.08),transparent 23%),
      radial-gradient(circle at 92% 25%,rgba(45,114,170,.08),transparent 25%),
      var(--canvas);
    color:var(--ink);
    font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
}
.container{width:100%;max-width:1600px;margin:0 auto;padding:28px 24px 48px}
.card{
    overflow:hidden;
    border:1px solid rgba(215,225,235,.9);
    border-radius:24px;
    background:var(--surface);
    box-shadow:var(--shadow);
}

/* HERO */
.header{
    position:relative;
    min-height:226px;
    padding:25px 34px 22px;
    overflow:hidden;
    color:#fff;
    background:
      radial-gradient(circle at 75% 35%,rgba(52,111,161,.25),transparent 28%),
      linear-gradient(125deg,#05172c 0%,#092644 52%,#103b61 100%);
}
.header:before{
    content:"";
    position:absolute;
    inset:-80px -30px auto auto;
    width:650px;
    height:430px;
    opacity:.75;
    background:
      linear-gradient(120deg,transparent 47%,rgba(232,184,93,.08) 48%,transparent 49%),
      linear-gradient(120deg,transparent 57%,rgba(255,255,255,.035) 58%,transparent 59%);
    transform:rotate(-8deg);
}
.header:after{
    content:"";
    position:absolute;
    width:520px;
    height:520px;
    right:-190px;
    bottom:-390px;
    border:1px solid rgba(232,184,93,.16);
    border-radius:50%;
    box-shadow:0 0 0 60px rgba(232,184,93,.025),0 0 0 120px rgba(232,184,93,.018);
}
.brand{
    position:relative;
    z-index:3;
    display:flex;
    align-items:center;
    gap:18px;
}
.brand-logo{
    width:68px;
    height:68px;
    padding:7px;
    border-radius:18px;
    background:#fff;
    border:1px solid rgba(255,255,255,.5);
    box-shadow:0 12px 30px rgba(0,0,0,.20);
    object-fit:contain;
}
.brand-copy{min-width:0}
.brand-name{
    margin:0 0 4px;
    color:var(--gold);
    font-size:12px;
    line-height:1;
    font-weight:900;
    letter-spacing:2.4px;
}
.header h1{
    margin:0;
    color:#fff;
    font-size:34px;
    line-height:1.08;
    font-weight:850;
    letter-spacing:-1px;
}
.header p{
    margin:8px 0 0;
    color:#afc0d2;
    font-size:13px;
}
.header-status{
    position:absolute;
    z-index:4;
    top:27px;
    right:32px;
    display:flex;
    align-items:center;
    gap:9px;
    padding:10px 14px;
    border:1px solid rgba(255,255,255,.14);
    border-radius:999px;
    background:rgba(255,255,255,.06);
    backdrop-filter:blur(10px);
    color:#dbe7f2;
    font-size:12px;
    font-weight:700;
}
.status-dot{
    width:8px;height:8px;border-radius:50%;
    background:#42d991;
    box-shadow:0 0 0 5px rgba(66,217,145,.12),0 0 12px rgba(66,217,145,.5);
}
.header-panel{
    position:absolute;
    z-index:4;
    top:27px;
    right:180px;
    padding:10px 15px;
    border:1px solid rgba(255,255,255,.13);
    border-radius:999px;
    color:#e8f0f8;
    background:rgba(255,255,255,.055);
    text-decoration:none;
    font-size:12px;
    font-weight:750;
    transition:.2s;
}
.header-panel:hover{background:rgba(255,255,255,.12);transform:translateY(-1px)}
.steps{
    position:relative;
    z-index:3;
    display:flex;
    align-items:center;
    max-width:760px;
    margin-top:28px;
}
.step{
    display:flex;
    align-items:center;
    gap:9px;
    color:#8399ae;
    font-size:11px;
    font-weight:750;
    white-space:nowrap;
}
.step-num{
    display:flex;
    align-items:center;
    justify-content:center;
    width:29px;height:29px;
    border:1px solid #3b5771;
    border-radius:50%;
    color:#9eb0c1;
    background:rgba(255,255,255,.035);
    font-size:10px;
    font-weight:850;
}
.step.active{color:#fff}
.step.active .step-num{
    border-color:var(--gold);
    background:var(--gold);
    color:#132b43;
    box-shadow:0 0 0 5px rgba(232,184,93,.09);
}
.step-line{
    width:70px;height:1px;
    margin:0 11px;
    background:linear-gradient(90deg,#405970,#2a4055);
}

/* CONTENT */
.content{padding:30px 34px 38px}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
    margin-bottom:18px;
}
.field{
    position:relative;
    padding:20px 20px 18px;
    border:1px solid var(--line);
    border-radius:16px;
    background:linear-gradient(180deg,#fff,#fbfcfd);
}
.field:focus-within{
    border-color:#b9cee1;
    box-shadow:0 8px 25px rgba(30,75,110,.07);
}
.field label,.main-label{
    display:block;
    margin:0 0 9px;
    color:#486078;
    font-size:11px;
    font-weight:900;
    letter-spacing:1px;
    text-transform:uppercase;
}
.field input{
    width:100%;
    height:50px;
    padding:0 15px;
    border:1px solid #d9e2ea;
    border-radius:10px;
    outline:none;
    background:#f9fbfd;
    color:#17283b;
    font-size:14px;
    transition:.2s;
}
.field input:focus{
    border-color:#4b8abd;
    background:#fff;
    box-shadow:0 0 0 4px rgba(45,114,170,.09);
}

/* SECTION CARDS */
.area-box,.info{
    position:relative;
    border-radius:17px;
}
.area-box{
    padding:21px 22px;
    margin-bottom:20px;
    border:1px solid var(--line);
    background:
      linear-gradient(135deg,#fff 0%,#fbfcfd 70%,#f8f4ea 100%);
}
.area-title{
    margin-bottom:14px;
    color:#334d66;
    font-size:11px;
    font-weight:900;
    letter-spacing:1.2px;
    text-transform:uppercase;
}
.required{color:var(--red)}
.area-options{display:flex;gap:10px;flex-wrap:wrap}
.area-option{position:relative}
.area-option input{position:absolute;opacity:0;pointer-events:none}
.area-option label{
    display:flex;
    align-items:center;
    gap:7px;
    min-height:39px;
    padding:0 15px;
    border:1px solid #d6e0e8;
    border-radius:9px;
    background:#fff;
    color:#52677c;
    cursor:pointer;
    font-size:12px;
    font-weight:800;
    transition:.18s;
}
.area-option label:before{
    content:"";
    width:7px;height:7px;
    border:1px solid #b9c7d3;
    border-radius:50%;
}
.area-option label:hover{
    transform:translateY(-1px);
    border-color:#aac0d2;
    box-shadow:0 7px 16px rgba(20,55,85,.07);
}
.area-option input:checked + label{
    border-color:var(--navy);
    background:var(--navy);
    color:#fff;
    box-shadow:0 8px 18px rgba(6,26,49,.18);
}
.area-option input:checked + label:before{
    border-color:var(--gold);
    background:var(--gold);
    box-shadow:0 0 0 3px rgba(232,184,93,.13);
}
.area-error{display:none;margin-top:10px;color:#c52e37;font-size:12px;font-weight:750}

.info{
    margin-bottom:22px;
    padding:18px 20px;
    border:1px solid #dfe7ee;
    background:#f8fafc;
    color:#6b7b8e;
    font-size:12px;
    line-height:1.75;
}
.info:before{
    content:"i";
    position:absolute;
    left:-1px;
    top:18px;
    width:4px;
    height:32px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:0 5px 5px 0;
    background:var(--gold);
    color:transparent;
}
.info strong{color:#2b435c}

/* INPUT AREA */
.main-label{
    margin-top:3px;
    margin-bottom:9px;
}
textarea{
    display:block;
    width:100%;
    min-height:350px;
    padding:18px;
    resize:vertical;
    border:1px solid #d7e1e9;
    border-radius:15px;
    outline:none;
    background:
      linear-gradient(#fbfcfd,#fbfcfd),
      repeating-linear-gradient(0deg,transparent 0,transparent 25px,rgba(37,83,120,.035) 26px);
    color:#26394d;
    font:13px/1.7 Consolas,Monaco,"Courier New",monospace;
    transition:.2s;
    box-shadow:inset 0 1px 2px rgba(10,30,50,.02);
}
textarea:focus{
    border-color:#4a8bbd;
    background:#fff;
    box-shadow:0 0 0 4px rgba(45,114,170,.09),0 12px 30px rgba(20,55,85,.06);
}

/* BUTTON BAR */
.buttons{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:15px;
}
button{
    min-height:44px;
    padding:0 18px;
    border:0;
    border-radius:10px;
    font:750 13px Inter,"Segoe UI",Arial,sans-serif;
    cursor:pointer;
    transition:.18s;
    box-shadow:0 5px 13px rgba(8,30,52,.08);
}
button:hover{transform:translateY(-2px);box-shadow:0 9px 22px rgba(8,30,52,.14)}
button:active{transform:translateY(0)}
.preview-btn{
    color:#fff;
    background:linear-gradient(135deg,#245d8e,#347dad);
}
.add-btn{
    color:#fff;
    background:linear-gradient(135deg,#147e45,#1ca15a);
}
.clear-btn{
    color:#526476;
    background:#eef2f6;
    border:1px solid #dce4eb;
}
.copy-btn{
    color:#fff;
    background:linear-gradient(135deg,#1b527f,#3478a8);
    min-height:40px;
}

/* PREVIEW */
.preview{
    margin-top:34px;
    padding-top:27px;
    border-top:1px solid #e7edf2;
}
.preview-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:15px;
    margin-bottom:13px;
}
.preview-title h2{
    margin:0 0 4px;
    color:#142c45;
    font-size:20px;
    font-weight:850;
    letter-spacing:-.3px;
}
.preview-title p{margin:0;color:#8190a0;font-size:12px}
.table-wrapper{
    overflow:auto;
    border:1px solid #dce5ed;
    border-radius:14px;
    box-shadow:0 10px 28px rgba(12,39,65,.065);
}
table{
    width:100%;
    min-width:1350px;
    border-collapse:separate;
    border-spacing:0;
}
th{
    padding:13px 11px;
    background:#0c3152;
    color:#fff;
    border-right:1px solid rgba(255,255,255,.08);
    text-align:left;
    font-size:10px;
    font-weight:850;
    text-transform:uppercase;
    letter-spacing:.65px;
    white-space:nowrap;
}
td{
    padding:8px;
    border-right:1px solid #edf1f5;
    border-bottom:1px solid #e8edf2;
    background:#fff;
    color:#293b4e;
    font-size:12px;
    white-space:nowrap;
}
td:last-child,th:last-child{border-right:0}
tbody tr:hover td{background:#f7fafc}
tbody tr:last-child td{border-bottom:0}
.drag-handle{width:45px;text-align:center;color:#899aaa;cursor:grab;font-size:18px;user-select:none}
.drag-handle:hover{color:var(--blue)}
.drag-handle:active{cursor:grabbing}
tr.dragging{opacity:.42}
tr.drag-over{box-shadow:inset 0 3px 0 #2f72aa}
.order-number{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:27px;height:27px;
    border:1px solid #dce5ec;
    border-radius:50%;
    background:#f2f6f9;
    color:#5e7287;
    font-size:11px;
    font-weight:850;
}
.edit-input{
    width:100%;
    min-height:36px;
    padding:7px 9px;
    border:1px solid #d7e0e8;
    border-radius:7px;
    outline:none;
    background:#fcfdff;
    color:#1c2e41;
    font:12px Inter,"Segoe UI",Arial,sans-serif;
}
.edit-input:focus,.main-select:focus{
    border-color:#4a8bbd;
    box-shadow:0 0 0 3px rgba(45,114,170,.08);
}
.name-input{min-width:250px}
.number-input{min-width:125px}
.relation-input{min-width:130px}
.main-select{
    min-width:82px;
    min-height:36px;
    padding:7px 9px;
    border:1px solid #d7e0e8;
    border-radius:7px;
    outline:none;
    background:#fff;
    color:#43576c;
    font-size:12px;
}
tr.main-row td{
    background:#f1fbf5;
    border-bottom-color:#c4e6d1;
}
tr.main-row .order-number{
    color:#08753b;
    background:#ddf5e5;
    border-color:#b8dfc6;
}

/* ALERTS */
.error,.success{
    padding:14px 17px;
    margin-bottom:20px;
    border-radius:11px;
    font-size:13px;
    font-weight:650;
}
.error{background:#fff6f7;border:1px solid #f0c6ca;border-left:4px solid var(--red);color:#a52831}
.success{background:#f3fcf7;border:1px solid #bde5cd;border-left:4px solid var(--green);color:#176b3d}

/* FOOTER */
.footer{
    padding:17px;
    border-top:1px solid #edf1f5;
    background:#fafcfd;
    color:#8492a1;
    text-align:center;
    font-size:11px;
}

/* MOBILE */
@media(max-width:900px){
    .container{padding:14px 10px 30px}
    .header{padding:22px 20px;min-height:220px}
    .header-status,.header-panel{position:static;display:inline-flex;margin-top:16px}
    .header-panel{margin-left:8px}
    .steps{overflow:auto;padding-bottom:4px}
    .step-line{width:35px;margin:0 7px}
    .content{padding:23px 18px 28px}
    .form-grid{grid-template-columns:1fr}
}
@media(max-width:600px){
    .brand-logo{width:58px;height:58px}
    .brand-name{font-size:10px}
    .header h1{font-size:27px}
    .header p{font-size:12px}
    .steps{margin-top:22px}
    .step{font-size:10px}
    .step-line{width:20px}
    .preview-top{align-items:flex-start;flex-direction:column}
    .copy-btn{width:100%}
    .buttons button{width:100%}
    textarea{min-height:300px}
}

.copy-contact-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-height:34px;
    margin-top:6px;
    padding:0 11px;
    border:1px solid #d8e3eb;
    border-radius:8px;
    background:#f7fafc;
    color:#31536f;
    font-size:11px;
    font-weight:800;
    cursor:pointer;
    transition:.18s ease;
}
.copy-contact-btn:hover{
    transform:translateY(-1px);
    border-color:#7aa6c7;
    background:#edf6fc;
}
.copy-contact-btn.copied{
    border-color:#9bd3b1;
    background:#edf9f2;
    color:#16804a;
}

</style>


</head>


<body>


<div class="container">


<div class="card">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="header">

<div class="brand">
    <img
        class="brand-logo"
        src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAH0CAIAAABEtEjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAGB2lUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSfvu78nIGlkPSdXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQnPz4KPHg6eG1wbWV0YSB4bWxuczp4PSdhZG9iZTpuczptZXRhLyc+CjxyZGY6UkRGIHhtbG5zOnJkZj0naHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyc+CgogPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9JycKICB4bWxuczpBdHRyaWI9J2h0dHA6Ly9ucy5hdHRyaWJ1dGlvbi5jb20vYWRzLzEuMC8nPgogIDxBdHRyaWI6QWRzPgogICA8cmRmOlNlcT4KICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0nUmVzb3VyY2UnPgogICAgIDxBdHRyaWI6Q3JlYXRlZD4yMDI2LTAzLTE2PC9BdHRyaWI6Q3JlYXRlZD4KICAgICA8QXR0cmliOkRhdGE+eyZxdW90O2RvYyZxdW90OzomcXVvdDtEQUhFRjducnBvdyZxdW90OywmcXVvdDt1c2VyJnF1b3Q7OiZxdW90O1VBRFRxcXZWN0ZzJnF1b3Q7LCZxdW90O2JyYW5kJnF1b3Q7OiZxdW90O2hhcnNoIGNoYXZkYeKAmXMgdGVhbSZxdW90OywmcXVvdDt0ZW1wbGF0ZSZxdW90OzomcXVvdDtEYXJrIEJsdWUgYW5kIEdvbGQgTHV4dXJ5IE1vZGVybiBSZWFsIEVzdGF0ZSBQcm9wZXJ0eSBMb2dvJnF1b3Q7fTwvQXR0cmliOkRhdGE+CiAgICAgPEF0dHJpYjpFeHRJZD5lMzRhZmFjZi1iYTczLTQ4NGUtYWQwZi05ZjA0NzllZDgwODc8L0F0dHJpYjpFeHRJZD4KICAgICA8QXR0cmliOkZiSWQ+NTI1MjY1OTE0MTc5NTgwPC9BdHRyaWI6RmJJZD4KICAgICA8QXR0cmliOlRvdWNoVHlwZT4yPC9BdHRyaWI6VG91Y2hUeXBlPgogICAgPC9yZGY6bGk+CiAgIDwvcmRmOlNlcT4KICA8L0F0dHJp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RLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgED/DwdOdPFGb0vzAAAAAElFTkSuQmCC"
        alt="The Divine Lands"
    >

    <div class="brand-copy">
        <div class="brand-name">THE DIVINE LANDS</div>

        <h1>
            Contact Manager
        </h1>

        <p>
            Organise contacts, schemes, areas &amp; relationship history in one place.
        </p>
    </div>
</div>

<!-- <div class="header-status">
    <span class="status-dot"></span>
    Database Connected
</div> -->

<!-- <a
    href="datashowing.php"
    class="header-panel"
>
    ◉ &nbsp; Panel
</a> -->

<a href="index.php" class="header-panel">🟢 &nbsp; ALL</a>

<div class="steps">

    <div class="step active">
        <span class="step-num">01</span>
        Details
    </div>

    <span class="step-line"></span>

    <div class="step">
        <span class="step-num">02</span>
        Paste Contacts
    </div>

    <span class="step-line"></span>

    <div class="step">
        <span class="step-num">03</span>
        Preview &amp; Edit
    </div>

    <span class="step-line"></span>

    <div class="step">
        <span class="step-num">04</span>
        Save
    </div>

</div>

</div>

<div class="content">


<!-- =====================================================
     ERROR
===================================================== -->

<?php if (
    $error !== ''
): ?>


<div class="error">

<?= e($error) ?>

</div>


<?php endif; ?>


<!-- =====================================================
     SUCCESS
===================================================== -->

<?php if (
    $success !== ''
): ?>


<div class="success">

<?= e($success) ?>

</div>


<?php endif; ?>


<form
    method="POST"
    id="contactForm"
>


<!-- =====================================================
     COMPANY + SCHEME
===================================================== -->

<div class="form-grid">


<div class="field">


<label>
    Company Name
</label>


<input
    type="text"
    name="company_name"
    value="<?= e($company_name) ?>"
    placeholder="Enter Company Name"
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
    value="<?= e($scheme_name) ?>"
    placeholder="Enter Scheme Name"
    required
>


</div>


</div>


<!-- =====================================================
     AREA
===================================================== -->

<div class="area-box">


<div class="area-title">

Select Area(s)

<span class="required">
    *
</span>

</div>


<div class="area-options">


<?php foreach (
    $area_options as $area
): ?>


<div class="area-option">


<input
    type="checkbox"
    id="area_<?= md5($area) ?>"
    name="areas[]"
    value="<?= e($area) ?>"
    <?= in_array(
        $area,
        $selected_areas,
        true
    )
        ? 'checked'
        : ''
    ?>
>


<label
    for="area_<?= md5($area) ?>"
>

<?= e(
    ucwords($area)
) ?>


</label>


</div>


<?php endforeach; ?>


</div>


<div
    id="area-error"
    class="area-error"
>

Please select at least one area.

</div>


</div>


<!-- =====================================================
     INFORMATION
===================================================== -->

<div class="info">


<strong>
    Workflow:
</strong>

<br>

Paste contacts → Generate Preview → Edit Name,
Numbers, Relations and Main → Drag rows to arrange
→ Select Area(s) → Add to Database.


<br><br>


<strong>
    Area:
</strong>

At least one area is mandatory.


<br><br>


<strong>
    Main Person Tracking:
</strong>

Every person marked as <strong>Main</strong> will be
stored as a comma-separated list in
<strong>area.main_persons</strong>.


<br><br>


<strong>
    Same Name:
</strong>

Older scheme names for the same person are automatically
maintained in <strong>relation_all</strong>.


<br><br>


<strong>
    Excel Copy:
</strong>

Name → A &nbsp; | &nbsp;
Number 1 → E &nbsp; | &nbsp;
Number 2 → F &nbsp; | &nbsp;
Number 3 → G

</div>


<!-- =====================================================
     CONTACT DATA
===================================================== -->

<label
    class="main-label"
    for="contacts"
>

Paste Contact Data

</label>


<textarea
    id="contacts"
    name="contacts"
    placeholder="Paste your contact data here..."
><?= e($input) ?></textarea>


<!-- =====================================================
     BUTTONS
===================================================== -->

<div class="buttons">


<button
    type="submit"
    name="generate_preview"
    value="1"
    class="preview-btn"
>

Generate Preview

</button>


<?php if (
    !empty($contacts)
): ?>


<button
    type="submit"
    name="add_to_database"
    value="1"
    class="add-btn"
    onclick="return confirmAdd();"
>

+ Add to Database

</button>


<?php endif; ?>


<button
    type="button"
    class="clear-btn"
    onclick="clearAll();"
>

Clear

</button>


</div>


<!-- =====================================================
     PREVIEW
===================================================== -->

<?php if (
    !empty($contacts)
): ?>


<div class="preview">


<div class="preview-top">


<div class="preview-title">


<h2>
    Editable Contact Preview
</h2>


<p>
    Drag rows using ☷ to change their order.
</p>


</div>


<button
    type="button"
    class="copy-btn"
    onclick="copyNamesAndNumbers();"
>

📋 Copy Name + Numbers

</button>


</div>


<div class="table-wrapper">


<table
    id="contactTable"
>


<thead>


<tr>


<th>
    #
</th>


<th>
    Main
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
    Drag
</th>


</tr>


</thead>


<tbody>


<?php


$rowNumber =
    1;


foreach (
    $contacts as $key => $contact
):


?>


<tr
    draggable="true"
>


<td>


<span class="order-number">

<?= $rowNumber ?>

</span>


</td>


<td>


<select
    name="main[]"
    class="main-select"
    onchange="updateMainRow(this);"
>


<option
    value=""
>

</option>


<option
    value="main"
>

Main

</option>


</select>
<button type="button" class="copy-contact-btn" onclick="return copyContactRow(this)" title="Copy Name, Number 1, Number 2, Number 3 and Relation 1">
    ⧉ Copy
</button>


</td>


<td>


<input
    type="text"
    name="person_name[]"
    value="<?= e(
        $contact['name']
    ) ?>"
    class="edit-input name-input"
>


</td>


<td>


<input
    type="text"
    name="number1[]"
    value="<?= e(
        $contact['mobiles'][0]
        ?? ''
    ) ?>"
    class="edit-input number-input"
>


</td>


<td>


<input
    type="text"
    name="number2[]"
    value="<?= e(
        $contact['mobiles'][1]
        ?? ''
    ) ?>"
    class="edit-input number-input"
>


</td>


<td>


<input
    type="text"
    name="number3[]"
    value="<?= e(
        $contact['mobiles'][2]
        ?? ''
    ) ?>"
    class="edit-input number-input"
>


</td>


<td>
<input
    type="text"
    name="relation1[]"
    value="<?= e(
        (($contact['duplicate_count'] ?? 1) > 1)
        ? (string)$contact['duplicate_count']
        : ''
    ) ?>"
    class="edit-input relation-input"
    placeholder="Relation 1"
>
</td>


<td>


<input
    type="text"
    name="relation2[]"
    value=""
    class="edit-input relation-input"
    placeholder="Relation 2"
>


</td>


<td
    class="drag-handle"
    title="Drag to change order"
>

☷

</td>


</tr>


<?php


$rowNumber++;


endforeach;


?>


</tbody>


</table>


</div>


</div>


<?php endif; ?>


</form>


</div>


<div class="footer">

TDL Contact Manager • Core PHP + MySQL

</div>


</div>


</div>


<script>


/* =========================================================
   AREA VALIDATION
========================================================= */

document
    .getElementById(
        'contactForm'
    )
    .addEventListener(
        'submit',
        function(event)
        {


            const submitter =
                event.submitter;


            if (

                !submitter ||

                (
                    submitter.name !==
                        'generate_preview' &&

                    submitter.name !==
                        'add_to_database'
                )

            ) {

                return;

            }


            const selectedAreas =
                document.querySelectorAll(
                    'input[name="areas[]"]:checked'
                );


            const areaError =
                document.getElementById(
                    'area-error'
                );


            if (
                selectedAreas.length === 0
            ) {


                event.preventDefault();


                if (areaError) {

                    areaError.style.display =
                        'block';

                }


                const areaBox =
                    document.querySelector(
                        '.area-box'
                    );


                if (areaBox) {

                    areaBox.scrollIntoView({

                        behavior:
                            'smooth',

                        block:
                            'center'

                    });

                }


                return false;

            }


            if (areaError) {

                areaError.style.display =
                    'none';

            }

        }
    );


/* =========================================================
   HIDE AREA ERROR
========================================================= */

document
    .querySelectorAll(
        'input[name="areas[]"]'
    )
    .forEach(
        function(checkbox)
        {


            checkbox.addEventListener(
                'change',
                function()
                {


                    const selectedAreas =
                        document.querySelectorAll(
                            'input[name="areas[]"]:checked'
                        );


                    const areaError =
                        document.getElementById(
                            'area-error'
                        );


                    if (
                        selectedAreas.length > 0
                    ) {

                        if (areaError) {

                            areaError.style.display =
                                'none';

                        }

                    }

                }
            );

        }
    );


/* =========================================================
   DRAG AND DROP
========================================================= */

let draggedRow =
    null;


const tableBody =
    document.querySelector(
        '#contactTable tbody'
    );


if (tableBody) {


    tableBody.addEventListener(
        'dragstart',
        function(event)
        {


            const row =
                event.target.closest(
                    'tr'
                );


            if (!row) {

                return;

            }


            draggedRow =
                row;


            row.classList.add(
                'dragging'
            );


            event.dataTransfer.effectAllowed =
                'move';

        }
    );


    tableBody.addEventListener(
        'dragend',
        function()
        {


            if (
                draggedRow
            ) {

                draggedRow.classList.remove(
                    'dragging'
                );

            }


            document
                .querySelectorAll(
                    '#contactTable tbody tr'
                )
                .forEach(
                    function(row)
                    {

                        row.classList.remove(
                            'drag-over'
                        );

                    }
                );


            updateRowNumbers();


            draggedRow =
                null;

        }
    );


    tableBody.addEventListener(
        'dragover',
        function(event)
        {


            event.preventDefault();


            const targetRow =
                event.target.closest(
                    'tr'
                );


            if (

                !targetRow ||

                targetRow === draggedRow

            ) {

                return;

            }


            document
                .querySelectorAll(
                    '#contactTable tbody tr'
                )
                .forEach(
                    function(row)
                    {

                        row.classList.remove(
                            'drag-over'
                        );

                    }
                );


            targetRow.classList.add(
                'drag-over'
            );


            const rect =
                targetRow.getBoundingClientRect();


            const middle =
                rect.top +
                (
                    rect.height /
                    2
                );


            if (
                event.clientY <
                middle
            ) {


                tableBody.insertBefore(

                    draggedRow,

                    targetRow

                );


            }

            else {


                tableBody.insertBefore(

                    draggedRow,

                    targetRow.nextSibling

                );

            }


            updateRowNumbers();

        }
    );

}


/* =========================================================
   UPDATE ROW NUMBERS
========================================================= */

function updateRowNumbers()
{

    const rows =
        document.querySelectorAll(
            '#contactTable tbody tr'
        );


    rows.forEach(
        function(row, index)
        {


            const number =
                row.querySelector(
                    '.order-number'
                );


            if (number) {

                number.textContent =
                    index + 1;

            }

        }
    );

}


/* =========================================================
   MAIN ROW
========================================================= */

function updateMainRow(
    select
)
{

    const row =
        select.closest(
            'tr'
        );


    if (
        select.value === 'main'
    ) {

        row.classList.add(
            'main-row'
        );

    }

    else {

        row.classList.remove(
            'main-row'
        );

    }

}


/* =========================================================
   COPY NAME + NUMBERS

   Excel:

   A = Name
   B = Blank
   C = Blank
   D = Blank
   E = Number1
   F = Number2
   G = Number3
========================================================= */

function copyNamesAndNumbers()
{

    const table =
        document.getElementById(
            'contactTable'
        );


    if (!table) {

        return;

    }


    const rows =
        table.querySelectorAll(
            'tbody tr'
        );


    let output =
        [];


    rows.forEach(
        function(row)
        {


            const name =
                row.querySelector(
                    'input[name="person_name[]"]'
                );


            const number1 =
                row.querySelector(
                    'input[name="number1[]"]'
                );


            const number2 =
                row.querySelector(
                    'input[name="number2[]"]'
                );


            const number3 =
                row.querySelector(
                    'input[name="number3[]"]'
                );


            const relation1 =
                row.querySelector(
                    'input[name="relation1[]"]'
                );


            const nameValue =
                name
                    ? name.value.trim()
                    : '';


            const number1Value =
                number1
                    ? number1.value.trim()
                    : '';


            const number2Value =
                number2
                    ? number2.value.trim()
                    : '';


            const number3Value =
                number3
                    ? number3.value.trim()
                    : '';


            const relation1Value =
                relation1
                    ? relation1.value.trim()
                    : '';


            output.push(

                nameValue +

                '\t' +

                '' +

                '\t' +

                '' +

                '\t' +

                '' +

                '\t' +

                number1Value +

                '\t' +

                number2Value +

                '\t' +

                number3Value +

                '\t' +

                relation1Value

            );

        }
    );


    const text =
        output.join(
            '\n'
        );


    if (

        navigator.clipboard &&

        window.isSecureContext

    ) {


        navigator.clipboard
            .writeText(
                text
            )
            .then(
                function()
                {

                    showCopied();

                }
            )
            .catch(
                function()
                {

                    fallbackCopy(
                        text
                    );

                }
            );


    }

    else {

        fallbackCopy(
            text
        );

    }

}


/* =========================================================
   FALLBACK COPY
========================================================= */

function fallbackCopy(
    text
)
{

    const textarea =
        document.createElement(
            'textarea'
        );


    textarea.value =
        text;


    textarea.style.position =
        'fixed';


    textarea.style.left =
        '-9999px';


    document.body.appendChild(
        textarea
    );


    textarea.focus();


    textarea.select();


    try {


        document.execCommand(
            'copy'
        );


        showCopied();


    }

    catch (
        error
    ) {


        alert(
            'Copy failed. Please copy manually.'
        );

    }


    document.body.removeChild(
        textarea
    );

}


/* =========================================================
   COPIED
========================================================= */

function showCopied()
{

    const button =
        document.querySelector(
            '.copy-btn'
        );


    if (!button) {

        return;

    }


    const oldText =
        button.innerHTML;


    button.innerHTML =
        '✓ Copied!';


    button.classList.add(
        'copied'
    );


    setTimeout(
        function()
        {


            button.innerHTML =
                oldText;


            button.classList.remove(
                'copied'
            );


        },
        2000
    );

}


/* =========================================================
   CONFIRM ADD
========================================================= */

function confirmAdd()
{

    const company =
        document.querySelector(
            'input[name="company_name"]'
        ).value.trim();


    const scheme =
        document.querySelector(
            'input[name="scheme_name"]'
        ).value.trim();


    const areas =
        document.querySelectorAll(
            'input[name="areas[]"]:checked'
        );


    const rows =
        document.querySelectorAll(
            '#contactTable tbody tr'
        );


    if (
        company === ''
    ) {

        alert(
            'Please enter Company Name.'
        );


        return false;

    }


    if (
        scheme === ''
    ) {

        alert(
            'Please enter Scheme Name.'
        );


        return false;

    }


    if (
        areas.length === 0
    ) {

        alert(
            'Please select at least one Area.'
        );


        const areaBox =
            document.querySelector(
                '.area-box'
            );


        if (areaBox) {

            areaBox.scrollIntoView({

                behavior:
                    'smooth',

                block:
                    'center'

            });

        }


        return false;

    }


    if (
        rows.length === 0
    ) {

        alert(
            'No contacts available.'
        );


        return false;

    }


    /* =====================================================
       COLLECT MAIN PERSONS FOR CONFIRMATION
    ===================================================== */

    let mainPersons =
        [];


    rows.forEach(
        function(row)
        {


            const mainSelect =
                row.querySelector(
                    'select[name="main[]"]'
                );


            const nameInput =
                row.querySelector(
                    'input[name="person_name[]"]'
                );


            if (

                mainSelect &&

                mainSelect.value === 'main' &&

                nameInput &&

                nameInput.value.trim() !== ''

            ) {


                const personName =
                    nameInput.value.trim();


                if (
                    !mainPersons.includes(
                        personName
                    )
                ) {

                    mainPersons.push(
                        personName
                    );

                }

            }

        }
    );


    let areaNames =
        [];


    areas.forEach(
        function(area)
        {

            areaNames.push(
                area.value
            );

        }
    );


    let message =

        'Add ' +
        rows.length +
        ' contacts to database?\n\n' +

        'Company: ' +
        company +
        '\n' +

        'Scheme: ' +
        scheme +
        '\n' +

        'Area: ' +
        areaNames.join(
            ', '
        ) +
        '\n\n' +

        'Main Persons: ';


    if (
        mainPersons.length > 0
    ) {

        message +=
            mainPersons.join(
                ', '
            );

    }

    else {

        message +=
            'None selected';

    }


    message +=

        '\n\n' +

        'A new Group ID will be created.\n' +

        'Previous same-name scheme history will also be updated.';


    return confirm(
        message
    );

}


/* =========================================================
   CLEAR
========================================================= */

function clearAll()
{

    const contacts =
        document.getElementById(
            'contacts'
        );


    if (contacts) {

        contacts.value =
            '';

    }


    const company =
        document.querySelector(
            'input[name="company_name"]'
        );


    if (company) {

        company.value =
            '';

    }


    const scheme =
        document.querySelector(
            'input[name="scheme_name"]'
        );


    if (scheme) {

        scheme.value =
            '';

    }


    document
        .querySelectorAll(
            'input[name="areas[]"]'
        )
        .forEach(
            function(item)
            {

                item.checked =
                    false;

            }
        );


    const areaError =
        document.getElementById(
            'area-error'
        );


    if (areaError) {

        areaError.style.display =
            'none';

    }


    const preview =
        document.querySelector(
            '.preview'
        );


    if (preview) {

        preview.remove();

    }


    document
        .querySelectorAll(
            '.success, .error'
        )
        .forEach(
            function(item)
            {

                item.remove();

            }
        );

}


/* =========================================================
   INITIALIZE MAIN ROWS
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function()
    {


        document
            .querySelectorAll(
                '.main-select'
            )
            .forEach(
                function(select)
                {

                    updateMainRow(
                        select
                    );

                }
            );


    }
);


function copyContactRow(button) {
    var row = button.closest('tr');

    if (!row) {
        alert('Contact row not found.');
        return false;
    }

    /*
     * Read the VALUES DIRECTLY FROM THE CLICKED ROW'S TABLE CELLS.
     * This deliberately does not depend on input names, PHP variables,
     * or document.querySelector() outside this row.
     */
    var cells = row.querySelectorAll('td');

    function readCell(index) {
        if (!cells[index]) return '';

        var input = cells[index].querySelector('input, textarea, select');

        if (input) {
            return String(input.value || '').trim();
        }

        return String(cells[index].innerText || cells[index].textContent || '')
            .replace(/\\s+/g, ' ')
            .trim();
    }

    var name = readCell(2);
    var number1 = readCell(3);
    var number2 = readCell(4);
    var number3 = readCell(5);
    var relation1 = readCell(6);

    /*
     * EXACT COPY ORDER:
     * Name | Number 1 | Number 2 | Number 3 | Relation 1
     *
     * Relation 1 is intentionally the LAST value.
     * Tabs preserve empty columns when pasted into Excel.
     */
    var text = [
        name,
        number1,
        number2,
        number3,
        relation1
    ].join('\t');

    if (!name && !number1 && !number2 && !number3 && !relation1) {
        alert('Nothing to copy in this row.');
        return false;
    }

    copyContactText(text, button);

    return false;
}


function copyContactText(text, button) {

    if (
        navigator.clipboard &&
        typeof navigator.clipboard.writeText === 'function'
    ) {
        navigator.clipboard.writeText(text)
            .then(function() {
                copySuccess(button);
            })
            .catch(function() {
                copyContactLegacy(text, button);
            });

        return;
    }

    copyContactLegacy(text, button);
}


function copyContactLegacy(text, button) {

    var textarea = document.createElement('textarea');

    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');

    textarea.style.position = 'fixed';
    textarea.style.left = '-99999px';
    textarea.style.top = '0';
    textarea.style.width = '2px';
    textarea.style.height = '2px';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';

    document.body.appendChild(textarea);

    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    var success = false;

    try {
        success = document.execCommand('copy');
    } catch (e) {
        success = false;
    }

    document.body.removeChild(textarea);

    if (success) {
        copySuccess(button);
    } else {
        /*
         * Last-resort fallback displays the COMPLETE copied content,
         * including Relation 1.
         */
        window.prompt('Copy this contact data:', text);
    }
}


function copySuccess(button) {

    var original = button.innerHTML;

    button.innerHTML = '✓ Copied';
    button.classList.add('copied');

    setTimeout(function() {
        button.innerHTML = original;
        button.classList.remove('copied');
    }, 1400);
}


</script>


</body>

</html>

