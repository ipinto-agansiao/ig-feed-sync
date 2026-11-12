# IG Feed Sync

Grelha de publicações do Instagram alimentada pelo n8n e pelo Apify. O plugin não faz pedidos para fora do site: é o n8n que envia as publicações para a REST API.

## Atualizações (GitHub)

Os sites recebem as versões novas a partir das releases do repositório público definido em `IGFS_UPDATE_REPO`, no `ig-feed-sync.php`. Não é preciso nenhum plugin extra: a biblioteca Plugin Update Checker vem dentro de `lib/`.

Para publicar uma versão nova:

1. Subir o número da versão no `ig-feed-sync.php`, em dois sítios: no cabeçalho `Version:` e na constante `IGFS_VERSION` (ex.: `1.3.0`).
2. Fazer commit e push para o GitHub.
3. No GitHub, ir a **Releases > Draft a new release**:
   - criar a tag `v1.3.0`;
   - carregar em **Publish release**.

   A Action `.github/workflows/release.yml` gera `ig-feed-sync.zip` e junta-o à release.
4. Nos sites, a atualização aparece em **Plugins**, com o botão **Atualizar agora**. Com as atualizações automáticas ativas nesse plugin, os sites atualizam-se sozinhos.

## n8n: um só workflow para todos os clientes

Instalação, feita uma única vez:

1. No n8n, importar `n8n/ig-feed-sync.json`.
2. Criar a credencial **Header Auth** "Apify":
   - Name: `Authorization`;
   - Value: `Bearer <token do Apify>`.

   Atribuir esta credencial aos 4 nós `Apify: …`.
3. No nó **Sincronizar cada cliente**, escolher este mesmo workflow na lista "Workflow".
4. Ativar o workflow. Por defeito corre de 12 em 12 horas.

Como funciona:

- O nó **Clientes** tem a lista dos sites.
- O workflow chama-se a si próprio uma vez por cliente, um cliente de cada vez.
- Se um cliente falhar, passa ao seguinte. O erro fica nas execuções do n8n.

## Avisos de erro

- **No WordPress:** se o Instagram não devolver uma conta (porque não existe, é privada ou tem restrição de idade), aparece um aviso vermelho no painel. As definições mostram, para cada conta, a foto, o nome e os seguidores que o Instagram devolveu. Assim dá para confirmar que é a conta certa: um nome mal escrito pode corresponder a outra pessoa.
- **No n8n:** a execução dessa conta fica vermelha, com o erro do Instagram.
- **Por email (Brevo):** importar `n8n/ig-feed-sync-avisos.json` como um workflow à parte. No nó "Enviar email (Brevo)":
  1. escolher a credencial **Header Auth** do Brevo (Name `api-key`, Value com a chave da API);
  2. no JSON, trocar `REMETENTE@dominio.pt` por um remetente validado no Brevo e `DESTINO@dominio.pt` pelo email que recebe os avisos;
  3. guardar.

  Depois, no workflow "IG Feed Sync", ir a **⋯ > Settings > Error workflow** e escolher "IG Feed Sync - Avisos". Não é preciso ativar o workflow de avisos.

## Juntar um cliente

1. Copiar a pasta `ig-feed-sync` para `wp-content/plugins/` e ativar o plugin.
2. Em **Definições > IG Feed Sync**, preencher:
   - a conta do Instagram;
   - a data de "Importar desde";
   - a opção "Ativa".
3. Copiar a chave da mesma página.
4. No nó **Clientes** do n8n, acrescentar uma linha:
   `{ site: 'https://site.pt', key: 'CHAVE', active: true },`
5. Executar o workflow uma vez à mão (**Execute workflow**) para a primeira importação.
6. Pôr o shortcode no tema ou numa página.

Cada site guarda a data da última publicação recebida. Os pedidos seguintes só trazem o que é novo. Para ir buscar publicações mais antigas, muda a data de "Importar desde" e guarda. A execução seguinte volta a pedir a partir dessa data e as publicações que já existem não são duplicadas.

## Lightbox e imagens

- **Lightbox:** ao clicar numa publicação, abre uma janela no próprio site, com a imagem ou o carrossel completo, a legenda, a data e o botão "Ver no Instagram".
  - Navega-se com as setas do ecrã, as setas do teclado ou deslizando no telemóvel. O Esc fecha a janela.
  - Ctrl ou Cmd + clique abre a publicação no Instagram.
  - Liga-se e desliga-se em Definições > IG Feed Sync > Imagens > Lightbox. No shortcode, `lightbox="1"` ou `lightbox="0"` muda só essa grelha.
- **Vídeos:** abrem no embed do Instagram (iframe), com o botão "Ver no Instagram" para o caso de não abrirem (por exemplo, em contas com restrição de idade).
  - O embed coloca cookies da Meta, por isso só carrega com consentimento de marketing no Complianz. Sem consentimento, mostra a capa e o botão.
  - Num site sem Complianz, o tema pode definir `window.igfsConsent = function () { return true/false; }`.
- **Imagens:** são convertidas para WebP (ou JPEG, se o servidor não suportar WebP), com um máximo de 1080 px e qualidade 80. Cada imagem gera só os tamanhos `thumbnail` e `medium_large`, cerca de 60 KB no total. O tamanho máximo (480 a 2160 px) e a qualidade (50 a 95) mudam-se em Definições > IG Feed Sync > Imagens e valem para as imagens importadas a partir daí.
- **Carrosséis:** guardam até 10 imagens.

## Várias contas no mesmo site

1. Em **Definições > IG Feed Sync > Outras contas**, escrever uma conta por linha, com o nome e a data de início:
   `outraconta 2026-06-01`
2. No nó **Clientes** do n8n, acrescentar uma linha para essa conta, com o mesmo site e a mesma chave:
   `{ site: 'https://site.pt', key: 'CHAVE', account: 'outraconta', active: true },`
3. Mostrar a grelha dessa conta com `[igfs_feed account="outraconta" ...]`. Sem `account`, a grelha mostra a conta principal.

Regras:

- Cada conta tem a sua data de última publicação e o seu perfil.
- A "Imagem do perfil" das definições e as "Publicações extra" só se aplicam à conta principal.
- Nas publicações adicionadas à mão, escolhe-se a conta na caixa "Instagram".

## Contas com restrição de idade (actor intropix)

O actor normal do Apify não lê contas com restrição de idade. Para essas contas, acrescentar `actor: 'intropix'` à linha do cliente, no nó **Clientes**:

`{ site: 'https://site.pt', key: 'CHAVE', account: 'conta', actor: 'intropix', active: true },`

- O workflow usa então o actor `intropix/instagram-posts-reels-scraper` e converte o resultado para o mesmo formato. O plugin não muda.
- O perfil (nome, seguidores e foto) vem das próprias publicações e só é atualizado quando há publicações novas. Este actor não devolve o número de publicações, por isso o cabeçalho não o mostra.
- O preço é por publicação, um pouco acima do actor normal. Usar só nas contas que precisam.

## Colaborações e publicações com restrição de idade

- **Colaborações sem restrição:** colar o link em **Publicações extra**, nas definições do plugin. O n8n importa-a na execução seguinte.
- **Publicações com restrição de idade:** o Apify não as consegue ler. Adicioná-las à mão em **Instagram > Adicionar**:
  1. Pôr um título.
  2. Escolher a imagem de destaque.
  3. Na caixa "Instagram", colar o link e escolher o tipo.
  4. Acertar a data e publicar.

  A publicação fica com o shortcode do Instagram. Por isso o n8n não a duplica e deixa de a pedir em "Publicações extra".

## Shortcode

```
[igfs_feed layout="cards" num="7" featured="tall"]
[igfs_feed layout="highlight" num="9" cols="4" title="#conta" button="Ver mais no Instagram"]
```

- `cards`: cabeçalho com foto, publicações e seguidores, e um cabeçalho pequeno em cada cartão.
- `highlight`: imagens quadradas, com a primeira em 2x2.
- `featured="tall"`: a primeira publicação ocupa uma coluna mais larga e tem a altura de 2 linhas. A imagem fica quadrada e alinhada ao topo. As outras colunas têm 2 publicações cada. Com 4 colunas, usar `num="7"`. Esta opção precisa de uma proporção numérica (não funciona com `ratio="auto"`).
- `featured="1"`: a primeira publicação ocupa 2x2. Com 4 colunas, usar `num="9"`. `featured="0"` desliga o destaque.
- `ratio`: proporção das imagens. Por defeito é `1` (quadrado); `4/5` é a proporção vertical do Instagram. Com `auto`, cada imagem mantém a proporção original.
- `width`: largura máxima da grelha, em px. Sem este atributo, a grelha ocupa a largura toda.
- Imagem do perfil: pode ser escolhida em Definições > IG Feed Sync. Se não for escolhida nenhuma, é usada a foto do Instagram, atualizada de 7 em 7 dias.
- **Botão "Ver no Instagram":** configura-se em Definições > IG Feed Sync. Tem texto, cor de fundo, cor do texto, estilo (cheio ou só contorno), cantos arredondados e maiúsculas.
  - O texto definido aparece em todas as grelhas.
  - No shortcode, `button="..."` muda o texto e `button=""` esconde o botão.
- Para outros ajustes no CSS do tema, há estas variáveis em `.igfs`: `--igfs-card`, `--igfs-muted` e `--igfs-gap`.

## Desligar ou cortar o acesso

- No n8n: pôr `active: false` na linha do cliente, no nó **Clientes**.
- No WordPress: desligar a opção "Ativa". Os pedidos de escrita passam a devolver 403.
- Se a chave for exposta, usar "Gerar chave nova" e atualizar a credencial no n8n.

## REST API (`/wp-json/igfs/v1`, header `X-IGFS-Key`)

| Rota | Método | O que faz |
|---|---|---|
| `/state` | GET | Devolve `enabled`, `username` e `since`. Regista a hora do pedido. |
| `/profile` | POST | Recebe `username`, `full_name`, `followers`, `posts_count` e `avatar_url`. |
| `/posts` | POST | Recebe `shortcode`, `url`, `caption`, `type` (`Image`, `Video` ou `Sidecar`), `timestamp` e `image_url`. |

Regras:

- `since` é o dia da última publicação guardada, ou a data inicial se ainda não houver nenhuma.
- As publicações repetidas são atualizadas pelo `shortcode`. As que estão no lixo não voltam.
- As imagens só são aceites da CDN do Instagram (`cdninstagram.com` e `fbcdn.net`). O filtro `igfs_image_hosts` permite mudar esta lista.
- Dos carrosséis guardam-se até 10 imagens. Dos vídeos guarda-se a miniatura e o vídeo abre no embed do Instagram.
- O workflow envia as publicações das mais antigas para as mais recentes. Se falhar a meio, a execução seguinte retoma sem deixar buracos.

## Sites com Basic Auth (staging)

O n8n não passa a Basic Auth do servidor. Para que chegue ao plugin, abrir só o caminho do plugin no `.htaccess`, por exemplo:
`SetEnvIf Request_URI "^/wp-json/igfs/" <variável de exceção>`.
O plugin continua protegido pela chave.
