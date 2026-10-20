# Proteção de checkout Proteside para Magento 2 / Adobe Commerce

[English](README.md) · [Español](README.es.md)

**Download:** o pacote para instalar está anexado à [versão mais recente](https://github.com/proteside-app/proteside-magento/releases/latest). Este repositório é publicado pela equipe Proteside apenas para download e não aceita contribuições. Dúvidas: [team@proteside.com](mailto:team@proteside.com).

`Proteside_Checkout` (`proteside/module-checkout`) ajuda você a atender aos requisitos 6.4.3 e
11.6.1 do PCI DSS 4.0 carregando a proteção client-side do Proteside nas suas páginas de pagamento,
com a sua chave pública do SDK. Sozinho, ele não deixa a loja em conformidade com o PCI DSS: a
conformidade abrange todo o ambiente e é avaliada pelo seu adquirente ou QSA. Ele reproduz a instalação canônica do dashboard Proteside — bootstrapper inline e síncrono seguido
do `shield.js` assíncrono — como os **primeiros scripts do `<head>`**, mantém o entitlement da sua
assinatura sincronizado com o Proteside e se integra à Content-Security-Policy e ao full-page cache do
Magento.

## Requisitos

| | |
| --- | --- |
| Magento Open Source / Adobe Commerce | 2.4.4 – 2.4.9 |
| PHP | 7.4, 8.1, 8.2, 8.3, 8.4, 8.5 (conforme a sua versão do Magento) |
| Conta Proteside | <https://app.proteside.com> — a chave pública do SDK (`pk_live_…`) está em **Configurações → Páginas e Domínios** |
| Cron do Magento | em execução (`bin/magento cron:run`) para a verificação periódica de status |

Opcional: `Magento_Csp` (incluído no Magento desde a 2.3.5) para whitelist de hash/host na CSP; o
módulo funciona sem ele. Varnish é suportado (veja *Cache*).

## Instalação

### Via Composer (recomendado)

Enquanto o pacote não estiver publicado no Packagist / Adobe Commerce Marketplace, adicione a pasta
do módulo como repositório `path` (ou um repositório VCS apontando para esta pasta):

```bash
composer config repositories.proteside path /caminho/para/plugin-magento/src/Proteside/Checkout
composer require proteside/module-checkout:^1.0
bin/magento module:enable Proteside_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile          # modo production
bin/magento setup:static-content:deploy -f   # modo production (JS do admin)
bin/magento cache:flush
```

### Via `app/code`

```bash
mkdir -p app/code/Proteside
cp -R /caminho/para/plugin-magento/src/Proteside/Checkout app/code/Proteside/Checkout
bin/magento module:enable Proteside_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Desinstalação

```bash
bin/magento module:disable Proteside_Checkout
composer remove proteside/module-checkout   # ou rm -rf app/code/Proteside/Checkout
bin/magento setup:upgrade && bin/magento cache:flush
```

O módulo guarda estado na tabela `flag` (`proteside_checkout_entitlement_*`) e em `core_config_data`
(`proteside/*`, `crontab/default/jobs/proteside_checkout_revalidate/*`). Não cria tabelas próprias.

## Configuração

**Lojas → Configuração → Segurança → Proteside** (recurso ACL `Proteside_Checkout::config`).

| Grupo | Campo | Padrão | Observações |
| --- | --- | --- | --- |
| Proteção de checkout Proteside | Habilitado | Não | Por website / visão de loja |
| | Chave do SDK | — | `pk_live_` ou `pk_test_` + 32 caracteres hexadecimais. Validada ao salvar. Pública por design. |
| Status da conexão | Status | — | Somente leitura: Ativo / Período de carência / Suspenso / Inativo / Chave inválida / Erro de conexão / Ainda não verificado, plano, decisão, última verificação, versão do bootstrapper embutido, contagem de regras inline, botão **Verificar status** |
| | Verificar status a cada | 6 horas | 6 / 12 / 24 h; reescreve a expressão cron do job `proteside_checkout_revalidate` |
| Onde carregar a proteção | Checkout (todas as etapas) | Sim | `checkout_index_index`, `checkout_onepage_failure`, `multishipping_checkout_*` |
| | Confirmação do pedido (obrigado) | Sim | `checkout_onepage_success`, `multishipping_checkout_success` — o SDK a usa para confirmar pagamentos com cartão; mantenha ligada |
| | Carrinho | Não | `checkout_cart_index` |
| | Loja inteira | Não | Todas as páginas HTML da loja |
| | Padrões de URL personalizados | — | Um por linha. Com `/` → casado contra o path, `*` curinga (`/paypal/*`). Sem `/` → casado contra o nome completo da ação (`paypal_express_review`, `braintree_*`). Use para páginas de retorno do seu gateway. |
| Opções | Canal de release | Seguir o dashboard | `auto` usa o `channel` devolvido por `/api/sdk/config` (padrão `stable`); `stable` / `latest` sobrescrevem |
| | Calibração (segundos) | — | 10–120; só é usada se a configuração remota não puder ser carregada |
| | Embutir regras de bloqueio na página (config inline) | Sim | Copia as suas regras ativas e os containers GTM permitidos para o bootstrapper (`window.__PROTE_INLINE__`), para que o bloqueio comece desde o primeiro byte |

Salvar a seção dispara uma verificação imediata de status + config e um flush do full-page cache.

As páginas são detectadas pelo **nome de ação do Magento**, nunca pela URL — URLs de checkout
personalizadas e códigos de loja na URL não atrapalham. O checkout padrão do Magento é uma página
única (`/checkout/#shipping`, `#payment`): o SDK permanece carregado entre as etapas. Se você usa um
checkout SPA de terceiros que muda o path da URL sem recarregar a página, chame
`Proteside.pageChanged(path)` a cada mudança de rota (veja a documentação).

## O que é injetado

Logo após a tag de abertura `<head …>` de cada página selecionada:

```html
<!-- Proteside Start -->
<script data-cfasync="false" data-no-optimize="1" data-nowprocket="" data-pagespeed-no-defer="">"use strict";window.__PROTE_INLINE__={"rules":[…],"gtm":[…]};var __ProteBoots=…</script>
<script src="https://cdn.proteside.com/v1/shield.js" async="true" data-key="pk_live_…" data-api="https://app.proteside.com/api/sdk"></script>
<!-- Proteside End -->
```

- O bootstrapper (versão em `etc/proteside/VERSION`, fontes `bootstrapper.src.*.ts`) vem dentro do
  módulo e é emitido byte a byte. Nunca é baixado em runtime.
- `window.__PROTE_INLINE__` só aparece quando a config inline está habilitada **e** existe pelo menos
  uma regra ou container GTM válido; fica no mesmo `<script>` do bootstrapper.
- O `shield.js` leva só `data-key`, `data-api` e, opcionalmente, `data-calibration`. O módulo não
  renderiza selo e nunca envia `data-safe-url` (o selo é um snippet estático copiado do dashboard,
  Configurações → Loja).
- Se a página já contém um bootstrapper Proteside (ex.: colado no tema), nada é injetado, para não
  carregar o SDK duas vezes. Remova o snippet manual quando o módulo estiver ativo.

## Ordem de instalação (`INSTALLATION_ORDER_WARNING`)

O bootstrapper grava `window.__PROTE_BOOT_SCRIPTS__ = document.scripts.length - 1`. Se qualquer
`<script>` (inline ou externo) rodar antes dele, o SDK reporta `INSTALLATION_ORDER_WARNING` ao
dashboard e `Proteside.getStatus().installationOrderValid` fica `false`; a proteção continua, com
cobertura parcial. Este módulo injeta imediatamente após `<head …>`, antes do `requirejs`, dos scripts
do tema e de qualquer tag manager, então a ordem já sai correta.

**Verifique:** abra o seu checkout, abra o console do navegador e rode

```js
Proteside.getStatus()
```

`installationOrderValid` precisa ser `true`. Se for `false`, algo mais coloca um `<script>` no
`<head>` antes das nossas tags — tipicamente outro módulo que também reescreve a resposta, um worker
de CDN/edge, ou um snippet do Google Tag Manager fixo no `head.phtml` do tema (o Proteside vem
**antes** do GTM; nunca instale o Proteside via GTM).

Por que não layout XML? Blocos em `head.additional` / `default.xml` são renderizados depois do
`requirejs` e dos scripts do tema, o que sempre dispararia o aviso. O observer de resposta é a única
forma de ser o primeiro.

## Content-Security-Policy

`etc/csp_whitelist.xml` adiciona, quando `Magento_Csp` está habilitado:

- `script-src https://cdn.proteside.com` + o hash estático do bootstrapper embutido sem config inline
  (`sha256-t2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=`, bootstrapper 1.1.3);
- `connect-src https://app.proteside.com https://cdn.proteside.com` (o shield.js também calcula o hash dos scripts externos, inclusive o dele, com um fetch CORS).

Além disso as duas tags são renderizadas via `Magento\Framework\View\Helper\SecureHtmlRenderer`, no
qual o `Magento_Csp` se engancha para liberar o sha256 do conteúdo inline **real** (ele muda quando há
regras inline) e o host do `src`. O hash é registrado em `controller_action_predispatch`, antes de o
header CSP ser montado, então o *restrict mode* do Magento 2.4.7 em `checkout_index_index` funciona
sem `'unsafe-inline'`.

Páginas servidas do full-page cache embutido (nunca o checkout, o carrinho ou a página de sucesso,
que não são cacheáveis) dependem do hash estático: nessas páginas, se houver regras inline, mantenha
a CSP em report-only ou desabilite "Embutir regras de bloqueio" para "Loja inteira" / páginas
personalizadas cacheáveis.

Se você mantém uma CSP própria fora do Magento (servidor web, WAF, CDN), adicione:

```
script-src  … https://cdn.proteside.com 'sha256-<hash do conteúdo inline emitido>'   (ou 'unsafe-inline')
connect-src … https://app.proteside.com https://cdn.proteside.com
```

`bin/magento proteside:status` imprime o hash atual do bootstrapper. Com regras inline o conteúdo — e
portanto o hash — muda sempre que as suas regras mudam; use nonce/`'unsafe-inline'` nesse caso ou
desabilite a config inline.

## Cache (full-page cache e Varnish)

- **FPC embutido:** o snippet é injetado em `controller_front_send_response_before`, que roda *depois*
  de o FPC gravar (miss) ou servir (hit) a página. Portanto o snippet **nunca faz parte da entrada
  em cache** e páginas cacheadas sempre recebem a chave, o canal, as regras e a decisão atuais. Nada a
  limpar.
- **Varnish / proxies reversos / caches de página em CDN:** a resposta que sai do Magento já contém o
  snippet, então o cache o guarda. O módulo limpa o full-page cache (mesmo caminho de Gerenciamento de
  Cache → Atualizar *Page Cache*, que faz purge no Varnish) ao salvar a configuração e sempre que uma
  verificação de status muda o que é emitido (decisão, canal, regras inline, base da CDN). Se você
  cacheia páginas numa CDN que o Magento não faz purge, limpe manualmente após trocar a chave.
- Checkout, carrinho e sucesso do pedido não são cacheáveis no Magento, então a proteção nas páginas
  de pagamento nunca fica defasada.

## Entitlement e verificação de status

O módulo chama `GET https://app.proteside.com/api/sdk/status` **só no servidor**: ao salvar a
configuração, no cron (a cada 6/12/24 h) e no botão **Verificar status** / `proteside:status
--refresh`. Nunca durante a renderização de uma página.

| Última resposta definitiva | API agora | Proteção carrega? |
| --- | --- | --- |
| `active` / `grace` | OK | sim (em grace, aviso de cobrança no admin) |
| `suspended` / `inactive` / `invalid` (401) | OK | não |
| nunca validou | indisponível | não (fail-closed) |
| `active` / `grace`, último sucesso ≤ 48 h | indisponível (429/5xx/timeout) | sim (tolerância) |
| `active` / `grace`, último sucesso > 48 h | indisponível | não |

`domainMismatch: true` é só um aviso ("esta chave pertence a uma loja cadastrada em outro domínio").
O mesmo ciclo busca `GET /api/sdk/config` para regras inline, containers GTM e canal de release. O
estado é guardado por chave do SDK (tabela `flag`) e compartilhado por todas as visões de loja que
usam a mesma chave.

Cron: job `proteside_checkout_revalidate`, grupo `default`. Confira o agendamento com
`bin/magento cron:run --group=default` e a tabela `cron_schedule`. Se nenhuma verificação rodar por
mais de 48 horas, a linha de status avisa.

## CLI

```bash
bin/magento proteside:status            # estado em cache por chave, decisão, plano, canal, contagens inline
bin/magento proteside:status --refresh  # consulta a API antes
bin/magento proteside:status --json
```

## Multi-loja

Chave do SDK, páginas e opções podem ser definidas por website ou visão de loja. Cada loja Proteside
(domínio) tem a sua chave: defina-a no escopo de visão de loja daquele domínio. O `site` enviado ao
endpoint de status é a URL base da primeira visão de loja que usa a chave.

## Serviços externos / dados enviados

- `https://app.proteside.com/api/sdk/status` e `/api/sdk/config` (lado servidor): a sua chave pública
  do SDK, a URL base da loja (`site`), `platform=magento`, a versão do módulo e o `User-Agent`
  `Proteside-Magento/1.0.1; <site>`. Nenhum dado de cliente ou pedido.
- No navegador do visitante, o `shield.js` (de `https://cdn.proteside.com`) envia a sua telemetria a
  `https://app.proteside.com/api/sdk/events`, como descrito na documentação do Proteside.
- Termos: <https://proteside.com/termos> · Privacidade: <https://proteside.com/privacidade>.

## Limitações

- Nada aqui pôde ser verificado neste repositório sem uma instância Magento; veja `VERIFICATION.md` para o
  que foi e o que não foi executado.
- Páginas cujo HTML não tem a tag `<head …>`, respostas não-HTML, redirects e fragmentos AJAX não são
  alterados.
- Outros módulos ou workers de edge que reescrevem a resposta depois deste observer (módulos com nome
  alfabeticamente posterior rodam depois no mesmo evento) podem mover as nossas tags. Verifique com
  `Proteside.getStatus()`.
- O bootstrapper atualiza junto com o módulo. Quando o Proteside publicar um bootstrapper novo,
  atualize o módulo.
- PWA Studio e outras vitrines headless não renderizam HTML pelo pipeline de resposta do
  Magento; use o snippet Proteside na aplicação frontend.

## Perguntas frequentes

**O status diz "Ainda não verificado".** Salve a configuração ou clique em **Verificar status**.
Confira se o servidor alcança `app.proteside.com` por HTTPS (porta 443).

**"Erro de conexão" mas a proteção segue ativa?** Erros transitórios da API mantêm a última decisão
por 48 horas. Se persistir, confira conectividade de saída/firewall e `var/log/system.log`.

**Troquei a chave e a loja ainda mostra a antiga.** Com Varnish, limpe o page cache (o módulo faz isso
ao salvar; senão Gerenciamento de Cache → Atualizar Page Cache). Limpe também a sua CDN.

**Funciona com o restrict mode da CSP do Magento 2.4.7 no checkout?** Sim — o hash inline e
`cdn.proteside.com` são liberados dinamicamente antes de o header ser enviado.

**Posso manter o snippet colado no tema?** Não. Remova-o; o módulo pula a injeção quando detecta um
bootstrapper já na página, mas você perderia a sincronização de entitlement/regras.

## Suporte

team@proteside.com · <https://docs.proteside.com> · dashboard <https://app.proteside.com>

## Licença

MIT — veja [LICENSE](LICENSE).
