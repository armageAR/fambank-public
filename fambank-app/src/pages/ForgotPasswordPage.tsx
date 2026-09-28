import { useState } from 'react'
import { Link } from 'react-router-dom'
import { AxiosError } from 'axios'
import { forgotPassword } from '@/api/auth'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (loading) return

    setError(null)
    setLoading(true)

    try {
      await forgotPassword(email)
      setSent(true)
    } catch (err) {
      const e = err as AxiosError<{ message: string }>
      setError(e.response?.data?.message ?? 'No se pudo procesar la solicitud.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-[100dvh] bg-gradient-to-b from-emerald-50 to-white flex flex-col items-center justify-center px-4">
      <div className="w-full max-w-sm">

        {/* Header */}
        <div className="text-center mb-8">
          <p className="text-xs tracking-[0.3em] text-gray-400 uppercase mb-1">FamBank</p>
          <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Recuperar contraseña</h1>
          <p className="text-sm text-gray-500 mt-1">
            Ingresá tu email y te enviaremos las instrucciones.
          </p>
        </div>

        {sent ? (
          /* Success state */
          <div className="flex flex-col gap-6">
            <div className="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-4 text-sm text-emerald-700 text-center">
              Si el email está registrado, recibirás un correo con las instrucciones para
              restablecer tu contraseña.
            </div>
            <Link
              to="/login"
              className="text-center text-sm text-gray-500 hover:text-emerald-600 transition-colors"
            >
              ← Volver al inicio de sesión
            </Link>
          </div>
        ) : (
          /* Form */
          <form onSubmit={handleSubmit} className="bg-white border border-gray-100 shadow-sm rounded-2xl p-6 flex flex-col gap-4" noValidate>
            <div className="flex flex-col gap-1.5">
              <label className="text-xs text-gray-500 font-medium tracking-wide uppercase">Email</label>
              <input
                type="email"
                inputMode="email"
                autoComplete="email"
                autoCapitalize="none"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="tu@email.com"
                className="w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-colors"
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
              disabled={loading || !email}
              className="w-full rounded-xl bg-emerald-600 text-white font-semibold py-3 text-base hover:bg-emerald-500 active:bg-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed shadow-sm shadow-emerald-600/20"
            >
              {loading ? 'Enviando...' : 'Enviar instrucciones'}
            </button>

            <Link
              to="/login"
              className="text-center text-sm text-gray-500 hover:text-emerald-600 transition-colors"
            >
              ← Volver al inicio de sesión
            </Link>
          </form>
        )}
      </div>
    </div>
  )
}
