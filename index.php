<?php
require 'vendor/autoload.php';
require 'SimplePdo.php'; // Asegúrate de tener este archivo
//use Phpml\Statistics\Correlation;
/*if (!class_exists('Phpml\Statistics\Correlation')) {
    echo "ERROR CRÍTICO: La librería PHP-ML no está cargada en el sistema.";
    // Opcional: ver qué hay en el autoload
    // print_r(get_declared_classes()); 
    die();
}*/

// 1. Configuración de la base de datos
$dsn = 'mysql:host=localhost;dbname=pokemon_db;charset=utf8';
$username = 'habib'; 
$password = 'habib'; // Pon tu contraseña si tienes

// 2. Registrar SimplePdo en Flight
Flight::register('db', 'SimplePdo', [$dsn, $username, $password]);

// --- APARTADO A: ENDPOINTS CRUD ---

// GET /pokemons - Retornar todos
Flight::route('GET /pokemons', function() {
    $db = Flight::db();
    $lista = $db->query("SELECT * FROM pokemons")->fetchAll();
    Flight::json($lista);
});

// GET /pokemons/{id} - Retornar uno o 404
Flight::route('GET /pokemons/@id', function($id) {
    $db = Flight::db();
    $pokemon = $db->query("SELECT * FROM pokemons WHERE id = ?", [$id])->fetch();
    
    if ($pokemon) {
        Flight::json($pokemon);
    } else {
        Flight::halt(404, json_encode(["error" => "Pokémon no encontrado"]));
    }
});

// POST /pokemons - Insertar nuevo
Flight::route('POST /pokemons', function() {
    $db = Flight::db();
    $request = Flight::request();
    
    $datos = [
        $request->data->nombre,
        $request->data->tipo,
        $request->data->region,
        $request->data->ataque,
        $request->data->defensa
    ];

    $db->query("INSERT INTO pokemons (nombre, tipo, region, ataque, defensa) VALUES (?, ?, ?, ?, ?)", $datos);
    
    Flight::json(["status" => "Pokémon registrado", "id" => $db->lastInsertId()], 201);
});

// GET /importar - Recupera de DummyJson, mapea y guarda en BD
Flight::route('GET /importar', function() {
    $db = Flight::db();
    
    // 1. Consumir la API externa (usamos file_get_contents para simplificar)
    $url = 'https://dummyjson.com/products/category/fragrances';
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    
    $importados = 0;

    // 2. Proceso de mapeo y guardado
    foreach ($data['products'] as $item) {
        // Mapeo: 'title' pasa a ser 'nombre', 'brand' a 'tipo', etc.
        // Además, modificamos valores: el ataque será el precio multiplicado por 2
        $nombre = "Item: " . $item['title'];
        $tipo = "Equipamiento"; 
        $region = "Tienda Dummy";
        $ataque = (int)$item['price'] * 2; 
        $defensa = (int)$item['stock'];

        // 3. Insertar en nuestra BD usando SimplePdo
        $sql = "INSERT INTO pokemons (nombre, tipo, region, ataque, defensa) VALUES (?, ?, ?, ?, ?)";
        $db->query($sql, [$nombre, $tipo, $region, $ataque, $defensa]);
        $importados++;
    }

    Flight::json([
        "mensaje" => "Mapeo completado",
        "registros_importados" => $importados
    ]);
});

/**
 * Apartado (c): Servicio que calcula el poder de un Pokémon según el clima real.
 * URL: /clima-batalla/@nombre
 */
Flight::route('GET /clima-batalla/@nombre', function($nombre) {
    $db = Flight::db();
    
    // 1. Buscar el Pokémon en nuestra base de datos
    $pokemon = $db->query("SELECT * FROM pokemons WHERE nombre = ?", [$nombre])->fetch();

    if (!$pokemon) {
        Flight::halt(404, json_encode(["error" => "Pokémon no encontrado localmente"]));
    }

    // 2. Consultar el clima de una ubicación (ej: Madrid) usando Open-Meteo
    // Latitud y Longitud de Madrid: 40.41, -3.70
    $weatherUrl = "https://api.open-meteo.com/v1/forecast?latitude=40.41&longitude=-3.70&current_weather=true";
    $weatherResponse = @file_get_contents($weatherUrl);
    
    if ($weatherResponse === FALSE) {
        Flight::halt(500, json_encode(["error" => "No se pudo obtener el clima de terceros"]));
    }

    $weatherData = json_decode($weatherResponse, true);
    $temp = $weatherData['current_weather']['temperature'];
    $isRaining = $weatherData['current_weather']['weathercode'] > 50; // Códigos > 50 suelen ser lluvia

    // 3. Lógica de negocio (Servicio Híbrido)
    $mensaje = "Estado normal";
    $ataqueFinal = $pokemon['ataque'];

    // Si hace calor (>25°C), los de fuego son más fuertes. Si llueve, los de agua.
    if ($temp > 25 && $pokemon['tipo'] == 'fuego') {
        $ataqueFinal += 20;
        $mensaje = "¡Potenciado por el calor extremo!";
    } elseif ($isRaining && $pokemon['tipo'] == 'agua') {
        $ataqueFinal += 20;
        $mensaje = "¡Potenciado por la lluvia!";
    }

    Flight::json([
        "pokemon" => $pokemon['nombre'],
        "tipo" => $pokemon['tipo'],
        "clima_actual" => [
            "ubicacion" => "Madrid",
            "temperatura" => $temp . "°C",
            "lluvia" => $isRaining ? "Sí" : "No"
        ],
        "resultado_batalla" => [
            "ataque_base" => $pokemon['ataque'],
            "ataque_final" => $ataqueFinal,
            "estado" => $mensaje
        ]
    ]);
});

/*
Flight::route('GET /estadisticas', function() {
    $db = Flight::db();
    $data = $db->query("SELECT ataque, defensa FROM pokemons")->fetchAll();

    if (count($data) < 2) {
        Flight::json(["error" => "Necesitas al menos 2 pokemons para calcular la correlacion"]);
        return;
    }

    $ataques = array_column($data, 'ataque');
    $defensas = array_column($data, 'defensa');

    // LLAMADA DIRECTA (Sin depender del 'use' de arriba)
    $correlacion = \Phpml\Statistics\Correlation::pearson($ataques, $defensas);

    Flight::json([
        "conteo" => count($data),
        "correlacion" => round($correlacion, 4)
    ]);
});
*/

// Apartado (d): Cálculo de datos estadísticos y correlacionales (Lógica manual de Pearson)
Flight::route('GET /estadisticas', function() {
    $db = Flight::db();
    
    // 1. Obtenemos los datos de la BD
    $data = $db->query("SELECT ataque, defensa FROM pokemons")->fetchAll();

    if (count($data) < 2) {
        Flight::json(["error" => "Se necesitan al menos 2 registros para el análisis"], 400);
        return;
    }

    // 2. Extraemos las columnas para el cálculo
    $x = array_column($data, 'ataque');
    $y = array_column($data, 'defensa');

    // 3. Algoritmo de Coeficiente de Correlación de Pearson
    // Implementamos la lógica que normalmente haría PHP-ML
    $pearson = function($x, $y) {
        $n = count($x);
        if ($n === 0) return 0;

        $avgX = array_sum($x) / $n;
        $avgY = array_sum($y) / $n;

        $num = 0;
        $den1 = 0;
        $den2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $diffX = $x[$i] - $avgX;
            $diffY = $y[$i] - $avgY;
            $num += $diffX * $diffY;
            $den1 += pow($diffX, 2);
            $den2 += pow($diffY, 2);
        }

        $divisor = sqrt($den1 * $den2);
        return ($divisor == 0) ? 0 : $num / $divisor;
    };

    $resultadoCorrelacion = $pearson($x, $y);

    // 4. Respuesta con Inteligencia de Negocios
    Flight::json([
        "analisis_predictivo" => [
            "total_muestras" => count($data),
            "coeficiente_pearson" => round($resultadoCorrelacion, 4),
            "interpretacion" => ($resultadoCorrelacion > 0) 
                ? "Existe una tendencia: a mayor ataque, mayor defensa." 
                : "No existe una relación lineal clara entre ataque y defensa.",
            "metodologia" => "Análisis de correlación aplicado sobre repositorio local"
        ],
        "estadisticas_basicas" => [
            "ataque_promedio" => round(array_sum($x) / count($x), 2),
            "defensa_promedio" => round(array_sum($y) / count($y), 2)
        ]
    ]);
});
Flight::start();