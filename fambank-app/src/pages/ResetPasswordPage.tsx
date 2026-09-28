import { useState } from 'react'
import { useNavigate, useSearchParams, Link } from 'react-router-dom'
import { AxiosError } from 'axios'
import { resetPassword } from '@/api/auth'

export default function ResetPasswordPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''

  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [done, setDone] = useState(false)

  if (!token || !email) {
    return (
      <div className="min-h-[100dvh] bg-gradient-to-b from-emerald-50 to-white flex flex-col items-center justify-center px-4 text-center">
        <p className="text-gray-500 text-sm">Enlace inválido o expirado.</p>
        <Link to="/login" className="mt-4 text-sm text-gray-500 hover:text-emerald-600 transition-colors">
          ← Volver al inicio de sesión
        </Link>
      </div>
    )
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (loading) return

    setError(null)
    setFieldErrors({})
    setLoading(true)

    try {
      await resetPassword(token, email, password, confirm)
      setDone(true)
      setTimeout(() => navigate('/login', { replace: true }), 2500)
    } catch (err) {
      const e = err as AxiosError<{ message: string; errors?: Record<string, string[]> }>
      if (e.response?.status === 422) {
        setFieldErrors(e.response.data?.errors ?? {})
        setError(e.response.data?.message ?? null)
      } else {
        setError(e.response?.data?.message ?? 'No se pudo restablecer la contraseña.')
      }
    } finally {
      setLoading(false)
    }
  }

  const inputClass =
    'w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-colors'

  return (
    <div className="min-h-[100dvh] bg-gradient-to-b from-emerald-50 to-white flex flex-col items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <p className="text-xs tracking-[0.3em] text-gray-400 uppercase mb-1">FamBank</p>
          <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Nueva contraseña</h1>
        </div>

        {done ? (
          <div className="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-4 text-sm text-emerald-700 text-center">
            ¡Contraseña restablecida! Redirigiendo al inicio de sesión...
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="bg-white border border-gray-100 shadow-sm rounded-2xl p-6 flex flex-col gap-4" noValidate>
            <div className="flex flex-col gap-1.5">
              <label className="text-xs text-gray-500 font-medium tracking-wide uppercase">Nueva contraseña</label>
              <input
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Mínimo 8 caracteres"
                className={inputClass}
                disabled={loading}
              />
              {fieldErrors.password && (
                <p className="text-xs text-red-500">{fieldErrors.password[0]}</p>
              )}
            </div>

            <div className="flex flex-col gap-1.5">
              <label className="text-xs text-gray-500 font-medium tracking-wide uppercase">Confirmar contraseña</label>
              <input
                type="password"
                autoComplete="new-password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                placeholder="Repetí la contraseña"
                className={inputClass}
                disabled={loading}
              />
            </div>

            {error && (
              <div className="rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
                {error}
              </div>
            )}

            <button
              type="submit"
              disabled={loading || !password || !confirm}
              className="w-full rounded-xl bg-emerald-600 text-white font-semibold py-3 text-base hover:bg-emerald-500 active:bg-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed shadow-sm shadow-emerald-600/20"
            >
              {loading ? 'Guardando...' : 'Guardar contraseña'}
            </button>
          </form>
        )}
      </div>
    </div>
  )
}
