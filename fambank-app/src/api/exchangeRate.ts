import api from './client'
import type { ExchangeRate } from '@/types/api'

export const fetchExchangeRate = () =>
  api.get<ExchangeRate>('/api/exchange-rate')
