import path from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Builds the React island bundle to the plugin's committed asset dir
// (../assets/dist). Fixed filenames (no hash) so PHP can enqueue them by a
// stable path; cache-busting is done PHP-side with filemtime().
export default defineConfig({
  plugins: [react(), tailwindcss()],
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
