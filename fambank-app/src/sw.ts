/// <reference lib="webworker" />
import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching'

declare const self: ServiceWorkerGlobalScope & {
  __WB_MANIFEST: Array<{ url: string; revision: string | null }>
}

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

self.addEventListener('push', (event) => {
  if (!event.data) return

  const { title = 'FamBank', body = '' } = event.data.json() as {
    title: string
    body: string
  }

  event.waitUntil(self.registration.showNotification(title, {
    body,
    icon: '/pwa-192x192.png',
    badge: '/pwa-192x192.png',
    tag: 'fambank-tx',
    // renotify: sin esto, una notificación con el mismo tag reemplaza a la
    // anterior en silencio (sin sonido ni vibración) y pasa desapercibida
    renotify: true,
  } as NotificationOptions))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  event.waitUntil(
    self.clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then(list => {
        const existing = list.find(c => c.url.includes('/dashboard'))
        if (existing && 'focus' in existing) return (existing as WindowClient).focus()
        // "/" redirige al dashboard que corresponda según el rol del usuario
        return self.clients.openWindow('/')
      })
  )
})
