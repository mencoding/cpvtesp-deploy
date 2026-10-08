# cpvtesp-deploy

Pacote da aplicação de cadastro usada no encontro 12 da disciplina CPVTESP — Tópicos Especiais (TADS, IFSP Campus Capivari, 2026-2): pôr uma aplicação PHP com banco MySQL no ar no sandbox do AWS Academy.

- `cpvtesp-deploy.tar.gz` — o pacote que o roteiro da aula baixa na instância.
- `cpvtesp-deploy/` — o mesmo conteúdo, aberto para leitura: `app/index.php`, `app/config.example.php` e `encerrar.sh`.

Download na instância:

```bash
curl -L -o pacote.tar.gz https://raw.githubusercontent.com/mencoding/cpvtesp-deploy/main/cpvtesp-deploy.tar.gz
sha256sum pacote.tar.gz
```

SHA-256 do pacote: `b44db950bef0f67646762172f4653a37229978a7e3a45d375595d64ab5871cdf`

O `encerrar.sh` só remove recursos com a etiqueta `Atividade = cpvtesp-deploy` e se recusa a rodar fora do sandbox do AWS Academy.
