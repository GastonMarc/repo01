-----------------------------------------------------------------------------------------------------
plugin.php
-----------------------------------------------------------------------------------------------------

<?php

/*
 * RackTables Plugin: Historial
 * Versi車n 5.12 - FINAL: TAG AJAX ARREGLADO, MOTD BASE64, TAB ROJA JS, MODAL EXACTO, PDF
 */

define('HISTORIAL_UPLOAD_DIR', sys_get_temp_dir() . '/racktables_historial');
define('HISTORIAL_MAX_FILE_SIZE', 10485760);
define('HISTORIAL_MOTD_PATH', '/var/www/html/RackTables-0.21.4/plugins/historial/motd.png');

date_default_timezone_set('America/Argentina/Buenos_Aires');

function plugin_historial_info()
{
    return array(
        'name' => 'historial',
        'longname' => 'Historial de Eventos',
        'version' => 'En la 69.G',
        'home_url' => 'OJ, la banda de FER, ITA-Phanel'
    );
}

function plugin_historial_install() {}
function plugin_historial_uninstall() {}

function plugin_historial_init()
{
    global $tabhandler, $tab;
    $tab['object']['historial'] = 'Historial';
    $tabhandler['object']['historial'] = 'historial_renderContent';
}

function historial_renderContent()
{
    global $object_id, $remote_username;
    $object_id = isset($object_id) ? $object_id : genericAssertion('object_id', 'natural');

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_REQUEST['op'])) {
            switch($_REQUEST['op']) {
                case 'historial_add':
                    historial_addFila($object_id, $remote_username);
                    break;
                case 'historial_delete':
                    historial_deleteFila($object_id, $remote_username);
                    break;
                case 'historial_edit':
                    historial_editFila($object_id, $remote_username);
                    break;
                case 'historial_download':
                    historial_downloadArchivo($object_id, $remote_username);
                    break;
                case 'historial_view':
                    historial_viewArchivo($object_id, $remote_username);
                    break;
                case 'historial_bulk_upload':
                    historial_procesarCargaMasiva($object_id, $remote_username);
                    break;
                case 'historial_export_csv':
                    historial_exportarInforme($object_id, $remote_username);
                    break;
                case 'historial_download_template':
                    historial_descargarPlantilla();
                    break;
                case 'historial_get_rack_objects':
                    historial_ajaxGetRackObjects();
                    break;
                case 'historial_get_tag_objects':
                    historial_ajaxGetTagObjects();
                    break;
            }
        }
    }

    historial_renderInterfaz($object_id, $remote_username);
}

// =====================================================
// FUNCIONES AUXILIARES
// =====================================================

function historial_getNombreObjeto($object_id)
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("SELECT name FROM Object WHERE id = :object_id");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['name'] : 'Desconocido';
    } catch (PDOException $e) {
        return 'Error';
    }
}

function historial_getProximoEvento($object_id)
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("SELECT MAX(evento) as max_evento FROM Historial WHERE object_id = :object_id");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $max_evento = $result['max_evento'];
        return ($max_evento === null) ? 1001 : $max_evento + 1;
    } catch (PDOException $e) {
        return 1001;
    }
}

function historial_getUsuarios()
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("SELECT nombre FROM Historial_Usuarios WHERE activo = 1 ORDER BY nombre ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return array();
    }
}

function historial_agregarUsuario($usuario)
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("INSERT IGNORE INTO Historial_Usuarios (nombre) VALUES (:nombre)");
        $stmt->execute(array(':nombre' => substr($usuario, 0, 50)));
    } catch (PDOException $e) {
        return false;
    }
    return true;
}

function historial_getUploadDir()
{
    if (!is_dir(HISTORIAL_UPLOAD_DIR)) {
        mkdir(HISTORIAL_UPLOAD_DIR, 0755, true);
    }
    return HISTORIAL_UPLOAD_DIR;
}

function historial_getObjectoCompleto($object_id)
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("SELECT * FROM Object WHERE id = :id");
        $stmt->execute(array(':id' => intval($object_id)));
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return array();
    }
}

function historial_getRacks()
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("SELECT id, name FROM Object WHERE objtype_id = 1560 ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return array();
    }
}

function historial_getObjectosEnRack($rack_id)
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("
            SELECT DISTINCT o.id, o.name, o.objtype_id
            FROM Object o
            INNER JOIN RackSpace rs ON o.id = rs.object_id
            WHERE rs.rack_id = :rack_id
            ORDER BY o.name ASC
        ");
        $stmt->execute(array(':rack_id' => intval($rack_id)));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return array();
    }
}

function historial_getTags()
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("
            SELECT DISTINCT id, tag FROM TagTree
            WHERE id IN (SELECT DISTINCT tag_id FROM TagStorage WHERE entity_realm = 'object')
            ORDER BY tag ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return array();
    }
}

function historial_getObjectosPorTag($tag_id)
{
    global $dbxlink;
    try {
        $stmt = $dbxlink->prepare("
            SELECT DISTINCT o.id, o.name
            FROM Object o
            INNER JOIN TagStorage ts ON o.id = ts.entity_id
            WHERE ts.tag_id = :tag_id AND ts.entity_realm = 'object'
            ORDER BY o.name ASC
        ");
        $stmt->execute(array(':tag_id' => intval($tag_id)));
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ? $result : array();
    } catch (PDOException $e) {
        return array();
    }
}

function historial_getObjectoInfoCompleta($object_id)
{
    global $dbxlink;

    $info = array();

    try {
        $stmt = $dbxlink->prepare("SELECT * FROM Object WHERE id = :id");
        $stmt->execute(array(':id' => intval($object_id)));
        $info['objeto'] = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbxlink->prepare("
            SELECT a.name, av.string_value, av.uint_value, av.float_value, a.type
            FROM AttributeValue av
            JOIN Attribute a ON av.attr_id = a.id
            WHERE av.object_id = :object_id
            ORDER BY a.name ASC
        ");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $info['atributos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $dbxlink->prepare("
            SELECT DISTINCT tt.tag
            FROM TagStorage ts
            JOIN TagTree tt ON ts.tag_id = tt.id
            WHERE ts.entity_realm = 'object' AND ts.entity_id = :object_id
            ORDER BY tt.tag ASC
        ");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $info['tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $dbxlink->prepare("
            SELECT p.name, p.label, poi.oif_name, p.l2address
            FROM Port p
            LEFT JOIN PortOuterInterface poi ON p.type = poi.id
            WHERE p.object_id = :object_id
            ORDER BY p.name ASC
        ");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $info['puertos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $dbxlink->prepare("
            SELECT name, type, ip FROM IPv4Allocation
            WHERE object_id = :object_id
            ORDER BY name ASC
        ");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $info['ips'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // Retornar lo que se pudo obtener
    }

    return $info;
}

function historial_ipDecimalANotacion($ip_decimal)
{
    $ip_decimal = intval($ip_decimal);
    if ($ip_decimal == 0) return '0.0.0.0';

    $octeto1 = ($ip_decimal >> 24) & 0xFF;
    $octeto2 = ($ip_decimal >> 16) & 0xFF;
    $octeto3 = ($ip_decimal >> 8) & 0xFF;
    $octeto4 = $ip_decimal & 0xFF;

    return "$octeto1.$octeto2.$octeto3.$octeto4";
}

function historial_esArchivoVisualizable($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $visualizables = array('txt', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'html', 'htm', 'csv');
    return in_array($ext, $visualizables);
}

function historial_getMOTDBase64()
{
    if (file_exists(HISTORIAL_MOTD_PATH)) {
        $data = file_get_contents(HISTORIAL_MOTD_PATH);
        $base64 = base64_encode($data);
        return "data:image/png;base64," . $base64;
    }
    return null;
}

// =====================================================
// RENDER - INTERFAZ PRINCIPAL
// =====================================================

function historial_renderInterfaz($object_id, $current_user)
{
    echo "<div id='historial-main-container'>";
    historial_renderCSS();
    historial_renderTabRedaJS();
    historial_renderFormNuevaFila($object_id, $current_user);
    echo "<br><br>";
    historial_renderTablaExistente($object_id, $current_user);
    echo "</div>";
}

function historial_renderTabRedaJS()
{
    echo "<script>
    (function() {
        var interval = setInterval(function() {
            var tab_link = document.querySelector('a[href*=\"tabno=historial\"]');
            if (tab_link) {
                tab_link.style.backgroundColor = '#dc3545';
                tab_link.style.color = 'white';
                tab_link.style.fontWeight = 'bold';
                clearInterval(interval);
            }
        }, 100);
        setTimeout(function() { clearInterval(interval); }, 5000);
    })();
    </script>";
}

function historial_renderCSS()
{
    echo "<style>
    #historial-main-container {
        font-family: Arial, sans-serif;
    }

    #historial-main-container .historial-header-row {
        display: flex;
        gap: 20px;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    #historial-main-container .historial-header-image {
        flex: 0 0 auto;
    }

    #historial-main-container .historial-header-image img {
        max-width: 250px;
        height: auto;
        max-height: 120px;
        border-radius: 4px;
        border: 1px solid #ccc;
        display: block;
    }

    #historial-main-container .historial-form-header {
        background-color: #ffffff;
        color: #000000;
        padding: 8px 12px;
        font-weight: bold;
        border-bottom: 1px solid #ccc;
    }

    #historial-main-container .historial-form-row {
        background-color: #ffffff;
        padding: 10px;
        border: 1px solid #ccc;
    }

    #historial-main-container .historial-buttons-row {
        display: flex;
        gap: 5px;
        align-items: center;
        flex-wrap: wrap;
    }

    #historial-main-container .historial-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    #historial-main-container .historial-table th {
        background-color: #007a37;
        color: white;
        font-weight: bold;
        padding: 8px 12px;
        text-align: left;
        border: 1px solid #ccc;
    }

    #historial-main-container .historial-table th.historial-th-acciones {
        background-color: #0066cc;
        text-align: center;
        padding: 4px;
        min-width: 55px;
    }

    #historial-main-container .historial-table th.historial-th-adjuntos {
        width: 25ch;
    }

    #historial-main-container .historial-table td {
        padding: 8px 12px;
        border: 1px solid #ccc;
    }

    #historial-main-container .historial-table td.historial-td-fecha {
        white-space: nowrap;
        width: 135px;
    }

    #historial-main-container .historial-table td.historial-td-tipo {
        width: 100px;
        word-wrap: break-word;
    }

    #historial-main-container .historial-table td.historial-td-descripcion {
        width: 200px;
        word-wrap: break-word;
        white-space: normal;
    }

    #historial-main-container .historial-table td.historial-td-estado {
        width: 80px;
        word-wrap: break-word;
    }

    #historial-main-container .historial-table td.historial-td-observaciones {
        width: 120px;
        word-wrap: break-word;
    }

    #historial-main-container .historial-table td.historial-td-adjuntos {
        width: 25ch;
        overflow: hidden;
    }

    #historial-main-container .historial-table td.historial-td-acciones {
        text-align: center;
        padding: 2px;
        min-width: 55px;
    }

    #historial-main-container .historial-table tr.row_odd {
        background-color: #f9f9f9;
    }

    #historial-main-container .historial-table tr.row_even {
        background-color: #f0f0f0;
    }

    #historial-main-container .historial-table tr:hover {
        background-color: #e8f4f8;
    }

    #historial-main-container .historial-form-table {
        width: 100%;
        border-collapse: collapse;
    }

    #historial-main-container .historial-form-table th {
        background-color: #007a37;
        color: white;
        font-weight: bold;
        padding: 8px 12px;
        text-align: left;
        border: 1px solid #ccc;
    }

    #historial-main-container .historial-form-table td {
        padding: 8px 12px;
        border: 1px solid #ccc;
        background-color: #ffffff;
    }

    #historial-main-container .historial-input-text,
    #historial-main-container .historial-input-select,
    #historial-main-container .historial-input-file {
        padding: 5px;
        border: 1px solid #999;
        border-radius: 3px;
        font-family: Arial, sans-serif;
        box-sizing: border-box;
    }

    #historial-main-container .historial-btn {
        padding: 6px 12px;
        background-color: #0066cc;
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-weight: bold;
        white-space: nowrap;
        display: inline-block;
    }

    #historial-main-container .historial-btn:hover {
        background-color: #0052a3;
    }

    #historial-main-container .historial-btn-masiva {
        background-color: #cc0000;
    }

    #historial-main-container .historial-btn-masiva:hover {
        background-color: #990000;
    }

    #historial-main-container .historial-btn-export {
        background-color: #804040;
        margin-left: 40px;
    }

    #historial-main-container .historial-btn-export:hover {
        background-color: #663333;
    }

    #historial-main-container .historial-btn-edit {
        background-color: #0066cc;
        color: white;
        padding: 2px 6px;
        font-size: 12px;
        margin: 0;
        border: none;
        cursor: pointer;
        border-radius: 2px;
        font-weight: bold;
    }

    #historial-main-container .historial-btn-edit:hover {
        background-color: #0052a3;
    }

    #historial-main-container .historial-btn-del {
        background-color: #cc0000;
        color: white;
        padding: 2px 6px;
        font-size: 12px;
        margin: 0;
        border: none;
        cursor: pointer;
        border-radius: 2px;
        font-weight: bold;
    }

    #historial-main-container .historial-btn-del:hover {
        background-color: #990000;
    }

    #historial-main-container .historial-char-count {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
    }

    #historial-modal-content {
        background-color: #fefefe;
        margin: 2% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 90%;
        max-width: 1200px;
        border-radius: 5px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        max-height: 70vh;
        overflow-y: auto;
    }

    #historial-modal-close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    #historial-modal-close:hover {
        color: #000;
    }

    #historial-modal-content h2 {
        margin-top: 0;
    }

    #historial-modal-content textarea {
        width: 100%;
        min-height: 250px;
        font-family: monospace;
        padding: 10px;
        border: 1px solid #999;
        border-radius: 3px;
        box-sizing: border-box;
        background-color: #c5e8f7;
    }

    #messages-container {
        position: fixed;
        right: 20px;
        top: 100px;
        width: 350px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 2000;
    }

    .message-box {
        padding: 12px;
        margin-bottom: 10px;
        border-radius: 4px;
        border-left: 4px solid;
    }

    .message-success {
        background-color: #d4edda;
        border-color: #28a745;
        color: #155724;
    }

    .message-error {
        background-color: #f8d7da;
        border-color: #dc3545;
        color: #721c24;
    }

    .export-option {
        padding: 15px;
        border: 1px solid #ccc;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    #objects_list, #tags_list, #tag_objects_list {
        display: none;
        margin: 10px 0;
        padding: 10px;
        border: 1px solid #ddd;
        background-color: #f9f9f9;
        max-height: 250px;
        overflow-y: auto;
    }

    #objects_list label, #tags_list label, #tag_objects_list label {
        display: block;
        margin: 5px 0;
    }

    .bulk-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        justify-content: center;
        margin-top: 15px;
    }

    .bulk-buttons button {
        padding: 8px 16px;
        font-weight: bold;
        cursor: pointer;
        border: none;
        border-radius: 3px;
        color: white;
    }

    #file-viewer-modal {
        display: none;
        position: fixed;
        z-index: 1001;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        overflow: auto;
    }

    #file-viewer-content {
        background-color: white;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 90%;
        max-width: 900px;
        border-radius: 5px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.3);
    }

    #file-viewer-close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    #file-viewer-close:hover {
        color: #000;
    }

    #file-viewer-body {
        margin-top: 20px;
        border: 1px solid #ccc;
        padding: 10px;
        border-radius: 3px;
        background-color: #f9f9f9;
        max-height: 600px;
        overflow: auto;
    }

    #file-viewer-body img {
        max-width: 100%;
        height: auto;
    }

    #file-viewer-body pre {
        white-space: pre-wrap;
        word-wrap: break-word;
        font-family: monospace;
    }

    .historial-adjuntos-cell {
        display: flex;
        align-items: center;
        gap: 2px;
        width: 100%;
    }

    .historial-adjuntos-filename {
        font-size: 10px;
        color: #666;
        flex: 1;
        word-break: break-all;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .historial-icon-view {
        cursor: pointer;
        font-size: 12px;
        text-decoration: none;
        flex-shrink: 0;
    }

    .historial-icon-download {
        cursor: pointer;
        font-size: 12px;
        text-decoration: none;
        flex-shrink: 0;
    }

    .historial-actions-cell {
        display: flex;
        gap: 2px;
        justify-content: center;
        padding: 0;
    }

    .bulk-modal-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .bulk-modal-left {
        border-right: 1px solid #ddd;
        padding-right: 20px;
    }

    .bulk-modal-right {
        padding-left: 20px;
    }

    .bulk-modal-left h3,
    .bulk-modal-right h3 {
        margin-top: 0;
        font-size: 14px;
        font-weight: bold;
    }

    .bulk-modal-left ul {
        margin: 10px 0;
        padding-left: 20px;
    }

    .bulk-modal-left li {
        margin: 5px 0;
        font-size: 12px;
        line-height: 1.4;
    }

    .bulk-modal-right pre {
        background-color: #f5f5f5;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 10px;
        margin: 10px 0;
        overflow-x: auto;
    }

    .bulk-modal-title-format {
        font-size: 11px;
        color: #666;
        margin-bottom: 5px;
    }
    </style>";
}

function historial_renderFormNuevaFila($object_id, $current_user)
{
    $nombre_objeto = historial_getNombreObjeto($object_id);
    $proximo_evento = historial_getProximoEvento($object_id);
    $fecha_actual = date('Y-m-d\TH:i');
    $usuarios = historial_getUsuarios();
    $motd_base64 = historial_getMOTDBase64();

    // HEADER CON MOTD
    echo "<div class='historial-header-row'>";

    if ($motd_base64) {
        echo "<div class='historial-header-image'>";
        echo "<img src='" . htmlspecialchars($motd_base64) . "' alt='Motd'>";
        echo "</div>";
    }

    echo "</div>";

    echo "<div class='historial-form-header'>Agregar nuevo evento</div>";

    echo "<div class='historial-form-row'>";

    echo "<form method='POST' action='' enctype='multipart/form-data' style='margin: 0;'>";
    echo "<input type='hidden' name='op' value='historial_add'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";

    echo "<table class='historial-form-table'>";
    echo "<tr>";
    echo "<th>Event.ID</th>";
    echo "<th>Equipo</th>";
    echo "<th>Fecha</th>";
    echo "<th>Usuario</th>";
    echo "<th>Tipo Evento</th>";
    echo "<th>Descripci車n</th>";
    echo "<th>Estado</th>";
    echo "<th>Observaciones</th>";
    echo "<th>Adjuntos</th>";
    echo "<th>Referencia</th>";
    echo "<th></th>";
    echo "</tr>";

    echo "<tr>";
    echo "<td><input type='text' name='evento' class='historial-input-text' value='" . intval($object_id) . "-" . $proximo_evento . "' style='width:100px;'></td>";
    echo "<td><input type='text' name='equipo' class='historial-input-text' value='" . htmlspecialchars($nombre_objeto) . "' style='width:150px;'></td>";
    echo "<td><input type='datetime-local' name='fecha' class='historial-input-text' value='" . $fecha_actual . "' required style='width:180px;'></td>";

    echo "<td>";
    echo "<select name='usuario' class='historial-input-select' style='width:100px;'>";
    echo "<option value=''>-- Seleccionar --</option>";
    foreach ($usuarios as $usuario) {
        $selected = ($usuario === $current_user) ? 'selected' : '';
        echo "<option value='" . htmlspecialchars($usuario) . "' $selected>" . htmlspecialchars($usuario) . "</option>";
    }
    echo "</select>";
    echo "<input type='text' name='usuario_custom' class='historial-input-text' placeholder='O escribir...' style='width:100px; margin-top:3px;' maxlength='50'>";
    echo "</td>";

    echo "<td>";
    echo "<select name='tipo_evento' class='historial-input-select' style='width:140px;'>";
    echo "<option value=''>-- Seleccionar --</option>";
    echo "<option value='Mantenimiento'>Mantenimiento</option>";
    echo "<option value='Cambio'>Cambio</option>";
    echo "<option value='Problema'>Problema</option>";
    echo "<option value='Mudanza'>Mudanza</option>";
    echo "<option value='Documentaci車n'>Documentaci車n</option>";
    echo "<option value='Otro-personalizado'>Otro-personalizado</option>";
    echo "</select>";
    echo "<input type='text' name='tipo_evento_custom' class='historial-input-text' placeholder='O escribir...' style='width:140px; margin-top:3px;' maxlength='50'>";
    echo "<div class='historial-char-count'>( max: 50 caracteres )</div>";
    echo "</td>";

    echo "<td>";
    echo "<input type='text' name='description' class='historial-input-text' maxlength='400' style='width:250px;'>";
    echo "<div class='historial-char-count'>( max: 400 caracteres )</div>";
    echo "</td>";

    echo "<td>";
    echo "<select name='estado' class='historial-input-select' style='width:130px;'>";
    echo "<option value=''>-- Seleccionar --</option>";
    echo "<option value='Iniciado'>Iniciado</option>";
    echo "<option value='En curso'>En curso</option>";
    echo "<option value='Actualizado'>Actualizado</option>";
    echo "<option value='Cerrado'>Cerrado</option>";
    echo "<option value='Cancelado'>Cancelado</option>";
    echo "</select>";
    echo "<input type='text' name='estado_custom' class='historial-input-text' placeholder='O escribir...' style='width:130px; margin-top:3px;' maxlength='100'>";
    echo "<div class='historial-char-count'>( max: 100 caracteres )</div>";
    echo "</td>";

    echo "<td>";
    echo "<input type='text' name='observaciones' class='historial-input-text' maxlength='200' style='width:200px;'>";
    echo "<div class='historial-char-count'>( max: 200 caracteres )</div>";
    echo "</td>";

    echo "<td>";
    echo "<input type='file' name='adjunto' class='historial-input-file' id='file-input' style='display:none;'>";
    echo "<button type='button' class='historial-btn' style='background-color: #a349a4;' onclick=\"document.getElementById('file-input').click()\">Subir</button>";
    echo "<span id='file-name' style='display:block; font-size:11px; margin-top:3px;'></span>";
    echo "</td>";

    echo "<td><input type='text' name='referencia' class='historial-input-text' maxlength='20' style='width:80px;'></td>";

    echo "<td>";
    echo "<div class='historial-buttons-row'>";
    echo "<input type='submit' class='historial-btn' value='Agregar'>";
    echo "<input type='button' class='historial-btn historial-btn-masiva' value='Carga Masiva' onclick='historial_showBulkUpload(" . intval($object_id) . ")'>";
    echo "</div>";
    echo "</td>";

    echo "</tr>";
    echo "</table>";
    echo "</form>";
    echo "</div>";

    echo "<script>
    document.getElementById('file-input').addEventListener('change', function(e) {
        var name = e.target.files[0] ? e.target.files[0].name : '';
        document.getElementById('file-name').textContent = name ? '?? ' + name : '';
    });
    </script>";
}

function historial_renderTablaExistente($object_id, $current_user)
{
    global $dbxlink;

    try {
        $stmt = $dbxlink->prepare("
            SELECT id, evento, equipo, fecha, usuario, tipo_evento, description, estado, observaciones, adjunto, referencia
            FROM Historial
            WHERE object_id = :object_id
            ORDER BY evento DESC
        ");
        $stmt->execute(array(':object_id' => intval($object_id)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "<div class='historial-error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
        return;
    }

    echo "<div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;'>";
    echo "<h3 style='margin: 0;'>Historial de eventos (" . count($rows) . ")</h3>";
    echo "<button class='historial-btn historial-btn-export' onclick='historial_showExport(" . intval($object_id) . ")'>Bajar Informe</button>";
    echo "</div>";

    if (count($rows) == 0) {
        echo "<p><em>Sin eventos registrados</em></p>";
        return;
    }

    echo "<table class='historial-table'>";
    echo "<tr>";
    echo "<th>Event.ID</th>";
    echo "<th>Equipo</th>";
    echo "<th class='historial-th-fecha'>Fecha</th>";
    echo "<th>Usuario</th>";
    echo "<th class='historial-th-tipo'>Tipo</th>";
    echo "<th class='historial-th-descripcion'>Descripci車n</th>";
    echo "<th class='historial-th-estado'>Estado</th>";
    echo "<th class='historial-th-observaciones'>Observaciones</th>";
    echo "<th class='historial-th-adjuntos'>Adjuntos</th>";
    echo "<th>Referencia</th>";
    echo "<th class='historial-th-acciones'>Acciones</th>";
    echo "</tr>";

    $order = 'odd';
    foreach ($rows as $row) {
        echo "<tr class='row_" . $order . "'>";
        echo "<td><strong>" . intval($object_id) . "-" . intval($row['evento']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['equipo']) . "</td>";
        echo "<td class='historial-td-fecha'>" . htmlspecialchars($row['fecha']) . "</td>";
        echo "<td>" . htmlspecialchars($row['usuario']) . "</td>";
        echo "<td class='historial-td-tipo'>" . htmlspecialchars($row['tipo_evento']) . "</td>";
        echo "<td class='historial-td-descripcion'>" . htmlspecialchars($row['description']) . "</td>";
        echo "<td class='historial-td-estado'>" . htmlspecialchars($row['estado']) . "</td>";
        echo "<td class='historial-td-observaciones'>" . htmlspecialchars($row['observaciones']) . "</td>";

        // COLUMNA ADJUNTOS - 25 caracteres exactos
        echo "<td class='historial-td-adjuntos'>";
        if ($row['adjunto']) {
            $file_parts = explode('_', $row['adjunto']);
            $original_name = end($file_parts);

            echo "<div class='historial-adjuntos-cell'>";
            echo "<span class='historial-adjuntos-filename' title='" . htmlspecialchars($original_name) . "'>" . htmlspecialchars($original_name) . "</span>";
            echo "<span class='historial-icon-view' onclick=\"historial_viewFile(" . intval($row['id']) . ", " . intval($object_id) . ")\" title='Ver archivo'>???</span>";
            echo "<span class='historial-icon-download' onclick=\"historial_downloadFile(" . intval($row['id']) . ", " . intval($object_id) . ")\" title='Descargar'>??</span>";
            echo "</div>";
        } else {
            echo "-";
        }
        echo "</td>";

        echo "<td>" . htmlspecialchars($row['referencia']) . "</td>";

        // ACCIONES
        echo "<td class='historial-td-acciones'>";
        echo "<div class='historial-actions-cell'>";
        echo "<button class='historial-btn-edit' onclick=\"historial_showEdit(" . intval($row['id']) . ", " . intval($object_id) . ")\">Edit</button>";
        echo "<button class='historial-btn-del' onclick=\"if(confirm('?Eliminar?')) { historial_delete(" . intval($row['id']) . ", " . intval($object_id) . "); }\">Del</button>";
        echo "</div>";
        echo "</td>";

        echo "</tr>";

        $order = ($order == 'odd') ? 'even' : 'odd';
    }

    echo "</table>";

    // MODALES
    historial_renderModalCargaMasiva($object_id);
    historial_renderModalExportar($object_id);
    historial_renderModalEditar($object_id);

    // CONTENEDOR DE MENSAJES
    echo "<div id='messages-container'></div>";

    // VIEWER MODAL
    echo "<div id='file-viewer-modal'>";
    echo "<div id='file-viewer-content'>";
    echo "<span id='file-viewer-close' onclick='historial_closeFileViewer()'>&times;</span>";
    echo "<h2 id='file-viewer-title'>Ver Archivo</h2>";
    echo "<div id='file-viewer-body'></div>";
    echo "</div>";
    echo "</div>";

    // SCRIPTS
    historial_renderScripts();
}

function historial_renderModalEditar($object_id)
{
    $usuarios = historial_getUsuarios();

    echo "<div id='historial-modal-editar' style='display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4);'>";
    echo "<div id='historial-modal-content'>";
    echo "<span id='historial-modal-close-edit' style='color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;'>&times;</span>";
    echo "<h2>Editar Evento</h2>";

    echo "<form id='historial-edit-form' method='POST' action=''>";
    echo "<input type='hidden' name='op' value='historial_edit'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";
    echo "<input type='hidden' name='id' id='edit-id' value=''>";

    echo "<table class='historial-form-table' style='width:100%;'>";

    echo "<tr><td style='width:150px;'><strong>Event.ID:</strong></td><td><input type='text' name='evento' id='edit-evento' class='historial-input-text' maxlength='20' style='width:200px;'></td></tr>";

    echo "<tr><td><strong>Equipo:</strong></td><td><input type='text' name='equipo' id='edit-equipo' class='historial-input-text' maxlength='255' style='width:300px;'></td></tr>";

    echo "<tr><td><strong>Fecha:</strong></td><td><input type='datetime-local' name='fecha' id='edit-fecha' class='historial-input-text' style='width:200px;'></td></tr>";

    echo "<tr><td><strong>Usuario:</strong></td><td>";
    echo "<select name='usuario' id='edit-usuario' class='historial-input-select' style='width:200px;'>";
    foreach ($usuarios as $usuario) {
        echo "<option value='" . htmlspecialchars($usuario) . "'>" . htmlspecialchars($usuario) . "</option>";
    }
    echo "</select>";
    echo "</td></tr>";

    echo "<tr><td><strong>Tipo Evento:</strong></td><td>";
    echo "<select name='tipo_evento' id='edit-tipo-evento' class='historial-input-select' style='width:200px;'>";
    echo "<option value='Mantenimiento'>Mantenimiento</option>";
    echo "<option value='Cambio'>Cambio</option>";
    echo "<option value='Problema'>Problema</option>";
    echo "<option value='Mudanza'>Mudanza</option>";
    echo "<option value='Documentaci車n'>Documentaci車n</option>";
    echo "<option value='Otro-personalizado'>Otro-personalizado</option>";
    echo "</select>";
    echo "</td></tr>";

    echo "<tr><td><strong>Descripci車n:</strong></td><td><input type='text' name='description' id='edit-description' class='historial-input-text' maxlength='400' style='width:400px;'></td></tr>";

    echo "<tr><td><strong>Estado:</strong></td><td>";
    echo "<select name='estado' id='edit-estado' class='historial-input-select' style='width:200px;'>";
    echo "<option value='Iniciado'>Iniciado</option>";
    echo "<option value='En curso'>En curso</option>";
    echo "<option value='Actualizado'>Actualizado</option>";
    echo "<option value='Cerrado'>Cerrado</option>";
    echo "<option value='Cancelado'>Cancelado</option>";
    echo "</select>";
    echo "</td></tr>";

    echo "<tr><td><strong>Observaciones:</strong></td><td><input type='text' name='observaciones' id='edit-observaciones' class='historial-input-text' maxlength='200' style='width:400px;'></td></tr>";

    echo "<tr><td><strong>Referencia:</strong></td><td><input type='text' name='referencia' id='edit-referencia' class='historial-input-text' maxlength='20' style='width:100px;'></td></tr>";

    echo "<tr><td colspan='2'>";
    echo "<input type='submit' class='historial-btn' value='Guardar cambios'>";
    echo "<input type='button' class='historial-btn' value='Cancelar' onclick='historial_closeEditModal()' style='background-color: #999; margin-left: 10px;'>";
    echo "</td></tr>";

    echo "</table>";
    echo "</form>";
    echo "</div>";
    echo "</div>";
}

function historial_renderModalCargaMasiva($object_id)
{
    echo "<div id='historial-modal-masiva' style='display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4);'>";
    echo "<div id='historial-modal-content' style='max-height: 80vh; overflow-y: auto;'>";
    echo "<span id='historial-modal-close-masiva' style='color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;'>&times;</span>";
    echo "<h2>Carga Masiva de Eventos</h2>";

    // LAYOUT 2 COLUMNAS
    echo "<div class='bulk-modal-layout'>";

    // LADO IZQUIERDO - INSTRUCCIONES
    echo "<div class='bulk-modal-left'>";
    echo "<h3>Instrucciones</h3>";
    echo "<p><strong>Opci車n 1 - Carga manual</strong></p>";
    echo "<ul>";
    echo "<li>En el BLOQUE DE CARGA, copiar y pegar un rengl車n por cada evento</li>";
    echo "<li>Pulsar el bot車n ROJO \"Procesar Carga\"</li>";
    echo "<li>Si no hubo errores de sintaxis, se generan uno o varios registros nuevos</li>";
    echo "</ul>";

    echo "<p><strong>Opci車n 2 - Descargar plantilla</strong></p>";
    echo "<ul>";
    echo "<li>Pulsar el bot車n \"Descargar Plantilla\"</li>";
    echo "<li>Seguir las instrucciones en el archivo</li>";
    echo "<li>Pulsar \"Subir archivo carga-masiva.txt\"</li>";
    echo "<li>El contenido quedar芍 en el BLOQUE DE CARGA</li>";
    echo "<li>Pulsar el bot車n ROJO \"Procesar Carga\"</li>";
    echo "</ul>";
    echo "</div>";


    // LADO DERECHO
    echo "<div class='bulk-modal-right'>";
    echo "<h3>Formato y restricciones</h3>";
    echo "<p>Son 9 campos, separados por \";\"</p>";
    echo "<pre>;Equipo;Fecha;Usuario;Tipo;Descripci車n;Estado;Observaciones;Adjuntos;Referencia;

Comenzar con \";\"

- Equipo:         S1F1R01U41
- Fecha:          DIA/MES/A?O HORA:MINUTO
- Usuario:        dominio_user
- Tipo:           Mantenimiento/Cambio/Problema/etc
- Descripci車n:    max 400 caracteres
- Estado:         Iniciado/En curso/etc
- Observaciones:  max 200 caracteres
- Adjuntos:       Dejar vac赤o ;;
- Referencia:     max 20 caracteres</pre>";

    echo "<h3>Ejemplo</h3>";
    echo "<pre>";
    echo ";S1F1R01U41;08/03/2026 09:21;dcifuentes;Mudanza;Avisamos al NOC;En curso;Obs-1;;OT-123678;\n";
    echo ";S1F1R01U41;08/03/2026 10:21;dcifuentes;Mudanza;Apagamos servidor;En curso;Obs-2;;OT-123678;\n";
    echo ";S1F4R01U05;10/02/2026 15:13;ndemary;Mantenimiento;Reemplazo;Iniciado;Notas;;OT-666222;\n";
    echo "</pre>";

    echo "</div>";
    echo "</div>";


    // BLOQUE DE CARGA
    echo "<h3 style='margin-top: 20px;'>BLOQUE DE CARGA</h3>";
    echo "<form method='POST' action='' enctype='multipart/form-data' id='bulk-form'>";
    echo "<input type='hidden' name='op' value='historial_bulk_upload'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";
    echo "<textarea name='bulk_data' required placeholder='Pega aqu赤 los datos separados por ;' style='width:100%; min-height:250px; padding: 10px; border: 1px solid #999; border-radius: 3px; background-color: #c5e8f7; font-family: monospace;'></textarea>";

    echo "<div class='bulk-buttons'>";
    echo "<input type='file' name='bulk_file' id='bulk-file-input' class='historial-input-file' style='display:none;'>";
    echo "<button type='button' style='background-color: #2196F3;' onclick=\"document.getElementById('bulk-file-input').click()\">Subir archivo carga-masiva.txt</button>";
    echo "<input type='submit' style='background-color: #cc0000; color: white; border: none; padding: 8px 16px; border-radius: 3px; cursor: pointer; font-weight: bold;' value='Procesar Carga'>";
    echo "<button type='button' style='background-color: #28a745;' onclick='historial_downloadTemplate()'>Descargar Plantilla</button>";
    echo "<button type='button' style='background-color: #ffa500;' onclick='document.querySelector(\"textarea[name=bulk_data]\").value=\"\"'>Limpiar</button>";
    echo "<button type='button' style='background-color: #6c757d;' onclick='historial_closeBulkModal()'>Cerrar</button>";
    echo "</div>";
    echo "</form>";

    echo "<script>
    document.getElementById('bulk-file-input').addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(event) {
                document.querySelector('textarea[name=bulk_data]').value = event.target.result;
            };
            reader.readAsText(file);
        }
    });
    </script>";

    echo "</div>";
    echo "</div>";
}

function historial_renderModalExportar($object_id)
{
    $racks = historial_getRacks();
    $tags = historial_getTags();
    $nombre_objeto = historial_getNombreObjeto($object_id);

    echo "<div id='historial-modal-export' style='display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); overflow-y: auto;'>";
    echo "<div id='historial-modal-content'>";
    echo "<span id='historial-modal-close-export' style='color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;'>&times;</span>";
    echo "<h2>Bajar Informe</h2>";

    echo "<form method='POST' action='' id='export-form'>";
    echo "<input type='hidden' name='op' value='historial_export_csv'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";

    // OPCI車N 1
    echo "<div class='export-option'>";
    echo "<label><input type='radio' name='export_type' value='single' checked> Opci車n 1: Informe de un objeto individual</label>";
    echo "<p><strong>Nombre del equipo:</strong></p>";
    echo "<input type='text' name='export_object' id='export_object' class='historial-input-text' style='width:100%; padding: 8px;' value='" . htmlspecialchars($nombre_objeto) . "'>";
    echo "</div>";

    // OPCI車N 2
    echo "<div class='export-option'>";
    echo "<label><input type='radio' name='export_type' value='rack'> Opci車n 2: Informes por RACK (genera un informe por cada objeto seleccionado)</label>";
    echo "<p><strong>Selecciona el RACK:</strong></p>";
    echo "<select name='rack_id' id='rack_select' style='width: 100%; padding: 8px; margin-bottom: 10px;' onchange='historial_loadRackObjects()'>";
    echo "<option value=''>-- Selecciona un RACK --</option>";
    foreach ($racks as $rack) {
        echo "<option value='" . intval($rack['id']) . "'>" . htmlspecialchars($rack['name']) . "</option>";
    }
    echo "</select>";
    echo "<div id='objects_list'>";
    echo "<p style='margin-top: 0;'><strong>Objetos del RACK:</strong></p>";
    echo "<div id='rack_objects_list' style='max-height: 200px; overflow-y: auto;'></div>";
    echo "</div>";
    echo "</div>";

    // OPCI車N 3 - INFORMES POR TAG
    echo "<div class='export-option'>";
    echo "<label><input type='radio' name='export_type' value='tags'> Opci車n 3: Informes por TAG (genera un informe por cada objeto seleccionado)</label>";
    echo "<p><strong>Selecciona un TAG:</strong></p>";
    echo "<select name='tag_id' id='tag_select' style='width: 100%; padding: 8px; margin-bottom: 10px;' onchange='historial_loadTagObjects()'>";
    echo "<option value=''>-- Selecciona un TAG --</option>";
    foreach ($tags as $tag) {
        echo "<option value='" . intval($tag['id']) . "'>" . htmlspecialchars($tag['tag']) . "</option>";
    }
    echo "</select>";
    echo "<div id='tags_list'>";
    echo "<p style='margin-top: 0;'><strong>Objetos con el TAG:</strong></p>";
    echo "<div id='tag_objects_list' style='max-height: 200px; overflow-y: auto;'></div>";
    echo "</div>";
    echo "</div>";

    // FORMATO
    echo "<div class='export-option' style='background-color: #e8f4f8;'>";
    echo "<p><strong>Selecciona el formato:</strong></p>";
    echo "<select name='export_format' style='width: 100%; padding: 8px;'>";
    echo "<option value='csv'>CSV</option>";
    echo "<option value='txt'>TXT</option>";
    echo "<option value='pdf'>PDF</option>";
    echo "</select>";
    echo "</div>";

    echo "<div style='display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;'>";
    echo "<input type='submit' class='historial-btn' style='background-color: #0066cc;' value='Generar Informe'>";
    echo "<input type='button' class='historial-btn' value='Cerrar' onclick='historial_closeExportModal()' style='background-color: #999;'>";
    echo "</div>";

    echo "</form>";
    echo "</div>";
    echo "</div>";
}

function historial_renderScripts()
{
    echo "<script>
    function historial_showMessage(text, type) {
        var container = document.getElementById('messages-container');
        var msgBox = document.createElement('div');
        msgBox.className = 'message-box message-' + type;
        msgBox.textContent = text;
        container.appendChild(msgBox);

        setTimeout(function() {
            msgBox.remove();
        }, 5000);
    }

    var modalMasiva = document.getElementById('historial-modal-masiva');
    var spanMasiva = document.getElementById('historial-modal-close-masiva');
    spanMasiva.onclick = function() { historial_closeBulkModal(); }

    function historial_closeBulkModal() {
        document.getElementById('historial-modal-masiva').style.display = 'none';
    }

    function historial_showBulkUpload(object_id) {
        document.getElementById('historial-modal-masiva').style.display = 'block';
    }

    var modalExport = document.getElementById('historial-modal-export');
    var spanExport = document.getElementById('historial-modal-close-export');
    spanExport.onclick = function() { historial_closeExportModal(); }

    function historial_closeExportModal() {
        document.getElementById('historial-modal-export').style.display = 'none';
    }

    function historial_showExport(object_id) {
        document.getElementById('historial-modal-export').style.display = 'block';
    }

    var modalEdit = document.getElementById('historial-modal-editar');
    var spanEdit = document.getElementById('historial-modal-close-edit');
    spanEdit.onclick = function() { historial_closeEditModal(); }

    function historial_closeEditModal() {
        document.getElementById('historial-modal-editar').style.display = 'none';
    }

    function historial_loadRackObjects() {
        var rack_id = document.getElementById('rack_select').value;
        if (!rack_id) {
            document.getElementById('objects_list').style.display = 'none';
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                document.getElementById('rack_objects_list').innerHTML = xhr.responseText;
                document.getElementById('objects_list').style.display = 'block';
            }
        };
        xhr.send('op=historial_get_rack_objects&rack_id=' + rack_id);
    }

    function historial_loadTagObjects() {
        var tag_id = document.getElementById('tag_select').value;
        console.log('TAG ID SELECTED:', tag_id);
        if (!tag_id) {
            document.getElementById('tags_list').style.display = 'none';
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            console.log('RESPONSE:', xhr.responseText);
            if (xhr.status === 200) {
                document.getElementById('tag_objects_list').innerHTML = xhr.responseText;
                document.getElementById('tags_list').style.display = 'block';
            }
        };
        xhr.send('op=historial_get_tag_objects&tag_id=' + tag_id);
    }

    function historial_downloadFile(id, object_id) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = '<input type=\"hidden\" name=\"op\" value=\"historial_download\">' +
                         '<input type=\"hidden\" name=\"object_id\" value=\"' + object_id + '\">' +
                         '<input type=\"hidden\" name=\"id\" value=\"' + id + '\">';
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function historial_viewFile(id, object_id) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (xhr.responseText.startsWith('ERROR:')) {
                    alert(xhr.responseText.substring(6));
                } else {
                    document.getElementById('file-viewer-body').innerHTML = xhr.responseText;
                    document.getElementById('file-viewer-modal').style.display = 'block';
                }
            }
        };
        xhr.send('op=historial_view&id=' + id + '&object_id=' + object_id);
    }

    function historial_closeFileViewer() {
        document.getElementById('file-viewer-modal').style.display = 'none';
    }

    function historial_downloadTemplate() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = '<input type=\"hidden\" name=\"op\" value=\"historial_download_template\">';
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function historial_delete(id, object_id) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = '<input type=\"hidden\" name=\"op\" value=\"historial_delete\">' +
                         '<input type=\"hidden\" name=\"object_id\" value=\"' + object_id + '\">' +
                         '<input type=\"hidden\" name=\"id\" value=\"' + id + '\">';
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function historial_showEdit(id, object_id) {
        var row = event.target.closest('tr');
        var cells = row.querySelectorAll('td');

        document.getElementById('edit-id').value = id;
        document.getElementById('edit-evento').value = cells[0].textContent.trim();
        document.getElementById('edit-equipo').value = cells[1].textContent.trim();
        document.getElementById('edit-fecha').value = cells[2].textContent.trim().replace(' ', 'T');
        document.getElementById('edit-usuario').value = cells[3].textContent.trim();
        document.getElementById('edit-tipo-evento').value = cells[4].textContent.trim();
        document.getElementById('edit-description').value = cells[5].textContent.trim();
        document.getElementById('edit-estado').value = cells[6].textContent.trim();
        document.getElementById('edit-observaciones').value = cells[7].textContent.trim();
        document.getElementById('edit-referencia').value = cells[9].textContent.trim();

        document.getElementById('historial-modal-editar').style.display = 'block';
    }
    </script>";
}

// ====================================== FUNCIONES DE BD Y OPERACIONES ======================================

function historial_addFila($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['evento']) || empty($_POST['evento'])) {
        historial_showResponseMessage("Event.ID: requerido", 'error');
        return;
    }

    $evento_str = $_POST['evento'];
    if (strpos($evento_str, '-') !== false) {
        $partes = explode('-', $evento_str);
        $evento_num = intval(end($partes));
    } else {
        $evento_num = intval($evento_str);
    }

    $usuario = !empty($_POST['usuario']) ? $_POST['usuario'] : (isset($_POST['usuario_custom']) && !empty($_POST['usuario_custom']) ? $_POST['usuario_custom'] : $current_user);
    historial_agregarUsuario($usuario);

    $tipo_evento = !empty($_POST['tipo_evento']) ? $_POST['tipo_evento'] : '';

    if (empty($tipo_evento)) {
        historial_showResponseMessage("Tipo: requerido", 'error');
        return;
    }

    $equipo = isset($_POST['equipo']) ? $_POST['equipo'] : historial_getNombreObjeto($object_id);
    $fecha = isset($_POST['fecha']) ? str_replace('T', ' ', $_POST['fecha']) : date('Y-m-d H:i:s');
    $description = isset($_POST['description']) ? $_POST['description'] : '';
    $estado = !empty($_POST['estado']) ? $_POST['estado'] : '';
    $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : '';
    $referencia = isset($_POST['referencia']) ? $_POST['referencia'] : '';

    $adjunto_nombre = null;
    if (isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] == UPLOAD_ERR_OK) {
        $file_name = $_FILES['adjunto']['name'];
        $file_tmp = $_FILES['adjunto']['tmp_name'];
        $file_size = $_FILES['adjunto']['size'];

        if ($file_size > HISTORIAL_MAX_FILE_SIZE) {
            historial_showResponseMessage("Archivo: excede 10MB", 'error');
            return;
        }

        $upload_dir = historial_getUploadDir();
        $adjunto_nombre = intval($object_id) . '-' . $evento_num . '_' . time() . '_' . basename($file_name);
        $file_path = $upload_dir . '/' . $adjunto_nombre;

        if (!move_uploaded_file($file_tmp, $file_path)) {
            historial_showResponseMessage("Archivo: error al subir", 'error');
            return;
        }
    }

    try {
        $stmt = $dbxlink->prepare("SELECT id FROM Historial WHERE object_id = :object_id AND evento = :evento");
        $stmt->execute(array(':object_id' => intval($object_id), ':evento' => $evento_num));
        if ($stmt->fetch()) {
            historial_showResponseMessage("Event.ID ya existe", 'error');
            return;
        }

        $stmt = $dbxlink->prepare("
            INSERT INTO Historial
            (object_id, evento, equipo, fecha, usuario, tipo_evento, description, estado, observaciones, adjunto, referencia, created_by)
            VALUES
            (:object_id, :evento, :equipo, :fecha, :usuario, :tipo_evento, :description, :estado, :observaciones, :adjunto, :referencia, :created_by)
        ");

        $stmt->execute(array(
            ':object_id' => intval($object_id),
            ':evento' => $evento_num,
            ':equipo' => $equipo,
            ':fecha' => $fecha,
            ':usuario' => $usuario,
            ':tipo_evento' => $tipo_evento,
            ':description' => $description,
            ':estado' => $estado,
            ':observaciones' => $observaciones,
            ':adjunto' => $adjunto_nombre,
            ':referencia' => $referencia,
            ':created_by' => $current_user
        ));

        historial_showResponseMessage("Event.ID " . intval($object_id) . "-" . $evento_num . " agregado", 'success');
    } catch (PDOException $e) {
        historial_showResponseMessage("Error: " . $e->getMessage(), 'error');
    }
}

function historial_editFila($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['id'])) {
        historial_showResponseMessage("ID no v芍lido", 'error');
        return;
    }

    $id = intval($_POST['id']);

    try {
        $stmt = $dbxlink->prepare("SELECT created_by FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            historial_showResponseMessage("Registro no encontrado", 'error');
            return;
        }

        $evento_num = intval(end(explode('-', $_POST['evento'])));
        $usuario = !empty($_POST['usuario']) ? $_POST['usuario'] : $current_user;
        $tipo_evento = !empty($_POST['tipo_evento']) ? $_POST['tipo_evento'] : '';

        $stmt = $dbxlink->prepare("
            UPDATE Historial
            SET evento = :evento, equipo = :equipo, fecha = :fecha, usuario = :usuario, tipo_evento = :tipo_evento,
                description = :description, estado = :estado, observaciones = :observaciones, referencia = :referencia
            WHERE id = :id
        ");

        $stmt->execute(array(
            ':evento' => $evento_num,
            ':equipo' => $_POST['equipo'],
            ':fecha' => str_replace('T', ' ', $_POST['fecha']),
            ':usuario' => $usuario,
            ':tipo_evento' => $tipo_evento,
            ':description' => $_POST['description'],
            ':estado' => $_POST['estado'],
            ':observaciones' => $_POST['observaciones'],
            ':referencia' => $_POST['referencia'],
            ':id' => $id
        ));

        historial_showResponseMessage("Registro actualizado", 'success');
    } catch (PDOException $e) {
        historial_showResponseMessage("Error: " . $e->getMessage(), 'error');
    }
}

function historial_deleteFila($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['id'])) {
        return;
    }

    $id = intval($_POST['id']);

    try {
        $stmt = $dbxlink->prepare("SELECT adjunto FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return;
        }

        if ($record['adjunto']) {
            $file_path = historial_getUploadDir() . '/' . $record['adjunto'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        $stmt = $dbxlink->prepare("DELETE FROM Historial WHERE id = :id");
        $stmt->execute(array(':id' => $id));

        historial_showResponseMessage("Registro eliminado", 'success');
    } catch (PDOException $e) {
        historial_showResponseMessage("Error: " . $e->getMessage(), 'error');
    }
}

function historial_showResponseMessage($msg, $type)
{
    echo "<script>
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof historial_showMessage === 'function') {
                historial_showMessage('" . addslashes($msg) . "', '" . $type . "');
            }
        });
    } else {
        if (typeof historial_showMessage === 'function') {
            historial_showMessage('" . addslashes($msg) . "', '" . $type . "');
        }
    }
    </script>";
}

function historial_downloadArchivo($object_id, $current_user)
{
    global $dbxlink;
    if (!isset($_POST['id'])) return;
    $id = intval($_POST['id']);
    try {
        $stmt = $dbxlink->prepare("SELECT adjunto FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record || !$record['adjunto']) return;

        $file_path = historial_getUploadDir() . '/' . $record['adjunto'];
        if (!file_exists($file_path)) return;

        $file_parts = explode('_', $record['adjunto']);
        $original_name = end($file_parts);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $original_name . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } catch (PDOException $e) {
        return;
    }
}

function historial_viewArchivo($object_id, $current_user)
{
    global $dbxlink;
    if (!isset($_POST['id'])) return;
    $id = intval($_POST['id']);
    try {
        $stmt = $dbxlink->prepare("SELECT adjunto FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record || !$record['adjunto']) {
            echo "ERROR: Archivo no encontrado";
            exit;
        }

        $file_path = historial_getUploadDir() . '/' . $record['adjunto'];
        if (!file_exists($file_path)) {
            echo "ERROR: Archivo no existe";
            exit;
        }

        if (!historial_esArchivoVisualizable($file_path)) {
            echo "ERROR: Este tipo de archivo no puede ser visualizado ac芍. Bajalo y miralo donde pueda ser visto";
            exit;
        }

        $file_parts = explode('_', $record['adjunto']);
        $original_name = end($file_parts);
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        echo "<h3>" . htmlspecialchars($original_name) . "</h3>";

        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'bmp'))) {
            echo "<img src='data:image/" . $ext . ";base64," . base64_encode(file_get_contents($file_path)) . "' style='max-width: 100%;'>";
        } elseif (in_array($ext, array('txt', 'csv', 'html', 'htm'))) {
            echo "<pre>" . htmlspecialchars(file_get_contents($file_path)) . "</pre>";
        } else {
            echo "<p>Archivo: " . htmlspecialchars($original_name) . "</p>";
        }

        exit;
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage();
        exit;
    }
}

function historial_procesarCargaMasiva($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['bulk_data']) || empty($_POST['bulk_data'])) {
        historial_showResponseMessage("No hay datos", 'error');
        return;
    }

    $lineas = explode("\n", $_POST['bulk_data']);
    $exito = 0;
    $error = 0;

    foreach ($lineas as $num_linea => $linea) {
        $linea = trim($linea);
        if (empty($linea) || substr($linea, 0, 1) === '#') continue;

        $campos = explode(';', $linea);
        if (count($campos) < 10) {
            $error++;
            continue;
        }

        $evento_blank = trim($campos[0]);
        $equipo = trim($campos[1]);
        $fecha_str = trim($campos[2]);
        $usuario = trim($campos[3]);
        $tipo = trim($campos[4]);
        $descripcion = trim($campos[5]);
        $estado = trim($campos[6]);
        $observaciones = trim($campos[7]);
        $referencia = trim($campos[9]);

        if (empty($equipo) || empty($fecha_str) || empty($usuario) || empty($tipo)) {
            $error++;
            continue;
        }

        $fecha_obj = DateTime::createFromFormat('d/m/Y H:i', $fecha_str);
        if (!$fecha_obj) {
            $error++;
            continue;
        }
        $fecha = $fecha_obj->format('Y-m-d H:i:s');

        $stmt = $dbxlink->prepare("SELECT id FROM Object WHERE name = :name");
        $stmt->execute(array(':name' => $equipo));
        $equipo_record = $stmt->fetch();

        if (!$equipo_record) {
            $error++;
            continue;
        }

        $equipo_id = $equipo_record['id'];

        $stmt = $dbxlink->prepare("SELECT MAX(evento) as max_evento FROM Historial WHERE object_id = :object_id");
        $stmt->execute(array(':object_id' => $equipo_id));
        $result = $stmt->fetch();
        $evento = ($result['max_evento'] === null) ? 1001 : $result['max_evento'] + 1;

        historial_agregarUsuario($usuario);

        if (strlen($tipo) > 50) $tipo = substr($tipo, 0, 50);
        if (strlen($descripcion) > 400) $descripcion = substr($descripcion, 0, 400);
        if (strlen($estado) > 100) $estado = substr($estado, 0, 100);
        if (strlen($observaciones) > 200) $observaciones = substr($observaciones, 0, 200);
        if (strlen($referencia) > 20) $referencia = substr($referencia, 0, 20);

        try {
            $stmt = $dbxlink->prepare("
                INSERT INTO Historial
                (object_id, evento, equipo, fecha, usuario, tipo_evento, description, estado, observaciones, adjunto, referencia, created_by)
                VALUES
                (:object_id, :evento, :equipo, :fecha, :usuario, :tipo_evento, :description, :estado, :observaciones, NULL, :referencia, :created_by)
            ");

            $stmt->execute(array(
                ':object_id' => intval($equipo_id),
                ':evento' => intval($evento),
                ':equipo' => $equipo,
                ':fecha' => $fecha,
                ':usuario' => $usuario,
                ':tipo_evento' => $tipo,
                ':description' => $descripcion,
                ':estado' => $estado,
                ':observaciones' => $observaciones,
                ':referencia' => $referencia,
                ':created_by' => $current_user
            ));

            $exito++;
        } catch (PDOException $e) {
            $error++;
        }
    }

    $msg = "Procesado: $exito eventos";
    if ($error > 0) $msg .= " | Errores: $error";
    historial_showResponseMessage($msg, ($exito > 0 && $error == 0) ? 'success' : 'error');
}

function historial_descargarPlantilla()
{
    $template = <<<'EOT'
Carga Masiva de Eventos

-----------------------------------------------------------------------------------
Instrucciones.
-----------------------------------------------------------------------------------
1. Gener芍 un .txt que se llame "carga-masiva.txt"
   De acuerdo a las instrucciones, respetando el formato del ejemplo

-----------------------------------------------------------------------------------
Formato y restricciones
-----------------------------------------------------------------------------------
Son 9 campos, separados por ";"

;Equipo;Fecha;Usuario;Tipo;Descripci車n;Estado;Observaciones;Adjuntos;Referencia;

Comenzar el rengl車n con ";"

     - Equipo:         S1F1R01U41
     - Fecha:          DIA/MES/A?O HORA:MINUTO   (ej: 08/03/2026 09:21)
         - Usuario:        tu usuario de dominio, solo el usuario
     - Tipo:           Mantenimiento/Cambio/Problema/Mudanza/Documentaci車n/Otro-personalizado
     - Descripci車n:    max 400 caracteres
     - Estado:         Iniciado/En curso/Actualizado/Cerrado/Cancelado/Otro-personalizado
     - Observaciones:  max 200 caracteres
         - Adjuntos:       Dejar vac赤o, pero que el campo exista entre ";"  ---> ;;
     - Referencia:     max 20 caracteres


Ejemplo

;S1F1R01U41;08/03/2026 09:21;dcifuentes;Mudanza;Avisamos al NOC que comienza la mudanza;En curso;Observaciones-1;;OT-123678;
;S1F1R01U41;08/03/2026 10:21;dcifuentes;Mudanza;Se procede a apagar el servidor;Finalizado;Cerrar la OT-122133a;;OT-123678;
;S1F1R01U41;08/03/2026 12:34;dcifuentes;Mudanza;Equipo listo para mudanza;En curso;Avisar a Tapha;;OT-123678;



Se pueden cargar registros en forma masiva para distintos equipos en una misma carga

ejemplo:

;S1F1R01U41;10/03/2026 03:44;jojcius;Documentaci車n;Carga inicial de datos en el eqiuipo;En curso;Equipo pr車ximo a mudarse;;OT-112233;
;S1F1R01U39;10/03/2026 03:44;jojcius;Documentaci車n;Carga inicial de datos en el eqiuipo;En curso;Equipo pr車ximo a mudarse;;OT-112233;
;S1F1R01U37;10/03/2026 03:44;jojcius;Documentaci車n;Carga inicial de datos en el eqiuipo;En curso;Equipo pr車ximo a mudarse;;OT-112233;
;S1F1R01U35;10/03/2026 03:44;jojcius;Documentaci車n;Carga inicial de datos en el eqiuipo;En curso;Equipo pr車ximo a mudarse;;OT-112233;
;S1F1R01U33;10/03/2026 03:44;jojcius;Documentaci車n;Carga inicial de datos en el eqiuipo;En curso;Equipo pr車ximo a mudarse;;OT-112233;
;S1F1R01U31;10/03/2026 03:44;jojcius;Documentaci車n;Carga inicial de datos en el eqiuipo;En curso;Equipo pr車ximo a mudarse;;OT-112233;
EOT;

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="carga-masiva.txt"');
    echo $template;
    exit;
}

function historial_ajaxGetRackObjects()
{
    if (!isset($_POST['rack_id'])) return;
    $rack_id = intval($_POST['rack_id']);
    $objetos = historial_getObjectosEnRack($rack_id);
    foreach ($objetos as $obj) {
        echo "<label><input type='checkbox' name='objetos[]' value='" . intval($obj['id']) . "' checked> " . htmlspecialchars($obj['name']) . "</label>";
    }
    exit;
}

function historial_ajaxGetTagObjects()
{
    if (!isset($_POST['tag_id'])) return;
    $tag_id = intval($_POST['tag_id']);
    $objetos = historial_getObjectosPorTag($tag_id);
    if (!empty($objetos)) {
        foreach ($objetos as $obj) {
            echo "<label><input type='checkbox' name='objetos[]' value='" . intval($obj['id']) . "' checked> " . htmlspecialchars($obj['name']) . "</label>";
        }
    } else {
        echo "<p style='color:#999;'>No hay objetos con este TAG</p>";
    }
    exit;
}

function historial_exportarInforme($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['export_type'])) {
        return;
    }

    $export_type = $_POST['export_type'];
    $format = isset($_POST['export_format']) ? $_POST['export_format'] : 'csv';

    if ($export_type == 'single') {
        $export_object = isset($_POST['export_object']) && !empty($_POST['export_object']) ? $_POST['export_object'] : historial_getNombreObjeto($object_id);
        $stmt = $dbxlink->prepare("SELECT id FROM Object WHERE name = :name");
        $stmt->execute(array(':name' => $export_object));
        $obj = $stmt->fetch();
        if (!$obj) return;

        historial_generarInformeObjeto($obj['id'], $format, $current_user);
    } else {
        $objetos_ids = isset($_POST['objetos']) ? $_POST['objetos'] : array();
        if (empty($objetos_ids)) return;

        foreach ($objetos_ids as $obj_id) {
            $obj_id = intval($obj_id);
            historial_generarInformeObjeto($obj_id, $format, $current_user);
        }
    }
}

function historial_generarInformeObjeto($object_id, $format, $current_user)
{
    global $dbxlink;

    $objeto = historial_getObjectoCompleto($object_id);
    $info_completa = historial_getObjectoInfoCompleta($object_id);

    $stmt = $dbxlink->prepare("SELECT * FROM Historial WHERE object_id = :object_id ORDER BY evento ASC");
    $stmt->execute(array(':object_id' => intval($object_id)));
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format == 'csv') {
        $csv = "\"INFORME DE EQUIPO\"\n";
        $csv .= "\"Generado:\";\""  . date('d/m/Y H:i:s') . "\"\n";
        $csv .= "\"Nombre:\";\""  . $objeto['name'] . "\"\n\n";

        $csv .= "\"INFORMACI車N DEL EQUIPO\"\n";
        if (isset($info_completa['objeto']) && is_array($info_completa['objeto'])) {
            foreach ($info_completa['objeto'] as $key => $value) {
                if (!in_array($key, array('id', 'objtype_id'))) {
                    $csv .= "\"" . $key . "\";\""  . $value . "\"\n";
                }
            }
        }
        $csv .= "\n";

        if (isset($info_completa['atributos']) && count($info_completa['atributos']) > 0) {
            $csv .= "\"ATRIBUTOS\"\n";
            foreach ($info_completa['atributos'] as $attr) {
                $value = $attr['string_value'] ?: $attr['uint_value'] ?: $attr['float_value'];
                $csv .= "\"" . $attr['name'] . "\";\""  . $value . "\"\n";
            }
            $csv .= "\n";
        }

        if (isset($info_completa['tags']) && count($info_completa['tags']) > 0) {
            $csv .= "\"TAGS:\";\"";
            $tag_names = array_map(function($t) { return $t['tag']; }, $info_completa['tags']);
            $csv .= implode(", ", $tag_names) . "\"\n\n";
        }

        if (isset($info_completa['ips']) && count($info_completa['ips']) > 0) {
            $csv .= "\"IPS ASIGNADAS\"\n";
            $csv .= "\"Nombre\";\"Tipo\";\"IP\"\n";
            foreach ($info_completa['ips'] as $ip) {
                $ip_notacion = historial_ipDecimalANotacion($ip['ip']);
                $csv .= "\"" . $ip['name'] . "\";\"" . $ip['type'] . "\";\"" . $ip_notacion . "\"\n";
            }
            $csv .= "\n";
        }

        $csv .= "\"HISTORIAL DE EVENTOS\"\n";
        $csv .= "\"Evento\";\"Equipo\";\"Fecha\";\"Usuario\";\"Tipo\";\"Descripci車n\";\"Estado\";\"Observaciones\";\"Adjuntos\";\"Referencia\"\n";

        foreach ($historial as $h) {
            $evento_str = intval($object_id) . "-" . intval($h['evento']);
            $csv .= "\"$evento_str\";\"" . $h['equipo'] . "\";\"" . $h['fecha'] . "\";\"" . $h['usuario'] . "\";\"" . $h['tipo_evento'] . "\";\"" . $h['description'] . "\";\"" . $h['estado'] . "\";\"" . $h['observaciones'] . "\";\"" . ($h['adjunto'] ? 'S赤' : 'NO') . "\";\"" . $h['referencia'] . "\"\n";
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="informe_' . $objeto['name'] . '_' . date('Ymd_His') . '.csv"');
        echo $csv;
        exit;
    } elseif ($format == 'txt') {
        $txt = "INFORME DE EQUIPO\n";
        $txt .= "Generado: " . date('d/m/Y H:i:s') . "\n";
        $txt .= str_repeat("=", 80) . "\n\n";

        $txt .= "EQUIPO: " . $objeto['name'] . "\n";
        $txt .= str_repeat("-", 80) . "\n\n";

        $txt .= "INFORMACI車N DEL EQUIPO:\n";
        if (isset($info_completa['objeto']) && is_array($info_completa['objeto'])) {
            foreach ($info_completa['objeto'] as $key => $value) {
                if (!in_array($key, array('id', 'objtype_id'))) {
                    $txt .= str_pad($key . ":", 25) . $value . "\n";
                }
            }
        }
        $txt .= "\n";

        if (isset($info_completa['atributos']) && count($info_completa['atributos']) > 0) {
            $txt .= "ATRIBUTOS:\n";
            foreach ($info_completa['atributos'] as $attr) {
                $value = $attr['string_value'] ?: $attr['uint_value'] ?: $attr['float_value'];
                $txt .= str_pad($attr['name'] . ":", 25) . $value . "\n";
            }
            $txt .= "\n";
        }

        if (isset($info_completa['tags']) && count($info_completa['tags']) > 0) {
            $txt .= "TAGS:\n";
            $tag_names = array_map(function($t) { return $t['tag']; }, $info_completa['tags']);
            $txt .= implode(", ", $tag_names) . "\n\n";
        }

        if (isset($info_completa['ips']) && count($info_completa['ips']) > 0) {
            $txt .= "IPS ASIGNADAS:\n";
            $txt .= str_pad("Nombre", 30) . str_pad("Tipo", 20) . "IP\n";
            $txt .= str_repeat("-", 80) . "\n";
            foreach ($info_completa['ips'] as $ip) {
                $ip_notacion = historial_ipDecimalANotacion($ip['ip']);
                $txt .= str_pad($ip['name'], 30) . str_pad($ip['type'], 20) . $ip_notacion . "\n";
            }
            $txt .= "\n";
        }

        $txt .= "\nHISTORIAL DE EVENTOS:\n";
        $txt .= str_repeat("-", 80) . "\n";

        foreach ($historial as $h) {
            $txt .= "\nEvento: " . intval($object_id) . "-" . intval($h['evento']) . "\n";
            $txt .= str_pad("Fecha:", 20) . $h['fecha'] . "\n";
            $txt .= str_pad("Usuario:", 20) . $h['usuario'] . "\n";
            $txt .= str_pad("Tipo:", 20) . $h['tipo_evento'] . "\n";
            $txt .= str_pad("Descripci車n:", 20) . $h['description'] . "\n";
            $txt .= str_pad("Estado:", 20) . $h['estado'] . "\n";
            $txt .= str_pad("Referencia:", 20) . $h['referencia'] . "\n";
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="informe_' . $objeto['name'] . '_' . date('Ymd_His') . '.txt"');
        echo $txt;
        exit;
    } elseif ($format == 'pdf') {
        // GENERAR PDF CON mPDF
        $autoload = '/var/www/html/RackTables-0.21.4/plugins/historial/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;

            try {
                $mpdf = new \Mpdf\Mpdf();

                $html = "<h1>INFORME DE EQUIPO</h1>";
                $html .= "<p><strong>Generado:</strong> " . date('d/m/Y H:i:s') . "</p>";
                $html .= "<p><strong>Equipo:</strong> " . htmlspecialchars($objeto['name']) . "</p>";

                $html .= "<h2>INFORMACI車N DEL EQUIPO</h2>";
                $html .= "<table border='1' cellpadding='5'>";
                if (isset($info_completa['objeto']) && is_array($info_completa['objeto'])) {
                    foreach ($info_completa['objeto'] as $key => $value) {
                        if (!in_array($key, array('id', 'objtype_id'))) {
                            $html .= "<tr><td><strong>" . htmlspecialchars($key) . "</strong></td><td>" . htmlspecialchars($value) . "</td></tr>";
                        }
                    }
                }
                $html .= "</table>";

                if (isset($info_completa['atributos']) && count($info_completa['atributos']) > 0) {
                    $html .= "<h2>ATRIBUTOS</h2>";
                    $html .= "<table border='1' cellpadding='5'>";
                    foreach ($info_completa['atributos'] as $attr) {
                        $value = $attr['string_value'] ?: $attr['uint_value'] ?: $attr['float_value'];
                        $html .= "<tr><td><strong>" . htmlspecialchars($attr['name']) . "</strong></td><td>" . htmlspecialchars($value) . "</td></tr>";
                    }
                    $html .= "</table>";
                }

                $html .= "<h2>HISTORIAL DE EVENTOS</h2>";
                $html .= "<table border='1' cellpadding='5'>";
                $html .= "<tr><th>Evento</th><th>Equipo</th><th>Fecha</th><th>Usuario</th><th>Tipo</th><th>Descripci車n</th><th>Estado</th></tr>";

                foreach ($historial as $h) {
                    $evento_str = intval($object_id) . "-" . intval($h['evento']);
                    $html .= "<tr>";
                    $html .= "<td>" . htmlspecialchars($evento_str) . "</td>";
                    $html .= "<td>" . htmlspecialchars($h['equipo']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($h['fecha']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($h['usuario']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($h['tipo_evento']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($h['description']) . "</td>";
                    $html .= "<td>" . htmlspecialchars($h['estado']) . "</td>";
                    $html .= "</tr>";
                }

                $html .= "</table>";

                $mpdf->WriteHTML($html);
                $mpdf->Output('informe_' . $objeto['name'] . '_' . date('Ymd_His') . '.pdf', 'D');
                exit;
            } catch (Exception $e) {
                header('Content-Type: text/plain');
                echo "Error al generar PDF: " . $e->getMessage();
                exit;
            }
        } else {
            header('Content-Type: text/plain');
            echo "Error: mPDF no est芍 instalado. Instala con: composer require mpdf/mpdf";
            exit;
        }
    }
}

?>


