<?php
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

function returnError($message) {
    echo json_encode(["error" => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['username']) || empty($_POST['username'])) {
        returnError("Please provide a username!");
    }

    $username = mysqli_real_escape_string($conn, $_POST['username']);

    $result = mysqli_query($conn, "SELECT * FROM players WHERE username = '$username'");
    if (!$result) {
        returnError("Database error!");
    }

    if (mysqli_num_rows($result) > 0) {
        returnError("The username is already taken!");
    }

    $register = mysqli_query($conn, "INSERT INTO players (username, status) VALUES ('$username', 'waiting')");
    if (!$register) {
        returnError("Could not register player!");
    }

    $player_id = mysqli_insert_id($conn);

    echo json_encode(["success" => true, "player_id" => $player_id]);
}
?>
