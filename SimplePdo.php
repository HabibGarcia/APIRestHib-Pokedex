<?php

class SimplePdo {
    protected $pdo;
    /**
     * Constructor: Establece la conexión con la base de datos.
     */
    public function __construct($dsn, $user, $pass) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lanza errores como excepciones
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Devuelve los datos como array asociativo
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa consultas preparadas reales
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            // Si hay error en la conexión, detenemos el script
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    /**
     * Ejecuta una consulta SQL con parámetros opcionales.
     * Es el método que usamos en Flight::db()->query(...)
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt; // Retornamos el objeto statement para encadenar ->fetch() o ->fetchAll()
    }

    /**
     * Retorna el último ID insertado (útil para el POST)
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}