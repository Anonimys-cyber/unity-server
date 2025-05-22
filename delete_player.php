<?php
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

function returnError($message) {
    echo json_encode(["error" => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['player_id']) || empty($_POST['player_id'])) {
        returnError("Please provide player_id!");
    }

    $player_id = intval($_POST['player_id']);

    $check = mysqli_query($conn, "SELECT * FROM players WHERE id = $player_id");
    if (!$check) {
        returnError("Database error while checking player.");
    }

    if (mysqli_num_rows($check) === 0) {
        returnError("Player not found.");
    }

    $delete = mysqli_query($conn, "DELETE FROM players WHERE id = $player_id");
    if (!$delete) {
        returnError("Could not delete player.");
    }

    echo json_encode(["success" => true, "message" => "Player deleted."]);
} else {
    returnError("Invalid request method.");
}
?>
