import path from 'node:path'
import { createRequire } from 'node:module'
import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// postcss is not a direct dependency; Vite ships it, so borrow Vite's copy.
const require = createRequire(import.meta.url)
const postcss = createRequire(require.resolve('vite'))('postcss') as typeof import('postcss')

/**
 * Confines Tailwind's utilities to the islands.
 *
 * The bundle loads on every page of the store, and Tailwind's utility names
 * are the theme's names too: `.text-sm`, `.text-xs`, `.rounded-full`,
 * `.animate-in`. Imported globally they reached pixfort's own markup, where
 * `.animate-in` is its entrance animation and `.text-sm` its 14px text, and
 * changed line heights and animations the merchant had set in pixfort.
 *
 * Every rule in the utilities layer is rewritten to apply only inside
 * `.galaxie-ui` (the class every island mount, dialog and toast root carries)
 * or on that element itself. `:where()` adds no specificity, so the islands
 * look exactly as before. Rules with a pseudo-element get only the descendant
 * form, since nothing can follow `::placeholder`.
 */
function scopeUtilities(): Plugin {
  const scope = (selector: string): string => {
    const inside = `:where(.galaxie-ui) ${selector}`

    return selector.includes('::') ? inside : `${inside},${selector}:where(.galaxie-ui)`
  }

  return {
    name: 'galaxie-scope-utilities',
    enforce: 'post',
    generateBundle(_options, bundle) {
      for (const file of Object.values(bundle)) {
        if (file.type !== 'asset' || !file.fileName.endsWith('.css')) continue

        const root = postcss.parse(typeof file.source === 'string' ? file.source : Buffer.from(file.source).toString('utf8'))

        root.walkAtRules('layer', (layer) => {
          if (layer.params.trim() !== 'utilities') return

          layer.walkRules((rule) => {
            const parent = rule.parent

            if (parent && parent.type === 'atrule' && /keyframes$/.test((parent as import('postcss').AtRule).name)) return

            rule.selectors = rule.selectors.map(scope)
          })
        })

        file.source = root.toString()
      }
    },
  }
}

// Builds the React island bundle to the plugin's committed asset dir
// (../assets/dist). Fixed filenames (no hash) so PHP can enqueue them by a
// stable path; cache-busting is done PHP-side with filemtime().
export default defineConfig({
  plugins: [react(), tailwindcss(), scopeUtilities()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, 'src'),
    },
  },
  build: {
    outDir: path.resolve(import.meta.dirname, '../assets/dist'),
    emptyOutDir: true,
    manifest: false,
    rollupOptions: {
      input: path.resolve(import.meta.dirname, 'src/main.tsx'),
      output: {
        // ES-module output so CSS is emitted as a separate, cacheable
        // `galaxie.css` (an IIFE build inlines the CSS into the JS). The entry
        // has no code-split chunks, so it's a single `galaxie.js` module,
        // enqueued in WordPress with `type="module"` (see Support\Assets).
        entryFileNames: 'galaxie.js',
        assetFileNames: (info) =>
          info.name?.endsWith('.css') ? 'galaxie.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
})
