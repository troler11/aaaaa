<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Telemetria > Supabase (TESTE 10 LINHAS)</title>
    <style>
        body { font-family: monospace; background: #121212; color: #ccc; padding: 20px; }
        .log { border-bottom: 1px solid #333; padding: 4px 0; }
        .err { color: #ff5555; font-weight: bold; background: #330000; padding: 5px; }
        .suc { color: #50fa7b; font-weight: bold; }
        .inf { color: #8be9fd; }
        .warn { color: #f1fa8c; font-weight: bold; }
    </style>
</head>
<body>
<h3>Processamento de Telemetria (V10 - Limite 10 Linhas)</h3>
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
// --- CONFIGURAÇÕES DO SERVIDOR ---
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

$SB_HOST = "aws-1-us-east-2.pooler.supabase.com"; // Seu Host do Supabase
$SB_DB   = "postgres";
$SB_USER = "postgres.iztzyvygulxlavixngeo";
$SB_PASS = "Lukinha2009@"; // Senha do banco (não é a chave da API)
$SB_PORT = "6543";

$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";
$URL_BASE = "https://abmtecnologia.abmprotege.net";

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') $COOKIE_FILE = sys_get_temp_dir() . '\abm_cookie_test.txt';
else $COOKIE_FILE = '/tmp/abm_cookie_test.txt';

$GEO_TOKENS = ["pk.5dec6e778adac747992ee2564e7a57e1", "pk.74da1c80d1fdd103198bb2729dfc24b9"];

// ==========================================================
// 2. FUNÇÕES
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
    
    $defHeaders = ["User-Agent: Mozilla/5.0", "Accept: application/json"];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defHeaders, $headers));

    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $urlF = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['body' => $res, 'code' => $code, 'url' => $urlF];
}

function realizarLogin() {
    global $ABM_USER, $ABM_PASS, $COOKIE_FILE, $URL_BASE;
    if (file_exists($COOKIE_FILE)) unlink($COOKIE_FILE);
    jsLog("🔑 Login...", "inf");
    $resp = curl_req('POST', "$URL_BASE/emp/abmtecnologia", $COOKIE_FILE, 
        ["login"=>$ABM_USER, "senha"=>$ABM_PASS, "password"=>$ABM_PASS], 
        ["Content-Type: application/x-www-form-urlencoded", "Origin: $URL_BASE", "Referer: $URL_BASE/emp/abmtecnologia"]
    );
    if ($resp['code'] != 200 || strpos($resp['url'], "emp/abmtecnologia") !== false) return false;
    return true;
}

function obterTokenOficial() {
    global $COOKIE_FILE, $URL_BASE;
    jsLog("🔄 Pedindo Token...", "inf");
    $resp = curl_req('POST', "$URL_BASE/token/Api_ftk4", $COOKIE_FILE, "", ["Content-Length: 0", "Origin: $URL_BASE", "Referer: $URL_BASE/dashboard_controller", "X-Requested-With: XMLHttpRequest"]);
    $json = json_decode($resp['body'], true);
    if (isset($json['access_token'])) return $json['access_token'];
    return false;
}

// ==========================================================
// 3. FLUXO
// ==========================================================

try {
    $dsn = "pgsql:host=$SB_HOST;port=$SB_PORT;dbname=$SB_DB;";
    $pdo = new PDO($dsn, $SB_USER, $SB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    jsLog("✅ DB Conectado.", "suc");
} catch (PDOException $e) { jsLog("❌ Erro DB: " . $e->getMessage(), "err"); exit; }

$dt = new DateTime('yesterday');
$dtStr = $dt->format('d/m/Y');
jsLog("📅 Data: $dtStr", "inf");

if (!realizarLogin()) { jsLog("❌ Falha Login", "err"); exit; }
$bearerToken = obterTokenOficial();
if (!$bearerToken) { jsLog("❌ Falha Token", "err"); exit; }

$payload = ['id_cliente'=>'195577', 'id_motorista'=>'0', 'dt_inicial'=>"$dtStr 00:00:00", 'dt_final'=>"$dtStr 23:59:59", 'id_indice'=>'7259', 'id_usuario'=>'250095', 'visualizar_por'=>'ativo'];
$headersFT = ["Authorization: Bearer $bearerToken"];

jsLog("📡 Baixando dados...", "inf");
$resp = curl_req('POST', "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/", $COOKIE_FILE, $payload, $headersFT);

if ($resp['code'] != 200) { jsLog("❌ Erro API: ".$resp['code'], "err"); exit; }
$json = json_decode($resp['body'], true);

// Flatten
$items = [];
if ($json) {
    foreach ($json as $i) {
        if (!isset($i['sub_table'])) continue;
        foreach ($i['sub_table'] as $sub) {
            foreach ($sub['sub_table_infracao'] ?? [] as $t) {
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
}

$totalTotal = count($items);
jsLog("📋 Total Encontrado: $totalTotal", "inf");

// --- LIMITADOR DE TESTE ---
if ($totalTotal > 10) {
    jsLog("🧪 MODO TESTE ATIVO: Cortando para 10 itens.", "warn");
    $items = array_slice($items, 0, 10);
}
// --------------------------

$chunks = array_chunk($items, 4);
$processed = 0;

foreach ($chunks as $batch) {
    // 1. GEO
    $mh = curl_multi_init(); $handles = [];
    foreach ($batch as $k => $row) {
        if ($row['lat'] == 0) { $batch[$k]['end'] = 'N/A'; }
        else {
            $tk = $GEO_TOKENS[$k % 2];
            $ch = curl_init("https://us1.locationiq.com/v1/reverse?key=$tk&lat={$row['lat']}&lon={$row['lon']}&format=json");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_multi_add_handle($mh, $ch); $handles[$k] = $ch;
        }
    }
    $run = null; do { curl_multi_exec($mh, $run); } while ($run);
    foreach ($batch as $k => $r) {
        if (isset($handles[$k])) {
            $info = curl_getinfo($handles[$k]);
            if ($info['http_code'] == 200) {
                $geo = json_decode(curl_multi_getcontent($handles[$k]), true);
                $addr = ($geo['address']['road']??'') . ", " . ($geo['address']['city']??'');
                $batch[$k]['end'] = trim($addr, ", ");
            } else $batch[$k]['end'] = "Erro API";
            curl_multi_remove_handle($mh, $handles[$k]); curl_close($handles[$k]);
        }
    }

    // 2. INSERT
    foreach ($batch as $row) {
        try {
            $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $row['data']);
            $sql = "INSERT INTO relatorio_infracoes 
                (codigo_ativo, placa, motorista, total_infracoes_geral, total_penalidades_geral, tipo_infracao, id_infracao_externo, data_infracao, velocidade, penalidade_valor, latitude, longitude, endereco)
                VALUES (:ativo, :placa, :mot, :ti, :tp, :tip, :id, :dt, :vel, :pen, :lat, :lon, :end)
                ON CONFLICT (id_infracao_externo) DO UPDATE SET endereco = EXCLUDED.endereco";
            
            $pdo->prepare($sql)->execute([
                ':ativo'=>$row['ativo'], ':placa'=>$row['placa'], ':mot'=>$row['mot'], 
                ':ti'=>(int)$row['t_inf'], ':tp'=>(int)$row['t_pen'], ':tip'=>$row['tipo'], 
                ':id'=>$row['id_ext'], ':dt'=>$dtObj ? $dtObj->format('Y-m-d H:i:s') : null, 
                ':vel'=>(int)$row['vel'], ':pen'=>$row['pen'], ':lat'=>$row['lat'], ':lon'=>$row['lon'], ':end'=>$row['end'] ?? 'N/A'
            ]);
            $processed++;
        } catch (PDOException $e) {
            jsLog("❌ ERRO SQL: " . $e->getMessage(), "err");
        }
    }
    jsLog("✅ Salvos: $processed", "suc");
}
jsLog("🏁 Teste Finalizado!", "suc");
?>
</body>
</html>
