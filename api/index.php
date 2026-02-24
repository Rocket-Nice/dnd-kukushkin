<?php
// Путь к публичной директории
$publicPath = __DIR__ . '/../public';

// Перенаправляем запросы в index.php Laravel
require $publicPath . '/index.php';