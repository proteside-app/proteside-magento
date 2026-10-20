/**
 * Decisão de PRÉ-INSERÇÃO (A5 / CONTRACTS §10-d) — pura, sem DOM, sem estado.
 * Compartilhada pelo bootstrapper (interceptor) e pelo rules-guard do SDK
 * (fallback pós-inserção), para que os dois lados julguem igual.
 *
 *  - `cdn.proteside.com` / `app.proteside.com` nunca são bloqueados.
 *  - Rules `{type:'block'|'allow', target:'domain'|'src'|'hash', value}`:
 *    `domain` = sufixo de host; `src` = exato (href ou origin+pathname);
 *    `hash` ignorado. Um `allow` que case neutraliza os `block`.
 *  - GTM: `googletagmanager.com/gtm.js?id=X` ou `/gtag/js?id=X` com lista de
 *    containers aprovados NÃO vazia e X fora dela → bloqueado (independente
 *    das rules — aprovação de containers é um controle separado).
 */
export interface PreRule { type: string; target: string; value: string }

export type BlockReason = { rule: PreRule } | { gtm: string }

export function blockReason(src: unknown, rules: unknown, gtm: unknown, base: string): BlockReason | null {
  try {
    const s = String(src == null ? '' : src)
    if (!s) return null
    const u = new URL(s, base)
    const host = u.hostname.toLowerCase()
    if (/(^|\.)(cdn|app)\.proteside\.com$/.test(host)) return null
    const plain = u.origin + u.pathname

    if (Array.isArray(rules)) {
      let block: PreRule | null = null
      for (const r of rules as PreRule[]) {
        if (!r || typeof r.value !== 'string' || !r.value) continue
        const hit = r.target === 'domain'
          ? host === r.value || host.endsWith('.' + r.value)
          : r.target === 'src' && (u.href === r.value || plain === r.value)
        if (!hit) continue
        if (r.type === 'allow') { block = null; break }
        if (r.type === 'block' && !block) block = r
      }
      if (block) return { rule: block }
    }

    if (Array.isArray(gtm) && gtm.length && /(^|\.)googletagmanager\.com$/.test(host) && /^\/(gtm\.js|gtag\/js)$/.test(u.pathname)) {
      const id = (u.searchParams.get('id') || '').toUpperCase()
      if (id && !(gtm as unknown[]).some(g => String(g).toUpperCase() === id)) return { gtm: id }
    }
  } catch {}
  return null
}
