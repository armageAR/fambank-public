import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { AxiosError } from 'axios'
import { login } from '@/api/auth'
import { useAuthStore } from '@/store/authStore'
import type { ApiError } from '@/types/api'

function detectDevice(): string {
  const ua = navigator.userAgent
  if (/android/i.test(ua)) return 'Android'
  if (/iphone/i.test(ua)) return 'iPhone'
  if (/ipad/i.test(ua)) return 'iPad'
  if (/windows/i.test(ua)) return 'Windows'
  if (/macintosh/i.test(ua)) return 'macOS'
  return 'Navegador Web'
}

export default function LoginPage() {
  const navigate = useNavigate()
  const { setAuth, user, token } = useAuthStore()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [rateLimitSecs, setRateLimitSecs] = useState<number | null>(null)

  // Redirect if already authenticated
  useEffect(() => {
    if (token && user) {
      navigate(user.role === 'admin' ? '/dashboard/admin' : '/dashboard/member', { replace: true })
    }
  }, [token, user, navigate])

  // Rate limit countdown
  useEffect(() => {
    if (!rateLimitSecs) return
    if (rateLimitSecs <= 0) { setRateLimitSecs(null); return }
    const id = setTimeout(() => setRateLimitSecs((s) => (s ?? 1) - 1), 1000)
    return () => clearTimeout(id)
  }, [rateLimitSecs])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (loading || rateLimitSecs) return

    setError(null)
    setFieldErrors({})
    setLoading(true)

    try {
      const { data } = await login(username.trim(), password, detectDevice())
      setAuth(data.data.token, data.data.user)
      navigate(
        data.data.user.role === 'admin' ? '/dashboard/admin' : '/dashboard/member',
        { replace: true },
      )
    } catch (err) {
      const e = err as AxiosError<ApiError>
      const status = e.response?.status
      const body = e.response?.data

      if (status === 422) {
        setFieldErrors(body?.errors ?? {})
      } else if (status === 429) {
        const secs = body?.data?.available_in ?? 60
        setRateLimitSecs(secs)
        setError(`Demasiados intentos. Podés volver a intentarlo en ${secs} segundos.`)
      } else if (status === 403) {
        setError('Tu cuenta está desactivada. Contactá al administrador.')
      } else if (status === 401) {
        setError('El usuario o la contraseña son incorrectos.')
      } else {
        setError('No se pudo conectar al servidor. Verificá tu conexión.')
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

        {/* Logo */}
        <div className="text-center mb-8 flex flex-col items-center">
          <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white text-2xl font-extrabold shadow-lg shadow-emerald-600/20 mb-3">
            $
          </div>
          <p className="text-xs tracking-[0.3em] text-gray-400 uppercase mb-1">Bienvenido a</p>
          <h1 className="text-3xl font-bold text-gray-900 tracking-tight">FamBank</h1>
          <p className="text-sm text-gray-500 mt-1">Gestión de ahorros familiares</p>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="bg-white border border-gray-100 shadow-sm rounded-2xl p-6 flex flex-col gap-4" noValidate>

          {/* Username */}
          <div className="flex flex-col gap-1.5">
            <label className="text-xs text-gray-500 font-medium tracking-wide uppercase">Usuario</label>
            <input
              type="text"
              autoComplete="username"
              autoCapitalize="none"
              autoCorrect="off"
              spellCheck={false}
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="tu usuario"
              className={inputClass}
              disabled={loading}
            />
            {fieldErrors.username && (
              <p className="text-xs text-red-500">{fieldErrors.username[0]}</p>
            )}
          </div>

          {/* Password */}
          <div className="flex flex-col gap-1.5">
            <label className="text-xs text-gray-500 font-medium tracking-wide uppercase">Contraseña</label>
            <div className="relative">
              <input
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                className={`${inputClass} pr-12`}
                disabled={loading}
              />
              <button
                type="button"
                onClick={() => setShowPassword((v) => !v)}
                className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors text-xs font-medium"
                tabIndex={-1}
              >
                {showPassword ? 'ocultar' : 'ver'}
              </button>
            </div>
            {fieldErrors.password && (
              <p className="text-xs text-red-500">{fieldErrors.password[0]}</p>
            )}
          </div>

          {/* Error message */}
          {error && (
            <div className={`rounded-xl px-4 py-3 text-sm ${
              rateLimitSecs
                ? 'bg-amber-50 border border-amber-200 text-amber-700'
                : 'bg-red-50 border border-red-200 text-red-600'
            }`}>
              {rateLimitSecs
                ? `Demasiados intentos. Intentá nuevamente en ${rateLimitSecs}s.`
                : error}
            </div>
          )}

          {/* Submit */}
          <button
            type="submit"
            disabled={loading || !!rateLimitSecs}
            className="mt-1 w-full rounded-xl bg-emerald-600 text-white font-semibold py-3 text-base hover:bg-emerald-500 active:bg-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed shadow-sm shadow-emerald-600/20"
          >
            {loading ? 'Ingresando...' : 'Ingresar'}
          </button>

          {/* Forgot password */}
          <div className="text-center">
            <Link
              to="/forgot-password"
              className="text-sm text-gray-500 hover:text-emerald-600 transition-colors"
            >
              ¿Olvidaste tu contraseña?
            </Link>
          </div>

        </form>
      </div>
    </div>
  )
}
