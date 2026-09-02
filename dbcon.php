
<?php
// $con=mysqli_connect('localhost','root','harsh','mstudy');
//$con=mysqli_connect('localhost','checkinguser','checkinguser','checkingdb');


// include 'stapa_validation.php';

// $con=@mysqli_connect('localhost','root','','dsl');
$con=@mysqli_connect('localhost','root','','tdl');
if(!$con) {


   $con=@mysqli_connect('localhost','harshchavda','harshchavda','user_base');



}

if(!$con) {


   $con=@mysqli_connect('localhost','root','','mstudy');



}

if(!$con) {


   $con=@mysqli_connect('sql12.freesqldatabase.com','sql12348338','pnkSCVtk3e','sql12348338');



}
   


?>
