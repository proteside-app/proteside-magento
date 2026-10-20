# Protección de checkout Proteside para Magento 2 / Adobe Commerce

[English](README.md) · [Português (Brasil)](README.pt-BR.md)

**Descarga:** el paquete para instalar está adjunto a la [versión más reciente](https://github.com/proteside-app/proteside-magento/releases/latest). Este repositorio lo publica el equipo de Proteside solo para descarga y no acepta contribuciones. Dudas: [team@proteside.com](mailto:team@proteside.com).

`Proteside_Checkout` (`proteside/module-checkout`) te ayuda a cumplir los requisitos 6.4.3 y
11.6.1 de PCI DSS 4.0 cargando la protección client-side de Proteside en tus páginas de pago, con tu
clave pública del SDK. Por sí solo no hace que la tienda cumpla PCI DSS: el cumplimiento abarca todo el
entorno y lo evalúa tu adquirente o QSA. Reproduce la instalación canónica del dashboard de Proteside — bootstrapper inline y síncrono seguido
del `shield.js` asíncrono — como los **primeros scripts del `<head>`**, mantiene el entitlement de tu
suscripción sincronizado con Proteside y se integra con la Content-Security-Policy y el full-page cache
de Magento.

## Requisitos

| | |
| --- | --- |
| Magento Open Source / Adobe Commerce | 2.4.4 – 2.4.9 |
| PHP | 7.4, 8.1, 8.2, 8.3, 8.4, 8.5 (según tu versión de Magento) |
| Cuenta de Proteside | <https://app.proteside.com> — la clave pública del SDK (`pk_live_…`) está en **Configuración → Páginas y Dominios** |
| Cron de Magento | en ejecución (`bin/magento cron:run`) para la verificación periódica de estado |

Opcional: `Magento_Csp` (incluido en Magento desde 2.3.5) para la lista blanca de hash/host en la CSP;
el módulo funciona sin él. Varnish es compatible (ver *Caché*).

## Instalación

### Con Composer (recomendado)

Mientras el paquete no esté publicado en Packagist / Adobe Commerce Marketplace, añade la carpeta del
módulo como repositorio `path` (o un repositorio VCS que apunte a esta carpeta):

```bash
composer config repositories.proteside path /ruta/a/plugin-magento/src/Proteside/Checkout
composer require proteside/module-checkout:^1.0
bin/magento module:enable Proteside_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile          # modo production
bin/magento setup:static-content:deploy -f   # modo production (JS del admin)
bin/magento cache:flush
```

### Con `app/code`

```bash
mkdir -p app/code/Proteside
cp -R /ruta/a/plugin-magento/src/Proteside/Checkout app/code/Proteside/Checkout
bin/magento module:enable Proteside_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Desinstalación

```bash
bin/magento module:disable Proteside_Checkout
composer remove proteside/module-checkout   # o rm -rf app/code/Proteside/Checkout
bin/magento setup:upgrade && bin/magento cache:flush
```

El módulo guarda su estado en la tabla `flag` (`proteside_checkout_entitlement_*`) y en
`core_config_data` (`proteside/*`, `crontab/default/jobs/proteside_checkout_revalidate/*`). No crea
tablas propias.

## Configuración

**Tiendas → Configuración → Seguridad → Proteside** (recurso ACL `Proteside_Checkout::config`).

| Grupo | Campo | Predeterminado | Notas |
| --- | --- | --- | --- |
| Protección de checkout Proteside | Habilitado | No | Por sitio web / vista de tienda |
| | Clave del SDK | — | `pk_live_` o `pk_test_` + 32 caracteres hexadecimales. Se valida al guardar. Pública por diseño. |
| Estado de la conexión | Estado | — | Solo lectura: Activo / Período de gracia / Suspendido / Inactivo / Clave inválida / Error de conexión / Aún no verificado, plan, decisión, última verificación, versión del bootstrapper incluido, número de reglas inline, botón **Verificar estado** |
| | Verificar estado cada | 6 horas | 6 / 12 / 24 h; reescribe la expresión cron del job `proteside_checkout_revalidate` |
| Dónde cargar la protección | Checkout (todos los pasos) | Sí | `checkout_index_index`, `checkout_onepage_failure`, `multishipping_checkout_*` |
| | Confirmación del pedido (gracias) | Sí | `checkout_onepage_success`, `multishipping_checkout_success` — el SDK la usa para confirmar pagos con tarjeta; manténla activada |
| | Carrito | No | `checkout_cart_index` |
| | Toda la tienda | No | Todas las páginas HTML de la tienda |
| | Patrones de URL personalizados | — | Uno por línea. Con `/` → se compara con la ruta, `*` como comodín (`/paypal/*`). Sin `/` → se compara con el nombre completo de la acción (`paypal_express_review`, `braintree_*`). Úsalo para las páginas de retorno de tu pasarela. |
| Opciones | Canal de release | Seguir el dashboard | `auto` usa el `channel` devuelto por `/api/sdk/config` (predeterminado `stable`); `stable` / `latest` lo sobrescriben |
| | Calibración (segundos) | — | 10–120; solo se usa si la configuración remota no puede cargarse |
| | Incluir reglas de bloqueo en la página (config inline) | Sí | Copia tus reglas activas y los contenedores GTM permitidos al bootstrapper (`window.__PROTE_INLINE__`) para que el bloqueo empiece desde el primer byte |

Guardar la sección lanza una verificación inmediata de estado + config y un vaciado del full-page cache.

Las páginas se detectan por el **nombre de acción de Magento**, nunca por la URL, así que las URL de
checkout personalizadas y los códigos de tienda en la URL no afectan. El checkout predeterminado de
Magento es una sola página (`/checkout/#shipping`, `#payment`): el SDK permanece cargado entre pasos.
Si usas un checkout SPA de terceros que cambia la ruta de la URL sin recargar, llama a
`Proteside.pageChanged(path)` en cada cambio de ruta (ver la documentación).

## Qué se inyecta

Justo después de la etiqueta de apertura `<head …>` de cada página seleccionada:

```html
<!-- Proteside Start -->
<script data-cfasync="false" data-no-optimize="1" data-nowprocket="" data-pagespeed-no-defer="">"use strict";window.__PROTE_INLINE__={"rules":[…],"gtm":[…]};var __ProteBoots=…</script>
<script src="https://cdn.proteside.com/v1/shield.js" async="true" data-key="pk_live_…" data-api="https://app.proteside.com/api/sdk"></script>
<!-- Proteside End -->
```

- El bootstrapper (versión en `etc/proteside/VERSION`, fuentes `bootstrapper.src.*.ts`) viene dentro
  del módulo y se emite byte a byte. Nunca se descarga en tiempo de ejecución.
- `window.__PROTE_INLINE__` solo aparece cuando la config inline está habilitada **y** existe al menos
  una regla o contenedor GTM válido; va en el mismo `<script>` que el bootstrapper.
- `shield.js` solo lleva `data-key`, `data-api` y, opcionalmente, `data-calibration`. El módulo no
  renderiza sello y nunca envía `data-safe-url` (el sello es un snippet estático copiado del dashboard,
  Configuración → Tienda).
- Si la página ya contiene un bootstrapper de Proteside (p. ej. pegado en el tema), no se inyecta nada
  para no cargar el SDK dos veces. Elimina el snippet manual cuando el módulo esté activo.

## Orden de instalación (`INSTALLATION_ORDER_WARNING`)

El bootstrapper guarda `window.__PROTE_BOOT_SCRIPTS__ = document.scripts.length - 1`. Si cualquier
`<script>` (inline o externo) se ejecuta antes que él, el SDK reporta `INSTALLATION_ORDER_WARNING` al
dashboard y `Proteside.getStatus().installationOrderValid` queda en `false`; la protección continúa con
cobertura parcial. Este módulo inyecta inmediatamente después de `<head …>`, antes de `requirejs`, de
los scripts del tema y de cualquier tag manager, así que el orden es correcto desde el principio.

**Verifica:** abre tu checkout, abre la consola del navegador y ejecuta

```js
Proteside.getStatus()
```

`installationOrderValid` debe ser `true`. Si es `false`, algo más pone un `<script>` en el `<head>`
antes de nuestras etiquetas — normalmente otro módulo que también reescribe la respuesta, un worker de
CDN/edge, o un snippet de Google Tag Manager fijo en el `head.phtml` del tema (Proteside va **antes**
del GTM; nunca instales Proteside a través de GTM).

¿Por qué no layout XML? Los bloques en `head.additional` / `default.xml` se renderizan después de
`requirejs` y de los scripts del tema, lo que siempre dispararía el aviso. El observer de respuesta es
la única forma de ser el primero.

## Content-Security-Policy

`etc/csp_whitelist.xml` añade, cuando `Magento_Csp` está habilitado:

- `script-src https://cdn.proteside.com` + el hash estático del bootstrapper incluido sin config inline
  (`sha256-t2ypUgwBPt6N82k2gXq5D5ZHIdxOXxhj6ZISJatVbg8=`, bootstrapper 1.1.3);
- `connect-src https://app.proteside.com https://cdn.proteside.com` (shield.js también calcula el hash de los scripts externos, incluido el suyo, con un fetch CORS).

Además, ambas etiquetas se renderizan mediante `Magento\Framework\View\Helper\SecureHtmlRenderer`, al
que `Magento_Csp` se engancha para permitir el sha256 del contenido inline **real** (cambia cuando hay
reglas inline) y el host del `src`. El hash se registra en `controller_action_predispatch`, antes de
construir la cabecera CSP, por lo que el *restrict mode* de Magento 2.4.7 en `checkout_index_index`
funciona sin `'unsafe-inline'`.

Las páginas servidas desde el full-page cache integrado (nunca el checkout, el carrito ni la página de
éxito, que no son cacheables) dependen del hash estático: en esas páginas, si hay reglas inline,
mantén la CSP en report-only o desactiva "Incluir reglas de bloqueo" para "Toda la tienda" / páginas
personalizadas cacheables.

Si mantienes una CSP propia fuera de Magento (servidor web, WAF, CDN), añade:

```
script-src  … https://cdn.proteside.com 'sha256-<hash del contenido inline emitido>'   (o 'unsafe-inline')
connect-src … https://app.proteside.com https://cdn.proteside.com
```

`bin/magento proteside:status` imprime el hash actual del bootstrapper. Con reglas inline el contenido
— y por tanto el hash — cambia cada vez que cambian tus reglas; usa nonce/`'unsafe-inline'` en ese
caso o desactiva la config inline.

## Caché (full-page cache y Varnish)

- **FPC integrado:** el snippet se inyecta en `controller_front_send_response_before`, que se ejecuta
  *después* de que el FPC guarde (miss) o sirva (hit) la página. Por tanto el snippet **nunca forma
  parte de la entrada cacheada** y las páginas cacheadas siempre reciben la clave, el canal, las reglas
  y la decisión actuales. Nada que vaciar.
- **Varnish / proxies inversos / cachés de página en CDN:** la respuesta que sale de Magento ya contiene
  el snippet, así que la caché lo guarda. El módulo vacía el full-page cache (misma ruta que Gestión de
  caché → Actualizar *Page Cache*, que hace purge en Varnish) al guardar la configuración y cada vez
  que una verificación de estado cambia lo que se emite (decisión, canal, reglas inline, base de la
  CDN). Si cacheas páginas en una CDN que Magento no purga, púrgala manualmente tras cambiar la clave.
- Checkout, carrito y éxito del pedido no son cacheables en Magento, así que la protección en las
  páginas de pago nunca queda desactualizada.

## Entitlement y verificación de estado

El módulo llama a `GET https://app.proteside.com/api/sdk/status` **solo en el servidor**: al guardar
la configuración, desde cron (cada 6/12/24 h) y desde el botón **Verificar estado** /
`proteside:status --refresh`. Nunca durante el renderizado de una página.

| Última respuesta definitiva | API ahora | ¿Carga la protección? |
| --- | --- | --- |
| `active` / `grace` | OK | sí (en grace, aviso de facturación en el admin) |
| `suspended` / `inactive` / `invalid` (401) | OK | no |
| nunca validó | no disponible | no (fail-closed) |
| `active` / `grace`, último éxito ≤ 48 h | no disponible (429/5xx/timeout) | sí (tolerancia) |
| `active` / `grace`, último éxito > 48 h | no disponible | no |

`domainMismatch: true` es solo un aviso ("esta clave pertenece a una tienda registrada en otro
dominio"). El mismo ciclo obtiene `GET /api/sdk/config` para las reglas inline, los contenedores GTM y
el canal de release. El estado se guarda por clave del SDK (tabla `flag`) y lo comparten todas las
vistas de tienda que usan la misma clave.

Cron: job `proteside_checkout_revalidate`, grupo `default`. Comprueba la programación con
`bin/magento cron:run --group=default` y la tabla `cron_schedule`. Si ninguna verificación se ejecuta
durante más de 48 horas, la fila de estado lo avisa.

## CLI

```bash
bin/magento proteside:status            # estado en caché por clave, decisión, plan, canal, recuentos inline
bin/magento proteside:status --refresh  # consulta la API primero
bin/magento proteside:status --json
```

## Multitienda

La clave del SDK, las páginas y las opciones pueden definirse por sitio web o vista de tienda. Cada
tienda de Proteside (dominio) tiene su propia clave: defínela en el ámbito de vista de tienda de ese
dominio. El `site` enviado al endpoint de estado es la URL base de la primera vista de tienda que usa
la clave.

## Servicios externos / datos enviados

- `https://app.proteside.com/api/sdk/status` y `/api/sdk/config` (lado servidor): tu clave pública del
  SDK, la URL base de la tienda (`site`), `platform=magento`, la versión del módulo y el `User-Agent`
  `Proteside-Magento/1.0.1; <site>`. Ningún dato de clientes o pedidos.
- En el navegador del visitante, `shield.js` (desde `https://cdn.proteside.com`) envía su telemetría a
  `https://app.proteside.com/api/sdk/events`, como se describe en la documentación de Proteside.
- Términos: <https://proteside.com/termos> · Privacidad: <https://proteside.com/privacidade>.

## Limitaciones

- Nada de esto pudo verificarse en este repositorio sin una instancia de Magento; consulta
  `VERIFICATION.md` para saber qué se ejecutó y qué no.
- Las páginas cuyo HTML no tiene la etiqueta `<head …>`, las respuestas no HTML, las redirecciones y
  los fragmentos AJAX no se modifican.
- Otros módulos o workers de edge que reescriben la respuesta después de este observer (los módulos con
  nombre alfabéticamente posterior se ejecutan después en el mismo evento) podrían mover nuestras
  etiquetas. Verifica con `Proteside.getStatus()`.
- El bootstrapper se actualiza con el módulo. Cuando Proteside publique un bootstrapper nuevo,
  actualiza el módulo.
- PWA Studio y otras tiendas headless no renderizan HTML a través del pipeline de respuesta de
  Magento; usa el snippet de Proteside en la aplicación frontend.

## Preguntas frecuentes

**El estado dice "Aún no verificado".** Guarda la configuración o haz clic en **Verificar estado**.
Asegúrate de que el servidor llega a `app.proteside.com` por HTTPS (puerto 443).

**¿"Error de conexión" pero la protección sigue activa?** Los errores transitorios de la API mantienen
la última decisión durante 48 horas. Si persiste, revisa la conectividad de salida/firewall y
`var/log/system.log`.

**Cambié la clave y la tienda sigue mostrando la anterior.** Con Varnish, vacía la caché de página (el
módulo lo hace al guardar; si no, Gestión de caché → Actualizar Page Cache). Vacía también tu CDN.

**¿Funciona con el restrict mode de la CSP de Magento 2.4.7 en el checkout?** Sí — el hash inline y
`cdn.proteside.com` se permiten dinámicamente antes de enviar la cabecera.

**¿Puedo mantener el snippet pegado en mi tema?** No. Elimínalo; el módulo omite la inyección cuando
detecta un bootstrapper ya en la página, pero perderías la sincronización de entitlement/reglas.

## Soporte

team@proteside.com · <https://docs.proteside.com> · dashboard <https://app.proteside.com>

## Licencia

MIT — ver [LICENSE](LICENSE).
