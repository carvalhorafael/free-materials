# Changelog

## Nao publicado

- Adiciona `_free_materials_downloads`, contagem editorial de downloads que os
  consumidores podem exibir como prova social. Vazia por padrao.

- Adiciona metadados que descrevem o material: formato, paginas ou itens,
  tamanho do arquivo, para quem serve, lista do que tem dentro e destaque no
  catalogo. Todos com chave propria `_free_materials_*`, expostos no REST e
  editaveis por um painel no editor.
- Adiciona `free_materials_formats()` e o filtro `free_materials_formats`, com
  os formatos conhecidos e validacao contra a lista no save.
- Corrige a exposicao dos metadados no REST. O post type nao declarava suporte
  a `custom-fields`, entao o WordPress nunca adicionava o campo `meta` a
  resposta e nenhuma chave registrada era alcancavel por REST, inclusive as de
  captura que ja existiam.

## 0.2.0 - capture UI decoupling

- Removed the plugin-owned lead capture meta box from the material editor.
- Added generic public helpers for capture destination and delivery URL meta keys.
- Kept legacy Brevo meta keys available for backward compatibility.

## 0.1.0 - initial release

- Initial plugin foundation for the Free Materials content domain.
