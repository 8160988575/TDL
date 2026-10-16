<?php
declare(strict_types=1);

/*
 * THE DIVINE LANDS — Premium Land Opportunity Studio v2
 * Standalone Core PHP. Replace the old file with this distinctly named version.
 */
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function clean(string $v, int $max = 500): string {
    $v = trim($v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
function validMapUrl(string $url): bool {
    if ($url === '') return true;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return $scheme === 'https' && in_array($host, ['maps.app.goo.gl','google.com','www.google.com','maps.google.com'], true);
}

$defaults = [
    'location'=>'',
    'map_url'=>'',
    'image_url'=>'',
    'area'=>'',
    'area_unit'=>'SQ. YD.',
    'tp'=>'',
    'fp'=>'',
    'opportunity'=>'',
    'price'=>'',
    'fsi'=>'',
    'zone'=>'',
    'road_width'=>'',
    'remarks'=>'',
    'contact'=>'+91-8160988575',
    'website'=>'thedivinelands.in',
    'company'=>'The Divine Lands',
    'tagline'=>'World of Lands@The_Divine_Lands'
];
$data = $defaults;
// Reset clears all property-specific fields while preserving company branding.
$resetRequested = isset($_GET['reset']) && $_GET['reset'] === '1';
if ($resetRequested) {
    foreach (['location', 'map_url', 'image_url', 'area', 'tp', 'fp', 'price', 'fsi', 'zone', 'road_width', 'remarks'] as $field) {
        $data[$field] = '';
    }
    $data['area_unit'] = 'SQ. YD.';
    $data['opportunity'] = 'Outright Sale';
}
$errors = [];
$message = '';
$notice = '';
$submitted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$storageFile = __DIR__ . '/saved_land_options.json';
$savedOptions = [];
if (is_file($storageFile)) {
    $stored = json_decode((string)file_get_contents($storageFile), true);
    if (is_array($stored)) $savedOptions = $stored;
}
$action = $submitted ? (string)($_POST['action'] ?? 'generate') : '';
$savedName = $submitted ? clean((string)($_POST['saved_name'] ?? ''), 100) : '';
if ($submitted) {
    foreach ($defaults as $key=>$default) {
        $data[$key] = clean((string)($_POST[$key] ?? $default), $key === 'remarks' ? 1500 : 300);
    }
    if (!in_array($data['area_unit'], ['SQ. YD.','SQ. FT.','ACRE','HECTARE','VIGHAS','SQYD SURVEY NO.','SQYD FP NO.'], true)) $data['area_unit'] = 'SQ. YD.';
    $types = ['JV Opportunity','Outright Sale','Joint Development','Farmhouse Land','Residential Land','Commercial Land','Industrial Land','Investment Opportunity','Other'];
    if (!in_array($data['opportunity'], $types, true)) $data['opportunity'] = 'Other';
    if ($action === 'load_option') {
        $requested = clean((string)($_POST['load_name'] ?? ''), 100);
        if (isset($savedOptions[$requested]) && is_array($savedOptions[$requested])) {
            $data = array_merge($defaults, array_intersect_key($savedOptions[$requested], $defaults));
            $savedName = $requested;
            $notice = 'Loaded saved land option: ' . $requested;
        } else $errors[] = 'Please select a saved land option to load.';
    } elseif ($action === 'delete_option') {
        $requested = clean((string)($_POST['load_name'] ?? ''), 100);
        if (isset($savedOptions[$requested])) {
            unset($savedOptions[$requested]);
            $json = json_encode($savedOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false && file_put_contents($storageFile, $json, LOCK_EX) !== false) $notice = 'Deleted saved land option: ' . $requested;
            else $errors[] = 'Could not update saved options. Please check folder write permissions.';
        } else $errors[] = 'Please select a saved land option to delete.';
    }
}

if ($submitted && !in_array($action, ['load_option','delete_option'], true)) {
    if ($data['location'] === '') $errors[] = 'Location is required.';
    if ($data['area'] === '' || !preg_match('/^[0-9,.\s]+$/', $data['area'])) $errors[] = 'Enter a valid land area.';
    if ($data['map_url'] !== '' && !validMapUrl($data['map_url'])) $errors[] = 'Use a valid HTTPS Google Maps link, or leave it blank.';
    if ($data['image_url'] !== '' && (!filter_var($data['image_url'], FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($data['image_url'], PHP_URL_SCHEME)), ['https','http'], true))) $errors[] = 'Property image must be a valid HTTP(S) URL, or leave it blank.';

    if (!$errors) {
        if ($action === 'save_option') {
            if ($savedName === '') $errors[] = 'Enter a name for this saved land option.';
            else {
                $savedOptions[$savedName] = $data;
                $json = json_encode($savedOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json !== false && file_put_contents($storageFile, $json, LOCK_EX) !== false) $notice = 'Saved land option: ' . $savedName;
                else $errors[] = 'Could not save options. Please make the formatter folder writable by PHP.';
            }
        }
    }
    if (!$errors && !in_array($action, ['delete_option'], true)) {
        $lines = [
            // 'PROPERTY DETAILS',
            strtoupper($data['location']),
            '',
            'Land: ' . $data['area'] . ' ' . $data['area_unit']
        ];
        if ($data['tp'] !== '') $lines[] = 'TP: ' . $data['tp'];
        if ($data['fp'] !== '') $lines[] = 'FP: ' . $data['fp'];
        $lines[] = 'Type: ' . $data['opportunity'];
        if ($data['price'] !== '') $lines[] = 'Price / Expectation: ' . $data['price'];
        if ($data['fsi'] !== '') $lines[] = 'FSI: ' . $data['fsi'];
        if ($data['zone'] !== '') $lines[] = 'Zone: ' . $data['zone'];
        if ($data['road_width'] !== '') $lines[] = 'Road: ' . $data['road_width'];
        if ($data['remarks'] !== '') { $lines[] = ''; $lines[] = 'Details: ';$lines[] = $data['remarks']; }
        if ($data['map_url'] !== '') {
            $lines[] = '';
            $lines[] = '📍 Google Maps:';
            $lines[] = $data['map_url'];
        }
        $lines[] = '';
        $lines[] = $data['company'];
        $lines[] = $data['contact'];
        $lines[] = $data['website'];
        $lines[] = $data['tagline'];
        $message = implode("\n", $lines);
    }
    if (is_file($storageFile)) {
        $stored = json_decode((string)file_get_contents($storageFile), true);
        if (is_array($stored)) $savedOptions = $stored;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Premium Land Opportunity Studio | The Divine Lands</title>
<style>
:root{--ink:#102b55;--blue:#0759a7;--cyan:#0ea5b7;--gold:#ffc83d;--paper:#f1f7ff;--muted:#63738b;--line:#dce7f5}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);font:15px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:radial-gradient(circle at 0% 0%,#d9f0ff 0,transparent 30%),radial-gradient(circle at 100% 10%,#fff0c2 0,transparent 27%),#f5f8fe}
.wrap{max-width:1380px;margin:auto;padding:26px 20px 48px}
.hero{position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:center;gap:20px;padding:32px 36px;border-radius:24px;color:white;background:linear-gradient(115deg,#073b8c,#0879b9 58%,#10a5b2);box-shadow:0 20px 48px #0759a729}
.hero:before,.hero:after{content:"";position:absolute;border:1px solid #ffffff30;border-radius:50%;pointer-events:none}
.hero:before{width:350px;height:350px;right:65px;top:-245px;box-shadow:0 0 0 28px #ffffff0c,0 0 0 58px #ffffff0a}
.hero:after{width:210px;height:210px;right:-80px;bottom:-155px}
.brandline{font-size:11px;letter-spacing:.22em;font-weight:900;color:#ffdf82}.hero h1{font-size:clamp(27px,3.5vw,42px);letter-spacing:-.04em;line-height:1.08;margin:12px 0 9px}.hero p{margin:0;color:#d9efff;max-width:620px}.hero-mark{z-index:1;width:94px;height:82px;border:1px solid #ffdc7d;border-radius:18px;display:grid;place-items:center;color:#ffdc7d;font-size:24px;font-weight:900;letter-spacing:.1em;background:#ffffff10;flex-shrink:0;padding:8px}.hero-mark img{max-width:100%;max-height:100%;object-fit:contain}
.monogram{width:42px;height:38px;border:1px solid #ffdc7d;color:#ffdc7d;border-radius:8px;display:grid;place-items:center;overflow:hidden;flex-shrink:0}.monogram img{max-width:100%;max-height:100%;object-fit:contain}.monogram span{font-size:10px;font-weight:900}
.jv-highlight{display:inline-flex;align-items:center;gap:7px;align-self:flex-start;background:linear-gradient(100deg,#ffdb67,#ffb600);color:#173a6a!important;padding:8px 11px;border-radius:7px;font-weight:950;box-shadow:0 5px 16px #ffbf2040;letter-spacing:.09em!important}
.jv-dot{width:7px;height:7px;border-radius:50%;background:#0759a7;box-shadow:0 0 0 3px #0759a71b}
.art-footer small{font-size:8px;color:#536d8e;margin-top:4px}

.layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.92fr);gap:22px;margin-top:22px;align-items:start}
.notice{max-width:1380px;margin:14px auto 0;padding:12px 16px;border:1px solid #a7e5c5;border-radius:12px;background:#e9fff2;color:#12653f;font-weight:800;font-size:13px}.saved-options-form{padding:14px;margin:0 0 18px;background:#f3f8ff;border:1px solid #d9eaff;border-radius:14px}.panel{min-width:0;background:#ffffffed;border:1px solid var(--line);border-radius:20px;padding:25px;box-shadow:0 16px 44px #153b6810}
.panel h2{font-size:20px;margin:0;letter-spacing:-.02em}.sub{font-size:13px;color:var(--muted);margin:5px 0 20px}
.section{font-size:10px;letter-spacing:.16em;text-transform:uppercase;font-weight:900;color:#0874a9;margin:24px 0 12px;display:flex;align-items:center;gap:10px}.section:after{content:"";height:1px;flex:1;background:linear-gradient(90deg,#a9d8ee,transparent)}.section:first-child{margin-top:0}
.fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{min-width:0}.full{grid-column:1/-1}
label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#304461}
input,select,textarea{width:100%;padding:12px 13px;border:1px solid #d8e3f1;border-radius:11px;background:#fbfdff;color:#102b55;font:inherit;font-size:13px;outline:none;transition:.18s}
input:focus,select:focus,textarea:focus{border-color:#1396cb;box-shadow:0 0 0 4px #dff4ff;background:white}textarea{min-height:90px;resize:vertical}
.hint{font-size:11px;line-height:1.55;color:#6d7b91;margin-top:7px}
.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:19px}.btn{border:0;border-radius:11px;padding:12px 15px;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;text-decoration:none;font:inherit;font-size:12px;font-weight:900;transition:transform .15s,box-shadow .15s}.btn:hover{transform:translateY(-1px);box-shadow:0 7px 18px #0c396b20}.primary{color:#fff;background:linear-gradient(110deg,#0759a7,#087fbd)}.secondary{background:#eaf4ff;color:#0759a7;border:1px solid #d2e9ff}.goldbtn{background:linear-gradient(110deg,#ffda65,#ffb800);color:#402e00}
.preview{position:sticky;top:14px;border-top:4px solid #ffbf20}.preview-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:15px}.pill{font-size:9px;font-weight:900;letter-spacing:.1em;border-radius:30px;background:#dff8ec;color:#08734c;padding:7px 10px;white-space:nowrap}
.property-art{overflow:hidden;border:1px solid #d3e3f5;border-radius:17px;background:#fff;box-shadow:0 18px 42px #0a376421;color:#102b55}
.art-cover{min-height:285px;padding:21px 23px 18px;color:white;background-color:#0759a7;background-image:radial-gradient(circle at 86% 15%,#ffcf4e66,transparent 24%),linear-gradient(125deg,#073b8c 0%,#0879b9 56%,#10a5b2 100%);background-size:cover;background-position:center;display:flex;flex-direction:column;position:relative}
.art-cover:after{content:"";position:absolute;right:-65px;top:55px;width:245px;height:245px;border:1px solid #ffdc7d55;border-radius:50%;box-shadow:0 0 0 25px #ffffff0c,0 0 0 55px #ffffff09;pointer-events:none}
.art-topline{display:flex;align-items:center;gap:9px;position:relative;z-index:1}.monogram{width:33px;height:33px;border:1px solid #ffdc7d;color:#ffdc7d;border-radius:8px;display:grid;place-items:center;font-size:10px;font-weight:900}.art-brand{font-size:9px;font-weight:900;letter-spacing:.14em;max-width:48%;overflow-wrap:anywhere}.art-index{margin-left:auto;color:#ffdf8a;font-size:8px;letter-spacing:.12em}
.art-kicker{font-size:8px;letter-spacing:.22em;color:#ffdf82;font-weight:900;margin-top:38px;position:relative;z-index:1}.art-location{font-size:clamp(25px,3vw,36px);line-height:1.04;font-weight:900;letter-spacing:-.045em;margin-top:8px;max-width:95%;overflow-wrap:anywhere;position:relative;z-index:1}.art-type{font-size:9px;letter-spacing:.15em;color:#e5f7ff;font-weight:850;margin-top:12px;position:relative;z-index:1}.art-rule{height:2px;width:54px;background:#ffd34f;margin-top:17px;position:relative;z-index:1}.art-description{font-size:10px;color:#e2f5ff;margin-top:10px;max-width:270px;position:relative;z-index:1}.art-bottom{display:flex;justify-content:space-between;gap:10px;font-size:7px;letter-spacing:.12em;color:#d3eaff;margin-top:auto;padding-top:24px;position:relative;z-index:1}
.art-body{padding:21px 23px 14px;background:linear-gradient(#fff,#f8fbff)}.art-area{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e3edf7;padding:10px 13px 12px;margin-bottom:2px;background:linear-gradient(110deg,#f0f9ff,#fff9e8);border:1px solid #dbeaf6;border-radius:12px}.art-label{display:block;font-size:8px;letter-spacing:.15em;color:#0879b9;font-weight:900}.art-size-line{display:flex;align-items:baseline;gap:7px;flex-wrap:wrap}.art-number{font-size:clamp(20px,2.2vw,27px);line-height:1.05;font-weight:850;letter-spacing:-.035em;margin-top:4px;color:#073b8c}.art-unit{font-size:10px;letter-spacing:.06em;font-weight:900;color:#63738b;margin-top:0}.area-ref{font-size:9px;font-weight:850;color:#8b6100;background:#ffedb4;border-radius:5px;padding:3px 6px}.area-mark{border-left:2px solid #ffca36;padding-left:12px;color:#0c8ca9;font-size:8px;line-height:1.5;letter-spacing:.1em;font-weight:900;text-align:right}.presented-brand{padding:10px 12px;border-radius:11px;background:linear-gradient(110deg,#edf8ff,#fff5d8);border:1px solid #e2d19c}.presented-label{display:inline-block;background:#ffdc7b;color:#674400;border-radius:5px;padding:3px 7px;font-size:7px;letter-spacing:.13em;font-weight:950}.presented-brand strong{display:block;margin-top:7px}
.art-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}.fact{padding:12px 0 10px;border-bottom:1px solid #e8eff7;min-width:0}.fact span{display:block;font-size:7px;letter-spacing:.13em;color:#75849b;font-weight:900}.fact strong{display:block;font-size:12px;line-height:1.4;color:#173b6a;margin-top:4px;overflow-wrap:anywhere}
.art-remarks{padding:14px 0 4px}.art-remarks p{font-size:10px;line-height:1.65;color:#52657e;margin:6px 0 0;overflow-wrap:anywhere}.art-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;border-top:1px solid #e2ebf5;margin-top:16px;padding-top:14px}.art-footer strong,.art-footer small{display:block;overflow-wrap:anywhere}.art-footer strong{font-size:10px;letter-spacing:.06em;margin-top:4px}.presented-brand strong{font-size:11px;color:#073b8c}.art-footer small{font-size:8px;color:#687b94;margin-top:3px}.location-stamp{max-width:145px;flex-shrink:0}.stamp-icon{width:30px;height:30px;border-radius:9px;background:#e0f4ff;color:#0759a7;display:grid;place-items:center;font-size:17px;font-weight:900}.stamp-title{font-size:8px;color:#0759a7;letter-spacing:.07em;font-weight:900;margin-top:5px}.stamp-note{font-size:7px;color:#718097;line-height:1.4;margin-top:3px}.disclaimer{border-top:1px solid #edf1f6;margin-top:14px;padding-top:9px;font-size:7px;color:#909bad}
.message-title{font-size:9px;letter-spacing:.16em;color:#0874a9;font-weight:900;margin:20px 0 8px}.message{white-space:pre-wrap;overflow-wrap:anywhere;background:#f5f9ff;border:1px solid #dfebf8;border-radius:12px;padding:17px;min-height:190px;font:12px/1.8 ui-monospace,SFMono-Regular,Consolas,monospace;color:#1c3558}.status{background:#f2f8ff;border-radius:9px;padding:10px 11px}
.errors{background:#fff0f0;border:1px solid #ffc9c9;color:#9c2820;padding:12px 15px;border-radius:10px;margin:16px 0}.foot{text-align:center;color:#8390a4;font-size:11px;margin-top:22px}
@media(max-width:930px){.layout{grid-template-columns:1fr}.preview{position:static}.hero{padding:25px}.hero-mark{display:none}}
@media(max-width:560px){.art-number{font-size:22px}.art-area{padding:9px 10px}.wrap{padding:12px 10px 28px}.hero{padding:22px 19px;border-radius:17px}.panel{padding:17px}.fields{grid-template-columns:1fr}.full{grid-column:auto}.art-cover{min-height:260px;padding:18px}.art-body{padding:17px}.art-actions .btn{flex:1 1 100%}.art-number{font-size:36px}}
@media print{@page{size:A4;margin:10mm}body{background:white!important}.wrap{padding:0;max-width:none}.hero,.layout>section:first-child,.preview-head,.art-actions,.message-title,.message,.status,.foot,.errors{display:none!important}.layout{display:block;margin:0}.preview{display:block;position:static;border:0;box-shadow:none;padding:0;margin:0}.property-art{max-width:155mm;margin:0 auto;border-radius:0;box-shadow:none;break-inside:avoid}.art-cover{min-height:82mm;-webkit-print-color-adjust:exact;print-color-adjust:exact}.art-cover,.art-body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="wrap">
<header class="hero">
  <div>
    <div class="brandline">THE DIVINE LANDS · LAND OPPORTUNITY STUDIO</div>
    <h1>Present land like an investment.</h1>
    <p>Create a vibrant, client-ready property poster with your logo, and a clean WhatsApp message with a clickable Google Maps link.</p>
  </div>
  <div class="hero-mark" aria-label="The Divine Lands logo"><img src="logo.png" alt="The Divine Lands logo" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><span style="display:none">DL</span></div>
</header>

<?php if ($errors): ?><div class="errors"><strong>Please check:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice" role="status"><?= e($notice) ?></div><?php endif; ?>

<div class="layout">
<section class="panel">
  <h2>Build your opportunity</h2>
  <p class="sub">Only entered details appear in the property poster. Optional facts stay hidden when blank.</p>
  <form method="post" action="" class="saved-options-form">
    <div class="section">Saved land options</div>
    <div class="fields">
      <div class="field full"><label for="load_name">Load a previously saved land</label><select id="load_name" name="load_name"><option value="">Select saved land option...</option><?php foreach ($savedOptions as $optionName => $optionData): ?><option value="<?= e((string)$optionName) ?>" <?= $savedName === (string)$optionName ? 'selected' : '' ?>><?= e((string)$optionName) ?></option><?php endforeach; ?></select><div class="hint">Choose a saved option, then load it to refill the form and regenerate the poster/message.</div></div>
    </div>
    <div class="actions"><button class="btn secondary" type="submit" name="action" value="load_option">↻ Load Selected</button><button class="btn secondary" type="submit" name="action" value="delete_option" onclick="return confirm('Delete this saved land option?')">✕ Delete Selected</button></div>
  </form>
  <form method="post" action="" id="landForm">
    <input type="hidden" name="action" value="generate">
    <input type="hidden" name="load_name" value="">
    <div class="section">Location & identity</div>
    <div class="fields">
      <div class="field full"><label for="location">Location / Area *</label><input id="location" name="location" required value="<?= e($data['location']) ?>" placeholder="e.g. Ognaj, Ahmedabad"></div>
      <div class="field full"><label for="map_url">Google Maps link</label><input id="map_url" name="map_url" type="url" value="<?= e($data['map_url']) ?>" placeholder="https://maps.app.goo.gl/..."><div class="hint">This URL appears as a clickable link in the WhatsApp text, not inside the PNG.</div></div>
      <div class="field full"><label for="image_url">Property / site photograph URL (optional)</label><input id="image_url" name="image_url" type="url" value="<?= e($data['image_url']) ?>" placeholder="Direct link to a site photo"><div class="hint">Use a publicly accessible image URL. Leave blank for the designed blue-gradient poster.</div></div>
      <div class="field"><label for="tp">TP Number</label><input id="tp" name="tp" value="<?= e($data['tp']) ?>" placeholder="e.g. 54"></div>
      <div class="field"><label for="fp">FP Number</label><input id="fp" name="fp" value="<?= e($data['fp']) ?>" placeholder="e.g. 12"></div>
    </div>

    <div class="section">Land details</div>
    <div class="fields">
      <div class="field"><label for="area">Land Area *</label><input id="area" name="area" required value="<?= e($data['area']) ?>" placeholder="e.g. 6620"></div>
      <div class="field"><label for="area_unit">Area Unit</label><select id="area_unit" name="area_unit"><?php foreach (['SQ. YD.','SQ. FT.','ACRE','HECTARE','VIGHAS','SQYD SURVEY NO.','SQYD FP NO.'] as $unit): ?><option value="<?= e($unit) ?>" <?= $data['area_unit']===$unit?'selected':'' ?>><?= e($unit) ?></option><?php endforeach; ?></select></div>
      <div class="field full"><label for="opportunity">Opportunity Type</label><select id="opportunity" name="opportunity"><?php foreach (['JV Opportunity','Outright Sale','Joint Development','Farmhouse Land','Residential Land','Commercial Land','Industrial Land','Investment Opportunity','Other'] as $type): ?><option value="<?= e($type) ?>" <?= $data['opportunity']===$type?'selected':'' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="price">Price / Expectation</label><input id="price" name="price" value="<?= e($data['price']) ?>" placeholder="e.g. On request"></div>
      <div class="field"><label for="fsi">FSI</label><input id="fsi" name="fsi" value="<?= e($data['fsi']) ?>" placeholder="Verified FSI"></div>
      <div class="field"><label for="zone">Zone</label><input id="zone" name="zone" value="<?= e($data['zone']) ?>" placeholder="e.g. R1 / R2"></div>
      <div class="field"><label for="road_width">Road Width</label><input id="road_width" name="road_width" value="<?= e($data['road_width']) ?>" placeholder="e.g. 30 m"></div>
      <div class="field full"><label for="remarks">Opportunity Notes</label><textarea id="remarks" name="remarks" maxlength="1500" placeholder="Verified highlights, deal terms, nearby landmarks..."><?= e($data['remarks']) ?></textarea></div>
    </div>

    <div class="section">Your brand</div>
    <div class="fields">
      <div class="field"><label for="company">Company Name</label><input id="company" name="company" value="<?= e($data['company']) ?>"></div>
      <div class="field"><label for="contact">Company Phone</label><input id="contact" name="contact" value="<?= e($data['contact']) ?>" placeholder="+91-..."></div>
      <div class="field"><label for="website">Website</label><input id="website" name="website" value="<?= e($data['website']) ?>" placeholder="thedivinelands.in"></div>
      <div class="field full"><label for="tagline">Tagline</label><input id="tagline" name="tagline" value="<?= e($data['tagline']) ?>"></div>
    </div>
    <div class="section">Save this land for later</div>
    <div class="fields"><div class="field full"><label for="saved_name">Saved option name</label><input id="saved_name" name="saved_name" value="<?= e($savedName) ?>" placeholder="e.g. Ognaj 6620 SQYD - JV"><div class="hint">Use a unique name. Saving an existing name updates that saved option.</div></div></div>
    <div class="actions"><button class="btn primary" type="submit" name="action" value="generate">✦ Generate Premium Poster</button><button class="btn goldbtn" type="submit" name="action" value="save_option">▣ Save Land Option</button><a class="btn secondary" id="resetBtn" href="<?= e(basename($_SERVER['PHP_SELF'] ?? 'land_opportunity_formatter_v2.php')) ?>?reset=1">Reset</a></div>
  </form>
</section>

<section class="panel preview">
  <div class="preview-head"><div><h2>Client preview</h2><p class="sub" style="margin:4px 0 0">Your shareable artwork updates after generation.</p></div><span class="pill">PREMIUM EDITION</span></div>
  <article class="property-art" id="propertyArt">
    <div class="art-cover"<?php if ($data['image_url'] !== ''): ?> style="background-image:linear-gradient(90deg,rgba(5,28,68,.93),rgba(5,71,130,.68) 62%,rgba(5,71,130,.3)),url('<?= e($data['image_url']) ?>')"<?php endif; ?>>
      <div class="art-topline"><span class="monogram"><img src="logo.png" alt="The Divine Lands logo" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><span style="display:none">DL</span></span><span class="art-brand"><?= e($data['company']) ?></span><span class="art-index">@THE WORLD OF LANDS</span></div>
      <div class="art-kicker">FEATURED LOCATION</div>
      <div class="art-location"><?= e(strtoupper($data['location'] ?: 'LAND LOCATION')) ?></div>
      <?php if ($data['opportunity'] === 'JV Opportunity'): ?>
      <div class="art-type jv-highlight"><span class="jv-dot"></span> JOINT VENTURE OPPORTUNITY</div>
      <?php else: ?>
      <div class="art-type"><?= e(strtoupper($data['opportunity'])) ?></div>
      <?php endif; ?>
      <div class="art-rule"></div>
      <div class="art-description">Land details curated by The Divine Lands.</div>
      <div class="art-bottom"><span>AHMEDABAD · GIFT CITY · GANDHINAGAR</span>
      <!-- <span>THE WORLD OF LANDS</span> -->
    </div>
    </div>
    <div class="art-body">
      <div class="art-area"><div class="area-details"><span class="art-label">LAND SIZE</span><div class="art-size-line"><span class="art-number"><?= e($data['area'] ?: '—') ?></span><span class="art-unit"><?= e($data['area_unit']) ?></span><?php if ($data['fp'] !== ''): ?><span class="area-ref">FP <?= e($data['fp']) ?></span><?php endif; ?></div></div><div class="area-mark">LAND<br>DETAILS</div></div>
      <div class="art-facts">
        <?php if ($data['tp'] !== ''): ?><div class="fact"><span>TP NUMBER</span><strong><?= e($data['tp']) ?></strong></div><?php endif; ?>
        <?php if ($data['fp'] !== ''): ?><div class="fact"><span>FP NUMBER</span><strong><?= e($data['fp']) ?></strong></div><?php endif; ?>
        <?php if ($data['fsi'] !== ''): ?><div class="fact"><span>FSI</span><strong><?= e($data['fsi']) ?></strong></div><?php endif; ?>
        <?php if ($data['zone'] !== ''): ?><div class="fact"><span>ZONE</span><strong><?= e($data['zone']) ?></strong></div><?php endif; ?>
        <?php if ($data['road_width'] !== ''): ?><div class="fact"><span>ROAD WIDTH</span><strong><?= e($data['road_width']) ?></strong></div><?php endif; ?>
        <?php if ($data['price'] !== ''): ?><div class="fact"><span>EXPECTATION</span><strong><?= e($data['price']) ?></strong></div><?php endif; ?>
      </div>
      <?php if ($data['remarks'] !== ''): ?><div class="art-remarks"><span class="art-label">OPPORTUNITY NOTES</span><p><?= nl2br(e($data['remarks'])) ?></p></div><?php endif; ?>
      <div class="art-footer">
        <div class="presented-brand"><span class="presented-label">PRESENTED BY</span><strong><?= e($data['company']) ?></strong>
          <small><?= e($data['tagline']) ?></small>
          <?php if ($data['contact'] !== ''): ?><small><?= e($data['contact']) ?></small><?php endif; ?>
          <?php if ($data['website'] !== ''): ?><small><?= e($data['website']) ?></small><?php endif; ?></div>
        <?php if ($data['map_url'] !== ''): ?><div class="location-stamp"><div class="stamp-icon">↗</div><div class="stamp-title">LOCATION AVAILABLE</div><div class="stamp-note">Clickable Google Maps link included in the WhatsApp message.</div></div><?php endif; ?>
      </div>
      <div class="disclaimer">Property details are subject to verification and due diligence.</div>
    </div>
  </article>

  <div class="actions art-actions">
    <button class="btn primary" type="button" id="downloadImageBtn">⬇ Download Shareable PNG</button>
    <button class="btn secondary" type="button" id="printBtn">▣ Save / Print / PDF</button>
    <button class="btn secondary" type="button" id="copyBtn">Copy WhatsApp Message</button>
    <button class="btn goldbtn" type="button" id="whatsappBtn">↗ Open WhatsApp</button>
  </div>
  <div class="message-title">WHATSAPP MESSAGE · CLICKABLE LOCATION LINK</div>
  <div id="message" class="message"><?= $message !== '' ? e($message) : e($resetRequested ? implode("\n", array_filter([$data['company'], $data['contact'], $data['website'], $data['tagline']], static fn($v) => $v !== '')) : "OGNAJ, AHMEDABAD\n\nLand: 6620 SQ. YD.\nTP: 54\nType: JV Opportunity\n\n📍 Google Maps:\nhttps://maps.app.goo.gl/whuNTbKgpuL4WHBy7\n\nThe Divine Lands\n +91-8160988575\nthedivinelands.in\nWorld of Lands@The_Divine_Lands") ?></div>
  <p class="hint status" id="status" role="status" aria-live="polite">Tip: send the PNG image and this message together. The URL in the message is clickable; the image stays clean without a QR code.</p>
</section>
</div>
<div class="foot">THE DIVINE LANDS · Every opportunity deserves a premium presentation.</div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
const messageEl = document.getElementById('message');
const statusEl = document.getElementById('status');
const locForFilename = <?= json_encode($data['location'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

document.getElementById('downloadImageBtn').addEventListener('click', async () => {
  if (!window.html2canvas) {
    statusEl.textContent = 'Image export library could not load. Check your internet connection and try again.';
    return;
  }
  statusEl.textContent = 'Creating your high-resolution poster...';
  try {
    const target = document.getElementById('propertyArt');
    const canvas = await html2canvas(target, {scale: 3, backgroundColor:'#ffffff', useCORS:true, allowTaint:false, logging:false});
    const link = document.createElement('a');
    const slug = (locForFilename || 'land-opportunity').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    link.download = 'the-divine-lands-' + (slug || 'land-opportunity') + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
    statusEl.textContent = 'PNG downloaded. Send it with the WhatsApp message below for the clickable map link.';
  } catch (err) {
    statusEl.textContent = 'Image export failed. If you entered an external site photo, try a publicly accessible image URL or leave that field blank.';
  }
});
document.getElementById('printBtn').addEventListener('click', () => window.print());
document.getElementById('copyBtn').addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(messageEl.innerText); statusEl.textContent = 'WhatsApp message copied. Paste it alongside the property image.'; }
  catch (err) {
    const area = document.createElement('textarea'); area.value = messageEl.innerText; document.body.appendChild(area); area.select();
    const ok = document.execCommand('copy'); area.remove();
    statusEl.textContent = ok ? 'WhatsApp message copied.' : 'Copy was blocked. Select the message text and copy it manually.';
  }
});
document.getElementById('whatsappBtn').addEventListener('click', () => {
  window.open('https://wa.me/?text=' + encodeURIComponent(messageEl.innerText), '_blank', 'noopener,noreferrer');
});
</script>
</body>
</html>
