<?php
/**
 * Git Web UI - Aplicación web de archivo único para gestión de Git
 * Interfaz minimalista con operaciones AJAX y visualización de logs
 */

// Configuración de seguridad - solo en entorno de desarrollo
if (getenv("ENV") !== "dev") {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado - Solo disponible en entorno de desarrollo']);
    exit();
}

// Función para ejecutar comandos Git de forma segura
function executeGitCommand($command, $allowedCommands = []) {
    // Lista blanca de comandos permitidos
    $allowed = [
        'status' => 'git status --porcelain',
        'status-full' => 'git status',
        'add' => 'git add .',
        'add-file' => 'git add',
        'commit' => 'git commit -m',
        'fetch' => 'git fetch',
        'pull' => 'git pull',
        'push' => 'git push',
        'log' => 'git log --oneline -10',
        'branch' => 'git branch -a',
        'graph' => 'git log --graph --pretty=format:"%h|%ad|%s|%an" --date=short --stat --all -20',
        'clone' => 'git clone'
    ];

    // Validar comando
    if (!isset($allowed[$command])) {
        return ['success' => false, 'error' => 'Comando no permitido'];
    }

    $gitCommand = $allowed[$command];

    // Para comandos que requieren parámetros adicionales
    if ($command === 'add-file' && isset($_POST['files'])) {
        $fileList = explode(' ', $_POST['files']);
        $sanitizedFiles = [];
        foreach ($fileList as $file) {
            // Security: Prevent path traversal
            if (strpos($file, '..') === false && !empty($file)) {
                $sanitizedFiles[] = escapeshellarg($file);
            }
        }
        if(!empty($sanitizedFiles)){
            $gitCommand .= ' ' . implode(' ', $sanitizedFiles);
        } else {
            return ['success' => false, 'error' => 'No valid files provided.'];
        }
    }

    if ($command === 'commit' && isset($_POST['message'])) {
        $message = escapeshellarg($_POST['message']);
        $gitCommand .= ' ' . $message;
    }

    if ($command === 'clone' && isset($_POST['url'])) {
        $url = $_POST['url'];
        // Security: Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('/^(https?|git):\/\/.*/', $url)) {
            return ['success' => false, 'error' => 'Invalid Git URL provided.'];
        }
        $gitCommand .= ' ' . escapeshellarg($url) . ' ./cloned-repo';
    }

    // Ejecutar comando
    $output = [];
    $returnCode = 0;

    exec($gitCommand . ' 2>&1', $output, $returnCode);

    return [
        'success' => $returnCode === 0,
        'output' => implode("\n", $output),
        'returnCode' => $returnCode,
        'command' => $gitCommand
    ];
}

// Manejar peticiones AJAX
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    $result = executeGitCommand($action);

    // Añadir timestamp
    $result['timestamp'] = date('H:i:s');

    echo json_encode($result);
    exit();
}

// Función para obtener información del repositorio
function getRepoInfo() {
    $info = [];

    // Verificar si es un repositorio Git
    if (is_dir('.git')) {
        $info['is_repo'] = true;

        // Obtener rama actual
        $output = [];
        exec('git branch --show-current 2>/dev/null', $output);
        $info['current_branch'] = trim(implode('', $output)) ?: 'main';

        // Obtener estado
        $output = [];
        exec('git status --porcelain 2>/dev/null', $output);
        $info['has_changes'] = !empty($output);

        // Contar cambios
        $staged = 0;
        $unstaged = 0;
        foreach ($output as $line) {
            if (strpos($line, ' ') === 0) {
                $staged++;
            } else {
                $unstaged++;
            }
        }
        $info['staged_count'] = $staged;
        $info['unstaged_count'] = $unstaged;

    } else {
        $info['is_repo'] = false;
    }

    return $info;
}

$repoInfo = getRepoInfo();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Git Web UI - Control de Versiones</title>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10.6.1/dist/mermaid.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 10px;
            font-size: 2.5em;
        }

        .repo-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .info-badge {
            background: #3498db;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
        }

        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .panel h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        .git-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }

        .clone-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            margin-bottom: 15px;
        }

        .clone-form input {
            flex: 1;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .clone-form input:focus {
            outline: none;
            border-color: #3498db;
        }

        .file-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 15px;
        }

        .file-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 5px;
        }

        .file-item:hover {
            background: #e9ecef;
        }

        .file-checkbox {
            margin-right: 10px;
        }

        .file-status {
            font-size: 12px;
            color: #666;
            margin-left: auto;
        }

        .file-status.modified { color: #f39c12; }
        .file-status.added { color: #27ae60; }
        .file-status.deleted { color: #e74c3c; }
        .file-status.renamed { color: #9b59b6; }

        .btn {
            background: linear-gradient(45deg, #3498db, #2980b9);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn.danger {
            background: linear-gradient(45deg, #e74c3c, #c0392b);
        }

        .btn.success {
            background: linear-gradient(45deg, #27ae60, #229954);
        }

        .btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
        }

        .commit-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .commit-form input {
            flex: 1;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .commit-form input:focus {
            outline: none;
            border-color: #3498db;
        }

        .logs-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            height: 400px;
            overflow-y: auto;
        }

        .logs-section h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        .log-entry {
            background: #f8f9fa;
            border-left: 4px solid #3498db;
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 0 8px 8px 0;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .log-entry.success {
            border-left-color: #27ae60;
            background: #d4edda;
        }

        .log-entry.error {
            border-left-color: #e74c3c;
            background: #f8d7da;
        }

        .log-entry .timestamp {
            color: #666;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .log-entry .command {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-indicator.success {
            background: #27ae60;
        }

        .status-indicator.error {
            background: #e74c3c;
        }

        .status-indicator.loading {
            background: #f39c12;
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .graph-container {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }

            .repo-info {
                flex-direction: column;
                align-items: center;
            }
        }

        .not-repo {
            text-align: center;
            color: #e74c3c;
            font-size: 1.2em;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 Git Web UI</h1>
            <?php if ($repoInfo['is_repo']): ?>
            <div class="repo-info">
                <div class="info-badge">
                    <strong>Rama:</strong> <?php echo htmlspecialchars($repoInfo['current_branch']); ?>
                </div>
                <div class="info-badge">
                    <strong>Cambios:</strong> <?php echo $repoInfo['unstaged_count']; ?> sin stage / <?php echo $repoInfo['staged_count']; ?> staged
                </div>
            </div>
            <?php else: ?>
            <div class="not-repo">
                ⚠️ No se detectó un repositorio Git en este directorio
            </div>
            <?php endif; ?>
        </div>

        <?php if ($repoInfo['is_repo']): ?>
        <div class="main-content">
            <div class="panel">
                <h2>📋 Acciones Git</h2>
                <div class="clone-form">
                    <input type="url" id="cloneUrl" placeholder="URL del repositorio Git..." maxlength="500">
                    <button class="btn" onclick="cloneRepository()">
                        📥 Clone
                    </button>
                </div>

                <div class="git-actions">
                    <button class="btn" onclick="executeCommand('status')">
                        📊 Status
                    </button>
                    <button class="btn" onclick="showAddDialog()">
                        ➕ Add Selectivo
                    </button>
                    <button class="btn" onclick="executeCommand('add')">
                        ➕ Add All
                    </button>
                    <button class="btn" onclick="executeCommand('fetch')">
                        📥 Fetch
                    </button>
                    <button class="btn" onclick="executeCommand('pull')">
                        ⬇️ Pull
                    </button>
                    <button class="btn" onclick="executeCommand('push')">
                        ⬆️ Push
                    </button>
                    <button class="btn success" onclick="executeCommand('log')">
                        📜 Historial
                    </button>
                    <button class="btn" onclick="executeCommand('branch')">
                        🌿 Ramas
                    </button>
                    <button class="btn" onclick="executeCommand('graph')">
                        📈 Gráfico
                    </button>
                </div>

                <div class="commit-form">
                    <input type="text" id="commitMessage" placeholder="Mensaje del commit..." maxlength="100">
                    <button class="btn success" onclick="commitChanges()">
                        💾 Commit
                    </button>
                </div>

                <!-- Modal para selección de archivos -->
                <div id="addModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 15px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                        <h3>Seleccionar archivos para añadir</h3>
                        <div id="fileList" class="file-list">
                            <!-- Los archivos se cargarán aquí -->
                        </div>
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                            <button class="btn" onclick="closeAddModal()">Cancelar</button>
                            <button class="btn success" onclick="addSelectedFiles()">Añadir Seleccionados</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>📊 Estado del Repositorio</h2>
                <div id="repo-status">
                    <div class="loading">
                        <div class="spinner"></div>
                        Cargando estado...
                    </div>
                </div>
            </div>
        </div>

        <div class="logs-section">
            <h2>📝 Historial de Comandos</h2>
            <div id="logs-container">
                <div class="log-entry">
                    <div class="timestamp"><?php echo date('H:i:s'); ?></div>
                    <div class="command">Sistema inicializado</div>
                    <div>Interfaz Git Web UI lista para usar</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Inicializar Mermaid
        mermaid.initialize({ startOnLoad: true, theme: 'default' });

        // Función para ejecutar comandos Git
        function executeCommand(action) {
            const button = event.target;
            const originalText = button.innerHTML;

            // Deshabilitar botón y mostrar loading
            button.disabled = true;
            button.innerHTML = '<div class="spinner"></div>Ejecutando...';

            // Crear FormData
            const formData = new FormData();
            formData.append('action', action);

            // Enviar petición AJAX
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                // Restaurar botón
                button.disabled = false;
                button.innerHTML = originalText;

                // Añadir entrada al log
                addLogEntry(data);

                // Actualizar estado si es necesario
                if (action === 'status' || action === 'add' || action === 'commit') {
                    loadRepoStatus();
                }

                // Mostrar gráfico si es comando graph
                if (action === 'graph' && data.success) {
                    showGraph(data.output);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                button.disabled = false;
                button.innerHTML = originalText;

                addLogEntry({
                    success: false,
                    command: 'Error de conexión',
                    output: 'No se pudo conectar con el servidor',
                    timestamp: new Date().toLocaleTimeString()
                });
            });
        }

        // Función para hacer commit
        function commitChanges() {
            const message = document.getElementById('commitMessage').value.trim();
            if (!message) {
                alert('Por favor, introduce un mensaje de commit');
                return;
            }

            const button = event.target;
            const originalText = button.innerHTML;

            button.disabled = true;
            button.innerHTML = '<div class="spinner"></div>Commit...';

            const formData = new FormData();
            formData.append('action', 'commit');
            formData.append('message', message);

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                button.disabled = false;
                button.innerHTML = originalText;

                if (data.success) {
                    document.getElementById('commitMessage').value = '';
                }

                addLogEntry(data);
                loadRepoStatus();
            })
            .catch(error => {
                console.error('Error:', error);
                button.disabled = false;
                button.innerHTML = originalText;
            });
        }

        // Función para añadir entrada al log
        function addLogEntry(data) {
            const logsContainer = document.getElementById('logs-container');
            const logEntry = document.createElement('div');
            logEntry.className = `log-entry ${data.success ? 'success' : 'error'}`;

            const statusIndicator = data.success ?
                '<span class="status-indicator success"></span>' :
                '<span class="status-indicator error"></span>';

            logEntry.innerHTML = `
                ${statusIndicator}
                <div class="timestamp">${data.timestamp || new Date().toLocaleTimeString()}</div>
                <div class="command">${data.command || 'Comando desconocido'}</div>
                <div>${data.output || data.error || 'Sin salida'}</div>
            `;

            logsContainer.insertBefore(logEntry, logsContainer.firstChild);

            // Limitar a 50 entradas
            while (logsContainer.children.length > 50) {
                logsContainer.removeChild(logsContainer.lastChild);
            }

            // Auto-scroll al inicio
            logsContainer.scrollTop = 0;
        }

        // Función para cargar estado del repositorio
        function loadRepoStatus() {
            const statusContainer = document.getElementById('repo-status');
            statusContainer.innerHTML = '<div class="loading"><div class="spinner"></div>Cargando estado...</div>';

            fetch(window.location.href + '?action=status', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                // Esta es una simplificación - en producción usarías un endpoint separado
                executeCommand('status');
            })
            .catch(error => {
                console.error('Error cargando estado:', error);
            });
        }

        // Función para mostrar gráfico de Git
        function showGraph(output) {
            const graphContainer = document.createElement('div');
            graphContainer.className = 'graph-container';
            graphContainer.innerHTML = '<h3>📈 Gráfico de Ramas</h3><div class="mermaid">' + convertGitLogToMermaid(output) + '</div>';

            // Insertar después del panel de acciones
            const mainContent = document.querySelector('.main-content');
            const existingGraph = mainContent.querySelector('.graph-container');
            if (existingGraph) {
                existingGraph.remove();
            }
            mainContent.appendChild(graphContainer);

            // Re-inicializar Mermaid
            mermaid.init(undefined, graphContainer.querySelector('.mermaid'));
        }

        // Función para convertir log de Git a formato Mermaid
        function convertGitLogToMermaid(gitLog) {
            const lines = gitLog.split('\n');
            let mermaidCode = 'gitGraph\n';
            let branchCounter = 0;
            const branchMap = new Map();

            lines.forEach(line => {
                const parts = line.split('|');
                if (parts.length === 4) {
                    const [graph, date, message, author] = parts;
                    const commitHash = graph.match(/\b[0-9a-f]{7}\b/);
                    if (commitHash) {
                        const commitId = commitHash[0];
                        
                        // Extraer estadísticas de archivos si están disponibles
                        const nextLineIndex = lines.indexOf(line) + 1;
                        let statsInfo = '';
                        if (nextLineIndex < lines.length && lines[nextLineIndex].includes('files changed')) {
                            const statsLine = lines[nextLineIndex];
                            const fileStats = statsLine.match(/(\d+)\s+files?\s+changed/);
                            if(fileStats) {
                                statsInfo = ` (${fileStats[0]})`;
                            }
                        }

                        mermaidCode += `  commit id: "${commitId}" msg: "${message.trim()}${statsInfo}"\n`;
                    }
                } else if (line.trim().startsWith('Merge:')) {
                    // Mermaid `merge` command needs a branch name
                    // This is a simplified representation
                    if (branchMap.size > 1) {
                        const branches = Array.from(branchMap.keys());
                        mermaidCode += `  merge ${branches[1]}\n`;
                    }
                } else if (line.trim().startsWith('*')) {
                     // Simple commit line without all details
                     const commitHash = line.match(/\b[0-9a-f]{7}\b/);
                     if(commitHash){
                        mermaidCode += `  commit id: "${commitHash[0]}"\n`;
                     }
                }
            });

            if (mermaidCode === 'gitGraph\n') {
                return 'gitGraph\n  commit id: "No commits found"\n';
            }

            return mermaidCode;
        }

        // Función para mostrar diálogo de add selectivo
        function showAddDialog() {
            const modal = document.getElementById('addModal');
            const fileList = document.getElementById('fileList');

            // Obtener archivos modificados
            fetch(window.location.href, {
                method: 'POST',
                body: new FormData(),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const files = parseGitStatus(data.output);
                    fileList.innerHTML = files.map(file => `
                        <div class="file-item">
                            <input type="checkbox" class="file-checkbox" value="${file.name}" id="file-${file.name.replace(/[^a-zA-Z0-9]/g, '-')}">
                            <label for="file-${file.name.replace(/[^a-zA-Z0-9]/g, '-')}">${file.name}</label>
                            <span class="file-status ${file.status}">${file.status}</span>
                        </div>
                    `).join('');
                    modal.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error obteniendo archivos:', error);
            });
        }

        // Función para parsear salida de git status
        function parseGitStatus(output) {
            const lines = output.split('\n');
            const files = [];

            lines.forEach(line => {
                const trimmed = line.trim();
                if (trimmed && !trimmed.startsWith('#') && trimmed.length > 2) {
                    let status = 'modified';
                    let name = trimmed;

                    if (trimmed.startsWith('M ')) {
                        status = 'modified';
                        name = trimmed.substring(2);
                    } else if (trimmed.startsWith('A ')) {
                        status = 'added';
                        name = trimmed.substring(2);
                    } else if (trimmed.startsWith('D ')) {
                        status = 'deleted';
                        name = trimmed.substring(2);
                    } else if (trimmed.startsWith('R ')) {
                        status = 'renamed';
                        name = trimmed.substring(2);
                    } else if (trimmed.startsWith('?? ')) {
                        status = 'added';
                        name = trimmed.substring(3);
                    }

                    files.push({ name: name.trim(), status });
                }
            });

            return files;
        }

        // Función para añadir archivos seleccionados
        function addSelectedFiles() {
            const checkboxes = document.querySelectorAll('.file-checkbox:checked');
            const files = Array.from(checkboxes).map(cb => cb.value);

            if (files.length === 0) {
                alert('Selecciona al menos un archivo');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add-file');
            formData.append('files', files.join(' '));

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                addLogEntry(data);
                closeAddModal();
                loadRepoStatus();
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Función para cerrar modal
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }

        // Función para clonar repositorio
        function cloneRepository() {
            const url = document.getElementById('cloneUrl').value.trim();
            if (!url) {
                alert('Introduce una URL válida');
                return;
            }

            const button = event.target;
            const originalText = button.innerHTML;

            button.disabled = true;
            button.innerHTML = '<div class="spinner"></div>Clonando...';

            const formData = new FormData();
            formData.append('action', 'clone');
            formData.append('url', url);

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                button.disabled = false;
                button.innerHTML = originalText;

                if (data.success) {
                    document.getElementById('cloneUrl').value = '';
                }

                addLogEntry(data);
                // Recargar página para mostrar nuevo repo si fue exitoso
                if (data.success) {
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                button.disabled = false;
                button.innerHTML = originalText;
            });
        }

        // Cargar estado inicial
        <?php if ($repoInfo['is_repo']): ?>
        loadRepoStatus();
        <?php endif; ?>

        // Auto-refresh del estado cada 30 segundos
        setInterval(() => {
            <?php if ($repoInfo['is_repo']): ?>
            loadRepoStatus();
            <?php endif; ?>
        }, 30000);
    </script>
</body>
</html>
