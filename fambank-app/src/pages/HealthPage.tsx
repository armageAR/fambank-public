import { useEffect, useState, useCallback } from 'react'

interface ServiceStatus {
  status: 'ok' | 'error' | 'checking'
  label: string
  detail?: string
}

interface HealthState {
  frontend: ServiceStatus
  api: ServiceStatus
  database: ServiceStatus
  lastChecked: Date | null
}

const API_URL = import.meta.env.VITE_API_URL ?? ''

export default function HealthPage() {
  const [health, setHealth] = useState<HealthState>({
    frontend: { status: 'ok', label: 'Frontend' },
    api: { status: 'checking', label: 'API' },
    database: { status: 'checking', label: 'Base de datos' },
    lastChecked: null,
  })
  const [checking, setChecking] = useState(false)

  const check = useCallback(async () => {
    setChecking(true)
    setHealth(h => ({
      ...h,
      api: { ...h.api, status: 'checking' },
      database: { ...h.database, status: 'checking' },
    }))

    try {
      const res = await fetch(`${API_URL}/api/health`, {
        signal: AbortSignal.timeout(8000),
      })
      const data = await res.json()

      setHealth({
        frontend: { status: 'ok', label: 'Frontend', detail: 'React + Vite' },
        api: {
          status: data.services.api.status === 'ok' ? 'ok' : 'error',
          label: 'API',
          detail: data.services.api.status === 'ok'
            ? `Laravel ${data.services.api.version}`
            : 'No responde',
        },
        database: {
          status: data.services.database.status === 'ok' ? 'ok' : 'error',
          label: 'Base de datos',
          detail: data.services.database.status === 'ok'
            ? 'PostgreSQL conectado'
            : data.services.database.error ?? 'Error de conexión',
        },
        lastChecked: new Date(),
      })
    } catch {
      setHealth(h => ({
        ...h,
        api: { ...h.api, status: 'error', detail: 'No se pudo conectar' },
        database: { ...h.database, status: 'error', detail: 'Depende de la API' },
        lastChecked: new Date(),
      }))
    } finally {
      setChecking(false)
    }
  }, [])

  useEffect(() => {
    check()
    const interval = setInterval(check, 30000)
    return () => clearInterval(interval)
  }, [check])

  const services = [health.frontend, health.api, health.database]
  const allOk = services.every(s => s.status === 'ok')
  const anyError = services.some(s => s.status === 'error')

  return (
    <div className="min-h-screen bg-gradient-to-b from-emerald-50 to-white text-gray-900 flex flex-col items-center justify-center p-6">
      <div className="mb-10 text-center">
        <div className="text-xs tracking-[0.3em] text-gray-400 uppercase mb-2">Sistema</div>
        <h1 className="text-3xl font-bold tracking-tight text-gray-900">FamBank</h1>
        <div className="mt-3 flex items-center justify-center gap-2">
          <span className={`inline-block w-2 h-2 rounded-full ${
            anyError ? 'bg-red-500 animate-pulse' :
            allOk ? 'bg-emerald-500' :
            'bg-amber-400 animate-pulse'
          }`} />
          <span className="text-xs text-gray-500">
            {anyError ? 'Degradado' : allOk ? 'Operacional' : 'Verificando...'}
          </span>
        </div>
      </div>

      <div className="w-full max-w-sm flex flex-col gap-3">
        {services.map((svc) => (
          <div key={svc.label} className="flex items-center gap-4 bg-white border border-gray-100 shadow-sm rounded-xl px-5 py-4">
            <div className="shrink-0">
              {svc.status === 'checking' ? (
                <div className="w-3 h-3 rounded-full bg-gray-300 animate-pulse" />
              ) : svc.status === 'ok' ? (
                <div className="w-3 h-3 rounded-full bg-emerald-500 shadow-[0_0_8px_2px_rgba(16,185,129,0.4)]" />
              ) : (
                <div className="w-3 h-3 rounded-full bg-red-500 shadow-[0_0_8px_2px_rgba(239,68,68,0.4)] animate-pulse" />
              )}
            </div>
            <div className="flex-1 min-w-0">
              <div className="text-sm font-semibold text-gray-900">{svc.label}</div>
              {svc.detail && <div className="text-xs text-gray-400 truncate mt-0.5">{svc.detail}</div>}
            </div>
            <div className={`text-xs font-medium px-2 py-0.5 rounded-md ${
              svc.status === 'checking' ? 'text-gray-400 bg-gray-100' :
              svc.status === 'ok' ? 'text-emerald-600 bg-emerald-50' :
              'text-red-500 bg-red-50'
            }`}>
              {svc.status === 'checking' ? '...' : svc.status === 'ok' ? 'OK' : 'ERROR'}
            </div>
          </div>
        ))}
      </div>

      <div className="mt-8 flex flex-col items-center gap-3">
        {health.lastChecked && (
          <p className="text-xs text-gray-400">Última verificación: {health.lastChecked.toLocaleTimeString('es-AR')}</p>
        )}
        <button onClick={check} disabled={checking}
          className="text-xs text-gray-500 hover:text-gray-900 transition-colors disabled:opacity-40 border border-gray-200 hover:border-gray-300 px-4 py-1.5 rounded-lg cursor-pointer">
          {checking ? 'Verificando...' : 'Verificar ahora'}
        </button>
        <p className="text-xs text-gray-300">Refresca cada 30s</p>
      </div>
    </div>
  )
}
