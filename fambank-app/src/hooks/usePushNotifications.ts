import { useCallback, useEffect, useState } from 'react'
import api from '@/api/client'

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)))
}

async function getVapidKey(): Promise<string | null> {
  try {
    const { data } = await api.get<{ key: string }>('/api/vapid-public-key')
    return data.key ?? null
  } catch {
    return null
  }
}

export type PushStatus = 'unsupported' | 'denied' | 'subscribed' | 'unsubscribed' | 'loading'

export function usePushNotifications() {
  const [status, setStatus] = useState<PushStatus>('loading')

  const check = useCallback(async () => {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      setStatus('unsupported'); return
    }
    if (Notification.permission === 'denied') {
      setStatus('denied'); return
    }
    const reg = await navigator.serviceWorker.ready
    const sub = await reg.pushManager.getSubscription()
    setStatus(sub ? 'subscribed' : 'unsubscribed')
  }, [])

  useEffect(() => { check() }, [check])

  const subscribe = useCallback(async () => {
    setStatus('loading')
    try {
      const vapidKey = await getVapidKey()
      if (!vapidKey) {
        console.error('No se pudo obtener la VAPID public key')
        setStatus('unsubscribed')
        return
      }

      const permission = await Notification.requestPermission()
      if (permission !== 'granted') { setStatus('denied'); return }

      const reg = await navigator.serviceWorker.ready

      // Desuscribir la existente para forzar una nueva con la clave actual
      const existing = await reg.pushManager.getSubscription()
      if (existing) await existing.unsubscribe()

      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey),
      })

      await api.post('/api/push-subscriptions', sub.toJSON())
      setStatus('subscribed')
    } catch (err) {
      console.error('Error al suscribirse a push:', err)
      setStatus('unsubscribed')
    }
  }, [])

  const unsubscribe = useCallback(async () => {
    const reg = await navigator.serviceWorker.ready
    const sub = await reg.pushManager.getSubscription()
    if (!sub) { setStatus('unsubscribed'); return }
    const endpoint = sub.endpoint
    await sub.unsubscribe()
    if (endpoint) {
      await api.delete('/api/push-subscriptions', { data: { endpoint } })
    }
    setStatus('unsubscribed')
  }, [])

  return { status, subscribe, unsubscribe }
}
