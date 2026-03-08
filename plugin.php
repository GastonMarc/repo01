<?php

/*
 * RackTables Plugin: Historial
 * Gestión de historial de eventos para objetos
 * Versión 4.0 - Completo con carga masiva y exportación
 */

define('HISTORIAL_UPLOAD_DIR', sys_get_temp_dir() . '/racktables_historial');
define('HISTORIAL_MAX_FILE_SIZE', 10485760); // 10MB

function plugin_historial_info()
{
    return array(
        'name' => 'historial',
        'longname' => 'Historial de Eventos',
        'version' => 'En la 69.G',
        'home_url' => 'OJ & Friends'
    );
}

function plugin_historial_install()
{
    global $dbxlink;
    
    try {
        $stmt = $dbxlink->prepare("SHOW TABLES LIKE 'Historial'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            throw new Exception("La tabla Historial no existe");
        }
        
        $stmt = $dbxlink->prepare("SHOW TABLES LIKE 'Historial_Usuarios'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            throw new Exception("Ejecuta primero el archivo SQL de setup");
        }
        
    } catch (PDOException $e) {
        throw new Exception("Error en instalación: " . $e->getMessage());
    }
}

function plugin_historial_uninstall() {}

function plugin_historial_init()
{
    global $tabhandler, $tab;
    $tab['object']['historial'] = 'Historial';
    $tabhandler['object']['historial'] = 'historial_renderContent';
}

// =====================================================
// FUNCIONES PRINCIPALES
// =====================================================

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
                case 'historial_bulk_upload':
                    historial_procesarCargaMasiva($object_id, $remote_username);
                    break;
                case 'historial_export_csv':
                    historial_exportarCSV($object_id, $remote_username);
                    break;
                case 'historial_download_template':
                    historial_descargarPlantilla();
                    break;
                case 'historial_add_usuario':
                    historial_agregarUsuario($remote_username);
                    break;
            }
        }
    }

    historial_renderInterfaz($object_id, $remote_username);
}

// =====================================================
// FUNCIONES AUXILIARES - BD
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

// =====================================================
// RENDER - INTERFAZ PRINCIPAL
// =====================================================

function historial_renderInterfaz($object_id, $current_user)
{
    echo "<div id='historial-main-container'>";
    historial_renderCSS();
    historial_renderFormNuevaFila($object_id, $current_user);
    echo "<br><br>";
    historial_renderTablaExistente($object_id, $current_user);
    echo "</div>";
}

function historial_renderCSS()
{
    echo "<style>
    #historial-main-container {
        font-family: Arial, sans-serif;
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
    
    #historial-main-container .historial-table td {
        padding: 8px 12px;
        border: 1px solid #ccc;
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
    }
    
    #historial-main-container .historial-btn:hover {
        background-color: #0052a3;
    }
    
    #historial-main-container .historial-btn-subir {
        background-color: #a349a4;
        padding: 6px 12px;
    }
    
    #historial-main-container .historial-btn-subir:hover {
        background-color: #8b3a8b;
    }
    
    #historial-main-container .historial-btn-masiva {
        background-color: #cc0000;
        padding: 6px 12px;
    }
    
    #historial-main-container .historial-btn-masiva:hover {
        background-color: #990000;
    }
    
    #historial-main-container .historial-btn-export {
        background-color: #804040;
        padding: 6px 12px;
    }
    
    #historial-main-container .historial-btn-export:hover {
        background-color: #663333;
    }
    
    #historial-main-container .historial-btn-download {
        background-color: #007a37;
        padding: 6px 12px;
    }
    
    #historial-main-container .historial-btn-download:hover {
        background-color: #005c2b;
    }
    
    #historial-main-container .historial-success {
        padding: 10px;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 4px;
        margin-bottom: 15px;
        color: #155724;
    }
    
    #historial-main-container .historial-error {
        padding: 10px;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        border-radius: 4px;
        margin-bottom: 15px;
        color: #721c24;
    }
    
    #historial-main-container .historial-link-edit {
        color: #0066cc;
        text-decoration: none;
        cursor: pointer;
        margin-right: 10px;
    }
    
    #historial-main-container .historial-link-edit:hover {
        text-decoration: underline;
    }
    
    #historial-main-container .historial-link-delete {
        color: #cc0000;
        text-decoration: none;
        cursor: pointer;
    }
    
    #historial-main-container .historial-link-delete:hover {
        text-decoration: underline;
    }
    
    #historial-main-container .historial-icon-file {
        cursor: pointer;
        font-size: 18px;
        color: #0066cc;
    }
    
    #historial-main-container .historial-icon-file:hover {
        color: #0052a3;
    }
    
    #historial-main-container .historial-char-count {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
    }
    
    #historial-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    
    #historial-modal.show {
        display: block;
    }
    
    #historial-modal-content {
        background-color: #fefefe;
        margin: 2% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 90%;
        max-width: 1000px;
        border-radius: 5px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        max-height: 80vh;
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
    
    #historial-modal-content pre {
        background-color: #f5f5f5;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 3px;
        overflow-x: auto;
        font-size: 12px;
    }
    
    #historial-modal-content textarea {
        width: 100%;
        min-height: 300px;
        font-family: monospace;
        padding: 10px;
        border: 1px solid #999;
        border-radius: 3px;
        box-sizing: border-box;
    }
    </style>";
}

function historial_renderFormNuevaFila($object_id, $current_user)
{
    $nombre_objeto = historial_getNombreObjeto($object_id);
    $proximo_evento = historial_getProximoEvento($object_id);
    $fecha_actual = date('Y-m-d\TH:i');
    $usuarios = historial_getUsuarios();
    
    echo "<div class='historial-form-header'>Agregar nuevo evento</div>";
    echo "<div class='historial-form-row'>";
    
    echo "<form method='POST' action='' enctype='multipart/form-data' style='margin: 0;'>";
    echo "<input type='hidden' name='op' value='historial_add'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";

    echo "<table class='historial-form-table'>";
    echo "<tr>";
    echo "<th>Evento</th>";
    echo "<th>Equipo</th>";
    echo "<th>Fecha</th>";
    echo "<th>Usuario</th>";
    echo "<th>Tipo Evento</th>";
    echo "<th>Descripción</th>";
    echo "<th>Estado</th>";
    echo "<th>Observaciones</th>";
    echo "<th>Adjuntos</th>";
    echo "<th>Referencia</th>";
    echo "<th></th>";
    echo "</tr>";

    echo "<tr>";

    // Evento (EDITABLE)
    echo "<td><input type='text' name='evento' class='historial-input-text' value='" . intval($object_id) . "-" . $proximo_evento . "' style='width:100px;'></td>";

    // Equipo (EDITABLE)
    echo "<td><input type='text' name='equipo' class='historial-input-text' value='" . htmlspecialchars($nombre_objeto) . "' style='width:150px;'></td>";

    // Fecha (EDITABLE)
    echo "<td><input type='datetime-local' name='fecha' class='historial-input-text' value='" . $fecha_actual . "' required style='width:180px;'></td>";

    // Usuario (SELECT + CUSTOM)
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

    // Tipo Evento
    echo "<td>";
    echo "<select name='tipo_evento' class='historial-input-select' style='width:140px;'>";
    echo "<option value=''>-- Seleccionar --</option>";
    echo "<option value='Mantenimiento'>Mantenimiento</option>";
    echo "<option value='Cambio'>Cambio</option>";
    echo "<option value='Problema'>Problema</option>";
    echo "<option value='Mudanza'>Mudanza</option>";
    echo "<option value='Documentación'>Documentación</option>";
    echo "<option value='Alta'>Alta</option>";
    echo "<option value='Baja'>Baja</option>";
    echo "<option value='Otro (No Específico)'>Otro (No Específico)</option>";
    echo "</select>";
    echo "<input type='text' name='tipo_evento_custom' class='historial-input-text' placeholder='O escribir...' style='width:140px; margin-top:3px;' maxlength='50'>";
    echo "<div class='historial-char-count'>( max: 50 caracteres )</div>";
    echo "</td>";

    // Descripción
    echo "<td>";
    echo "<input type='text' name='description' class='historial-input-text' maxlength='200' style='width:250px;'>";
    echo "<div class='historial-char-count'>( max: 200 caracteres )</div>";
    echo "</td>";

    // Estado (NUEVO ORDEN)
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

    // Observaciones
    echo "<td>";
    echo "<input type='text' name='observaciones' class='historial-input-text' maxlength='200' style='width:200px;'>";
    echo "<div class='historial-char-count'>( max: 200 caracteres )</div>";
    echo "</td>";

    // Adjuntos - BOTÓN VIOLETA
    echo "<td>";
    echo "<input type='file' name='adjunto' class='historial-input-file' id='file-input' style='display:none;'>";
    echo "<button type='button' class='historial-btn historial-btn-subir' onclick=\"document.getElementById('file-input').click()\">Subir</button>";
    echo "<span id='file-name' style='display:block; font-size:11px; margin-top:3px;'></span>";
    echo "</td>";

    // Referencia
    echo "<td><input type='text' name='referencia' class='historial-input-text' maxlength='20' style='width:80px;'></td>";

    // Botones
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
    
    // Script para mostrar nombre del archivo
    echo "<script>
    document.getElementById('file-input').addEventListener('change', function(e) {
        var name = e.target.files[0] ? e.target.files[0].name : '';
        document.getElementById('file-name').textContent = name ? '📎 ' + name : '';
    });
    </script>";
}

function historial_renderTablaExistente($object_id, $current_user)
{
    global $dbxlink;

    try {
        $stmt = $dbxlink->prepare("
            SELECT id, evento, equipo, fecha, usuario, tipo_evento, description, estado, observaciones, adjunto, referencia, created_by 
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
    echo "<button class='historial-btn historial-btn-export' onclick='historial_showExport(" . intval($object_id) . ")'>Bajar Informe CSV/XLS</button>";
    echo "</div>";

    if (count($rows) == 0) {
        echo "<p><em>Sin eventos registrados</em></p>";
        return;
    }

    echo "<table class='historial-table'>";
    echo "<tr>";
    echo "<th>Evento</th>";
    echo "<th>Equipo</th>";
    echo "<th>Fecha</th>";
    echo "<th>Usuario</th>";
    echo "<th>Tipo</th>";
    echo "<th>Descripción</th>";
    echo "<th>Estado</th>";
    echo "<th>Observaciones</th>";
    echo "<th>Adjuntos</th>";
    echo "<th>Referencia</th>";
    echo "<th>Acciones</th>";
    echo "</tr>";

    $order = 'odd';
    foreach ($rows as $row) {
        echo "<tr class='row_" . $order . "'>";
        echo "<td><strong>" . intval($object_id) . "-" . intval($row['evento']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['equipo']) . "</td>";
        echo "<td>" . htmlspecialchars($row['fecha']) . "</td>";
        echo "<td>" . htmlspecialchars($row['usuario']) . "</td>";
        echo "<td>" . htmlspecialchars($row['tipo_evento']) . "</td>";
        echo "<td>" . htmlspecialchars($row['description']) . "</td>";
        echo "<td>" . htmlspecialchars($row['estado']) . "</td>";
        echo "<td>" . htmlspecialchars($row['observaciones']) . "</td>";
        
        // Adjuntos - ICONO MEJORADO
        echo "<td>";
        if ($row['adjunto']) {
            echo "<span class='historial-icon-file' onclick=\"historial_downloadFile(" . intval($row['id']) . ", " . intval($object_id) . ")\" title='Descargar archivo'>📥</span>";
        } else {
            echo "-";
        }
        echo "</td>";
        
        echo "<td>" . htmlspecialchars($row['referencia']) . "</td>";
        echo "<td>";
        echo "<a class='historial-link-edit' onclick=\"historial_showEdit(" . intval($row['id']) . ", " . intval($object_id) . ")\">Editar</a>";
        echo " | ";
        echo "<a class='historial-link-delete' onclick=\"if(confirm('¿Eliminar este registro?')) { historial_delete(" . intval($row['id']) . ", " . intval($object_id) . "); }\">Borrar</a>";
        echo "</td>";
        echo "</tr>";

        $order = ($order == 'odd') ? 'even' : 'odd';
    }

    echo "</table>";
    
    // MODALES
    historial_renderModalEditar($object_id);
    historial_renderModalCargaMasiva($object_id);
    historial_renderModalExportar($object_id);
    
    // SCRIPTS
    historial_renderScripts();
}

function historial_renderModalEditar($object_id)
{
    $usuarios = historial_getUsuarios();
    
    echo "<div id='historial-modal-editar' id='historial-modal' style='display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4);'>";
    echo "<div id='historial-modal-content' style='background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 900px; border-radius: 5px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);'>";
    echo "<span id='historial-modal-close' style='color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;'>&times;</span>";
    echo "<h2>Editar Evento</h2>";
    
    echo "<form id='historial-edit-form' method='POST' action=''>";
    echo "<input type='hidden' name='op' value='historial_edit'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";
    echo "<input type='hidden' name='id' id='edit-id' value=''>";
    
    echo "<table class='historial-form-table' style='width:100%;'>";
    
    echo "<tr><td style='width:150px;'><strong>Evento:</strong></td><td><input type='text' name='evento' id='edit-evento' class='historial-input-text' maxlength='20' style='width:200px;'></td></tr>";
    
    echo "<tr><td><strong>Equipo:</strong></td><td><input type='text' name='equipo' id='edit-equipo' class='historial-input-text' maxlength='255' style='width:300px;'></td></tr>";
    
    echo "<tr><td><strong>Fecha:</strong></td><td><input type='datetime-local' name='fecha' id='edit-fecha' class='historial-input-text' style='width:200px;'></td></tr>";
    
    echo "<tr><td><strong>Usuario:</strong></td><td>";
    echo "<select name='usuario' id='edit-usuario' class='historial-input-select' style='width:200px;'>";
    foreach ($usuarios as $usuario) {
        echo "<option value='" . htmlspecialchars($usuario) . "'>" . htmlspecialchars($usuario) . "</option>";
    }
    echo "</select>";
    echo "<input type='text' name='usuario_custom' id='edit-usuario-custom' class='historial-input-text' placeholder='O escribir...' style='width:200px; margin-left:5px;' maxlength='50'>";
    echo "</td></tr>";
    
    echo "<tr><td><strong>Tipo Evento:</strong></td><td>";
    echo "<select name='tipo_evento' id='edit-tipo-evento' class='historial-input-select' style='width:200px;'>";
    echo "<option value=''>-- Seleccionar --</option>";
    echo "<option value='Mantenimiento'>Mantenimiento</option>";
    echo "<option value='Cambio'>Cambio</option>";
    echo "<option value='Problema'>Problema</option>";
    echo "<option value='Mudanza'>Mudanza</option>";
    echo "<option value='Documentación'>Documentación</option>";
    echo "<option value='Alta'>Alta</option>";
    echo "<option value='Baja'>Baja</option>";
    echo "<option value='Otro (No Específico)'>Otro (No Específico)</option>";
    echo "</select>";
    echo "<input type='text' name='tipo_evento_custom' id='edit-tipo-evento-custom' class='historial-input-text' placeholder='O escribir...' style='width:200px; margin-left:5px;' maxlength='50'>";
    echo "</td></tr>";
    
    echo "<tr><td><strong>Descripción:</strong></td><td><input type='text' name='description' id='edit-description' class='historial-input-text' maxlength='200' style='width:400px;'></td></tr>";
    
    echo "<tr><td><strong>Estado:</strong></td><td>";
    echo "<select name='estado' id='edit-estado' class='historial-input-select' style='width:200px;'>";
    echo "<option value=''>-- Seleccionar --</option>";
    echo "<option value='Iniciado'>Iniciado</option>";
    echo "<option value='En curso'>En curso</option>";
    echo "<option value='Actualizado'>Actualizado</option>";
    echo "<option value='Cerrado'>Cerrado</option>";
    echo "<option value='Cancelado'>Cancelado</option>";
    echo "</select>";
    echo "<input type='text' name='estado_custom' id='edit-estado-custom' class='historial-input-text' placeholder='O escribir...' style='width:200px; margin-left:5px;' maxlength='100'>";
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
    echo "<div id='historial-modal-masiva' id='historial-modal' style='display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); overflow-y: auto;'>";
    echo "<div id='historial-modal-content' style='background-color: #fefefe; margin: 2% auto; padding: 20px; border: 1px solid #888; width: 90%; max-width: 1000px; border-radius: 5px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);'>";
    echo "<span id='historial-modal-close-masiva' style='color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;'>&times;</span>";
    echo "<h2>Carga Masiva de Eventos</h2>";
    
    echo "<h3>Instrucciones</h3>";
    echo "<p>Para hacer una carga masiva, debes generar un renglón por cada evento, con cada columna separada por <strong>;</strong></p>";
    echo "<p>El campo <strong>Evento</strong> déjalo en blanco (ej: ;;), el sistema lo generará automáticamente.</p>";
    echo "<p>El resto de los campos los puedes completar o dejar en blanco, siempre que los separes por <strong>;</strong></p>";
    
    echo "<h3>Formato y restricciones</h3>";
    echo "<pre>";
    echo "Evento;Equipo;Fecha;Usuario;Tipo;Descripción;Estado;Observaciones;Adjuntos;Referencia;\n";
    echo "\n";
    echo "RESTRICCIONES:\n";
    echo "- Evento:        Dejar en blanco (ej: ;;)\n";
    echo "- Equipo:        Debe ser el nombre exacto del equipo en RackTables (max 255 caract.)\n";
    echo "- Fecha:         Formato: DIA/MES/AÑO HORA:MINUTO (ej: 08/03/2026 09:21)\n";
    echo "- Usuario:       Nombre del usuario (max 50 caract.)\n";
    echo "- Tipo:          Mantenimiento/Cambio/Problema/Mudanza/Documentación/Alta/Baja/Otro\n";
    echo "- Descripción:   max 200 caracteres\n";
    echo "- Estado:        Iniciado/En curso/Actualizado/Cerrado/Cancelado (max 100 caract.)\n";
    echo "- Observaciones: max 200 caracteres\n";
    echo "- Adjuntos:      Dejar en blanco (ej: ;;)\n";
    echo "- Referencia:    max 20 caracteres\n";
    echo "</pre>";
    
    echo "<h3>Ejemplo</h3>";
    echo "<pre>";
    echo ";;S1F1R01U41;08/03/2026 09:21;dcifuentes;Mudanza;Avisamos al NOC que comienza la mudanza;;OT-123678;\n";
    echo ";;S1F1R01U41;08/03/2026 10:21;dcifuentes;Mudanza;Se procede a apagar el servidor;;OT-123678;\n";
    echo ";;S1F1R01U41;08/03/2026 12:34;dcifuentes;Mudanza;Equipo listo para mudanza;;OT-123678;\n";
    echo ";;S1F4R01U05;10/02/2026 15:13;ndemary;Mantenimiento;Reemplazo de memoria;;OT-666222;\n";
    echo "</pre>";
    
    echo "<h3>Cargar datos</h3>";
    echo "<form method='POST' action=''>";
    echo "<input type='hidden' name='op' value='historial_bulk_upload'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";
    echo "<textarea name='bulk_data' required placeholder='Pega aquí los datos separados por ; (uno por línea)'></textarea>";
    echo "<br><br>";
    echo "<input type='submit' class='historial-btn historial-btn-masiva' value='Procesar Carga'>";
    echo "<input type='button' class='historial-btn' value='Descargar Plantilla' onclick='historial_downloadTemplate()' style='background-color: #28a745;'>";
    echo "<input type='button' class='historial-btn' value='Cerrar' onclick='historial_closeBulkModal()' style='background-color: #999;'>";
    echo "</form>";
    echo "</div>";
    echo "</div>";
}

function historial_renderModalExportar($object_id)
{
    echo "<div id='historial-modal-export' id='historial-modal' style='display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4);'>";
    echo "<div id='historial-modal-content' style='background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 5px;'>";
    echo "<span id='historial-modal-close-export' style='color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;'>&times;</span>";
    echo "<h2>Bajar Informe</h2>";
    
    echo "<form method='POST' action=''>";
    echo "<input type='hidden' name='op' value='historial_export_csv'>";
    echo "<input type='hidden' name='object_id' value='" . intval($object_id) . "'>";
    
    echo "<p><strong>Selecciona el equipo:</strong></p>";
    echo "<input type='text' name='export_object' id='export_object' class='historial-input-text' placeholder='Nombre del equipo' style='width:100%; margin-bottom:10px;'>";
    echo "<small>Déjalo vacío para el equipo actual</small>";
    
    echo "<br><br>";
    echo "<input type='submit' class='historial-btn historial-btn-export' value='Generar CSV'>";
    echo "<input type='button' class='historial-btn' value='Cerrar' onclick='historial_closeExportModal()' style='background-color: #999;'>";
    
    echo "</form>";
    echo "</div>";
    echo "</div>";
}

function historial_renderScripts()
{
    echo "<script>
    // MODALES - EDITAR
    var modalEditar = document.getElementById('historial-modal-editar');
    var spanEditar = document.getElementById('historial-modal-close');
    
    spanEditar.onclick = function() { historial_closeEditModal(); }
    window.onclick = function(event) {
        if (event.target == modalEditar) historial_closeEditModal();
    }
    
    function historial_closeEditModal() {
        document.getElementById('historial-modal-editar').style.display = 'none';
    }
    
    // MODALES - CARGA MASIVA
    var modalMasiva = document.getElementById('historial-modal-masiva');
    var spanMasiva = document.getElementById('historial-modal-close-masiva');
    
    spanMasiva.onclick = function() { historial_closeBulkModal(); }
    
    function historial_closeBulkModal() {
        document.getElementById('historial-modal-masiva').style.display = 'none';
    }
    
    function historial_showBulkUpload(object_id) {
        document.getElementById('historial-modal-masiva').style.display = 'block';
    }
    
    // MODALES - EXPORT
    var modalExport = document.getElementById('historial-modal-export');
    var spanExport = document.getElementById('historial-modal-close-export');
    
    spanExport.onclick = function() { historial_closeExportModal(); }
    
    function historial_closeExportModal() {
        document.getElementById('historial-modal-export').style.display = 'none';
    }
    
    function historial_showExport(object_id) {
        document.getElementById('historial-modal-export').style.display = 'block';
        document.getElementById('export_object').value = '';
    }
    
    // EDITAR
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
    
    // DESCARGAR ARCHIVO
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
    
    // DESCARGAR PLANTILLA
    function historial_downloadTemplate() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = '<input type=\"hidden\" name=\"op\" value=\"historial_download_template\">';
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    // ELIMINAR
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
    </script>";
}

// =====================================================
// FUNCIONES - AGREGAR / EDITAR / ELIMINAR
// =====================================================

function historial_addFila($object_id, $current_user)
{
    global $dbxlink;

    // Validar evento
    if (!isset($_POST['evento']) || empty($_POST['evento'])) {
        echo "<div class='historial-error'><strong>Error:</strong> Debe ingresar un número de evento</div>";
        return;
    }

    $evento_str = $_POST['evento'];
    if (strpos($evento_str, '-') !== false) {
        $partes = explode('-', $evento_str);
        $evento_num = intval(end($partes));
    } else {
        $evento_num = intval($evento_str);
    }

    // Obtener usuario (select o custom)
    $usuario = !empty($_POST['usuario']) ? $_POST['usuario'] : (isset($_POST['usuario_custom']) && !empty($_POST['usuario_custom']) ? $_POST['usuario_custom'] : $current_user);
    historial_agregarUsuario($usuario);

    // Tipo evento
    $tipo_evento = !empty($_POST['tipo_evento']) ? $_POST['tipo_evento'] : (isset($_POST['tipo_evento_custom']) && !empty($_POST['tipo_evento_custom']) ? $_POST['tipo_evento_custom'] : '');
    
    if (empty($tipo_evento)) {
        echo "<div class='historial-error'><strong>Error:</strong> Debe seleccionar o escribir un tipo de evento</div>";
        return;
    }

    // Otros campos
    $equipo = isset($_POST['equipo']) ? $_POST['equipo'] : historial_getNombreObjeto($object_id);
    $fecha = isset($_POST['fecha']) ? str_replace('T', ' ', $_POST['fecha']) : date('Y-m-d H:i:s');
    $description = isset($_POST['description']) ? $_POST['description'] : '';
    $estado = !empty($_POST['estado']) ? $_POST['estado'] : (isset($_POST['estado_custom']) && !empty($_POST['estado_custom']) ? $_POST['estado_custom'] : '');
    $observaciones = isset($_POST['observaciones']) ? $_POST['observaciones'] : '';
    $referencia = isset($_POST['referencia']) ? $_POST['referencia'] : '';
    
    // Archivo
    $adjunto_nombre = null;
    if (isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] == UPLOAD_ERR_OK) {
        $file_name = $_FILES['adjunto']['name'];
        $file_tmp = $_FILES['adjunto']['tmp_name'];
        $file_size = $_FILES['adjunto']['size'];
        
        if ($file_size > HISTORIAL_MAX_FILE_SIZE) {
            echo "<div class='historial-error'><strong>Error:</strong> El archivo excede 10MB</div>";
            return;
        }
        
        $upload_dir = historial_getUploadDir();
        $adjunto_nombre = intval($object_id) . '-' . $evento_num . '_' . time() . '_' . basename($file_name);
        $file_path = $upload_dir . '/' . $adjunto_nombre;
        
        if (!move_uploaded_file($file_tmp, $file_path)) {
            echo "<div class='historial-error'><strong>Error:</strong> No se pudo subir el archivo</div>";
            return;
        }
    }

    try {
        // Verificar duplicado
        $stmt = $dbxlink->prepare("SELECT id FROM Historial WHERE object_id = :object_id AND evento = :evento");
        $stmt->execute(array(':object_id' => intval($object_id), ':evento' => $evento_num));
        if ($stmt->fetch()) {
            echo "<div class='historial-error'><strong>Error:</strong> El evento " . intval($object_id) . "-" . $evento_num . " ya existe</div>";
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

        echo "<div class='historial-success'><strong>✓ Evento " . intval($object_id) . "-" . $evento_num . " agregado exitosamente</strong></div>";
    } catch (PDOException $e) {
        echo "<div class='historial-error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function historial_editFila($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['id'])) {
        echo "<div class='historial-error'><strong>Error:</strong> ID no válido</div>";
        return;
    }

    $id = intval($_POST['id']);

    try {
        $stmt = $dbxlink->prepare("SELECT created_by FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            echo "<div class='historial-error'><strong>Error:</strong> Registro no encontrado</div>";
            return;
        }

        // Obtener usuario (select o custom)
        $usuario = !empty($_POST['usuario']) ? $_POST['usuario'] : (isset($_POST['usuario_custom']) && !empty($_POST['usuario_custom']) ? $_POST['usuario_custom'] : '');
        if (!empty($usuario)) {
            historial_agregarUsuario($usuario);
        }

        $tipo_evento = !empty($_POST['tipo_evento']) ? $_POST['tipo_evento'] : (isset($_POST['tipo_evento_custom']) && !empty($_POST['tipo_evento_custom']) ? $_POST['tipo_evento_custom'] : '');
        
        $stmt = $dbxlink->prepare("
            UPDATE Historial 
            SET evento = :evento, equipo = :equipo, fecha = :fecha, usuario = :usuario, tipo_evento = :tipo_evento, 
                description = :description, estado = :estado, observaciones = :observaciones, referencia = :referencia
            WHERE id = :id
        ");

/* NUEVO -------------------------------------------------------------------- */


        $stmt->execute(array(
            ':evento' => intval(end(explode('-', $_POST['evento']))),
            ':equipo' => $_POST['equipo'],
            ':fecha' => str_replace('T', ' ', $_POST['fecha']),
            ':usuario' => $usuario,
            ':tipo_evento' => $tipo_evento,
            ':description' => $_POST['description'],
            ':estado' => !empty($_POST['estado']) ? $_POST['estado'] : (isset($_POST['estado_custom']) ? $_POST['estado_custom'] : ''),
            ':observaciones' => $_POST['observaciones'],
            ':referencia' => $_POST['referencia'],
            ':id' => $id
        ));



/* NUEVO -------------------------------------------------------------------- */



/* ORIGINAL------------------------------------------------------------------

        $stmt->execute(array(
            ':evento' => intval(explode('-', $_POST['evento'])[1] ?? $_POST['evento']),
            ':equipo' => $_POST['equipo'],
            ':fecha' => str_replace('T', ' ', $_POST['fecha']),
            ':usuario' => $usuario,
            ':tipo_evento' => $tipo_evento,
            ':description' => $_POST['description'],
            ':estado' => !empty($_POST['estado']) ? $_POST['estado'] : (isset($_POST['estado_custom']) ? $_POST['estado_custom'] : ''),
            ':observaciones' => $_POST['observaciones'],
            ':referencia' => $_POST['referencia'],
            ':id' => $id
        ));


  ORIGINAL------------------------------------------------------------------ */



        echo "<div class='historial-success'><strong>✓ Registro actualizado exitosamente</strong></div>";
    } catch (PDOException $e) {
        echo "<div class='historial-error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function historial_deleteFila($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['id'])) {
        echo "<div class='historial-error'><strong>Error:</strong> ID no válido</div>";
        return;
    }

    $id = intval($_POST['id']);

    try {
        $stmt = $dbxlink->prepare("SELECT adjunto FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            echo "<div class='historial-error'><strong>Error:</strong> Registro no encontrado</div>";
            return;
        }

        // Eliminar archivo
        if ($record['adjunto']) {
            $file_path = historial_getUploadDir() . '/' . $record['adjunto'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        $stmt = $dbxlink->prepare("DELETE FROM Historial WHERE id = :id");
        $stmt->execute(array(':id' => $id));

        echo "<div class='historial-success'><strong>✓ Registro eliminado exitosamente</strong></div>";
    } catch (PDOException $e) {
        echo "<div class='historial-error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// =====================================================
// CARGA MASIVA
// =====================================================

function historial_procesarCargaMasiva($object_id, $current_user)
{
    global $dbxlink;
    
    if (!isset($_POST['bulk_data']) || empty($_POST['bulk_data'])) {
        echo "<div class='historial-error'><strong>Error:</strong> No hay datos para procesar</div>";
        return;
    }

    $lineas = explode("\n", $_POST['bulk_data']);
    $exito = 0;
    $error = 0;
    $errores = array();

    foreach ($lineas as $num_linea => $linea) {
        $linea = trim($linea);
        if (empty($linea) || substr($linea, 0, 1) === '#') continue;

        $campos = explode(';', $linea);
        if (count($campos) < 10) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Formato incorrecto (faltan campos)";
            $error++;
            continue;
        }

        // Extraer campos
        $evento_blank = trim($campos[0]);  // Debe estar vacío
        $equipo = trim($campos[1]);
        $fecha_str = trim($campos[2]);
        $usuario = trim($campos[3]);
        $tipo = trim($campos[4]);
        $descripcion = trim($campos[5]);
        $estado = trim($campos[6]);
        $observaciones = trim($campos[7]);
        // $adjunto = trim($campos[8]);  // Siempre vacío
        $referencia = trim($campos[9]);

        // Validaciones
        if (empty($equipo)) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Equipo vacío";
            $error++;
            continue;
        }

        if (empty($fecha_str)) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Fecha vacía";
            $error++;
            continue;
        }

        // Convertir fecha: DIA/MES/AÑO HORA:MINUTO
        $fecha_obj = DateTime::createFromFormat('d/m/Y H:i', $fecha_str);
        if (!$fecha_obj) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Fecha inválida (usar DIA/MES/AÑO HORA:MINUTO)";
            $error++;
            continue;
        }
        $fecha = $fecha_obj->format('Y-m-d H:i:s');

        if (empty($usuario)) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Usuario vacío";
            $error++;
            continue;
        }

        if (empty($tipo)) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Tipo de evento vacío";
            $error++;
            continue;
        }

        // Validar que equipo exista
        $stmt = $dbxlink->prepare("SELECT id FROM Object WHERE name = :name");
        $stmt->execute(array(':name' => $equipo));
        $equipo_record = $stmt->fetch();
        
        if (!$equipo_record) {
            $errores[] = "Línea " . ($num_linea + 1) . ": Equipo '$equipo' no existe en RackTables";
            $error++;
            continue;
        }

        $equipo_id = $equipo_record['id'];

        // Obtener próximo evento
        $stmt = $dbxlink->prepare("SELECT MAX(evento) as max_evento FROM Historial WHERE object_id = :object_id");
        $stmt->execute(array(':object_id' => $equipo_id));
        $result = $stmt->fetch();
        $evento = ($result['max_evento'] === null) ? 1001 : $result['max_evento'] + 1;

        // Agregar usuario
        historial_agregarUsuario($usuario);

        // Validar largos
        if (strlen($tipo) > 50) $tipo = substr($tipo, 0, 50);
        if (strlen($descripcion) > 200) $descripcion = substr($descripcion, 0, 200);
        if (strlen($estado) > 100) $estado = substr($estado, 0, 100);
        if (strlen($observaciones) > 200) $observaciones = substr($observaciones, 0, 200);
        if (strlen($referencia) > 20) $referencia = substr($referencia, 0, 20);

        // Insertar
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
            $errores[] = "Línea " . ($num_linea + 1) . ": " . $e->getMessage();
            $error++;
        }
    }

    // Mostrar resultado
    echo "<div class='historial-success'><strong>✓ Procesado: " . $exito . " eventos agregados</strong></div>";
    
    if ($error > 0) {
        echo "<div class='historial-error'><strong>⚠ Errores (" . $error . "):</strong><ul>";
        foreach ($errores as $err) {
            echo "<li>" . htmlspecialchars($err) . "</li>";
        }
        echo "</ul></div>";
    }
}

// =====================================================
// DESCARGAR / EXPORTAR
// =====================================================

function historial_downloadArchivo($object_id, $current_user)
{
    global $dbxlink;

    if (!isset($_POST['id'])) return;

    $id = intval($_POST['id']);

    try {
        $stmt = $dbxlink->prepare("SELECT adjunto FROM Historial WHERE id = :id AND object_id = :object_id");
        $stmt->execute(array(':id' => $id, ':object_id' => intval($object_id)));
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record || !$record['adjunto']) {
            echo "<div class='historial-error'><strong>Error:</strong> No hay archivo</div>";
            return;
        }

        $file_path = historial_getUploadDir() . '/' . $record['adjunto'];

        if (!file_exists($file_path)) {
            echo "<div class='historial-error'><strong>Error:</strong> Archivo no encontrado</div>";
            return;
        }

        $file_parts = explode('_', $record['adjunto']);
        $original_name = end($file_parts);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $original_name . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;

    } catch (PDOException $e) {
        echo "<div class='historial-error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function historial_descargarPlantilla()
{
    $template = "# CARGA MASIVA - PLANTILLA\n";
    $template .= "# Para hacer una carga masiva, debes generar un renglón por cada evento\n";
    $template .= "# Cada columna debe estar separada por ;\n";
    $template .= "# El campo Evento debe estar vacío (ej: ;;)\n\n";
    $template .= "Evento;Equipo;Fecha;Usuario;Tipo;Descripción;Estado;Observaciones;Adjuntos;Referencia;\n";
    $template .= ";;S1F1R01U41;08/03/2026 09:21;dcifuentes;Mudanza;Avisamos al NOC;;OT-123678;\n";
    $template .= ";;S1F1R01U41;08/03/2026 10:21;dcifuentes;Mudanza;Apagamos servidor;;OT-123678;\n";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_carga_masiva.csv"');
    echo $template;
    exit;
}

function historial_exportarCSV($object_id, $current_user)
{
    global $dbxlink;

    $export_object = isset($_POST['export_object']) && !empty($_POST['export_object']) ? $_POST['export_object'] : historial_getNombreObjeto($object_id);
    
    // Obtener ID del objeto por nombre
    $stmt = $dbxlink->prepare("SELECT id FROM Object WHERE name = :name");
    $stmt->execute(array(':name' => $export_object));
    $obj = $stmt->fetch();
    
    if (!$obj) {
        echo "<div class='historial-error'><strong>Error:</strong> Equipo no encontrado</div>";
        return;
    }

    $export_id = $obj['id'];

    // Obtener datos del objeto
    $stmt = $dbxlink->prepare("SELECT * FROM Object WHERE id = :id");
    $stmt->execute(array(':id' => intval($export_id)));
    $objeto = $stmt->fetch(PDO::FETCH_ASSOC);

    // Obtener historial
    $stmt = $dbxlink->prepare("
        SELECT * FROM Historial 
        WHERE object_id = :object_id 
        ORDER BY evento ASC
    ");
    $stmt->execute(array(':object_id' => intval($export_id)));
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generar CSV
    $csv = "INFORME DE EQUIPO\n";
    $csv .= "Generado: " . date('d/m/Y H:i:s') . "\n\n";
    
    $csv .= "=== INFORMACIÓN DEL EQUIPO ===\n";
    foreach ($objeto as $key => $value) {
        $csv .= "$key;$value\n";
    }

    $csv .= "\n=== HISTORIAL DE EVENTOS ===\n";
    $csv .= "Evento;Equipo;Fecha;Usuario;Tipo;Descripción;Estado;Observaciones;Adjuntos;Referencia\n";
    
    foreach ($historial as $h) {
        $evento_str = intval($export_id) . "-" . intval($h['evento']);
        $csv .= "\"$evento_str\";\"" . $h['equipo'] . "\";\"" . $h['fecha'] . "\";\"" . $h['usuario'] . "\";\"" . $h['tipo_evento'] . "\";\"" . $h['description'] . "\";\"" . $h['estado'] . "\";\"" . $h['observaciones'] . "\";\"" . ($h['adjunto'] ? 'SÍ' : 'NO') . "\";\"" . $h['referencia'] . "\"\n";
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="informe_' . $export_object . '_' . date('Ymd_His') . '.csv"');
    echo $csv;
    exit;
}

?>
