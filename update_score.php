<?php
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

$player_id = $_POST['player_id'] ?? null;
$score = $_POST['score'] ?? null;

if (!$player_id || !$score) {
    die(json_encode(["error" => "Missing player_id or score"]));
}

$player_id = mysqli_real_escape_string($conn, $player_id);
$score = mysqli_real_escape_string($conn, $score);

$query = "UPDATE game_players SET score = '$score' WHERE player_id = '$player_id'";
$result = mysqli_query($conn, $query);

if ($result) {
    echo json_encode(["success" => "Score updated successfully."]);
} else {
    echo json_encode(["error" => "Update failed.", "details" => mysqli_error($conn)]);
}

mysqli_close($conn);
?>
