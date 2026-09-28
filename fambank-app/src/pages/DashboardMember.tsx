import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AxiosError } from 'axios'
import { logout, updateProfile } from '@/api/auth'
import { getMyTransactions, createTransaction, cancelTransaction } from '@/api/transactions'
import { useAuthStore } from '@/store/authStore'
import { useExchangeRate } from '@/hooks/useExchangeRate'
import type { Transaction, User } from '@/types/api'

function fmt(n: number, decimals = 2) {
  return n.toLocaleString('es-AR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

// ─── Profile modal (email y contraseña) ──────────────────────────────────────
function ProfileModal({ user, onClose, onUpdated }: { user: User; onClose: () => void; onUpdated: (u: User, msg: string) => void }) {
  const [email, setEmail]               = useState(user.email)
  const [currentPass, setCurrentPass]   = useState('')
  const [newPass, setNewPass]           = useState('')
  const [confirmPass, setConfirmPass]   = useState('')
  const [error, setError]               = useState<string | null>(null)
  const [fieldErrors, setFE]            = useState<Record<string, string[]>>({})
  const [loading, setLoading]           = useState(false)

  const wantsPasswordChange = newPass !== '' || confirmPass !== '' || currentPass !== ''
  const emailChanged        = email.trim() !== user.email
  const canSave = !loading && (emailChanged || wantsPasswordChange)

  const inputClass = 'w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-colors'

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!canSave) return
    setError(null); setFE({}); setLoading(true)
    try {
      const payload: Parameters<typeof updateProfile>[0] = {}
      if (emailChanged) payload.email = email.trim()
      if (wantsPasswordChange) {
        payload.password              = newPass
        payload.password_confirmation = confirmPass
        payload.current_password      = currentPass
      }
      const { data } = await updateProfile(payload)
      onUpdated(data.data, data.message)
    } catch (err) {
      const e = err as AxiosError<{ message: string; errors?: Record<string, string[]> }>
      if (e.response?.status === 422 && e.response.data?.errors) setFE(e.response.data.errors)
      else setError(e.response?.data?.message ?? 'Error al guardar.')
    } finally { setLoading(false) }
  }

  return (
    <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-4">
      <div className="w-full max-w-sm bg-white border border-gray-100 shadow-xl rounded-2xl p-6 flex flex-col gap-4 max-h-[90dvh] overflow-y-auto">

        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-900">Mi cuenta</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg leading-none">✕</button>
        </div>

        {/* Datos fijos */}
        <div className="rounded-xl bg-gray-50 px-4 py-3 flex flex-col gap-1">
          <p className="text-sm text-gray-900">{user.name} <span className="text-xs text-gray-500">@{user.username}</span></p>
          <p className="text-xs text-gray-400">El nombre y el usuario los administra el admin.</p>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-3" noValidate>
          {/* Email */}
          <div>
            <p className="text-xs text-gray-500 uppercase tracking-wide mb-1.5">Email</p>
            <input type="email" inputMode="email" autoCapitalize="none" value={email} onChange={e => setEmail(e.target.value)} className={inputClass} disabled={loading} />
            {fieldErrors.email && <p className="text-xs text-red-500 mt-1">{fieldErrors.email[0]}</p>}
          </div>

          {/* Cambio de contraseña */}
          <div className="border-t border-gray-100 pt-3 flex flex-col gap-3">
            <p className="text-xs text-gray-500 uppercase tracking-wide">Cambiar contraseña <span className="text-gray-400 normal-case">(opcional)</span></p>
            <div>
              <input type="password" autoComplete="current-password" value={currentPass} onChange={e => setCurrentPass(e.target.value)} placeholder="Contraseña actual" className={inputClass} disabled={loading} />
              {fieldErrors.current_password && <p className="text-xs text-red-500 mt-1">{fieldErrors.current_password[0]}</p>}
            </div>
            <div>
              <input type="password" autoComplete="new-password" value={newPass} onChange={e => setNewPass(e.target.value)} placeholder="Nueva contraseña" className={inputClass} disabled={loading} />
              {fieldErrors.password && <p className="text-xs text-red-500 mt-1">{fieldErrors.password[0]}</p>}
            </div>
            <input type="password" autoComplete="new-password" value={confirmPass} onChange={e => setConfirmPass(e.target.value)} placeholder="Confirmar nueva contraseña" className={inputClass} disabled={loading} />
          </div>

          {error && <p className="text-xs text-red-500">{error}</p>}

          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="flex-1 rounded-xl border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300 py-3 text-sm transition-colors">
              Cancelar
            </button>
            <button type="submit" disabled={!canSave} className="flex-1 rounded-xl bg-emerald-600 text-white font-semibold py-3 text-sm hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
              {loading ? 'Guardando...' : 'Guardar'}
            </button>
          </div>
        </form>

      </div>
    </div>
  )
}

// ─── Transaction creation modal ───────────────────────────────────────────────
function NewTransactionModal({
  onClose, onCreated, buyRate, sellRate, balanceUsd,
}: {
  onClose: () => void
  onCreated: () => void
  buyRate: number
  sellRate: number
  balanceUsd: number
}) {
  const [type, setType]       = useState<'deposit' | 'withdrawal'>('deposit')
  const [amountArs, setArs]   = useState('')
  const [notes, setNotes]     = useState('')
  const [error, setError]     = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const rate       = type === 'deposit' ? sellRate : buyRate
  const rateLabel  = type === 'deposit' ? 'Cotización de venta' : 'Cotización de compra'
  const ars        = parseFloat(amountArs) || 0
  const usd        = ars > 0 ? Math.round((ars / rate) * 100) / 100 : 0
  const overBalance = type === 'withdrawal' && usd > balanceUsd && usd > 0

  const canAccept = ars > 0 && !overBalance && !loading

  const handleSubmit = async () => {
    if (!canAccept) return
    setError(null)
    setLoading(true)
    try {
      // El TC se envía solo como referencia/compatibilidad: la API nueva lo
      // ignora para members (usa la cotización del servidor al grabar), y la
      // API vieja lo requiere — así el deploy de frontend y API no necesita
      // ser simultáneo.
      await createTransaction({ type, amount_ars: ars, exchange_rate: rate, notes: notes.trim() || undefined })
      onCreated()
    } catch (err) {
      const e = err as AxiosError<{ message: string }>
      setError(e.response?.data?.message ?? 'Error al crear la transacción.')
    } finally {
      setLoading(false)
    }
  }

  const inputBase = 'w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-colors'

  return (
    <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-4">
      <div className="w-full max-w-sm bg-white border border-gray-100 shadow-xl rounded-2xl p-6 flex flex-col gap-4">

        {/* Header */}
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-900">Nueva transacción</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg leading-none">✕</button>
        </div>

        {/* Type selector */}
        <div className="grid grid-cols-2 gap-2">
          <button
            onClick={() => setType('deposit')}
            className={`rounded-xl py-3 text-sm font-semibold border transition-colors ${
              type === 'deposit'
                ? 'bg-emerald-50 border-emerald-300 text-emerald-600'
                : 'border-gray-200 text-gray-400 hover:border-gray-300 hover:text-gray-600'
            }`}
          >
            ↓ Depósito
          </button>
          <button
            onClick={() => setType('withdrawal')}
            className={`rounded-xl py-3 text-sm font-semibold border transition-colors ${
              type === 'withdrawal'
                ? 'bg-red-50 border-red-300 text-red-600'
                : 'border-gray-200 text-gray-400 hover:border-gray-300 hover:text-gray-600'
            }`}
          >
            ↑ Retiro
          </button>
        </div>

        {/* Amount in ARS */}
        <div>
          <p className="text-xs text-gray-500 uppercase tracking-wide mb-1.5">Importe en pesos</p>
          <div className="relative">
            <span className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm">$</span>
            <input
              type="number"
              inputMode="decimal"
              step="1"
              min="1"
              value={amountArs}
              onChange={e => setArs(e.target.value)}
              placeholder="0"
              className={`${inputBase} pl-8`}
              autoFocus
            />
          </div>
        </div>

        {/* Rate + USD preview */}
        <div className={`rounded-xl px-4 py-3 flex flex-col gap-2 ${type === 'deposit' ? 'bg-emerald-50 border border-emerald-100' : 'bg-red-50 border border-red-100'}`}>
          <div className="flex items-center justify-between">
            <p className="text-xs text-gray-500">{rateLabel} <span className="text-gray-400">(referencia)</span></p>
            <p className="text-sm font-semibold text-gray-900">$ {fmt(rate, 0)}</p>
          </div>
          <div className="h-px bg-gray-200/70" />
          <div className="flex items-center justify-between">
            <p className="text-xs text-gray-500">
              {type === 'deposit' ? 'USD a acreditar (pendiente)' : 'USD a retirar'}
            </p>
            <p className={`text-lg font-bold ${type === 'deposit' ? 'text-emerald-600' : 'text-red-600'}`}>
              {ars > 0 ? `USD ${fmt(usd)}` : '—'}
            </p>
          </div>
          {overBalance && (
            <p className="text-xs text-red-500">
              Saldo insuficiente. Tenés USD {fmt(balanceUsd)}.
            </p>
          )}
          <p className="text-xs text-gray-400">La cotización definitiva se obtiene al grabar la operación.</p>
        </div>

        {/* Notes */}
        <div>
          <p className="text-xs text-gray-500 uppercase tracking-wide mb-1.5">Comentario <span className="text-gray-400 normal-case">(opcional)</span></p>
          <textarea
            value={notes}
            onChange={e => setNotes(e.target.value)}
            placeholder="Ej: transferencia del 10/06..."
            rows={2}
            className="w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-colors resize-none"
          />
        </div>

        {error && <p className="text-xs text-red-500">{error}</p>}

        {/* Actions */}
        <div className="flex gap-2">
          <button
            onClick={onClose}
            className="flex-1 rounded-xl border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300 py-3 text-sm transition-colors"
          >
            Cancelar
          </button>
          <button
            onClick={handleSubmit}
            disabled={!canAccept}
            className={`flex-1 rounded-xl font-semibold py-3 text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
              type === 'deposit'
                ? 'bg-emerald-600 text-white hover:bg-emerald-500 active:bg-emerald-700'
                : 'bg-red-500 text-white hover:bg-red-400 active:bg-red-600'
            }`}
          >
            {loading ? 'Enviando...' : '✓ Aceptar'}
          </button>
        </div>

      </div>
    </div>
  )
}

// ─── Transaction row ──────────────────────────────────────────────────────────
function TxRow({ tx, onCancel }: { tx: Transaction; onCancel: (tx: Transaction) => void }) {
  const isDeposit   = tx.type === 'deposit'
  const isPending   = tx.status === 'pending'
  const usd = parseFloat(tx.amount_usd)
  const ars = parseFloat(tx.amount_ars)

  const date = new Date(tx.created_at).toLocaleDateString('es-AR', {
    day: '2-digit', month: '2-digit', year: '2-digit',
  })

  return (
    <div className={`rounded-xl border px-4 py-3 flex flex-col gap-1.5 ${
      isPending ? 'bg-amber-50/50 border-amber-200' : 'bg-white border-gray-100'
    }`}>
      <div className="flex items-center gap-3">
        {/* Type icon */}
        <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm shrink-0 ${
          isDeposit ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500'
        }`}>
          {isDeposit ? '↓' : '↑'}
        </div>

        {/* Info */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-1.5 flex-wrap">
            <p className={`text-sm font-semibold ${isDeposit ? 'text-emerald-600' : 'text-red-500'}`}>
              {isDeposit ? '+' : '-'} USD {fmt(usd)}
            </p>
            {isPending && (
              <span className="text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-md">
                pendiente
              </span>
            )}
            {tx.status === 'rejected' && (
              <span className="text-xs bg-red-50 text-red-500 border border-red-200 px-1.5 py-0.5 rounded-md">
                rechazada
              </span>
            )}
            {tx.status === 'cancelled' && (
              <span className="text-xs bg-gray-100 text-gray-500 border border-gray-200 px-1.5 py-0.5 rounded-md">
                cancelada
              </span>
            )}
          </div>
          <p className="text-xs text-gray-400 truncate">
            $ {fmt(ars, 0)} · {date}
            {tx.created_by_name && (
              <span className="text-amber-600/80"> · por {tx.created_by_name}</span>
            )}
          </p>
        </div>

        {/* Cancel button */}
        {isPending && (
          <button
            onClick={() => onCancel(tx)}
            className="text-xs text-red-500 border border-red-200 hover:border-red-300 px-2 py-1 rounded-lg transition-colors shrink-0"
          >
            Cancelar
          </button>
        )}
      </div>

      {/* Notes */}
      {tx.notes && (
        <p className="text-xs text-gray-400 pl-11 italic">"{tx.notes}"</p>
      )}
    </div>
  )
}

// ─── Dashboard ────────────────────────────────────────────────────────────────
export default function DashboardMember() {
  const navigate = useNavigate()
  const { user, setUser, clearAuth } = useAuthStore()
  const { rate, loading: rateLoading, error: rateError, refresh } = useExchangeRate()

  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loadingTx, setLoadingTx]       = useState(true)
  const [showNewTx, setShowNewTx]       = useState(false)
  const [showProfile, setShowProfile]   = useState(false)
  const [profileMsg, setProfileMsg]     = useState<string | null>(null)
  const [page, setPage]                 = useState(1)
  const [lastPage, setLastPage]         = useState(1)
  const [total, setTotal]               = useState(0)

  const loadTransactions = useCallback(async (p = 1) => {
    setLoadingTx(true)
    try {
      const { data } = await getMyTransactions(p)
      setTransactions(data.data)
      setLastPage(data.meta.last_page)
      setTotal(data.meta.total)
      setUser(data.user)
    } catch { /* ignore */ }
    finally { setLoadingTx(false) }
  }, [setUser])

  useEffect(() => { loadTransactions(page) }, [loadTransactions, page])

  const handleCancel = async (tx: Transaction) => {
    if (!confirm('¿Cancelar esta transacción?')) return
    try {
      const { data } = await cancelTransaction(tx.id)
      setUser(data.user)
      setTransactions(ts => ts.map(t => t.id === tx.id ? data.data : t))
    } catch { /* ignore */ }
  }

  const handleCreated = async () => {
    setShowNewTx(false)
    setPage(1)
    await loadTransactions(1)
  }

  const goToPage = (p: number) => {
    setPage(p)
    loadTransactions(p)
  }

  const handleLogout = async () => {
    try { await logout() } catch { /* ignore */ }
    clearAuth()
    navigate('/login', { replace: true })
  }

  const balanceUsd = parseFloat(user?.balance_usd ?? '0')
  const balanceArs = rate ? balanceUsd * rate.blue.buy : null

  return (
    <div className="min-h-[100dvh] bg-gray-50 text-gray-900 flex flex-col">

      {/* Header */}
      <header className="flex items-center justify-between px-5 py-4 bg-white border-b border-gray-100">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white text-base font-extrabold shrink-0">
            $
          </div>
          <div>
            <p className="text-xs text-gray-400 tracking-[0.2em] uppercase">FamBank</p>
            <p className="text-sm font-semibold text-gray-900">
              {user?.name}{user?.username && <span className="text-xs font-normal text-gray-400"> @{user.username}</span>}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setShowProfile(true)}
            className="text-xs text-gray-500 hover:text-gray-900 border border-gray-200 hover:border-gray-300 px-3 py-1.5 rounded-lg transition-colors"
          >
            Mi cuenta
          </button>
          <button
            onClick={handleLogout}
            className="text-xs text-gray-400 hover:text-gray-700 border border-gray-200 hover:border-gray-300 px-3 py-1.5 rounded-lg transition-colors"
          >
            Salir
          </button>
        </div>
      </header>

      <main className="flex-1 flex flex-col gap-4 p-5 pb-8">

        {/* Profile update feedback */}
        {profileMsg && (
          <div className="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 flex items-center justify-between gap-3">
            <p className="text-xs text-emerald-700">{profileMsg}</p>
            <button onClick={() => setProfileMsg(null)} className="text-emerald-600 hover:text-emerald-700 text-sm leading-none shrink-0">✕</button>
          </div>
        )}

        {/* Balance */}
        <div className="rounded-2xl bg-white border border-gray-100 shadow-sm px-5 py-5">
          <p className="text-xs text-gray-400 tracking-wide uppercase mb-3">Tu saldo</p>
          <div className="flex flex-col gap-3">
            <div className="flex items-baseline justify-between">
              <span className="text-xs text-gray-500">Dólares</span>
              <span className="text-2xl font-bold text-emerald-600">USD {fmt(balanceUsd)}</span>
            </div>
            <div className="h-px bg-gray-100" />
            <div className="flex items-baseline justify-between">
              <span className="text-xs text-gray-500">
                Pesos{rate ? <span className="text-gray-400"> (× {fmt(rate.blue.buy, 0)})</span> : ''}
              </span>
              <span className="text-lg font-semibold text-gray-900">
                {balanceArs !== null ? `$ ${fmt(balanceArs, 0)}` : '—'}
              </span>
            </div>
          </div>
        </div>

        {/* Exchange rate */}
        <div className="rounded-2xl bg-white border border-gray-100 shadow-sm px-5 py-5">
          <div className="flex items-center justify-between mb-3">
            <p className="text-xs text-gray-400 tracking-wide uppercase">Dólar blue</p>
            {!rateLoading && (
              <button onClick={refresh} className="text-xs text-gray-400 hover:text-emerald-600 transition-colors">actualizar</button>
            )}
          </div>
          {rateLoading && !rate ? (
            <p className="text-xs text-gray-400">Obteniendo cotización...</p>
          ) : rateError && !rate ? (
            <p className="text-xs text-red-500">No se pudo obtener la cotización.</p>
          ) : rate ? (
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-xl bg-gray-50 px-4 py-3">
                <p className="text-xs text-gray-500 text-right">Compra</p>
                <p className="text-xs text-gray-400 mb-1 text-right">(Lo que te pago por dólar)</p>
                <p className="text-xl font-bold text-gray-900 text-right">$ {fmt(rate.blue.buy, 0)}</p>
              </div>
              <div className="rounded-xl bg-gray-50 px-4 py-3">
                <p className="text-xs text-gray-500 text-right">Venta</p>
                <p className="text-xs text-gray-400 mb-1 text-right">(Lo que pagás por dólar)</p>
                <p className="text-xl font-bold text-gray-900 text-right">$ {fmt(rate.blue.sell, 0)}</p>
              </div>
              <p className="col-span-2 text-xs text-gray-300 text-right">Fuente: Bluelytics · actualiza cada 5min</p>
            </div>
          ) : null}
        </div>

        {/* Transactions */}
        <div className="flex flex-col gap-3">
          <div className="flex items-center justify-between">
            <p className="text-xs text-gray-400 tracking-wide uppercase">Movimientos</p>
            {rate && (
              <button
                onClick={() => setShowNewTx(true)}
                className="text-xs bg-emerald-600 text-white font-semibold px-3 py-2 rounded-xl hover:bg-emerald-500 active:bg-emerald-700 transition-colors"
              >
                + Nueva
              </button>
            )}
          </div>

          {loadingTx ? (
            <p className="text-xs text-gray-400">Cargando movimientos...</p>
          ) : transactions.length === 0 ? (
            <p className="text-xs text-gray-400">Sin movimientos todavía.</p>
          ) : (
            <>
              {transactions.map(tx => (
                <TxRow key={tx.id} tx={tx} onCancel={handleCancel} />
              ))}

              {/* Pagination */}
              {lastPage > 1 && (
                <div className="flex items-center justify-between pt-1">
                  <button
                    onClick={() => goToPage(page - 1)}
                    disabled={page <= 1}
                    className="text-xs border border-gray-200 text-gray-500 hover:text-gray-900 hover:border-gray-300 px-3 py-2 rounded-lg transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  >
                    ← Anterior
                  </button>
                  <p className="text-xs text-gray-400">
                    Página {page} de {lastPage} <span className="text-gray-300">· {total} movimientos</span>
                  </p>
                  <button
                    onClick={() => goToPage(page + 1)}
                    disabled={page >= lastPage}
                    className="text-xs border border-gray-200 text-gray-500 hover:text-gray-900 hover:border-gray-300 px-3 py-2 rounded-lg transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  >
                    Siguiente →
                  </button>
                </div>
              )}
            </>
          )}
        </div>

      </main>

      {/* Profile modal */}
      {showProfile && user && (
        <ProfileModal
          user={user}
          onClose={() => setShowProfile(false)}
          onUpdated={(u, msg) => { setUser(u); setShowProfile(false); setProfileMsg(msg) }}
        />
      )}

      {/* New transaction modal */}
      {showNewTx && rate && (
        <NewTransactionModal
          buyRate={rate.blue.buy}
          sellRate={rate.blue.sell}
          balanceUsd={balanceUsd}
          onClose={() => setShowNewTx(false)}
          onCreated={handleCreated}
        />
      )}
    </div>
  )
}
