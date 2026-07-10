<?php
require_once "php/conexion.php";

/* ===============================
   RECIBIR FILTROS
   =============================== */

$indicador = isset($_GET["indicador"]) ? $_GET["indicador"] : "nacimientos";
$anio = isset($_GET["anio"]) ? $_GET["anio"] : "";
$alcaldia = isset($_GET["alcaldia"]) ? $_GET["alcaldia"] : "";

/* ===============================
   CONDICIONES SQL PARA FILTROS
   =============================== */

$where = " WHERE 1=1 ";

if ($anio !== "") {
    $anio_sql = intval($anio);
    $where .= " AND anio = $anio_sql ";
}

if ($alcaldia !== "") {
    $alcaldia_sql = $conn->real_escape_string($alcaldia);
    $where .= " AND alcaldia = '$alcaldia_sql' ";
}

/* ===============================
   TARJETAS RESUMEN GENERALES
   Datos estáticos del proyecto
   =============================== */

$sqlResumenGeneral = "
    SELECT 
        COUNT(*) AS registros_muestra,
        COUNT(DISTINCT clues) AS total_clues,
        COUNT(DISTINCT CASE 
            WHEN alcaldia <> 'SIN INFORMACION' THEN alcaldia 
        END) AS total_alcaldias,
        ROUND(AVG(edad_madre), 0) AS edad_promedio
    FROM nacimientos
";

$resumenGeneral = $conn->query($sqlResumenGeneral);

if (!$resumenGeneral) {
    die("Error en resumen general SQL: " . $conn->error);
}

$datosResumen = $resumenGeneral->fetch_assoc();

$registrosMuestra = $datosResumen["registros_muestra"] ?? 2000;
$totalClues = $datosResumen["total_clues"] ?? 0;
$totalAlcaldias = $datosResumen["total_alcaldias"] ?? 16;
$edadPromedio = $datosResumen["edad_promedio"] ?? 0;

/* ===============================
   CONSULTA PRINCIPAL
   =============================== */

$tituloTabla = "";
$sql = "";

switch ($indicador) {
    case "sexo":
        $tituloTabla = "Nacimientos por sexo";
        $sql = "
            SELECT 
                sexo,
                SUM(nacimientos) AS total_nacimientos
            FROM nacimientos
            $where
            GROUP BY sexo
            ORDER BY total_nacimientos DESC
        ";
        break;

    case "edad":
        $tituloTabla = "Edad promedio de la madre";
        $sql = "
            SELECT 
                anio,
                alcaldia,
                ROUND(AVG(edad_madre), 0) AS edad_promedio
            FROM nacimientos
            $where
            AND edad_madre IS NOT NULL
            GROUP BY anio, alcaldia
            ORDER BY edad_promedio DESC
        ";
        break;

    case "adolescentes":
        $tituloTabla = "Madres adolescentes";
        $sql = "
            SELECT 
                anio,
                alcaldia,
                SUM(nacimientos) AS madres_adolescentes
            FROM nacimientos
            $where
            AND edad_madre BETWEEN 10 AND 19
            GROUP BY anio, alcaldia
            ORDER BY madres_adolescentes DESC
        ";
        break;

    case "mayores35":
        $tituloTabla = "Madres de 35 años o más";
        $sql = "
            SELECT 
                anio,
                alcaldia,
                SUM(nacimientos) AS madres_35_mas
            FROM nacimientos
            $where
            AND edad_madre >= 35
            GROUP BY anio, alcaldia
            ORDER BY madres_35_mas DESC
        ";
        break;

    case "hospitales":
        $tituloTabla = "Top unidades médicas / CLUES";
        $sql = "
            SELECT 
                clues,
                COALESCE(nombre_unidad, 'Sin nombre registrado') AS nombre_unidad,
                alcaldia,
                latitud,
                longitud,
                SUM(nacimientos) AS total_nacimientos
            FROM vista_nacimientos_clues_geo
            $where
            AND clues <> 'SIN INFORMACION'
            AND nombre_unidad IS NOT NULL
            AND latitud IS NOT NULL
            AND longitud IS NOT NULL
            GROUP BY 
                clues,
                nombre_unidad,
                alcaldia,
                latitud,
                longitud
            ORDER BY total_nacimientos DESC
            LIMIT 30
        ";
        break;

    case "nacimientos":
    default:
        $tituloTabla = "Nacimientos por año y alcaldía";
        $sql = "
            SELECT 
                anio,
                alcaldia,
                SUM(nacimientos) AS total_nacimientos
            FROM nacimientos
            $where
            GROUP BY anio, alcaldia
            ORDER BY total_nacimientos DESC
        ";
        break;
}

$resultado = $conn->query($sql);

if (!$resultado) {
    die("Error en consulta SQL: " . $conn->error);
}

/* ===============================
   GUARDAR FILAS EN ARREGLO
   =============================== */

$filas = [];

while ($fila = $resultado->fetch_assoc()) {
    $filas[] = $fila;
}

/* ===============================
   FUNCIONES PARA GRÁFICA
   =============================== */

function obtenerValorNumerico($fila) {
    $metricas = [
        "total_nacimientos",
        "madres_adolescentes",
        "madres_35_mas",
        "edad_promedio"
    ];

    foreach ($metricas as $metrica) {
        if (isset($fila[$metrica]) && is_numeric($fila[$metrica])) {
            return floatval($fila[$metrica]);
        }
    }

    foreach ($fila as $clave => $valor) {
        if (
            is_numeric($valor) &&
            $clave !== "anio" &&
            $clave !== "latitud" &&
            $clave !== "longitud" &&
            $clave !== "edad_madre"
        ) {
            return floatval($valor);
        }
    }

    return 0;
}

function obtenerEtiquetaGrafica($fila, $indicador) {
    if ($indicador == "hospitales" && isset($fila["nombre_unidad"])) {
        return $fila["nombre_unidad"];
    }

    if ($indicador == "sexo" && isset($fila["sexo"])) {
        return $fila["sexo"];
    }

    if (isset($fila["anio"]) && isset($fila["alcaldia"])) {
        return $fila["anio"] . " | " . $fila["alcaldia"];
    }

    if (isset($fila["alcaldia"])) {
        return $fila["alcaldia"];
    }

    return "Dato";
}

/* ===============================
   TOP 5 PARA GRÁFICA
   =============================== */

$filasGrafica = $filas;

$filasGrafica = array_filter($filasGrafica, function($fila) {
    return obtenerValorNumerico($fila) > 0;
});

usort($filasGrafica, function($a, $b) {
    return obtenerValorNumerico($b) <=> obtenerValorNumerico($a);
});

$top5 = array_slice($filasGrafica, 0, 5);

$valorMaximo = 0;

foreach ($top5 as $fila) {
    $valor = obtenerValorNumerico($fila);

    if ($valor > $valorMaximo) {
        $valorMaximo = $valor;
    }
}

if ($valorMaximo == 0) {
    $valorMaximo = 1;
}

/* ===============================
   COORDENADAS APROXIMADAS DE ALCALDÍAS
   Para mapa cuando NO sea hospitales
   =============================== */

$coordsAlcaldias = [
    "AZCAPOTZALCO" => ["lat" => 19.4847, "lng" => -99.1859],
    "GUSTAVO A. MADERO" => ["lat" => 19.4870, "lng" => -99.1128],
    "MIGUEL HIDALGO" => ["lat" => 19.4250, "lng" => -99.2000],
    "CUAUHTEMOC" => ["lat" => 19.4332, "lng" => -99.1451],
    "VENUSTIANO CARRANZA" => ["lat" => 19.4300, "lng" => -99.0950],
    "IZTACALCO" => ["lat" => 19.3953, "lng" => -99.0977],
    "BENITO JUAREZ" => ["lat" => 19.3800, "lng" => -99.1610],
    "ALVARO OBREGON" => ["lat" => 19.3605, "lng" => -99.2267],
    "COYOACAN" => ["lat" => 19.3467, "lng" => -99.1617],
    "IZTAPALAPA" => ["lat" => 19.3574, "lng" => -99.0931],
    "LA MAGDALENA CONTRERAS" => ["lat" => 19.3106, "lng" => -99.2420],
    "CUAJIMALPA DE MORELOS" => ["lat" => 19.3571, "lng" => -99.2990],
    "TLALPAN" => ["lat" => 19.2880, "lng" => -99.1670],
    "XOCHIMILCO" => ["lat" => 19.2622, "lng" => -99.1036],
    "TLAHUAC" => ["lat" => 19.2869, "lng" => -99.0051],
    "MILPA ALTA" => ["lat" => 19.1910, "lng" => -99.0230]
];

/* ===============================
   MARCADORES REALES DE CLUES
   Solo para indicador hospitales
   =============================== */

$marcadoresClues = [];

if ($indicador == "hospitales") {
    foreach ($top5 as $fila) {
        if (
            isset($fila["latitud"]) &&
            isset($fila["longitud"]) &&
            $fila["latitud"] !== null &&
            $fila["longitud"] !== null
        ) {
            $marcadoresClues[] = [
                "clues" => $fila["clues"],
                "nombre_unidad" => $fila["nombre_unidad"],
                "alcaldia" => $fila["alcaldia"],
                "latitud" => floatval($fila["latitud"]),
                "longitud" => floatval($fila["longitud"]),
                "total_nacimientos" => intval($fila["total_nacimientos"])
            ];
        }
    }
}

/* ===============================
   COLUMNAS A OCULTAR EN TABLA
   =============================== */

$columnasOcultas = [];

if ($indicador == "hospitales") {
    $columnasOcultas = ["clues", "latitud", "longitud"];
}

$nombresColumnas = [
    "clues" => "CLUES",
    "nombre_unidad" => "Unidad médica",
    "alcaldia" => "Alcaldía",
    "latitud" => "Latitud",
    "longitud" => "Longitud",
    "total_nacimientos" => "Total de nacimientos",
    "anio" => "Año",
    "sexo" => "Sexo",
    "edad_promedio" => "Edad promedio",
    "madres_adolescentes" => "Madres adolescentes",
    "madres_35_mas" => "Madres de 35 años o más"
];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta | NatalData CDMX</title>
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>

<body>

<header>
    <nav class="navbar">
        <div class="logo">NatalData CDMX</div>

        <ul class="nav-links">
            <li><a href="index.html">Inicio</a></li>
            <li><a href="consulta.php">Consulta</a></li>
            <li><a href="nosotros.html">Nosotros</a></li>
        </ul>
    </nav>
</header>

<main class="container-consulta">

    <section class="resumen-dashboard">

        <div class="resumen-card">
            <span class="resumen-icono">🧾</span>
            <h3><?php echo number_format($registrosMuestra); ?></h3>
            <p>Registros de muestra ponderada</p>
        </div>

        <div class="resumen-card">
            <span class="resumen-icono">🏥</span>
            <h3><?php echo number_format($totalClues); ?></h3>
            <p>Unidades médicas / CLUES</p>
        </div>

        <div class="resumen-card">
            <span class="resumen-icono">📍</span>
            <h3><?php echo number_format($totalAlcaldias); ?></h3>
            <p>Alcaldías de la CDMX</p>
        </div>

        <div class="resumen-card">
            <span class="resumen-icono">📊</span>
            <h3><?php echo number_format($edadPromedio, 0); ?></h3>
            <p>Edad promedio de la madre</p>
        </div>

    </section>

    <section class="card filtros">
        <h2>Consulta de datos</h2>

        <form method="GET" action="consulta.php" class="formulario-consulta">

            <div class="form-group">
                <label for="indicador">Indicador</label>

                <select name="indicador" id="indicador">
                    <option value="nacimientos" <?php if($indicador=="nacimientos") echo "selected"; ?>>
                        Nacimientos por año y alcaldía
                    </option>

                    <option value="sexo" <?php if($indicador=="sexo") echo "selected"; ?>>
                        Nacimientos por sexo
                    </option>

                    <option value="edad" <?php if($indicador=="edad") echo "selected"; ?>>
                        Edad promedio de la madre
                    </option>

                    <option value="adolescentes" <?php if($indicador=="adolescentes") echo "selected"; ?>>
                        Madres adolescentes
                    </option>

                    <option value="mayores35" <?php if($indicador=="mayores35") echo "selected"; ?>>
                        Madres de 35 años o más
                    </option>

                    <option value="hospitales" <?php if($indicador=="hospitales") echo "selected"; ?>>
                        Top unidades médicas
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="anio">Año</label>

                <select name="anio" id="anio">
                    <option value="">Todos</option>

                    <?php
                    $consultaAnios = $conn->query("SELECT DISTINCT anio FROM nacimientos ORDER BY anio");

                    while ($filaAnio = $consultaAnios->fetch_assoc()) {
                        $selected = ($anio == $filaAnio["anio"]) ? "selected" : "";
                        echo "<option value='" . $filaAnio["anio"] . "' $selected>" . $filaAnio["anio"] . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label for="alcaldia">Alcaldía</label>

                <select name="alcaldia" id="alcaldia">
                    <option value="">Todas</option>

                    <?php
                    $consultaAlcaldias = $conn->query("
                        SELECT DISTINCT alcaldia 
                        FROM nacimientos 
                        WHERE alcaldia <> 'SIN INFORMACION'
                        ORDER BY alcaldia
                    ");

                    while ($filaAlcaldia = $consultaAlcaldias->fetch_assoc()) {
                        $selected = ($alcaldia == $filaAlcaldia["alcaldia"]) ? "selected" : "";

                        echo "<option value='" . htmlspecialchars($filaAlcaldia["alcaldia"]) . "' $selected>" 
                            . htmlspecialchars($filaAlcaldia["alcaldia"]) . 
                        "</option>";
                    }
                    ?>
                </select>
            </div>

            <button type="submit" class="btn-buscar">Buscar</button>

        </form>
    </section>

    <section class="dashboard-grid">

        <div class="card grafica-card">
            <h2>Top 5 resultados</h2>
            <p>Principales valores de acuerdo con la consulta seleccionada.</p>

            <div class="grafica-barras">

                <?php if (count($top5) > 0): ?>

                    <?php foreach ($top5 as $fila): ?>

                        <?php
                        $etiqueta = obtenerEtiquetaGrafica($fila, $indicador);
                        $valor = obtenerValorNumerico($fila);
                        $porcentajeBarra = ($valor / $valorMaximo) * 100;
                        ?>

                        <div class="barra-item">
                            <div class="barra-info">
                                <span title="<?php echo htmlspecialchars($etiqueta); ?>">
                                    <?php echo htmlspecialchars($etiqueta); ?>
                                </span>

                                <strong>
                                    <?php echo number_format($valor, 0); ?>
                                </strong>
                            </div>

                            <div class="barra-fondo">
                                <div class="barra-relleno" style="width: <?php echo $porcentajeBarra; ?>%;"></div>
                            </div>
                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>No hay datos para mostrar con los filtros seleccionados.</p>

                <?php endif; ?>

            </div>
        </div>

        <div class="card mapa-card">
            <h2>Ubicación</h2>

            <?php if ($indicador == "hospitales"): ?>
                <p>Ubicación real de las unidades médicas del Top 5.</p>
                <h3>Unidades médicas georreferenciadas</h3>
            <?php elseif ($alcaldia !== ""): ?>
                <p>Consulta filtrada para la alcaldía:</p>
                <h3><?php echo htmlspecialchars($alcaldia); ?></h3>
            <?php else: ?>
                <p>Consulta general para las alcaldías incluidas en la muestra.</p>
                <h3>Ciudad de México</h3>
            <?php endif; ?>

            <div id="mapa-interactivo"></div>

            <p class="nota-mapa">
                Mapa interactivo con ubicación aproximada por alcaldía. Para unidades médicas se muestran coordenadas reales del catálogo CLUES.
            </p>
        </div>

    </section>

    <section class="card tabla-completa">
        <h2><?php echo $tituloTabla; ?></h2>

        <div class="tabla-scroll">

            <table>
                <thead>
                    <tr>
                        <?php if (count($filas) > 0): ?>
                            <?php foreach (array_keys($filas[0]) as $columna): ?>
                                <?php if (!in_array($columna, $columnasOcultas)): ?>
                                    <th>
                                        <?php 
                                            echo htmlspecialchars($nombresColumnas[$columna] ?? strtoupper($columna)); 
                                        ?>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <th>Sin resultados</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($filas) > 0): ?>

                        <?php foreach ($filas as $fila): ?>
                            <tr>
                                <?php foreach ($fila as $columna => $valor): ?>
                                    <?php if (!in_array($columna, $columnasOcultas)): ?>
                                        <td><?php echo htmlspecialchars($valor); ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td>No se encontraron resultados para la consulta seleccionada.</td>
                        </tr>

                    <?php endif; ?>
                </tbody>
            </table>

        </div>
    </section>

</main>

<footer>
    <p>NatalData CDMX | Proyecto web</p>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    const alcaldiaSeleccionada = <?php echo json_encode($alcaldia, JSON_UNESCAPED_UNICODE); ?>;
    const indicadorSeleccionado = <?php echo json_encode($indicador, JSON_UNESCAPED_UNICODE); ?>;
    const coordsAlcaldias = <?php echo json_encode($coordsAlcaldias, JSON_UNESCAPED_UNICODE); ?>;
    const marcadoresClues = <?php echo json_encode($marcadoresClues, JSON_UNESCAPED_UNICODE); ?>;

    const centroCDMX = [19.4326, -99.1332];

    const mapa = L.map("mapa-interactivo").setView(centroCDMX, 10.5);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 18,
        attribution: "&copy; OpenStreetMap contributors"
    }).addTo(mapa);

    const iconoRosa = L.divIcon({
        html: "📍",
        className: "marcador-rosa",
        iconSize: [34, 34],
        iconAnchor: [17, 34],
        popupAnchor: [0, -34]
    });

    if (indicadorSeleccionado === "hospitales" && marcadoresClues.length > 0) {

        const grupoMarcadores = [];

        marcadoresClues.forEach(function(unidad) {
            const marcador = L.marker([unidad.latitud, unidad.longitud], { icon: iconoRosa })
                .addTo(mapa)
                .bindPopup(
                    "<strong>" + unidad.nombre_unidad + "</strong><br>" +
                    "CLUES: " + unidad.clues + "<br>" +
                    "Alcaldía: " + unidad.alcaldia + "<br>" +
                    "Nacimientos: " + unidad.total_nacimientos
                );

            grupoMarcadores.push(marcador);
        });

        const grupo = L.featureGroup(grupoMarcadores);
        mapa.fitBounds(grupo.getBounds().pad(0.25));

    } else if (alcaldiaSeleccionada && coordsAlcaldias[alcaldiaSeleccionada]) {

        const punto = coordsAlcaldias[alcaldiaSeleccionada];

        L.marker([punto.lat, punto.lng], { icon: iconoRosa })
            .addTo(mapa)
            .bindPopup("<strong>" + alcaldiaSeleccionada + "</strong><br>Ubicación aproximada")
            .openPopup();

        mapa.setView([punto.lat, punto.lng], 12);

    } else {

        Object.keys(coordsAlcaldias).forEach(function(nombre) {
            const punto = coordsAlcaldias[nombre];

            L.marker([punto.lat, punto.lng], { icon: iconoRosa })
                .addTo(mapa)
                .bindPopup("<strong>" + nombre + "</strong>");
        });

    }

    setTimeout(function() {
        mapa.invalidateSize();
    }, 300);
</script>

</body>
</html>