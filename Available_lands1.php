<?php
/* =========================================================
   THE DIVINE LANDS — AVAILABLE LANDS
   CORE PHP + MYSQL ONLY
   ========================================================= */

session_start();

$con = @mysqli_connect('localhost','root','','tdl');

if (!$con) {
    die('Database connection failed: '.mysqli_connect_error());
}

mysqli_set_charset($con,'utf8mb4');

function e($v){
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------
   CSRF
   --------------------------------------------------------- */
if (empty($_SESSION['available_lands_csrf'])) {
    $_SESSION['available_lands_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['available_lands_csrf'];

function verify_csrf(){
    global $csrf;
    if (
        empty($_POST['csrf']) ||
        !hash_equals($csrf,(string)$_POST['csrf'])
    ) {
        die('Invalid request.');
    }
}

/* ---------------------------------------------------------
   CHECK TABLE COLUMNS
   --------------------------------------------------------- */
$columns = [];
$cr = mysqli_query($con,"SHOW COLUMNS FROM `available_lands`");

if (!$cr) {
    die('Table `available_lands` not found: '.e(mysqli_error($con)));
}

while ($c = mysqli_fetch_assoc($cr)) {
    $columns[$c['Field']] = $c;
}

$required = [
    'confirmed',
    'imp',
    'grade',
    'owner_details',
    'land_area',
    'land details',
    'price',
    'active_groups',
    'location',
    'stared',
    'remarks',
    'notes'
];

$missing = [];
foreach($required as $r){
    if(!isset($columns[$r])){
        $missing[] = $r;
    }
}

/*
 * An id is strongly recommended for reliable update/delete.
 * If absent, ADD/LIST/filters still work.
 */
$has_id = isset($columns['id']);

/* ---------------------------------------------------------
   GROUPS FROM EXISTING DATA TABLE
   Active buyer groups are selected by grp_id.
   --------------------------------------------------------- */
$groups = [];

$gr = mysqli_query(
    $con,
    "SELECT grp_id,
            MAX(company_name) AS company_name,
            MAX(scheme_name) AS scheme_name
     FROM data
     WHERE grp_id IS NOT NULL
       AND TRIM(CAST(grp_id AS CHAR)) <> ''
     GROUP BY grp_id
     ORDER BY CAST(grp_id AS UNSIGNED) DESC"
);

if($gr){
    while($g=mysqli_fetch_assoc($gr)){
        $groups[]=$g;
    }
}

/* ---------------------------------------------------------
   STAR / UNSTAR
   --------------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_star'])){

    verify_csrf();

    if(!$has_id){
        die('Please add an `id` primary key to available_lands to use star/unstar.');
    }

    $id=(int)($_POST['id'] ?? 0);
    $new=(int)($_POST['new_star'] ?? 0);
    $new=$new===1 ? 1 : 0;

    if($id>0){
        $st=mysqli_prepare(
            $con,
            "UPDATE available_lands SET stared=? WHERE id=? LIMIT 1"
        );

        if(!$st){
            die('Star update failed: '.e(mysqli_error($con)));
        }

        mysqli_stmt_bind_param($st,'ii',$new,$id);

        if(!mysqli_stmt_execute($st)){
            die('Star update failed: '.e(mysqli_stmt_error($st)));
        }

        mysqli_stmt_close($st);
    }

    /* Return to the same filtered list, if one was supplied. */
    $return='available_lands.php';
    if(!empty($_POST['return_query'])){
        $return.='?'.ltrim((string)$_POST['return_query'],'?');
    }

    header("Location: ".$return);
    exit;
}

/* ---------------------------------------------------------
   DELETE
   --------------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_land'])){

    verify_csrf();

    if(!$has_id){
        die('Please add an `id` primary key to available_lands to use delete.');
    }

    $id=(int)($_POST['id'] ?? 0);

    if($id>0){
        $st=mysqli_prepare(
            $con,
            "DELETE FROM available_lands WHERE id=? LIMIT 1"
        );

        if(!$st){
            die('Delete failed: '.e(mysqli_error($con)));
        }

        mysqli_stmt_bind_param($st,'i',$id);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }

    header("Location: available_lands.php");
    exit;
}

/* ---------------------------------------------------------
   SAVE / UPDATE
   --------------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_land'])){

    verify_csrf();

    $confirmed = isset($_POST['confirmed']) ? 1 : 0;
    $imp       = isset($_POST['imp']) ? 1 : 0;
    $stared    = isset($_POST['stared']) ? 1 : 0;

    $grade         = trim($_POST['grade'] ?? '');
    $owner_details = trim($_POST['owner_details'] ?? '');
    $land_area     = trim($_POST['land_area'] ?? '');
    $land_details  = trim($_POST['land_details'] ?? '');
    $price         = trim($_POST['price'] ?? '');
    $location      = trim($_POST['location'] ?? '');
    $remarks       = trim($_POST['remarks'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    $active = isset($_POST['active_groups']) ? (array)$_POST['active_groups'] : [];
    $active = array_values(array_unique(array_filter(
        array_map('intval',$active),
        function($v){ return $v > 0; }
    )));
    $active_groups = implode(',',$active);

    if(!$missing){

        if($has_id && !empty($_POST['id'])){

            $id=(int)$_POST['id'];

            $sql="
                UPDATE available_lands SET
                    confirmed=?,
                    imp=?,
                    grade=?,
                    owner_details=?,
                    land_area=?,
                    `land details`=?,
                    price=?,
                    active_groups=?,
                    location=?,
                    stared=?,
                    remarks=?,
                    notes=?
                WHERE id=?
                LIMIT 1
            ";

            $st=mysqli_prepare($con,$sql);

            if(!$st){
                die('Update failed: '.e(mysqli_error($con)));
            }

            /* 13 variables = 13 type characters:
               i i s s s s s s s i s s i */
            mysqli_stmt_bind_param(
                $st,
                'iisssssssissi',
                $confirmed,
                $imp,
                $grade,
                $owner_details,
                $land_area,
                $land_details,
                $price,
                $active_groups,
                $location,
                $stared,
                $remarks,
                $notes,
                $id
            );

            if(!mysqli_stmt_execute($st)){
                die('Update failed: '.e(mysqli_stmt_error($st)));
            }

            mysqli_stmt_close($st);

        }else{

            $sql="
                INSERT INTO available_lands
                (
                    confirmed,
                    imp,
                    grade,
                    owner_details,
                    land_area,
                    `land details`,
                    price,
                    active_groups,
                    location,
                    stared,
                    remarks,
                    notes
                )
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ";

            $st=mysqli_prepare($con,$sql);

            if(!$st){
                die('Insert prepare failed: '.e(mysqli_error($con)));
            }

            /* 12 variables = 12 type characters:
               i i s s s s s s s i s s */
            mysqli_stmt_bind_param(
                $st,
                'iisssssssiss',
                $confirmed,
                $imp,
                $grade,
                $owner_details,
                $land_area,
                $land_details,
                $price,
                $active_groups,
                $location,
                $stared,
                $remarks,
                $notes
            );

            if(!mysqli_stmt_execute($st)){
                die('Insert failed: '.e(mysqli_stmt_error($st)));
            }

            mysqli_stmt_close($st);
        }
    }

    header("Location: available_lands.php");
    exit;
}

/* ---------------------------------------------------------
   EDIT
   --------------------------------------------------------- */
$edit_id=(int)($_GET['edit'] ?? 0);
$editing=null;

if($edit_id>0 && $has_id){

    $st=mysqli_prepare(
        $con,
        "SELECT * FROM available_lands WHERE id=? LIMIT 1"
    );

    if($st){
        mysqli_stmt_bind_param($st,'i',$edit_id);
        mysqli_stmt_execute($st);
        $rr=mysqli_stmt_get_result($st);
        $editing=mysqli_fetch_assoc($rr);
        mysqli_stmt_close($st);
    }
}

/* Dynamic bind helper for filters */
function stmt_bind_dynamic($stmt, $types, $params){
    if($types==='') return;
    $bind=[$stmt,$types];
    foreach($params as $k=>$v){
        $bind[]=$params[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param',$bind);
}

/* ---------------------------------------------------------
   FILTERS
   --------------------------------------------------------- */
$search=trim($_GET['search'] ?? '');
$location_filter=trim($_GET['location'] ?? '');
$land_area_filter=trim($_GET['land_area'] ?? '');
$confirmed_filter=isset($_GET['confirmed']) && (string)$_GET['confirmed']==='1';
$imp_filter=isset($_GET['imp']) && (string)$_GET['imp']==='1';
$star_filter=(isset($_GET['starred']) && (string)$_GET['starred']==='1');

$rows=[];

$sql="SELECT * FROM available_lands";
$where=[];
$params=[];
$types='';

if($search!==''){

    $where[]="(
        owner_details LIKE ?
        OR location LIKE ?
        OR grade LIKE ?
        OR price LIKE ?
        OR land_area LIKE ?
        OR `land details` LIKE ?
        OR remarks LIKE ?
        OR notes LIKE ?
        OR EXISTS (
            SELECT 1 FROM data gd
            WHERE FIND_IN_SET(gd.grp_id, REPLACE(COALESCE(available_lands.active_groups,''),' ',''))
              AND (gd.scheme_name LIKE ? OR gd.company_name LIKE ?)
        )
    )";

    $like='%'.$search.'%';

    for($i=0;$i<10;$i++){
        $params[]=$like;
    }

    $types.='ssssssssss';
}

if($location_filter!==''){
    $where[]="location LIKE ?";
    $params[]='%'.$location_filter.'%';
    $types.='s';
}

if($land_area_filter!==''){
    $where[]="land_area LIKE ?";
    $params[]='%'.$land_area_filter.'%';
    $types.='s';
}

if($confirmed_filter){
    $where[]="confirmed=1";
}

if($imp_filter){
    $where[]="imp=1";
}

if($star_filter){
    $where[]="COALESCE(stared,0)=1";
}

if($where){
    $sql.=" WHERE ".implode(" AND ",$where);
}

$sql.=" ORDER BY stared DESC, imp DESC, confirmed DESC";

$st=mysqli_prepare($con,$sql);

if(!$st){
    die('List query failed: '.e(mysqli_error($con)));
}

if($params){
    stmt_bind_dynamic($st,$types,$params);
}

mysqli_stmt_execute($st);
$rs=mysqli_stmt_get_result($st);

while($r=mysqli_fetch_assoc($rs)){
    $rows[]=$r;
}

mysqli_stmt_close($st);

$current_query = http_build_query($_GET);

/* ---------------------------------------------------------
   FORM VALUES
   --------------------------------------------------------- */
$f=[
    'id'=>$editing['id'] ?? '',
    'confirmed'=>$editing['confirmed'] ?? 0,
    'imp'=>$editing['imp'] ?? 0,
    'stared'=>$editing['stared'] ?? 0,
    'grade'=>$editing['grade'] ?? '',
    'owner_details'=>$editing['owner_details'] ?? '',
    'land_area'=>$editing['land_area'] ?? '',
    'land_details'=>$editing['land details'] ?? '',
    'price'=>$editing['price'] ?? '',
    'active_groups'=>$editing['active_groups'] ?? '',
    'location'=>$editing['location'] ?? '',
    'remarks'=>$editing['remarks'] ?? '',
    'notes'=>$editing['notes'] ?? ''
];

$selected_groups=array_filter(
    array_map('intval',explode(',',(string)$f['active_groups']))
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>The Divine Lands | Available Lands</title>
<style>
*{box-sizing:border-box}
:root{
--navy:#061a31;--navy2:#0c3152;--blue:#347cad;--gold:#e4b45b;
--green:#159653;--red:#c93742;--ink:#14283d;--muted:#77899a;
--line:#dfe7ee;--bg:#f3f6f9;--cream:#fff9ec
}
body{
margin:0;background:radial-gradient(circle at 0 0,rgba(228,180,91,.09),transparent 25%),radial-gradient(circle at 100% 10%,rgba(52,124,173,.08),transparent 28%),var(--bg);
color:var(--ink);font-family:Inter,"Segoe UI",Arial,sans-serif;-webkit-font-smoothing:antialiased
}
.page{width:min(1480px,calc(100% - 34px));margin:auto;padding:23px 0 50px}
.hero{
position:relative;overflow:hidden;min-height:200px;padding:28px 33px;border-radius:24px;color:#fff;
background:radial-gradient(circle at 73% 30%,rgba(68,132,181,.23),transparent 29%),linear-gradient(125deg,#04172d,#092744 58%,#113f64);
box-shadow:0 22px 60px rgba(5,27,48,.18)
}
.hero:after{content:"";position:absolute;right:-220px;bottom:-400px;width:520px;height:520px;border:1px solid rgba(228,180,91,.18);border-radius:50%}
.brand{position:relative;z-index:2;display:flex;align-items:center;gap:17px}
.logo{width:67px;height:67px;padding:7px;border-radius:17px;background:#fff;object-fit:contain;box-shadow:0 12px 28px rgba(0,0,0,.22)}
.brand-small{color:var(--gold);font-size:10px;font-weight:900;letter-spacing:2.5px;margin-bottom:5px}
.hero h1{margin:0;font-size:34px;letter-spacing:-1px}
.hero p{margin:7px 0 0;color:#aec1d2;font-size:13px}
.panel{position:absolute;z-index:3;right:28px;top:27px;padding:10px 15px;border:1px solid rgba(255,255,255,.16);border-radius:999px;color:#eaf1f7;background:rgba(255,255,255,.07);text-decoration:none;font-size:12px;font-weight:850}
.hero-bottom{position:absolute;z-index:2;left:33px;right:33px;bottom:23px;display:flex;justify-content:space-between;align-items:center}
.hero-note{color:#90a8bd;font-size:10px;font-weight:850;letter-spacing:1px}
.hero-count{display:flex;gap:8px;align-items:center;font-size:12px;font-weight:850}.dot{width:8px;height:8px;border-radius:50%;background:#39d78a;box-shadow:0 0 0 5px rgba(57,215,138,.12)}

.toolbar{
margin:22px 0;padding:18px;border:1px solid var(--line);border-radius:17px;background:#fff;box-shadow:0 12px 35px rgba(15,40,65,.08)
}
.toolbar-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.toolbar-title h2{margin:0;font-size:19px}.toolbar-title p{margin:4px 0 0;color:var(--muted);font-size:11px}
.add{height:43px;padding:0 17px;border:0;border-radius:9px;background:linear-gradient(135deg,#147e45,#1ca45c);color:#fff;text-decoration:none;display:inline-flex;align-items:center;font-size:12px;font-weight:850}
.filters{display:grid;grid-template-columns:minmax(240px,1.8fr) minmax(150px,1fr) minmax(150px,1fr) 130px 130px 150px 82px;gap:10px;align-items:stretch}
.input,.select{width:100%;height:44px;padding:0 12px;border:1px solid #d7e0e8;border-radius:9px;outline:0;background:#fafcfd;color:#203348;font:12px Inter,"Segoe UI",Arial,sans-serif}
.input:focus,.select:focus,.textarea:focus{border-color:#4b8cbd;box-shadow:0 0 0 4px rgba(52,124,173,.08);background:#fff}
.filter{height:44px;border:0;border-radius:9px;background:#205f8f;color:#fff;font-size:11px;font-weight:850;cursor:pointer}.search-btn{background:linear-gradient(135deg,#092846,#1d638f);box-shadow:0 7px 16px rgba(9,40,70,.16)}.search-btn:hover{filter:brightness(1.08)}
.clear{height:44px;display:flex;align-items:center;justify-content:center;border:1px solid #dce4eb;border-radius:9px;background:#f6f8fa;color:#5c7287;text-decoration:none;font-size:10px;font-weight:850}

.form-card{margin-bottom:25px;padding:23px;border:1px solid var(--line);border-radius:17px;background:#fff;box-shadow:0 10px 30px rgba(15,40,65,.065)}
.form-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.form-head h2{margin:0;font-size:19px}.close{color:#5b7286;text-decoration:none;font-size:11px;font-weight:850}
.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.field{min-width:0}.field.full{grid-column:1/-1}
.field label{display:block;margin:0 0 7px 2px;color:#50677b;font-size:10px;font-weight:900;letter-spacing:.9px;text-transform:uppercase}
.textarea{width:100%;min-height:120px;padding:12px;border:1px solid #d7e0e8;border-radius:9px;resize:vertical;outline:0;background:#fafcfd;color:#203348;font:12px/1.55 Inter,"Segoe UI",Arial,sans-serif}
.textarea.big{min-height:145px}
.checks{display:flex;flex-wrap:wrap;gap:8px}
.check{position:relative}.check input{position:absolute;opacity:0}.check label{display:flex;align-items:center;gap:7px;height:38px;padding:0 12px;border:1px solid #d8e1e9;border-radius:9px;background:#fafcfd;color:#667a8d;cursor:pointer;letter-spacing:0;font-size:10px}
.check label:before{content:"";width:7px;height:7px;border:1px solid #b6c4d0;border-radius:50%}
.check input:checked+label{background:#092846;border-color:#092846;color:#fff}.check input:checked+label:before{background:var(--gold);border-color:var(--gold)}
.group-select{width:100%;min-height:125px;padding:8px;border:1px solid #d7e0e8;border-radius:9px;background:#fafcfd;color:#203348;outline:0;font:12px Inter,"Segoe UI",Arial,sans-serif}
.help{margin-top:6px;color:#8997a5;font-size:10px}
.actions{display:flex;gap:9px;margin-top:18px}.save{height:43px;padding:0 19px;border:0;border-radius:9px;background:linear-gradient(135deg,#147e45,#1ca45c);color:#fff;font-size:12px;font-weight:850;cursor:pointer}.cancel{height:43px;padding:0 17px;border:1px solid #dce4eb;border-radius:9px;background:#f6f8fa;color:#5c7287;text-decoration:none;display:flex;align-items:center;font-size:11px;font-weight:850}

.results{display:flex;justify-content:space-between;align-items:end;margin:0 2px 12px}.results h2{margin:0;font-size:20px}.results p{margin:4px 0 0;color:var(--muted);font-size:11px}.count{padding:7px 11px;border:1px solid #dce5ec;border-radius:999px;background:#fff;color:#5e7488;font-size:11px;font-weight:850}
.list{display:flex;flex-direction:column;gap:10px}
.land{
display:grid;grid-template-columns:48px minmax(155px,.8fr) minmax(100px,.55fr) minmax(145px,.75fr) minmax(155px,.85fr) minmax(430px,2.7fr) minmax(390px,2.4fr) 100px;
align-items:stretch;overflow:hidden;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 5px 18px rgba(15,40,65,.045);transition:.18s
}
.land.starred{border-color:#ead49c;background:#fffdf8}.land:hover{transform:translateY(-1px);box-shadow:0 10px 26px rgba(15,40,65,.09)}
.num{display:flex;align-items:center;justify-content:center}.num span{width:31px;height:31px;border:1px solid #dfe6ed;border-radius:9px;background:#f5f8fa;display:flex;align-items:center;justify-content:center;color:#718397;font-size:10px;font-weight:900}
.cell{min-width:0;padding:14px;border-left:1px solid #edf1f4}.label{margin-bottom:5px;color:#9a702c;font-size:9px;font-weight:900;letter-spacing:1px;text-transform:uppercase}.value{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#1b3045;font-size:12px;font-weight:750;line-height:1.4}
.wrap{white-space:normal;max-height:44px;overflow:hidden}.chips{display:flex;flex-wrap:wrap;gap:5px;min-width:0}.chip{max-width:100%;padding:5px 7px;border:1px solid #dce4ea;border-radius:7px;background:#f5f8fa;color:#587086;font-size:9px;font-weight:850;overflow-wrap:anywhere;word-break:break-word}.chip.ok{background:#effaf4;border-color:#c4e8d1;color:#157a43}.chip.imp{background:#fff5dc;border-color:#ecd59a;color:#936b1b}.chip.star{background:#fff3d2;border-color:#e5c777;color:#8c6418}
.star-box{display:flex;align-items:center;justify-content:center;border-left:1px solid #edf1f4;padding:10px}.star-btn{min-width:78px;height:34px;border:1px solid #dbe3ea;border-radius:8px;background:#f7f9fb;color:#718397;cursor:pointer;font-size:10px;font-weight:850}.star-btn.on{background:#fff3cf;border-color:#dfbd68;color:#8d6518}.edit{display:inline-flex;height:27px;padding:0 9px;align-items:center;border-radius:7px;background:#eef5fa;color:#2d668d;text-decoration:none;font-size:9px;font-weight:850;margin-top:7px}.del{height:27px;padding:0 9px;border:1px solid #f0ced1;border-radius:7px;background:#fff6f7;color:#b52d37;font-size:9px;font-weight:850;cursor:pointer;margin-top:7px}
.empty{padding:55px;text-align:center;border:1px solid var(--line);border-radius:15px;background:#fff;color:#8492a0}.footer{margin-top:24px;text-align:center;color:#8996a4;font-size:10px}

.error{margin-bottom:18px;padding:13px 15px;border:1px solid #f1c9cc;border-radius:10px;background:#fff5f6;color:#a92e39;font-size:11px;font-weight:750}

@media(max-width:1250px){
.filters{grid-template-columns:minmax(220px,1.5fr) repeat(3,minmax(120px,1fr)) minmax(120px,1fr) 78px}
.land{grid-template-columns:45px minmax(145px,1fr) minmax(120px,.8fr) minmax(190px,1.4fr) minmax(250px,1.8fr) minmax(230px,1.6fr) 90px}
}
@media(max-width:1000px){
.filters{grid-template-columns:repeat(3,minmax(0,1fr))}
.land{grid-template-columns:45px 1fr 1fr;grid-template-areas:"num loc owner" "num status buyers" "num landdetails details" "num star star"}
.num{grid-area:num}.loc{grid-area:loc}.owner{grid-area:owner}.status{grid-area:status}.buyers{grid-area:buyers}.land-details{grid-area:landdetails}.details{grid-area:details}.star-box{grid-area:star}
}
@media(max-width:680px){
.page{width:calc(100% - 18px);padding-top:10px}.hero{padding:22px 18px;min-height:225px}.logo{width:56px;height:56px}.hero h1{font-size:27px}.brand-small{font-size:9px}.panel{position:static;display:inline-flex;margin-top:16px}.hero-bottom{left:18px;right:18px;bottom:18px}.toolbar{padding:14px}.toolbar-top{align-items:flex-start;flex-direction:column}.add{width:100%;justify-content:center}.filters{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.actions{flex-direction:column}.save,.cancel{width:100%;justify-content:center}.results{align-items:flex-start;flex-direction:column;gap:9px}.land{grid-template-columns:43px 1fr;grid-template-areas:"num loc" "num owner" "num status" "num buyers" "num landdetails" "num details" "num star"}.cell{border-left:0;border-top:1px solid #edf1f4}.loc{border-top:0}.large-scroll{
    height:180px;padding-left:14px}
}

.group-picker{border:1px solid #d7e0e8;border-radius:11px;background:#fafcfd;overflow:hidden}
.group-search{width:100%;height:43px;padding:0 13px;border:0;border-bottom:1px solid #e1e8ee;outline:0;background:#fff;color:#203348;font:12px Inter,"Segoe UI",Arial,sans-serif}
.group-options{max-height:245px;overflow-y:auto;padding:7px}
.group-option{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:8px;cursor:pointer}
.group-option:hover{background:#f0f5f8}
.group-option input{position:absolute;opacity:0;pointer-events:none}
.group-check{width:17px;height:17px;flex:0 0 17px;border:1px solid #c2ced8;border-radius:5px;background:#fff;position:relative}
.group-option input:checked+.group-check{background:#092846;border-color:#092846}
.group-option input:checked+.group-check:after{content:"✓";position:absolute;left:3px;top:-1px;color:#fff;font-size:13px;font-weight:900}
.group-info{min-width:0;display:grid;grid-template-columns:auto 1fr;column-gap:8px;align-items:center}
.group-info strong{color:#9a702c;font-size:10px}
.group-info span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#24394e;font-size:11px;font-weight:800}
.group-info small{grid-column:2;color:#7b8c9b;font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.selected-groups{display:flex;flex-wrap:wrap;gap:5px;padding:8px;border-top:1px solid #e4eaf0;min-height:10px}
.selected-groups:empty{display:none}
.selected-chip{padding:5px 8px;border-radius:999px;background:#edf5fb;border:1px solid #d1e1ed;color:#2d668d;font-size:9px;font-weight:850}
.location-link{display:inline-flex;align-items:center;gap:5px;color:#216b9d;text-decoration:none;font-size:11px;font-weight:850}
.location-link:hover{text-decoration:underline}


/* Spacious reading columns for the important text fields */
.land .owner{max-width:100%}
.land .owner .wrap{font-size:10px;color:#718397}
.large-scroll{
    height:130px;
    overflow-y:auto;
    overflow-x:hidden;
    padding:10px 11px;
    border:1px solid #e3e9ee;
    border-radius:9px;
    background:#fbfcfd;
    color:#30465a;
    font-size:12px;
    line-height:1.65;
    white-space:normal;
    overflow-wrap:anywhere;
    scrollbar-width:thin;
}
.large-scroll::-webkit-scrollbar{width:6px}
.large-scroll::-webkit-scrollbar-thumb{background:#c8d3dc;border-radius:10px}
.large-scroll::-webkit-scrollbar-track{background:#f1f4f6}
.remarks-scroll{background:#fffdf8;border-color:#eadfbe}
.note-block{margin-bottom:10px}
.note-block:last-child{margin-bottom:0}
.note-block strong{
    display:block;
    margin-bottom:4px;
    color:#9a702c;
    font-size:9px;
    letter-spacing:.7px;
    text-transform:uppercase
}
.note-block span{display:block;color:#30465a}

.location-area{
    display:flex;
    align-items:center;
    gap:7px;
    margin-top:10px;
    padding-top:8px;
    border-top:1px solid #edf1f4;
}
.mini-label{
    color:#9a702c;
    font-size:8px;
    font-weight:900;
    letter-spacing:.8px;
    text-transform:uppercase;
}
.area-value{
    display:inline-flex;
    align-items:center;
    min-height:22px;
    padding:3px 7px;
    border:1px solid #dbe4eb;
    border-radius:7px;
    background:#f5f8fa;
    color:#526d83;
    font-size:9px;
    font-weight:850;
    overflow-wrap:anywhere;
}

.filter-toggle{
    position:relative;
    height:46px;
    min-width:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 10px;
    border:1px solid #d5e0e8;
    border-radius:12px;
    background:linear-gradient(180deg,#fff,#f7fafc);
    color:#60778b;
    font-size:10px;
    font-weight:900;
    cursor:pointer;
    user-select:none;
    transition:all .18s ease;
    box-shadow:0 3px 9px rgba(15,40,65,.04);
    overflow:hidden
}
.filter-toggle:hover{
    transform:translateY(-1px);
    border-color:#aebfcd;
    box-shadow:0 7px 16px rgba(15,40,65,.09)
}
.filter-toggle input{
    position:absolute;
    opacity:0;
    width:1px;
    height:1px
}
.filter-toggle span{
    width:100%;
    height:34px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    border-radius:8px;
    white-space:nowrap;
    transition:all .18s ease
}
.filter-toggle.active-confirmed{
    border-color:#8ed0aa;
    background:#f0fbf5;
    color:#137542
}
.filter-toggle.active-confirmed span{
    background:linear-gradient(135deg,#147e45,#1ca45c);
    color:#fff;
    box-shadow:0 4px 10px rgba(21,150,83,.20)
}
.filter-toggle.active-important{
    border-color:#e2c77e;
    background:#fffaf0;
    color:#936b1b
}
.filter-toggle.active-important span{
    background:linear-gradient(135deg,#936817,#d5a53d);
    color:#fff;
    box-shadow:0 4px 10px rgba(168,120,25,.20)
}
.filter-toggle.active-starred{
    border-color:#e1c06b;
    background:#fff9e9;
    color:#8a6419
}
.filter-toggle.active-starred span{
    background:linear-gradient(135deg,#76520c,#d4a23e);
    color:#fff;
    box-shadow:0 4px 10px rgba(168,120,25,.22)
}

.star-btn{
    min-width:88px;
    height:36px;
    border:1px solid #dbe3ea;
    border-radius:9px;
    background:linear-gradient(135deg,#f7f9fb,#edf2f5);
    color:#617589;
    cursor:pointer;
    font-size:10px;
    font-weight:900;
    transition:.18s;
    box-shadow:0 4px 10px rgba(15,40,65,.05);
}
.star-btn:hover{transform:translateY(-1px);box-shadow:0 7px 14px rgba(15,40,65,.10)}
.star-btn.on{
    background:linear-gradient(135deg,#fff0b9,#e4b45b);
    border-color:#d5ae59;
    color:#775414;
}

.status-star{
    display:inline-flex;
    align-items:center;
    margin-top:7px;
    padding:5px 8px;
    border-radius:7px;
    font-size:9px;
    font-weight:900;
    letter-spacing:.2px;
}
.status-star.is-starred{
    background:#fff3d2;
    border:1px solid #e5c777;
    color:#8c6418;
}
.status-star.is-not-starred{
    background:#f5f8fa;
    border:1px solid #dce4ea;
    color:#718397;
}
</style>
</head>
<body>
<div class="page">

<section class="hero">
<div class="brand">
<img class="logo" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAH0CAIAAABEtEjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAGB2lUWHRYTUw6Y29tLmFkb2JlLnhtcAAAAAAAPD94cGFja2V0IGJlZ2luPSfvu78nIGlkPSdXNU0wTXBDZWhpSHpyZVN6TlRjemtjOWQnPz4KPHg6eG1wbWV0YSB4bWxuczp4PSdhZG9iZTpuczptZXRhLyc+CjxyZGY6UkRGIHhtbG5zOnJkZj0naHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyc+CgogPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9JycKICB4bWxuczpBdHRyaWI9J2h0dHA6Ly9ucy5hdHRyaWJ1dGlvbi5jb20vYWRzLzEuMC8nPgogIDxBdHRyaWI6QWRzPgogICA8cmRmOlNlcT4KICAgIDxyZGY6bGkgcmRmOnBhcnNlVHlwZT0nUmVzb3VyY2UnPgogICAgIDxBdHRyaWI6Q3JlYXRlZD4yMDI2LTAzLTE2PC9BdHRyaWI6Q3JlYXRlZD4KICAgICA8QXR0cmliOkRhdGE+eyZxdW90O2RvYyZxdW90OzomcXVvdDtEQUhFRjducnBvdyZxdW90OywmcXVvdDt1c2VyJnF1b3Q7OiZxdW90O1VBRFRxcXZWN0ZzJnF1b3Q7LCZxdW90O2JyYW5kJnF1b3Q7OiZxdW90O2hhcnNoIGNoYXZkYeKAmXMgdGVhbSZxdW90OywmcXVvdDt0ZW1wbGF0ZSZxdW90OzomcXVvdDtEYXJrIEJsdWUgYW5kIEdvbGQgTHV4dXJ5IE1vZGVybiBSZWFsIEVzdGF0ZSBQcm9wZXJ0eSBMb2dvJnF1b3Q7fTwvQXR0cmliOkRhdGE+CiAgICAgPEF0dHJpYjpFeHRJZD5lMzRhZmFjZi1iYTczLTQ4NGUtYWQwZi05ZjA0NzllZDgwODc8L0F0dHJpYjpFeHRJZD4KICAgICA8QXR0cmliOkZiSWQ+NTI1MjY1OTE0MTc5NTgwPC9BdHRyaWI6RmJJZD4KICAgICA8QXR0cmliOlRvdWNoVHlwZT4yPC9BdHRyaWI6VG91Y2hUeXBlPgogICAgPC9yZGY6bGk+CiAgIDwvcmRmOlNlcT4KICA8L0F0dHJp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RLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgECEOwAIRLgDgED/DwdOdPFGb0vzAAAAAElFTkSuQmCC" alt="The Divine Lands">
<div>
<div class="brand-small">THE DIVINE LANDS</div>
<h1>Available Lands</h1>
<p>Premium land inventory, active buyers, priorities and notes — all in one place.</p>
</div>
</div>
<a href="index.php" class="panel">All</a>
<div class="hero-bottom">
<div class="hero-note">LAND INVENTORY • OPPORTUNITY MANAGEMENT</div>
<div class="hero-count"><span class="dot"></span><?= count($rows) ?> Lands</div>
</div>
</section>

<?php if($missing): ?>
<div class="error">
Missing database fields:
<?= e(implode(', ',$missing)) ?>.
Please add these fields to <b>available_lands</b> before saving.
</div>
<?php endif; ?>

<section class="toolbar">

<div class="toolbar-top">
<div class="toolbar-title">
<h2>Land Inventory</h2>
<p>Search, filter, prioritise and manage your available land.</p>
</div>
<a href="available_lands.php?add=1" class="add">＋ Add Available Land</a>
</div>

<form method="get">
<div class="filters">

<input class="input" type="text" name="search" value="<?= e($search) ?>" placeholder="Search owner, location, grade, price, details, remarks...">

<input class="input" list="landAreaFilterList" name="land_area" value="<?= e($land_area_filter) ?>" placeholder="Land Area">
<datalist id="landAreaFilterList">
<?php
$la=mysqli_query($con,"SELECT DISTINCT TRIM(land_area) AS land_area FROM available_lands WHERE TRIM(COALESCE(land_area,''))<>'' ORDER BY TRIM(land_area) ASC");
if($la){ while($lav=mysqli_fetch_assoc($la)){ echo '<option value="'.e($lav['land_area']).'">'; }}
?>
</datalist>

<button class="filter search-btn" type="submit">⌕ Search</button>

<label class="filter-toggle <?= $confirmed_filter ? 'active-confirmed' : '' ?>">
<input type="checkbox" name="confirmed" value="1"
       <?= $confirmed_filter ? 'checked' : '' ?>
       onchange="this.form.submit()">
<span>✓ Confirmed</span>
</label>

<label class="filter-toggle <?= $imp_filter ? 'active-important' : '' ?>">
<input type="checkbox" name="imp" value="1"
       <?= $imp_filter ? 'checked' : '' ?>
       onchange="this.form.submit()">
<span>★ Important</span>
</label>

<label class="filter-toggle star-filter <?= $star_filter ? 'active-starred' : '' ?>">
<input type="checkbox" name="starred" value="1"
       <?= $star_filter ? 'checked' : '' ?>
       onchange="this.form.submit()">
<span>⭐ Starred Only</span>
</label>

<a class="clear" href="available_lands.php">Clear</a>

</div>
</form>

</section>


<?php if(isset($_GET['add']) || $editing): ?>

<section class="form-card">

<div class="form-head">
<h2><?= $editing ? 'Edit Available Land' : 'Add Available Land' ?></h2>
<a href="available_lands.php" class="close">Close ×</a>
</div>

<form method="post">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="save_land" value="1">

<?php if($editing && $has_id): ?>
<input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
<?php endif; ?>

<div class="form-grid">

<div class="field">
<label>Status</label>
<div class="checks">

<div class="check">
<input id="confirmed" type="checkbox" name="confirmed" value="1" <?= (int)$f['confirmed']===1?'checked':'' ?>>
<label for="confirmed">✓ Confirmed</label>
</div>

<div class="check">
<input id="imp" type="checkbox" name="imp" value="1" <?= (int)$f['imp']===1?'checked':'' ?>>
<label for="imp">★ Important</label>
</div>

<div class="check">
<input id="stared" type="checkbox" name="stared" value="1" <?= (int)$f['stared']===1?'checked':'' ?>>
<label for="stared">★ Starred</label>
</div>

</div>
</div>

<div class="field">
<label>Grade</label>
<input class="input" name="grade" value="<?= e($f['grade']) ?>" placeholder="A / A+ / B / etc.">
</div>

<div class="field">
<label>Land Area</label>
<input class="input" name="land_area" value="<?= e($f['land_area']) ?>" placeholder="e.g. 5 Acre / 20 Bigha">
</div>

<div class="field">
<label>Location</label>
<input class="input" type="text" name="location" value="<?= e($f['location']) ?>" placeholder="Enter location text or paste Google Maps link">
<div class="help">Text is allowed. If a URL is entered, it can be opened as a link.</div>
</div>

<div class="field">
<label>Price</label>
<input class="input" name="price" value="<?= e($f['price']) ?>" placeholder="Land price">
</div>

<div class="field">
<label>Owner Details</label>
<input class="input" name="owner_details" value="<?= e($f['owner_details']) ?>" placeholder="Owner name / contact / details">
</div>

<div class="field full">
<label>Land Details</label>
<textarea class="textarea big" name="land_details" placeholder="Write complete land details..."><?= e($f['land_details']) ?></textarea>
</div>

<div class="field full">
<label>Active Buyer Groups</label>

<div class="group-picker" id="groupPicker">

    <input
        type="text"
        class="group-search"
        id="groupSearch"
        placeholder="Search by Group ID, company name or scheme name..."
        autocomplete="off"
    >

    <div class="group-options" id="groupOptions">

        <?php foreach($groups as $g): ?>

        <?php
            $gid=(int)$g['grp_id'];
            $company=trim($g['company_name']??'');
            $scheme=trim($g['scheme_name']??'');
            $group_text='Group '.$gid;
            if($company!=='') $group_text.=' — '.$company;
            if($scheme!=='') $group_text.=' — '.$scheme;
        ?>

        <label
            class="group-option"
            data-search="<?= e(strtolower($gid.' '.$company.' '.$scheme)) ?>"
        >
            <input
                type="checkbox"
                name="active_groups[]"
                value="<?= $gid ?>"
                <?= in_array($gid,$selected_groups,true)?'checked':'' ?>
            >

            <span class="group-check"></span>

            <span class="group-info">
                <strong>G<?= $gid ?></strong>
                <span><?= e($company ?: 'No company') ?></span>
                <small><?= e($scheme ?: 'No scheme') ?></small>
            </span>
        </label>

        <?php endforeach; ?>

    </div>

    <div class="selected-groups" id="selectedGroups"></div>

</div>

<div class="help">
    Select multiple groups. You can search directly by <b>scheme name</b>, company name or group number.
</div>
</div>

<div class="field">
<label>Remarks</label>
<textarea class="textarea" name="remarks" placeholder="General remarks about this land..."><?= e($f['remarks']) ?></textarea>
</div>

<div class="field">
<label>Notes</label>
<textarea class="textarea" name="notes" placeholder="General notes, follow-ups or internal information..."><?= e($f['notes']) ?></textarea>
</div>

</div>

<div class="actions">
<button class="save" type="submit"><?= $editing ? '✓ Update Land' : '＋ Save Available Land' ?></button>
<a class="cancel" href="available_lands.php">Cancel</a>
</div>

</form>
</section>
<?php endif; ?>


<div class="results">

<div>
<h2>Available Lands</h2>
<p>Starred and important opportunities appear first. Click a location to open the map.</p>
</div>

<div class="count"><?= count($rows) ?> Result<?= count($rows)==1?'':'s' ?></div>

</div>


<?php if($rows): ?>

<div class="list">

<?php foreach($rows as $i=>$land): ?>

<?php
$star=(int)($land['stared']??0)===1;

$active_ids=array_filter(
array_map('intval',explode(',',(string)($land['active_groups']??'')))
);

$active_labels=[];

foreach($active_ids as $gid){
foreach($groups as $g){
if((int)$g['grp_id']===$gid){
$x='G'.$gid;
if(trim($g['company_name']??'')!=='') $x.=' • '.$g['company_name'];
$active_labels[]=$x;
break;
}
}
}
?>

<article class="land <?= $star?'starred':'' ?>">

<div class="num"><span><?= $i+1 ?></span></div>

<div class="cell loc">
<div class="label">Land Location</div>
<?php if(filter_var($land['location']??'',FILTER_VALIDATE_URL)): ?>
<a class="location-link" href="<?= e($land['location']) ?>" target="_blank" rel="noopener noreferrer">📍 Open Location</a>
<?php else: ?>
<div class="value" title="<?= e($land['location']) ?>"><?= e($land['location'])?:'—' ?></div>
<?php endif; ?>

<div class="location-area">
<span class="mini-label">Land Area</span>
<span class="area-value"><?= e($land['land_area']) ?: '—' ?></span>
</div>

<?php if($has_id): ?>
<a class="edit" href="available_lands.php?edit=<?= (int)$land['id'] ?>">Edit</a>

<form method="post" style="display:inline" onsubmit="return confirm('Delete this land record?');">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="id" value="<?= (int)$land['id'] ?>">
<input type="hidden" name="delete_land" value="1">
<button class="del" type="submit">Delete</button>
</form>
<?php endif; ?>
</div>



<div class="cell owner">
<div class="label">Owner Details</div>
<div class="value wrap" title="<?= e($land['owner_details']) ?>"><?= e($land['owner_details'])?:'—' ?></div>
</div>


<div class="cell status">
<div class="label">Status / Grade / Price</div>
<div class="chips">

<span class="chip <?= (int)$land['confirmed']===1?'ok':'' ?>">
<?= (int)$land['confirmed']===1?'✓ Confirmed':'Not Confirmed' ?>
</span>

<?php if(trim($land['grade']??'')!==''): ?>
<span class="chip">Grade <?= e($land['grade']) ?></span>
<?php endif; ?>

<?php if((int)$land['imp']===1): ?>
<span class="chip imp">★ Important</span>
<?php endif; ?>

<?php if(trim($land['price']??'')!==''): ?>
<span class="chip">₹ <?= e($land['price']) ?></span>
<?php endif; ?>

</div>

<div class="status-star <?= !empty($land['stared']) ? 'is-starred' : 'is-not-starred' ?>">
    <?= !empty($land['stared']) ? '★ Starred' : '☆ Not Starred' ?>
</div>
</div>


<div class="cell buyers">
<div class="label">Active Buyer Groups</div>
<div class="chips">

<?php if($active_labels): ?>
<?php foreach($active_labels as $x): ?>
<span class="chip"><?= e($x) ?></span>
<?php endforeach; ?>
<?php else: ?>
<span class="chip">No active group</span>
<?php endif; ?>

</div>
</div>


<div class="cell land-details">
<div class="label">Land Details</div>
<div class="large-scroll" title="<?= e($land['land details'] ?? '') ?>">
<?= e($land['land details'] ?? '') ?: '—' ?>
</div>
</div>


<div class="cell details">
<div class="label">Remarks & Notes</div>
<div class="large-scroll remarks-scroll">
<?php
$rn=trim(($land['remarks']??''));
$nt=trim(($land['notes']??''));
if($rn!=='') echo '<div class="note-block"><strong>Remarks</strong><span>'.nl2br(e($rn)).'</span></div>';
if($nt!=='') echo '<div class="note-block"><strong>Notes</strong><span>'.nl2br(e($nt)).'</span></div>';
if($rn==='' && $nt==='') echo '—';
?>
</div>
</div>


<div class="star-box">

<?php if($has_id): ?>

<form method="post" style="margin:0">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<input type="hidden" name="id" value="<?= (int)$land['id'] ?>">
<input type="hidden" name="new_star" value="<?= $star?0:1 ?>">
<input type="hidden" name="return_query" value="<?= e($current_query) ?>">
<button class="star-btn <?= $star?'on':'' ?>" type="submit" name="toggle_star" title="<?= $star?'Remove star from this land':'Star this land' ?>">
<?= $star?'★ Unstar':'☆ Star' ?>
</button>
</form>

<?php else: ?>

<span class="chip <?= $star?'star':'' ?>">
<?= $star?'★ Starred':'Not starred' ?>
</span>

<?php endif; ?>

</div>

</article>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
<h3>No available land found</h3>
<p>Try changing the filters or add a new land record.</p>
</div>

<?php endif; ?>

<div class="footer">The Divine Lands • Available Lands</div>

</div>

<script>
(function(){
    const search=document.getElementById('groupSearch');
    const options=document.querySelectorAll('.group-option');
    const selected=document.getElementById('selectedGroups');

    if(!search) return;

    function refresh(){
        const q=search.value.trim().toLowerCase();

        options.forEach(function(option){
            const hay=option.getAttribute('data-search')||'';
            option.style.display=(!q || hay.indexOf(q)!==-1)?'flex':'none';
        });

        selected.innerHTML='';

        document.querySelectorAll('.group-option input:checked').forEach(function(input){
            const label=input.closest('.group-option');
            const strong=label.querySelector('strong');
            const chip=document.createElement('span');
            chip.className='selected-chip';
            chip.textContent=strong ? strong.textContent : 'G'+input.value;
            selected.appendChild(chip);
        });
    }

    search.addEventListener('input',refresh);
    document.querySelectorAll('.group-option input').forEach(function(input){
        input.addEventListener('change',refresh);
    });

    refresh();
})();
</script>

</body>
</html>
