<?php
// Inicia sessão e verifica segurança
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php'; 
require_once 'includes/page_logic.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Carrega os dados brutos
$empresas_permitidas = $_SESSION['allowed_companies'] ?? [];
$dados = handle_index_data($empresas_permitidas);
$linhas_totais = $dados['todas_linhas'] ?? [];

// --- 1. PREPARAÇÃO DOS FILTROS ---
$lista_empresas = [];
foreach ($linhas_totais as $l) {
    $nome_emp = $l['empresa']['nome'] ?? 'N/D';
    if ($nome_emp !== 'N/D') {
        $lista_empresas[$nome_emp] = $nome_emp;
    }
}
sort($lista_empresas);

$filtro_empresa = isset($_GET['empresa']) ? $_GET['empresa'] : '';
$filtro_sentido = isset($_GET['sentido']) ? $_GET['sentido'] : '';

// --- FUNÇÕES AUXILIARES PHP ---
date_default_timezone_set('America/Sao_Paulo');

function calcularDiffMinutos($horaProg, $horaReal) {
    if ($horaProg == 'N/D' || $horaReal == 'N/D' || empty($horaReal)) return 0;
    $tProg = strtotime($horaProg);
    $tReal = strtotime($horaReal);
    return ($tReal - $tProg) / 60;
}

function calcularTempoRestante($timestampOuHora) {
    if (empty($timestampOuHora) || $timestampOuHora == 'N/D') return 'N/D';
    
    if (is_numeric($timestampOuHora)) {
        $fim = $timestampOuHora;
    } else {
        $fim = strtotime($timestampOuHora);
        // Ajuste para virada de dia
        if ($fim < time() - 43200) $fim += 86400;
    }
    
    $agora = time();
    $diff = round(($fim - $agora) / 60);
    
    if ($diff < 0) return "Encerrando...";
    return $diff . " min";
}

// --- 2. PROCESSAMENTO ---
$qtd_atraso_inicio = 0;
$qtd_sem_atraso = 0;
$qtd_aguardando = 0;
$linhas_filtradas = []; 

foreach ($linhas_totais as $l) {
    // Normalização Sentido
    $sIdaRaw = $l['sentidoIDA'] ?? $l['sentidoIda'] ?? true;
    $is_entrada = filter_var($sIdaRaw, FILTER_VALIDATE_BOOLEAN);

    // Filtros
    $empresa_atual = $l['empresa']['nome'] ?? 'N/D';
    if (!empty($filtro_empresa) && $empresa_atual !== $filtro_empresa) continue; 
    if (!empty($filtro_sentido)) {
        if ($filtro_sentido === 'entrada' && !$is_entrada) continue;
        if ($filtro_sentido === 'saida' && $is_entrada) continue;
    }

    // Dados visuais
    $l['label_sentido'] = $is_entrada ? 'Entrada' : 'Saída';
    $l['class_sentido'] = $is_entrada ? 'bg-primary' : 'bg-warning text-dark';

    $linhas_filtradas[] = $l;

    // Estatísticas
    $prog = $l['horarioProgramado'] ?? 'N/D';
    $real = $l['horarioReal'] ?? 'N/D';
    
    if ($real == 'N/D') {
        $qtd_aguardando++;
    } else {
        $diff = calcularDiffMinutos($prog, $real);
        if ($diff > 10) $qtd_atraso_inicio++;
        else $qtd_sem_atraso++;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Viação Mimo - Relatório Operacional</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background-color: #0b1f3a; color: white; min-height: 100vh; width: 250px; position: fixed; z-index: 1000; }
        .sidebar a { color: #d1d5db; display: block; padding: 14px 20px; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; }
        .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; border-left-color: #0d6efd; }
        .content { margin-left: 250px; padding: 30px; }
        .card-relatorio { border: none; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); color: white; }
        .table-relatorio th { background-color: #e9ecef; color: #495057; font-weight: 600; }
        .mini-loader { width: 0.8rem; height: 0.8rem; border-width: 0.12em; }
        @media print {
            .sidebar, .btn-print, .filter-section { display: none !important; }
            .content { margin-left: 0; padding: 0; }
        }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column">
    <div class="text-center py-4 bg-dark bg-opacity-25">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo" style="max-width: 160px;">
    </div>
    <a href="/"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
    <a href="#"><i class="bi bi-map me-2"></i>Rotas</a>
    <a href="#"><i class="bi bi-bus-front me-2"></i>Veículos</a>
    <a href="#"><i class="bi bi-person-vcard me-2"></i>Motoristas</a>
    <a href="relatorio.php" class="active"><i class="bi bi-file-earmark-text me-2"></i>Relatórios</a>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin.php"><i class="bi bi-people-fill me-2"></i>Usuários</a>
    <?php endif; ?>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
</div>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">Relatório de Execução</h3>
            <p class="text-muted mb-0">Status de início e previsão de término das linhas.</p>
        </div>
        <button class="btn btn-outline-dark btn-print" onclick="window.print()">
            <i class="bi bi-printer me-2"></i>Imprimir
        </button>
    </div>

    <div class="card border-0 shadow-sm mb-4 filter-section">
        <div class="card-body py-3">
            <form method="GET" class="row align-items-center g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold text-secondary small mb-1"><i class="bi bi-building me-1"></i>Cliente</label>
                    <select name="empresa" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos os Clientes</option>
                        <?php foreach ($lista_empresas as $emp): ?>
                            <option value="<?php echo htmlspecialchars($emp); ?>" <?php echo ($filtro_empresa === $emp) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold text-secondary small mb-1"><i class="bi bi-signpost-split me-1"></i>Sentido</label>
                    <select name="sentido" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="entrada" <?php echo ($filtro_sentido === 'entrada') ? 'selected' : ''; ?>>➡️ Entrada (Ida)</option>
                        <option value="saida" <?php echo ($filtro_sentido === 'saida') ? 'selected' : ''; ?>>⬅️ Saída (Volta)</option>
                    </select>
                </div>
                <?php if(!empty($filtro_empresa) || !empty($filtro_sentido)): ?>
                <div class="col-auto align-self-end">
                    <a href="relatorio.php" class="btn btn-outline-danger btn-sm mb-1"><i class="bi bi-x-lg me-1"></i>Limpar</a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-relatorio bg-success p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-uppercase mb-1 opacity-75">Iniciadas sem Atraso</h6><h2 class="mb-0 fw-bold"><?php echo $qtd_sem_atraso; ?></h2></div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-relatorio bg-danger p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-uppercase mb-1 opacity-75">Iniciadas com Atraso (>10min)</h6><h2 class="mb-0 fw-bold"><?php echo $qtd_atraso_inicio; ?></h2></div>
                    <i class="bi bi-exclamation-triangle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-relatorio bg-secondary p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-uppercase mb-1 opacity-75">Aguardando Início</h6><h2 class="mb-0 fw-bold"><?php echo $qtd_aguardando; ?></h2></div>
                    <i class="bi bi-hourglass-split fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <?php if(count($linhas_filtradas) > 0): ?>
                <table class="table table-hover table-striped align-middle mb-0 table-relatorio">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Sentido</th>
                            <th>Linha / Rota</th>
                            <th>Veículo</th>
                            <th>Prog. Início</th>
                            <th>Real Início</th>
                            <th>Status Início</th>
                            <th>Previsão Término</th>
                            <th>Tempo Restante</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linhas_filtradas as $l): 
                            $empresa = $l['empresa']['nome'] ?? 'N/D';
                            $rota = $l['descricaoLinha'] ?? 'N/D';
                            $placa_clean = htmlspecialchars($l['veiculo']['veiculo'] ?? '', ENT_QUOTES);
                            $veiculo = !empty($placa_clean) ? $placa_clean : 'N/D';
                            
                            $prog = $l['horarioProgramado'] ?? 'N/D';
                            $real = $l['horarioReal'] ?? 'N/D';
                            $prog_fim = $l['horariofinalProgramado'] ?? 'N/D';
                            
                            // Definição de IDs para o JS atualizar
                            $id_prev_termino = "prev-termino-" . $placa_clean;
                            $id_tempo_rest = "tempo-rest-" . $placa_clean;
                            
                            // Determina se deve ativar o JS para esta linha
                            $ja_saiu = ($real != 'N/D');
                            $categoria = $l['categoria'] ?? '';
                            // Calcula se: Saiu, não está desligado. 
                            // (Nota: O JS vai tentar buscar o dado mais recente da API)
                            $deve_calcular = ($ja_saiu && $categoria != 'Carro desligado') ? 'true' : 'false';

                            // --- CÁLCULO INICIAL PHP (Fallback) ---
                            // Se não tiver JS ou API falhar, isso será exibido
                            $status_badge = '';
                            $prev_termino_exibicao = '-';
                            $tempo_restante_exibicao = '-';

                            if ($real == 'N/D') {
                                $status_badge = '<span class="badge bg-light text-dark border">Aguardando</span>';
                                $prev_termino_exibicao = $prog_fim; 
                            } else {
                                $diff = calcularDiffMinutos($prog, $real);
                                if ($diff > 10) {
                                    $status_badge = '<span class="badge bg-danger">Iniciada com Atraso ('.$diff.' min)</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-success">Iniciada sem Atraso</span>';
                                }

                                // Lógica de Projeção PHP (Atraso Saída + Prog Fim)
                                // O JS vai sobrescrever isso com dados LIVE se conseguir
                                if ($prog_fim != 'N/D' && $prog != 'N/D') {
                                    $minutos_atraso_saida = calcularDiffMinutos($prog, $real);
                                    $ts_prog_fim = strtotime($prog_fim);
                                    if ($ts_prog_fim < strtotime($prog)) $ts_prog_fim += 86400;
                                    $ts_previsto_calc = $ts_prog_fim + ($minutos_atraso_saida * 60);
                                    
                                    $prev_termino_exibicao = date('H:i', $ts_previsto_calc) . ' <small class="text-secondary">(Est)</small>';
                                    $tempo_restante_exibicao = calcularTempoRestante($ts_previsto_calc);
                                } else {
                                    $prev_termino_exibicao = $prog_fim;
                                }
                            }
                        ?>
                      <?php $id_linha = $l['idLinha'] ?? ''; // Pega o ID único da linha ?>
<tr class="linha-relatorio" data-placa="<?php echo $placa_clean; ?>" data-id="<?php echo $id_linha; ?>" data-calcular="<?php echo $deve_calcular; ?>">
                            <td class="fw-bold text-secondary small"><?php echo $empresa; ?></td>
                            <td><span class="badge rounded-pill <?php echo $l['class_sentido']; ?>"><?php echo $l['label_sentido']; ?></span></td>
                            <td><?php echo $rota; ?></td>
                            <td class="fw-bold"><?php echo $veiculo; ?></td>
                            <td><?php echo $prog; ?></td>
                            <td><?php echo $real; ?></td>
                            <td><?php echo $status_badge; ?></td>
                            
                            <td id="<?php echo $id_prev_termino; ?>" class="fw-bold text-dark">
                                <?php echo $prev_termino_exibicao; ?>
                            </td>
                            <td id="<?php echo $id_tempo_rest; ?>" class="fw-bold text-primary">
                                <?php echo $tempo_restante_exibicao; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-search fs-1"></i>
                        <p class="mt-2">Nenhuma linha encontrada para os filtros selecionados.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Carrega imediatamente ao abrir
    carregarPrevisoesLive();

    // 2. Configura para atualizar a cada 3 min
    setInterval(() => {
        console.log("Atualizando previsões live...");
        carregarPrevisoesLive();
    }, 180000);
});

async function processarEmLotes(items, limite, callback) {
    let index = 0;
    const executar = async () => {
        if (index >= items.length) return;
        const lote = Array.from(items).slice(index, index + limite);
        index += limite;
        const promessas = lote.map(item => callback(item));
        await Promise.all(promessas);
        await executar();
    };
    await executar();
}

async function carregarPrevisoesLive() {
    // Seleciona apenas linhas que já saíram
    const linhas = document.querySelectorAll('.linha-relatorio[data-calcular="true"]');
    
    await processarEmLotes(linhas, 5, async (tr) => {
        const placa = tr.getAttribute('data-placa');
        const idLinha = tr.getAttribute('data-id'); 
        
        // Elementos para atualizar
        const cellPrev = document.getElementById('prev-termino-' + placa);
        const cellRest = document.getElementById('tempo-rest-' + placa);
        
        if (!placa || !cellPrev) return;

        // Opcional: Colocar um "..." discreto ou mudar cor para indicar que está checando
        // cellRest.style.opacity = "0.5";

        try {
            // --- CORREÇÃO IMPORTANTE: O nome do parâmetro deve ser idLinha para bater com o PHP ---
            const response = await fetch(`/previsao/${placa}?idLinha=${idLinha}`);
            const data = await response.json();

            if (data.duracaoSegundos) {
                // --- CÁLCULO MATEMÁTICO LIVE ---
                const agora = new Date();
                const chegada = new Date(agora.getTime() + data.duracaoSegundos * 1000);
                
                // Formata Hora Chegada
                const h = String(chegada.getHours()).padStart(2, '0');
                const m = String(chegada.getMinutes()).padStart(2, '0');
                const horarioChegadaLive = `${h}:${m}`;

                // Formata Tempo Restante (Minutos)
                const minutosRestantes = Math.round(data.duracaoSegundos / 60);

                // Atualiza HTML com animação visual de sucesso
                cellPrev.innerHTML = `${horarioChegadaLive} <small class="text-success fw-bold">(Live)</small>`;
                
                // Muda cor dependendo do tempo
                if(minutosRestantes < 10) {
                    cellRest.className = "fw-bold text-danger blink-animation"; // Piscando se estiver chegando
                } else {
                    cellRest.className = "fw-bold text-primary";
                }
                cellRest.innerHTML = `${minutosRestantes} min`;
            }
        } catch (error) {
            console.warn(`Erro ao buscar previsão para ${placa}`, error);
        } finally {
            // cellRest.style.opacity = "1";
        }
    });
}
</script>

</body>
</html>
