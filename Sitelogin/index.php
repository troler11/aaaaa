<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Telemetria > Supabase (Token Hunter)</title>
    <style>
        body { font-family: monospace; background: #121212; color: #ccc; padding: 20px; }
        .log { border-bottom: 1px solid #333; padding: 4px 0; }
        .err { color: #ff5555; font-weight: bold; }
        .suc { color: #50fa7b; font-weight: bold; }
        .inf { color: #8be9fd; }
        .token { color: #ffb86c; font-weight: bold; border: 1px dashed #ffb86c; padding: 2px; }
    </style>
</head>
<body>
<h3>Processamento de Telemetria (V6 - WebSocket Token)</h3>
<div id="logs"></div>

<script>
    function log(msg, type='') {
        const d = document.createElement('div');
        d.className = 'log ' + type;
        d.innerText = '[' + new Date().toLocaleTimeString() + '] ' + msg;
        const box = document.getElementById('logs');
        box.appendChild(d);
        window.scrollTo(0, document.body.scrollHeight);
    }
</script>

<?php
// --- CONFIGURAÇÕES DE SERVIDOR ---
@apache_setenv('no-gzip', 1); 
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1); 
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);
set_time_limit(0); 
error_reporting(E_ALL); 
date_default_timezone_set('America/Sao_Paulo');

function jsLog($msg, $type='') {
    $c = addslashes($msg);
    echo "<script>log('$c', '$type');</script>";
    echo str_pad('', 4096)."\n"; 
    flush(); 
}

// ==========================================================
// 1. CONFIGURAÇÕES
// ==========================================================

// -> SUPABASE
$SB_HOST = "aws-1-us-east-2.pooler.supabase.com"; // Seu Host do Supabase
$SB_DB   = "postgres";
$SB_USER = "postgres.iztzyvygulxlavixngeo";
$SB_PASS = "Lukinha2009@"; // Senha do banco (não é a chave da API)
$SB_PORT = "6543";

// -> FULLTRACK
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";

// Arquivo Cookie
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $COOKIE_FILE = sys_get_temp_dir() . '\abm_cookie_ws.txt';
} else {
    $COOKIE_FILE = '/tmp/abm_cookie_ws.txt';
}

// Tokens LocationIQ
$GEO_TOKENS = [
    "pk.5dec6e778adac747992ee2564e7a57e1", "pk.74da1c80d1fdd103198bb2729dfc24b9"
];

// ==========================================================
// 2. FUNÇÕES DE REDE
// ==========================================================

function curl_req($method, $url, $cookie, $data=null, $headers=[]) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $defHeaders = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36",
        "Accept: application/json, text/javascript, */*; q=0.01"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defHeaders, $headers));

    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$res, $code, $err];
}

/**
 * LÓGICA DE CAÇA AO TOKEN
 */
function obterTokenFullTrack() {
    global $ABM_USER, $ABM_PASS, $COOKIE_FILE;
    
    // 1. Limpa cookies antigos
    if (file_exists($COOKIE_FILE)) unlink($COOKIE_FILE);
    
    jsLog("🔑 Fazendo Login...", "inf");

    // 2. Login
    list($res, $code, $err) = curl_req('POST', "https://abmtecnologia.abmprotege.net/emp/abmtecnologia", $COOKIE_FILE, 
        ["login"=>$ABM_USER, "senha"=>$ABM_PASS, "password"=>$ABM_PASS], ["Content-Type: application/x-www-form-urlencoded"]);

    if ($code != 200 || strpos($res, "erro") !== false) {
        jsLog("❌ Erro Login (HTTP $code).", "err");
        return false;
    }

    // 3. Acessa Mapa Geral para ler o HTML/JS
    jsLog("🕵️ Varrendo código fonte do Mapa Geral...", "inf");
    list($html, $codeMap, $errMap) = curl_req('GET', "https://abmtecnologia.abmprotege.net/mapaGeral", $COOKIE_FILE);
    
    $token = "";

    // ESTRATÉGIA DE REGEX PARA ENCONTRAR O TOKEN
    // Procuramos por strings de 40 caracteres hexadecimais (SHA1)
    
    // A. Procura por "authToken=" (igual na URL do WebSocket que você achou)
    if (preg_match('/authToken\s*[=:]\s*["\']?([a-f0-9]{40})["\']?/i', $html, $matches)) {
        $token = $matches[1];
        jsLog("🎯 Token achado via 'authToken': $token", "token");
    }
    // B. Procura por "token:" ou "token ="
    elseif (preg_match('/token\s*[:=]\s*["\']([a-f0-9]{40})["\']/i', $html, $matches)) {
        $token = $matches[1];
        jsLog("🎯 Token achado via variável 'token': $token", "token");
    }
    // C. Procura genérica: qualquer hash de 40 chars que esteja dentro de aspas simples ou duplas
    elseif (preg_match_all('/["\']([a-f0-9]{40})["\']/', $html, $matches)) {
        // Pega o último encontrado, pois geralmente tokens de sessão aparecem no final dos scripts de config
        $possiveis = $matches[1];
        $token = end($possiveis);
        jsLog("⚠️ Token inferido por padrão (último hash 40 chars): $token", "token");
    }

    if (!empty($token)) {
        return $token;
    }
    
    jsLog("❌ Token não encontrado no HTML. Verifique se o layout do site mudou.", "err");
    return false;
}

// ==========================================================
// 3. FLUXO PRINCIPAL
// ==========================================================

// Conexão DB
try {
    $pdo = new PDO("pgsql:host=$SB_HOST;port=$SB_PORT;dbname=$SB_DB;", $SB_USER, $SB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    jsLog("✅ DB Conectado.", "suc");
} catch (PDOException $e) { jsLog("❌ Erro DB: ".$e->getMessage(), "err"); }

$dt = new DateTime('yesterday');
$dtStr = $dt->format('d/m/Y');
jsLog("📅 Data: $dtStr", "inf");

// 1. OBTER TOKEN DINÂMICO
$bearerToken = obterTokenFullTrack();
if (!$bearerToken) exit;

// 2. API RELATÓRIO
$payload = [
    'id_cliente'=>'195577', 'id_motorista'=>'0', 'dt_inicial'=>"$dtStr 00:00:00", 'dt_final'=>"$dtStr 23:59:59",
    'id_indice'=>'7259', 'id_usuario'=>'250095', 'visualizar_por'=>'ativo'
];

$headersFT = ["Authorization: Bearer $bearerToken"];

jsLog("📡 Baixando Relatório...", "inf");
list($res, $code, $err) = curl_req('POST', "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/", $COOKIE_FILE, $payload, $headersFT);

if ($code != 200) {
    jsLog("❌ Erro API ($code).", "err");
    // Se falhar, tenta usar o cookie como autorização fallback
    jsLog("⚠️ Tentando fallback sem Bearer...", "warn");
    list($res, $code, $err) = curl_req('POST', "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/", $COOKIE_FILE, $payload);
    if ($code != 200) exit;
}

$json = json_decode($res, true);
if (!$json) { jsLog("❌ JSON Inválido.", "err"); exit; }

// 3. FLATTEN
$items = [];
foreach ($json as $i) {
    if (!isset($i['sub_table'])) continue;
    foreach ($i['sub_table'] as $sub) {
        if (!isset($sub['sub_table_infracao'])) continue;
        foreach ($sub['sub_table_infracao'] as $t) {
            if (in_array($t['tipo_infracao'], ["Motor Ocioso", "Banguela"])) continue;
            foreach ($t['infracoes'] ?? [] as $d) {
                $items[] = [
                    'ativo' => $i['descricao_ativo'], 'placa' => $i['tag_ativo'], 'mot' => $sub['descricao_motorista'],
                    't_inf' => $i['total_infracoes'], 't_pen' => $i['total_penalidade'], 'tipo' => $t['tipo_infracao'],
                    'id_ext' => $d['id_infracao'], 'data' => $d['data'], 'lat' => floatval($d['endereco']['lat']), 'lon' => floatval($d['endereco']['lon']),
                    'vel' => $d['velocidade'], 'pen' => $d['penalidade']
                ];
            }
        }
    }
}

$total = count($items);
jsLog("📋 Total: $total infrações.", "inf");
if ($total == 0) { jsLog("🎉 Sem dados.", "suc"); exit; }

// 4. PROCESSAMENTO
$chunks = array_chunk($items, 4);
$processed = 0;

foreach ($chunks as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    
    // Verifica Cache
    foreach ($batch as $k => $row) {
        $found = false;
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT endereco_completo FROM cache_enderecos WHERE latitude = :lat AND longitude = :lon");
                $stmt->execute([':lat' => $row['lat'], ':lon' => $row['lon']]);
                $cached = $stmt->fetchColumn();
                if ($cached) { $batch[$k]['end'] = $cached; $batch[$k]['src'] = 'cache'; $found = true; }
            } catch (Exception $e) {}
        }
        
        if (!$found) {
            if ($row['lat'] == 0) { $batch[$k]['end'] = 'N/A'; $batch[$k]['src'] = 'inv'; }
            else {
                $batch[$k]['src'] = 'api';
                $tk = $GEO_TOKENS[$k % count($GEO_TOKENS)];
                $url = "https://us1.locationiq.com/v1/reverse?key=$tk&lat={$row['lat']}&lon={$row['lon']}&format=json";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_multi_add_handle($mh, $ch);
                $handles[$k] = $ch;
            }
        }
    }
    
    $running = null; do { curl_multi_exec($mh, $running); } while ($running);

    // Salva
    foreach ($batch as $k => $row) {
        if (isset($handles[$k])) {
            $ch = $handles[$k];
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $geo = json_decode(curl_multi_getcontent($ch), true);
                $addr = ($geo['address']['road'] ?? '') . ", " . ($geo['address']['city'] ?? '');
                $batch[$k]['end'] = trim($addr, ", ");
                if (isset($pdo)) {
                    $pdo->prepare("INSERT INTO cache_enderecos (latitude, longitude, endereco_completo) VALUES (:l, :ln, :e) ON CONFLICT DO NOTHING")->execute([':l'=>$row['lat'], ':ln'=>$row['lon'], ':e'=>$batch[$k]['end']]);
                }
            } else { $batch[$k]['end'] = "Erro API Geo"; }
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }

        if (isset($pdo)) {
            try {
                $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $row['data']);
                $sql = "INSERT INTO relatorio_infracoes 
                    (codigo_ativo, placa, motorista, total_infracoes_geral, total_penalidades_geral, tipo_infracao, id_infracao_externo, data_infracao, velocidade, penalidade_valor, latitude, longitude, endereco)
                    VALUES (:ativo, :placa, :mot, :ti, :tp, :tip, :id, :dt, :vel, :pen, :lat, :lon, :end)
                    ON CONFLICT (id_infracao_externo) DO UPDATE SET endereco = EXCLUDED.endereco";
                $pdo->prepare($sql)->execute([
                    ':ativo'=>$row['ativo'], ':placa'=>$row['placa'], ':mot'=>$row['mot'], ':ti'=>$row['t_inf'], ':tp'=>$row['t_pen'],
                    ':tip'=>$row['tipo'], ':id'=>$row['id_ext'], ':dt'=>$dtObj ? $dtObj->format('Y-m-d H:i:s') : null,
                    ':vel'=>(int)$row['vel'], ':pen'=>$row['pen'], ':lat'=>$row['lat'], ':lon'=>$row['lon'], ':end'=>$batch[$k]['end']
                ]);
            } catch (Exception $e) { jsLog("Erro Insert: ".$e->getMessage(), "err"); }
        }
        $processed++;
    }
    if ($handles) curl_multi_close($mh);
    jsLog("✅ Processado: $processed / $total", "inf");
}

jsLog("🎉 Finalizado!", "suc");
?>
</body>
</html>
