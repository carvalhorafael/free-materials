# Instrucoes para agentes

## Contexto do projeto

Este repositorio contem o plugin WordPress `free-materials`.

O objetivo do plugin e ser dono do dominio persistente de materiais gratuitos:
post type, taxonomia, metadados editoriais e rewrites. Temas devem consumir
esse contrato para renderizacao, mas nao devem registrar novamente esse dominio.

## Fronteira de responsabilidade

O plugin e responsavel por:

- registrar `material_gratuito`;
- registrar `material_categoria`;
- registrar metadados canonicos de captura;
- expor funcoes/constantes publicas estaveis;
- manter rewrites e flush em ativacao/desativacao;
- fornecer testes para o contrato WordPress.

O plugin nao e responsavel por:

- layout publico;
- templates de tema;
- envio de leads para Brevo;
- automacoes de CRM;
- regras visuais de um tema especifico.

## Regras de trabalho no repositorio

- Leia o codigo e a documentacao antes de alterar contratos publicos.
- Preserve os slugs e meta keys existentes, salvo decisao explicita de migracao.
- Use prefixo interno `free_materials_` para funcoes publicas do plugin e
  `Free_Materials_` para classes.
- Quando expor hooks/options novos, prefira nomes especificos e documente no
  README ou em `docs/`.
- Adicione testes proporcionais ao risco da mudanca.
- Sanitizar toda entrada e escapar toda saida.
- Validar capabilities e nonces em qualquer acao administrativa.
- Internacionalizar textos visiveis com text domain `free-materials`.

## Testes automatizados

- Use `composer test:unit` para validacao rapida de regras puras.
- Use `composer test:wordpress` para hooks WordPress, registros de CPT,
  taxonomia, metadados, meta boxes, nonces e rewrites.
- Use `composer test` antes de considerar uma mudanca pronta.
- Se alterar empacotamento ou release, rode `composer package` e confira o ZIP.

## Seguranca e repositorio publico

Este repositorio deve permanecer seguro para publicacao no GitHub.

- Nunca versionar credenciais, tokens, dumps de banco, logs com dados pessoais,
  `.env` reais ou arquivos locais de credencial.
- Nao incluir `vendor/`, `build/`, `dist/`, caches ou artefatos locais em commits.
- Antes de commitar, revisar `git status --short` e o diff.

## Fluxo de branches

Regra padrao:

- nao desenvolver diretamente em `main`;
- usar `develop` como branch auxiliar de integracao;
- criar branches de trabalho com prefixo `codex/`;
- abrir PRs pequenos para `develop`;
- publicar releases apenas quando a versao preparada chegar em `main`.

Antes de comecar uma nova tarefa, sempre verificar:

```bash
git status --short --branch
git branch -vv
```

## Fluxo de release

Quando o usuario pedir uma nova release:

1. Confirmar base em `develop`.
2. Usar o workflow `Prepare Release`.
3. Mergear a PR `release/vX.Y.Z` em `develop`.
4. Abrir ou acompanhar PR de `develop` para `main`.
5. Acompanhar o workflow `Release` em `main`.
6. Conferir asset `free-materials-X.Y.Z.zip` na GitHub Release.
