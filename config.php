<?php
$host = "sql213.ezyro.com";
$port = 3306;
$dbname = "ezyro_38975348_unity_game";
$user = "ezyro_38975348";
$password = "4247c00a4d1f";

$conn = new mysqli($host, $user, $password, $dbname, $port);

if ($conn->connect_error) {
    die(json_encode(["error" => "Connection to the database failed: " . $conn->connect_error]));
}
?>
