import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'path'

const pwaPlugin = VitePWA({
  registerType: 'autoUpdate',
  manifest: {
    name: 'FixPay',
    short_name: 'FixPay',
    description: 'Fast, secure Nigerian payments',
    theme_color: '#007AFF',
    background_color: '#ffffff',
    display: 'standalone',
    orientation: 'portrait-primary',
    start_url: '/',
    icons: [
      { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
      { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
    ],
  },
  workbox: {
    globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
    runtimeCaching: [{
      urlPattern: /\/api\//,
      handler: 'NetworkFirst',
      options: {
        cacheName: 'api-cache',
        networkTimeoutSeconds: 10,
        expiration: { maxEntries: 100, maxAgeSeconds: 300 },
      },
    }],
  },
})

export default defineConfig(({ command }) => ({
  plugins: [
    react(),
    tailwindcss(),
    ...(command === 'build' ? [pwaPlugin] : []),
  ],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  server: {
    port: 5273,
    host: true,
    // Proxy /api and /sanctum to Laravel backend (port 8001).
    // This eliminates CORS — browser sees same-origin requests.
    proxy: {
      '/api': { target: 'http://127.0.0.1:8001', changeOrigin: true },
      '/sanctum': { target: 'http://127.0.0.1:8001', changeOrigin: true },
    },
  },
  optimizeDeps: {
    include: ['recharts']
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id: string) {
          if (!id.includes('node_modules')) return undefined
          if (id.includes('react-router'))              return 'vendor-router'
          if (id.includes('react-dom') || id.includes('/react/')) return 'vendor-react'
          if (id.includes('@tanstack/react-query'))     return 'vendor-query'
          if (id.includes('/motion/') || id.includes('@heroicons')) return 'vendor-ui'
          if (id.includes('react-hook-form') || id.includes('@hookform') || id.includes('/zod/')) return 'vendor-forms'
          if (id.includes('axios') || id.includes('zustand') || id.includes('dexie') || id.includes('clsx') || id.includes('tailwind-merge')) return 'vendor-misc'
        },
      },
    },
  },
}))