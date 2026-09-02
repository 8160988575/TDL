<?php

/*
|--------------------------------------------------------------------------
| CONTACT EXCEL GENERATOR - CORE PHP ONLY
|--------------------------------------------------------------------------
| No database
| No Composer
| No external library
|
| Excel format:
| Column 1 = Name
| Column 2 = Blank
| Column 3 = Blank
| Column 4 = Blank
| Column 5 = Mobile
| Column 6 = Mobile 2
| Column 7 = Mobile 3
| Column 8 = Mobile 4
|--------------------------------------------------------------------------
*/

$contacts = [];
$error = '';
$success = '';
$input = '';


// ---------------------------------------------------------
// NORMALIZE NAME
// ---------------------------------------------------------

function normalizeName($name)
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);

    return strtoupper($name);
}


// ---------------------------------------------------------
// CLEAN EMAIL
// ---------------------------------------------------------

function cleanEmail($email)
{
    $email = trim($email);

    // Remove markdown escaping
    $email = str_replace('\@', '@', $email);

    return $email;
}


// ---------------------------------------------------------
// CLEAN MOBILE
// ---------------------------------------------------------

function cleanMobile($mobile)
{
    $mobile = trim($mobile);

    // Keep only numbers
    $mobile = preg_replace('/[^0-9]/', '', $mobile);

    return $mobile;
}


// ---------------------------------------------------------
// PARSE CONTACTS
// ---------------------------------------------------------

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

    Name: JOHN PATEL
    Email Id: john@gmail.com
    Mobile: 9876543210
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

        $name = trim($match[1]);

        $email = cleanEmail($match[2]);

        $mobile = cleanMobile($match[3]);


        if ($name === '' || $mobile === '') {
            continue;
        }


        /*
        -----------------------------------------------------
        DUPLICATE NAME KEY
        -----------------------------------------------------
        */

        $nameKey = normalizeName($name);


        /*
        -----------------------------------------------------
        CREATE PERSON
        -----------------------------------------------------
        */

        if (!isset($result[$nameKey])) {

            $result[$nameKey] = [
                'name' => $name,
                'emails' => [],
                'mobiles' => []
            ];
        }


        /*
        -----------------------------------------------------
        UNIQUE EMAIL
        -----------------------------------------------------
        */

        if (
            $email !== '' &&
            !in_array(
                $email,
                $result[$nameKey]['emails'],
                true
            )
        ) {

            $result[$nameKey]['emails'][] = $email;
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

            $result[$nameKey]['mobiles'][] = $mobile;
        }
    }


    return $result;
}


// ---------------------------------------------------------
// DOWNLOAD EXCEL
// ---------------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['download_excel']) &&
    $_POST['download_excel'] === '1'
) {

    $input = $_POST['contacts'] ?? '';

    $contacts = parseContacts($input);


    if (empty($contacts)) {

        die('No valid contacts found.');
    }


    $filename =
        'contact_list_' .
        date('Y-m-d_H-i-s') .
        '.xls';


    /*
    ---------------------------------------------------------
    EXCEL HEADERS
    ---------------------------------------------------------
    */

    header(
        'Content-Type: application/vnd.ms-excel; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    header('Cache-Control: max-age=0');


    echo '<html>';

    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '</head>';

    echo '<body>';


    echo '<table border="1">';


    /*
    ---------------------------------------------------------
    TABLE HEADER
    ---------------------------------------------------------
    */

    echo '<tr>';

    echo '<th>Name</th>';

    echo '<th></th>';

    echo '<th></th>';

    echo '<th></th>';

    echo '<th>Mobile</th>';

    echo '<th>Mobile 2</th>';

    echo '<th>Mobile 3</th>';

    echo '<th>Mobile 4</th>';

    echo '</tr>';


    /*
    ---------------------------------------------------------
    TABLE DATA
    ---------------------------------------------------------
    */

    foreach ($contacts as $contact) {

        echo '<tr>';


        // NAME

        echo '<td>';

        echo htmlspecialchars(
            $contact['name'],
            ENT_QUOTES,
            'UTF-8'
        );

        echo '</td>';


        // BLANK COLUMNS 2,3,4

        echo '<td></td>';

        echo '<td></td>';

        echo '<td></td>';


        /*
        -----------------------------------------------------
        MOBILE COLUMNS 5-8
        -----------------------------------------------------
        */

        for ($i = 0; $i < 4; $i++) {

            echo '<td style="mso-number-format:\@;">';


            if (isset($contact['mobiles'][$i])) {

                echo htmlspecialchars(
                    $contact['mobiles'][$i],
                    ENT_QUOTES,
                    'UTF-8'
                );
            }


            echo '</td>';
        }


        echo '</tr>';
    }


    echo '</table>';

    echo '</body>';

    echo '</html>';

    exit;
}


// ---------------------------------------------------------
// NORMAL PREVIEW
// ---------------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['download_excel'])
) {

    $input = $_POST['contacts'] ?? '';

    $contacts = parseContacts($input);


    if (empty($contacts)) {

        $error =
            'No valid contacts found. Please check the pasted format.';

    } else {

        $success =
            count($contacts) .
            ' unique contacts found successfully.';
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
    Contact Excel Generator
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

    width: 100%;

    max-width: 1200px;

    margin: 40px auto;

    padding: 0 20px;
}


/* =========================================================
   CARD
========================================================= */

.card {

    background: #ffffff;

    border-radius: 14px;

    overflow: hidden;

    box-shadow:
        0 8px 35px
        rgba(0, 0, 0, 0.08);
}


/* =========================================================
   HEADER
========================================================= */

.header {

    background: #1f4e78;

    color: #ffffff;

    padding: 30px;
}


.header h1 {

    margin: 0 0 8px;

    font-size: 28px;
}


.header p {

    margin: 0;

    font-size: 14px;

    opacity: 0.9;
}


/* =========================================================
   CONTENT
========================================================= */

.content {

    padding: 30px;
}


/* =========================================================
   INFO BOX
========================================================= */

.info {

    background: #eef6ff;

    border-left:
        4px solid #1f4e78;

    border-radius: 7px;

    padding: 15px 18px;

    margin-bottom: 25px;

    font-size: 14px;

    line-height: 1.6;
}


/* =========================================================
   ERROR
========================================================= */

.error {

    background: #fff1f2;

    border-left:
        4px solid #dc2626;

    color: #991b1b;

    padding: 14px 16px;

    border-radius: 7px;

    margin-bottom: 20px;
}


/* =========================================================
   SUCCESS
========================================================= */

.success {

    background: #ecfdf5;

    border-left:
        4px solid #16a34a;

    color: #166534;

    padding: 14px 16px;

    border-radius: 7px;

    margin-bottom: 20px;
}


/* =========================================================
   LABEL
========================================================= */

label {

    display: block;

    font-weight: bold;

    margin-bottom: 10px;
}


/* =========================================================
   TEXTAREA
========================================================= */

textarea {

    width: 100%;

    min-height: 430px;

    resize: vertical;

    padding: 16px;

    border:
        1px solid #d1d5db;

    border-radius: 9px;

    outline: none;

    font-family:
        Consolas,
        monospace;

    font-size: 14px;

    line-height: 1.6;

    color: #111827;

    background: #ffffff;
}


textarea:focus {

    border-color:
        #1f4e78;

    box-shadow:
        0 0 0 3px
        rgba(31, 78, 120, 0.12);
}


/* =========================================================
   BUTTON AREA
========================================================= */

.buttons {

    display: flex;

    gap: 12px;

    flex-wrap: wrap;

    margin-top: 18px;
}


/* =========================================================
   GENERAL BUTTON
========================================================= */

button {

    border: none;

    border-radius: 8px;

    padding: 13px 22px;

    font-size: 14px;

    font-weight: bold;

    cursor: pointer;

    transition: 0.2s;
}


/* =========================================================
   PREVIEW BUTTON
========================================================= */

.generate {

    background: #1f4e78;

    color: #ffffff;
}


.generate:hover {

    background: #173a5a;
}


/* =========================================================
   DOWNLOAD BUTTON
========================================================= */

.download {

    background: #15803d;

    color: #ffffff;
}


.download:hover {

    background: #166534;
}


/* =========================================================
   CLEAR BUTTON
========================================================= */

.clear {

    background: #e5e7eb;

    color: #374151;
}


.clear:hover {

    background: #d1d5db;
}


/* =========================================================
   PREVIEW
========================================================= */

.preview {

    margin-top: 35px;
}


/* =========================================================
   PREVIEW HEADER
========================================================= */

.preview-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    margin-bottom: 15px;
}


.preview-header h2 {

    margin: 0;

    font-size: 20px;
}


/* =========================================================
   COPY BUTTON
========================================================= */

.copy-btn {

    background: #2563a6;

    color: #ffffff;

    border: none;

    border-radius: 8px;

    padding: 10px 18px;

    font-size: 14px;

    font-weight: bold;

    cursor: pointer;

    transition: 0.2s;
}


.copy-btn:hover {

    background: #1d4f85;
}


.copy-btn.copied {

    background: #15803d;
}


/* =========================================================
   TABLE WRAPPER
========================================================= */

.table-wrapper {

    overflow-x: auto;

    border:
        1px solid #e5e7eb;

    border-radius: 8px;
}


/* =========================================================
   TABLE
========================================================= */

table {

    width: 100%;

    min-width: 850px;

    border-collapse: collapse;
}


th {

    background: #1f4e78;

    color: #ffffff;

    padding: 11px;

    text-align: left;

    font-size: 13px;
}


td {

    border:
        1px solid #e5e7eb;

    padding: 10px;

    font-size: 13px;

    white-space: nowrap;
}


tbody tr:hover {

    background: #f8fafc;
}


/* =========================================================
   FOOTER
========================================================= */

.footer {

    text-align: center;

    padding: 18px;

    font-size: 12px;

    color: #6b7280;

    border-top:
        1px solid #f1f5f9;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 600px) {

    .container {

        margin: 15px auto;

        padding: 0 10px;
    }


    .content {

        padding: 20px;
    }


    .header {

        padding: 22px;
    }


    .header h1 {

        font-size: 23px;
    }


    textarea {

        min-height: 350px;
    }


    .preview-header {

        flex-direction: column;

        align-items: flex-start;
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
    Contact Excel Generator
</h1>

<p>
    Paste your contact list and clean duplicate contacts automatically.
</p>

</div>


<!-- =====================================================
     CONTENT
===================================================== -->

<div class="content">


<!-- =====================================================
     INFO
===================================================== -->

<div class="info">

<strong>Excel Format:</strong>

<br>

Column 1 = Name &nbsp; | &nbsp;

Columns 2–4 = Blank &nbsp; | &nbsp;

Column 5 = Mobile &nbsp; | &nbsp;

Columns 6–8 = Additional Mobile Numbers

<br><br>

If the same name has multiple different mobile numbers,
they will automatically be placed in Mobile 2, Mobile 3
and Mobile 4.

Same name + same number will be kept only once.

</div>


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
     FORM
===================================================== -->

<form method="POST">


<label for="contacts">

Paste Contact Data

</label>


<textarea
    id="contacts"
    name="contacts"
    placeholder="Paste your contact data here...

Example:

**Name:**JOHN PATEL

**Email Id:**john@gmail.com

**Mobile:**9876543210

**Name:**JOHN PATEL

**Email Id:**john2@gmail.com

**Mobile:**9999999999
"><?= htmlspecialchars(
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
    class="generate"
>

    Generate Preview

</button>


<button
    type="submit"
    name="download_excel"
    value="1"
    class="download"
>

    Download Excel

</button>


<button
    type="button"
    class="clear"
    onclick="clearData()"
>

    Clear

</button>


</div>


</form>


<!-- =====================================================
     PREVIEW
===================================================== -->

<?php if (!empty($contacts)): ?>

<div class="preview">


<div class="preview-header">


<h2>

Cleaned Contact Preview

</h2>


<button
    type="button"
    class="copy-btn"
    onclick="copyTable()"
>

📋 Copy Table

</button>


</div>


<div class="table-wrapper">


<table id="contactTable">


<thead>

<tr>

<th>Name</th>

<th></th>

<th></th>

<th></th>

<th>Mobile</th>

<th>Mobile 2</th>

<th>Mobile 3</th>

<th>Mobile 4</th>

</tr>

</thead>


<tbody>


<?php foreach ($contacts as $contact): ?>

<tr>


<!-- NAME -->

<td>

<?= htmlspecialchars(
    $contact['name'],
    ENT_QUOTES,
    'UTF-8'
) ?>

</td>


<!-- BLANK COLUMNS -->

<td></td>

<td></td>

<td></td>


<!-- MOBILE COLUMNS -->

<?php for ($i = 0; $i < 4; $i++): ?>

<td>

<?php

if (isset($contact['mobiles'][$i])) {

    echo htmlspecialchars(
        $contact['mobiles'][$i],
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

</td>

<?php endfor; ?>


</tr>

<?php endforeach; ?>


</tbody>

</table>

</div>

</div>

<?php endif; ?>


</div>


<!-- =====================================================
     FOOTER
===================================================== -->

<div class="footer">

Core PHP • No Database • No Composer • No External Library

</div>


</div>

</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>


/*
|--------------------------------------------------------------------------
| CLEAR DATA
|--------------------------------------------------------------------------
*/

function clearData()
{

    document.getElementById(
        'contacts'
    ).value = '';

}


/*
|--------------------------------------------------------------------------
| COPY TABLE
|--------------------------------------------------------------------------
|
| Copies the table as TAB separated data.
|
| You can directly paste it into:
|
| Excel
| Google Sheets
| LibreOffice
|
|--------------------------------------------------------------------------
*/

function copyTable()
{

    const table =
        document.getElementById(
            'contactTable'
        );


    if (!table) {

        return;
    }


    let rows = [];


    /*
    ---------------------------------------------------------
    GET ALL TABLE ROWS
    ---------------------------------------------------------
    */

    const tableRows =
        table.querySelectorAll(
            'tr'
        );


    tableRows.forEach(
        function(row)
        {

            let cells = [];


            row.querySelectorAll(
                'th, td'
            ).forEach(
                function(cell)
                {

                    let text =
                        cell.innerText
                            .replace(
                                /\r?\n|\r/g,
                                ''
                            )
                            .trim();


                    cells.push(text);

                }
            );


            /*
            -------------------------------------------------
            TAB SEPARATED
            -------------------------------------------------
            */

            rows.push(
                cells.join('\t')
            );

        }
    );


    const text =
        rows.join('\n');


    /*
    ---------------------------------------------------------
    COPY TO CLIPBOARD
    ---------------------------------------------------------
    */

    if (
        navigator.clipboard &&
        window.isSecureContext
    ) {

        navigator.clipboard.writeText(text)

        .then(
            function()
            {

                showCopied();

            }
        )

        .catch(
            function()
            {

                fallbackCopy(text);

            }
        );

    } else {

        fallbackCopy(text);

    }

}


/*
|--------------------------------------------------------------------------
| FALLBACK COPY
|--------------------------------------------------------------------------
*/

function fallbackCopy(text)
{

    const textarea =
        document.createElement(
            'textarea'
        );


    textarea.value = text;


    textarea.style.position =
        'fixed';

    textarea.style.left =
        '-9999px';


    document.body.appendChild(
        textarea
    );


    textarea.select();


    try {

        document.execCommand(
            'copy'
        );

        showCopied();

    } catch (error) {

        alert(
            'Copy failed. Please select the table manually.'
        );

    }


    document.body.removeChild(
        textarea
    );

}


/*
|--------------------------------------------------------------------------
| COPIED MESSAGE
|--------------------------------------------------------------------------
*/

function showCopied()
{

    const button =
        document.querySelector(
            '.copy-btn'
        );


    if (!button) {

        return;
    }


    const originalText =
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
                originalText;


            button.classList.remove(
                'copied'
            );

        },
        2000
    );

}

</script>


</body>

</html>

