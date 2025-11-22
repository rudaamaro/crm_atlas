<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
if (is_admin($user)) {
    redirect('dashboard_admin.php');
}
if (is_representante($user)) {
    redirect('dashboard_rep.php');
}

$pageTitle = 'Painel do Vendedor';
require __DIR__ . '/partials/header.php';
?>
<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Olá, <?= esc($user['name'] ?? ''); ?></h1>
        <p class="mt-1 text-sm text-slate-600">Você está logado como vendedor e pode acessar apenas seus próprios dados e formulários.</p>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-slate-800">Acesso rápido</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-700">
            <li>Para registrar um novo formulário, utilize o menu <strong>Atendimentos</strong>.</li>
            <li>Você só verá registros criados por você.</li>
            <li>Caso precise alterar seus dados, fale com o representante responsável.</li>
        </ul>
    </div>
</div>
<?php
require __DIR__ . '/partials/footer.php';
