<?php
$host = 'localhost';
$username = 'iotenvir_sealling';
$password = '@Sealling123';
$database = 'iotenvir_sealling';

$koneksi = mysqli_connect($host, $username, $password, $database);

if (!$koneksi) {
    die("Koneksi gagal: " . mysqli_connect_error());
}
header('Content-Type: application/json');
?>
