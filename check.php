<?php
header('Content-Type: application/json; charset=utf-8');

$con = @mysqli_connect('localhost','root','','tdl');
if (!$con) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Database connection failed']);
    exit;
}
mysqli_set_charset($con, 'utf8mb4');

function ejson($v) { return $v === null ? '' : (string)$v; }
$gid = (int)($_GET['ajax_group'] ?? 0);
if ($gid <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid group ID']);
    exit;
}

$stmt = mysqli_prepare($con, "SELECT id, grp_id, company_name, scheme_name, name, number1, number2, number3, relation1, relation2, relation_all, `main`, area FROM data WHERE grp_id = ? ORDER BY id ASC");
if (!$stmt) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>mysqli_error($con)]); exit; }
mysqli_stmt_bind_param($stmt,'i',$gid);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$persons=[]; $group=['grp_id'=>$gid,'company_name'=>'','scheme_name'=>'','area'=>'']; $relations=[]; $mainPersons=[];
while ($row=mysqli_fetch_assoc($result)) {
    if ($group['company_name']==='') $group['company_name']=$row['company_name'] ?? '';
    elseif (strcmp((string)($row['company_name']??''),(string)$group['company_name'])>0) $group['company_name']=$row['company_name'] ?? '';
    if ($group['scheme_name']==='') $group['scheme_name']=$row['scheme_name'] ?? '';
    elseif (strcmp((string)($row['scheme_name']??''),(string)$group['scheme_name'])>0) $group['scheme_name']=$row['scheme_name'] ?? '';
    if ($group['area']==='') $group['area']=$row['area'] ?? '';
    elseif (strcmp((string)($row['area']??''),(string)$group['area'])>0) $group['area']=$row['area'] ?? '';
    $persons[]=$row;
    $rel=trim((string)($row['relation_all']??''));
    if ($rel!=='') foreach (preg_split('/\s*,\s*/',$rel) as $r) { $r=trim($r); if ($r!=='' && !in_array($r,$relations,true)) $relations[]=$r; }
    if (trim((string)($row['main']??''))==='main') { $mn=trim((string)($row['name']??'')); if ($mn!=='' && !in_array($mn,$mainPersons,true)) $mainPersons[]=$mn; }
}
mysqli_stmt_close($stmt);

$star=0;
$st=mysqli_prepare($con,"SELECT MAX(CASE WHEN LOWER(TRIM(COALESCE(star,''))) IN ('1','star','yes','true') THEN 1 ELSE 0 END) AS is_starred FROM area WHERE grp_id=?");
if ($st) { mysqli_stmt_bind_param($st,'i',$gid); mysqli_stmt_execute($st); $sr=mysqli_stmt_get_result($st); if($x=mysqli_fetch_assoc($sr)) $star=(int)($x['is_starred']??0); mysqli_stmt_close($st); }

echo json_encode(['ok'=>true,'group'=>$group,'persons'=>$persons,'relations'=>$relations,'main_persons'=>$mainPersons,'is_starred'=>$star], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
