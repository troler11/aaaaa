<?php
// 1. INÍCIO E CONFIGURAÇÕES (Sem HTML)
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php'; 
// Se page_logic.php tiver HTML ou espaços em branco, pode causar erro. 
// O ideal é que ele só tenha código PHP.
require_once 'includes/page_logic.php'; 

// 2. VERIFICAÇÃO DE SEGURANÇA
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    // Se for AJAX e não estiver logado, retorna erro JSON
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'auth_required']);
        exit;
    }
    header("Location: login.php"); exit;
}
session_write_close();
verificarPermissaoPagina('escala');

// 3. DEFINIÇÃO DE CONSTANTES
define('GOOGLE_SCRIPT_URL', 'https://script.google.com/macros/s/AKfycbxpJjRQ0KhIQtHA36CD_cugZyQD1GrftfIahwqxV9Nqxx1jnF5T2bt0tQgNM0kWfRArrQ/exec'); 
define('CACHE_TIME', 60); 
date_default_timezone_set('America/Sao_Paulo');

$data_filtro = $_GET['data'] ?? date('d/m/Y'); 
$nome_arquivo_cache = 'cache_frota_' . str_replace('/', '-', $data_filtro) . '.json';

// --- HELPERS ---
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function getDadosJsonCurl($scriptUrl, $data, $cacheFile, $cacheTime) {
    // Tenta ler do cache primeiro
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $conteudo = @file_get_contents($cacheFile);
        if ($conteudo) return json_decode($conteudo, true);
    }
    
    $ch = curl_init();
    // Importante: Passamos a action=read e a data
    curl_setopt($ch, CURLOPT_URL, $scriptUrl . "?action=read&data=" . urlencode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Aumentei timeout para garantir
    
    // Se seu servidor tiver problemas com SSL antigo, descomente as linhas abaixo:
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && $response) {
        $json = json_decode($response, true);
        // Só salva em cache se for um array válido e NÃO tiver erro
        if (is_array($json) && !isset($json['error'])) {
            file_put_contents($cacheFile, $response);
            return $json;
        }
    }
    
    // Fallback: tenta cache antigo se a conexão falhar
    if (file_exists($cacheFile)) return json_decode(file_get_contents($cacheFile), true);
    return [];
}

function processarDados($rows) {
    if (!is_array($rows) || isset($rows['error']) || empty($rows)) return [];
    
    // Normaliza cabeçalho para minúsculo
    $header = array_map('mb_strtolower', array_map('trim', $rows[0]));
    
    // Função auxiliar para achar índice da coluna
    $findCol = function($keywords) use ($header) {
        foreach ($header as $idx => $colName) {
            foreach ($keywords as $key) { if (strpos($colName, $key) !== false) return $idx; }
        } return -1;
    };

    // Mapeamento das colunas (Adicione variações de nomes aqui se precisar)
    $map = [
        'empresa' => $findCol(['clientes', 'cliente', 'empresa']), 
        'rota' => $findCol(['rota', 'linha', 'itinerario']),
        'motorista' => $findCol(['motorista', 'condutor', 'mot']), 
        'reserva' => $findCol(['reserva']),
        'escala' => $findCol(['escala', 'veiculo escala']), 
        'enviada' => $findCol(['enviada', 'veiculo enviado']),
        'prog' => $findCol(['ini', 'inicio', 'prog']), 
        'real' => $findCol(['real', 'realizado', 'chegada']),
        'obs' => $findCol(['observação', 'obs', 'ocorrencia']), 
        'manut' => $findCol(['manutenção', 'manut']),
        'carro' => $findCol(['aguardando', 'carro']), 
        'ra' => $findCol(['ra', 'r.a', 'registro'])
    ];

    $dados = [];
    $limparHorario = fn($val) => empty($val) ? '' : substr(trim($val), 0, 5);

    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        
        // Pula linhas vazias onde não tem empresa nem rota
        if (empty($r[$map['empresa']] ?? '') && empty($r[$map['rota']] ?? '')) continue;
        
        $empresa = isset($r[$map['empresa']]) ? trim($r[$map['empresa']]) : '---';
        
        // Bloqueio de Empresas Específicas
        $empresaCheck = mb_strtoupper($empresa, 'UTF-8');
        $empresasIgnoradas = ['VIACAO MIMO VARZEA', 'VIAÇÃO MIMO VARZEA', 'VIACAO MIMO', 'VIAÇÃO MIMO'];
        if (in_array($empresaCheck, $empresasIgnoradas)) continue;

        $valManut = $r[$map['manut']] ?? '';
        $valCarro = $r[$map['carro']] ?? '';

        $dados[] = [
            'empresa' => $empresa,
            'rota' => $r[$map['rota']] ?? '---',
            'motorista' => $r[$map['motorista']] ?? 'Não Definido',
            'reserva' => $r[$map['reserva']] ?? '',
            'frota_escala' => $r[$map['escala']] ?? '---',
            'frota_enviada' => $r[$map['enviada']] ?? '---',
            'h_prog' => $limparHorario($r[$map['prog']] ?? ''),
            'h_real' => $limparHorario($r[$map['real']] ?? ''),
            'obs' => $r[$map['obs']] ?? '',
            'ra_val' => $r[$map['ra']] ?? '',
            'manutencao' => (stripos($valManut, 'sim') !== false || stripos($valManut, 'manuten') !== false),
            'aguardando' => (stripos($valCarro, 'sim') !== false || stripos($valCarro, 'aguard') !== false),
        ];
    }
    return $dados;
}

// 4. BLOCO AJAX (EXECUTADO ANTES DE QUALQUER HTML)
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    // Garbage Collector de Cache
    if (rand(1, 100) === 1) {
        $files = glob('cache_frota_*.json');
        if ($files) {
            $now = time();
            foreach ($files as $file) { if ($now - filemtime($file) > 172800) @unlink($file); }
        }
    }
    // Force Refresh
    if (isset($_GET['force_refresh']) && $_GET['force_refresh'] == '1') {
        if (file_exists($nome_arquivo_cache)) @unlink($nome_arquivo_cache); 
    }

    // Busca dados
    $raw_data = getDadosJsonCurl(GOOGLE_SCRIPT_URL, $data_filtro, $nome_arquivo_cache, CACHE_TIME);
    $lista_dados = processarDados($raw_data);

    // Ordenação Padrão
    usort($lista_dados, function($a, $b) {
        if ($a['h_prog'] == $b['h_prog']) return 0;
        return ($a['h_prog'] < $b['h_prog']) ? -1 : 1;
    });

    // Limpeza de buffer segura
    while (ob_get_level()) { @ob_end_clean(); }

    header('Content-Type: application/json');
    $currentHash = md5(json_encode($lista_dados));
    $clientHash = $_GET['last_hash'] ?? '';

    if ($clientHash === $currentHash) {
        echo json_encode(['status' => 'no_change']);
    } else {
        echo json_encode(['status' => 'updated', 'hash' => $currentHash, 'dados' => $lista_dados]);
    }
    exit; // ENCERRA O SCRIPT AQUI. Não carrega o HTML abaixo.
}

// 5. CARGA INICIAL (SOMENTE HTML)
require_once 'menus.php'; 

// Envia qualquer buffer pendente
if(ob_get_level()) ob_end_flush();

$lista_dados = []; // Inicia vazio para o JS preencher via AJAX
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIMO - Painel de Frota</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar .logo-container img{transition:all .3s;max-width:160px}
        .sidebar.toggled .logo-container img{max-width:50px}
        :root { --sidebar-bg: #0f172a; --sidebar-width: 260px; --card-blue: #1e3a8a; --card-green: #10b981; --card-amber: #f59e0b; --alert-red: #dc2626; --alert-orange: #f97316; --alert-purple: #7e22ce; }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .sidebar { background-color: #0b1f3a; color: white; min-height: 100vh; width: 250px; position: fixed; z-index: 1000; transition: width 0.3s ease; overflow-y: auto; }
        .sidebar a { color: #d1d5db; display: flex; align-items: center; padding: 14px 20px; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; white-space: nowrap; overflow: hidden; }
        .sidebar.toggled { width: 80px; }
        .sidebar.toggled a span { display: none; } 
        .sidebar.toggled a { justify-content: center; } 
        .sidebar.toggled a i { margin-right: 0 !important; }
        .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; border-left-color: #0d6efd; }
        .content { margin-left: 250px; padding: 30px; transition: margin-left 0.3s ease; }
        .content.toggled { margin-left: 80px; }
        .top-header { background: white; padding: 1rem 2rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 900; }
        
        .kpi-card { border-radius: 12px; padding: 1.5rem; color: white; text-align: center; height: 100%; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .kpi-title { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; opacity: 0.9; margin-bottom: 0.5rem; }
        .kpi-value { font-size: 2.5rem; font-weight: 800; line-height: 1; }
        .bg-mimo-blue { background-color: var(--card-blue); } .bg-mimo-green { background-color: var(--card-green); } .bg-mimo-amber { background-color: var(--card-amber); }
        
        .kpi-alert-card { background: white; border-radius: 10px; padding: 1rem; display: flex; align-items: center; justify-content: space-between; border-left: 5px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .alert-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .alert-number { font-size: 1.8rem; font-weight: 800; color: #334155; }
        .border-red { border-left-color: var(--alert-red); } .text-red { color: var(--alert-red); }
        .border-orange { border-left-color: var(--alert-orange); } .text-orange { color: var(--alert-orange); }
        .border-purple { border-left-color: var(--alert-purple); } .text-purple { color: var(--alert-purple); }
        
        .card-table { background: white; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden; }
        .table-custom thead th { background-color: #f8fafc; color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; padding: 1rem; border-bottom: 1px solid #e2e8f0; cursor: pointer; user-select: none; }
        .table-custom thead th:hover { background-color: #e2e8f0; color: #334155; }
        .table-custom tbody td { padding: 1rem; vertical-align: middle; color: #334155; font-size: 0.875rem; border-bottom: 1px solid #f1f5f9; }
        
        .badge-status-pendente { background-color: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .badge-status-ok { background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-status-erro { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .divergent-text { color: #dc2626; font-weight: 700; text-decoration: underline; }
        
        .search-input { border-radius: 20px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 5px 15px 5px 35px; width: 250px; }
        .search-wrapper { position: relative; }
        .search-wrapper i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        
        .progress-container { height: 4px; width: 100%; background-color: #f1f5f9; position: relative; }
        .progress-bar-custom { height: 100%; background-color: #10b981; width: 100%; transition: width 1s linear; }
        
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-online { background-color: #10b981; box-shadow: 0 0 5px #10b981; }
        .status-offline { background-color: #ef4444; box-shadow: 0 0 5px #ef4444; }
        .text-atraso { color: #dc2626; font-weight: 700; }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column" id="sidebar">
    <div class="text-center py-4 bg-dark bg-opacity-25 logo-container">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo">
    </div>
    <?php foreach ($menu_itens as $chave => $item): 
        if ((($_SESSION['user_role'] ?? '') === 'admin') || in_array($chave, $permissoes_usuario)): ?>
        <a href="<?php echo $item['link']; ?>" class="<?php echo ($pagina_atual == $item['link']) ? 'active' : ''; ?>">
            <i class="bi <?php echo $item['icon']; ?> me-2"></i><span><?php echo $item['label']; ?></span>
        </a>
    <?php endif; endforeach; ?>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary"><i class="bi bi-box-arrow-right me-2"></i><span>Sair</span></a>
</div>

<div class="content" id="content">
    <header class="top-header">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light btn-sm shadow-sm" id="btnToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-bold text-dark">Visão Geral das Linhas</h5>
                <div class="d-flex align-items-center small text-muted">
                    <span id="connStatus" class="status-dot status-online"></span>
                    <span id="connText">Online</span>
                    <span class="mx-2">•</span>
                    Data: <strong><?php echo h($data_filtro); ?></strong>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-success btn-sm" onclick="exportToCSV()" title="Baixar Excel"><i class="bi bi-file-earmark-spreadsheet"></i> Exportar</button>
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleFullScreen()" title="Modo TV"><i class="bi bi-arrows-fullscreen"></i></button>
            
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="data" class="form-control form-control-sm" value="<?php echo h($data_filtro); ?>" style="width: 120px;" placeholder="dd/mm/aaaa">
                <button class="btn btn-dark btn-sm">Ir</button>
            </form>
            <div class="search-wrapper"><i class="bi bi-search"></i><input type="text" id="searchInput" class="search-input" placeholder="Buscar na tela..."></div>
        </div>
    </header>

    <div class="p-4">
        <div class="row g-4 mb-3">
            <div class="col-md-4"><div class="kpi-card bg-mimo-blue"><div class="kpi-title">Total de Linhas</div><div class="kpi-value" id="kpi-total">0</div></div></div>
            <div class="col-md-4"><div class="kpi-card bg-mimo-green"><div class="kpi-title">Confirmadas (RA)</div><div class="kpi-value" id="kpi-confirmadas">0</div></div></div>
            <div class="col-md-4"><div class="kpi-card bg-mimo-amber"><div class="kpi-title">Pendentes</div><div class="kpi-value" id="kpi-pendentes">0</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-4"><div class="kpi-alert-card border-red"><div><div class="alert-label text-red">Em Manutenção</div><div class="alert-number text-red" id="kpi-manutencao">0</div></div><div class="alert-icon text-red"><i class="bi bi-wrench"></i></div></div></div>
            <div class="col-md-4"><div class="kpi-alert-card border-orange"><div><div class="alert-label text-orange">Aguardando Carro</div><div class="alert-number text-orange" id="kpi-aguardando">0</div></div><div class="alert-icon text-orange"><i class="bi bi-cone-striped"></i></div></div></div>
            <div class="col-md-4"><div class="kpi-alert-card border-purple"><div><div class="alert-label text-purple">Cobrir</div><div class="alert-number text-purple" id="kpi-cobrir">0</div></div><div class="alert-icon text-purple"><i class="bi bi-exclamation-triangle-fill"></i></div></div></div>
        </div>

        <div class="bg-white p-3 rounded shadow-sm border mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1">Empresa</label>
                    <select id="filtroEmpresa" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <!-- Options serão populadas via JS -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1">Status (Visual)</label>
                    <select id="filtroStatus" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="pendente">Aguardando</option>
                        <option value="confirmado">Confirmado</option>
                        <option value="manutencao">Manutenção</option>
                        <option value="cobrir">Cobrir</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-table" id="cardTable">
            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">Operação</h6>
                <small class="text-muted"><i class="bi bi-arrow-down-up"></i> Clique nos títulos para ordenar</small>
            </div>
            <div class="progress-container"><div class="progress-bar-custom" id="progressBar"></div></div>
            <div class="table-responsive">
                <table class="table table-custom mb-0 w-100">
                    <thead>
                        <tr>
                            <th onclick="sortTable('empresa')">Empresa / Rota <i class="bi bi-sort-alpha-down small ms-1"></i></th>
                            <th class="text-center" onclick="sortTable('frota_escala')">Frotas <i class="bi bi-sort-numeric-down small ms-1"></i></th>
                            <th onclick="sortTable('motorista')">Motorista <i class="bi bi-sort-alpha-down small ms-1"></i></th>
                            <th>Detalhes & Obs</th>
                            <th class="text-center" onclick="sortTable('status')">Status <i class="bi bi-sort-down small ms-1"></i></th>
                            <th class="text-end" onclick="sortTable('h_prog')">Horário <i class="bi bi-clock small ms-1"></i></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-veiculos">
                        <tr><td colspan="6" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted">Carregando dados...</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- ESTADO GLOBAL ---
    // Inicializa vazio, será populado via AJAX
    let DADOS_GLOBAIS = []; 
    let currentHash = '';
    let timeLeft = 60;
    let filterTimeout;
    let sortConfig = { key: 'h_prog', order: 'asc' }; 

    // Elementos
    const els = {
        tbody: document.getElementById('tabela-veiculos'),
        search: document.getElementById('searchInput'),
        empresa: document.getElementById('filtroEmpresa'),
        status: document.getElementById('filtroStatus'),
        progress: document.getElementById('progressBar'),
        card: document.getElementById('cardTable'),
        kpis: {
            total: document.getElementById('kpi-total'),
            confirmados: document.getElementById('kpi-confirmadas'),
            pendentes: document.getElementById('kpi-pendentes'),
            manutencao: document.getElementById('kpi-manutencao'),
            aguardando: document.getElementById('kpi-aguardando'),
            cobrir: document.getElementById('kpi-cobrir')
        },
        connDot: document.getElementById('connStatus'),
        connText: document.getElementById('connText')
    };

    // --- FUNÇÃO PARA POPULAR FILTRO DE EMPRESAS ---
    function atualizarFiltroEmpresas() {
        const select = els.empresa;
        const valorAtual = select.value;
        const empresas = new Set();
        
        // Coleta empresas únicas dos dados carregados
        DADOS_GLOBAIS.forEach(row => {
            if(row.empresa !== '---') empresas.add(row.empresa);
        });
        
        // Converte para array e ordena
        const empresasArray = Array.from(empresas).sort();

        // Mantém a opção "Todas" e reconstrói as outras
        select.innerHTML = '<option value="">Todas</option>';
        
        empresasArray.forEach(emp => {
            const option = document.createElement('option');
            option.value = emp;
            option.text = emp;
            if(emp === valorAtual) option.selected = true; // Mantém seleção
            select.appendChild(option);
        });
    }

    // --- ORDENAÇÃO ---
    function sortTable(key) {
        if (sortConfig.key === key) {
            sortConfig.order = sortConfig.order === 'asc' ? 'desc' : 'asc';
        } else {
            sortConfig.key = key;
            sortConfig.order = 'asc';
        }
        renderizarApp();
    }

    function applySort(data) {
        return data.sort((a, b) => {
            let valA = a[sortConfig.key] || '';
            let valB = b[sortConfig.key] || '';

            if (sortConfig.key === 'status') {
                valA = a.manutencao ? '3' : (a.ra_val ? '2' : '1');
                valB = b.manutencao ? '3' : (b.ra_val ? '2' : '1');
            }

            if (valA < valB) return sortConfig.order === 'asc' ? -1 : 1;
            if (valA > valB) return sortConfig.order === 'asc' ? 1 : -1;
            return 0;
        });
    }

    // --- UTILS ---
    function toggleFullScreen() {
        if (!document.fullscreenElement) { document.documentElement.requestFullscreen(); } 
        else { if (document.exitFullscreen) document.exitFullscreen(); }
    }

    function exportToCSV() {
        const empresaSel = els.empresa.value;
        const statusSel = els.status.value;
        const termo = els.search.value.toLowerCase();
        
        let csvContent = "data:text/csv;charset=utf-8,\uFEFF"; // BOM para Excel
        csvContent += "Empresa;Rota;Motorista;Reserva;Frota Escala;Frota Enviada;Status;H. Prog;H. Real;Obs;RA\n";

        DADOS_GLOBAIS.forEach(row => {
            if (empresaSel && row.empresa !== empresaSel) return;
            const obsTexto = (row.obs || '').toString();
            const realizou = (row.ra_val && String(row.ra_val).trim() !== '');
            const isCobrir = obsTexto.toLowerCase().includes('cobrir');
            let statusKey = 'pendente';
            if (row.manutencao) statusKey = 'manutencao';
            else if (realizou) statusKey = 'confirmado';
            
            if (statusSel) {
                if (statusSel === 'cobrir') { if (!isCobrir) return; }
                else if (statusSel !== statusKey) return;
            }
            const searchText = `${row.empresa} ${row.rota} ${row.motorista} ${row.frota_escala} ${row.frota_enviada} ${obsTexto}`.toLowerCase();
            if (termo && !searchText.includes(termo)) return;

            let statusLabel = row.manutencao ? 'Manutencao' : (realizou ? 'Confirmado' : 'Aguardando');
            // CSV Escape
            const escape = (t) => `"${String(t).replace(/"/g, '""')}"`;
            
            csvContent += `${escape(row.empresa)};${escape(row.rota)};${escape(row.motorista)};${escape(row.reserva)};${escape(row.frota_escala)};${escape(row.frota_enviada)};${statusLabel};${row.h_prog};${row.h_real};${escape(row.obs)};${row.ra_val}\n`;
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "frota_export.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // --- RENDERIZAÇÃO ---
    function renderizarApp() {
        // Se ainda não tem dados, não faz nada (mantém spinner)
        if (DADOS_GLOBAIS.length === 0) return;

        const empresaSel = els.empresa.value;
        const statusSel = els.status.value;
        const termo = els.search.value.toLowerCase();
        
        const dadosOrdenados = applySort([...DADOS_GLOBAIS]);

        let html = '';
        let kpi = { total:0, confirmados:0, pendentes:0, manutencao:0, aguardando:0, cobrir:0 };
        const safe = (s) => s ? String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';

        dadosOrdenados.forEach(row => {
            if (empresaSel && row.empresa !== empresaSel) return;
            
            const obsTexto = (row.obs || '').toString();
            const realizou = (row.ra_val && String(row.ra_val).trim() !== '');
            const isCobrir = obsTexto.toLowerCase().includes('cobrir');
            
            let statusKey = 'pendente';
            if (row.manutencao) statusKey = 'manutencao';
            else if (realizou) statusKey = 'confirmado';
            
            if (statusSel) {
                if (statusSel === 'cobrir') { if (!isCobrir) return; }
                else if (statusSel !== statusKey) return;
            }

            const searchText = `${row.empresa} ${row.rota} ${row.motorista} ${row.frota_escala} ${row.frota_enviada} ${obsTexto} ${row.ra_val}`.toLowerCase();
            if (termo && !searchText.includes(termo)) return;

            // KPIs
            kpi.total++;
            if (statusKey === 'confirmado') kpi.confirmados++;
            else if (statusKey === 'pendente' && !row.manutencao) kpi.pendentes++;
            if (row.manutencao) kpi.manutencao++;
            if (row.aguardando) kpi.aguardando++;
            if (isCobrir) kpi.cobrir++;

            // HTML Construction
            const divergencia = (row.frota_escala != row.frota_enviada);
            const obsDisplay = safe(obsTexto.length > 30 ? obsTexto.substring(0, 30) + '...' : obsTexto);
            
            let badgeHtml = '<span class="badge rounded-pill badge-status-pendente px-3">Aguardando</span>';
            if (statusKey === 'manutencao') badgeHtml = '<span class="badge rounded-pill badge-status-erro px-3">Manutenção</span>';
            else if (statusKey === 'confirmado') badgeHtml = '<span class="badge rounded-pill badge-status-ok px-3">Confirmado</span>';

            // Check Atraso
            const isAtrasado = (row.h_real && row.h_prog && row.h_real > row.h_prog && row.h_real !== 'N/D');
            const classReal = isAtrasado ? 'text-atraso' : 'text-muted';

            html += `
            <tr>
                <td><div class="fw-bold text-dark">${safe(row.empresa)}</div><div class="small text-muted text-truncate" style="max-width:250px">${safe(row.rota)}</div></td>
                <td class="text-center"><div class="small text-muted">${safe(row.frota_escala)} / <strong class="${divergencia ? 'divergent-text' : ''}">${safe(row.frota_enviada)}</strong></div></td>
                <td>
                    <div class="d-flex flex-column gap-1">
                        <div class="d-flex align-items-center gap-2"><div class="bg-light rounded-circle p-2 text-secondary"><i class="bi bi-person-fill"></i></div><span class="fw-medium small text-dark">${safe(row.motorista)}</span></div>
                        ${row.reserva ? `<div class="ms-4 small text-muted"><i class="bi bi-arrow-return-right"></i> Res: <strong>${safe(row.reserva)}</strong></div>` : ''}
                    </div>
                </td>
                <td>
                    <div class="d-flex flex-column gap-1">
                        ${row.manutencao ? '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger py-1" style="font-size:0.7rem">Em Manutenção</span>' : ''}
                        ${row.aguardando ? '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning py-1" style="font-size:0.7rem">Aguard. Carro</span>' : ''}
                        ${obsTexto ? `<small class="text-secondary fst-italic" title="${safe(obsTexto)}">${isCobrir ? '<strong class="text-purple">COBRIR</strong> ' : ''}${obsDisplay}</small>` : ''}
                        ${realizou ? `<small class="text-success fw-bold" style="font-size:0.75rem">RA: ${safe(row.ra_val)}</small>` : ''}
                    </div>
                </td>
                <td class="text-center">${badgeHtml}</td>
                <td class="text-end">
                    <div><small class="text-muted">Prog:</small> <strong>${safe(row.h_prog)}</strong></div>
                    ${(row.h_real && row.h_real!=='N/D') ? `<div><small class="text-muted">Real:</small> <span class="${classReal}">${safe(row.h_real)}</span></div>` : ''}
                </td>
            </tr>`;
        });

        if (html === '') html = '<tr><td colspan="6" class="text-center py-5 text-muted">Nenhum registro encontrado.</td></tr>';
        
        els.tbody.innerHTML = html;
        els.kpis.total.innerText = kpi.total;
        els.kpis.confirmados.innerText = kpi.confirmados;
        els.kpis.pendentes.innerText = kpi.pendentes;
        els.kpis.manutencao.innerText = kpi.manutencao;
        els.kpis.aguardando.innerText = kpi.aguardando;
        els.kpis.cobrir.innerText = kpi.cobrir;
    }

    function aplicarFiltros() {
        renderizarApp();
        localStorage.setItem('mimo_empresa', els.empresa.value);
        localStorage.setItem('mimo_status', els.status.value);
        localStorage.setItem('mimo_search', els.search.value);
    }

    els.empresa.addEventListener('change', aplicarFiltros);
    els.status.addEventListener('change', aplicarFiltros);
    els.search.addEventListener('keyup', () => { clearTimeout(filterTimeout); filterTimeout = setTimeout(aplicarFiltros, 300); });

    function updateStatus(isOnline) {
        if(isOnline) {
            els.connDot.classList.remove('status-offline'); els.connDot.classList.add('status-online');
            els.connText.innerText = "Online"; els.connText.classList.remove('text-danger');
        } else {
            els.connDot.classList.remove('status-online'); els.connDot.classList.add('status-offline');
            els.connText.innerText = "Offline"; els.connText.classList.add('text-danger');
        }
    }

    function atualizarDados() {
        const urlParams = new URLSearchParams(window.location.search);
        const dataAtual = urlParams.get('data') || '<?php echo h($data_filtro); ?>';
        
        // Chama a URL atual com ?ajax=1 para pegar os dados
        fetch(window.location.pathname + `?ajax=1&data=${encodeURIComponent(dataAtual)}&last_hash=${currentHash}`)
            .then(r => {
                if(!r.ok) throw new Error("Erro");
                updateStatus(true);
                return r.json();
            })
            .then(data => {
                if (data.status === 'updated') {
                    DADOS_GLOBAIS = data.dados;
                    currentHash = data.hash;
                    
                    atualizarFiltroEmpresas(); // Atualiza o select de empresas
                    renderizarApp(); // Renderiza a tabela
                    
                    els.card.classList.add('table-updated');
                    setTimeout(()=>els.card.classList.remove('table-updated'), 500);
                }
                timeLeft = 60;
                els.progress.style.width = "100%";
            })
            .catch(e => { 
                timeLeft = 60; 
                updateStatus(false);
                console.error(e); 
            });
    }

    setInterval(() => {
        if(timeLeft > 0) {
            timeLeft--;
            els.progress.style.width = ((timeLeft/60)*100) + "%";
            els.progress.style.backgroundColor = timeLeft < 10 ? "#dc2626" : "#10b981";
        } else {
            atualizarDados();
        }
    }, 1000);

    const btnToggle = document.getElementById('btnToggle');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    if(localStorage.getItem('mimo_menu_state') === 'closed') { sidebar.classList.add('toggled'); content.classList.add('toggled'); }
    if(btnToggle) btnToggle.addEventListener('click', () => {
        sidebar.classList.toggle('toggled'); content.classList.toggle('toggled');
        localStorage.setItem('mimo_menu_state', sidebar.classList.contains('toggled')?'closed':'open');
    });

    if(localStorage.getItem('mimo_empresa')) els.empresa.value = localStorage.getItem('mimo_empresa');
    if(localStorage.getItem('mimo_status')) els.status.value = localStorage.getItem('mimo_status');
    if(localStorage.getItem('mimo_search')) els.search.value = localStorage.getItem('mimo_search');

    // Inicialização
    renderizarApp(); 
    atualizarDados(); 
    
    window.addEventListener('online', () => updateStatus(true));
    window.addEventListener('offline', () => updateStatus(false));
</script>
</body>
</html>
