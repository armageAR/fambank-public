export interface User {
  id: number
  name: string
  username: string
  email: string
  role: 'admin' | 'member'
  balance_usd: string | null
  active: boolean
  last_login_at: string | null
}

export interface ExchangeRatePair {
  buy: number
  sell: number
}

export interface ExchangeRate {
  blue: ExchangeRatePair
  oficial: ExchangeRatePair
  fetched_at: string
}

export interface Transaction {
  id: number
  type: 'deposit' | 'withdrawal' | 'adjustment'
  type_label: string
  status: 'pending' | 'confirmed' | 'rejected' | 'cancelled'
  status_label: string
  amount_usd: string
  amount_ars: string
  exchange_rate: string
  notes: string | null
  confirmed_at: string | null
  created_at: string
  created_by_id: number | null
  created_by_name: string | null  // null si lo creó el propio member
  user?: User
  reviewer?: User
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ApiError {
  message: string
  errors?: Record<string, string[]>
  data?: { available_in?: number }
}
