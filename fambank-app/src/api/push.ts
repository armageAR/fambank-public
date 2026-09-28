import api from './client'

export const testPushNotification = () =>
  api.post<{ message: string; data: { sent: number; failed: number; errors: string[] } }>(
    '/api/push-subscriptions/test',
  )
