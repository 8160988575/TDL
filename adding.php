
<?php

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
    die('Database connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($con, 'utf8mb4');


/* =========================================================
   VARIABLES
========================================================= */

$contacts = [];

$error = '';

$success = '';

$input = '';

$company_name = '';

$scheme_name = '';


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

    return str_replace(
        '\@',
        '@',
        $email
    );
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
   PARSE CONTACTS
========================================================= */

function parseContacts($input)
{
    $result = [];


    /*
    ---------------------------------------------------------
    FORMAT 1

    **Name:**JOHN PATEL

    **Email Id:**john@gmail.com

    **Mobile:**9876543210
    ---------------------------------------------------------
    */

    preg_match_all(

        '/\*\*Name:\*\*\s*(.*?)\s*\*\*Email Id:\*\*\s*(.*?)\s*\*\*Mobile:\*\*\s*([0-9]+)/is',

        $input,

        $matches,

        PREG_SET_ORDER

    );


    /*
    ---------------------------------------------------------
    FORMAT 2
    ---------------------------------------------------------
    */

    if (empty($matches)) {

        preg_match_all(

            '/Name:\s*(.*?)\s*Email Id:\s*(.*?)\s*Mobile:\s*([0-9]+)/is',

            $input,

            $matches,

            PREG_SET_ORDER

        );

    }


    foreach ($matches as $match) {

        $name =
            trim($match[1]);


        $email =
            cleanEmail($match[2]);


        $mobile =
            cleanMobile($match[3]);


        if (
            $name === '' ||
            $mobile === ''
        ) {

            continue;

        }


        $nameKey =
            normalizeName($name);


        /*
        -----------------------------------------------------
        CREATE CONTACT
        -----------------------------------------------------
        */

        if (!isset($result[$nameKey])) {

            $result[$nameKey] = [

                'name' => $name,

                'email' => $email,

                'mobiles' => [],

                'relation1' => '',

                'relation2' => '',

                'main' => 0

            ];

        }


        /*
        -----------------------------------------------------
        UNIQUE MOBILE
        -----------------------------------------------------
        */

        if (
            !in_array(
                $mobile,
                $result[$nameKey]['mobiles'],
                true
            )
        ) {

            $result[$nameKey]['mobiles'][] =
                $mobile;

        }

    }


    return $result;
}


/* =========================================================
   ADD TO DATABASE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_to_database'])
) {


    $company_name =
        trim(
            $_POST['company_name'] ?? ''
        );


    $scheme_name =
        trim(
            $_POST['scheme_name'] ?? ''
        );


    $names =
        $_POST['person_name'] ?? [];


    $number1 =
        $_POST['number1'] ?? [];


    $number2 =
        $_POST['number2'] ?? [];


    $number3 =
        $_POST['number3'] ?? [];


    $relation1 =
        $_POST['relation1'] ?? [];


    $relation2 =
        $_POST['relation2'] ?? [];


    $main =
        $_POST['main'] ?? [];


    /*
    ---------------------------------------------------------
    VALIDATION
    ---------------------------------------------------------
    */

    if ($company_name === '') {

        $error =
            'Please enter Company Name.';

    }

    elseif ($scheme_name === '') {

        $error =
            'Please enter Scheme Name.';

    }

    elseif (empty($names)) {

        $error =
            'No contacts found.';

    }

    else {


        /*
        -----------------------------------------------------
        PREPARE SQL
        -----------------------------------------------------
        */

        $sql = "

            INSERT INTO data

            (
                company_name,
                scheme_name,
                name,
                number1,
                number2,
                number3,
                relation1,
                relation2,
                `main`
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
                ?
            )

        ";


        $stmt =
            mysqli_prepare(
                $con,
                $sql
            );


        if (!$stmt) {

            $error =
                'Database query error: ' .
                mysqli_error($con);

        }

        else {


            $inserted =
                0;


            $failed =
                0;


            /*
            -------------------------------------------------
            INSERT EACH PERSON
            -------------------------------------------------
            */

            foreach (
                $names as $key => $name
            ) {


                $name =
                    trim($name);


                if ($name === '') {

                    continue;

                }


                /*
                -------------------------------------------------
                NUMBERS
                -------------------------------------------------
                */

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


                /*
                -------------------------------------------------
                RELATIONS
                -------------------------------------------------
                */

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


                /*
                -------------------------------------------------
                MAIN
                -------------------------------------------------
                */

                $m =
                    isset(
                        $main[$key]
                    )
                    ? (int)$main[$key]
                    : 0;


                /*
                -------------------------------------------------
                ONLY 0 OR 1
                -------------------------------------------------
                */

                if ($m !== 1) {

                    $m = 0;

                }


                /*
                -------------------------------------------------
                BIND
                -------------------------------------------------
                */

                mysqli_stmt_bind_param(

                    $stmt,

                    'ssssssssi',

                    $company_name,

                    $scheme_name,

                    $name,

                    $n1,

                    $n2,

                    $n3,

                    $r1,

                    $r2,

                    $m

                );


                /*
                -------------------------------------------------
                EXECUTE
                -------------------------------------------------
                */

                if (
                    mysqli_stmt_execute(
                        $stmt
                    )
                ) {

                    $inserted++;

                }

                else {

                    $failed++;

                }

            }


            mysqli_stmt_close(
                $stmt
            );


            /*
            -------------------------------------------------
            RESULT
            -------------------------------------------------
            */

            if ($failed === 0) {

                $success =
                    $inserted .
                    ' contacts added successfully.';

            }

            else {

                $success =
                    $inserted .
                    ' contacts added successfully. ' .
                    $failed .
                    ' failed.';

            }

        }

    }

}


/* =========================================================
   GENERATE PREVIEW
========================================================= */

elseif (
    $_SERVER['REQUEST_METHOD'] === 'POST'
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


    $contacts =
        parseContacts(
            $input
        );


    if (empty($contacts)) {

        $error =
            'No valid contacts found. Please check the pasted format.';

    }

    else {

        $success =
            count($contacts) .
            ' unique contacts found.';

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
   RESET
========================================================= */

* {
    box-sizing: border-box;
}


/* =========================================================
   BODY
========================================================= */

body {

    margin: 0;

    padding: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        #f3f6fa;

    color:
        #1f2937;

}


/* =========================================================
   CONTAINER
========================================================= */

.container {

    max-width:
        1350px;

    margin:
        35px auto;

    padding:
        0 20px;

}


/* =========================================================
   CARD
========================================================= */

.card {

    background:
        #ffffff;

    border-radius:
        15px;

    overflow:
        hidden;

    box-shadow:
        0 8px 35px
        rgba(
            0,
            0,
            0,
            0.08
        );

}


/* =========================================================
   HEADER
========================================================= */

.header {

    background:
        #1f4e78;

    color:
        #ffffff;

    padding:
        28px 30px;

}


.header h1 {

    margin:
        0 0 8px;

    font-size:
        28px;

}


.header p {

    margin:
        0;

    font-size:
        14px;

    opacity:
        0.9;

}


/* =========================================================
   CONTENT
========================================================= */

.content {

    padding:
        30px;

}


/* =========================================================
   COMPANY / SCHEME
========================================================= */

.form-grid {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        18px;

    margin-bottom:
        25px;

}


.field label {

    display:
        block;

    font-weight:
        bold;

    margin-bottom:
        8px;

}


.field input {

    width:
        100%;

    padding:
        13px 14px;

    border:
        1px solid #d1d5db;

    border-radius:
        8px;

    outline:
        none;

    font-size:
        14px;

}


.field input:focus {

    border-color:
        #1f4e78;

    box-shadow:
        0 0 0 3px
        rgba(
            31,
            78,
            120,
            0.10
        );

}


/* =========================================================
   INFO
========================================================= */

.info {

    background:
        #eef6ff;

    border-left:
        4px solid #1f4e78;

    padding:
        15px 18px;

    border-radius:
        7px;

    margin-bottom:
        22px;

    font-size:
        14px;

    line-height:
        1.6;

}


/* =========================================================
   ERROR
========================================================= */

.error {

    background:
        #fff1f2;

    border-left:
        4px solid #dc2626;

    color:
        #991b1b;

    padding:
        14px 16px;

    border-radius:
        7px;

    margin-bottom:
        20px;

}


/* =========================================================
   SUCCESS
========================================================= */

.success {

    background:
        #ecfdf5;

    border-left:
        4px solid #16a34a;

    color:
        #166534;

    padding:
        14px 16px;

    border-radius:
        7px;

    margin-bottom:
        20px;

}


/* =========================================================
   LABEL
========================================================= */

.main-label {

    display:
        block;

    font-weight:
        bold;

    margin-bottom:
        10px;

}


/* =========================================================
   TEXTAREA
========================================================= */

textarea {

    width:
        100%;

    min-height:
        350px;

    padding:
        16px;

    resize:
        vertical;

    border:
        1px solid #d1d5db;

    border-radius:
        9px;

    outline:
        none;

    font-family:
        Consolas,
        monospace;

    font-size:
        14px;

    line-height:
        1.6;

}


textarea:focus {

    border-color:
        #1f4e78;

    box-shadow:
        0 0 0 3px
        rgba(
            31,
            78,
            120,
            0.10
        );

}


/* =========================================================
   BUTTONS
========================================================= */

.buttons {

    display:
        flex;

    gap:
        12px;

    flex-wrap:
        wrap;

    margin-top:
        18px;

}


button {

    border:
        none;

    border-radius:
        8px;

    padding:
        13px 22px;

    font-size:
        14px;

    font-weight:
        bold;

    cursor:
        pointer;

}


.preview-btn {

    background:
        #1f4e78;

    color:
        #ffffff;

}


.preview-btn:hover {

    background:
        #173a5a;

}


.add-btn {

    background:
        #15803d;

    color:
        #ffffff;

}


.add-btn:hover {

    background:
        #166534;

}


.clear-btn {

    background:
        #e5e7eb;

    color:
        #374151;

}


/* =========================================================
   PREVIEW
========================================================= */

.preview {

    margin-top:
        35px;

}


.preview-top {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom:
        15px;

}


.preview-top h2 {

    margin:
        0;

    font-size:
        20px;

}


/* =========================================================
   TABLE WRAPPER
========================================================= */

.table-wrapper {

    overflow-x:
        auto;

    border:
        1px solid #e5e7eb;

    border-radius:
        8px;

}


/* =========================================================
   TABLE
========================================================= */

table {

    width:
        100%;

    min-width:
        1150px;

    border-collapse:
        collapse;

}


th {

    background:
        #1f4e78;

    color:
        #ffffff;

    padding:
        12px;

    text-align:
        left;

    font-size:
        13px;

    white-space:
        nowrap;

}


td {

    border:
        1px solid #e5e7eb;

    padding:
        8px;

    font-size:
        13px;

    white-space:
        nowrap;

}


/* =========================================================
   EDITABLE INPUTS
========================================================= */

.edit-input {

    width:
        100%;

    min-width:
        130px;

    padding:
        8px 9px;

    border:
        1px solid #d1d5db;

    border-radius:
        6px;

    outline:
        none;

    font-size:
        13px;

}


.name-input {

    min-width:
        250px;

}


.number-input {

    min-width:
        120px;

}


.relation-input {

    min-width:
        130px;

}


.edit-input:focus {

    border-color:
        #2563a6;

    box-shadow:
        0 0 0 2px
        rgba(
            37,
            99,
            166,
            0.10
        );

}


/* =========================================================
   MAIN SELECT
========================================================= */

.main-select {

    padding:
        8px 10px;

    border:
        1px solid #d1d5db;

    border-radius:
        6px;

    outline:
        none;

    font-size:
        13px;

    cursor:
        pointer;

}


.main-select:focus {

    border-color:
        #2563a6;

}


/* =========================================================
   MAIN ROW
========================================================= */

tr.main-row {

    background:
        #ecfdf5;

}


tr.main-row td {

    border-color:
        #86efac;

}


/* =========================================================
   FOOTER
========================================================= */

.footer {

    text-align:
        center;

    padding:
        18px;

    border-top:
        1px solid #f1f5f9;

    color:
        #6b7280;

    font-size:
        12px;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    .container {

        margin:
            15px auto;

        padding:
            0 10px;

    }


    .content {

        padding:
            20px;

    }


    .header {

        padding:
            22px;

    }


    .header h1 {

        font-size:
            23px;

    }


    .form-grid {

        grid-template-columns:
            1fr;

    }


    textarea {

        min-height:
            300px;

    }


    .preview-top {

        align-items:
            flex-start;

        flex-direction:
            column;

        gap:
            10px;

    }

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

<h1>
    TDL Contact Manager
</h1>

<p>
    Add and edit contact details before saving to database
</p>

</div>


<div class="content">


<!-- =====================================================
     ERROR
===================================================== -->

<?php if ($error !== ''): ?>

<div class="error">

<?= htmlspecialchars(
    $error,
    ENT_QUOTES,
    'UTF-8'
) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     SUCCESS
===================================================== -->

<?php if ($success !== ''): ?>

<div class="success">

<?= htmlspecialchars(
    $success,
    ENT_QUOTES,
    'UTF-8'
) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     MAIN FORM
===================================================== -->

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
    value="<?= htmlspecialchars(
        $company_name,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
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
    value="<?= htmlspecialchars(
        $scheme_name,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    placeholder="Enter Scheme Name"
    required
>

</div>


</div>


<!-- =====================================================
     INFO
===================================================== -->

<div class="info">

<strong>
    Edit everything before adding:
</strong>

<br>

You can change <strong>Name</strong>,
<strong>Mobile 1</strong>,
<strong>Mobile 2</strong>,
<strong>Mobile 3</strong>,
<strong>Relation 1</strong>,
<strong>Relation 2</strong>
and <strong>Main</strong> directly in the preview.

<br><br>

<strong>Main = Yes</strong> will save as
<code>main = 1</code>.

<strong>Main = No</strong> will save as
<code>main = 0</code>.

</div>


<!-- =====================================================
     INPUT
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
><?= htmlspecialchars(
    $input,
    ENT_QUOTES,
    'UTF-8'
) ?></textarea>


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


<?php if (!empty($contacts)): ?>

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

<?php if (!empty($contacts)): ?>


<div class="preview">


<div class="preview-top">


<h2>
    Editable Contact Preview
</h2>


<div>

<strong>
    <?= count($contacts) ?>
</strong>

Contacts

</div>


</div>


<div class="table-wrapper">


<table id="contactTable">


<thead>


<tr>

<th>
    Main
</th>

<th>
    Name
</th>

<th>
    Mobile 1
</th>

<th>
    Mobile 2
</th>

<th>
    Mobile 3
</th>

<th>
    Relation 1
</th>

<th>
    Relation 2
</th>

</tr>


</thead>


<tbody>


<?php foreach (
    $contacts
    as $key => $contact
): ?>


<tr>


<!-- =====================================================
     MAIN
===================================================== -->

<td>


<select
    name="main[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    class="main-select"
    onchange="changeMain(this);"
>


<option
    value="0"
    <?= (
        $contact['main'] == 0
        ? 'selected'
        : ''
    ) ?>
>

No

</option>


<option
    value="1"
    <?= (
        $contact['main'] == 1
        ? 'selected'
        : ''
    ) ?>
>

Yes

</option>


</select>


</td>


<!-- =====================================================
     NAME
===================================================== -->

<td>


<input
    type="text"
    name="person_name[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    value="<?= htmlspecialchars(
        $contact['name'],
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="edit-input name-input"
>


</td>


<!-- =====================================================
     NUMBER 1
===================================================== -->

<td>


<input
    type="text"
    name="number1[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    value="<?= htmlspecialchars(
        $contact['mobiles'][0]
        ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="edit-input number-input"
>


</td>


<!-- =====================================================
     NUMBER 2
===================================================== -->

<td>


<input
    type="text"
    name="number2[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    value="<?= htmlspecialchars(
        $contact['mobiles'][1]
        ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="edit-input number-input"
>


</td>


<!-- =====================================================
     NUMBER 3
===================================================== -->

<td>


<input
    type="text"
    name="number3[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    value="<?= htmlspecialchars(
        $contact['mobiles'][2]
        ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="edit-input number-input"
>


</td>


<!-- =====================================================
     RELATION 1
===================================================== -->

<td>


<input
    type="text"
    name="relation1[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    value="<?= htmlspecialchars(
        $contact['relation1'],
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="edit-input relation-input"
    placeholder="Relation 1"
>


</td>


<!-- =====================================================
     RELATION 2
===================================================== -->

<td>


<input
    type="text"
    name="relation2[<?= htmlspecialchars(
        $key,
        ENT_QUOTES,
        'UTF-8'
    ) ?>]"
    value="<?= htmlspecialchars(
        $contact['relation2'],
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    class="edit-input relation-input"
    placeholder="Relation 2"
>


</td>


</tr>


<?php endforeach; ?>


</tbody>


</table>


</div>


</div>


<?php endif; ?>


</form>


</div>


<!-- =====================================================
     FOOTER
===================================================== -->

<div class="footer">

TDL Contact Manager • Core PHP + MySQL • No Framework

</div>


</div>


</div>


<script>


/* =========================================================
   MAIN PERSON
========================================================= */

function changeMain(select)
{

    const selects =
        document.querySelectorAll(
            '.main-select'
        );


    /*
    ---------------------------------------------------------
    Only one Main = Yes
    ---------------------------------------------------------
    */

    if (select.value === '1') {

        selects.forEach(
            function(item)
            {

                if (item !== select) {

                    item.value = '0';

                }

            }
        );

    }


    /*
    ---------------------------------------------------------
    Highlight selected main row
    ---------------------------------------------------------
    */

    const rows =
        document.querySelectorAll(
            '#contactTable tbody tr'
        );


    rows.forEach(
        function(row)
        {

            row.classList.remove(
                'main-row'
            );

        }
    );


    selects.forEach(
        function(item)
        {

            if (item.value === '1') {

                item
                    .closest('tr')
                    .classList.add(
                        'main-row'
                    );

            }

        }
    );

}


/* =========================================================
   CONFIRM ADD
========================================================= */

function confirmAdd()
{

    const main =
        document.querySelector(
            '.main-select option[value="1"]:checked'
        );


    if (!main) {

        alert(
            'Please select one Main Person.'
        );

        return false;

    }


    const company =
        document.querySelector(
            'input[name="company_name"]'
        ).value.trim();


    const scheme =
        document.querySelector(
            'input[name="scheme_name"]'
        ).value.trim();


    if (!company) {

        alert(
            'Please enter Company Name.'
        );

        return false;

    }


    if (!scheme) {

        alert(
            'Please enter Scheme Name.'
        );

        return false;

    }


    return confirm(
        'Are you sure you want to add all contacts to the database?'
    );

}


/* =========================================================
   CLEAR
========================================================= */

function clearAll()
{

    const form =
        document.getElementById(
            'contactForm'
        );


    /*
    ---------------------------------------------------------
    Clear textarea
    ---------------------------------------------------------
    */

    document.getElementById(
        'contacts'
    ).value = '';


    /*
    ---------------------------------------------------------
    Clear company
    ---------------------------------------------------------
    */

    document.querySelector(
        'input[name="company_name"]'
    ).value = '';


    /*
    ---------------------------------------------------------
    Clear scheme
    ---------------------------------------------------------
    */

    document.querySelector(
        'input[name="scheme_name"]'
    ).value = '';


    /*
    ---------------------------------------------------------
    Remove preview
    ---------------------------------------------------------
    */

    const table =
        document.getElementById(
            'contactTable'
        );


    if (table) {

        table.closest(
            '.preview'
        ).remove();

    }


    /*
    ---------------------------------------------------------
    Remove success/error
    ---------------------------------------------------------
    */

    document.querySelectorAll(
        '.success, .error'
    ).forEach(
        function(item)
        {

            item.remove();

        }
    );

}


/* =========================================================
   INITIAL MAIN HIGHLIGHT
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function()
    {

        const selected =
            document.querySelector(
                '.main-select[value="1"]'
            );


        document
            .querySelectorAll(
                '.main-select'
            )
            .forEach(
                function(select)
                {

                    if (select.value === '1') {

                        select
                            .closest('tr')
                            .classList.add(
                                'main-row'
                            );

                    }

                }
            );

    }
);

</script>


</body>

</html>
```
