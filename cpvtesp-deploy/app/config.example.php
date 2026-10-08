<?php
// Copie este arquivo para config.php, na mesma pasta, e troque os três valores marcados com TROQUE.
// config.php guarda a senha do banco: não publique esse arquivo em repositório nem em captura de tela.
return [
    'host'    => 'TROQUE-PELO-ENDPOINT-DO-SEU-BANCO.us-east-1.rds.amazonaws.com', // RDS > o seu banco > Conectividade e segurança
    'porta'   => 3306,
    'banco'   => 'cadastro',          // a aplicação cria este banco na primeira visita, se ele não existir
    'usuario' => 'TROQUE-PELO-USUARIO-MESTRE',
    'senha'   => 'TROQUE-PELA-SENHA-MESTRE',
];
