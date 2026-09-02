<?php
/* =========================================================
   TDL CONTROL PANEL - INDEX
   Core PHP only | No database required
   ========================================================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>The Divine Lands | Control Panel</title>
<style>
*{box-sizing:border-box}
:root{
    --navy:#092846;
    --navy2:#123f62;
    --blue:#236b98;
    --green:#159657;
    --gold:#c69a3b;
    --text:#20364a;
    --muted:#72879a;
    --line:#e3ebf1;
    --bg:#f3f7fa;
    --white:#fff;
}
html,body{margin:0;min-height:100%;font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--text)}
body{
    background:
        radial-gradient(circle at 8% 8%,rgba(35,107,152,.10),transparent 30%),
        radial-gradient(circle at 92% 15%,rgba(198,154,59,.10),transparent 28%),
        linear-gradient(135deg,#f5f8fb 0%,#eef4f7 100%);
}
.page{min-height:100vh;padding:34px 24px 42px}
.shell{max-width:1180px;margin:auto}

/* Header */
.header{
    position:relative;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:25px;
    padding:28px 32px;
    border:1px solid rgba(255,255,255,.8);
    border-radius:24px;
    background:linear-gradient(135deg,rgba(9,40,70,.98),rgba(24,75,110,.97));
    box-shadow:0 22px 55px rgba(9,40,70,.18);
}
.header:before{
    content:"";
    position:absolute;width:280px;height:280px;right:-95px;top:-150px;
    border:1px solid rgba(255,255,255,.12);border-radius:50%;
}
.header:after{
    content:"";position:absolute;width:190px;height:190px;right:60px;bottom:-135px;
    border:1px solid rgba(255,255,255,.09);border-radius:50%;
}
.brand{display:flex;align-items:center;gap:18px;position:relative;z-index:1}
.logo{
    width:70px;height:70px;border-radius:17px;object-fit:contain;
    background:#fff;padding:7px;box-shadow:0 8px 22px rgba(0,0,0,.15)
}
.brand h1{margin:0;color:#fff;font-size:25px;letter-spacing:.2px}
.brand p{margin:6px 0 0;color:rgba(255,255,255,.68);font-size:11px;font-weight:600;letter-spacing:.6px}
.panel-badge{
    position:relative;z-index:1;display:flex;align-items:center;gap:8px;
    padding:10px 14px;border:1px solid rgba(255,255,255,.14);border-radius:12px;
    background:rgba(255,255,255,.08);color:#fff;font-size:10px;font-weight:900;
    letter-spacing:.8px;text-transform:uppercase
}
.dot{width:8px;height:8px;border-radius:50%;background:#3ed27b;box-shadow:0 0 0 5px rgba(62,210,123,.12)}

/* Intro */
.intro{text-align:center;padding:34px 15px 25px}
.intro h2{margin:0;font-size:27px;color:var(--navy)}
.intro p{margin:8px auto 0;max-width:600px;color:var(--muted);font-size:12px;line-height:1.7}

/* Cards */
.cards{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px
}
.card{
    position:relative;overflow:hidden;display:flex;align-items:center;gap:20px;
    min-height:175px;padding:25px;
    text-decoration:none;color:inherit;
    background:rgba(255,255,255,.94);
    border:1px solid var(--line);border-radius:20px;
    box-shadow:0 12px 30px rgba(20,52,75,.07);
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease
}
.card:before{
    content:"";position:absolute;left:0;top:0;bottom:0;width:4px;
    background:var(--accent,var(--blue))
}
.card:after{
    content:"";position:absolute;width:120px;height:120px;right:-45px;bottom:-60px;
    border-radius:50%;background:var(--soft,rgba(35,107,152,.07))
}
.card:hover{
    transform:translateY(-5px);
    box-shadow:0 20px 42px rgba(20,52,75,.13);
    border-color:#cbdbe5
}
.card-icon{
    position:relative;z-index:1;flex:0 0 68px;width:68px;height:68px;
    display:flex;align-items:center;justify-content:center;
    border-radius:17px;background:var(--soft,rgba(35,107,152,.08));
    color:var(--accent,var(--blue));font-size:28px
}
.card-content{position:relative;z-index:1;min-width:0;flex:1}
.card-number{
    display:inline-block;margin-bottom:7px;color:var(--accent,var(--blue));
    font-size:9px;font-weight:900;letter-spacing:1.1px;text-transform:uppercase
}
.card h3{margin:0;color:var(--navy);font-size:18px}
.card p{margin:7px 0 0;color:var(--muted);font-size:11px;line-height:1.55}
.arrow{
    position:relative;z-index:1;flex:0 0 auto;width:34px;height:34px;
    display:flex;align-items:center;justify-content:center;border-radius:50%;
    background:#f2f6f8;color:#567083;font-size:18px;transition:.2s
}
.card:hover .arrow{background:var(--accent,var(--blue));color:#fff;transform:translateX(3px)}

.green{--accent:#159657;--soft:rgba(21,150,87,.09)}
.blue{--accent:#236b98;--soft:rgba(35,107,152,.09)}
.gold{--accent:#b68525;--soft:rgba(198,154,59,.12)}
.navy{--accent:#092846;--soft:rgba(9,40,70,.08)}

/* Footer */
.footer{
    margin-top:25px;padding:15px 5px;text-align:center;color:#91a1ae;
    font-size:9px;font-weight:700;letter-spacing:.5px
}

@media(max-width:760px){
    .page{padding:18px 13px 30px}
    .header{padding:21px;border-radius:19px}
    .logo{width:56px;height:56px;border-radius:13px}
    .brand h1{font-size:19px}.brand p{font-size:9px}
    .panel-badge{display:none}
    .intro{padding:27px 10px 20px}
    .intro h2{font-size:23px}
    .cards{grid-template-columns:1fr;gap:13px}
    .card{min-height:145px;padding:20px}
}
@media(max-width:420px){
    .brand{gap:12px}.brand h1{font-size:17px}
    .card-icon{flex-basis:57px;width:57px;height:57px;font-size:23px}
    .card h3{font-size:16px}
    .card p{font-size:10px}
}
</style>
</head>
<body>
<div class="page">
<div class="shell">

<header class="header">
    <div class="brand">
        <img class="logo" src="logo.png" alt="The Divine Lands">
        <div>
            <h1>The Divine Lands</h1>
            <p>LAND &amp; GROUP MANAGEMENT PANEL</p>
        </div>
    </div>
    <div class="panel-badge"><span class="dot"></span> Control Panel</div>
</header>

<section class="intro">
    <h2>Welcome to your management panel</h2>
    <p>Select a section below to manage groups, review group information, or manage your available land inventory.</p>
</section>

<section class="cards">

<a class="card green" href="dataadding.php">
    <div class="card-icon">＋</div>
    <div class="card-content">
        <span class="card-number">01 · Management</span>
        <h3>Add Group</h3>
        <p>Create a new group and add all required company, scheme and person details.</p>
    </div>
    <div class="arrow">›</div>
</a>

<a class="card blue" href="datashowing.php">
    <div class="card-icon">⌕</div>
    <div class="card-content">
        <span class="card-number">02 · Details</span>
        <h3>See Group Details</h3>
        <p>Open complete group information and manage the internal group details.</p>
    </div>
    <div class="arrow">›</div>
</a>

<a class="card navy" href="listshow.php">
    <div class="card-icon">☷</div>
    <div class="card-content">
        <span class="card-number">03 · Directory</span>
        <h3>See Group List</h3>
        <p>View a clean short list of groups with the key information at a glance.</p>
    </div>
    <div class="arrow">›</div>
</a>

<a class="card gold" href="available_lands.php">
    <div class="card-icon">⌂</div>
    <div class="card-content">
        <span class="card-number">04 · Land Inventory</span>
        <h3>Available Lands</h3>
        <p>Manage available land, buyers, location, details, remarks, status and starred properties.</p>
    </div>
    <div class="arrow">›</div>
</a>

</section>

<div class="footer">THE DIVINE LANDS &nbsp;•&nbsp; MANAGEMENT SYSTEM</div>

</div>
</div>
</body>
</html>
