<?php
/* =========================================================
   THE DIVINE LANDS — GROUP DIRECTORY
   CORE PHP + MYSQL ONLY
   ========================================================= */

$con = @mysqli_connect('localhost','root','','tdl');

if (!$con) {
    die('Database connection failed: '.mysqli_connect_error());
}

mysqli_set_charset($con,'utf8mb4');

function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

$areas = ['naroda','zundal','sg highway','gift city','gandhinagar'];

$area = strtolower(trim($_GET['area'] ?? ''));
$search = trim($_GET['search'] ?? '');
$star_filter = isset($_GET['star']) && $_GET['star'] === '1' ? '1' : '';

if ($area !== '' && !in_array($area,$areas,true)) {
    $area = '';
}

/* =========================================================
   STAR / UNSTAR GROUP
   Uses the existing area.star field.
   The whole group's area rows are updated together.
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['star_action'])) {

    $grp_id = (int)($_POST['grp_id'] ?? 0);
    $new_star = ($_POST['star_action'] === 'star') ? 1 : 0;

    if ($grp_id > 0) {

        $update = mysqli_prepare(
            $con,
            "UPDATE area SET star=? WHERE grp_id=?"
        );

        if ($update) {
            mysqli_stmt_bind_param($update,'ii',$new_star,$grp_id);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
        }
    }

    /* Return to exactly the same filtered page. */
    $query = [];

    if ($search !== '') {
        $query['search'] = $search;
    }

    if ($area !== '') {
        $query['area'] = $area;
    }

    if ($star_filter !== '') {
        $query['star'] = $star_filter;
    }

    $redirect = 'listshow.php';

    if ($query) {
        $redirect .= '?' . http_build_query($query);
    }

    header('Location: '.$redirect);
    exit;
}

/* =========================================================
   GROUP DATA
   Area names and star status are taken from area table.
   ========================================================= */
$sql = "
    SELECT
        d.grp_id,
        MAX(d.company_name) AS company_name,
        MAX(d.scheme_name) AS scheme_name,

        GROUP_CONCAT(
            DISTINCT CASE
                WHEN LOWER(TRIM(COALESCE(d.main,'')))='main'
                THEN NULLIF(TRIM(d.name),'')
            END
            ORDER BY d.name
            SEPARATOR ', '
        ) AS main_persons,

        (
            SELECT GROUP_CONCAT(
                DISTINCT TRIM(a2.area)
                ORDER BY
                    FIELD(
                        LOWER(TRIM(a2.area)),
                        'naroda',
                        'zundal',
                        'sg highway',
                        'gift city',
                        'gandhinagar'
                    )
                SEPARATOR ', '
            )
            FROM area a2
            WHERE a2.grp_id=d.grp_id
              AND TRIM(COALESCE(a2.area,'')) <> ''
        ) AS area_names,

        (
            SELECT
                CASE
                    WHEN MAX(
                        CASE
                            WHEN LOWER(TRIM(COALESCE(a3.star,''))) IN ('star','1','yes','true')
                            THEN 1
                            ELSE 0
                        END
                    )=1
                    THEN 1
                    ELSE 0
                END
            FROM area a3
            WHERE a3.grp_id=d.grp_id
        ) AS is_starred

    FROM data d
";

$where = [];
$params = [];
$types = '';

if ($area !== '') {

    $where[] = "EXISTS (
        SELECT 1
        FROM area af
        WHERE af.grp_id=d.grp_id
          AND LOWER(TRIM(af.area))=?
    )";

    $params[] = $area;
    $types .= 's';
}

if ($star_filter === '1') {

    $where[] = "EXISTS (
        SELECT 1
        FROM area asf
        WHERE asf.grp_id=d.grp_id
          AND LOWER(TRIM(COALESCE(asf.star,''))) IN ('star','1','yes','true')
    )";
}

if ($search !== '') {

    $where[] = "(
        d.company_name LIKE ?
        OR d.scheme_name LIKE ?
        OR d.name LIKE ?
        OR CAST(d.grp_id AS CHAR) LIKE ?
    )";

    $like = '%'.$search.'%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'ssss';
}

if ($where) {
    $sql .= ' WHERE '.implode(' AND ',$where);
}

$sql .= "
    GROUP BY d.grp_id
    ORDER BY d.grp_id DESC
";

$stmt = mysqli_prepare($con,$sql);

if (!$stmt) {
    die('Query preparation failed: '.e(mysqli_error($con)));
}

if ($params) {
    mysqli_stmt_bind_param($stmt,$types,...$params);
}

mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$groups = [];

while ($row = mysqli_fetch_assoc($res)) {
    $groups[] = $row;
}

mysqli_stmt_close($stmt);

$total = count($groups);
$starred_count = 0;

foreach ($groups as $g) {
    if ((int)$g['is_starred'] === 1) {
        $starred_count++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>The Divine Lands | Groups</title>

<style>
*{box-sizing:border-box}

:root{
    --navy:#061a31;
    --navy2:#0d2f50;
    --blue:#3477aa;
    --gold:#e5b65a;
    --ink:#14283d;
    --muted:#78899b;
    --line:#e1e8ef;
    --bg:#f4f7fa;
    --green:#169653;
    --gold-bg:#fff9eb;
}

html,body{margin:0;min-height:100%;}

body{
    background:
        radial-gradient(circle at 0 0,rgba(229,182,90,.08),transparent 25%),
        radial-gradient(circle at 100% 15%,rgba(52,119,170,.08),transparent 28%),
        var(--bg);
    color:var(--ink);
    font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
}

.page{
    width:min(1480px,calc(100% - 36px));
    margin:0 auto;
    padding:24px 0 45px;
}

/* ---------- HERO ---------- */
.hero{
    position:relative;
    min-height:205px;
    overflow:hidden;
    padding:28px 34px;
    border-radius:24px;
    background:
        radial-gradient(circle at 72% 30%,rgba(63,126,177,.24),transparent 28%),
        linear-gradient(125deg,#04172d 0%,#092744 58%,#113d62 100%);
    color:#fff;
    box-shadow:0 22px 60px rgba(5,27,48,.18);
}

.hero:before{
    content:"";
    position:absolute;
    right:-90px;
    top:-180px;
    width:720px;
    height:440px;
    transform:rotate(-9deg);
    background:
        linear-gradient(120deg,transparent 47%,rgba(229,182,90,.09) 48%,transparent 49%),
        linear-gradient(120deg,transparent 58%,rgba(255,255,255,.04) 59%,transparent 60%);
}

.hero:after{
    content:"";
    position:absolute;
    right:-220px;
    bottom:-410px;
    width:520px;
    height:520px;
    border:1px solid rgba(229,182,90,.17);
    border-radius:50%;
}

.brand{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    gap:17px;
}

.logo{
    width:67px;
    height:67px;
    padding:7px;
    object-fit:contain;
    border-radius:17px;
    background:#fff;
    box-shadow:0 12px 28px rgba(0,0,0,.22);
}

.brand-small{
    margin-bottom:5px;
    color:var(--gold);
    font-size:11px;
    font-weight:900;
    letter-spacing:2.4px;
}

.hero h1{
    margin:0;
    color:#fff;
    font-size:34px;
    line-height:1.05;
    letter-spacing:-1px;
}

.hero-sub{
    margin-top:8px;
    color:#afc1d2;
    font-size:13px;
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

.hero-bottom{
    position:absolute;
    z-index:3;
    left:34px;
    right:34px;
    bottom:23px;
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.hero-note{
    color:#91a8bc;
    font-size:10px;
    font-weight:800;
    letter-spacing:1px;
}

.total{
    display:flex;
    align-items:center;
    gap:8px;
    color:#e8eff5;
    font-size:12px;
    font-weight:800;
}

.dot{
    width:8px;
    height:8px;
    border-radius:50%;
    background:#39d78a;
    box-shadow:0 0 0 5px rgba(57,215,138,.12);
}

/* ---------- FILTER ---------- */
.filter-card{
    position:relative;
    z-index:10;
    margin:22px 0 28px;
    padding:17px;
    border:1px solid #dfe6ed;
    border-radius:17px;
    background:rgba(255,255,255,.98);
    box-shadow:0 16px 42px rgba(15,40,65,.12);
}

.filter-form{
    width:100%;
    min-width:0;
    display:grid;
    grid-template-columns:minmax(0,1fr) 210px 145px 145px;
    gap:10px;
}

.search-box{
    position:relative;
    min-width:0;
}

.search-icon{
    position:absolute;
    left:15px;
    top:50%;
    transform:translateY(-50%);
    color:#8291a0;
    font-size:17px;
    pointer-events:none;
}

.search-input,.area-select{
    width:100%;
    height:47px;
    border:1px solid #d7e0e8;
    border-radius:10px;
    outline:none;
    background:#fafcfd;
    color:#172a3e;
    font:13px Inter,"Segoe UI",Arial,sans-serif;
    transition:.18s;
}

.search-input{padding:0 14px 0 42px}
.area-select{padding:0 12px;cursor:pointer}

.search-input:focus,.area-select:focus{
    border-color:#4a89b9;
    background:#fff;
    box-shadow:0 0 0 4px rgba(52,119,170,.09);
}

.btn{
    height:47px;
    border:0;
    border-radius:10px;
    padding:0 16px;
    cursor:pointer;
    font:800 12px Inter,"Segoe UI",Arial,sans-serif;
    transition:.18s;
}

.btn-search{
    color:#fff;
    background:linear-gradient(135deg,#1d547f,#347eae);
    box-shadow:0 7px 17px rgba(25,76,112,.17);
}

.btn-star{
    color:#735a24;
    background:#fff8e8;
    border:1px solid #eedaa9;
}

.btn-star.active{
    color:#fff;
    background:linear-gradient(135deg,#c38d28,#e3b54f);
    border-color:#d8aa4a;
}

.btn:hover{
    transform:translateY(-1px);
    box-shadow:0 8px 19px rgba(20,45,70,.13);
}

.filter-bottom{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-top:10px;
}

.filter-info{
    color:#81909f;
    font-size:11px;
}

.clear{
    color:#42647c;
    text-decoration:none;
    font-size:11px;
    font-weight:800;
}

/* ---------- RESULTS ---------- */
.results-head{
    display:flex;
    align-items:end;
    justify-content:space-between;
    gap:15px;
    margin:0 2px 13px;
}

.results-head h2{
    margin:0;
    color:#152c43;
    font-size:21px;
    letter-spacing:-.3px;
}

.results-head p{
    margin:4px 0 0;
    color:#81909f;
    font-size:11px;
}

.results-badges{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.badge{
    padding:7px 11px;
    border:1px solid #dce5ec;
    border-radius:999px;
    background:#fff;
    color:#5d7388;
    font-size:11px;
    font-weight:800;
}

.badge.starred{
    color:#7b5c1c;
    border-color:#ecd9aa;
    background:#fffaf0;
}

/* ---------- GROUP ROW ---------- */
.group-list{
    display:flex;
    flex-direction:column;
    gap:9px;
}

.group{
    position:relative;
    display:grid;
    grid-template-columns:70px minmax(190px,1.05fr) minmax(170px,.95fr) minmax(170px,1fr) 105px;
    align-items:stretch;
    min-height:86px;
    overflow:hidden;
    border:1px solid #dfe6ec;
    border-radius:14px;
    background:#fff;
    box-shadow:0 5px 18px rgba(15,40,65,.045);
    transition:.18s ease;
}

.group:before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:3px;
    background:linear-gradient(#d6a442,#f0ce84);
}

.group.is-starred{
    border-color:#ead7a8;
    box-shadow:0 7px 23px rgba(140,101,25,.08);
}

.group.is-starred:before{
    background:linear-gradient(#d29a31,#f1c968);
}

.group:hover{
    transform:translateY(-1px);
    border-color:#cbd9e4;
    box-shadow:0 10px 25px rgba(15,40,65,.09);
}

.group-no{
    display:flex;
    align-items:center;
    justify-content:center;
}

.group-no span{
    display:flex;
    align-items:center;
    justify-content:center;
    width:37px;
    height:37px;
    border:1px solid #dfe6ed;
    border-radius:10px;
    background:#f5f8fa;
    color:#708398;
    font-size:11px;
    font-weight:900;
}

.group.is-starred .group-no span{
    border-color:#ead7aa;
    background:#fff8e9;
    color:#986f22;
}

.cell{
    min-width:0;
    padding:15px 18px;
    border-left:1px solid #edf1f4;
}

.cell-label{
    margin-bottom:6px;
    color:#9b702d;
    font-size:9px;
    font-weight:900;
    letter-spacing:1px;
    text-transform:uppercase;
}

.cell-value{
    overflow:hidden;
    color:#1b3045;
    font-size:13px;
    font-weight:750;
    line-height:1.35;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.main-cell{
    background:linear-gradient(90deg,#fbfdfc,#f6fbf8);
}

.main-cell .cell-label{color:#17804b}
.main-cell .cell-value{color:#1e6341}

.area-cell{
    background:#fcfdfe;
    min-width:0;
    overflow:hidden;
}

.area-list{
    display:flex;
    flex-wrap:wrap;
    align-items:flex-start;
    gap:6px;
    width:100%;
    max-width:100%;
    min-width:0;
    overflow:hidden;
}

.area-tag{
    display:inline-flex;
    align-items:center;
    justify-content:flex-start;
    box-sizing:border-box;
    min-width:0;
    max-width:100%;
    width:auto;
    padding:5px 9px;
    border:1px solid #dbe4eb;
    border-radius:999px;
    background:#f5f8fa;
    color:#52697e;
    font-size:10px;
    font-weight:800;
    line-height:1.25;
    white-space:normal;
    overflow:hidden;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.area-tag:before{
    content:"";
    flex:0 0 5px;
    width:5px;
    height:5px;
    margin-right:5px;
    border-radius:50%;
    background:#8da1b3;
}

.star-cell{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px;
    border-left:1px solid #edf1f4;
}

.star-form{margin:0}

.star-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    min-width:82px;
    height:34px;
    padding:0 10px;
    border:1px solid #dbe3ea;
    border-radius:8px;
    background:#f8fafc;
    color:#718296;
    cursor:pointer;
    font:800 10px Inter,"Segoe UI",Arial,sans-serif;
    transition:.18s;
}

.star-button:hover{
    border-color:#d9bd78;
    background:#fff9ea;
    color:#9a7021;
}

.star-button.starred{
    border-color:#dfbd6b;
    background:#fff5d9;
    color:#94691a;
}

.star-symbol{
    font-size:15px;
    line-height:1;
}

.no-results{
    padding:58px 25px;
    border:1px solid #dfe6ec;
    border-radius:15px;
    background:#fff;
    text-align:center;
    box-shadow:0 6px 20px rgba(15,40,65,.05);
}

.no-icon{
    width:50px;
    height:50px;
    margin:0 auto 13px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    background:#f1f5f8;
    color:#7d8d9d;
    font-size:21px;
}

.no-results h3{margin:0 0 5px;font-size:15px}
.no-results p{margin:0;color:#8492a0;font-size:12px}

.footer{
    margin-top:23px;
    color:#8996a4;
    text-align:center;
    font-size:10px;
}

@media(max-width:1050px){
    .filter-form{
        grid-template-columns:minmax(0,1fr) 180px 130px 130px;
    }

    .group{
        grid-template-columns:60px minmax(170px,1fr) minmax(160px,1fr);
    }

    .cell.main-cell,
    .area-cell,
    .star-cell{
        grid-column:auto;
    }

    .group{
        grid-template-areas:
            "num group scheme"
            "num main area"
            "num star star";
    }

    .group-no{grid-area:num}
    .group .group-cell{grid-area:group}
    .group .scheme-cell{grid-area:scheme}
    .group .main-cell{grid-area:main}
    .group .area-cell{grid-area:area}
    .group .star-cell{grid-area:star}
}

@media(max-width:760px){
    .page{width:min(100% - 20px,680px);padding-top:12px}
    .hero{min-height:235px;padding:22px 20px}
    .logo{width:58px;height:58px}
    .hero h1{font-size:28px}
    .brand-small{font-size:9px;letter-spacing:1.8px}
    .hero-sub{font-size:11px}
    .panel-link{position:static;display:inline-block;margin-top:17px}
    .hero-bottom{left:20px;right:20px;bottom:20px}
    .hero-note{font-size:9px}
    .filter-card{width:auto;margin:18px 8px 22px;padding:13px}
    .filter-form{grid-template-columns:1fr}
    .btn{width:100%}
    .filter-bottom{align-items:flex-start;flex-direction:column}
    .results-head{align-items:flex-start;flex-direction:column}
    .group{
        grid-template-columns:52px 1fr;
        grid-template-areas:
            "num group"
            "num scheme"
            "num main"
            "num area"
            "num star";
    }
    .cell{
        padding:12px 14px;
        border-left:0;
        border-top:1px solid #edf1f4;
    }
    .group .group-cell{border-top:0}
    .group-no{grid-area:num}
    .star-cell{justify-content:flex-start;padding-left:14px}
}

@media(max-width:430px){
    .hero{min-height:245px}
    .hero-bottom{align-items:flex-start;flex-direction:column;gap:9px}
    .results-badges{width:100%}
}
</style>
</head>

<body>

<div class="page">

    <section class="hero">

        <div class="brand">

            <img
                class="logo"
                src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAH0CAIAAABEtEjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAGB2lUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSfvu78nIGlkPSdXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQnPz4KPHg6eG1wbWV0YSB4bWxuczp4PSdhZG9iZTpuczptZXRhLyc+CjxyZGY6UkRGIHhtbG5zOnJkZj0naHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyc+CgogPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9JycKICB4bWxuczpBdHRyaWI9J2h0dHA6Ly9ucy5hdHRyaWJ1dGlvbi5jb20vYWRzLzEuMC8nPgogIDxBdHRyaWI6QWRzPgogICA8cmRmOlNlcT4KICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0nUmVzb3VyY2UnPgogICAgIDxBdHRyaWI6Q3JlYXRlZD4yMDI2LTAzLTE2PC9BdHRyaWI6Q3JlYXRlZD4KICAgICA8QXR0cmliOkRhdGE+eyZxdW90O2RvYyZxdW90OzomcXVvdDtEQUhFRjducnBvdyZxdW90OywmcXVvdDt1c2VyJnF1b3Q7OiZxdW90O1VBRFRxcXZWN0ZzJnF1b3Q7LCZxdW90O2JyYW5kJnF1b3Q7OiZxdW90O2hhcnNoIGNoYXZkYeKAmXMgdGVhbSZxdW90OywmcXVvdDt0ZW1wbGF0ZSZxdW90OzomcXVvdDtEYXJrIEJsdWUgYW5kIEdvbGQgTHV4dXJ5IE1vZGVybiBSZWFsIEVzdGF0ZSBQcm9wZXJ0eSBMb2dvJnF1b3Q7fTwvQXR0cmliOkRhdGE+CiAgICAgPEF0dHJpYjpFeHRJZD5lMzRhZmFjZi1iYTczLTQ4NGUtYWQwZi05ZjA0NzllZDgwODc8L0F0dHJpYjpFeHRJZD4KICAgICA8QXR0cmliOkZiSWQ+NTI1MjY1OTE0MTc5NTgwPC9BdHRyaWI6RmJJZD4KICAgICA8QXR0cmliOlRvdWNoVHlwZT4yPC9BdHRyaWI6VG91Y2hUeXBlPgogICAgPC9yZGY6bGk+CiAgIDwvcmRmOlNlcT4KICA8L0F0dHJp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RLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgED/DwdOdPFGb0vzAAAAAElFTkSuQmCC"
                alt="The Divine Lands"
            >

            <div>
                <div class="brand-small">THE DIVINE LANDS</div>

                <h1>Group Directory</h1>

                <div class="hero-sub">
                    Groups, schemes, areas and main contacts — organised in one place.
                </div>
            </div>

        </div>

        <a href="index.php" class="panel-link">
           ALL
        </a>

        <div class="hero-bottom">

            <div class="hero-note">
                GROUP MANAGEMENT • AREA WISE DIRECTORY
            </div>

            <div class="total">
                <span class="dot"></span>
                <?= $total ?> Groups
            </div>

        </div>

    </section>


    <section class="filter-card">

        <form method="get" class="filter-form">

            <div class="search-box">

                <span class="search-icon">⌕</span>

                <input
                    class="search-input"
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search group, scheme or main person..."
                    autocomplete="off"
                >

            </div>


            <select class="area-select" name="area">

                <option value="">All Areas</option>

                <?php foreach($areas as $a): ?>

                    <option
                        value="<?= e($a) ?>"
                        <?= $area === $a ? 'selected' : '' ?>
                    >
                        <?= e(ucwords($a)) ?>
                    </option>

                <?php endforeach; ?>

            </select>


            <button class="btn btn-search" type="submit">
                Search
            </button>


            <button
                class="btn btn-star <?= $star_filter === '1' ? 'active' : '' ?>"
                type="submit"
                name="star"
                value="<?= $star_filter === '1' ? '0' : '1' ?>"
            >
                <?= $star_filter === '1' ? '★ Starred' : '☆ Starred Only' ?>
            </button>

        </form>


        <div class="filter-bottom">

            <div class="filter-info">

                <?php if($search !== '' || $area !== '' || $star_filter === '1'): ?>

                    Filtered results

                    <?php if($area !== ''): ?>
                        • <?= e(ucwords($area)) ?>
                    <?php endif; ?>

                    <?php if($search !== ''): ?>
                        • “<?= e($search) ?>”
                    <?php endif; ?>

                    <?php if($star_filter === '1'): ?>
                        • Starred groups
                    <?php endif; ?>

                <?php else: ?>

                    Showing all groups

                <?php endif; ?>

            </div>


            <?php if($search !== '' || $area !== '' || $star_filter === '1'): ?>

                <a href="listshpw.php" class="clear">
                    Clear Filters
                </a>

            <?php endif; ?>

        </div>

    </section>


    <section>

        <div class="results-head">

            <div>

                <h2>Groups</h2>

                <p>
                    Group name, scheme, area and main person.
                </p>

            </div>


            <div class="results-badges">

                <div class="badge">
                    <?= $total ?> Result<?= $total == 1 ? '' : 's' ?>
                </div>

                <?php if($starred_count > 0): ?>

                    <div class="badge starred">
                        ★ <?= $starred_count ?> Starred
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <?php if($groups): ?>

            <div class="group-list">

                <?php foreach($groups as $i => $g): ?>

                    <?php
                        $is_starred = ((int)$g['is_starred'] === 1);
                        $area_names = trim($g['area_names'] ?? '');
                    ?>

                    <article class="group <?= $is_starred ? 'is-starred' : '' ?>">

                        <div class="group-no">

                            <span>
                                <?= $i + 1 ?>
                            </span>

                        </div>


                        <div class="cell group-cell">

                            <div class="cell-label">
                                Group Name
                            </div>

                            <div
                                class="cell-value"
                                title="<?= e($g['company_name']) ?>"
                            >
                                <?= e($g['company_name']) ?>
                            </div>

                        </div>


                        <div class="cell scheme-cell">

                            <div class="cell-label">
                                Scheme
                            </div>

                            <div
                                class="cell-value"
                                title="<?= e($g['scheme_name']) ?>"
                            >
                                <?= e($g['scheme_name']) ?>
                            </div>

                        </div>


                        <div class="cell main-cell">

                            <div class="cell-label">
                                Main Person
                            </div>

                            <div
                                class="cell-value"
                                title="<?= e($g['main_persons'] ?? '') ?>"
                            >

                                <?php if(trim($g['main_persons'] ?? '') !== ''): ?>

                                    <?= e($g['main_persons']) ?>

                                <?php else: ?>

                                    <span style="color:#9aa7b3;font-weight:600">
                                        No main person marked
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>


                        <div class="cell area-cell">

                            <div class="cell-label">
                                Area
                            </div>

                            <?php if($area_names !== ''): ?>

                                <div class="area-list">

                                    <?php foreach(explode(',',$area_names) as $one_area): ?>

                                        <span class="area-tag">
                                            <?= e(ucwords(trim($one_area))) ?>
                                        </span>

                                    <?php endforeach; ?>

                                </div>

                            <?php else: ?>

                                <div class="cell-value" style="color:#9aa7b3;font-weight:600">
                                    No area
                                </div>

                            <?php endif; ?>

                        </div>


                        <div class="star-cell">

                            <form method="post" class="star-form">

                                <input
                                    type="hidden"
                                    name="grp_id"
                                    value="<?= (int)$g['grp_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="star_action"
                                    value="<?= $is_starred ? 'unstar' : 'star' ?>"
                                >

                                <button
                                    type="submit"
                                    class="star-button <?= $is_starred ? 'starred' : '' ?>"
                                    title="<?= $is_starred ? 'Remove star' : 'Star this group' ?>"
                                >

                                    <span class="star-symbol">
                                        <?= $is_starred ? '★' : '☆' ?>
                                    </span>

                                    <?= $is_starred ? 'Starred' : 'Star' ?>

                                </button>

                            </form>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="no-results">

                <div class="no-icon">⌕</div>

                <h3>No groups found</h3>

                <p>
                    Try another group name, scheme, main person or area.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <div class="footer">
        The Divine Lands • Group Directory
    </div>

</div>

</body>
</html>
