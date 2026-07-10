<?php

$host = "sql209.infinityfree.com";
$usuario = "if0_42269496";
$password = "bSG2clyLmCHAQ";
$base_datos = "if0_42269496_nataldata";

$conn = new mysqli($host, $usuario, $password, $base_datos);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>