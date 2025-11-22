<?php
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    logout();
    flash('status', 'Sessao encerrada. Ate breve!');
}

redirect('index.php');






