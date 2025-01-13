<?php 
require '../config.php';
require '../cors.php';

function obtenerRangoPrecios($pdo)
{
    $query = "SELECT MIN(precio) AS minPrice, MAX(precio) AS maxPrice FROM productos";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $products = $stmt->fetch();
    echo json_encode($products);
}

obtenerRangoPrecios($pdo);
?>