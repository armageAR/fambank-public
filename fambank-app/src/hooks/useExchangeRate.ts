import { useCallback, useEffect, useState } from 'react'
import { fetchExchangeRate } from '@/api/exchangeRate'
import type { ExchangeRate } from '@/types/api'

const POLL_MS = 5 * 60 * 1000 // 5 minutos

interface State {
  rate: ExchangeRate | null
  loading: boolean
  error: boolean
}

export function useExchangeRate(): State & { refresh: () => void } {
  const [state, setState] = useState<State>({ rate: null, loading: true, error: false })

  const refresh = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: false }))
    try {
      const { data } = await fetchExchangeRate()
      setState({ rate: data, loading: false, error: false })
    } catch {
      setState((s) => ({ ...s, loading: false, error: true }))
    }
  }, [])

  useEffect(() => {
    refresh()
    const id = setInterval(refresh, POLL_MS)
    return () => clearInterval(id)
  }, [refresh])

  return { ...state, refresh }
}
