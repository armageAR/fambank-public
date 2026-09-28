# FamBank App

Frontend React + TypeScript + PWA para FamBank.

## Stack

- React 19
- TypeScript
- Vite
- shadcn/ui + Tailwind CSS
- Zustand (estado global)
- Axios (llamadas a la API)
- vite-plugin-pwa (PWA + Share Target)

## Variables de entorno requeridas en Railway

| Variable | Descripción |
|----------|-------------|
| `VITE_API_URL` | URL de la API Laravel (fambank-api.railway.app) |

## Desarrollo local

```bash
cd fambank-app
npm install
cp .env.example .env
npm run dev
```

## PWA - Share Target

La app está configurada para recibir comprobantes compartidos desde MercadoPago u otras apps en Android. Al instalar la PWA en el celu, aparece en el menú "Compartir" del sistema.
