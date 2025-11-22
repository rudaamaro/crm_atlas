<?php
require_once __DIR__ . '/bootstrap.php';

ensure_logged_redirect();

if (is_post()) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    remember_old(['email' => $email]);

    if ($email === '' || $password === '') {
        flash('error', 'Informe e-mail e senha.');
    } elseif (login($email, $password)) {
        $user = current_user();
        if ($user) {
            if ($user['email'] === DEFAULT_ADMIN_EMAIL) {
                flash('status', 'Voce esta usando a senha padrao. Altere assim que possivel.');
            }
            redirect_dashboard($user);
        }
        redirect('dashboard_admin.php');
    } else {
        flash('error', 'Credenciais invalidas ou Usuario inativo.');
    }
}

$pageTitle = 'Login';
$hideNav = true;
require __DIR__ . '/partials/header.php';
?>
<div class="flex justify-center">
    <div class="w-full max-w-md rounded-lg bg-white p-8 shadow">
        <h1 class="mb-6 text-center text-2xl font-semibold text-slate-800">Acesso ao Sistema</h1>
        <form method="post" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-600" for="email">E-mail</label>
                <input type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required class="w-full rounded border border-slate-200 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-600" for="password">Senha</label>
                <input type="password" id="password" name="password" required class="w-full rounded border border-slate-200 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
            </div>
            <button type="submit" class="w-full rounded bg-slate-900 py-2 text-sm font-semibold text-white hover:bg-slate-800">Entrar</button>
        </form>
        <p class="mt-6 text-center text-xs text-slate-500">
        </p>
    </div>
</div>
<?php
clear_old();
require __DIR__ . '/partials/footer.php';






