import { useEffect, useState } from 'react'

interface Clock {
  date: string
  time: string
}

function snapshot(): Clock {
  const now = new Date()
  return {
    date: now.toLocaleDateString('es-AR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }),
    time: now.toLocaleTimeString('es-AR', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    }),
  }
}

export function useClock(): Clock {
  const [clock, setClock] = useState<Clock>(snapshot)

  useEffect(() => {
    const id = setInterval(() => setClock(snapshot()), 1000)
    return () => clearInterval(id)
  }, [])

  return clock
}
