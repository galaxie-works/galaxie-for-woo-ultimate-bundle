import * as React from 'react'

import { getGalaxieConfig } from '@/lib/wp'

/** The phrases that become links, and the document each stands for. */
const PHRASES: Array<[RegExp, 'terms' | 'privacy' | 'returns']> = [
  [/termos de uso/i, 'terms'],
  [/pol[ií]tica de privacidade/i, 'privacy'],
  [/trocas e devolu[çc][õo]es/i, 'returns'],
]

const ANY = new RegExp(PHRASES.map(([re]) => re.source).join('|'), 'gi')

/**
 * A consent text with "termos de uso", "política de privacidade" and "trocas
 * e devoluções" linked to the pages chosen on wp-admin → Galaxie → Páginas
 * legais, each opening in a new tab. A phrase whose page is not set stays
 * plain text, and so does the whole text when the module is off.
 */
function LegalText({ text }: { text: string }) {
  const legal = getGalaxieConfig().legal ?? {}
  const parts: React.ReactNode[] = []
  let last = 0

  for (const match of text.matchAll(ANY)) {
    const phrase = match[0]
    const doc = PHRASES.find(([re]) => re.test(phrase))?.[1]
    const page = doc ? legal[doc] : undefined
    if (!page?.url) continue

    const at = match.index ?? 0
    if (at > last) parts.push(text.slice(last, at))
    parts.push(
      <a key={at} href={page.url} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()}>
        {phrase}
      </a>
    )
    last = at + phrase.length
  }

  if (!parts.length) return <>{text}</>
  if (last < text.length) parts.push(text.slice(last))
  return <>{parts}</>
}

export { LegalText }
