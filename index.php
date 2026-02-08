<?php
require 'vendor/autoload.php';
require 'SimplePdo.php';
use Phpml\Math\Statistic\Mean;
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
// --- APARTADO A: Grupo de Endpoints para Pokémon ---
// Agrupamos bajo el prefijo '/pokemons'
Flight::group('/pokemons', function(\flight\net\Router $router) {

    // 1. GET /pokemons - Obtener todos
    $router->get('', function() {
        $db = Flight::db();
        // Usamos query() de tu SimplePdo
        $lista = $db->query("SELECT * FROM pokemons")->fetchAll();
        Flight::json($lista);
    });

    // 2. GET /pokemons/@id - Obtener uno por ID
    $router->get('/@id', function($id) {
        $db = Flight::db();
        $pokemon = $db->query("SELECT * FROM pokemons WHERE id = ?", [$id])->fetch();
        
        if ($pokemon) {
            Flight::json($pokemon);
        } else {
            // Error 404 según pide la práctica
            Flight::halt(404, json_encode(["error" => "Pokémon no encontrado"]));
        }
    });

    // 3. POST /pokemons - Insertar nuevo
    $router->post('', function() {
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
        
        Flight::json([
            "status" => "Pokémon registrado", 
            "id" => $db->lastInsertId()
        ], 201);
    });
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

// --- Apartado D: Análisis de Medias por Tipo (PHP-ML) ---

Flight::route('GET /estadisticas', function() {
    $db = Flight::db();
    
    // 1. Recuperamos los datos de tus Pokémon
    // Nota: Usamos query()->fetchAll() que es como lo tienes en tu SimplePdo
    $pokemons = $db->query("SELECT tipo, ataque, defensa FROM pokemons")->fetchAll();
    
    if (empty($pokemons)) {
        Flight::halt(404, json_encode(["error" => "No hay pokémons para analizar"]));
    }

    // 2. Agrupamos las estadísticas por tipo de Pokémon
    $datosPorTipo = [];
    foreach ($pokemons as $p) {
        $tipo = $p['tipo'];
        $datosPorTipo[$tipo]['ataques'][] = (int)$p['ataque'];
        $datosPorTipo[$tipo]['defensas'][] = (int)$p['defensa'];
    }

    $analisis = [];

    // 3. Calculamos las medias usando PHP-ML para cada tipo
    foreach ($datosPorTipo as $tipo => $valores) {
        // Usamos Mean::arithmetic() como en el código de tu compañera
        $analisis[$tipo] = [
            "cantidad" => count($valores['ataques']),
            "media_ataque" => round(Mean::arithmetic($valores['ataques']), 2),
            "media_defensa" => round(Mean::arithmetic($valores['defensas']), 2)
        ];
    }

    // 4. Respuesta JSON
    Flight::json([
        "informe" => "Análisis estadístico de fuerza por tipo de Pokémon",
        "resultados" => $analisis,
        "libreria_utilizada" => "PHP-ML (Machine Learning Library)"
    ]);
});
Flight::start();