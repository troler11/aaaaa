<?php
// Inicia sessão
session_start();

// Carrega configurações e conexão com BD ($pdo)
require_once 'config.php'; 

// --- 1. SEGURANÇA: Apenas ADMIN pode acessar ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Se o usuário logado NÃO for admin, manda de volta pro dashboard
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$msg = '';
$msg_tipo = ''; // success ou danger

// --- 2. PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // --- A. ADICIONAR USUÁRIO ---
    if ($acao === 'adicionar') {
        $nome = trim($_POST['full_name']);
        $user = trim($_POST['username']);
        $pass = $_POST['password']; // Senha pura
        $role = $_POST['role'];
        $comps_str = $_POST['companies']; // String separada por vírgula

        // Trata empresas: String "Meli, DHL" -> Array ["Meli", "DHL"] -> JSON
        $comps_array = array_filter(array_map('trim', explode(',', $comps_str)));
        $json_comps = !empty($comps_array) ? json_encode(array_values($comps_array)) : NULL;

        // Hash da senha
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, allowed_companies) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user, $hash, $nome, $role, $json_comps]);
            $msg = "Usuário criado com sucesso!";
            $msg_tipo = "success";
        } catch (PDOException $e) {
            $msg = "Erro ao criar: " . $e->getMessage();
            $msg_tipo = "danger";
        }
    }

    // --- B. EDITAR USUÁRIO ---
    elseif ($acao === 'editar') {
        $id = $_POST['user_id'];
        $nome = trim($_POST['full_name']);
        $user = trim($_POST['username']);
        $role = $_POST['role'];
        $comps_str = $_POST['companies'];
        $nova_senha = $_POST['password']; // Pode estar vazio

        $comps_array = array_filter(array_map('trim', explode(',', $comps_str)));
        $json_comps = !empty($comps_array) ? json_encode(array_values($comps_array)) : NULL;

        try {
            if (!empty($nova_senha)) {
                // Se digitou senha nova, atualiza tudo + senha
                $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=?, allowed_companies=?, password=? WHERE id=?");
                $stmt->execute([$nome, $user, $role, $json_comps, $hash, $id]);
            } else {
                // Se senha vazia, mantem a antiga
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=?, allowed_companies=? WHERE id=?");
                $stmt->execute([$nome, $user, $role, $json_comps, $id]);
            }
            $msg = "Usuário atualizado com sucesso!";
            $msg_tipo = "success";
        } catch (PDOException $e) {
            $msg = "Erro ao atualizar: " . $e->getMessage();
            $msg_tipo = "danger";
        }
    }

    // --- C. EXCLUIR USUÁRIO ---
    elseif ($acao === 'excluir') {
        $id = $_POST['user_id'];
        // Evita que o admin se exclua
        if ($id == $_SESSION['user_id']) {
            $msg = "Você não pode excluir a si mesmo!";
            $msg_tipo = "warning";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $msg = "Usuário removido.";
                $msg_tipo = "success";
            } catch (PDOException $e) {
                $msg = "Erro ao excluir: " . $e->getMessage();
                $msg_tipo = "danger";
            }
        }
    }
}

// --- 3. BUSCAR USUÁRIOS PARA LISTAGEM ---
$usuarios = $pdo->query("SELECT * FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Usuários - Viação Mimo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background-color: #0b1f3a; color: white; min-height: 100vh; width: 250px; position: fixed; z-index: 1000; }
        .sidebar a { color: #d1d5db; display: block; padding: 14px 20px; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; }
        .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; border-left-color: #0d6efd; }
        .content { margin-left: 250px; padding: 30px; }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column">
    <div class="text-center py-4 bg-dark bg-opacity-25">
        <h4 class="fw-bold text-white m-0">ABM Bus</h4>
        <small class="text-muted">Admin</small>
    </div>
    <a href="/"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
   <a href="#"><i class="bi bi-map me-2"></i>Rotas</a>
    <a href="#"><i class="bi bi-bus-front me-2"></i>Veículos</a>
    <a href="#"><i class="bi bi-person-vcard me-2"></i>Motoristas</a>
      <a href="relatorio.php"><i class="bi bi-bar-chart me-2"></i>Relatórios</a>
    <a href="admin.php" class="active"><i class="bi bi-people-fill me-2"></i>Usuários</a>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
</div>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark">Gestão de Usuários</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdicionar">
            <i class="bi bi-person-plus-fill me-2"></i>Novo Usuário
        </button>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msg_tipo; ?> alert-dismissible fade show" role="alert">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Nome Completo</th>
                            <th>Usuário (Login)</th>
                            <th>Nível</th>
                            <th>Empresas Permitidas</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($usuarios as $u): 
                            // Formata as empresas para exibição (Array -> String com vírgulas)
                            $comps_display = "Todas (Admin)";
                            $comps_data = "";
                            if (!empty($u['allowed_companies'])) {
                                $arr = json_decode($u['allowed_companies'], true);
                                if (is_array($arr) && count($arr) > 0) {
                                    $comps_display = implode(", ", $arr);
                                    $comps_data = implode(",", $arr);
                                }
                            }
                            if ($u['role'] == 'admin') $comps_display = '<span class="badge bg-dark">Acesso Total</span>';
                        ?>
                        <tr>
                            <td><?php echo $u['id']; ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td>
                                <span class="badge <?php echo $u['role'] === 'admin' ? 'bg-danger' : 'bg-info'; ?>">
                                    <?php echo strtoupper($u['role']); ?>
                                </span>
                            </td>
                            <td class="small text-secondary"><?php echo $comps_display; ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary me-1 btn-editar" 
                                    data-id="<?php echo $u['id']; ?>"
                                    data-nome="<?php echo htmlspecialchars($u['full_name']); ?>"
                                    data-user="<?php echo htmlspecialchars($u['username']); ?>"
                                    data-role="<?php echo $u['role']; ?>"
                                    data-comps="<?php echo htmlspecialchars($comps_data); ?>"
                                    data-bs-toggle="modal" data-bs-target="#modalEditar">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este usuário?');">
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAdicionar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin.php">
      <div class="modal-header">
        <h5 class="modal-title">Novo Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="acao" value="adicionar">
        
        <div class="mb-3">
            <label class="form-label">Nome Completo</label>
            <input type="text" class="form-control" name="full_name" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Usuário (Login)</label>
            <input type="text" class="form-control" name="username" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Senha</label>
            <input type="password" class="form-control" name="password" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Nível de Acesso</label>
            <select class="form-select" name="role">
                <option value="user">Usuário Comum (Restrito)</option>
                <option value="admin">Administrador (Total)</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Empresas (Separadas por vírgula)</label>
            <input type="text" class="form-control" name="companies" placeholder="Ex: RED BULL, MERCADO LIVRE">
            <div class="form-text">Deixe vazio se for Admin. Para usuários comuns, digite o nome exato que vem da API.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin.php">
      <div class="modal-header">
        <h5 class="modal-title">Editar Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="acao" value="editar">
        <input type="hidden" name="user_id" id="edit_id">
        
        <div class="mb-3">
            <label class="form-label">Nome Completo</label>
            <input type="text" class="form-control" name="full_name" id="edit_name" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Usuário (Login)</label>
            <input type="text" class="form-control" name="username" id="edit_user" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Nova Senha</label>
            <input type="password" class="form-control" name="password" placeholder="Deixe em branco para manter a atual">
        </div>
        <div class="mb-3">
            <label class="form-label">Nível de Acesso</label>
            <select class="form-select" name="role" id="edit_role">
                <option value="user">Usuário Comum (Restrito)</option>
                <option value="admin">Administrador (Total)</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Empresas (Separadas por vírgula)</label>
            <input type="text" class="form-control" name="companies" id="edit_comps">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Script para preencher o modal de edição
const editBtns = document.querySelectorAll('.btn-editar');
const editName = document.getElementById('edit_name');
const editUser = document.getElementById('edit_user');
const editRole = document.getElementById('edit_role');
const editComps = document.getElementById('edit_comps');
const editId = document.getElementById('edit_id');

editBtns.forEach(btn => {
    btn.addEventListener('click', function() {
        editId.value = this.getAttribute('data-id');
        editName.value = this.getAttribute('data-nome');
        editUser.value = this.getAttribute('data-user');
        editRole.value = this.getAttribute('data-role');
        editComps.value = this.getAttribute('data-comps');
    });
});
</script>
</body>
</html>
