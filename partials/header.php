<?php
require_once __DIR__ . '/../bootstrap.php';
$pageTitle = $pageTitle ?? APP_NAME;
$hideNav = $hideNav ?? false;
$user = current_user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle) ?> | <?= esc(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
<?php if (!$hideNav && $user): ?>
    <header class="bg-slate-900 text-white">
        <div class="max-w-6xl mx-auto px-4 py-3 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div class="text-lg font-semibold"><?= esc(APP_NAME) ?></div>
            <nav class="flex flex-wrap gap-3 text-sm font-medium">
                <a href="<?= is_admin($user) ? 'dashboard_admin.php' : 'dashboard_rep.php' ?>" class="hover:text-amber-300">Dashboard</a>
                <a href="atendimentos.php" class="hover:text-amber-300">Atendimentos</a>
                <?php if (is_admin($user)): ?>
                    <a href="usuarios.php" class="hover:text-amber-300">Usuarios</a>
                    <a href="municipios.php" class="hover:text-amber-300">Municipios</a>
                    <a href="duplicidades.php" class="hover:text-amber-300">Duplicidades</a>
                <?php elseif (is_representante($user)): ?>
                    <a href="usuarios.php" class="hover:text-amber-300">Vendedores</a>
                <?php endif; ?>
                
                <a href="logout.php" class="hover:text-amber-300">Sair</a>
            </nav>
        </div>
    </header>
<?php endif; ?>
    <main class="max-w-6xl mx-auto px-4 py-6">
<?php if ($flash = get_flash('auth')): ?>
        <div class="mb-4 rounded border-l-4 border-slate-900 bg-slate-100 px-4 py-3 text-slate-700">
            <?= esc($flash['message']) ?>
        </div>
<?php endif; ?>
<?php if ($flash = get_flash('status')): ?>
        <div class="mb-4 rounded border-l-4 border-emerald-500 bg-emerald-50 px-4 py-3 text-emerald-800">
            <?= esc($flash['message']) ?>
        </div>
<?php endif; ?>
<?php if ($flash = get_flash('error')): ?>
        <div class="mb-4 rounded border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-rose-800">
            <?= esc($flash['message']) ?>
        </div>
<?php endif; ?>

