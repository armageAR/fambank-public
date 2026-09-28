# FamBank App

Mobile-first React and TypeScript frontend for the FamBank family savings ledger. Members can view their USD balance and request ARS deposits or withdrawals; administrators review requests and manage member accounts.

## Stack

- React 19, TypeScript, Vite, and React Router
- Tailwind CSS for styling
- Zustand for client state and Axios for API requests
- `vite-plugin-pwa` for installable PWA behavior and Share Target configuration

The current interface is in Spanish for its family audience.

## Run locally

```bash
npm install
cp .env.example .env
npm run dev
```

Set `VITE_API_URL` in `.env` to the local Laravel API URL (typically `http://localhost:8000`). Run `npm run build` to type-check and produce the frontend bundle. The app normally serves on `http://localhost:5173` during development.

## PWA and notifications

The PWA manifest is configured as an Android Share Target so the installed app can appear in the system sharing menu. Receiving a shared item is not the same as a complete receipt-import workflow. Push notifications require browser permission and the API's VAPID configuration; set `VITE_VAPID_PUBLIC_KEY` for the frontend when using that feature.

See the [root README](../README.md) for project scope and the [API README](../fambank-api/README.md) for backend setup.
