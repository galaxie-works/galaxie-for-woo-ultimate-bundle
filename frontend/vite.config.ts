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
/**
 * Fails the build when a chunk imports an entry file.
 *
 * WordPress loads the entries with `?ver=…` on their URL. A chunk importing
 * `../galaxie-kit.js` would load a second copy of that module (another URL),
 * with its own kit store and a second boot. Entries must stay boot-only; what
 * chunks share lives in chunks (see manualChunks).
 */
function entriesStayLeaves(): Plugin {
  return {
    name: 'galaxie-entries-stay-leaves',
    enforce: 'post',
    generateBundle(_options, bundle) {
      const entries = new Set(
        Object.values(bundle)
          .filter((file) => file.type === 'chunk' && file.isEntry)
          .map((file) => file.fileName)
      )

      for (const file of Object.values(bundle)) {
        if (file.type !== 'chunk') continue

        const bad = [...file.imports, ...file.dynamicImports].filter((name) => entries.has(name))
        if (bad.length) this.error(`${file.fileName} imports the entry ${bad.join(', ')}: move the shared code into a chunk (manualChunks).`)
      }
    },
  }
}

export default defineConfig({
  // Relative, so the lazy chunks (the phone field's library, see lib/phone.ts)
  // load from beside galaxie.js and the flag sprites resolve from beside
  // galaxie.css, wherever WordPress serves the plugin from.
  base: './',
  plugins: [react(), tailwindcss(), scopeUtilities(), entriesStayLeaves()],
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
      // Two entries: everything (galaxie.js), and the kit flow alone
      // (galaxie-kit.js) for pages whose only Galaxie part is the kit launcher.
      // Code both use goes into shared chunks, one module per URL, so a page
      // with both still has one kit store.
      input: {
        galaxie: path.resolve(import.meta.dirname, 'src/main.tsx'),
        'galaxie-kit': path.resolve(import.meta.dirname, 'src/kit.ts'),
      },
      output: {
        // ES-module output so CSS is emitted as a separate, cacheable
        // `galaxie.css` (an IIFE build inlines the CSS into the JS). The entry
        // is `galaxie.js`, enqueued in WordPress with `type="module"` (see
        // Support\Assets). Its only code-split chunks are dynamic imports that
        // it loads itself, relative to its own URL; none of them may import
        // CSS, which all goes into the one `galaxie.css`.
        entryFileNames: '[name].js',
        // The kit's modules (all but the lazily loaded builder) and the pure
        // gift libraries in one hashed chunk, so the builder chunk imports
        // them from there and never from an entry file.
        manualChunks(id) {
          if (/[\/]src[\/]globals[\/]kit-(?!builder)[a-z-]+\.ts$/.test(id)) return 'kit-core'
          if (/[\/]src[\/]lib[\/](gift-[a-z-]+|pix-popup)\.ts$/.test(id)) return 'kit-core'
          return undefined
        },
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) =>
          info.name?.endsWith('.css') ? 'galaxie.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
})
