import api from './client'
import type { Transaction, User, PaginationMeta } from '@/types/api'

export const getMyTransactions = (page = 1) =>
  api.get<{ data: Transaction[]; user: User; meta: PaginationMeta }>(`/api/transactions?page=${page}`)

export const createTransaction = (payload: {
  type: 'deposit' | 'withdrawal'
  amount_ars: number
  exchange_rate?: number // solo admin; para members el servidor obtiene la cotización al grabar
  user_id?: number
  notes?: string
}) => api.post<{ data: Transaction; user: User; message: string }>('/api/transactions', payload)

export const cancelTransaction = (id: number) =>
  api.delete<{ data: Transaction; user: User; message: string }>(`/api/transactions/${id}`)

export const getPendingTransactions = () =>
  api.get<{ data: Transaction[] }>('/api/admin/transactions')

export const getUserTransactions = (userId: number, page = 1) =>
  api.get<{ data: Transaction[]; user: User; meta: PaginationMeta }>(`/api/admin/users/${userId}/transactions?page=${page}`)

export const confirmTransaction = (id: number, exchangeRate?: number, notes?: string) =>
  api.put<{ data: Transaction; message: string }>(`/api/admin/transactions/${id}/confirm`, {
    ...(exchangeRate !== undefined && { exchange_rate: exchangeRate }),
    ...(notes !== undefined && { notes }),
  })

export const rejectTransaction = (id: number, notes?: string) =>
  api.put<{ data: Transaction; message: string }>(`/api/admin/transactions/${id}/reject`, {
    ...(notes !== undefined && { notes }),
  })
