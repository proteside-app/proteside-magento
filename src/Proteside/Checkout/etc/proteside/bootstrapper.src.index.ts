/**
 * PROTESIDE BOOTSTRAPPER
 * Executa ANTES de qualquer outro script. Zero lógica de negócio. Zero rede. <1ms.
 * Se qualquer patch falhar, continua silenciosamente. R2 absoluto.
 *
 * Contrato interno (CONTRACTS §10): tudo que é interceptado vira uma entrada
 * `{type, args, ts}` em `window.__PROTE_BUFFER__`. O SDK substitui o `push`
 * desse array por um dispatcher — por isso NUNCA cacheamos a referência ao
 * `push` (sempre `__proteBuffer.push(...)`).
 */

import { blockReason } from './match'

const w = window as unknown as Record<string, unknown>

// PRIMEIRA LINHA: timestamp de boot
w.__PROTE_BOOT_TS__ = Date.now()
// Quantos scripts executaram ANTES do bootstrapper (verificação de ordem de instalação)
try { w.__PROTE_BOOT_SCRIPTS__ = document.scripts.length - 1 } catch {}

// Buffer de chamadas capturadas antes do SDK completo carregar
const __proteBuffer: Array<{ type: string; args: unknown[]; ts: number }> = []
w.__PROTE_BUFFER__ = __proteBuffer

function __p(type: string, args: unknown[]): void {
  try { __proteBuffer.push({ type, args, ts: Date.now() }) } catch {}
}

// ── Config inline (A5): `window.__PROTE_INLINE__ = { rules?, gtm? }` ─────────
// O snippet pode renderizar a config da loja inline para que o interceptor de
// pré-inserção funcione ANTES do SDK carregar. O SDK republica os arrays a
// partir da config remota no init (remoto > inline).
try {
  const inline = (w.__PROTE_INLINE__ || {}) as { rules?: unknown; gtm?: unknown }
  if (!Array.isArray(w.__PROTE_RULES__)) w.__PROTE_RULES__ = Array.isArray(inline.rules) ? inline.rules : []
  if (!Array.isArray(w.__PROTE_GTM__)) w.__PROTE_GTM__ = Array.isArray(inline.gtm) ? inline.gtm : []
} catch {}

// Salvar referências originais ANTES de qualquer patch (R6)
const __origFetch       = window.fetch.bind(window)
const __origXHROpen     = XMLHttpRequest.prototype.open
const __origXHRSend     = XMLHttpRequest.prototype.send
const __origAEL         = EventTarget.prototype.addEventListener
const __origClipWrite   = navigator.clipboard?.writeText?.bind(navigator.clipboard)
const __origSWRegister  = navigator.serviceWorker?.register?.bind(navigator.serviceWorker)
const __origBeacon      = navigator.sendBeacon?.bind(navigator)

const __orig: Record<string, unknown> = {
  fetch: __origFetch,
  xhrOpen: __origXHROpen,
  xhrSend: __origXHRSend,
  addEventListener: __origAEL,
  clipboardWrite: __origClipWrite,
  swRegister: __origSWRegister,
  sendBeacon: __origBeacon,
}
w.__PROTE_ORIG__ = __orig

// ── PATCH: interceptor de PRÉ-INSERÇÃO de <script src> (A5) ──────────────────
// Avalia `window.__PROTE_RULES__` / `window.__PROTE_GTM__` no momento da
// inserção. Casou → o script NÃO entra no DOM (nunca executa) e o buffer
// recebe `{type:'blocked_script', args:[src, rule | 'gtm', containerId?]}`.
// O SDK converte em SCRIPT_BLOCKED{stage:'pre_insertion'} / GTM_CONTAINER_BLOCKED.
try {
  const isScript = (n: unknown): n is HTMLScriptElement =>
    !!n && (n as Node).nodeType === 1 && String((n as Element).tagName).toUpperCase() === 'SCRIPT'
  const MARK = '__prote_blocked__'

  // true → bloqueado (e registrado no buffer). O elemento fica marcado: não
  // entra no DOM depois (independente da ordem src/append) e, se já estava
  // inserido sem src, é removido.
  const blockedSrc = (src: unknown, el?: HTMLScriptElement): boolean => {
    const why = blockReason(src, w.__PROTE_RULES__, w.__PROTE_GTM__, location.href)
    if (!why) { if (el) try { delete (el as unknown as Record<string, unknown>)[MARK] } catch {} ; return false }
    __p('blocked_script', 'gtm' in why ? [String(src), 'gtm', why.gtm] : [String(src), why.rule])
    if (el) {
      try {
        ;(el as unknown as Record<string, unknown>)[MARK] = true
        if (el.parentNode) el.parentNode.removeChild(el)
        // Loaders que aguardam onload/onerror falham rápido em vez de pendurar
        setTimeout(() => el.dispatchEvent(new Event('error')), 0)
      } catch {}
    }
    return true
  }

  const blockedNode = (n: unknown): boolean => {
    try {
      if (isScript(n)) {
        if ((n as unknown as Record<string, unknown>)[MARK] === true) return true
        return blockedSrc(n.src || n.getAttribute('src') || '', n)
      }
      if (n && (n as Node).nodeType === 11) {
        // DocumentFragment: remove os scripts barrados antes de inserir o resto
        ;(n as DocumentFragment).querySelectorAll('script[src]').forEach(s => {
          if (blockedSrc((s as HTMLScriptElement).src, s as HTMLScriptElement)) s.remove()
        })
      }
    } catch {}
    return false
  }

  const NP = Node.prototype
  const EP = Element.prototype
  const oAppend = NP.appendChild, oInsert = NP.insertBefore, oReplace = NP.replaceChild
  const oEAppend = EP.append, oEPrepend = EP.prepend, oSetAttr = EP.setAttribute
  NP.appendChild = function <T extends Node>(n: T): T { return blockedNode(n) ? n : (oAppend.call(this, n) as T) }
  NP.insertBefore = function <T extends Node>(n: T, ref: Node | null): T { return blockedNode(n) ? n : (oInsert.call(this, n, ref) as T) }
  NP.replaceChild = function <T extends Node>(n: Node, old: T): T { return blockedNode(n) ? old : (oReplace.call(this, n, old) as T) }
  EP.append = function (...nodes: (Node | string)[]): void { oEAppend.apply(this, nodes.filter(x => !blockedNode(x))) }
  EP.prepend = function (...nodes: (Node | string)[]): void { oEPrepend.apply(this, nodes.filter(x => !blockedNode(x))) }
  EP.setAttribute = function (name: string, value: string): void {
    if (isScript(this) && String(name).toLowerCase() === 'src' && blockedSrc(value, this)) return
    return oSetAttr.call(this, name, value)
  }
  const SP = HTMLScriptElement.prototype
  const d = Object.getOwnPropertyDescriptor(SP, 'src')
  if (d && d.set && d.configurable) {
    Object.defineProperty(SP, 'src', {
      ...d,
      set(this: HTMLScriptElement, v: string) { if (!blockedSrc(v, this)) d.set!.call(this, v) },
    })
  }
  __orig.appendChild = oAppend; __orig.insertBefore = oInsert; __orig.replaceChild = oReplace
  __orig.append = oEAppend; __orig.prepend = oEPrepend; __orig.setAttribute = oSetAttr; __orig.scriptSrc = d
} catch {}

// ── PATCH: fetch ──
try {
  window.fetch = function(input: RequestInfo | URL, init?: RequestInit) {
    __p('fetch', [input, init])
    return __origFetch(input, init)
  }
} catch {}

// ── PATCH: XHR ──
try {
  XMLHttpRequest.prototype.open = function(method: string, url: string | URL, ...rest: unknown[]) {
    const x = this as unknown as Record<string, unknown>
    x.__prote_url__ = url
    x.__prote_method__ = method
    return (__origXHROpen as (...args: unknown[]) => void).call(this, method, url, ...rest)
  }
  XMLHttpRequest.prototype.send = function(body?: Document | XMLHttpRequestBodyInit | null) {
    const x = this as unknown as Record<string, unknown>
    __p('xhr', [x.__prote_url__, x.__prote_method__, body])
    return (__origXHRSend as (body?: Document | XMLHttpRequestBodyInit | null) => void).call(this, body)
  }
} catch {}

// ── PATCH: sendBeacon ──
try {
  if (__origBeacon) {
    navigator.sendBeacon = function(url: string | URL, data?: BodyInit | null) {
      __p('beacon', [url, data])
      return __origBeacon(url, data)
    }
  }
} catch {}

// ── PATCH: addEventListener ──
// Para listeners de campos sensíveis captura a stack (truncada a 1500 chars)
// para o SDK atribuir o script de origem (KEYLOGGER_DETECTED / FIELD_ACCESS).
try {
  EventTarget.prototype.addEventListener = function(type: string, listener: EventListenerOrEventListenerObject, options?: boolean | AddEventListenerOptions) {
    const args: unknown[] = [type, this, listener]
    if (/^(keydown|keyup|input|change|paste)$/.test(String(type))) {
      try { args.push(String(new Error().stack || '').slice(0, 1500)) } catch {}
    }
    __p('listener', args)
    return (__origAEL as typeof EventTarget.prototype.addEventListener).call(this, type, listener, options)
  }
} catch {}

// ── PATCH: Clipboard ──
// args: [text, replace]. O despacho é síncrono depois do init do SDK, então o
// clipboard-guard pode chamar `replace(gt)` (mode 'block') ANTES da escrita e
// a página escreve o código legítimo no lugar do texto do atacante. `replace`
// devolve false se chamado tarde (entrada drenada depois da escrita) — aí o
// guard cai no fallback de reescrever depois.
try {
  if (__origClipWrite) {
    navigator.clipboard.writeText = function(text: string) {
      let out = text, open = true
      __p('clipboard', [text, (v: string) => open && typeof v === 'string' && ((out = v), true)])
      open = false
      return __origClipWrite(out)
    }
  }
} catch {}

// ── PATCH: Service Worker ──
try {
  if (__origSWRegister) {
    navigator.serviceWorker.register = function(scriptURL: string | URL, options?: RegistrationOptions) {
      __p('sw_register', [scriptURL, options && options.scope])
      return __origSWRegister(scriptURL, options)
    }
  }
} catch {}

// ── PATCH: copy event ──
try {
  document.addEventListener('copy', function() {
    __p('copy_event', [window.getSelection()?.toString() ?? ''])
  }, { capture: true, passive: true })
} catch {}

// ── PATCH: WebSocket ──
try {
  const OrigWS = window.WebSocket as unknown as Record<string, unknown> & { prototype: unknown }
  const WS = function(url: string | URL, protocols?: string | string[]) {
    __p('websocket', [url])
    return new (OrigWS as unknown as typeof WebSocket)(url, protocols)
  } as unknown as Record<string, unknown>
  WS.prototype = OrigWS.prototype
  // Preserva as constantes estáticas (CONNECTING/OPEN/CLOSING/CLOSED)
  'CONNECTING|OPEN|CLOSING|CLOSED'.split('|').forEach(k => { WS[k] = OrigWS[k] })
  w.WebSocket = WS
} catch {}
