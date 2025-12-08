<?php
// --- OTIMIZAÇÃO 1: GZIP SEGURO ---
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    if (!@ob_start("ob_gzhandler")) ob_start();
} else {
    ob_start();
}

// --- CONFIGURAÇÃO ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php'; 
require_once 'menus.php'; 
require_once 'includes/page_logic.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) { header("Location: login.php"); exit; }
verificarPermissaoPagina('dashboard');

$empresas_permitidas = $_SESSION['allowed_companies'] ?? [];
date_default_timezone_set('America/Sao_Paulo'); 
$hora_atual = date('H:i');

// --- CACHE BACKEND (Arquivo) ---
$cacheKey = md5(json_encode($empresas_permitidas));
$cacheFile = sys_get_temp_dir() . "/dashboard_frota_$cacheKey.json";
$cacheTime = 15; 

$dados_dashboard = null;
$usando_cache = false; 

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $conteudo_cache = file_get_contents($cacheFile);
    if ($conteudo_cache) {
        $dados_dashboard = json_decode($conteudo_cache, true);
        $usando_cache = true;
    }
}

if (!$dados_dashboard) {
    $dados_dashboard = handle_index_data($empresas_permitidas);
    
    // Minificação de Chaves (Mapping)
    $linhas_otimizadas = [];
    foreach ($dados_dashboard['todas_linhas'] as $l) {
        $sIdaRaw = $l['sentidoIDA'] ?? $l['sentidoIda'] ?? true;
        $sentidoBool = filter_var($sIdaRaw, FILTER_VALIDATE_BOOLEAN);
        $linhas_otimizadas[] = [
            'id' => $l['idLinha'] ?? '',
            'e'  => $l['empresa']['nome'] ?? '',
            'r'  => $l['descricaoLinha'] ?? '',
            'v'  => $l['veiculo']['veiculo'] ?? '',
            's'  => $sentidoBool ? 1 : 0,
            'pi' => $l['horarioProgramado'] ?? '',
            'ri' => $l['horarioReal'] ?? '',
            'pf' => $l['horariofinalProgramado']??'',
            'li' => $l['localinicial'] ?? '',
            'lf' => $l['localfinal'] ?? '',
            'ts' => $l['previsao_fim_ts'] ?? 0,
            'u'  => $l['ultimaData'] ?? '',
            'c'  => $l['categoria'] ?? ''
        ];
    }
    $dados_dashboard['todas_linhas'] = $linhas_otimizadas;
    file_put_contents($cacheFile, json_encode($dados_dashboard));
}

extract($dados_dashboard); 

$lista_empresas_unicas = [];
foreach ($todas_linhas as $l) {
    if (!empty($l['e'])) $lista_empresas_unicas[$l['e']] = $l['e'];
}
asort($lista_empresas_unicas);

// --- AJAX API (ETag + JSON Minificado) ---
if (isset($_GET['ajax_tbody'])) {
    header('Content-Type: application/json');
    $payload = json_encode(['d' => $todas_linhas, 'h' => $hora_atual]);
    $etag = md5($payload);
    
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        header("HTTP/1.1 304 Not Modified");
        exit;
    }
    header("ETag: $etag");
    echo $payload;
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viação Mimo - Dashboard</title>
    
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />

    <style>
    body{background-color:#f8f9fa;font-family:'Segoe UI',sans-serif;overflow-x:hidden}
    
    /* Layout */
    .sidebar{background-color:#0b1f3a;color:#fff;min-height:100vh;width:250px;position:fixed;z-index:1000;transition:all .3s ease;overflow-y:auto}
    .sidebar a{color:#d1d5db;display:flex;align-items:center;padding:14px 20px;text-decoration:none;border-left:4px solid transparent;font-weight:500;white-space:nowrap;overflow:hidden}
    .sidebar a i{min-width:30px;font-size:1.1rem}
    .sidebar a.active,.sidebar a:hover{background-color:#1b2e52;color:#fff;border-left-color:#0d6efd}
    .sidebar .logo-container img{transition:all .3s;max-width:160px}
    .sidebar.toggled{width:80px}
    .sidebar.toggled .logo-container img{max-width:50px}
    .sidebar.toggled a span{display:none}.sidebar.toggled a{justify-content:center;padding:14px 0}
    .sidebar.toggled a i{margin-right:0!important}
    .content{margin-left:250px;padding:30px;transition:all .3s ease}
    .content.toggled{margin-left:80px}
    
    /* OTIMIZAÇÃO: CONTENT VISIBILITY & CONTAINMENT */
    .card-summary, .filter-bar, .modal-content { content-visibility: auto; contain-intrinsic-size: 100px 100px; }
    
    /* Performance de Tabela: Cada linha é isolada do layout global */
    .table tbody tr { contain: layout style; }

    /* Estilos Visuais */
    .card-summary{border-radius:12px;padding:20px;color:#fff;text-align:center;position:relative;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,.1)}
    .card-summary h5{font-size:.9rem;opacity:.9;margin-bottom:5px;text-transform:uppercase}
    .card-summary h3{font-size:2rem;font-weight:700;margin:0}
    .card-blue{background:linear-gradient(135deg,#0b1f3a 0,#1e3a8a 100%)}
    .card-red{background:linear-gradient(135deg,#b91c1c 0,#dc2626 100%)}
    .card-green{background:linear-gradient(135deg,#047857 0,#10b981 100%)}
    .bg-secondary{background:linear-gradient(135deg,#4b5563 0,#6b7280 100%)!important}
    .bg-info{background:linear-gradient(135deg,#0891b2 0,#06b6d4 100%)!important}
    .bg-warning{background:linear-gradient(135deg,#f59e0b 0,#d97706 100%)!important}
    
    .table-responsive{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.05);padding:5px}
    .table thead{background-color:#f1f5f9}
    .table thead th{color:#475569;font-weight:600;border-bottom:2px solid #e2e8f0;padding:15px;cursor:pointer;white-space:nowrap}
    .table tbody td{padding:15px;vertical-align:middle;color:#334155;white-space:normal}
    th:hover{background-color:#e2e8f0;color:#0f172a}
    
    .search-bar{border-radius:20px;border:1px solid #cbd5e1;padding-left:40px;height:45px}
    .search-icon{position:absolute;left:15px;top:12px;color:#94a3b8}
    .modal-content{border:none;border-radius:16px;overflow:hidden}
    #mapaRota{height:550px;width:100%;background-color:#e9ecef;z-index:1}
    .leaflet-routing-container{display:none!important}
    .mini-loader{width:1rem;height:1rem;border-width:.15em}
    .table-ultra-compact{font-size:.9rem}
    .table-ultra-compact td,.table-ultra-compact th{padding-top:2px!important;padding-bottom:2px!important;padding-left:4px!important;padding-right:4px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
    .col-narrow{width:1%;white-space:nowrap}
    .bi{font-size:.9em}
    .btn-xs{padding:.1rem .3rem;font-size:.7rem;line-height:1}
    .blink-animation{animation:blinker 1.5s linear infinite}
    @keyframes blinker{50%{opacity:.5}}
    .filter-bar{background-color:#fff;border-radius:12px;padding:15px;margin-bottom:20px;box-shadow:0 2px 5px rgba(0,0,0,.05)}
    
    .skeleton { background: #e0e0e0; border-radius: 4px; animation: shimmer 1.5s infinite linear; }
    .skeleton-text { height: 12px; margin-bottom: 6px; width: 100%; }
    .skeleton-block { height: 100px; width: 100%; }
    @keyframes shimmer { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
    </style>
</head>
<body>

<script>
    // Injection de Dados Compactados
    window.dadosIniciais = <?php echo json_encode($todas_linhas); ?>;
    window.horaServidorInicial = "<?php echo $hora_atual; ?>";
</script>

<div class="sidebar d-flex flex-column" id="sidebar">
    <div class="text-center py-4 bg-dark bg-opacity-25 logo-container">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo">
    </div>
    <?php 
    foreach ($menu_itens as $chave => $item): 
        $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
        $tem_permissao = in_array($chave, $permissoes_usuario);
        if ($is_admin || $tem_permissao):
            $classe_active = ($pagina_atual == $item['link'] || ($chave == 'escala' && $pagina_atual == 'escala.php')) ? 'active' : '';
    ?>
        <a href="<?php echo $item['link']; ?>" class="<?php echo $classe_active; ?>" title="<?php echo $item['label']; ?>">
            <i class="bi <?php echo $item['icon']; ?> me-2"></i><span><?php echo $item['label']; ?></span>
        </a>
    <?php endif; endforeach; ?>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary" title="Sair">
        <i class="bi bi-box-arrow-right me-2"></i><span>Sair</span>
    </a>
</div>

<div class="content" id="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-dark border-0 shadow-sm" id="btnToggleMenu"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h4 class="fw-bold text-dark mb-1">Visão Geral da Frota</h4>
                <p class="text-muted small mb-0">Monitoramento em tempo real <?php if($usando_cache) echo '<span class="badge bg-light text-secondary border">⚡ Cache</span>'; ?></p>
            </div>
        </div>
        
        <div class="d-flex gap-2 w-50 justify-content-end align-items-center">
            <button class="btn btn-white border shadow-sm" onclick="toggleFullScreen()" title="Modo Tela Cheia" style="height: 45px; width: 45px;">
                <i class="bi bi-arrows-fullscreen"></i>
            </button>

            <div class="position-relative w-50">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="searchInput" class="form-control search-bar" placeholder="Busca Inteligente (Placa, Linha, Empresa)...">
            </div>
        </div>
    </div>

    <div class="filter-bar">
        <div class="row g-2 align-items-center" id="filterArea">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Empresa:</label>
                <select id="filtroEmpresa" class="form-select form-select-sm" onchange="aplicarFiltrosFrontend()">
                    <option value="">Todas as Empresas</option>
                    <?php foreach ($lista_empresas_unicas as $emp_nome): ?>
                        <option value="<?php echo htmlspecialchars($emp_nome); ?>">
                            <?php echo htmlspecialchars($emp_nome); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Sentido:</label>
                <select id="filtroSentido" class="form-select form-select-sm" onchange="aplicarFiltrosFrontend()">
                    <option value="">Todos os Sentidos</option>
                    <option value="ida">➡️ IDA</option>
                    <option value="volta">⬅️ VOLTA</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary mb-1">Status (Tempo Real):</label>
                <select id="filtroStatusJS" class="form-select form-select-sm" onchange="aplicarFiltrosFrontend()">
                    <option value="">Todos</option>
                    <option value="atrasado_geral">🚨 Atrasados (Qualquer Tipo)</option>
                    <option value="atrasado_saida">⚠️ Atraso na Saída (Inicial)</option>
                    <option value="atrasado_percurso">🛑 Atraso no Percurso (GPS)</option>
                    <option value="pontual">✅ Pontual</option>
                    <option value="desligado">🔌 Desligado / Sem Sinal</option>
                    <option value="aguardando">⏳ Aguardando</option>
                </select>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card-summary card-blue"><h5>Total</h5><h3 id="count-total">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary card-red"><h5>Atrasados</h5><h3 id="count-atrasados">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary card-green"><h5>Pontual</h5><h3 id="count-pontual">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary bg-secondary"><h5>Desligados</h5><h3 id="count-desligados">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary bg-info"><h5>Em Deslocamento</h5><h3 id="count-deslocamento">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary bg-warning"><h5>Não Iniciou</h5><h3 id="count-sem-inicio">0</h3></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm table-ultra-compact align-middle mb-0">
                    <thead>
                        <tr>
                            <th onclick="ordenarTabela('e')">Empresa <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela('r')">Rota <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela('v')">Veículo <i class="bi bi-arrow-down-up small"></i></th>
                            <th class="col-narrow" title="Previsão Início"> Prev. Ini</th>
                            <th onclick="ordenarTabela('pi')">Prog. Início <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela('ri')">Real Início <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela('pf')">Prog. Fim <i class="bi bi-arrow-down-up small"></i></th>
                            <th class="col-narrow" title="Previsão Fim">Prev. Fim</th>
                            <th onclick="ordenarTabela('u')">Ultimo Reporte <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela('st')">Status <i class="bi bi-arrow-down-up small"></i></th>
                            <th class="text-center">Prev. Inicial</th>
                            <th class="text-center">Prev. Final</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-veiculos"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="popupResultado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-primary"><i class="bi bi-map me-2"></i>Detalhamento da Rota</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body p-0">
        <div id="resultadoConteudo" class="px-3 pt-2"></div>
        <div id="mapaRota"></div>
        <div class="d-flex justify-content-center gap-3 py-2 small bg-light border-top">
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill me-1"></i> Rota Percorrida</span>
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill text-danger me-1"></i> Rota Fixa</span>
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill text-primary me-1"></i> Previsão</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0 rounded-4">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-danger">⚠️ Alerta de Atrasos</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body pt-3" id="alertModalBody"></div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Ciente</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fuse.js@6.6.2"></script>

<script>
if ('serviceWorker' in navigator) {
    // Registra SW fictício apenas para criar cache de assets (pode ser expandido)
    // Para produção real, crie um arquivo sw.js. Aqui deixamos preparado.
    // navigator.serviceWorker.register('/sw.js');
}
</script>

<script>
// --- VARIÁVEIS GLOBAIS ---
// --- CACHE DE PREVISÕES CLIENT-SIDE ---
let mapaInstancia = null;
let mapaLayerGroup = null; 
let routingControl = null; 
let currentController = null; 
let renderToken = 0;
let direcaoAtual = {};

let todosDadosVeiculos = window.dadosIniciais || [];
let horaServidorAtual = window.horaServidorInicial || "00:00";
let fuseInstance = null;
// --- CACHE DE PREVISÕES CLIENT-SIDE ---
const previsoesCacheLocal = new Map(); 

// 4 minutos = 4 * 60 * 1000 = 240000 ms
const TTL_PREVISAO = 240000;

// OTIMIZAÇÃO 3: MEMOIZATION MAP (Cache de strings HTML)
const htmlCache = new Map();

const fuseOptions = {
    keys: ['v', 'e', 'r', 'li', 'lf'], 
    threshold: 0.3, 
    ignoreLocation: true
};

document.addEventListener("DOMContentLoaded", function() {
    const empSalva = localStorage.getItem('filtro_empresa');
    const sentSalvo = localStorage.getItem('filtro_sentido');
    if(empSalva) document.getElementById('filtroEmpresa').value = empSalva;
    if(sentSalvo) document.getElementById('filtroSentido').value = sentSalvo;

    fuseInstance = new Fuse(todosDadosVeiculos, fuseOptions);

    if(document.getElementById('mapaRota')) {
        // preferCanvas para performance de desenho
        mapaInstancia = L.map('mapaRota', { preferCanvas: true }).setView([-23.5505, -46.6333], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(mapaInstancia);
        mapaLayerGroup = L.layerGroup().addTo(mapaInstancia);
    }

    const modalElement = document.getElementById('popupResultado');
    modalElement.addEventListener('shown.bs.modal', function () { if (mapaInstancia) mapaInstancia.invalidateSize(); });
    modalElement.addEventListener('hidden.bs.modal', function () {
        if (currentController) { currentController.abort(); currentController = null; }
        renderToken++; 
        if(mapaLayerGroup) mapaLayerGroup.clearLayers(); 
        if (routingControl && mapaInstancia) { 
            mapaInstancia.removeControl(routingControl); 
            routingControl = null; 
        }
        document.getElementById("mapaRota").style.visibility = 'hidden'; 
    });

    renderizarTabelaCompleta();

    document.getElementById('tabela-veiculos').addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-acao-mapa');
        if(btn) {
            const tr = btn.closest('tr');
            try {
                const data = JSON.parse(tr.getAttribute('data-json'));
                const tipo = btn.getAttribute('data-tipo');
                if(tipo === 'inicial') buscarRastreamentoinicial(data.v, data.li, data.pi, data.id, btn);
                else buscarRastreamento(data.v, data.lf, data.pf, data.id, btn);
            } catch(err) { console.error("Erro dados linha", err); }
        }
    });

    const searchInput = document.getElementById('searchInput');
    let timeoutSearch = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(timeoutSearch);
            timeoutSearch = setTimeout(aplicarFiltrosFrontend, 250); 
        });
    }

    verificarAlertas();
    iniciarAtualizacaoAutomatica();
    
    const btnToggle = document.getElementById('btnToggleMenu');
    if (btnToggle) {
        btnToggle.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('toggled');
            document.getElementById('content').classList.toggle('toggled');
            setTimeout(() => { if (mapaInstancia) mapaInstancia.invalidateSize(); }, 350);
        });
    }
});

function aplicarFiltrosFrontend() { 
    localStorage.setItem('filtro_empresa', document.getElementById('filtroEmpresa').value);
    localStorage.setItem('filtro_sentido', document.getElementById('filtroSentido').value);
    renderizarTabelaCompleta(); 
}

function renderizarTabelaCompleta() {
    requestAnimationFrame(() => {
        const termoBusca = document.getElementById('searchInput')?.value || '';
        const emp = document.getElementById('filtroEmpresa')?.value || '';
        const sentido = document.getElementById('filtroSentido')?.value || '';
        const status = document.getElementById('filtroStatusJS')?.value || '';

        let dadosFiltrados = todosDadosVeiculos;
        if (termoBusca.length > 0) {
            const resultados = fuseInstance.search(termoBusca);
            dadosFiltrados = resultados.map(r => r.item); 
        }

        dadosFiltrados = dadosFiltrados.filter(l => {
            if (emp && l.e !== emp) return false; 
            const sentidoStr = l.s ? 'ida' : 'volta'; 
            if (sentido && sentidoStr !== sentido) return false;
            return true;
        });

        if (status) {
            dadosFiltrados = dadosFiltrados.filter(l => {
                const tempHtml = montarHTMLLinha(l, horaServidorAtual); 
                if (status === 'atrasado_geral') return tempHtml.includes('atrasado');
                if (status === 'atrasado_saida') return tempHtml.includes('(Inicial)') || tempHtml.includes('(P. Inicial)');
                if (status === 'atrasado_percurso') return tempHtml.includes('(Percurso)');
                if (status === 'pontual') return tempHtml.includes('Pontual');
                if (status === 'desligado') return tempHtml.includes('Desligado');
                if (status === 'aguardando') return tempHtml.includes('Aguardando');
                return true;
            });
        }

        const tbody = document.getElementById('tabela-veiculos');
        if (dadosFiltrados.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center py-4 text-muted">Nenhum veículo encontrado.</td></tr>';
        } else {
            tbody.innerHTML = dadosFiltrados.map(l => montarHTMLLinha(l, horaServidorAtual)).join('');
        }
        
        atualizarCardsResumo(); 
        carregarPrevisoesAutomaticamente(); 
    });
}

function montarHTMLLinha(l, horaServidor) {
    // --- OTIMIZAÇÃO 3: MEMOIZATION (Se a linha não mudou, retorna cache) ---
    // Criamos uma chave única baseada no ID e no Timestamp de cache + hora atual (para o calculo de atraso)
    // Se o horário mudou, precisamos recalcular. Se não, cache.
    // Nota: Como 'horaServidor' muda a cada 30s, o cache dura 30s. Suficiente.
    const cacheKey = `${l.id}-${l.ts}-${l.ri}-${horaServidor}`;
    if(htmlCache.has(cacheKey)) {
        return htmlCache.get(cacheKey);
    }

    const tolerancia = 10;
    const prog = l.pi || '23:59';
    const real = l.ri || 'N/D';
    const dataProgFim = l.pf || 'N/D';
    const placaClean = l.v;
    const idLinha = l.id || '';
    const iconSentido = l.s ? '<i class="bi bi-arrow-right-circle-fill text-primary ms-1" title="IDA"></i>' : '<i class="bi bi-arrow-left-circle-fill text-warning ms-1" title="VOLTA"></i>';
    const timestampCache = l.ts || '';
    const temCache = timestampCache !== 0 && timestampCache !== '';
    const valorCache = temCache ? new Date(parseInt(timestampCache) * 1000).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'}) : '--:--';

    let statusHtml = '';
    let atrasoSaida = false;
    let atrasoPercurso = false;

    const diffMin = (h1, h2) => {
        if(h1=='N/D'||h2=='N/D'||!h1||!h2) return 0;
        const [hA, mA] = h1.split(':').map(Number);
        const [hB, mB] = h2.split(':').map(Number);
        return (hB*60+mB) - (hA*60+mA);
    };

    if (l.c === 'Carro desligado') {
        statusHtml = '<span class="badge bg-secondary rounded-pill">Desligado</span>';
    } else if (real === 'N/D' || !real) {
        const diff = diffMin(prog, horaServidor);
        if (diff > tolerancia) {
            atrasoSaida = true;
            statusHtml = '<span class="badge rounded-pill bg-danger blink-animation">Atrasado (Inicial)</span>';
        } else {
            statusHtml = '<span class="badge bg-light text-dark border">Aguardando</span>';
        }
    } else {
        const diffSaida = diffMin(prog, real);
        if (l.s && temCache && dataProgFim !== 'N/D') {
            if (diffMin(dataProgFim, valorCache) > tolerancia) atrasoPercurso = true;
        }
        if (atrasoPercurso) {
            statusHtml = (diffSaida > tolerancia) ? '<span class="badge bg-danger rounded-pill">Atrasado (P. Inicial)</span>' : '<span class="badge bg-danger rounded-pill">Atrasado (Percurso)</span>';
        } else if (diffSaida > tolerancia) {
            statusHtml = '<span class="badge bg-danger rounded-pill">Atrasado (P. Inicial)</span>';
        } else {
            statusHtml = '<span class="badge bg-success rounded-pill">Pontual</span>';
        }
    }

    const classePrev = (temCache && dataProgFim !== 'N/D' && valorCache > dataProgFim) ? "text-danger fw-bold" : "text-success fw-bold";
    const metaData = JSON.stringify({ v: placaClean, id: idLinha, li: l.li||'N/D', lf: l.lf||'N/D', pi: prog, pf: dataProgFim });
    const deveCalcular = (real !== 'N/D' && l.c !== 'Carro desligado' && !temCache) ? 'true' : 'false';

    const html = `<tr data-atraso-tipo="${atrasoSaida?'saida':''}" data-json='${metaData}'>
        <td>${l.e || 'N/D'}</td>
        <td>${l.r || 'N/D'} ${iconSentido}</td>
        <td class='fw-bold text-primary'>${placaClean}</td>
        <td id='prev-ini-${placaClean}' class='text-muted small'>--:--</td>
        <td class='${atrasoSaida?'text-danger fw-bold':''}'>${prog}</td>
        <td>${real}</td>
        <td><strong>${dataProgFim}</strong></td>
        <td id='prev-fim-${placaClean}' class='${classePrev} celula-previsao' data-placa='${placaClean}' data-prog-fim='${dataProgFim}' data-ts-cache='${timestampCache}' data-calcular='${deveCalcular}' data-id-linha='${idLinha}'>${valorCache}</td>
        <td title='${l.u||''}'>${l.u||'N/D'}</td>
        <td>${statusHtml}</td>
        <td class='text-center'><button class='btn btn-outline-primary btn-xs rounded-circle btn-acao-mapa' data-tipo='inicial'><i class='bi bi-clock'></i></button></td>
        <td class='text-center'><button class='btn btn-primary btn-sm rounded-circle shadow-sm btn-acao-mapa' data-tipo='final'><i class='bi bi-geo-alt-fill'></i></button></td>
    </tr>`;
    
    // Salva no cache e retorna
    htmlCache.set(cacheKey, html);
    return html;
}

function iniciarAtualizacaoAutomatica() {
    setInterval(async () => {
        try {
            const urlAtual = new URL(window.location.href);
            urlAtual.searchParams.set('t', Date.now());
            urlAtual.searchParams.set('ajax_tbody', '1'); 
            
            const response = await fetch(urlAtual);
            if(response.status === 200) {
                const data = await response.json(); 
                if(data.d) { 
                    // LIMPEZA DE MEMÓRIA: Remove referências antigas antes de atribuir novas
                    todosDadosVeiculos = null; 
                    todosDadosVeiculos = data.d;
                    horaServidorAtual = data.h; 
                    
                    // Limpa cache se a hora mudou muito (evita memory leak infinito)
                    if(htmlCache.size > 2000) htmlCache.clear();

                    fuseInstance.setCollection(todosDadosVeiculos); 
                    renderizarTabelaCompleta(); 
                }
            }
        } catch (e) { console.error("Erro refresh", e); }
    }, 30000);
}

function atualizarCardsResumo() {
    let counts = { total: 0, atrasados: 0, pontual: 0, desligados: 0, deslocamento: 0, semInicio: 0 };
    document.querySelectorAll("#tabela-veiculos tr").forEach(row => {
        if (row.cells.length < 10) return;
        counts.total++;
        const text = row.cells[9].innerText.trim();
        if (text.includes("Atrasado")) {
            if (text.includes("(Inicial)") && !text.includes("Percurso")) counts.semInicio++; 
            else counts.atrasados++;
        } else if (text.includes("Pontual")) counts.pontual++;
        else if (text.includes("Desligado")) counts.desligados++;
        else if (text.includes("Em deslocamento")) counts.deslocamento++;
        else counts.semInicio++; 
    });
    const setVal = (id, val) => { const el = document.getElementById(id); if(el) el.innerText = val; };
    setVal('count-total', counts.total); setVal('count-atrasados', counts.atrasados); setVal('count-pontual', counts.pontual);
    setVal('count-desligados', counts.desligados); setVal('count-deslocamento', counts.deslocamento); setVal('count-sem-inicio', counts.semInicio);
}

async function carregarPrevisoesAutomaticamente(elementosPrioritarios = null) {
    const celulas = elementosPrioritarios || document.querySelectorAll('.celula-previsao');
    let index = 0;
    const batchSize = 10; // Aumentei o batch pois a leitura de cache é rápida

    const processNextBatch = async () => {
        if (index >= celulas.length) return;
        
        const lote = Array.from(celulas).slice(index, index + batchSize);
        index += batchSize;

        await Promise.all(lote.map(async (celula) => {
            const placa = celula.getAttribute('data-placa');
            const idLinha = celula.getAttribute('data-id-linha');
            const progFim = celula.getAttribute('data-prog-fim');
            const tsCacheServer = celula.getAttribute('data-ts-cache');
            
            // 1. Verifica se já está carregando ou se já tem valor final do servidor
            if (celula.getAttribute('data-calculando') === 'true') return;
            
            // Se o texto não é o default e não é N/D, assumimos que já está preenchido
            // MAS, se acabou de renderizar (refresh), o texto voltou a ser o do servidor.
            // Então confiamos na validação do Cache Local abaixo.

            // 2. Prioridade: Cache do Servidor (se existir e for válido)
            if (tsCacheServer && tsCacheServer !== '0' && tsCacheServer !== '') {
                // A lógica original já trata isso na renderização, mas reforçamos aqui se necessário
                // Se já veio do servidor, não fazemos nada.
                return;
            }

            // 3. Verificação do Cache Local (JavaScript Map)
            if (previsoesCacheLocal.has(placa)) {
                const cacheData = previsoesCacheLocal.get(placa);
                const idadeCache = Date.now() - cacheData.timestamp;

                // Se o cache é recente (menos de 60s), usamos ele e evitamos o fetch
                if (idadeCache < TTL_PREVISAO) {
                    aplicarEstiloPrevisao(celula, cacheData.horario, progFim);
                    return;
                } else {
                    // Cache expirado, removemos
                    previsoesCacheLocal.delete(placa);
                }
            }

            // 4. Se precisa calcular (flag do HTML) e não temos cache local
            if (celula.getAttribute('data-calcular') === 'true') {
                celula.setAttribute('data-calculando', 'true');
                celula.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary mini-loader"></div>';

                try {
                    const response = await fetch(`/previsao/${placa}?idLinha=${idLinha}`);
                    const data = await response.json();
                    
                    let est = 'N/D';
                   if (data.duracaoSegundos) {
    const chegada = new Date(new Date().getTime() + data.duracaoSegundos * 1000);
    est = String(chegada.getHours()).padStart(2, '0') + ":" + String(chegada.getMinutes()).padStart(2, '0');
    
    // --- ALTERAÇÃO AQUI: Salvamos o JSON completo (rota + tempo) ---
    previsoesCacheLocal.set(placa, {
        horario: est,
        fullData: data, // Guardamos tudo: waypoints, rastro, etc.
        timestamp: Date.now()
    });
                    }

                    aplicarEstiloPrevisao(celula, est, progFim);

                } catch (error) {
                    celula.innerText = 'Erro';
                } finally {
                    celula.removeAttribute('data-calculando');
                }
            }
        }));

        // Processa o próximo lote quase imediatamente se foi cache hit, ou espera um pouco se foi network
        setTimeout(processNextBatch, 50); 
    };

    processNextBatch();
}

// Função auxiliar para aplicar estilo visual (DRY - Don't Repeat Yourself)
function aplicarEstiloPrevisao(celula, horarioEstimado, horarioProgramado) {
    celula.innerText = horarioEstimado;
    
    // Remove classes anteriores para garantir limpeza
    celula.classList.remove('text-danger', 'text-success', 'text-dark');
    celula.classList.add('fw-bold', 'celula-previsao');

    if (horarioEstimado === 'N/D' || horarioEstimado === '--:--') {
        celula.classList.add('text-muted');
        return;
    }

    if (horarioProgramado !== 'N/D' && horarioEstimado > horarioProgramado) {
        celula.classList.add('text-danger'); // Atrasado
    } else {
        celula.classList.add('text-success'); // No horário/Adiantado
    }
}

async function processarBusca(placa, localAlvo, horarioFinalProg, idLinha, button, tipo) {
    if (currentController) currentController.abort();
    currentController = new AbortController();
    const signal = currentController.signal;
    renderToken = Date.now();
    const meuToken = renderToken;

    const previsaoCell = button.closest('tr').querySelector('.celula-previsao');
    const textoOriginal = previsaoCell.innerHTML;
    
    document.getElementById("mapaRota").style.visibility = 'visible';
    if(mapaLayerGroup) mapaLayerGroup.clearLayers(); 
    
    if (routingControl && mapaInstancia) { 
        mapaInstancia.removeControl(routingControl); 
        routingControl = null; 
    }
    
    // Skeleton Screen (Carregando...)
    document.getElementById("resultadoConteudo").innerHTML = `
        <div class="container-fluid px-3 pt-3">
            <div class="d-flex justify-content-between mb-3"><div class="skeleton skeleton-text" style="width: 40%"></div><div class="skeleton skeleton-text" style="width: 20%"></div></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><div class="skeleton skeleton-block"></div></div>
                <div class="col-6"><div class="skeleton skeleton-block"></div></div>
            </div>
            <div class="text-center text-muted small"><i class="bi bi-hdd-network"></i> Carregando dados do mapa...</div>
        </div>
    `;
    new bootstrap.Modal(document.getElementById("popupResultado")).show();

    try {
        const baseUrl = tipo === 'inicial' ? `/previsaoinicial/${placa}` : `/previsao/${placa}`;
        
        // --- LÓGICA DE CACHE INTELIGENTE PARA O MAPA ---
        let promessaRota;
        let usouCache = false;

        // Verifica se temos cache VÁLIDO (menos de 4 min) e se o tipo é compátivel (cache da tabela geralmente é 'final')
        if (tipo !== 'inicial' && previsoesCacheLocal.has(placa)) {
            const cacheItem = previsoesCacheLocal.get(placa);
            if ((Date.now() - cacheItem.timestamp) < TTL_PREVISAO) {
                // USA O CACHE: Retorna uma promessa resolvida imediatamente com os dados locais
                console.log("Usando cache de rota para o mapa!");
                promessaRota = Promise.resolve(cacheItem.fullData);
                usouCache = true;
            }
        }

        // Se não usou cache, faz o fetch normal
        if (!promessaRota) {
            promessaRota = fetch(`${baseUrl}?idLinha=${idLinha}`, { signal }).then(r => r.json());
        }

        // Buscamos sempre a POSIÇÃO atual do veículo (é leve e precisa ser precisa)
        // E buscamos a rota (seja do cache ou da internet)
        const [respRastreio, rotaData] = await Promise.all([
            fetch(`/buscar_rastreamento/${placa}`, { signal }).then(r => r.json()), 
            promessaRota
        ]);
        
        if (renderToken !== meuToken) return;

        previsaoCell.innerHTML = textoOriginal;

        let latVeiculo = null, lngVeiculo = null;
        let veiculoData = (Array.isArray(respRastreio) && respRastreio.length > 0) ? respRastreio[0] : null;
        
        if (veiculoData) {
            if (veiculoData.lat) { latVeiculo = veiculoData.lat; lngVeiculo = veiculoData.lng; }
            else if (veiculoData.loc) { 
                const p = (typeof veiculoData.loc === 'string') ? veiculoData.loc.split(',') : veiculoData.loc;
                latVeiculo = p[0]; lngVeiculo = p[1];
            }
            
            const enderecoAtual = veiculoData.endereco || veiculoData.loc || 'Localizando...';
            let horarioEstimado = '--';
            
            // Recalcula hora estimada baseada no "duracaoSegundos" (do cache ou live)
            // Nota: Se for do cache, o tempo estimado será "da hora que o cache foi criado".
            // Se quiser atualizar o tempo baseado no cache antigo, precisaria recalcular, 
            // mas geralmente para 4 minutos a diferença é aceitável.
            if (rotaData.duracaoSegundos) {
                 // Se quiser ser muito preciso e o dado for de cache, poderia subtrair o tempo passado, 
                 // mas manter simples é melhor para evitar bugs de tempo negativo.
                 const chegada = new Date(new Date().getTime() + rotaData.duracaoSegundos * 1000);
                 horarioEstimado = String(chegada.getHours()).padStart(2, '0') + ":" + String(chegada.getMinutes()).padStart(2, '0');
            }

            const labelProg = (tipo === 'inicial') ? 'Inicial Programado' : 'Final Programado';
            const labelEst = (tipo === 'inicial') ? 'Chegada Prevista' : 'Previsão Atualizada';
            const statusCor = (horarioEstimado !== '--' && horarioFinalProg !== 'N/D' && horarioEstimado > horarioFinalProg) ? 'text-danger fw-bold' : (horarioEstimado !== '--' ? 'text-success fw-bold' : 'text-dark');
            
            const badgeCache = usouCache ? '<span class="badge bg-light text-secondary border ms-2" title="Rota carregada da memória">⚡ Rápido</span>' : '';

            document.getElementById("resultadoConteudo").innerHTML = `
            <div class="container-fluid px-3 pt-3">
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-light">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-bus-front me-2 text-primary"></i>${veiculoData.identificacao || 'Veículo'} ${badgeCache}</h5>
                    <span class="badge bg-success">Online</span>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="p-3 border rounded bg-white shadow-sm h-100"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">Origem Atual</small><br><span id="txt-origem" class="d-block text-dark fw-semibold" style="font-size: 0.9rem;">${enderecoAtual}</span></div></div>
                    <div class="col-6"><div class="p-3 border rounded bg-white shadow-sm h-100"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">Destino</small><br><span id="txt-destino" class="d-block text-dark fw-semibold" style="font-size: 0.9rem;">${localAlvo || 'Calculando...'}</span></div></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="p-3 border rounded bg-light shadow-sm h-100 text-center"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">${labelProg}</small><br><span class="d-block text-dark fw-semibold fs-4">${horarioFinalProg || 'N/D'}</span></div></div>
                    <div class="col-6"><div class="p-3 border rounded shadow-sm h-100 text-center" style="background-color: #f0f8ff;"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">${labelEst}</small><br><span class="d-block fs-4 ${statusCor}">${horarioEstimado}</span></div></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-light" style="background-color: #e0f2fe;">
                    <strong class="text-dark"><i class="bi bi-stopwatch-fill me-2"></i>Estimativa:</strong>
                    <div class="text-end"><span class="fs-4 fw-bold text-dark">${rotaData.tempo || '--'}</span><span class="text-muted mx-2">|</span><span class="fs-5 text-dark">${rotaData.distancia || '--'}</span></div>
                </div>
            </div>`;
            
            if (latVeiculo && lngVeiculo) {
                gerarMapaRota(latVeiculo, lngVeiculo, rotaData.lat, rotaData.lng, (veiculoData?.endereco || 'Veículo'), localAlvo, rotaData.waypoints_usados, rotaData.todos_pontos_visual, rotaData.rastro_oficial, rotaData.rastro_real, tipo, meuToken);
            } else { 
                document.getElementById("mapaRota").innerHTML = `<div class="text-center text-muted py-5">Coordenadas indisponíveis.</div>`; 
            }
        } else {
            document.getElementById("resultadoConteudo").innerHTML = `<div class="alert alert-warning m-3 text-dark">Veículo não encontrado.</div>`;
        }
    } catch (err) {
        if (err.name !== 'AbortError') document.getElementById("resultadoConteudo").innerHTML = `<div class='alert alert-danger m-3'>Erro: ${err.message}</div>`;
    }
}

function gerarMapaRota(latO, lngO, latD, lngD, nomeO, nomeD, waypoints, todosPontos=[], rastroOficial=[], rastroReal=[], tipo, tokenSolicitante) {
    if (tokenSolicitante !== renderToken || !mapaInstancia) return;

    // 1. LIMPEZA
    mapaLayerGroup.clearLayers();
    if (routingControl) {
        mapaInstancia.removeControl(routingControl);
        routingControl = null;
    }

    // Garante que são números para os cálculos de distância
    latO = parseFloat(latO); lngO = parseFloat(lngO);
    latD = parseFloat(latD); lngD = parseFloat(lngD);
    
    const icons = {
        bus: L.icon({iconUrl:'https://cdn-icons-png.flaticon.com/512/3448/3448339.png', iconSize:[38,38], iconAnchor:[19,38], popupAnchor:[0,-30]}),
        flagStart: L.icon({iconUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png', iconSize:[25,41], iconAnchor:[12,41], popupAnchor:[1,-34]}),
        flagEnd: L.icon({iconUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png', iconSize:[25,41], iconAnchor:[12,41], popupAnchor:[1,-34]})
    };

    let boundsTotal = L.latLngBounds();
    let indexCorteInicio = 0;
    let indexCorteFim = 0; // Novo índice para o final da linha azul
    let rastroCoords = [];

    // --- PREPARAÇÃO DOS DADOS E CÁLCULOS DOS CORTES ---
    if (rastroOficial?.length) {
        rastroCoords = rastroOficial.map(c => [parseFloat(c[1]), parseFloat(c[0])]); // [lat, lng]
        indexCorteFim = rastroCoords.length; // Por padrão, vai até o fim do array

        // 1. Descobre onde o ÔNIBUS está na linha (Início da linha azul)
        let menorDistBus = Infinity;
        for (let i = 0; i < rastroCoords.length; i++) {
            const dist = Math.sqrt(Math.pow(rastroCoords[i][0] - latO, 2) + Math.pow(rastroCoords[i][1] - lngO, 2));
            if (dist < menorDistBus) {
                menorDistBus = dist;
                indexCorteInicio = i;
            }
        }

        // 2. Se for Previsão INICIAL, descobre onde é o PONTO DE CHEGADA na linha (Fim da linha azul)
        // Isso evita que a linha azul continue depois do ponto inicial até o final da rota da linha
        if (tipo === 'inicial') {
            let menorDistDest = Infinity;
            // Otimização: Procura a partir do ônibus para frente, assumindo rota linear/circular progressiva
            // Se preferir buscar em toda rota (caso o GPS pule), mude o 'j = indexCorteInicio' para 'j = 0'
            for (let j = 0; j < rastroCoords.length; j++) {
                const distDest = Math.sqrt(Math.pow(rastroCoords[j][0] - latD, 2) + Math.pow(rastroCoords[j][1] - lngD, 2));
                if (distDest < menorDistDest) {
                    menorDistDest = distDest;
                    indexCorteFim = j + 1; // +1 para incluir o ponto na renderização
                }
            }
            
            // Segurança: Se por algum motivo o cálculo falhar ou o destino estiver "atrás" (loop), mantém até o fim
            // ou ajusta conforme necessidade. Aqui mantemos a lógica simples de proximidade.
            if (indexCorteFim < indexCorteInicio) indexCorteFim = rastroCoords.length;
        }
    }

    // --- CAMADA 1: ROTA OFICIAL COMPLETA (VERMELHA) ---
    if (rastroCoords.length > 0) {
        const linhaVermelha = L.polyline(rastroCoords, {
            color: '#ff0505',
            weight: 6,
            opacity: 0.4,
            interactive: false
        }).addTo(mapaLayerGroup);
        boundsTotal.extend(linhaVermelha.getBounds());

        // --- CAMADA 2: ROTA FUTURA (AZUL) ---
        // Agora usa o 'indexCorteFim' para parar de desenhar no destino
        const parteFutura = rastroCoords.slice(indexCorteInicio, indexCorteFim);
        
        if (parteFutura.length > 1) {
            L.polyline(parteFutura, {
                color: '#0d6efd',
                weight: 4,
                opacity: 0.9,
                interactive: false
            }).addTo(mapaLayerGroup);
        }

        // --- CAMADA 3: LINHA GUIA DE DESVIO ---
        L.polyline([[latO, lngO], rastroCoords[indexCorteInicio]], {
            color: '#0d6efd',
            weight: 2,
            dashArray: '5, 5',
            opacity: 0.8
        }).addTo(mapaLayerGroup);
    }

    // --- CAMADA 4: RASTRO REAL (PRETO PONTILHADO) ---
    // Apenas se NÃO for inicial
    const pontosReais = Array.isArray(rastroReal) ? rastroReal : (rastroReal?.coords || []);
    if (tipo !== 'inicial' && pontosReais.length > 0) {
        const pathReal = [...pontosReais.map(c => [c[1], c[0]]), [latO, lngO]];
        const linhaReal = L.polyline(pathReal, {
            color: '#000', 
            weight: 3, 
            opacity: 0.7, 
            dashArray: '3, 6',
            interactive: false
        }).addTo(mapaLayerGroup);
        boundsTotal.extend(linhaReal.getBounds());
    }

    // --- CAMADA 5: MARCADORES ---
    if (todosPontos?.length) {
        todosPontos.forEach((p, i) => {
            const isFirst = i === 0;
            const isLast = i === todosPontos.length - 1;
            
            // Lógica de exibição dos ícones de bandeira
            if (isFirst || (tipo === 'final' && isLast)) {
                L.marker([p.lat, p.lng], { icon: isFirst ? icons.flagStart : icons.flagEnd }).addTo(mapaLayerGroup);
            } else if (tipo === 'final') {
                const passouVisualmente = (rastroCoords.length > 0) ? false : p.passou; 
                L.circleMarker([p.lat, p.lng], { 
                    radius: 4, 
                    fillColor: p.passou ? '#555' : '#0d6efd', 
                    color: 'transparent', 
                    fillOpacity: 0.9 
                }).bindPopup(`<b>${p.nome}</b>`).addTo(mapaLayerGroup);
            }
        });
    }

    // --- CAMADA 6: ÔNIBUS ---
    L.marker([latO, lngO], { icon: icons.bus, zIndexOffset: 1000 })
        .bindPopup(`<b>🚌 ${nomeO}</b>`)
        .addTo(mapaLayerGroup);
    
    // Garante que o zoom foca no Ônibus e no Destino (para garantir visibilidade da parte azul)
    boundsTotal.extend([latO, lngO]);
    if (!isNaN(latD) && !isNaN(lngD)) boundsTotal.extend([latD, lngD]);

    if (mapaInstancia && boundsTotal.isValid()) {
        mapaInstancia.fitBounds(boundsTotal, { padding: [50, 50], maxZoom: 16, animate: false });
    }
}

    // --- CAMADA 1: ROTA OFICIAL COMPLETA (VERMELHA) ---
    // Isso garante que você sempre veja o traçado oficial inteiro
    if (rastroCoords.length > 0) {
        const linhaVermelha = L.polyline(rastroCoords, {
            color: '#ff0505',  // Vermelho
            weight: 6,         // Espessura grossa para ser a base
            opacity: 0.4,      // Transparente para não ofuscar o resto
            interactive: false
        }).addTo(mapaLayerGroup);
        boundsTotal.extend(linhaVermelha.getBounds());

        // --- CAMADA 2: ROTA FUTURA (AZUL) ---
        // Desenha "por cima" da vermelha, apenas do ponto atual para frente
        const parteFutura = rastroCoords.slice(indexCorte);
        if (parteFutura.length > 1) {
            L.polyline(parteFutura, {
                color: '#0d6efd', // Azul forte
                weight: 4,        // Um pouco mais fina que a vermelha para dar efeito de "dentro"
                opacity: 0.9,
                interactive: false
            }).addTo(mapaLayerGroup);
        }

        // --- CAMADA 3: LINHA GUIA DE DESVIO ---
        // Se o ônibus estiver fora da rota, desenha uma linha pontilhada ligando ele à rota
        L.polyline([[latO, lngO], rastroCoords[indexCorte]], {
            color: '#0d6efd',
            weight: 2,
            dashArray: '5, 5',
            opacity: 0.8
        }).addTo(mapaLayerGroup);
    }

    // --- CAMADA 4: RASTRO REAL (PRETO PONTILHADO) ---
    // Histórico do GPS
    const pontosReais = Array.isArray(rastroReal) ? rastroReal : (rastroReal?.coords || []);
    if (pontosReais.length > 0) {
        const pathReal = [...pontosReais.map(c => [c[1], c[0]]), [latO, lngO]];
        const linhaReal = L.polyline(pathReal, {
            color: '#000', 
            weight: 3, 
            opacity: 0.7, 
            dashArray: '3, 6', // Pontilhado preto
            interactive: false
        }).addTo(mapaLayerGroup);
        boundsTotal.extend(linhaReal.getBounds());
    }

    // --- CAMADA 5: MARCADORES ---
    if (todosPontos?.length) {
        todosPontos.forEach((p, i) => {
            const isFirst = i === 0;
            const isLast = i === todosPontos.length - 1;
            
            if (isFirst || (tipo === 'final' && isLast)) {
                L.marker([p.lat, p.lng], { icon: isFirst ? icons.flagStart : icons.flagEnd }).addTo(mapaLayerGroup);
            } else if (tipo === 'final') {
                // Se o índice da parada for menor que o corte, ela fica cinza (passou)
                // Se for maior, fica azul (futuro) - Aproximação visual
                // Nota: Idealmente usaríamos a flag p.passou do backend se disponível
                const passouVisualmente = (rastroCoords.length > 0) ? false : p.passou; // Se temos rastro, cor é fixa, se não, usa flag
                
                L.circleMarker([p.lat, p.lng], { 
                    radius: 4, 
                    fillColor: p.passou ? '#555' : '#0d6efd', 
                    color: 'transparent', 
                    fillOpacity: 0.9 
                }).bindPopup(`<b>${p.nome}</b>`).addTo(mapaLayerGroup);
            }
        });
    }

    // --- CAMADA 6: ÔNIBUS ---
    L.marker([latO, lngO], { icon: icons.bus, zIndexOffset: 1000 })
        .bindPopup(`<b>🚌 ${nomeO}</b>`)
        .addTo(mapaLayerGroup);
    
    boundsTotal.extend([latO, lngO]);

    if (mapaInstancia && boundsTotal.isValid()) {
        mapaInstancia.fitBounds(boundsTotal, { padding: [50, 50], maxZoom: 16, animate: false });
    }
}

function ordenarTabela(n) {
    const tbody = document.getElementById("tabela-veiculos");
    const linhas = Array.from(tbody.rows);
    const asc = !direcaoAtual[n];
    direcaoAtual[n] = asc;
    linhas.sort((a, b) => {
        const cellA = a.children[colunaIndex(n)]?.innerText.trim().toLowerCase() || '';
        const cellB = b.children[colunaIndex(n)]?.innerText.trim().toLowerCase() || '';
        const numA = parseFloat(cellA.replace(',', '.'));
        const numB = parseFloat(cellB.replace(',', '.'));
        if (!isNaN(numA) && !isNaN(numB)) return asc ? numA - numB : numB - numA;
        return asc ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
    });
    linhas.forEach(linha => tbody.appendChild(linha));
}

function colunaIndex(chave) {
    const map = {'e':0, 'r':1, 'v':2, 'pi':4, 'ri':5, 'pf':6, 'u':8, 'st':9};
    return map[chave] !== undefined ? map[chave] : 0;
}

function verificarAlertas() {
    let clientesCriticos = {};
    document.querySelectorAll("#tabela-veiculos tr").forEach(linha => {
        if (linha.getAttribute("data-atraso-tipo") === "saida") {
            const empresa = linha.cells[0].innerText.trim(); 
            const rota = linha.cells[1].innerText.trim();
            const veiculo = linha.cells[2].innerText.trim();
            const horario = linha.cells[4].innerText.trim();
            if (!clientesCriticos[empresa]) clientesCriticos[empresa] = [];
            clientesCriticos[empresa].push({ veiculo, rota, horario });
        }
    });
    const total = Object.values(clientesCriticos).flat().length;
    if (total > 0) {
        let html = `<div class="alert alert-danger border-danger d-flex align-items-center mb-4"><i class="bi bi-megaphone-fill fs-3 me-3 text-danger"></i><div><strong>Saída Atrasada!</strong><br><b>${total}</b> veículo(s) atrasados.</div></div>`;
        for (const [cliente, lista] of Object.entries(clientesCriticos)) {
            html += `<h6 class="fw-bold mt-3 mb-2 text-dark">${cliente} (${lista.length})</h6><div class="list-group list-group-flush border rounded mb-4">` + lista.map(i => `<div class="list-group-item d-flex justify-content-between px-3 py-2"><div><span class="badge bg-dark me-2">${i.veiculo}</span><strong>${i.rota}</strong><br><small class="text-danger">Prog: ${i.horario}</small></div></div>`).join('') + `</div>`;
        }
        document.getElementById('alertModalBody').innerHTML = html;
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }
}

function buscarRastreamento(p,l,h,i,b) { processarBusca(p,l,h,i,b,'final'); }
function buscarRastreamentoinicial(p,l,h,i,b) { processarBusca(p,l,h,i,b,'inicial'); }

// --- FUNÇÃO TELA CHEIA ---
function toggleFullScreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(err => {
            console.log(`Erro ao tentar ativar tela cheia: ${err.message}`);
        });
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
}
</script>
</body>
</html>

