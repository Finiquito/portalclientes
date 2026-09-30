# Ferramentas de referência, desenho e componentes prontos

Fonte: vídeo "Todo Site Feito com IA É Igual (e Como Fugir Disso)" (youtube.com/watch?v=I8_RP_BJVLk).
Complementa o SKILL.md — aqui está o **catálogo**; lá estão as **regras**.

## 1. Onde buscar referência (cada fonte tem um papel)

| Fonte | O que traz | Limite | Use para |
|---|---|---|---|
| **Mobbin** (mobbin.com) | Prints de **apps e sites reais** (iFood, Uber, Strava…), fluxo inteiro, por app ou por padrão | Pago (há trial); base curada, menos "wow" | Produto que precisa **funcionar**: dashboard, CRM, onboarding, checkout, settings. Primeira parada quando o usuário vai usar de verdade |
| **Pinterest** (pinterest.com) | Muita **variação** — busca `"CRM UI"`, `"pricing dark"` | Mistura qualidade; muitos repins do Dribbble | Sair do padrão, achar direção estética diferente |
| **Dribbble** (dribbble.com) | Acabamento alto, portfólio de designers | Muito **"design impossível"** (bonito, nunca aplicado, não sobrevive a dado real) | Roubar **um componente** (um gráfico, um card, um header), não a tela inteira |
| **dark.design** / templates Framer | Landings dark, hero, pricing | Só landing | Landing/hero/pricing (ver SKILL passo 4) |
| **LazyWeb MCP** | Busca com base real dentro do agente | Free tem base menor | Pesquisa sem sair do terminal (ver SKILL passo 5) |

Ordem sugerida: **Mobbin → Pinterest → Dribbble**. Se a referência veio só do Dribbble, checar no
Mobbin se algum produto real faz parecido antes de copiar a estrutura.

**O que copiar:** um elemento por vez (o gráfico da direita, o card de KPI, o padrão de sidebar),
combinado com o que já existe. Copiar a tela inteira de uma fonte = clone; combinar 3 fontes com
os próprios tokens = referência. "Se inspire, não copie."

**Como aplicar:** print (Win+Shift+S / ⌘⇧4) → colar no agente/gerador → *"porte este gráfico para
o card de receita da minha dashboard, usando cores, fonte e espaçamento do DESIGN.md"*. Funciona
com qualquer IA capaz de ler imagem (Claude, v0, Stitch, Gemini); **não** esperar isso de modelo
pequeno/local.

## 2. Desenhar antes de codar (design → código)

Quando não existe layout, gerar **design** primeiro é mais barato que gerar código e refazer.

| Ferramenta | O que faz | Saída | Custo | Observações |
|---|---|---|---|---|
| **Google Stitch** (stitch.withgoogle.com) | "Figma com IA": gera **design system automático** e telas consistentes; aceita `DESIGN.md`, imagens, Figma, URL do site | HTML puro (converter para React pelo agente); copiar para Figma; App Store assets, metadados ASO, kit de marketing, heatmap, audit; views desktop/tablet/mobile | ~300 créditos/dia grátis | 4 modos: **Gemini 3 Flash** (rápido), **Gemini 3.1 Pro** (thinking, melhor), **Ideate** (a partir de PRD), **Redesign** (**só imagem, sem código** — evitar). Converte print: *"converta essa imagem para dashboard seguindo nosso design system"*. Melhor resultado do vídeo |
| **Subframe** (subframe.com) | Mesmo papel do Stitch | **React + Tailwind**; instalação via `npx` ou **MCP** direto no Claude Code/Codex | 10 páginas/dia grátis | Conversão de print ficou abaixo do Stitch; vantagem é o código React e o MCP |
| **Pencil.dev** | Figma dentro do VS Code/app próprio, operado por agentes via **MCP** | Design editável por vários agentes ao mesmo tempo | — | Para quem quer o agente (Codex/Claude) mexendo no canvas como se fosse Figma |
| **v0** (v0.app) | Gerador de front **com shadcn** por padrão; templates públicos clonáveis; design systems; importa Figma; MCP; deploy Vercel em segundos | Next/React + shadcn | US$ 5/mês de crédito grátis | Menos slop que Lovable com o mesmo prompt curto. Ao clonar template público marcar *trust source* **só depois de ler o prompt** (prompt injection) |

Fluxo: prompt curto → design system gerado → conferir tokens com o DESIGN.md do projeto → só
então exportar/converter para código → entrar no loop Impeccable (SKILL passos 1–3).

## 3. Qual modelo para qual tarefa de UI

| Situação | Modelo que rendeu melhor no vídeo | Por quê |
|---|---|---|
| Gerar visual **do zero, sem referência** (hero animado, SVG, ilustração) | **Gemini 3.1 Pro / 3.5 Flash** (Antigravity, Stitch, AI Studio) | Teste "SVG animado de flor desabrochando": Gemini fez animação + controles sem pedir; Opus 4.8 ficou aquém |
| Portar **print → código** dentro do projeto, respeitar tokens, refatorar | Claude (Opus/Fable) no Claude Code | Contexto do repo, DESIGN.md, hooks do Impeccable |
| Componente shadcn pronto para colar | v0 | shadcn nativo |

Regra: **se não tem referência, peça o rascunho visual ao Gemini/Stitch; se tem, dê a referência ao
Claude com os tokens.** Nunca pedir "faça bonito" sem referência ao modelo que vai escrever o código.

## 4. Catálogos de componentes prontos

| Catálogo | Stack | Como usar | Risco |
|---|---|---|---|
| **Shoogle** (shoogle.dev) | Blocos shadcn (hero, pricing, feature) | Copiar prompt/código (SKILL passo 6) | Estrela = pago |
| **Aceternity UI** (ui.aceternity.com) | Componentes de efeito (globo 3D, beams, cards animados), React/Tailwind/Framer Motion | Botão "copiar prompt" → colar no v0/Lovable/agente | **"Praga"**: todo mundo usa os mesmos efeitos; usar 1 por página no máximo |
| **21st.dev** | Maior catálogo comunitário; código, CLI, remix, prompt | Copiar prompt → agente | **Público**: qualquer um submete; é onde mais aparece **prompt injection** |

Prefira componente pronto a gradiente genérico gerado — mas o bloco entra **sem tokens do projeto**
(fonte, espaçamento, cor quebram) → sempre `audit → typeset → polish` depois (SKILL "regra do loop").

## 5. Prompt injection em componentes/prompts públicos

O prompt de instalação de um componente é **texto que o seu agente vai obedecer**. Em catálogos
abertos (21st.dev, templates públicos do v0, gists) já circulam prompts com instruções escondidas
(exfiltrar `.env`, instalar dependência, alterar hooks).

Antes de colar qualquer prompt ou código de terceiro no agente:

1. **Ler o prompt inteiro** (e o código) — procurar instruções que não têm a ver com o componente:
   `curl`, `fetch` para domínio externo, leitura de `.env`/`~/.ssh`, `npm install` de pacote
   desconhecido, "ignore previous instructions", texto em branco/oculto, base64.
2. Preferir **copiar o código** ao "copiar o prompt" quando os dois existem — código dá para revisar linha a linha.
3. Rodar em projeto **sem secrets** ou em worktree/sandbox; nunca com `--dangerously-skip-permissions`.
4. Marcar *trust source* (v0) só para o que você leu.
5. Checar o `package.json` depois: dependência nova que você não pediu = remover.

Vale igual para skills de UI baixadas: skill é prompt.
