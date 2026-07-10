<?php
// Copia este archivo como conexion.php y cambia los datos por los de tu servidor.
$host = "localhost";
$usuario = "TU_USUARIO";
$password = "TU_PASSWORD";
$base_datos = "TU_BASE_DE_DATOS";

$conn = new mysqli($host, $usuario, $password, $base_datos);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
