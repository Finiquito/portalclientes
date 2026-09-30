# Web Interface Guidelines — checklist condensado

Fonte: Rauno Freiberg, https://interfaces.rauno.me (lista viva; este arquivo é um resumo em
checklist, não uma cópia). WAI-ARIA não é duplicado aqui — consultar a spec para acessibilidade
completa. Usar em `harden`, `polish` e no checklist final. Cada item vem com o *porquê* quando
ele não é óbvio — citar o motivo ao corrigir, não só o item.

## Interatividade (forms e controles)
- [ ] Clicar no `<label>` foca o input (`for`/`id` ou label envolvendo o input).
- [ ] Inputs dentro de `<form>` → Enter envia.
- [ ] `type` correto (`email`, `password`, `tel`, `url`, `number`).
- [ ] `spellcheck="false"` e `autocomplete` adequados; `required` para validação nativa.
- [ ] Ícone de prefixo/sufixo dentro do input (posicionado absoluto + padding), clicável → foca o campo. Nunca "ao lado".
- [ ] Mudanças de configuração aplicam na hora, sem botão "confirmar".
- [ ] Botão desabilita após submit → evita requisição duplicada.
- [ ] `user-select: none` em conteúdo interno de elementos interativos (texto de botão não deve ser selecionável ao clicar).
- [ ] Camadas decorativas (brilho, gradiente, glow) com `pointer-events: none` → não roubam clique.
- [ ] Itens de lista/menus sem "buraco" entre eles: aumentar `padding`, não `margin`, para a área clicável ser contínua.

## Tipografia
- [ ] `-webkit-font-smoothing: antialiased` e `text-rendering: optimizeLegibility`.
- [ ] Fonte escolhida para o alfabeto/idioma do conteúdo.
- [ ] Peso da fonte **não muda** em hover/seleção → evita layout shift.
- [ ] Nada abaixo de peso 400. Títulos médios ficam melhores em 500–600.
- [ ] Tamanhos fluidos com `clamp()` (ex.: `clamp(48px, 5vw, 72px)` em heading).
- [ ] `font-variant-numeric: tabular-nums` em tabelas, timers, preços — números não "dançam".
- [ ] `-webkit-text-size-adjust: 100%` → iOS não redimensiona texto em paisagem.

## Movimento
- [ ] Trocar tema (dark/light) **não** dispara transições nos elementos (desabilitar temporariamente; `next-themes` já faz isso).
- [ ] Animações ≤ 200 ms para parecer imediato.
- [ ] Escala proporcional ao gatilho: diálogo entra de ~0.8 → 1 com fade, não de 0; botão pressionado vai para ~0.96/0.9, não 0.8.
- [ ] Ações frequentes e sem novidade **sem** animação: menu de contexto, adicionar/remover item de lista, hover trivial (macOS só anima o menu ao fechar, não ao abrir).
- [ ] Animações em loop pausam fora da viewport (CPU/GPU).
- [ ] `scroll-behavior: smooth` para âncoras, com offset do header fixo.

## Toque (mobile)
- [ ] Hover só em dispositivos com ponteiro: `@media (hover: hover)` → touch não fica "preso" no estado hover.
- [ ] Inputs com `font-size ≥ 16px` → iOS não dá zoom ao focar.
- [ ] Sem autofocus em touch → o teclado cobriria a tela.
- [ ] `<video autoplay muted playsinline>` para autoplay no iOS.
- [ ] `touch-action` desabilitado em componentes com pan/zoom próprio.
- [ ] `-webkit-tap-highlight-color: transparent`, **sempre** com um estado de toque alternativo.

## Performance
- [ ] `blur()` alto em `filter`/`backdrop-filter` é lento — usar com parcimônia.
- [ ] Retângulo sólido + blur causa banding → usar gradiente radial.
- [ ] `transform: translateZ(0)` só onde a animação engasga; `will-change` só durante a animação e como último recurso (preventivo piora).
- [ ] Muitos vídeos em autoplay no iOS travam: pausar/desmontar fora da tela.
- [ ] Valores em tempo real (scroll, wheel) via `ref` + DOM direto, não re-render do React a cada evento.
- [ ] Adaptar a hardware/rede do dispositivo (Save-Data, `navigator.connection`, `prefers-reduced-motion`).

## Acessibilidade
- [ ] Botão desabilitado **não** carrega tooltip: fica fora da ordem de tab, usuário de teclado nunca vê o motivo. Explicar de outro jeito.
- [ ] Anel de foco com `box-shadow`, não `outline` (Safari < 16.4 ignora `border-radius` no outline).
- [ ] Listas sequenciais navegáveis com ↑ ↓; itens deletáveis com ⌘ Backspace quando fizer sentido.
- [ ] Dropdown abre em `mousedown`, não `click` → abre no aperto, sem atraso.
- [ ] Favicon SVG com `<style>` seguindo `prefers-color-scheme`.
- [ ] Controle só-ícone → `aria-label` explícito.
- [ ] Tooltip de hover sem conteúdo interativo.
- [ ] Imagem é `<img>` (leitor de tela, "copiar imagem" no menu de contexto), não `background-image`.
- [ ] Ilustração feita em HTML/CSS → `aria-label` no wrapper, não expor a árvore DOM ao leitor.
- [ ] Texto com gradiente desliga o gradiente em `::selection`.
- [ ] Menus aninhados com "cone de previsão" (safe triangle) para o ponteiro não fechar o submenu ao atravessar.

## Design / comportamento
- [ ] Optimistic UI: atualiza local, reverte com feedback se o servidor falhar.
- [ ] Redirect de auth no servidor, antes do carregamento no cliente → URL não pisca.
- [ ] `::selection` estilizado com a paleta do produto.
- [ ] Feedback perto do gatilho: ✓ inline após copiar, não toast; erro de form destaca o campo.
- [ ] Estado vazio convida a criar o primeiro item, com templates opcionais.

## Como usar na skill
- `harden`: seções Interatividade, Toque, Acessibilidade.
- `polish`: Tipografia, Movimento, Design.
- Audit final: percorrer tudo; cada ❌ vira item P1 (acessibilidade/toque) ou P2 (resto).
